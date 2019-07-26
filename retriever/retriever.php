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
use Friendica\Content\Text\HTML;
use Friendica\Content\Text\BBCode;
use Friendica\Object\Image;
use Friendica\Util\Network;
use Friendica\Core\L10n;
use Friendica\Database\DBA;
use Friendica\Model\ItemURI;

function retriever_install() {
    Addon::registerHook('plugin_settings', 'addon/retriever/retriever.php', 'retriever_plugin_settings');
    Addon::registerHook('plugin_settings_post', 'addon/retriever/retriever.php', 'retriever_plugin_settings_post');
    Addon::registerHook('post_remote', 'addon/retriever/retriever.php', 'retriever_post_remote_hook');
    Addon::registerHook('contact_photo_menu', 'addon/retriever/retriever.php', 'retriever_contact_photo_menu');
    Addon::registerHook('cron', 'addon/retriever/retriever.php', 'retriever_cron');

    $r = q("SELECT `id` FROM `pconfig` WHERE `cat` LIKE 'retriever_%%'");
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
    /* if (Config::get('retriever', 'dbversion') == '0.12') { */
    /*     q("ALTER TABLE `retriever_resource` ADD COLUMN `contact-id` int(10) unsigned NULL AFTER `id`"); */
    /*     Config::set('retriever', 'dbversion', '0.13'); */
    /* } */
    if (Config::get('retriever', 'dbversion') != '0.12') {
        $schema = file_get_contents(dirname(__file__).'/database.sql');
        $arr = explode(';', $schema);
        foreach ($arr as $a) {
            $r = q($a);
        }
        Config::set('retriever', 'dbversion', '0.12');
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
    // 100 is a nice sane number.  Maybe this should be configurable.
    retriever_retrieve_items(100, $a);
    retriever_tidy();
}

$retriever_item_count = 0;

function retriever_retrieve_items($max_items, $a) {
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
    Logger::log('retriever_retrieve_items: asked for maximum ' . $max_items . ', already retrieved ' . $retriever_item_count . ', retrieve ' . $retrieve_items, Logger::DEBUG);
    do {
        $r = q("SELECT * FROM `retriever_resource` WHERE `completed` IS NULL AND (`last-try` IS NULL OR %s) ORDER BY `last-try` ASC LIMIT %d",
               DBA::escape(implode($schedule_clauses, ' OR ')),
               intval($retrieve_items));
        if (!is_array($r)) {
            break;
        }
        if (count($r) == 0) {
            break;
        }
        Logger::log('retriever_retrieve_items: found ' . count($r) . ' waiting resources in database', Logger::DEBUG);
        foreach ($r as $rr) {
            retrieve_resource($rr);
            $retriever_item_count++;
        }
        $retrieve_items = $max_items - $retriever_item_count;
    }
    while ($retrieve_items > 0);

    /* Look for items that are waiting even though the resource has
     * completed.  This usually happens because we've been asked to
     * retrospectively apply a config change.  It could also happen
     * due to a cron job dying or something. */
    $r = q("SELECT retriever_resource.`id` as resource, retriever_item.`id` as item FROM retriever_resource, retriever_item, retriever_rule WHERE retriever_item.`finished` = 0 AND retriever_item.`resource` = retriever_resource.`id` AND retriever_resource.`completed` IS NOT NULL AND retriever_item.`contact-id` = retriever_rule.`contact-id` AND retriever_item.`item-uid` = retriever_rule.`uid` LIMIT %d",
           intval($retrieve_items));
    if (!$r) {
        $r = array();
    }
    Logger::log('retriever_retrieve_items: items waiting even though resource has completed: ' . count($r), Logger::DEBUG);
    foreach ($r as $rr) {
        $resource = q("SELECT * FROM retriever_resource WHERE `id` = %d", $rr['resource']);
        $retriever_item = retriever_get_retriever_item($rr['item']);
        if (!$retriever_item) {
            Logger::log('retriever_retrieve_items: no retriever item with id ' . $rr['item'], Logger::INFO);
            continue;
        }
        $item = retriever_get_item($retriever_item);
        if (!$item) {
            Logger::log('retriever_retrieve_items: no item ' . $retriever_item['item-uri'], Logger::INFO);
            continue;
        }
        $retriever = get_retriever($item['contact-id'], $item['uid']);
        if (!$retriever) {
            Logger::log('retriever_retrieve_items: no retriever for item ' .
                   $retriever_item['item-uri'] . ' ' . $retriever_item['uid'] . ' ' . $item['contact-id'],
                   Logger::INFO);
            continue;
        }
        retriever_apply_completed_resource_to_item($retriever, $item, $resource[0], $a);
        q("UPDATE `retriever_item` SET `finished` = 1 WHERE id = %d",
          intval($retriever_item['id']));
        retriever_check_item_completed($item);
    }
}

function retriever_tidy() {
    q("DELETE FROM retriever_resource WHERE completed IS NOT NULL AND completed < DATE_SUB(now(), INTERVAL 1 WEEK)");
    q("DELETE FROM retriever_resource WHERE completed IS NULL AND created < DATE_SUB(now(), INTERVAL 3 MONTH)");

    $r = q("SELECT retriever_item.id FROM retriever_item LEFT OUTER JOIN retriever_resource ON (retriever_item.resource = retriever_resource.id) WHERE retriever_resource.id is null");
    Logger::log('retriever_tidy: found ' . count($r) . ' retriever_items with no retriever_resource');
    foreach ($r as $rr) {
        q('DELETE FROM retriever_item WHERE id = %d', intval($rr['id']));
    }
}

function retrieve_dataurl_resource($resource) {
    if (!preg_match("/date:(.*);base64,(.*)/", $resource['url'], $matches)) {
        Logger::log('retrieve_dataurl_resource: ' . $resource['id'] . ' does not match pattern');
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
    if (substr($resource['url'], 0, 5) == "data:") {
        return retrieve_dataurl_resource($resource);
    }

    $a = get_app();

    try {
        Logger::log('retrieve_resource: ' . ($resource['num-tries'] + 1) .
               ' attempt at resource ' . $resource['id'] . ' ' . $resource['url'], Logger::DEBUG);
        $redirects = 0;
        $cookiejar = tempnam(get_temppath(), 'cookiejar-retriever-');
        $fetch_result = Network::fetchUrlFull($resource['url'], $resource['binary'], $redirects, '', $cookiejar);
        unlink($cookiejar);
        $resource['data'] = $fetch_result->getBody();
        $resource['http-code'] = $fetch_result->getReturnCode();
        $resource['type'] = $fetch_result->getContentType();
        $resource['redirect-url'] = $fetch_result->getRedirectUrl();
        Logger::log('retrieve_resource: got code ' . $resource['http-code'] .
               ' retrieving resource ' . $resource['id'] .
               ' final url ' . $resource['redirect-url'], Logger::DEBUG);
    } catch (Exception $e) {
        Logger::log('retrieve_resource: unable to retrieve ' . $resource['url'] . ' - ' . $e->getMessage());
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
}

function get_retriever($contact_id, $uid, $create = false) {
    $r = q("SELECT * FROM `retriever_rule` WHERE `contact-id` = %d AND `uid` = %d",
           intval($contact_id), intval($uid));
    if (count($r)) {
        $r[0]['data'] = json_decode($r[0]['data'], true);
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
    $retriever_items = q("SELECT * FROM `retriever_item` WHERE id = %d", intval($id));
    if (count($retriever_items) != 1) {
        Logger::log('retriever_get_retriever_item: unable to find retriever_item ' . $id, Logger::INFO);
        return;
    }
    return $retriever_items[0];
}

function retriever_get_item($retriever_item) {
    // @@@ Need to replace this with Item::selectFirst
    $items = q("SELECT * FROM `item` WHERE `uri` = '%s' AND `uid` = %d AND `contact-id` = %d",
               DBA::escape($retriever_item['item-uri']),
               intval($retriever_item['item-uid']),
               intval($retriever_item['contact-id']));
    if (count($items) != 1) {
        Logger::log('retriever_get_item: unexpected number of results ' .
               count($items) . " when searching for item $uri $uid $cid", Logger::INFO);
        return;
    }
    return $items[0];
}

function retriever_item_completed($retriever_item_id, $resource, $a) {
    Logger::log('retriever_item_completed: id ' . $retriever_item_id . ' url ' . $resource['url'], Logger::DEBUG);

    $retriever_item = retriever_get_retriever_item($retriever_item_id);
    if (!$retriever_item) {
        return;
    }
    // Note: the retriever might be null.  Doesn't matter.
    $retriever = get_retriever($retriever_item['contact-id'], $retriever_item['item-uid']);
    $item = retriever_get_item($retriever_item);
    if (!$item) {
        return;
    }

    retriever_apply_completed_resource_to_item($retriever, $item, $resource, $a);

    q("UPDATE `retriever_item` SET `finished` = 1 WHERE id = %d",
      intval($retriever_item['id']));
    retriever_check_item_completed($item);
}

function retriever_resource_completed($resource, $a) {
    Logger::log('retriever_resource_completed: id ' . $resource['id'] . ' url ' . $resource['url'], Logger::DEBUG);
    $r = q("SELECT `id` FROM `retriever_item` WHERE `resource` = %d", $resource['id']);
    foreach ($r as $rr) {
        retriever_item_completed($rr['id'], $resource, $a);
    }
}

function apply_retrospective($a, $retriever, $num) {
    $r = q("SELECT * FROM `item` WHERE `contact-id` = %d ORDER BY `received` DESC LIMIT %d",
           intval($retriever['contact-id']), intval($num));
    foreach ($r as $item) {
        q('UPDATE `item` SET `visible` = 0 WHERE `id` = %d', $item['id']);
        q('UPDATE `thread` SET `visible` = 0 WHERE `iid` = %d', $item['id']);
        retriever_on_item_insert($a, $retriever, $item);
    }
}

function retriever_on_item_insert($a, $retriever, &$item) {
    if (!$retriever || !$retriever['id']) {
        Logger::log('retriever_on_item_insert: No retriever supplied', Logger::INFO);
        return;
    }
    if (!$retriever["data"]['enable'] == "on") {
        return;
    }
    if (array_key_exists('pattern', $retriever["data"]) && $retriever["data"]['pattern']) {
        $url = preg_replace('/' . $retriever["data"]['pattern'] . '/', $retriever["data"]['replace'], $item['plink']);
        Logger::log('retriever_on_item_insert: Changed ' . $item['plink'] . ' to ' . $url, Logger::DATA);
    }
    else {
        $url = $item['plink'];
    }

    $resource = add_retriever_resource($a, $url);
    $retriever_item_id = add_retriever_item($item, $resource);
}

function add_retriever_resource($a, $url, $binary = false) {
    Logger::log('add_retriever_resource: ' . $url, Logger::DEBUG);

    $scheme = parse_url($url, PHP_URL_SCHEME);
    if ($scheme == 'data') {
        $fp = fopen($url, 'r');
        $meta = stream_get_meta_data($fp);
        $type = $meta['mediatype'];
        $data = stream_get_contents($fp);
        fclose($fp);

        $url = 'md5://' . hash('md5', $url);
        $r = q("SELECT * FROM `retriever_resource` WHERE `url` = '%s'", DBA::escape($url));
        $resource = $r[0];
        if (count($r)) {
            Logger::log('add_retriever_resource: Resource ' . $url . ' already requested', Logger::DEBUG);
            return $resource;
        }

        Logger::log('retrieve_resource: got data URL type ' . $resource['type'], Logger::DEBUG);
        q("INSERT INTO `retriever_resource` (`type`, `binary`, `url`, `completed`, `data`) " .
          "VALUES ('%s', %d, '%s', now(), '%s')",
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
        Logger::log('add_retriever_resource: URL is longer than 800 characters', Logger::INFO);
    }

    $r = q("SELECT * FROM `retriever_resource` WHERE `url` = '%s'", DBA::escape($url));
    if (count($r)) {
        Logger::log('add_retriever_resource: Resource ' . $url . ' already requested', Logger::DEBUG);
        return $r[0];
    }

    q("INSERT INTO `retriever_resource` (`binary`, `url`) " .
      "VALUES (%d, '%s')", intval($binary ? 1 : 0), DBA::escape($url));
    $r = q("SELECT * FROM `retriever_resource` WHERE `url` = '%s'", DBA::escape($url));
    return $r[0];
}

function add_retriever_item(&$item, $resource) {
    Logger::log('add_retriever_item: ' . $resource['url'] . ' for ' . $item['uri'] . ' ' . $item['uid'] . ' ' . $item['contact-id'], Logger::DEBUG);

    q("INSERT INTO `retriever_item` (`item-uri`, `item-uid`, `contact-id`, `resource`) " .
      "VALUES ('%s', %d, %d, %d)",
      DBA::escape($item['uri']), intval($item['uid']), intval($item['contact-id']), intval($resource["id"]));
    $r = q("SELECT id FROM `retriever_item` WHERE " .
           "`item-uri` = '%s' AND `item-uid` = %d AND `contact-id` = %d AND `resource` = %d ORDER BY id DESC",
           DBA::escape($item['uri']), intval($item['uid']), intval($item['contact-id']), intval($resource['id']));
    if (!count($r)) {
        Logger::log("add_retriever_item: couldn't create retriever item for " .
               $item['uri'] . ' ' . $item['uid'] . ' ' . $item['contact-id'],
               Logger::INFO);
        return;
    }
    Logger::log('add_retriever_item: created retriever_item ' . $r[0]['id'] . ' for item ' . $item['uri'] . ' ' . $item['uid'] . ' ' . $item['contact-id'], Logger::DEBUG);
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
        Logger::log('retriever_apply_xslt_text: empty XSLT text', Logger::INFO);
        return $doc;
    }
    $xslt_doc = new DOMDocument();
    if (!$xslt_doc->loadXML($xslt_text)) {
        Logger::log('retriever_apply_xslt_text: could not load XML', Logger::INFO);
        return $doc;
    }
    $xp = new XsltProcessor();
    $xp->importStylesheet($xslt_doc);
    $result = $xp->transformToDoc($doc);
    return $result;
}

function retriever_apply_dom_filter($retriever, &$item, $resource) {
    Logger::log('retriever_apply_dom_filter: applying XSLT to ' . $item['id'] . ' ' . $item['uri'] . ' contact ' . $item['contact-id'], Logger::DEBUG);

    if (!array_key_exists('include', $retriever['data']) && !array_key_exists('customxslt', $retriever['data'])) {
        Logger::log('retriever_apply_dom_filter: no include and no customxslt', Logger::INFO);
        return;
    }
    if (!$resource['data']) {
        Logger::log('retriever_apply_dom_filter: no text to work with', Logger::INFO);
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
        Logger::log('retriever_apply_dom_filter: applying include/exclude template \"' . $extract_xslt . '\"', Logger::DEBUG);
        $doc = retriever_apply_xslt_text($extract_xslt, $doc);
    }
    if (array_key_exists('customxslt', $retriever['data']) && $retriever['data']['customxslt']) {
        Logger::log('retriever_apply_dom_filter: applying custom XSLT \"' . $retriever['data']['customxslt'] . '\"', Logger::DEBUG);
        $doc = retriever_apply_xslt_text($retriever['data']['customxslt'], $doc);
    }
    if (!$doc) {
        Logger::log('retriever_apply_dom_filter: failed to apply extract XSLT template', Logger::INFO);
        return;
    }

    $components = parse_url($resource['redirect-url']);
    $rooturl = $components['scheme'] . "://" . $components['host'];
    $dirurl = $rooturl . dirname($components['path']) . "/";
    $params = array('$dirurl' => $dirurl, '$rooturl' => $rooturl);
    $fix_urls_template = Renderer::getMarkupTemplate('fix-urls.tpl', 'addon/retriever/');
    $fix_urls_xslt = Renderer::replaceMacros($fix_urls_template, $params);
    $doc = retriever_apply_xslt_text($fix_urls_xslt, $doc);
    if (!$doc) {
        Logger::log('retriever_apply_dom_filter: failed to apply fix urls XSLT template', Logger::INFO);
        return;
    }

    $body = HTML::toBBCode($doc->saveHTML());
    if (!strlen($body)) {
        Logger::log('retriever_apply_dom_filter retriever ' . $retriever['id'] . ' item ' . $item['id'] . ': output was empty', Logger::INFO);
        return;
    }
    $body .= "\n\n" . L10n::t('Retrieved') . ' ' . date("Y-m-d") . ': [url=';
    $body .=  $item['plink'];
    $body .= ']' . $item['plink'] . '[/url]';

    $uri_id = ItemURI::getIdByURI($item['uri']);
    //@@@ remove this
    $item['body'] = $body;
    Logger::log('retriever_apply_dom_filter: XSLT result \"' . $body . '\"', Logger::DATA);
    DBA::update('item', ['body' => $body], ['id' => $item['id']]);
    DBA::update('item-content', ['body' => $body], ['uri-id' => $uri_id]);
}

function retrieve_images(&$item, $a) {
    $matches1 = array();
    preg_match_all("/\[img\=([0-9]*)x([0-9]*)\](.*?)\[\/img\]/ism", $item["body"], $matches1);
    $matches2 = array();
    preg_match_all("/\[img\](.*?)\[\/img\]/ism", $item["body"], $matches2);
    $matches = array_merge($matches1[3], $matches2[1]);
    Logger::log('retrieve_images: found ' . count($matches) . ' images for item ' . $item['uri'] . ' ' . $item['uid'] . ' ' . $item['contact-id'], Logger::DEBUG);
    foreach ($matches as $url) {
        if (strpos($url, get_app()->getBaseUrl()) === FALSE) {
            $resource = add_retriever_resource($a, $url, true);
            if (!$resource['completed']) {
                add_retriever_item($item, $resource);
            }
            else {
                retriever_transform_images($a, $item, $resource);
            }
        }
    }
}

function retriever_check_item_completed(&$item)
{
    $r = q('SELECT count(*) FROM retriever_item WHERE `item-uri` = "%s" ' .
           'AND `item-uid` = %d AND `contact-id` = %d AND `finished` = 0',
           DBA::escape($item['uri']), intval($item['uid']),
           intval($item['contact-id']));
    $waiting = $r[0]['count(*)'];
    Logger::log('retriever_check_item_completed: item ' . $item['uri'] . ' ' . $item['uid']
           . ' '. $item['contact-id'] . ' waiting for ' . $waiting . ' resources', Logger::DEBUG);
    $old_visible = $item['visible'];
    $item['visible'] = $waiting ? 0 : 1;
    if (array_key_exists('id', $item) && ($item['id'] > 0) && ($old_visible != $item['visible'])) {
        Logger::log('retriever_check_item_completed: changing visible flag to ' . $item['visible'] . ' and invoking notifier ("edit_post", ' . $item['id'] . ')', Logger::DEBUG);
        q("UPDATE `item` SET `visible` = %d WHERE `id` = %d",
          intval($item['visible']),
          intval($item['id']));
        q("UPDATE `thread` SET `visible` = %d WHERE `iid` = %d",
          intval($item['visible']),
          intval($item['id']));
    }
}

function retriever_apply_completed_resource_to_item($retriever, &$item, $resource, $a) {
    Logger::log('retriever_apply_completed_resource_to_item: retriever ' .
           ($retriever ? $retriever['id'] : 'none') .
           ' resource ' . $resource['url'] . ' plink ' . $item['plink'], Logger::DEBUG);
    if (strpos($resource['type'], 'image') !== false) {
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

function retriever_transform_images($a, &$item, $resource) {
    if (!$resource["data"]) {
        Logger::log('retriever_transform_images: no data available for '
               . $resource['id'] . ' ' . $resource['url'], Logger::INFO);
        return;
    }

    try {
        $photo = Image::storePhoto($a, $item['uid'], $resource['data'], $resource['url']);
    } catch (Exception $e) {
        Logger::log('retriever_transform_images caught exception ' . $e->getMessage());
        return;
    }
    if (!array_key_exists('full', $photo)) {
        Logger::log('retriever_transform_images: no replacement URL for image ' . $resource['url']);
        return;
    }
    $new_url = $photo['full'];
    Logger::log('retriever_transform_images: replacing ' . $resource['url'] . ' with ' .
           $new_url . ' in item ' . $item['plink'], Logger::DEBUG);
    $transformed = str_replace($resource["url"], $new_url, $item['body']);
    if ($transformed === $item['body']) {
        return;
    }

    $item['body'] = $transformed;
    q("UPDATE `item` SET `body` = '%s' WHERE `plink` = '%s' AND `uid` = %d AND `contact-id` = %d",
      DBA::escape($item['body']),
      DBA::escape($item['plink']),
      intval($item['uid']),
      intval($item['contact-id']));
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
        $retriever = get_retriever($a->argv[1], local_user(), false);

        if (!empty($_POST["id"])) {
            $retriever = get_retriever($a->argv[1], local_user(), true);
            $retriever["data"] = array();
            foreach (array('pattern', 'replace', 'enable', 'images', 'customxslt', 'storecookies', 'cookiedata') as $setting) {
                if (!empty($_POST['retriever_' . $setting])) {
                    $retriever["data"][$setting] = $_POST['retriever_' . $setting];
                }
            }
            foreach ($_POST as $k=>$v) {
                if (preg_match("/retriever-(include|exclude)-(\d+)-(element|attribute|value)/", $k, $matches)) {
                    $retriever['data'][$matches[1]][intval($matches[2])][$matches[3]] = $v;
                }
            }
            // You've gotta have an element, even if it's just "*"
            foreach ($retriever['data']['include'] as $k=>$clause) {
                if (!$clause['element']) {
                    unset($retriever['data']['include'][$k]);
                }
            }
            foreach ($retriever['data']['exclude'] as $k=>$clause) {
                if (!$clause['element']) {
                    unset($retriever['data']['exclude'][$k]);
                }
            }
            q("UPDATE `retriever_rule` SET `data`='%s' WHERE `id` = %d",
              DBA::escape(json_encode($retriever["data"])), intval($retriever["id"]));
            $a->page['content'] .= "<p><b>Settings Updated";
            if (!empty($_POST["retriever_retrospective"])) {
                apply_retrospective($a, $retriever, $_POST["retriever_retrospective"]);
                $a->page['content'] .= " and retrospectively applied to " . $_POST["apply"] . " posts";
            }
            $a->page['content'] .= ".</p></b>";
        }

        $template = Renderer::getMarkupTemplate('/rule-config.tpl', 'addon/retriever/');
        $a->page['content'] .= Renderer::replaceMacros($template, array(
                                                  '$enable' => array(
                                                      'retriever_enable',
                                                      L10n::t('Enabled'),
                                                      $retriever['data']['enable']),
                                                  '$pattern' => array(
                                                      'retriever_pattern',
                                                      L10n::t('URL Pattern'),
                                                      $retriever["data"]['pattern'],
                                                      L10n::t('Regular expression matching part of the URL to replace')),
                                                  '$replace' => array(
                                                      'retriever_replace',
                                                      L10n::t('URL Replace'),
                                                      $retriever["data"]['replace'],
                                                      L10n::t('Text to replace matching part of above regular expression')),
                                                  '$images' => array(
                                                      'retriever_images',
                                                      L10n::t('Download Images'),
                                                      $retriever['data']['images']),
                                                  '$retrospective' => array(
                                                      'retriever_retrospective',
                                                      L10n::t('Retrospectively Apply'),
                                                      '0',
                                                      L10n::t('Reapply the rules to this number of posts')),
                                                  'storecookies' => array(
                                                      'retriever_storecookies',
                                                      L10n::t('Store cookies'),
                                                      $retriever['data']['storecookies'],
                                                      L10n::t("Preserve cookie data across fetches.")),
                                                  '$cookiedata' => array(
                                                      'retriever_cookiedata',
                                                      L10n::t('Cookie Data'),
                                                      $retriever['data']['cookiedata'],
                                                      L10n::t("Latest cookie data for this feed.  Netscape cookie file format.")),
                                                  '$customxslt' => array(
                                                      'retriever_customxslt',
                                                      L10n::t('Custom XSLT'),
                                                      $retriever['data']['customxslt'],
                                                      L10n::t("When standard rules aren't enough, apply custom XSLT to the article")),
                                                  '$title' => L10n::t('Retrieve Feed Content'),
                                                  '$help' => $a->getBaseUrl() . '/retriever/help',
                                                  '$help_t' => L10n::t('Get Help'),
                                                  '$submit_t' => L10n::t('Submit'),
                                                  '$submit' => L10n::t('Save Settings'),
                                                  '$id' => ($retriever["id"] ? $retriever["id"] : "create"),
                                                  '$tag_t' => L10n::t('Tag'),
                                                  '$attribute_t' => L10n::t('Attribute'),
                                                  '$value_t' => L10n::t('Value'),
                                                  '$add_t' => L10n::t('Add'),
                                                  '$remove_t' => L10n::t('Remove'),
                                                  '$include_t' => L10n::t('Include'),
                                                  '$include' => $retriever['data']['include'],
                                                  '$exclude_t' => L10n::t('Exclude'),
                                                  '$exclude' => $retriever["data"]['exclude']));
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
    Logger::log('retriever_post_remote_hook: ' . $item['uri'] . ' ' . $item['uid'] . ' ' . $item['contact-id'], Logger::DEBUG);

    $retriever = get_retriever($item['contact-id'], $item["uid"], false);
    if ($retriever) {
        retriever_on_item_insert($a, $retriever, $item);
    }
    else {
        if (PConfig::get($item["uid"], 'retriever', 'oembed')) {
            // Convert to HTML and back to take advantage of bbcode's resolution of oembeds.
            $body = HTML::toBBCode(BBCode::convert($item['body']));
            if ($body) {
                $item['body'] = $body;
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
