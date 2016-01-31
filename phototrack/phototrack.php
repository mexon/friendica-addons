<?php
/**
 * Name: Photo Track
 * Description: Track which photos are actually being used and delete any others
 * Version: 0.1
 * Author: Matthew Exon <http://mat.exon.name>
 */

/* tables:

contact: photo thumb micro about
fcontact: photo
fsuggest: photo
gcontact: photo about
item: body
mail: from-photo
notify: photo
profile: photo thumb about

*/

if (!defined('PHOTOTRACK_DEFAULT_BATCH_SIZE')) {
    define('PHOTOTRACK_DEFAULT_BATCH_SIZE', 1000);
}
if (!defined('PHOTOTRACK_DEFAULT_SEARCH_INTERVAL')) {
    define('PHOTOTRACK_DEFAULT_SEARCH_INTERVAL', 360);
}

function phototrack_install() {
    global $db;

// also want post_local_end @@@
    register_hook('post_local_end', 'addon/phototrack/phototrack.php', 'phototrack_post_local_end');
    register_hook('post_remote_end', 'addon/phototrack/phototrack.php', 'phototrack_post_remote_end');
    register_hook('notifier_end', 'addon/phototrack/phototrack.php', 'phototrack_notifier_end');
    register_hook('cron', 'addon/phototrack/phototrack.php', 'phototrack_cron');

    if (get_config('phototrack', 'dbversion') != '0.1') {
        $schema = file_get_contents(dirname(__file__).'/database.sql');
        $arr = explode(';', $schema);
        foreach ($arr as $a) {
            $r = q($a);
            if ($db->error) {
                logger('phototrack: Unable to create database table: ' . $db->error);
                return;
            }
        }
        set_config('phototrack', 'dbversion', '0.1');
    }
}

function phototrack_uninstall() {
    unregister_hook('post_local_end', 'addon/phototrack/phototrack.php', 'phototrack_post_local_end');
    unregister_hook('post_remote_end', 'addon/phototrack/phototrack.php', 'phototrack_post_remote_end');
    unregister_hook('notifier_end', 'addon/phototrack/phototrack.php', 'phototrack_notifier_end');
    unregister_hook('cron', 'addon/phototrack/phototrack.php', 'phototrack_cron');
}

function phototrack_module() {}

function phototrack_finished_row($table, $id) {
    logger('@@@ phototrack_finished_row ' . $table . ' ' . $id);
    $existing = q("SELECT id FROM phototrack_row_check WHERE `table` = '$table' AND `row-id` = '$id'");
logger('@@@ how many existing? ' . count($existing));
    if (count($existing)) {
        q("UPDATE phototrack_row_check SET checked = NOW() WHERE `table` = '$table' AND `row-id` = '$id'");
    }
    else {
logger('@@@ insert phototrack_row_check values ' . $table. ' ' . $id);
        q("INSERT INTO phototrack_row_check (`table`, `row-id`, `checked`) VALUES ('$table', '$id', NOW())");
    }
}

function phototrack_photo_use($photo, $table, $field, $id) {
    logger('@@@ phototrack_field_use ' . $photo . ' ' . $table . ' ' . $field . ' ' . $id);
    foreach (Photo::supportedTypes() as $m => $e) {
        $photo = str_replace(".$e", '', $photo);
    }
    if (substr($photo, -2, 1) == '-') {
        $resolution = intval(substr($photo,-1,1));
        $photo = substr($photo,0,-2);
    }
    if (strlen($photo) != 32) {
        logger('@@@ not a guid ' . $photo);
        return;
    }
    logger('@@@ look for existing photo resource '. $photo);
    $r = q("SELECT `resource-id` FROM `photo` WHERE `resource-id` = '%s' LIMIT 1", dbesc($photo));
    if (!count($r)) {
        logger('@@@ no such resource ' . $photo);
        return;
    }
logger('@@@ tried to find resource id ' . $photo . ' got ' . print_r($r, true));
    $rid = $r[0]['resource-id'];
    $existing = q("SELECT id FROM phototrack_photo_use WHERE `resource-id` = '$rid' AND `table` = '$table' AND `field` = '$field' AND `row-id` = '$id'");
    if (count($existing)) {
        q("UPDATE phototrack_photo_use SET checked = NOW() WHERE `resource-id` = '$rid' AND `table` = '$table' AND `field` = '$field' AND `row-id` = '$id'");
    }
    else {
logger('@@@ insert new photo use ' . $rid . ' ' . $table . ' ' . $field . ' ' . $id);
        q("INSERT INTO phototrack_photo_use (`resource-id`, `table`, `field`, `row-id`, `checked`) VALUES ('$rid', '$table', '$field', '$id', NOW())");
    }
}

function phototrack_check_field_url($a, $table, $field, $id, $url) {
    logger('@@@ phototrack_check_field_url got url ' . $url);
    $baseurl = $a->get_baseurl();
    if (strpos($url, $baseurl) !== FALSE) {
        logger('@@@ url matches baseurl ' . $baseurl);
        $url = substr($url, strlen($baseurl));
    }
    if (strpos($url, '/photo/') !== FALSE) {
        logger('@@@ this has a photo url');
        $rid = substr($url, strlen('/photo/'));
        logger('@@@ got a use of a photo ' . $rid . ' about to call phototrack_field_use');
        phototrack_photo_use($rid, $table, $field, $id);
        logger('@@@ finished call to phototrack_field_use');
    }
}

function phototrack_check_field_bbcode($a, $table, $field, $id, $value) {
    logger('@@@ phototrack_check_field_bbcode ' . $table . ' ' . $field . ' ' . $id . ' value ' . $value);
    $baseurl = $a->get_baseurl();
    $matches = array();
    preg_match_all("/\[img(\=([0-9]*)x([0-9]*))?\](.*?)\[\/img\]/ism", $value, $matches);
    foreach ($matches[4] as $url) {
logger('@@@ after iterating over matches 4 I have this URL ' . $url);
        phototrack_check_field_url($a, $table, $field, $id, $url);
    }
}

function phototrack_post_local_end(&$a, &$item) {
logger('@@@ phototrack_post_local_end item ' . $item['id']);
    phototrack_check_row($a, 'item', $item);
}

function phototrack_post_remote_end(&$a, &$item) {
logger('@@@ phototrack_post_remote_end item ' . $item['id']);
    phototrack_check_row($a, 'item', $item);
}

function phototrack_notifier_end($item) {
        $a = get_app();

logger('@@@ phototrack_notifier_end item ' . $item['id']);
logger('@@@ base url ' . $a->get_baseurl());
}

function phototrack_check_row($a, $table, $row) {
    switch ($table) {
        case 'item':
            $fields = array(
                'body' => 'bbcode');
            break;
        case 'contact':
            $fields = array(
                'photo' => 'url',
                'thumb' => 'url',
                'micro' => 'url',
                'about' => 'bbcode');
            break;
        case 'fcontact':
            $fields = array(
                'photo' => 'url');
            break;
        case 'fsuggest':
            $fields = array(
                'photo' => 'url');
            break;
        case 'gcontact':
            $fields = array(
                'photo' => 'url',
                'about' => 'bbcode');
            break;
        default: $fields = array(); break;
    }
    foreach ($fields as $field => $type) {
        switch ($type) {
            case 'bbcode': phototrack_check_field_bbcode($a, $table, $field, $row['id'], $row[$field]); break;
            case 'url': phototrack_check_field_url($a, $table, $field, $row['id'], $row[$field]); break;
        }
    }
    phototrack_finished_row($table, $row['id']);
}

function phototrack_batch_size() {
    $batch_size = get_config('phototrack', 'batch_size');
    if ($batch_size > 0) {
        return $batch_size;
    }
    return PHOTOTRACK_DEFAULT_BATCH_SIZE;
}

function phototrack_search_table($a, $table) {
    $batch_size = phototrack_batch_size();
    $rows = q("SELECT `$table`.* FROM `$table` LEFT OUTER JOIN phototrack_row_check ON ( phototrack_row_check.`table` = '$table' AND phototrack_row_check.`row-id` = `$table`.id ) WHERE ( ( phototrack_row_check.checked IS NULL ) OR ( phototrack_row_check.checked < DATE_SUB(NOW(), INTERVAL 1 MONTH) ) ) ORDER BY phototrack_row_check.checked LIMIT $batch_size");
    foreach ($rows as $row) {
        phototrack_check_row($a, $table, $row);
    }
    $r = q("SELECT COUNT(*) FROM `$table` LEFT OUTER JOIN phototrack_row_check ON ( phototrack_row_check.`table` = '$table' AND phototrack_row_check.`row-id` = `$table`.id ) WHERE ( ( phototrack_row_check.checked IS NULL ) OR ( phototrack_row_check.checked < DATE_SUB(NOW(), INTERVAL 1 MONTH) ) )");
    $remaining = $r[0]['COUNT(*)'];
    logger('@@@ remaining items table ' .$table . ' is ' . $remaining);
    return $remaining;
}

function phototrack_cron_time() {
    $prev_remaining = get_config('phototrack', 'remaining_items');
    if ($prev_remaining > 10 * phototrack_batch_size()) {
        logger('@@@ lots of things still remaining, always cron time now');
        return true;
    }
    $last = get_config('phototrack', 'last_search');
    $search_interval = intval(get_config('phototrack', 'search_interval'));
    if (!$search_interval) {
        $search_interval = PHOTOTRACK_DEFAULT_SEARCH_INTERVAL;
    }
    logger('@@@ phototrack_cron_time now ' . time() . ' last ' . $last . ' search_interval ' . $search_interval);
    if ($last) {
        $next = $last + ($search_interval * 60);
        if ($next > time()) {
            logger('phototrack: search interval not reached');
            return false;
        }
    }
    return true;
}

function phototrack_cron($a, $b) {
    if (!phototrack_cron_time()) {
        return;
    }
    set_config('phototrack', 'last_search', time());

    $remaining = 0;
    $remaining += phototrack_search_table($a, 'item');
    $remaining += phototrack_search_table($a, 'contact');
    $remaining += phototrack_search_table($a, 'fcontact');
    $remaining += phototrack_search_table($a, 'fsuggest');
    $remaining += phototrack_search_table($a, 'gcontact');

    logger('@@@ total remaining items ' . $remaining);
    set_config('phototrack', 'remaining_items', $remaining);
    if ($remaining === 0) {
        phototrack_tidy();
    }
    logger('@@@ phototrack cron finished');
}

function phototrack_tidy() {
//@@@ so this is how this will work.  Delete all use rows older than a certain time.  Then delete things with no use rows.  This both tidies up our own database and also expires things after a month
    logger('@@@ phototrack_tidy');
    return;
    q('DELETE FROM photo WHERE `id` IN (SELECT * FROM (SELECT photo.`id` FROM photo LEFT OUTER JOIN phototrack_photo_use ON (photo.`resource-id` = phototrack_photo_use.`resource-id`) WHERE phototrack_photo_use.id IS NULL AND photo.`created` < DATE_SUB(NOW(), INTERVAL 3 MONTH) AND `album` = "Retrieved Images") AS X)');
    $r = q("SELECT id FROM phototrack_item WHERE completed IS NOT NULL AND completed < DATE_SUB(NOW(), INTERVAL 1 YEAR)");
    foreach ($r as $rr) {
        q('DELETE FROM phototrack_item WHERE id = %d', intval($rr['id']));
    }
    logger('phototrack_tidy: deleted ' . count($r) . ' old items', LOGGER_DEBUG);
}
