<?php

/**
 * Name: Scraper
 * Description: Plumbing needed by screenscraper-based connectors
 * Version: 0.1
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
    logger('@@@ scraper_content ' . print_r($a->argv, true));
    if (!local_user()) {
        $a->page['content'] .= "<p>Please log in</p>";
        return;
    }
    if ($a->argv[2]) {
        $scraper = get_scraper_details(local_user(), $a->argv[2]);
    }
    if ($a->argv[1] === "detail") {
        if (!$scraper) {
            $a->page['content'] .= "<p>Scraper not found</p>";
            return;
        }
        $template = file_get_contents(dirname(__file__).'/detail.tpl');
        $a->page['content'] .= replace_macros($template, array('$scraper' => $scraper));
        return;
    }
    if ($a->argv[1] === "login") {
        $a->page['content'] .= '<p>logged in <a href="scraper/detail/' . $scraper['guid'] . '">goback</a></p>';
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
    logger('@@@ scraper_gen_select_statement: ' . $statement);
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
    logger('@@@ scraper_gen_insert_statement: ' . $statement);
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
    logger('@@@ scraper_gen_update_statement: ' . $statement);
    return $statement;
}

function scraper_submit_feed($a, $feed, $scrape_xsl_file, $import_xsl_file) {
    logger('@@@ one');
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
    logger('@@@ two');
    if (!$import) {
        return array("error" => "no importer found");
    }
    $imported = json_decode($import->transformToXml($scraped));
    if (!count($imported->contacts) && !count($imported->items)) {
        logger("scraper_submit_feed: couldn't find any items");
        logger("   data: " . $import->transformToXml($scraped));
    }
    logger('@@@ three');
    logger('scraper_submit_feed: ' . print_r($imported, true));
    $results = array('items' => array(), 'contacts' => array());

    if ($imported->contacts) {
        foreach ($imported->contacts as $contact) {
            if ($contact && ($contact != '_empty_')) {
                logger('@@@ submitting contact ' . $contact->url);
                scraper_update_contact($a, $contact);
                array_push($results['contacts'], $contact->url);
            }
        }
    }
    if ($imported->items) {
        foreach ($imported->items as $item) {
            if ($item && ($item != '_empty_')) {
                logger('@@@ submitting item ' . $item->plink);
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

    logger('@@@ unable to find contact for ' . $owner . ' trying uri ' . $item->uri);
    $r = q("SELECT `contact-id` FROM `item` WHERE `uid` = %d AND `parent-uri` = '%s'",
           intval($item->uid), $item->uri);
    if (count($r)) {
        logger('@@@ found contact using parent-uri ' . $item->uri);
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
        logger("@@@ old commented is " . $existing['commented'] . ' new is ' . $item->commented);
        if ($item->commented && ($existing['commented'] > $item->commented)) {
            logger('@@@ old commented is newer, clearing');
            $item->commented = null;
        }
        logger('@@@ one');
        q(scraper_gen_update_statement("item", $item, $criteria));
        logger('@@@ 2');
    }
    else {
        $item->changed = datetime_convert();
        $item->created = $item->edited;
        logger('@@@ 3');
        q(scraper_gen_insert_statement("item", $item));
        logger('@@@ 4');
    }

    $r = null;
    if ($item->{'parent-uri'}) {
        $r = q("SELECT `id` FROM `item` WHERE `uri` = '%s' AND `uid` = %d",
               dbesc($item->{'parent-uri'}), intval($item->uid));
    }
    if (count($r)) {
        logger('@@@ found parent uri from ' . $item->{'parent-uri'} . ' setting parent to ' . $r[0]["id"]);
        q("UPDATE `item` SET `parent` = %d WHERE `uri` = '%s' AND `uid` = %d",
          intval($r[0]["id"]), dbesc($item->plink), intval($item->uid));
    }
    else {
        logger('@@@ found no parent uri for ' . $item->{'parent-uri'} . ' setting parent to self');
        q("UPDATE `item` SET `parent` = `id` WHERE `uri` = '%s' AND `uid` = %d",
          dbesc($item->plink), intval($item->uid));
    }
    q("SELECT `id` FROM `item` WHERE `uri` = '%s' AND `uid` = %d", dbesc($item->uid), intval($item->uid));
    if (count($r)) {
        logger('@@@ new item id is ' . $r[0]["id"] . ' updating references to ' . $item->uri);
        q("UPDATE `item` SET `parent` = %d WHERE `parent-uri` = '%s'", intval($r[0]["id"]), dbesc($item->uri));
    }

}

function scraper_update_contact($a, $contact) {
    logger('@@@ scraper_update_contact: ' . print_r($contact, true));
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
        logger('@@@ old contact, maybe updating');
        $oldrating = $r[0]["rating"];
        logger('@@@ old rating ' . $r[0]["rating"] . ' new rating ' . $contact->rating);
        if ($contact->rating >= $r[0]["rating"]) {
            logger('@@@ updating contact, rating is good enough');
            q(scraper_gen_update_statement("contact", $contact, $criteria));
        }
        if ($contact->photo) {
            logger('@@@ updating contact with photo ' . $contact->photo);
            scraper_update_photo_for_contact($a, $contact, $contact->photo);
        }
    }
    else {
        logger('@@@ new contact, lets insert');
        // If we get a contact with rel 0, it means it's random guff and they're no relation to us at all
        if ($contact->rel) {
            q(scraper_gen_insert_statement("contact", $contact));
            if ($contact->photo) {
                scraper_update_photo_for_contact($a, $contact);
            }
    if ($contact->photo) {
        logger('@@@ new contact with photo ' . $contact->photo);
        scraper_update_photo_for_contact($a, $contact, $contact->photo);
        return;
    }
    if ($contact->micro) {
        logger('@@@ new contact with micro ' . $contact->micro);
        scraper_update_photo_for_contact($a, $contact, $contact->micro);
        return;
    }
    if ($contact->thumb) {
        logger('@@@ new contact with thumb ' . $contact->thumb);
        scraper_update_photo_for_contact($a, $contact, $contact->thumb);
        return;
    }
        }
    }
}

function scraper_post(&$a) {
    header("Content-type: application/json");

    logger('@@@ scraper_post: ' . $a->argv[1] . " data " . print_r($request, true));

    $request = json_decode(file_get_contents("php://input"));
    if (!local_user()) {
        $result = array("error" => "please log in");
    }
    else
    {
        logger('@@@ scraper_post: ' . $a->argv[1] . " data " . print_r($request, true));
        if ($a->argv[1] === "register") {
            $result = scraper_register($request);
        }
        if ($a->argv[1] === "getcommand") {
            $result = scraper_get_command($request);
        }
        if ($a->argv[1] === "finishcommand") {
            $result = scraper_finish_command(local_user(), $request);
        }
        if ($a->argv[1] === "login") {
            scraper_add_command(local_user(), $a->argv[2], "storepassword", $_POST);
            $result = null;
        }
        if ($a->argv[1] === "detail") {
            if ($_POST['want-status']) {
                logger('Updating desired status of scraper ' . $a->argv[2] . ' to ' . dbesc($_POST['want-status']));
                
                $r = q("UPDATE scraper SET `want-status` = '%s' WHERE `guid` = '%s'", dbesc($_POST['want-status']), dbesc($a->argv[2]));
                $r = q("SELECT * FROM  scraper");
            }
        }
    }
    if ($result) {
        echo json_encode($result);
        killme();
    }
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
        logger('@@@ already have this photo');
        return $contact->photo;
    }

    // Code copied from facebook.php, maybe this should be a module hmm?
    require_once("Photo.php");

    $photos = import_profile_photo($r[0]['photo'],$contact->uid,$contact->id);
    q("UPDATE `contact` SET `photo` = '%s',
                        `thumb` = '%s',
                        `micro` = '%s',
                        `name-date` = '%s',
                        `uri-date` = '%s',
                        `avatar-date` = '%s'
                        WHERE `id` = %d LIMIT 1
                ",
        dbesc($photos[0]),
        dbesc($photos[1]),
        dbesc($photos[2]),
        dbesc(datetime_convert()),
        dbesc(datetime_convert()),
        dbesc(datetime_convert()),
        intval($contact->id)
    );
}

function scraper_register($reg) {
    $scraper = get_scraper($reg);
    if (!$scraper) {
        logger("scraper: Couldn't create scraper for guid " . $reg['guid']);
        return;
    }
    $r = q("UPDATE scraper SET `address` = '%s', `status` = '%s', `activity` = '%s', `command` = '%s', `interval` = %d, `data` = '%s', `update` = now() WHERE `guid` = '%s'",
           dbesc($_SERVER['REMOTE_ADDR']), dbesc($reg->status), dbesc($reg->activity), dbesc($reg->command),
           $reg->interval, dbesc(json_encode($reg->data)), dbesc($reg->guid));
    $scraper = get_scraper($reg);
    call_hooks('scraper_register', $scraper);
    $reply = array("want-status" => $scraper['want-status']);
    if ($reg->command) {
        $r = q("SELECT * FROM scraper_command WHERE uid = %d AND guid = '%s'", local_user(), dbesc($reg->command));
        $command = $r[0];
        if (!$command || ($command["finished"] && ($command["finished"] != '0000-00-00 00:00:00'))) {
            $reply["cancel-command"] = $reg->command;
        }
    }
    $result = json_encode($reply);
    echo $result;
    killme();
}

function get_scraper_details($uid, $guid) {
    $r = q("SELECT * FROM scraper WHERE `guid` = '%s' AND uid = %d", dbesc($guid), $uid);
    if ($r) {
        return $r[0];
    }
}

function get_scraper($reg) {
    $details = get_scraper_details(local_user(), $reg->guid);
    if ($details) {
        return $details;
    }
    $r = q("INSERT INTO scraper (guid, uid, type, network, created, data) VALUES ('%s', %d, '%s', '%s', now(), '%s')",
           dbesc($reg->guid), local_user(), dbesc($reg->type), dbesc($reg->network), dbesc(json_encode($reg->data)));
    return get_scraper_details(local_user(), $reg->guid);
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

function scraper_finish_command($uid, $request) {
    logger('scraper_finish_command uid ' . $uid . ' request data ' . print_r($request, true));
    $r = q("UPDATE scraper_command SET finished = now(), result = '%s' WHERE uid = %d AND guid = '%s'",
           dbesc(json_encode($request->data)), $uid, dbesc($request->guid));
    $result = scraper_get_command($request);
    $result["finished"] = $request->guid;
    $r = q("SELECT * FROM scraper_command WHERE uid = %d AND guid = '%s'", local_user(), dbesc($request->guid));
    if (count($r)) {
        logger('@@@ about to call hooks scraper_finish_command');
        call_hooks('scraper_finish_command', $r[0]);
        logger('@@@ finished call hooks scraper_finish_command');
    }
    return $result;
}

function scraper_get_command($request) {
    $query = <<<EOF
SELECT * FROM scraper_command
 WHERE uid = %d
 AND `scraper-guid` = '%s'
 AND (valid IS NULL OR valid < now())
 AND (expires IS NULL OR expires > now())
 AND started IS NULL
 AND finished IS NULL
 ORDER BY created
 LIMIT 1
EOF;
    $r = q($query, local_user(), $request->scraperguid);
    $command = $r[0];
    if ($command) {
        $command["data"] = json_decode($command["data"]);
        $r = q("UPDATE scraper_command SET started = now() WHERE guid = '%s'", $command["guid"]);
        logger('sending command ' . $command["guid"] . ' to scraper ' . $command["scraper-guid"]);
        $result = array("command" => $command);
    }
    else {
        $result = array("empty" => "empty");
    }
    return $result;
}

function scraper_command_exists($scraper_guid, $command) {
    $query = <<<EOF
SELECT id FROM `scraper_command`
 WHERE `uid` = %d
 AND `scraper-guid` = '%s'
 AND `started` IS NULL
 AND `finished` IS NULL
 AND `command` = '%s'
EOF;
    $r = q($query, intval(local_user()), dbesc($scraper_guid), dbesc($command));
    return $r[0];
}

function scraper_add_command($uid, $scraper_guid, $command, $data, $wait=0, $expire=null) {
    logger('@@@ scraper_add_command');
    $scraper = get_scraper_details($uid, $scraper_guid);
    if (!$scraper) {
        logger('@@@ no scraper for ' . $scraper_guid);
        return null;
    }

    $query = <<<EOF
SELECT unix_timestamp(valid) AS time
 FROM scraper_command
 WHERE uid = %d
 AND `scraper-guid` = '%s'
 AND command = '%s'
 ORDER BY valid DESC
 LIMIT 1
EOF;

    if (!$wait) { // By default, wait a minute or two
        $wait = rand(60, 120);
    }
    $r = q($query, intval($uid), dbesc($scraper_guid), dbesc($command));
    $time = $r[0]['time'] - time();
    if ($time > 0) {
        $wait += $time;
    }

    $query = <<<EOF
INSERT INTO scraper_command
 (guid, uid, `scraper-guid`, command, data, created, valid, expires)
 VALUES
 ('%s', %d, '%s', '%s', '%s', now(), now() + interval %d second, %s)
EOF;

    $clause = $expire ? ("now() + interval " + $expire + " second") : "'0000-00-00 00:00:00'";
    $r = q($query, dbesc(get_guid()), $uid, dbesc($scraper['guid']),
           dbesc($command), dbesc(json_encode($data)), $wait, $clause);
    return $wait;
}

function check_expired_sleepers() {
    $r = q("select scraper.uid as scraper_uid, scraper.guid as scraper_guid, timestampdiff(second, started, now()) as sleeping, `interval`, scraper_command.guid as command_guid, scraper_command.data from scraper, scraper_command where scraper.guid = scraper_command.`scraper-guid` and scraper_command.command = 'sleep' and started > finished");
    foreach ($r as $rr) {
        $rr['sleepduration'] = json_decode($rr['data']);
        // Give sleepers a decent chance to wake up: they have five of their ping intervals
        if ($rr['sleeping'] > $rr['sleepduration'] + 5 * $rr['interval']) {
            logger("Scraper " . $rr['scraper_guid'] . " sleep command " . $rr['command_guid'] .
                   " overran, it's been " . $rr['sleeping'] . "s, should have been at most " .
                   $rr['sleepduration'] . "s");
            $request = new stdClass;
            $request->guid = $rr['command_guid'];
            $request->data = new stdClass;
            $request->data->error = 'timeout';
            scraper_finish_command($rr['scraper_uid'], $request);
        }
    }
}

function check_disconnected_scrapers() {
    // Todo: check if any scrapers have failed to send us an update
    // within 5 ping intervals, and if so update their status to
    // "disconnected".  If they have a command in progress, leave it
    // there on the assumption that they'll come back to it later.
}

function check_abandoned_commands() {
    // Check for scraper commands that claim they are underway but
    // actually the main scraper status shows that it's either not
    // dealing with a command or is dealing with some other command.

    // todo, give a few intervals leeway "just in case"
    $r = q("select scraper_command.guid, scraper_command.uid from scraper_command left outer join scraper on (scraper_command.guid = scraper.command) where (started > finished) and scraper.command is null");
    foreach ($r as $rr) {
        logger('Scraper command ' . $rr['command_guid'] . ' seems to be abandoned');
            $request = new stdClass;
            $request->guid = $rr['guid'];
            $request->data = new stdClass;
            $request->data->error = 'abandoned';
            scraper_finish_command($rr['uid'], $request);
    }
}

function scraper_cron($a,$b) {
    echo "@@@ scraper_cron\n";
    logger('@@@ scraper_cron');
    check_expired_sleepers();
    check_disconnected_scrapers();
    check_abandoned_commands();
    echo "@@@ scraper cron finishing\n";
}

function scraper_connector_settings(&$a,&$b) {
    logger('@@@ scraper_connector_settings');
    $r = q("SELECT * FROM scraper WHERE uid = %d", local_user());
    $template = file_get_contents(dirname(__file__).'/connsettings.tpl');
    $section .= replace_macros($template, array('$scrapers' => $r));
    $b .= $section;
}

function scraper_connector_settings_post ($a,$post) {
    logger('@@@ scraper_connector_settings_post');
    $scraper = get_scraper_details(local_user(), $a->argv[2]);
    if (!$scraper) {
        $a->page['content'] .= "<p>Scraper not found</p>";
        return;
    }
}

function scraper_search($criteria) {
    logger('@@@ scraper_search');
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
    logger('@@@ scraper_search: ' . $query);
    return q($query);
}

function scraper_command_search($criteria) {
    logger('@@@ scraper_command_search');
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
    logger('@@@ scraper_command_search: ' . $query);
    return q($query);
}

function scraper_ensure_sleep_exists($network, $sleep_for, $wait_for) {
    $query = <<<EOF
select scraper.uid, scraper.guid
 from scraper left outer join scraper_command
 on (scraper.guid = scraper_command.`scraper-guid`
     and scraper_command.command = 'sleep'
     and (scraper_command.finished is null or scraper_command.finished = '0000-00-00 00:00:00'))
 where network = '%s'
 and scraper_command.id is null
EOF;

    $r = q($query, $network);
    foreach ($r as $rr) {
        logger('Adding sleep command for scraper ' . $rr['guid']);
        scraper_add_command($rr['uid'], $rr['guid'], 'sleep', array('time' => $sleep_for), $wait_for);
    }
}
