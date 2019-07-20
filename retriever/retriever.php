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
use Friendica\Content\Text\HTML;
use Friendica\Content\Text\BBCode;
use Friendica\Object\Image;
use Friendica\Util\Network;
use Friendica\Core\L10n;
use Friendica\Database\DBA;

function retriever_install() {
    Addon::registerHook('plugin_settings', 'addon/retriever/retriever.php', 'retriever_plugin_settings');
    Addon::registerHook('plugin_settings_post', 'addon/retriever/retriever.php', 'retriever_plugin_settings_post');
    Addon::registerHook('post_remote', 'addon/retriever/retriever.php', 'retriever_post_remote_hook');
    Addon::registerHook('contact_photo_menu', 'addon/retriever/retriever.php', 'retriever_contact_photo_menu');
    Addon::registerHook('cron', 'addon/retriever/retriever.php', 'retriever_cron');

    $r = q("SELECT `id` FROM `pconfig` WHERE `cat` LIKE 'retriever_%%'");
    if (count($r) || (Config::get('retriever', 'dbversion') == '0.1')) {
        $retrievers = array();
        $r = q("SELECT SUBSTRING(`cat`, 10) AS `contact`, `k`, `v` FROM `pconfig` WHERE `cat` LIKE 'retriever%%'");
        foreach ($r as $rr) {
            $retrievers[$rr['contact']][$rr['k']] = $rr['v'];
        }
        foreach ($retrievers as $k => $v) {
            $rr = q("SELECT `uid` FROM `contact` WHERE `id` = %d", intval($k));
            $uid = $rr[0]['uid'];
            $v['images'] = 'on';
            q("INSERT INTO `retriever_rule` (`uid`, `contact-id`, `data`) VALUES (%d, %d, '%s')",
              intval($uid), intval($k), DBA::escape(json_encode($v)));
        }
        q("DELETE FROM `pconfig` WHERE `cat` LIKE 'retriever_%%'");
        Config::set('retriever', 'dbversion', '0.2');
    }
    if (Config::get('retriever', 'dbversion') == '0.2') {
        q("ALTER TABLE `retriever_resource` DROP COLUMN `retriever`");
        Config::set('retriever', 'dbversion', '0.3');
    }
    if (Config::get('retriever', 'dbversion') == '0.3') {
        q("ALTER TABLE `retriever_item` MODIFY COLUMN `item-uri` varchar(800) CHARACTER SET ascii NOT NULL");
        q("ALTER TABLE `retriever_resource` MODIFY COLUMN `url` varchar(800) CHARACTER SET ascii NOT NULL");
        Config::set('retriever', 'dbversion', '0.4');
    }
    if (Config::get('retriever', 'dbversion') == '0.4') {
        q("ALTER TABLE `retriever_item` ADD COLUMN `finished` tinyint(1) unsigned NOT NULL DEFAULT '0'");
        Config::set('retriever', 'dbversion', '0.5');
    }
    if (Config::get('retriever', 'dbversion') == '0.5') {
        q('ALTER TABLE `retriever_resource` CHANGE `created` `created` timestamp NOT NULL DEFAULT now()');
        q('ALTER TABLE `retriever_resource` CHANGE `completed` `completed` timestamp NULL DEFAULT NULL');
        q('ALTER TABLE `retriever_resource` CHANGE `last-try` `last-try` timestamp NULL DEFAULT NULL');
        q('ALTER TABLE `retriever_item` DROP KEY `all`');
        q('ALTER TABLE `retriever_item` ADD KEY `all` (`item-uri`, `item-uid`, `contact-id`)');
        Config::set('retriever', 'dbversion', '0.6');
    }
    if (Config::get('retriever', 'dbversion') == '0.6') {
        q('ALTER TABLE `retriever_item` CONVERT TO CHARACTER SET utf8 COLLATE utf8_bin');
        q('ALTER TABLE `retriever_item` CHANGE `item-uri` `item-uri`  varchar(800) CHARACTER SET ascii COLLATE ascii_bin NOT NULL');
        q('ALTER TABLE `retriever_resource` CONVERT TO CHARACTER SET utf8 COLLATE utf8_bin');
        q('ALTER TABLE `retriever_resource` CHANGE `url` `url`  varchar(800) CHARACTER SET ascii COLLATE ascii_bin NOT NULL');
        q('ALTER TABLE `retriever_rule` CONVERT TO CHARACTER SET utf8 COLLATE utf8_bin');
        Config::set('retriever', 'dbversion', '0.7');
    }
    if (Config::get('retriever', 'dbversion') == '0.7') {
        $r = q("SELECT `id`, `data` FROM `retriever_rule`");
        foreach ($r as $rr) {
            logger('retriever_install: retriever ' . $rr['id'] . ' old config ' . $rr['data'], LOGGER_DATA);
            $data = json_decode($rr['data'], true);
            if ($data['pattern']) {
                $matches = array();
                if (preg_match("/\/(.*)\//", $data['pattern'], $matches)) {
                    $data['pattern'] = $matches[1];
                }
            }
            if ($data['match']) {
                $include = array();
                foreach (explode('|', $data['match']) as $component) {
                    $matches = array();
                    if (preg_match("/([A-Za-z][A-Za-z0-9]*)\[@([A-Za-z][a-z0-9]*)='([^']*)'\]/", $component, $matches)) {
                        $include[] = array(
                            'element' => $matches[1],
                            'attribute' => $matches[2],
                            'value' => $matches[3]);
                    }
                    if (preg_match("/([A-Za-z][A-Za-z0-9]*)\[contains(concat(' ',normalize-space(@class),' '),' ([^ ']+) ')]/", $component, $matches)) {
                        $include[] = array(
                            'element' => $matches[1],
                            'attribute' => $matches[2],
                            'value' => $matches[3]);
                    }
                }
                $data['include'] = $include;
                unset($data['match']);
            }
            if ($data['remove']) {
                $exclude = array();
                foreach (explode('|', $data['remove']) as $component) {
                    $matches = array();
                    if (preg_match("/([A-Za-z][A-Za-z0-9]*)\[@([A-Za-z][a-z0-9]*)='([^']*)'\]/", $component, $matches)) {
                        $exclude[] = array(
                            'element' => $matches[1],
                            'attribute' => $matches[2],
                            'value' => $matches[3]);
                    }
                    if (preg_match("/([A-Za-z][A-Za-z0-9]*)\[contains(concat(' ',normalize-space(@class),' '),' ([^ ']+) ')]/", $component, $matches)) {
                        $exclude[] = array(
                            'element' => $matches[1],
                            'attribute' => $matches[2],
                            'value' => $matches[3]);
                    }
                }
                $data['exclude'] = $exclude;
                unset($data['remove']);
            }
            $r = q('UPDATE `retriever_rule` SET `data` = "%s" WHERE `id` = %d', DBA::escape(json_encode($data)), $rr['id']);
            logger('retriever_install: retriever ' . $rr['id'] . ' new config ' . json_encode($data), LOGGER_DATA);
        }
        Config::set('retriever', 'dbversion', '0.8');
    }
    if (Config::get('retriever', 'dbversion') == '0.8') {
        q("ALTER TABLE `retriever_resource` ADD COLUMN `http-code` smallint(1) unsigned NULL DEFAULT NULL");
        Config::set('retriever', 'dbversion', '0.9');
    }
    if (Config::get('retriever', 'dbversion') == '0.9') {
        q("ALTER TABLE `retriever_item` DROP COLUMN `parent`");
        q("ALTER TABLE `retriever_resource` ADD COLUMN `redirect-url` varchar(800) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL");
        Config::set('retriever', 'dbversion', '0.10');
    }
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
    logger('retriever_retrieve_items: asked for maximum ' . $max_items . ', already retrieved ' . $retriever_item_count . ', retrieve ' . $retrieve_items, LOGGER_DEBUG);
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
        logger('retriever_retrieve_items: found ' . count($r) . ' waiting resources in database', LOGGER_DEBUG);
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
    logger('retriever_retrieve_items: items waiting even though resource has completed: ' . count($r), LOGGER_DEBUG);
    foreach ($r as $rr) {
        $resource = q("SELECT * FROM retriever_resource WHERE `id` = %d", $rr['resource']);
        $retriever_item = retriever_get_retriever_item($rr['item']);
        if (!$retriever_item) {
            logger('retriever_retrieve_items: no retriever item with id ' . $rr['item'], LOGGER_INFO);
            continue;
        }
        $item = retriever_get_item($retriever_item);
        if (!$item) {
            logger('retriever_retrieve_items: no item ' . $retriever_item['item-uri'], LOGGER_INFO);
            continue;
        }
        $retriever = get_retriever($item['contact-id'], $item['uid']);
        if (!$retriever) {
            logger('retriever_retrieve_items: no retriever for item ' .
                   $retriever_item['item-uri'] . ' ' . $retriever_item['uid'] . ' ' . $item['contact-id'],
                   LOGGER_INFO);
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
    logger('retriever_tidy: found ' . count($r) . ' retriever_items with no retriever_resource');
    foreach ($r as $rr) {
        q('DELETE FROM retriever_item WHERE id = %d', intval($rr['id']));
    }
}

function retrieve_dataurl_resource($resource) {
    if (!preg_match("/date:(.*);base64,(.*)/", $resource['url'], $matches)) {
        logger('retrieve_dataurl_resource: ' . $resource['id'] . ' does not match pattern');
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
        logger('retrieve_resource: ' . ($resource['num-tries'] + 1) .
               ' attempt at resource ' . $resource['id'] . ' ' . $resource['url'], LOGGER_DEBUG);
        $redirects;
        $cookiejar = tempnam(get_temppath(), 'cookiejar-retriever-');
        $fetch_result = Network::fetchUrlFull($resource['url'], $resource['binary'], $redirects, array('cookiejar' => $cookiejar));
        unlink($cookiejar);
        $resource['data'] = $fetch_result['body'];
        $resource['http-code'] = $a->get_curl_code();
        $resource['type'] = $a->get_curl_content_type();
        $resource['redirect-url'] = $fetch_result['redirect_url'];
        logger('retrieve_resource: got code ' . $resource['http-code'] .
               ' retrieving resource ' . $resource['id'] .
               ' final url ' . $resource['redirect-url'], LOGGER_DEBUG);
    } catch (Exception $e) {
        logger('retrieve_resource: unable to retrieve ' . $resource['url'] . ' - ' . $e->getMessage());
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
        logger('retriever_get_retriever_item: unable to find retriever_item ' . $id, LOGGER_INFO);
        return;
    }
    return $retriever_items[0];
}

function retriever_get_item($retriever_item) {
    $items = q("SELECT * FROM `item` WHERE `uri` = '%s' AND `uid` = %d AND `contact-id` = %d",
               DBA::escape($retriever_item['item-uri']),
               intval($retriever_item['item-uid']),
               intval($retriever_item['contact-id']));
    if (count($items) != 1) {
        logger('retriever_get_item: unexpected number of results ' .
               count($items) . " when searching for item $uri $uid $cid", LOGGER_INFO);
        return;
    }
    return $items[0];
}

function retriever_item_completed($retriever_item_id, $resource, $a) {
    logger('retriever_item_completed: id ' . $retriever_item_id . ' url ' . $resource['url'], LOGGER_DEBUG);

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
    logger('retriever_resource_completed: id ' . $resource['id'] . ' url ' . $resource['url'], LOGGER_DEBUG);
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
        logger('retriever_on_item_insert: No retriever supplied', LOGGER_INFO);
        return;
    }
    if (!$retriever["data"]['enable'] == "on") {
        return;
    }
    if (array_key_exists('pattern', $retriever["data"]) && $retriever["data"]['pattern']) {
        $url = preg_replace('/' . $retriever["data"]['pattern'] . '/', $retriever["data"]['replace'], $item['plink']);
        logger('retriever_on_item_insert: Changed ' . $item['plink'] . ' to ' . $url, LOGGER_DATA);
    }
    else {
        $url = $item['plink'];
    }

    $resource = add_retriever_resource($a, $url);
    $retriever_item_id = add_retriever_item($item, $resource);
}

function add_retriever_resource($a, $url, $binary = false) {
    logger('add_retriever_resource: ' . $url, LOGGER_DEBUG);

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
            logger('add_retriever_resource: Resource ' . $url . ' already requested', LOGGER_DEBUG);
            return $resource;
        }

        logger('retrieve_resource: got data URL type ' . $resource['type'], LOGGER_DEBUG);
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
        logger('add_retriever_resource: URL is longer than 800 characters', LOGGER_INFO);
    }

    $r = q("SELECT * FROM `retriever_resource` WHERE `url` = '%s'", DBA::escape($url));
    if (count($r)) {
        logger('add_retriever_resource: Resource ' . $url . ' already requested', LOGGER_DEBUG);
        return $r[0];
    }

    q("INSERT INTO `retriever_resource` (`binary`, `url`) " .
      "VALUES (%d, '%s')", intval($binary ? 1 : 0), DBA::escape($url));
    $r = q("SELECT * FROM `retriever_resource` WHERE `url` = '%s'", DBA::escape($url));
    return $r[0];
}

function add_retriever_item(&$item, $resource) {
    logger('add_retriever_item: ' . $resource['url'] . ' for ' . $item['uri'] . ' ' . $item['uid'] . ' ' . $item['contact-id'], LOGGER_DEBUG);

    q("INSERT INTO `retriever_item` (`item-uri`, `item-uid`, `contact-id`, `resource`) " .
      "VALUES ('%s', %d, %d, %d)",
      DBA::escape($item['uri']), intval($item['uid']), intval($item['contact-id']), intval($resource["id"]));
    $r = q("SELECT id FROM `retriever_item` WHERE " .
           "`item-uri` = '%s' AND `item-uid` = %d AND `contact-id` = %d AND `resource` = %d ORDER BY id DESC",
           DBA::escape($item['uri']), intval($item['uid']), intval($item['contact-id']), intval($resource['id']));
    if (!count($r)) {
        logger("add_retriever_item: couldn't create retriever item for " .
               $item['uri'] . ' ' . $item['uid'] . ' ' . $item['contact-id'],
               LOGGER_INFO);
        return;
    }
    logger('add_retriever_item: created retriever_item ' . $r[0]['id'] . ' for item ' . $item['uri'] . ' ' . $item['uid'] . ' ' . $item['contact-id'], LOGGER_DEBUG);
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
        logger('retriever_apply_xslt_text: empty XSLT text', LOGGER_INFO);
        return $doc;
    }
    $xslt_doc = new DOMDocument();
    if (!$xslt_doc->loadXML($xslt_text)) {
        logger('retriever_apply_xslt_text: could not load XML', LOGGER_INFO);
        return $doc;
    }
    $xp = new XsltProcessor();
    $xp->importStylesheet($xslt_doc);
    $result = $xp->transformToDoc($doc);
    return $result;
}

function retriever_apply_dom_filter($retriever, &$item, $resource) {
    logger('retriever_apply_dom_filter: applying XSLT to ' . $item['id'] . ' ' . $item['uri'] . ' contact ' . $item['contact-id'], LOGGER_DEBUG);

    if (!array_key_exists('include', $retriever['data']) && !array_key_exists('customxslt', $retriever['data'])) {
        logger('retriever_apply_dom_filter: no include and no customxslt', LOGGER_INFO);
        return;
    }
    if (!$resource['data']) {
        logger('retriever_apply_dom_filter: no text to work with', LOGGER_INFO);
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
    $extract_template = get_markup_template('extract.tpl', 'addon/retriever/');
    $extract_xslt = replace_macros($extract_template, $params);
    if ($retriever['data']['include']) {
        $doc = retriever_apply_xslt_text($extract_xslt, $doc);
    }
    if (array_key_exists('customxslt', $retriever['data']) && $retriever['data']['customxslt']) {
        $doc = retriever_apply_xslt_text($retriever['data']['customxslt'], $doc);
    }
    if (!$doc) {
        logger('retriever_apply_dom_filter: failed to apply extract XSLT template', LOGGER_INFO);
        return;
    }

    $components = parse_url($resource['redirect-url']);
    $rooturl = $components['scheme'] . "://" . $components['host'];
    $dirurl = $rooturl . dirname($components['path']) . "/";
    $params = array('$dirurl' => $dirurl, '$rooturl' => $rooturl);
    $fix_urls_template = get_markup_template('fix-urls.tpl', 'addon/retriever/');
    $fix_urls_xslt = replace_macros($fix_urls_template, $params);
    $doc = retriever_apply_xslt_text($fix_urls_xslt, $doc);
    if (!$doc) {
        logger('retriever_apply_dom_filter: failed to apply fix urls XSLT template', LOGGER_INFO);
        return;
    }

    $item['body'] = HTML::toBBCode($doc->saveHTML());
    if (!strlen($item['body'])) {
        logger('retriever_apply_dom_filter retriever ' . $retriever['id'] . ' item ' . $item['id'] . ': output was empty', LOGGER_INFO);
        return;
    }
    $item['body'] .= "\n\n" . L10n::t('Retrieved') . ' ' . date("Y-m-d") . ': [url=';
    $item['body'] .=  $item['plink'];
    $item['body'] .= ']' . $item['plink'] . '[/url]';
    DBA::update('item', ['body' => $item['body']], ['id' => $item['id']]);
    DBA::update('item-content', ['body' => $item['body']], ['uri' => $item['uri']]);
}

function retrieve_images(&$item, $a) {
    $matches1 = array();
    preg_match_all("/\[img\=([0-9]*)x([0-9]*)\](.*?)\[\/img\]/ism", $item["body"], $matches1);
    $matches2 = array();
    preg_match_all("/\[img\](.*?)\[\/img\]/ism", $item["body"], $matches2);
    $matches = array_merge($matches1[3], $matches2[1]);
    logger('retrieve_images: found ' . count($matches) . ' images for item ' . $item['uri'] . ' ' . $item['uid'] . ' ' . $item['contact-id'], LOGGER_DEBUG);
    foreach ($matches as $url) {
        if (strpos($url, get_app()->get_baseurl()) === FALSE) {
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
    logger('retriever_check_item_completed: item ' . $item['uri'] . ' ' . $item['uid']
           . ' '. $item['contact-id'] . ' waiting for ' . $waiting . ' resources', LOGGER_DEBUG);
    $old_visible = $item['visible'];
    $item['visible'] = $waiting ? 0 : 1;
    if (array_key_exists('id', $item) && ($item['id'] > 0) && ($old_visible != $item['visible'])) {
        logger('retriever_check_item_completed: changing visible flag to ' . $item['visible'] . ' and invoking notifier ("edit_post", ' . $item['id'] . ')', LOGGER_DEBUG);
        q("UPDATE `item` SET `visible` = %d WHERE `id` = %d",
          intval($item['visible']),
          intval($item['id']));
        q("UPDATE `thread` SET `visible` = %d WHERE `iid` = %d",
          intval($item['visible']),
          intval($item['id']));
    }
}

function retriever_apply_completed_resource_to_item($retriever, &$item, $resource, $a) {
    logger('retriever_apply_completed_resource_to_item: retriever ' .
           ($retriever ? $retriever['id'] : 'none') .
           ' resource ' . $resource['url'] . ' plink ' . $item['plink'], LOGGER_DEBUG);
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
        logger('retriever_transform_images: no data available for '
               . $resource['id'] . ' ' . $resource['url'], LOGGER_INFO);
        return;
    }

    try {
        $photo = Image::storePhoto($a, $item['uid'], $resource['data'], $resource['url']);
    } catch (Exception $e) {
        logger('retriever_transform_images caught exception ' . $e->getMessage());
        return;
    }
    if (!array_key_exists('full', $photo)) {
        logger('retriever_transform_images: no replacement URL for image ' . $resource['url']);
        return;
    }
    $new_url = $photo['full'];
    logger('retriever_transform_images: replacing ' . $resource['url'] . ' with ' .
           $new_url . ' in item ' . $item['plink'], LOGGER_DEBUG);
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
            $feeds[$k]['url'] = $a->get_baseurl() . '/retriever/' . $v['id'];
        }
        $template = get_markup_template('/help.tpl', 'addon/retriever/');
        $a->page['content'] .= replace_macros($template, array(
                                                  '$config' => $a->get_baseurl() . '/settings/addon',
                                                  '$feeds' => $feeds));
        return;
    }
    if ($a->argv[1]) {
        $retriever = get_retriever($a->argv[1], local_user(), false);

        if (x($_POST["id"])) {
            $retriever = get_retriever($a->argv[1], local_user(), true);
            $retriever["data"] = array();
            foreach (array('pattern', 'replace', 'enable', 'images', 'customxslt') as $setting) {
                if (x($_POST['retriever_' . $setting])) {
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
            if (x($_POST["retriever_retrospective"])) {
                apply_retrospective($a, $retriever, $_POST["retriever_retrospective"]);
                $a->page['content'] .= " and retrospectively applied to " . $_POST["apply"] . " posts";
            }
            $a->page['content'] .= ".</p></b>";
        }

        $template = get_markup_template('/rule-config.tpl', 'addon/retriever/');
        $a->page['content'] .= replace_macros($template, array(
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
                                                  '$customxslt' => array(
                                                      'retriever_customxslt',
                                                      L10n::t('Custom XSLT'),
                                                      $retriever['data']['customxslt'],
                                                      L10n::t("When standard rules aren't enough, apply custom XSLT to the article")),
                                                  '$title' => L10n::t('Retrieve Feed Content'),
                                                  '$help' => $a->get_baseurl() . '/retriever/help',
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
        $args["menu"][ 'retriever' ] = array(L10n::t('Retriever'), $a->get_baseurl() . '/retriever/' . $args["contact"]['id']);
    }
}

function retriever_post_remote_hook(&$a, &$item) {
    logger('retriever_post_remote_hook: ' . $item['uri'] . ' ' . $item['uid'] . ' ' . $item['contact-id'], LOGGER_DEBUG);

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
    $template = get_markup_template('/settings.tpl', 'addon/retriever/');
    $s .= replace_macros($template, array(
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
                             '$help' => $a->get_baseurl() . '/retriever/help'));
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
