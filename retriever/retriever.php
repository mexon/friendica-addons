<?php
/**
 * Name: Retrieve Feed Content
 * Description: Follow the permalink of RSS/Atom feed items and replace the summary with the full content.
 * Version: 0.2
 * Author: Matthew Exon <http://mat.exon.name>
 */

function retriever_install() {
    register_hook('plugin_settings', 'addon/retriever/retriever.php', 'retriever_plugin_settings');
    register_hook('plugin_settings_post', 'addon/retriever/retriever.php', 'retriever_plugin_settings_post');
    register_hook('post_remote', 'addon/retriever/retriever.php', 'retriever_post_remote_hook');
    register_hook('contact_photo_menu', 'addon/retriever/retriever.php', 'retriever_contact_photo_menu');
    register_hook('cron', 'addon/retriever/retriever.php', 'retriever_cron');

    $schema = file_get_contents(dirname(__file__).'/database.sql');
    $arr = explode(';', $schema);
    foreach ($arr as $a) {
        $r = q($a);
    }

    $r = q("SELECT `id` FROM `pconfig` WHERE `cat` LIKE 'retriever_%%'");
    if (count($r) || (get_config('retriever', 'dbversion') == '0.1')) {
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
              intval($uid), intval($k), dbesc(json_encode($v)));
        }
        q("DELETE FROM `pconfig` WHERE `cat` LIKE 'retriever%%'");
    }
    if (get_config('retriever', 'dbversion') == '0.2') {
        q("ALTER TABLE `retriever_resource` DROP COLUMN `retriever`");
    }
    set_config('retriever', 'dbversion', '0.3');
}

function retriever_uninstall() {
    unregister_hook('plugin_settings', 'addon/retriever/retriever.php', 'retriever_plugin_settings');
    unregister_hook('plugin_settings_post', 'addon/retriever/retriever.php', 'retriever_plugin_settings_post');
    unregister_hook('post_remote', 'addon/retriever/retriever.php', 'retriever_post_remote_hook');
    unregister_hook('plugin_settings', 'addon/retriever/retriever.php', 'retriever_plugin_settings');
    unregister_hook('plugin_settings_post', 'addon/retriever/retriever.php', 'retriever_plugin_settings_post');
    unregister_hook('contact_photo_menu', 'addon/retriever/retriever.php', 'retriever_contact_photo_menu');
    unregister_hook('cron', 'addon/retriever/retriever.php', 'retriever_cron');
}

function retriever_module() {}

function retriever_cron($a, $b) {
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
                   '(`num-tries` = ' . $i . ' AND TIMESTAMPADD(' . dbesc($unit) .
                   ', ' . intval($num) . ', `last-try`) < now())');
    }

    // 100 is a nice sane number.  Maybe this should be configurable.
    // Feel free to write me a bug about that, explaining in detail
    // how important it is to you.
    $r = q("SELECT * FROM `retriever_resource` WHERE `completed` IS NULL AND (`last-try` IS NULL OR %s) ORDER BY `last-try` ASC LIMIT 100",
           dbesc(implode($schedule_clauses, ' OR ')));
    foreach ($r as $rr) {
        retrieve_resource($rr);
    }
}

function retrieve_resource($resource) {
    logger('retriever_resource: ' . ($resource['num-tries'] + 1) .
           ' attempt at resource ' . $resource['url'], LOGGER_DEBUG);
    q("UPDATE `retriever_resource` SET `last-try` = now(), `num-tries` = `num-tries` + 1 WHERE id = %d",
      intval($resource['id']));
    $data = fetch_url($resource['url'], $resource['binary']);
    if ($data) {
        $resource['data'] = $data;
        q("UPDATE `retriever_resource` SET `completed` = now(), `data` = '%s' WHERE id = %d",
          dbesc($data), intval($resource['id']));
        resource_completed($resource);
    }
}

function get_retriever($contact_id, $uid, $create = false) {
    logger('get_retriever: Searching for retriever uid ' . $uid . ' contact ' . $contact_id . ($create ? ', CREATING' : ', read-only'));
    $r = q("SELECT * FROM `retriever_rule` WHERE `contact-id` = %d AND `uid` = %d",
           intval($contact_id), intval($uid));
    if (count($r)) {
        $r[0]['data'] = json_decode($r[0]['data']);
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

function retriever_item_completed($retriever_item_id, $resource) {
    logger('retriever_item_completed: id ' . $retriever_item_id . ' url ' . $resource['url'], LOGGER_DEBUG);
    $r = q("SELECT * FROM `retriever_item` WHERE id = %d", intval($retriever_item_id));
    if (!count($r)) {
        logger('Unable to find retriever_item ' . $retriever_item_id);
        return;
    }
    $retriever_item = $r[0];
    $retriever = get_retriever($retriever_item['contact-id'], $retriever_item['item-uid']);
    $items = q("SELECT * FROM `item` WHERE `uri` = '%s' AND `uid` = %d AND `contact-id` = %d",
               dbesc($retriever_item['item-uri']),
               intval($retriever_item['item-uid']),
               intval($retriever_item['contact-id']));

    foreach ($items as $item) {
        retriever_on_resource_completed($retriever, $item, $resource, $retriever_item);
    }
}

function resource_completed($resource) {
    logger('resource_completed: id ' . $resource['id'] . ' url ' . $resource['url'], LOGGER_DEBUG);
    $r = q("SELECT `id` FROM `retriever_item` WHERE `resource` = %d", $resource['id']);
    foreach ($r as $rr) {
        retriever_item_completed($rr['id'], $resource);
    }
}

function apply_retrospective($retriever, $num) {
    $r = q("SELECT * FROM `item` WHERE `contact-id` = %d ORDER BY `received` DESC LIMIT %d",
           intval($retriever['contact-id']), intval($num));
    foreach ($r as $item) {
        retriever_on_item_insert($retriever, $item);
    }
}

function retriever_on_item_insert($retriever, &$item) {
    if (!$retriever || !$retriever['id']) {
        logger('retriever_on_item_insert: No retriever supplied', LOGGER_ERROR);
        return;
    }
    if ($retriever["data"]->enable == "on") {
    }
    if ($retriever["data"]->pattern) {
        $url = preg_replace($retriever["data"]->pattern, $retriever["data"]->replace, $item['plink']);
        logger('retriever_on_item_insert: Changed ' . $item['plink'] . ' to ' . $url, LOGGER_DATA);
    }
    else {
        $url = $item['plink'];
    }

    $resource = add_retriever_resource($url, "html");
    add_retriever_item($item, $resource);
}

function add_retriever_resource($url, $type, $binary = false) {
    logger('add_retriever_resource: ' . $url, LOGGER_DEBUG);
    $r = q("SELECT * FROM `retriever_resource` WHERE `url` = '%s'", dbesc($url));
    $resource = $r[0];
    if (count($r)) {
        logger('add_retriever_resource: Resource ' . $url . ' already requested', LOGGER_DEBUG);
        return $r[0];
    }
    else {
        q("INSERT INTO `retriever_resource` (`type`, `binary`, `url`, `created`) " .
          "VALUES ('%s', %d, '%s', now())",
          dbesc($type), intval($binary ? 1 : 0), dbesc($url));
        $r = q("SELECT * FROM `retriever_resource` WHERE `url` = '%s'", dbesc($url));
        return $r[0];
    }
}

function add_retriever_item(&$item, $resource, $parent = null) {
    logger('add_retriever_item: ' . $resource['url'], LOGGER_DEBUG);

    $r = q("SELECT id FROM `retriever_item` WHERE " .
           "`item-uri` = '%s' AND `item-uid` = %d AND `contact-id` = %d AND `resource` = %d",
           dbesc($item['uri']), intval($item['uid']), intval($item['contact-id']), intval($resource['id']));
    if (count($r)) {
        logger("add_retriever_item: retriever item for " .
               $item['uid'] . ', id ' . $r[0]['id'] . " already exists, id " . $r[0]['id'], LOGGER_ERROR);
        return;
    }

    q("INSERT INTO `retriever_item` (`item-uri`, `item-uid`, `contact-id`, `resource`, `parent`) " .
      "VALUES ('%s', %d, %d, %d, 0)",
      dbesc($item['uri']), intval($item['uid']), intval($item['contact-id']), intval($resource["id"]));
    $r = q("SELECT id FROM `retriever_item` WHERE " .
           "`item-uri` = '%s' AND `item-uid` = %d AND `contact-id` = %d AND `resource` = %d",
           dbesc($item['uri']), intval($item['uid']), intval($item['contact-id']), intval($resource['id']));
    if (!count($r)) {
        logger("add_retriever_item: couldn't create retriever item for " .
               $item['uid'] . ', id ' . $r[0]['id'], LOGGER_ERROR);
    }
    q("UPDATE `retriever_item` SET `parent` = %d WHERE id = %d",
      intval($parent ? $parent['id'] : $r[0]['id']), intval($r[0]['id']));
    if ($resource["completed"] != "0000-00-00 00:00:00") {
        retriever_item_completed($r[0]['id'], $resource);
    }
}

function retriever_apply_dom_filter($retriever, &$item, $text) {
    logger('retriever_apply_dom_filter: applying XSLT to ' . $item['plink'], LOGGER_DEBUG);
    require_once('include/html2bbcode.php');	

    if (!$text) {
        logger('retriever_apply_dom_filter: no text to work with', LOGGER_ERROR);
        return;
    }

    $extracter_template = file_get_contents(dirname(__file__).'/extract.tpl');
    $doc = new DOMDocument();
    $doc->loadHTML($text);

    $components = parse_url($item['plink']);
    $rooturl = $components['scheme'] . "://" . $components['host'];
    $dirurl = $rooturl . dirname($components['path']) . "/";

    $params = array('$match' => $retriever["data"]->match,
                    '$remove' => $retriever["data"]->remove,
                    '$pageurl' => $item['plink'],
                    '$dirurl' => $dirurl,
                    '$rooturl' => $rooturl);
    $xslt = replace_macros($extracter_template, $params);
    $xmldoc = new DOMDocument();
    $xmldoc->loadXML($xslt);
    $xp = new XsltProcessor();
    $xp->importStylesheet($xmldoc);
    $transformed = $xp->transformToXML($doc);
    $item['body'] = html2bbcode($transformed);
    if (!$item['body']) {
        logger('retriever_apply_dom_filter: output was empty', LOGGER_ERROR);
        return;
    }
    q("UPDATE `item` SET `body` = '%s', `received` = now(), `edited` = now() WHERE `id` = %d",
      dbesc($item['body']), intval($item['id']));
}

function retrieve_images(&$item, $parent_retriever_item) {
    $matches1 = array();
    preg_match_all("/\[img\=([0-9]*)x([0-9]*)\](.*?)\[\/img\]/ism", $item["body"], $matches1);
    $matches2 = array();
    preg_match_all("/\[img\](.*?)\[\/img\]/ism", $item["body"], $matches2);
    $matches = array_merge($matches1[3], $matches2[1]);
    foreach ($matches as $url) {
        if (strpos($url, get_app()->get_baseurl()) === FALSE) {
            $resource = add_retriever_resource($url, "image", true);
            if ($resource['completed'] == '0000-00-00 00:00:00') {
                add_retriever_item($item, $resource, $parent_retriever_item);
            }
            else {
                retriever_transform_images($item, $resource);
            }
        }
    }
}

function retriever_on_resource_completed($retriever, &$item, $resource, $retriever_item) {
    logger('retriever_on_resource_completed: retriever ' . $retriever['id'] .
           ' resource ' . $resource['url'], LOGGER_DEBUG);
    if ($resource['type'] == 'html') {
        retriever_apply_dom_filter($retriever, $item, $resource['data']);
        if ($retriever["data"]->images ) {
            retrieve_images($item, $retriever_item);
        }
    }
    if ($resource['type'] == 'image') {
        retriever_transform_images($item, $resource, $retriever_item);
    }
}

function retriever_transform_images(&$item, $resource) {
    require_once('Photo.php');	
    $img = new Photo($resource["data"]);
    $hash = photo_new_resource();
    $r = $img->store($item['uid'], $item['contact-id'], $hash, $resource['url'], 'Retrieved Images', 0);
    $new_url = get_app()->get_baseurl() . '/photo/' . $hash;
    logger('retriever_transform_images: replacing ' . $resource['url'] . ' with ' .
           $new_url . ' in item ' . $item['plink'], LOGGER_DEBUG);
    $item['body'] = str_replace($resource["url"], $new_url, $item['body']);
    q("UPDATE `item` SET `edited` = now(), `body` = '%s' WHERE `plink` = '%s' AND `uid` = %d AND `contact-id` = %d",
      dbesc($item['body']), dbesc($item['plink']), intval($item['uid']), intval($item['contact-id']));
}

function retriever_content($a) {
    if (!local_user()) {
        $a->page['content'] .= "<p>Please log in</p>";
        return;
    }
    if ($a->argv[1]) {
        $retriever = get_retriever($a->argv[1], local_user(), false);

        if (x($_POST["id"])) {
            $retriever = get_retriever($a->argv[1], local_user(), true);
            $retriever["data"] = new stdClass;
            foreach (array('pattern', 'replace', 'match', 'remove', 'enable', 'images') as $setting) {
                if (x($_POST[$setting])) {
                    $retriever["data"]->{$setting} = $_POST[$setting];
                }
            }
            q("UPDATE `retriever_rule` SET `data`='%s' WHERE `id` = %d",
              dbesc(json_encode($retriever["data"])), intval($retriever["id"]));
            $a->page['content'] .= "<p><b>Settings Updated";
            if (x($_POST["apply"])) {
                apply_retrospective($retriever, $_POST["apply"]);
                $a->page['content'] .= " and retrospectively applied to " . $_POST["apply"] . " posts";
            }
            $a->page['content'] .= ".</p></b>";
        }

        $template = file_get_contents(dirname(__file__).'/rule-config.tpl');
        $a->page['content'] .= replace_macros($template, array(
            '$title' => t('Retrieve Feed Content'),
            '$submit' => t('Submit'),
            '$id' => ($retriever["id"] ? $retriever["id"] : "create"),
            '$enabled' => ($retriever["data"]->enable == "on") ? ' checked="true"' : '',
            '$pattern' => $retriever["data"]->pattern ? ' value="' . $retriever["data"]->pattern . '"' : '',
            '$replace' => $retriever["data"]->replace ? ' value="' . $retriever["data"]->replace . '"' : '',
            '$match' => $retriever["data"]->match ? ' value="' . $retriever["data"]->match . '"' : '',
            '$remove' => $retriever["data"]->remove ? ' value="' . $retriever["data"]->remove . '"' : '',
            '$images' => ($retriever["data"]->images == "on") ? ' checked="true"' : ''));
        return;
    }
}

function retriever_contact_photo_menu($a, &$args) {
    if (!$args) {
        return;
    }
    if ($args["contact"]["network"] == "feed") {
        $args["menu"][ t("Retriever") ] = $a->get_baseurl() . '/retriever/' . $args["contact"]['id'];
    }
}

function retriever_post_remote_hook(&$a, &$item) {
    logger('retriever_post_remote_hook: ' . $item['plink'], LOGGER_DEBUG);

    $retriever = get_retriever($item['contact-id'], $item["uid"], false);
    if ($retriever) {
        retriever_on_item_insert($retriever, $item);
    }
    else {
        if (get_pconfig($item["uid"], 'retriever', 'all_photos')) {
            retrieve_images($item, null);
        }
    }
}

function retriever_plugin_settings(&$a,&$s) {
    $all_photos = get_pconfig(local_user(), 'retriever', 'all_photos');
    $all_photos_mu = ($all_photos == 'on') ? ' checked="true"' : '';
    $template = file_get_contents(dirname(__file__).'/settings.tpl');
    $s .= replace_macros($template, array('$all_photos' => $all_photos_mu));
}

function retriever_plugin_settings_post($a,$post) {
    if ($_POST['all_photos']) {
        set_pconfig(local_user(), 'retriever', 'all_photos', $_POST['all_photos']);
    }
    else {
        del_pconfig(local_user(), 'retriever', 'all_photos');
    }
}
