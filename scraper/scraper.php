<?php

/**
 * Name: Scraper
 * Description: Plumbing needed by screenscraper-based connectors
 * Version: 0.2
 * Author: Matthew Exon <http://mat.exon.name>
 */

function scraper_install() {
    set_config('scraper', 'dbversion', '0.1');
    register_hook('cron',                  'addon/scraper/scraper.php', 'scraper_cron');
    register_hook('connector_settings',       'addon/scraper/scraper.php', 'scraper_connector_settings');
    register_hook('connector_settings_post',  'addon/scraper/scraper.php', 'scraper_connector_settings_post');

    $schema = file_get_contents(dirname(__file__).'/database.sql');
    $arr = explode(';', $schema);
    foreach ($arr as $a) {
        $r = q($a);
    }
}

function scraper_uninstall() {
    set_config('scraper', 'dbversion', '0.1');
    unregister_hook('cron',                  'addon/scraper/scraper.php', 'scraper_cron');
    unregister_hook('connector_settings',       'addon/scraper/scraper.php', 'scraper_connector_settings');
    unregister_hook('connector_settings_post',  'addon/scraper/scraper.php', 'scraper_connector_settings_post');
}

function scraper_module() {}

function scraper_content(&$a) {
    logger("@@@ scraper_content is " . $a->argv[1]);
    $sites = array();
    call_hooks('scraper_site', &$sites);

    if (!local_user()) {
        $a->page['content'] .= "<p>Please log in</p>";
        return;
    }

    $r = q("SELECT `nickname` FROM `user` WHERE `uid` = %d", local_user());
    $nick = $r[0]['nickname'];

    if ($a->argv[1] === 'Scraper.user.js') {
        foreach ($sites as &$site) {
            $site['user'] = get_pconfig(local_user(), $site['name'], 'user');
        }
        $template = file_get_contents(dirname(__file__).'/Scraper.user.js.tpl');
        header("Content-type: text/javascript; charset=utf-8");
        echo replace_macros($template, array('$baseurl' => $a->get_baseurl(),
                                             '$sites' => $sites,
                                             '$nick' => $nick));
        echo "hello world 2";
        logger('@@@ about do killme');
        killme();
    }
    $window = get_scraper_window($a->argv[1], $a->argv[2]);
    if ($a->argv[3] === "close") {
        $window['state'] = 'close';
        save_scraper_window($window);
        $a->page['content'] .= "<p>Window closed</p>";
        return;
    }
    if ($a->argv[3] === 'state') {
        $window['state'] = $a->argv[4];
        save_scraper_window($window);
        $a->page['content'] .= "<p>State set to " . $a->argv[4] . "</p>";
        return;
    }
    if ($a->argv[3] === "spawn") {
        foreach ($sites as $site) {
            if ($site['name'] == $a->argv[4]) {
                $a->page['content'] .= '<p>Spawning window at ' . $site['home'] . '</p>';
                $window['state'] = 'spawn';
                $window['data']->spawn = $site['home'];
                save_scraper_window($window);
                return;
            }
        }
        $network = $a->argv[4];
        $a->page['content'] .= '<p>Want to spawn ' . $network . '</p>';
        return;
    }
    if ($a->argv[3] === "detail") {
        if (!$window) {
            $a->page['content'] .= "<p>Window not found</p>";
            return;
        }
        foreach ($sites as $s) {
            if ($s['name'] === $window['network']) {
                $site = $s;
            }
            $site['user'] = get_pconfig(local_user(), $site['name'], 'user');
        }
        $template = file_get_contents(dirname(__file__).'/detail.tpl');
        $a->page['content'] .= replace_macros($template, array('$nick' => $nick,
                                                               '$window' => $window,
                                                               '$sites' => $sites,
                                                               '$states' => $site['states'],
                                                               '$baseurl' => $a->get_baseurl()));
        return;
    }
    $a->page['content'] .= "<p>Page not found</p>";
}

/* Look up contacts on both the url and the nurl */
function scraper_get_contact($a, $uid, $url, $nurl) {
    $r = q("SELECT * FROM contact WHERE uid = %d AND url = '%s' OR nurl = '%s' LIMIT 1", $uid, $url, $nurl);
    return $r[0];
}

function db_escape_pc($string) {
    return dbesc(str_replace('%','%%',$string));
}

function scraper_gen_select_statement($table, $criteria) {
    $wheres = array();
    foreach ($criteria as $k => $v) {
        if ($v === null) {
            continue;
        }
        if (gettype($v) == 'integer') {
            array_push($wheres, '`' . db_escape_pc($k) . '` = ' . intval($v));
        }
        else {
            array_push($wheres, '`' . db_escape_pc($k) . '` = "' . db_escape_pc($v) . '"');
        }
    }
    $statement = 'SELECT * FROM ' . $table . ' WHERE ' . implode($wheres, ' AND ');
    return $statement;
}

function scraper_gen_insert_statement($table, $fields) {
    $keys = array();
    $values = array();
    foreach ($fields as $k => $v) {
        if ($v === null) {
            continue;
        }
        array_push($keys, '`' . db_escape_pc($k) . '`');
        if (gettype($v) == 'integer') {
            array_push($values, intval($v));
        }
        else {
            array_push($values, '"' . db_escape_pc($v) . '"');
        }
    }
    $statement = 'INSERT INTO ' . $table . '(' . implode($keys,', ') . ') VALUES (' . implode($values,', ') . ')';
    return $statement;
}

function scraper_gen_update_statement($table, $fields, $criteria) {
    $sets = array();
    $wheres = array();
    foreach ($fields as $k => $v) {
        if ($v === null) {
            continue;
        }
        if (gettype($v) == 'integer') {
            array_push($sets, '`' . db_escape_pc($k) . '` = ' . intval($v));
        }
        else {
            array_push($sets, '`' . db_escape_pc($k) . '` = "' . db_escape_pc($v) . '"');
        }
    }
    foreach ($criteria as $k => $v) {
        if ($v === null) {
            continue;
        }
        if (gettype($v) == 'integer') {
            array_push($wheres, '`' . db_escape_pc($k) . '` = ' . intval($v));
        }
        else {
            array_push($wheres, '`' . db_escape_pc($k) . '` = "' . db_escape_pc($v) . '"');
        }
    }
    $statement = 'UPDATE ' . $table . ' SET ' . implode($sets,', ') . ' WHERE ' . implode($wheres, ' AND ');
    return $statement;
}

function scraper_submit_feed($a, $feed, $scrape_xsl_file, $import_xsl_file) {
    libxml_use_internal_errors(true);

    $scrape = scraper_get_xslt_from_file($scrape_xsl_file);
    if (!$scrape) {
        return array("error" => "no scraper found");
    }
    $import = scraper_get_xslt_from_file($import_xsl_file);

    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8">' . $feed);
    $dom->encoding = "utf-8";
    $scraped = $scrape->transformToDoc($dom);
    if (!$import) {
        return array("error" => "no importer found");
    }
    $imported = json_decode($import->transformToXml($scraped));
    if (!count($imported->contacts) && !count($imported->items)) {
        logger("scraper_submit_feed: couldn't find any items");
        logger("   data: " . $import->transformToXml($scraped));
    }
    logger('scraper_submit_feed: ' . print_r($imported, true));
    $results = array('items' => array(), 'contacts' => array());

    if ($imported->contacts) {
        foreach ($imported->contacts as $contact) {
            if ($contact && ($contact != '_empty_')) {
                scraper_update_contact($a, $contact);
                array_push($results['contacts'], $contact->url);
            }
        }
    }
    if ($imported->items) {
        foreach ($imported->items as $item) {
            if ($item && ($item != '_empty_')) {
                scraper_update_item($a, $item);
                array_push($results['items'], $item->plink);
            }
        }
    }
    return $results;
}

function scraper_find_contact($item) {
    $query = <<<EOF
SELECT `id`
 FROM `contact`
 WHERE `uid` = %d
 AND (`url` = '%s' OR `url` = '%s' OR `nurl` = '%s' OR `nurl` = '%s')
EOF;

    $owner = dbesc($item->{'owner-link'});
    $author = dbesc($item->{'author-link'});
    $r = q($query, intval($item->uid), $owner, $author, $owner, $author);
    if (count($r)) {
        return $r[0]['id'];
    }

    $r = q("SELECT `contact-id` FROM `item` WHERE `uid` = %d AND `parent-uri` = '%s'",
           intval($item->uid), $item->uri);
    if (count($r)) {
        return $r[0]['contact-id'];
    }
}

function scraper_update_item($a, $item) {
    if (!$item->uid) {
        if (!local_user()) {
            logger("scraper_update_item: no uid");
            return;
        }
        $item->uid = local_user();
    }
    if (!$item->plink) {
        logger('scraper_update_item: no plink');
        return;
    }
    $criteria = new stdClass;
    $criteria->plink = $item->plink;

    $item->edited = datetime_convert($item->timezone, 'UTC', $item->edited);
    if ($item->commented) {
        $item->commented = datetime_convert($item->timezone, 'UTC', $item->commented);
    }
    $item->timezone = null;
    $item->received = datetime_convert();
    $item->guid = get_guid();
    $item->{'contact-id'} = scraper_find_contact($item);
    if (!$item->{'contact-id'}) {
        logger("couldn't find contact for item " . $item->plink);
        return;
    }

    $r = q(scraper_gen_select_statement("item", $criteria));
    $existing = $r[0];
    if ($existing) {
        if ($item->body != $r[0]["body"]) {
            $item->changed = datetime_convert();
        }
        if ($item->commented && ($existing->commented > $item->commented)) {
            $item->commented = null;
        }
        q(scraper_gen_update_statement("item", $item, $criteria));
    }
    else {
        $item->changed = datetime_convert();
        $item->created = $item->edited;
        q(scraper_gen_insert_statement("item", $item));
    }

    $r = null;
    if ($item->{'parent-uri'}) {
        $r = q("SELECT `id` FROM `item` WHERE `uri` = '%s' AND `uid` = %d",
               dbesc($item->{'parent-uri'}), intval($item->uid));
    }
    if (count($r)) {
        q("UPDATE `item` SET `parent` = %d WHERE `uri` = '%s' AND `uid` = %d",
          intval($r[0]["id"]), dbesc($item->plink), intval($item->uid));
    }
    else {
        q("UPDATE `item` SET `parent` = `id` WHERE `uri` = '%s' AND `uid` = %d",
          dbesc($item->plink), intval($item->uid));
    }
    q("SELECT `id` FROM `item` WHERE `uri` = '%s' AND `uid` = %d", dbesc($item->uid), intval($item->uid));
    if (count($r)) {
        q("UPDATE `item` SET `parent` = %d WHERE `parent-uri` = '%s'", intval($r[0]["id"]), dbesc($item->uri));
    }

}

function scraper_update_contact($a, $contact) {
    if (!$contact->uid) {
        if (!local_user()) {
            logger("scraper_update_contact: no uid");
            return;
        }
        $contact->uid = local_user();
    }
    $criteria = new stdClass;
    $criteria->uid = $contact->uid;
    if ($contact->nurl) {
        $criteria->nurl = $contact->nurl;
    }
    else {
        if ($contact->url) {
            $criteria->url = $contact->url;
        }
        else {
            logger("scraper_update_contact: didn't supply url or nurl");
            return;
        }
    }
    if ($contact->name) {
        $contact->{'name-date'} = datetime_convert();
    }
    if ($contact->photo) {
        $contact->{'avatar-date'} = datetime_convert();
    }
    if ($contact->url) {
        $contact->{'uri-date'} = datetime_convert();
    }
    
    $r = q(scraper_gen_select_statement("contact", $criteria));

    if (count($r)) {
        $oldrating = $r[0]["rating"];
        if ($contact->rating >= $r[0]["rating"]) {
            q(scraper_gen_update_statement("contact", $contact, $criteria));
        }
        if ($contact->photo) {
            scraper_update_photo_for_contact($a, $contact, $contact->photo);
        }
    }
    else {
        // If we get a contact with rel 0, it means it's random guff and they're no relation to us at all
        if ($contact->rel) {
            q(scraper_gen_insert_statement("contact", $contact));
            if ($contact->photo) {
                scraper_update_photo_for_contact($a, $contact);
            }
            if ($contact->photo) {
                scraper_update_photo_for_contact($a, $contact, $contact->photo);
                return;
            }
            if ($contact->micro) {
                scraper_update_photo_for_contact($a, $contact, $contact->micro);
                return;
            }
            if ($contact->thumb) {
                scraper_update_photo_for_contact($a, $contact, $contact->thumb);
                return;
            }
        }
    }
}

$state_machine = array(
    'new' => function(&$window) {
        $window['state'] = 'new:interval';
        $window['interval'] = 30;
        return array("function" => "set_interval", "interval" => $window['interval']);
    },
    'new:interval' => function(&$window) {
        $window['state'] = 'new:url';
        return array("function" => "get_url");
    },
    'new:url' => function(&$window) {
        $window['data']->url = file_get_contents("php://input");
        if ($window['data']->previous_state) {
            $window['state'] = $window['data']->previous_state;
            unset($window['data']->previous_state);
        }
        else {
            $window['state'] = 'wait';
        }
        call_hooks('scraper_own', &$window);
        return nil;
    },
    'wait' => function(&$window) {
        return nil;
    },
    'spawn' => function(&$window) {
        $window['state'] = 'wait';
        $url = $window['data']->spawn;
        unset($window['data']->spawn);
        return array("function" => "open_window", "url" => $url);
    },
    'close' => function(&$window) {
        $window['state'] = 'wait';
        return array("function" => "close_window");
    },
    );

function get_state_machine($window) {
    $network = $window['network'];
    if ($network) {
        include_once("addon/$network/$network.php");
        if (function_exists($network . '_state_machine')) {
            $func = $network . '_state_machine';
            return $func();
        }
    }
    global $state_machine;
    if (!$state_machine) {
        set_state_machine();
    }
    return $state_machine;
}

function scraper_state_machine(&$window) {
    $state_machine = get_state_machine($window);
    if (file_get_contents("php://input") == "start") {
        $window['data']->previous_state = $window['state'];
        $window['state'] = 'new';
    }
    $fun = $state_machine[$window['state']];
    if (!$fun) {
        $window['state'] = 'wait';
        save_scraper_window($window);
        return null;
    }
    $result = $fun($window);
    save_scraper_window($window);
    return $result;
}

function get_scraper_window($nick, $wid) {
    $r = q("SELECT `uid` FROM `user` WHERE `nickname` = '%s'", $nick);
    if (!count($r)) {
        return;
    }
    $uid = $r[0]['uid'];
    $r = q("SELECT * FROM `scraper_window` WHERE `uid` = %s AND `wid` = '%s'", intval($uid), dbesc($wid));
    if (!count($r)) {
        q("INSERT INTO `scraper_window` (`sid`, `wid`, `uid`, `state`, `interval`, `first-seen`)" .
          "VALUES ('%s', '%s', %d, 'new', 0, now())", dbesc(session_id()), dbesc($wid), intval($uid));
        $r = q("SELECT * FROM `scraper_window` WHERE `sid` = '%s' AND `wid` = '%s' AND `uid` = %d",
               dbesc(session_id()), dbesc($wid), intval($uid));
    }
    $r[0]['data'] = json_decode($r[0]['data']);
    $r[0]['scraped'] = json_decode($r[0]['scraped']);
    if (gettype($r[0]['data']) != 'object') {
        $r[0]['data'] = new stdClass; // avoid recursively decoded garbage
    }
    return $r[0];
}

function save_scraper_window($window) {
    $scraped = (gettype($window['scraped']) == 'object') ? json_encode($window['scraped']) : "";
    $data = (gettype($window['data']) == 'object') ? json_encode($window['data']) : "";

    q("UPDATE `scraper_window` SET `network` = '%s', `state`= '%s', `interval` = %d, " .
      "`url` = '%s', `scraped` = '%s', `data` = '%s', " .
      "`addr` = '%s', `last-seen` = now() WHERE `id` = %d",
      dbesc($window['network']), dbesc($window['state']), intval($window['interval']),
      dbesc($window['url']), dbesc($scraped),
      dbesc($data), dbesc($_SESSION["addr"]), intval($window['id']));
    delete_expired_windows();
}

function scraper_get_command($window) {
    $oldstate = $window['state'];
    $command = scraper_state_machine($window);
    header("Content-type: application/json");
    logger('@@@ state machine: ' . $oldstate . ' => ' . $window['state'] . ', command ' . json_encode($command));
    echo json_encode($command);
    save_scraper_window($window);
    killme();
}

function scraper_post(&$a) {
    header("Content-type: application/json");

    if ($a->argv[3]) {
        $window = get_scraper_window($a->argv[2], $a->argv[3]);
    }
    if ($a->argv[1] == "register") {
        logger("@@@ scraper_post: register thing received");
        $window['url'] = file_get_contents("php://input");
        $window['interval'] = 30;
        call_hooks('scraper_own', &$window);
        save_scraper_window($window);
        echo json_encode(array("interval" => $window['interval'], "xslt" => $window['xslt']));
        killme();
    }
    if ($a->argv[1] == "scraped") {
        logger('@@@ got scraped raw stuff ' . file_get_contents("php://input"));
        $window['scraped'] = json_decode(file_get_contents("php://input"));
        if (!$window['scraped']) {
            logger('@@@ scraped raw stuff does not compute');
        }
        save_scraper_window($window);
        killme();
    }
    if ($a->argv[1] == "command") {
        logger("@@@ scraper_post: command thing received");
        scraper_get_command($window);
        return;
    }
    if ($a->argv[1] === "detail") {
        $window = get_scraper_window($a->argv[3]);
        if (!$window) {
            $a->page['content'] .= "<p>Window not found</p>";
            return;
        }
        q("UPDATE `scraper_window` SET `network` = '%s' WHERE id = %d",
          dbesc($_POST['network']), intval($window['id']));
        $a->page['content'] .= "<p>Network updated</p>";
        scraper_content($a);
        return;
    }
}

function scraper_get_photo_for_contact($a, $contact) {
}

/* Checks to see if the contact already uses the photo in the 'photo'
 * field, and if not, updates it.  If there's a 'thumb' or 'micro'
 * field and the contact doesn't have a photo, updates to that.  If
 * there's no contact at all, then don't do that part.  However, if
 * there's no contact or if the contact didn't have a photo before or
 * if the photo was updated, update any items in the database which
 * don't already have a author or owner avatar. */
function scraper_update_photo_for_contact($a, &$contact, $url) {
    logger('scraper_update_photo_for_contact: Updating photo for ' . $contact->name);

    $r = q("SELECT `resource-id` FROM `photo` WHERE `contact-id` = %d AND `uid` = %d AND `filename` = '%s'",
           intval($contact->id), intval($contact->uid), dbesc($photo_url));
    if (count($r)) {
        return $contact->photo;
    }

    require_once("include/Photo.php");

    $resource_id = photo_new_resource();
			
    $img_str = fetch_url($photo_url,true);
    $img = new Photo($img_str);
    if(!$img->is_valid()) {
        return;
    }
    logger('aborting photo stuff early');
    return; //@@@

    $hash = $resource_id;

    $img->scaleImageSquare(175);
    $r = $img->store($contact->uid, $contact->id, $hash, $photo_url, 'Contact Photos', 4);
    $contact->photo = $a->get_baseurl() . '/photo/' . $hash . '-4.jpg';
    logger('@@@ stored the main photo as ' . $contact->photo);
				
    $img->scaleImage(80);
    $r = $img->store($contact->uid, $contact->id, $hash, $photo_url, 'Contact Photos', 5);
    $contact->micro = $a->get_baseurl() . '/photo/' . $hash . '-5.jpg';
    logger('@@@ stored the micro photo as ' . $contact->micro);

    $img->scaleImage(48);
    $r = $img->store($contact->uid, $contact->id, $hash, $photo_url, 'Contact Photos', 6);
    $contact->thumb = $a->get_baseurl() . '/photo/' . $hash . '-6.jpg';
    logger('@@@ stored the thumb photo as ' . $contact->thumb);

    q("UPDATE `contact` SET `avatar-date` = now(), `photo` = '%s', `thumb` = '%s', `micro` = '%s'  
				WHERE `uid` = %d AND `id` = %d LIMIT 1",
      dbesc($contact->photo),
      dbesc($contact->micro),
      dbesc($contact->thumb),
      intval($contact->uid),
      intval($contact->id)
        );
}

function scraper_get_xslt_from_file($file) {
    $xsl_text = file_get_contents($file);
    if (!$xsl_text) {
        logger("Couldn't read any XSLT out of " . $file);
        return;
    }
    $xsl = new XSLTProcessor();
    logger('@@@ Getting xslt ' . $xsl_text);
    $xsl->importStylesheet(new SimpleXMLElement($xsl_text));
    return $xsl;
}

function delete_expired_windows() {
    q("DELETE FROM `scraper_window` WHERE TIMESTAMPADD(SECOND, `interval` * 3, `last-seen`) < NOW()");
    q("DELETE FROM `scraper_window` WHERE `last-seen` IS NULL AND TIMESTAMPADD(MINUTE, 3, `first-seen`) < NOW()");
}

function scraper_cron($a,$b) {
    delete_expired_windows();
}

function scraper_connector_settings(&$a,&$b) {
    $uid = local_user();
    $r = q("SELECT `nickname` FROM `user` WHERE `uid` = %d", local_user());
    $nick = $r[0]['nickname'];
    $windows = q("SELECT `sid`, `wid`, `addr`, `network`, `state`, `interval`, `last-seen` AS `last`, `first-seen` AS `first` " .
                 "FROM `scraper_window` WHERE `uid` = %d", intval(local_user()));
    $template = file_get_contents(dirname(__file__).'/sessions.tpl');
    $sites = array();
    call_hooks('scraper_site', &$sites);
    foreach ($sites as &$site) {
        $site['user'] = get_pconfig(local_user(), $site['name'], 'user');
    }
    $b .= replace_macros($template, array('$windows' => $windows,
                                          '$sites' => $sites,
                                          '$nick' => $nick,
                                          '$gmurl' => ($a->get_baseurl() . '/scraper/Scraper.user.js')));
}

function scraper_connector_settings_post ($a,$post) {
    $sites = array();
    call_hooks('scraper_site', &$sites);
    foreach ($sites as $site) {
        if (x($_POST, 'user_' . $site['name'])) {
            set_pconfig(local_user(), $site['name'], 'user', $_POST['user_' . $site['name']]);
        }
    }
}

function scraper_search($criteria) {
    $clauses = array();
    foreach ($criteria as $k => $v) {
        if ($k === 'uid') {
            array_push($clauses, "`" . $k . "` = " . $v);
        }
        else {
            array_push($clauses, "`" . $k . "` = '" . dbesc($v) . "'");
        }
    }
    $query = "SELECT * FROM scraper";
    if (count($clauses)) {
        $query .= " WHERE" . implode(' AND ', $clauses);
    }
    return q($query);
}

function scraper_command_search($criteria) {
    $clauses = array();
    foreach ($criteria as $k => $v) {
        if ($k === 'uid') {
            array_push($clauses, "`" . $k . "` = " . $v);
        }
        else {
            array_push($clauses, "`" . $k . "` = '" . dbesc($v) . "'");
        }
    }
    $query = "SELECT * FROM scraper_command";
    if (count($clauses)) {
        $query .= " WHERE " . implode(' AND ', $clauses);
    }
    return q($query);
}
