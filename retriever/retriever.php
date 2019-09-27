<?php
/**
 * Name: Retrieve Feed Content
 * Description: Follow the permalink of RSS/Atom feed items and replace the summary with the full content.
 * Version: 1.1
 * Author: Matthew Exon <http://mat.exon.name>
 * Status: Unsupported
 */

use Friendica\Core\Addon;
use Friendica\Core\Config;
use Friendica\Core\PConfig;
use Friendica\Core\Logger;
use Friendica\Core\Renderer;
use Friendica\Core\System;
use Friendica\Content\Text\HTML;
use Friendica\Content\Text\BBCode;
use Friendica\Model\Photo;
use Friendica\Object\Image;
use Friendica\Util\Network;
use Friendica\Core\L10n;
use Friendica\Database\DBA;
use Friendica\Model\ItemURI;
use Friendica\Model\Item;

function retriever_install() {
    Addon::registerHook('plugin_settings', 'addon/retriever/retriever.php', 'retriever_plugin_settings');
    Addon::registerHook('plugin_settings_post', 'addon/retriever/retriever.php', 'retriever_plugin_settings_post');
    Addon::registerHook('post_remote', 'addon/retriever/retriever.php', 'retriever_post_remote_hook');
    Addon::registerHook('contact_photo_menu', 'addon/retriever/retriever.php', 'retriever_contact_photo_menu');
    Addon::registerHook('cron', 'addon/retriever/retriever.php', 'retriever_cron');

    if (Config::get('retriever', 'dbversion') == '0.10') {
        q("ALTER TABLE `retriever_resource` MODIFY COLUMN `type` char(255) NULL DEFAULT NULL");
        q("ALTER TABLE `retriever_resource` MODIFY COLUMN `data` mediumblob NULL DEFAULT NULL");
        q("ALTER TABLE `retriever_rule` MODIFY COLUMN `data` mediumtext NULL DEFAULT NULL");
        Config::set('retriever', 'dbversion', '0.11');
    }
    if (Config::get('retriever', 'dbversion') == '0.11') {
        q("ALTER TABLE `retriever_resource` ADD INDEX `url` (`url`)");
        q("ALTER TABLE `retriever_resource` ADD INDEX `completed` (`completed`)");
        q("ALTER TABLE `retriever_item` ADD INDEX `finished` (`finished`)");
        q("ALTER TABLE `retriever_item` ADD INDEX `item-uid` (`item-uid`)");
        Config::set('retriever', 'dbversion', '0.12');
    }
    if (Config::get('retriever', 'dbversion') == '0.12') {
        q("ALTER TABLE `retriever_resource` ADD COLUMN `contact-id` int(10) unsigned NOT NULL DEFAULT '0' AFTER `id`");
        q("ALTER TABLE `retriever_resource` ADD COLUMN `item-uid` int(10) unsigned NOT NULL DEFAULT '0' AFTER `id`");
        Config::set('retriever', 'dbversion', '0.13');
    }
    if (Config::get('retriever', 'dbversion') != '0.13') {
        $schema = file_get_contents(dirname(__file__).'/database.sql');
        $arr = explode(';', $schema);
        foreach ($arr as $a) {
            $r = q($a);
        }
        Config::set('retriever', 'dbversion', '0.13');
    }
}

function retriever_uninstall() {
    Addon::unregisterHook('plugin_settings', 'addon/retriever/retriever.php', 'retriever_plugin_settings');
    Addon::unregisterHook('plugin_settings_post', 'addon/retriever/retriever.php', 'retriever_plugin_settings_post');
    Addon::unregisterHook('post_remote', 'addon/retriever/retriever.php', 'retriever_post_remote_hook');
    Addon::unregisterHook('plugin_settings', 'addon/retriever/retriever.php', 'retriever_plugin_settings');
    Addon::unregisterHook('plugin_settings_post', 'addon/retriever/retriever.php', 'retriever_plugin_settings_post');
    Addon::unregisterHook('contact_photo_menu', 'addon/retriever/retriever.php', 'retriever_contact_photo_menu');
    Addon::unregisterHook('cron', 'addon/retriever/retriever.php', 'retriever_cron');
}

function retriever_module() {}

function retriever_cron($a, $b) {
    // 100 is a nice sane number.  Maybe this should be configurable. @@@

    // Do this first, otherwise it can interfere with retreiver_retrieve_items
    retriever_clean_up_completed_resources(100, $a);

    retriever_retrieve_items(100, $a);
    retriever_tidy();
}

$retriever_item_count = 0;

function retriever_retrieve_items($max_items, $a) {
    Logger::info('@@@ retriever_retrieve_items');
    global $retriever_item_count;

    $retriever_schedule = array(array(1,'minute'),
                                array(10,'minute'),
                                array(1,'hour'),
                                array(1,'day'),
                                array(2,'day'),
                                array(1,'week'),
                                array(1,'month'));

    $schedule_clauses = array();
    for ($i = 0; $i < count($retriever_schedule); $i++) {
        $num = $retriever_schedule[$i][0];
        $unit = $retriever_schedule[$i][1];
        array_push($schedule_clauses,
                   '(`num-tries` = ' . $i . ' AND TIMESTAMPADD(' . DBA::escape($unit) .
                   ', ' . intval($num) . ', `last-try`) < now())');
    }

    $retrieve_items = $max_items - $retriever_item_count;
    Logger::debug('retriever_retrieve_items: asked for maximum ' . $max_items . ', already retrieved ' . $retriever_item_count . ', retrieve ' . $retrieve_items);
    do {
        Logger::info('@@@ retriever_retrieve_items loop max ' . $max_items . ' count ' . $retriever_item_count);
        Logger::info("@@@ SELECT * FROM `retriever_resource` WHERE `completed` IS NULL AND (`last-try` IS NULL OR " . implode($schedule_clauses, ' OR ') . ") ORDER BY `last-try` ASC LIMIT " . $retrieve_items);
        $retriever_resources = q("SELECT * FROM `retriever_resource` WHERE `completed` IS NULL AND (`last-try` IS NULL OR %s) ORDER BY `last-try` ASC LIMIT %d",
               DBA::escape(implode($schedule_clauses, ' OR ')),
               intval($retrieve_items));
        if (!is_array($retriever_resources)) {
            break;
        }
        if (count($retriever_resources) == 0) {
            break;
        }
        Logger::debug('retriever_retrieve_items: found ' . count($retriever_resources) . ' waiting resources in database');
        foreach ($retriever_resources as $retriever_resource) {
            Logger::info('@@@ need to get the retriever config here cid ' . $retriever_resource['contact-id'] . ' uid ' . $retriever_resource['item-uid']);
            retrieve_resource($retriever_resource);
            $retriever_item_count++;
        }
        $retrieve_items = $max_items - $retriever_item_count;
    }
    while ($retrieve_items > 0);
    // @@@ todo: when items add further items (i.e. images), do the new images go round this loop again?
    Logger::info('@@@ retriever_retrieve_items: finished retrieving items');
}

/* Look for items that are waiting even though the resource has
 * completed.  This usually happens because we've been asked to
 * retrospectively apply a config change.  It could also happen due to
 * a cron job dying or something. */
function retriever_clean_up_completed_resources($max_items, $a) {
    $r = q("SELECT retriever_resource.`id` as resource, retriever_item.`id` as item FROM retriever_resource, retriever_item, retriever_rule WHERE retriever_item.`finished` = 0 AND retriever_item.`resource` = retriever_resource.`id` AND retriever_resource.`completed` IS NOT NULL AND retriever_item.`contact-id` = retriever_rule.`contact-id` AND retriever_item.`item-uid` = retriever_rule.`uid` LIMIT %d",
           intval($max_items));
    if (!$r) {
        $r = array();
    }
    Logger::debug('retriever_clean_up_completed_resources: items waiting even though resource has completed: ' . count($r));
    foreach ($r as $rr) {
        $resource = q("SELECT * FROM retriever_resource WHERE `id` = %d", $rr['resource']);
        $retriever_item = retriever_get_retriever_item($rr['item']);
        if (!DBA::isResult($retriever_item)) {
            Logger::warning('retriever_clean_up_completed_resources: no retriever item with id ' . $rr['item']);
            continue;
        }
        $item = retriever_get_item($retriever_item);
        if (!$item) {
            Logger::warning('retriever_clean_up_completed_resources: no item ' . $retriever_item['item-uri']);
            continue;
        }
        $retriever_rule = get_retriever_rule($retriever_item['contact-id'], $item['uid']);
        if (!$retriever_rule) {
            Logger::warning('retriever_clean_up_completed_resources: no retriever for uri ' . $retriever_item['item-uri'] . ' uid ' . $retriever_item['uid'] . ' ' . $retriever_item['contact-id']);
            continue;
        }
        Logger::info('@@@ retriever_clean_up_completed_resources: about to retriever_apply_completed_resource_to_item');
        retriever_apply_completed_resource_to_item($retriever_rule, $item, $resource[0], $a);
        q("UPDATE `retriever_item` SET `finished` = 1 WHERE id = %d", intval($retriever_item['id']));
        retriever_check_item_completed($item);
    }
}

function retriever_tidy() {
    q("DELETE FROM retriever_resource WHERE completed IS NOT NULL AND completed < DATE_SUB(now(), INTERVAL 1 WEEK)");
    q("DELETE FROM retriever_resource WHERE completed IS NULL AND created < DATE_SUB(now(), INTERVAL 3 MONTH)");

    $r = q("SELECT retriever_item.id FROM retriever_item LEFT OUTER JOIN retriever_resource ON (retriever_item.resource = retriever_resource.id) WHERE retriever_resource.id is null");
    Logger::info('retriever_tidy: found ' . count($r) . ' retriever_items with no retriever_resource');
    foreach ($r as $rr) {
        q('DELETE FROM retriever_item WHERE id = %d', intval($rr['id']));
    }
}

function retrieve_dataurl_resource($resource) {
    if (!preg_match("/date:(.*);base64,(.*)/", $resource['url'], $matches)) {
        Logger::info('retrieve_dataurl_resource: ' . $resource['id'] . ' does not match pattern');
    } else {
        $resource['type'] = $matches[1];
        $resource['data'] = base64url_decode($matches[2]);
    }

    // Succeed or fail, there's no point retrying
    q("UPDATE `retriever_resource` SET `last-try` = now(), `num-tries` = `num-tries` + 1, `completed` = now(), `data` = '%s', `type` = '%s' WHERE id = %d",
      DBA::escape($resource['data']),
      DBA::escape($resource['type']),
      intval($resource['id']));
    retriever_resource_completed($resource, $a);
}

function retrieve_resource($resource) {
    Logger::info('@@@ retrieve_resource: url ' . $resource['url'] . ' uid ' . $resource['item-uid'] . ' cid ' . $resource['contact-id']);

    if (substr($resource['url'], 0, 5) == "data:") {
        return retrieve_dataurl_resource($resource);
    }

    $a = get_app();

    $retriever_rule = get_retriever_rule($resource['contact-id'], $resource['item-uid']);

    try {
        Logger::debug('retrieve_resource: ' . ($resource['num-tries'] + 1) . ' attempt at resource ' . $resource['id'] . ' ' . $resource['url']);
        $redirects = 0;
        $cookiejar = tempnam(get_temppath(), 'cookiejar-retriever-');
        if (array_key_exists('storecookies', $retriever_rule) && $retriever_rule['storecookies']) {
            file_put_contents($cookiejar, $retriever_rule['cookiedata']);
        }
        $fetch_result = Network::fetchUrlFull($resource['url'], $resource['binary'], $redirects, '', $cookiejar);
        if (array_key_exists('storecookies', $retriever_rule) && $retriever_rule['storecookies']) {
            $retriever_rule['cookiedata'] = file_get_contents($cookiejar);
            //@@@ do the store here
        }
        unlink($cookiejar);
        $resource['data'] = $fetch_result->getBody();
        $resource['http-code'] = $fetch_result->getReturnCode();
        $resource['type'] = $fetch_result->getContentType();
        $resource['redirect-url'] = $fetch_result->getRedirectUrl();
        Logger::debug('retrieve_resource: got code ' . $resource['http-code'] . ' retrieving resource ' . $resource['id'] . ' final url ' . $resource['redirect-url']);
    } catch (Exception $e) {
        Logger::info('retrieve_resource: unable to retrieve ' . $resource['url'] . ' - ' . $e->getMessage());
    }
    q("UPDATE `retriever_resource` SET `last-try` = now(), `num-tries` = `num-tries` + 1, `http-code` = %d, `redirect-url` = '%s' WHERE id = %d",
      intval($resource['http-code']),
      DBA::escape($resource['redirect-url']),
      intval($resource['id']));
    if ($resource['data']) {
        q("UPDATE `retriever_resource` SET `completed` = now(), `data` = '%s', `type` = '%s' WHERE id = %d",
          DBA::escape($resource['data']),
          DBA::escape($resource['type']),
          intval($resource['id']));
        retriever_resource_completed($resource, $a);
    }
    Logger::info('@@@ retrieve_resource finished: ' . $resource['url']);
}

function get_retriever_rule($contact_id, $uid, $create = false) {
    Logger::info('@@@ get_retriever_rule ' . "SELECT * FROM `retriever_rule` WHERE `contact-id` = " . intval($contact_id) . " AND `uid` = " . intval($uid));
    $r = q("SELECT * FROM `retriever_rule` WHERE `contact-id` = %d AND `uid` = %d",
           intval($contact_id), intval($uid));
    Logger::info('@@@ get_retriever_rule count is ' . count($r));
    if (count($r)) {
        $r[0]['data'] = json_decode($r[0]['data'], true);
        Logger::info('@@@ get_retriever_rule returning an actual thing');
        return $r[0];
    }
    if ($create) {
        q("INSERT INTO `retriever_rule` (`uid`, `contact-id`) VALUES (%d, %d)",
          intval($uid), intval($contact_id));
        $r = q("SELECT * FROM `retriever_rule` WHERE `contact-id` = %d AND `uid` = %d",
               intval($contact_id), intval($uid));
        return $r[0];
    }
}

function retriever_get_retriever_item($id) {
    return DBA::selectFirst('retriever_item', [], ['id' => intval($id)]);
}

function retriever_class_of_item($item) { //@@@
    if (!$item) {
        return 'false';
    }
    if (array_key_exists('finished', $item)) {
        Logger::info('@@@ oh no this is a bad thing');
        return 'retriever_item';
    }
    if (array_key_exists('moderated', $item)) {
        return 'friendica_item';
    }
    return 'unknown';
}

function mat_test($item) { //@@@
    return 'mat_test';
}

function retriever_get_item($retriever_item) {
    // @@@ add contact id as a search term
    Logger::info('@@@ retriever_get_item uri ' . $retriever_item['item-uri'] . ' uid ' . $retriever_item['item-uid'] . ' cid ' . $retriever_item['contact-id']);
    try {//@@@ not necessary
        $item = Item::selectFirst([], ['uri' => $retriever_item['item-uri'], 'uid' => intval($retriever_item['item-uid'])]);
        Logger::log('@@@ 1 item class is ' . retriever_class_of_item($item) . ' ' . mat_test($item));
        if (!DBA::isResult($item)) {
            Logger::log('retriever_get_item: no item found for uri ' . $retriever_item['item-uri']);
            return;
        }
        Logger::info('@@@ retriever_get_item: yay item found for uri ' . $retriever_item['item-uri'] . ' guid ' . $item['guid'] . ' plink ' . $item['plink']);
        return $item;
    } catch (Exception $e) {
        Logger::info('retriever_get_item: exception ' . $e->getMessage());
    }
}

function retriever_item_completed($retriever_item_id, $resource, $a) {
    Logger::debug('retriever_item_completed: id ' . $retriever_item_id . ' url ' . $resource['url']);

    $retriever_item = retriever_get_retriever_item($retriever_item_id);
    if (!DBA::isResult($retriever_item)) {
        Logger::info('retriever_item_completed: no retriever item with id ' . $retriever_item_id);
        return;
    }
    $item = retriever_get_item($retriever_item);
        Logger::log('@@@ 2 item class is ' . retriever_class_of_item($item) . ' ' . mat_test($item));
    if (!$item) {
        Logger::log('retriever_item_completed: no item ' . $retriever_item['item-uri']);
        return;
    }
    // Note: the retriever might be null.  Doesn't matter.
    $retriever_rule = get_retriever_rule($retriever_item['contact-id'], $retriever_item['item-uid']);

    retriever_apply_completed_resource_to_item($retriever_rule, $item, $resource, $a);

    q("UPDATE `retriever_item` SET `finished` = 1 WHERE id = %d",
      intval($retriever_item['id']));
    retriever_check_item_completed($item);
}

function retriever_resource_completed($resource, $a) {
    Logger::debug('retriever_resource_completed: id ' . $resource['id'] . ' url ' . $resource['url']);
    $r = q("SELECT `id` FROM `retriever_item` WHERE `resource` = %d", $resource['id']);
    foreach ($r as $rr) {
        retriever_item_completed($rr['id'], $resource, $a);
    }
}

function apply_retrospective($a, $retriever, $num) {
    $r = q("SELECT * FROM `item` WHERE `contact-id` = %d ORDER BY `received` DESC LIMIT %d",
           intval($retriever['contact-id']), intval($num));
    foreach ($r as $item) {
        Logger::log('@@@ 3 item class is ' . retriever_class_of_item($item) . ' ' . mat_test($item)); //@@@ already know this is wrong
        q('UPDATE `item` SET `visible` = 0 WHERE `id` = %d', $item['id']);
        q('UPDATE `thread` SET `visible` = 0 WHERE `iid` = %d', $item['id']);
        retriever_on_item_insert($a, $retriever, $item);
    }
}

//@@@ make this trigger a retriever immediately somehow
//@@@ need a lock to say something is doing something
function retriever_on_item_insert($a, $retriever, &$item) {
    Logger::info('@@@ 4 item class is ' . retriever_class_of_item($item) . ' ' . mat_test($item));
        Logger::info('@@@ retriever_on_item_insert start ' . $item['plink']);
    if (!$retriever || !$retriever['id']) {
        Logger::info('retriever_on_item_insert: No retriever supplied');
        return;
    }
    if (!$retriever["data"]['enable'] == "on") {
        Logger::info('@@@ retriever_on_item_insert: Disabled');
        return;
    }
    if (array_key_exists('pattern', $retriever["data"]) && $retriever["data"]['pattern']) {
        $url = preg_replace('/' . $retriever["data"]['pattern'] . '/', $retriever["data"]['replace'], $item['plink']);
        Logger::debug('retriever_on_item_insert: Changed ' . $item['plink'] . ' to ' . $url);
    }
    else {
        $url = $item['plink'];
    }

    Logger::debug('@@@ retriever_on_item_insert: about to add_retriever_resource uid ' . $item['uid'] . ' cid ' . $item['contact-id']);
    $resource = add_retriever_resource($a, $url, $item['uid'], $item['contact-id']);
    $retriever_item_id = add_retriever_item($item, $resource);
}

function add_retriever_resource($a, $url, $uid, $cid, $binary = false) {
    Logger::debug('add_retriever_resource: url ' . $url . ' uid ' . $uid . ' contact-id ' . $cid);

    $scheme = parse_url($url, PHP_URL_SCHEME);
    if ($scheme == 'data') {
        $fp = fopen($url, 'r');
        $meta = stream_get_meta_data($fp);
        $type = $meta['mediatype'];
        $data = stream_get_contents($fp);
        fclose($fp);

        $url = 'md5://' . hash('md5', $url);
        $r = q("SELECT * FROM `retriever_resource` WHERE `url` = '%s' AND `item-uid` = %d AND `contact-id` = %d", DBA::escape($url), intval($uid), intval($cid));
        $resource = $r[0];
        if (count($r)) {
            Logger::debug('add_retriever_resource: Resource ' . $url . ' already requested');
            return $resource;
        }

        Logger::debug('retrieve_resource: got data URL type ' . $resource['type']);
        q("INSERT INTO `retriever_resource` (`item-uid`, `contact-id`, `type`, `binary`, `url`, `completed`, `data`) " .
          "VALUES (%d, %d, '%s', %d, '%s', now(), '%s')",
          intval($uid),
          intval($cid),
          DBA::escape($type),
          intval($binary ? 1 : 0),
          DBA::escape($url),
          DBA::escape($data));
        $r = q("SELECT * FROM `retriever_resource` WHERE `url` = '%s'", DBA::escape($url));
        $resource = $r[0];
        if (count($r)) {
            retriever_resource_completed($resource, $a);
        }
        return $resource;
    }

    if (strlen($url) > 800) {
        Logger::warning('add_retriever_resource: URL is longer than 800 characters');
    }

    $r = q("SELECT * FROM `retriever_resource` WHERE `url` = '%s' AND `item-uid` = %d AND `contact-id` = %d", DBA::escape($url), intval($uid), intval($cid));
    if (count($r)) {
        Logger::debug('add_retriever_resource: Resource ' . $url . ' uid ' . $uid . ' cid ' . $cid . ' already requested');
        return $r[0];
    }

    q("INSERT INTO `retriever_resource` (`item-uid`, `contact-id`, `binary`, `url`) " .
      "VALUES (%d, %d, %d, '%s')", intval($uid), intval($cid), intval($binary ? 1 : 0), DBA::escape($url));
    $r = q("SELECT * FROM `retriever_resource` WHERE `url` = '%s'", DBA::escape($url));
    return $r[0];
}

function add_retriever_item(&$item, $resource) {
    Logger::debug('@@@ 5 item class is ' . retriever_class_of_item($item) . ' ' . mat_test($item));
    Logger::debug('add_retriever_item: ' . $resource['url'] . ' for ' . $item['uri'] . ' ' . $item['uid'] . ' ' . $item['contact-id']);

    $r = q("SELECT COUNT(*) FROM `retriever_item` WHERE " .
           "`item-uri` = '%s' AND `item-uid` = %d AND `contact-id` = %d AND `resource` = %d",
           DBA::escape($item['uri']), intval($item['uid']), intval($item['contact-id']), intval($resource['id']));
    if ($r[0]['COUNT(*)'] > 0) {
        Logger::info("add_retriever_item: retriever item already present for " . $item['uri'] . ' ' . $item['uid'] . ' ' . $item['contact-id']);
        return;
    }
    q("INSERT INTO `retriever_item` (`item-uri`, `item-uid`, `contact-id`, `resource`) " .
      "VALUES ('%s', %d, %d, %d)",
      DBA::escape($item['uri']), intval($item['uid']), intval($item['contact-id']), intval($resource["id"]));
    $r = q("SELECT id FROM `retriever_item` WHERE " .
           "`item-uri` = '%s' AND `item-uid` = %d AND `contact-id` = %d AND `resource` = %d ORDER BY id DESC",
           DBA::escape($item['uri']), intval($item['uid']), intval($item['contact-id']), intval($resource['id']));
    if (!count($r)) {
        Logger::info("add_retriever_item: couldn't create retriever item for " . $item['uri'] . ' ' . $item['uid'] . ' ' . $item['contact-id']);
        return;
    }
    Logger::debug('add_retriever_item: created retriever_item ' . $r[0]['id'] . ' for item ' . $item['uri'] . ' ' . $item['uid'] . ' ' . $item['contact-id']);
    return $r[0]['id'];
}

function retriever_get_encoding($resource) {
    $matches = array();
    if (preg_match('/charset=(.*)/', $resource['type'], $matches)) {
        return trim(array_pop($matches));
    }
    return 'utf-8';
}

function retriever_apply_xslt_text($xslt_text, $doc) {
    if (!$xslt_text) {
        Logger::info('retriever_apply_xslt_text: empty XSLT text');
        return $doc;
    }
    $xslt_doc = new DOMDocument();
    if (!$xslt_doc->loadXML($xslt_text)) {
        Logger::info('retriever_apply_xslt_text: could not load XML');
        return $doc;
    }
    $xp = new XsltProcessor();
    $xp->importStylesheet($xslt_doc);
    $result = $xp->transformToDoc($doc);
    return $result;
}

//@@@ is that an item or a resource_item?  I really want an item here so I can update it
function retriever_apply_dom_filter($retriever, &$item, $resource) {
    Logger::debug('@@@ 6 item class is ' . retriever_class_of_item($item) . ' ' . mat_test($item));
    Logger::debug('retriever_apply_dom_filter: applying XSLT to ' . $item['id'] . ' ' . $item['uri'] . ' contact ' . $item['contact-id']);

    if (!array_key_exists('include', $retriever['data']) && !array_key_exists('customxslt', $retriever['data'])) {
        Logger::info('retriever_apply_dom_filter: no include and no customxslt');
        return;
    }
    if (!$resource['data']) {
        Logger::info('retriever_apply_dom_filter: no text to work with');
        return;
    }

    $encoding = retriever_get_encoding($resource);
    $content = mb_convert_encoding($resource['data'], 'HTML-ENTITIES', $encoding);
    $doc = new DOMDocument('1.0', 'UTF-8');
    if (strpos($resource['type'], 'html') !== false) {
        @$doc->loadHTML($content);
    }
    else {
        $doc->loadXML($content);
    }

    $params = array('$spec' => $retriever['data']);
    $extract_template = Renderer::getMarkupTemplate('extract.tpl', 'addon/retriever/');
    $extract_xslt = Renderer::replaceMacros($extract_template, $params);
    if ($retriever['data']['include']) {
        Logger::debug('retriever_apply_dom_filter: applying include/exclude template \"' . $extract_xslt . '\"');
        $doc = retriever_apply_xslt_text($extract_xslt, $doc);
    }
    if (array_key_exists('customxslt', $retriever['data']) && $retriever['data']['customxslt']) {
        Logger::debug('retriever_apply_dom_filter: applying custom XSLT \"' . $retriever['data']['customxslt'] . '\"');
        $doc = retriever_apply_xslt_text($retriever['data']['customxslt'], $doc);
    }
    if (!$doc) {
        Logger::info('retriever_apply_dom_filter: failed to apply extract XSLT template');
        return;
    }

    Logger::info('@@@ retriever_apply_dom_filter: 1');
    $components = parse_url($resource['redirect-url']);
    $rooturl = $components['scheme'] . "://" . $components['host'];
    $dirurl = $rooturl . dirname($components['path']) . "/";
    Logger::info('@@@ retriever_apply_dom_filter: 2');
    $params = array('$dirurl' => $dirurl, '$rooturl' => $rooturl);
    $fix_urls_template = Renderer::getMarkupTemplate('fix-urls.tpl', 'addon/retriever/');
    $fix_urls_xslt = Renderer::replaceMacros($fix_urls_template, $params);
    Logger::info('@@@ retriever_apply_dom_filter: 3');
    $doc = retriever_apply_xslt_text($fix_urls_xslt, $doc);
    Logger::info('@@@ retriever_apply_dom_filter: 4');
    if (!$doc) {
        Logger::info('retriever_apply_dom_filter: failed to apply fix urls XSLT template');
        return;
    }

    Logger::info('@@@ retriever_apply_dom_filter: 5');
    $body = HTML::toBBCode($doc->saveHTML());
    if (!strlen($body)) {
        Logger::info('retriever_apply_dom_filter retriever ' . $retriever['id'] . ' item ' . $item['id'] . ': output was empty');
        return;
    }
    $body .= "\n\n" . L10n::t('Retrieved') . ' ' . date("Y-m-d") . ': [url=';
    $body .=  $item['plink'];
    $body .= ']' . $item['plink'] . '[/url]';

    Logger::info('@@@ retriever_apply_dom_filter: 6');
    $uri_id = ItemURI::getIdByURI($item['uri']); //@@@ why can't I get this from the item itself?
    Logger::info('@@@ retriever_apply_dom_filter: item id is ' . $item['id'] . ' uri id is ' . $uri_id);
    Logger::debug('retriever_apply_dom_filter: XSLT result \"' . $body . '\"');
    Item::update(['body' => $body], ['uri-id' => $uri_id]);
}

function retrieve_images(&$item, $a) {
    $blah_item_class = retriever_class_of_item($item) . ' ' . mat_test($item);
    Logger::debug('@@@ 7 item class is ' . $blah_item_class);

    $uri_id = ItemURI::getIdByURI($item['uri']); //@@@ why can't I get this from the item itself?

    $content = DBA::selectFirst('item-content', [], ['uri-id' => $uri_id]);
    $body = $content['body'];
    if (!strlen($body)) {
        Logger::warning('retrieve_images: no body for uri-id ' . $uri_id);
        return;
    }

    Logger::info('@@@ retrieve_images start looking in body "' . $body . '"');
    $matches1 = array();
    preg_match_all("/\[img\=([0-9]*)x([0-9]*)\](.*?)\[\/img\]/ism", $body, $matches1);
    $matches2 = array();
    preg_match_all("/\[img\](.*?)\[\/img\]/ism", $body, $matches2);
    $matches = array_merge($matches1[3], $matches2[1]);
    Logger::debug('retrieve_images: found ' . count($matches) . ' images for item ' . $item['uri'] . ' ' . $item['uid'] . ' ' . $item['contact-id']);
    foreach ($matches as $url) {
        Logger::debug('@@@ retrieve_images: url ' . $url);
        if (strpos($url, get_app()->getBaseUrl()) === FALSE) {
            Logger::debug('@@@ retrieve_images: it is from somewhere else');
            Logger::debug('@@@ retrieve_images: about to add_retriever_resource uid ' . $item['uid'] . ' cid ' . $item['contact-id']);
            $resource = add_retriever_resource($a, $url, $item['uid'], $item['contact-id'], true);
            if (!$resource['completed']) {
                Logger::debug('@@@ retrieve_images: do not have it yet, get it later');
                add_retriever_item($item, $resource);
            }
            else {
                Logger::debug('@@@ retrieve_images: got it already, transform');
                retriever_transform_images($a, $item, $resource);
            }
        }
    }
    Logger::info('@@@ retrieve_images end');
}

function retriever_check_item_completed(&$item)
{
    Logger::debug('@@@ 9 item class is ' . retriever_class_of_item($item) . ' ' . mat_test($item));
    $r = q('SELECT count(*) FROM retriever_item WHERE `item-uri` = "%s" ' .
           'AND `item-uid` = %d AND `contact-id` = %d AND `finished` = 0',
           DBA::escape($item['uri']), intval($item['uid']),
           intval($item['contact-id']));
    $waiting = $r[0]['count(*)'];
    Logger::debug('retriever_check_item_completed: item ' . $item['uri'] . ' ' . $item['uid'] . ' '. $item['contact-id'] . ' waiting for ' . $waiting . ' resources');
    $old_visible = $item['visible'];
    $item['visible'] = $waiting ? 0 : 1;
    if (array_key_exists('id', $item) && ($item['id'] > 0) && ($old_visible != $item['visible'])) {
        Logger::debug('retriever_check_item_completed: changing visible flag to ' . $item['visible']);
        q("UPDATE `item` SET `visible` = %d WHERE `id` = %d",
          intval($item['visible']),
          intval($item['id']));
        q("UPDATE `thread` SET `visible` = %d WHERE `iid` = %d",
          intval($item['visible']),
          intval($item['id']));
    }
}

function retriever_apply_completed_resource_to_item($retriever, &$item, $resource, $a) {
    Logger::debug('@@@ 10 item class is ' . retriever_class_of_item($item) . ' ' . mat_test($item));
    Logger::debug('retriever_apply_completed_resource_to_item: retriever ' . ($retriever ? $retriever['id'] : 'none') . ' resource ' . $resource['url'] . ' plink ' . $item['plink']);
    if (strpos($resource['type'], 'image') !== false) {
        Logger::info('@@@ retriever_apply_completed_resource_to_item this is an image must transform');
        retriever_transform_images($a, $item, $resource);
    }
    if (!$retriever) {
        return;
    }
    if ((strpos($resource['type'], 'html') !== false) ||
        (strpos($resource['type'], 'xml') !== false)) {
        retriever_apply_dom_filter($retriever, $item, $resource);
        if ($retriever["data"]['images'] ) {
            retrieve_images($item, $a);
        }
    }
}

//@@@ todo: change all Logger::info t etc
//@@@ todo: what is this reference for?  document if needed delete if not
function retriever_transform_images($a, &$item, $resource) {
    Logger::debug('@@@ 11 item class is ' . retriever_class_of_item($item) . ' ' . mat_test($item));
    Logger::info('@@@ retriever_transform_images');
    if (!$resource["data"]) {
        Logger::info('retriever_transform_images: no data available for ' . $resource['id'] . ' ' . $resource['url']);
        return;
    }

    $uri_id = ItemURI::getIdByURI($item['uri']); //@@@ why can't I get this from the item itself?

    try { //@@@ probably can get rid of this try/catch
        $data = $resource['data'];
        $type = $resource['type'];
        $uid = $item['uid'];
        $cid = $item['contact-id'];
        $rid = Photo::newResource();
        $path = parse_url($resource['url'], PHP_URL_PATH);
        $parts = pathinfo($path);
        $filename = $parts['filename'] . (array_key_exists('extension', $parts) ? '.' . $parts['extension'] : '');
        Logger::info('@@@ retriever_transform_images url ' . $resource['url'] . ' path ' . $path . ' filename ' . $parts['filename']);
        $album = 'Wall Photos';
        $scale = 0;
        $desc = ''; // TODO: store alt text with resource when it's requested so we can fill this in
        Logger::debug('retriever_transform_images storing ' . strlen($data) . ' bytes type ' . $type . ': uid ' . $uid . ' cid ' . $cid . ' rid ' . $rid . ' filename ' . $filename . ' album ' . $album . ' scale ' . $scale . ' desc ' . $desc);
        Logger::info('@@@ retriever_transform_images before new Image');
        $image = new Image($data, $type);
        Logger::info('@@@ retriever_transform_images after new Image');
        if (!$image->isValid()) {
            Logger::warning('retriever_transform_images: invalid image found at URL ' . $resource['url'] . ' for item ' . $item['id']);
            return;
        }
        Logger::info('@@@ retriever_transform_images before Photo::store');
        $photo = Photo::store($image, $uid, $cid, $rid, $filename, $album, 0, 0, "", "", "", "", $desc);
        Logger::info('@@@ retriever_transform_images after Photo::store');
        $new_url = System::baseUrl() . '/photo/' . $rid . '-0.' . $image->getExt();
        Logger::info('@@@ retriever_transform_images new url ' . $new_url . ' rid ' . $rid . ' ext ' . $image->getExt());
        if (!strlen($new_url)) {
            Logger::warning('retriever_transform_images: no replacement URL for image ' . $resource['url']);
            return;
        }

        $content = DBA::selectFirst('item-content', [], ['uri-id' => $uri_id]);
        $body = $content['body'];
        Logger::info('@@@ retriever_transform_images: found body for uri id ' . $uri_id . ': ' . $body);

        Logger::debug('retriever_transform_images: replacing ' . $resource['url'] . ' with ' . $new_url . ' in item ' . $item['uri']);
        Logger::debug('@@@ retriever_transform_images: replacing ' . $resource['url'] . ' with ' . $new_url . ' in body ' . $body);
        $body = str_replace($resource["url"], $new_url, $body);

        Logger::info('@@@ retriever_transform_images: result \"' . $body . '\"');
        Item::update(['body' => $body], ['uri-id' => $uri_id]);
    } catch (Exception $e) {
        Logger::info('retriever_transform_images caught exception ' . $e->getMessage());
        return;
    }
}

function retriever_content($a) {
    if (!local_user()) {
        $a->page['content'] .= "<p>Please log in</p>";
        return;
    }
    if ($a->argv[1] === 'help') {
        $feeds = q("SELECT `id`, `name`, `thumb` FROM contact WHERE `uid` = %d AND `network` = 'feed'",
                   local_user());
        foreach ($feeds as $k=>$v) {
            $feeds[$k]['url'] = $a->getBaseUrl() . '/retriever/' . $v['id'];
        }
        $template = Renderer::getMarkupTemplate('/help.tpl', 'addon/retriever/');
        $a->page['content'] .= Renderer::replaceMacros($template, array(
                                                  '$config' => $a->getBaseUrl() . '/settings/addon',
                                                  '$feeds' => $feeds));
        return;
    }
    if ($a->argv[1]) {
        $retriever_rule = get_retriever_rule($a->argv[1], local_user(), false);

        if (!empty($_POST["id"])) {
            $retriever_rule = get_retriever_rule($a->argv[1], local_user(), true);
            $retriever_rule["data"] = array();
            foreach (array('pattern', 'replace', 'enable', 'images', 'customxslt', 'storecookies', 'cookiedata') as $setting) {
                if (!empty($_POST['retriever_' . $setting])) {
                    $retriever_rule["data"][$setting] = $_POST['retriever_' . $setting];
                }
            }
            foreach ($_POST as $k=>$v) {
                if (preg_match("/retriever-(include|exclude)-(\d+)-(element|attribute|value)/", $k, $matches)) {
                    $retriever_rule['data'][$matches[1]][intval($matches[2])][$matches[3]] = $v;
                }
            }
            // You've gotta have an element, even if it's just "*"
            foreach ($retriever_rule['data']['include'] as $k=>$clause) {
                if (!$clause['element']) {
                    unset($retriever_rule['data']['include'][$k]);
                }
            }
            foreach ($retriever_rule['data']['exclude'] as $k=>$clause) {
                if (!$clause['element']) {
                    unset($retriever_rule['data']['exclude'][$k]);
                }
            }
            q("UPDATE `retriever_rule` SET `data`='%s' WHERE `id` = %d",
              DBA::escape(json_encode($retriever_rule["data"])), intval($retriever_rule["id"]));
            $a->page['content'] .= "<p><b>Settings Updated";
            if (!empty($_POST["retriever_retrospective"])) {
                apply_retrospective($a, $retriever_rule, $_POST["retriever_retrospective"]);
                $a->page['content'] .= " and retrospectively applied to " . $_POST["apply"] . " posts";
            }
            $a->page['content'] .= ".</p></b>";
        }

        $template = Renderer::getMarkupTemplate('/rule-config.tpl', 'addon/retriever/');
        $a->page['content'] .= Renderer::replaceMacros($template, array(
                                                  '$enable' => array(
                                                      'retriever_enable',
                                                      L10n::t('Enabled'),
                                                      $retriever_rule['data']['enable']),
                                                  '$pattern' => array(
                                                      'retriever_pattern',
                                                      L10n::t('URL Pattern'),
                                                      $retriever_rule["data"]['pattern'],
                                                      L10n::t('Regular expression matching part of the URL to replace')),
                                                  '$replace' => array(
                                                      'retriever_replace',
                                                      L10n::t('URL Replace'),
                                                      $retriever_rule["data"]['replace'],
                                                      L10n::t('Text to replace matching part of above regular expression')),
                                                  '$images' => array(
                                                      'retriever_images',
                                                      L10n::t('Download Images'),
                                                      $retriever_rule['data']['images']),
                                                  '$retrospective' => array(
                                                      'retriever_retrospective',
                                                      L10n::t('Retrospectively Apply'),
                                                      '0',
                                                      L10n::t('Reapply the rules to this number of posts')),
                                                  'storecookies' => array(
                                                      'retriever_storecookies',
                                                      L10n::t('Store cookies'),
                                                      $retriever_rule['data']['storecookies'],
                                                      L10n::t("Preserve cookie data across fetches.")),
                                                  '$cookiedata' => array(
                                                      'retriever_cookiedata',
                                                      L10n::t('Cookie Data'),
                                                      $retriever_rule['data']['cookiedata'],
                                                      L10n::t("Latest cookie data for this feed.  Netscape cookie file format.")),
                                                  '$customxslt' => array(
                                                      'retriever_customxslt',
                                                      L10n::t('Custom XSLT'),
                                                      $retriever_rule['data']['customxslt'],
                                                      L10n::t("When standard rules aren't enough, apply custom XSLT to the article")),
                                                  '$title' => L10n::t('Retrieve Feed Content'),
                                                  '$help' => $a->getBaseUrl() . '/retriever/help',
                                                  '$help_t' => L10n::t('Get Help'),
                                                  '$submit_t' => L10n::t('Submit'),
                                                  '$submit' => L10n::t('Save Settings'),
                                                  '$id' => ($retriever_rule["id"] ? $retriever_rule["id"] : "create"),
                                                  '$tag_t' => L10n::t('Tag'),
                                                  '$attribute_t' => L10n::t('Attribute'),
                                                  '$value_t' => L10n::t('Value'),
                                                  '$add_t' => L10n::t('Add'),
                                                  '$remove_t' => L10n::t('Remove'),
                                                  '$include_t' => L10n::t('Include'),
                                                  '$include' => $retriever_rule['data']['include'],
                                                  '$exclude_t' => L10n::t('Exclude'),
                                                  '$exclude' => $retriever_rule["data"]['exclude']));
        return;
    }
}

function retriever_contact_photo_menu($a, &$args) {
    if (!$args) {
        return;
    }
    if ($args["contact"]["network"] == "feed") {
        $args["menu"][ 'retriever' ] = array(L10n::t('Retriever'), $a->getBaseUrl() . '/retriever/' . $args["contact"]['id']);
    }
}

function retriever_post_remote_hook(&$a, &$item) {
    Logger::info('@@@ 12 item class is ' . retriever_class_of_item($item) . ' ' . mat_test($item));
    Logger::info('retriever_post_remote_hook: ' . $item['uri'] . ' ' . $item['uid'] . ' ' . $item['contact-id']);

    $uri_id = ItemURI::getIdByURI($item['uri']); //@@@ why can't I get this from the item itself?
    $retriever_rule = get_retriever_rule($item['contact-id'], $item["uid"], false);
    if ($retriever_rule) {
        retriever_on_item_insert($a, $retriever_rule, $item);
    }
    else {
        if (PConfig::get($item["uid"], 'retriever', 'oembed')) {
            // Convert to HTML and back to take advantage of bbcode's resolution of oembeds.
            $content = DBA::selectFirst('item-content', [], ['uri-id' => $uri_id]);
            $body = HTML::toBBCode(BBCode::convert($content['body']));
            Logger::debug('@@@ retriever_post_remote_hook item uri-id ' . $uri_id . ' body "' . $item['body'] . '" item content body "' . $body . '"');
            if ($body) {
                $item['body'] = $body;
                Item::update(['body' => $body], ['uri-id' => $uri_id]);
            }
        }
        if (PConfig::get($item["uid"], 'retriever', 'all_photos')) {
            retrieve_images($item, $a);
        }
    }
    retriever_check_item_completed($item);
}

function retriever_plugin_settings(&$a,&$s) {
    $all_photos = PConfig::get(local_user(), 'retriever', 'all_photos');
    $oembed = PConfig::get(local_user(), 'retriever', 'oembed');
    $template = Renderer::getMarkupTemplate('/settings.tpl', 'addon/retriever/');
    $s .= Renderer::replaceMacros($template, array(
                             '$allphotos' => array(
                                 'retriever_all_photos',
                                 L10n::t('All Photos'),
                                 $all_photos,
                                 L10n::t('Check this to retrieve photos for all posts')),
                             '$oembed' => array(
                                 'retriever_oembed',
                                 L10n::t('Resolve OEmbed'),
                                 $oembed,
                                 L10n::t('Check this to attempt to retrieve embedded content for all posts - useful e.g. for Facebook posts')),
                             '$submit' => L10n::t('Save Settings'),
                             '$title' => L10n::t('Retriever Settings'),
                             '$help' => $a->getBaseUrl() . '/retriever/help'));
}

function retriever_plugin_settings_post($a,$post) {
    if ($_POST['retriever_all_photos']) {
        PConfig::set(local_user(), 'retriever', 'all_photos', $_POST['retriever_all_photos']);
    }
    else {
        PConfig::del(local_user(), 'retriever', 'all_photos');
    }
    if ($_POST['retriever_oembed']) {
        PConfig::set(local_user(), 'retriever', 'oembed', $_POST['retriever_oembed']);
    }
    else {
        PConfig::del(local_user(), 'retriever', 'oembed');
    }
}
