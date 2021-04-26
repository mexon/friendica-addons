<?php
/**
 * Name: Photo Track
 * Description: Track which photos are actually being used and delete any others
 * Version: 1.0
 * Author: Matthew Exon <http://mat.exon.name>
 */

/*
 * List of tables and the fields that are checked:
 * 
 * contact: photo thumb micro about
 * fcontact: photo
 * fsuggest: photo
 * gcontact: photo about
 * item: body
 * item-content: body
 * mail: from-photo
 * notify: photo
 * profile: photo thumb about
 */

use Friendica\Core\Addon;
use Friendica\Core\Logger;
use Friendica\Object\Image;
use Friendica\Database\DBA;
use Friendica\DI;

if (!defined('PHOTOTRACK_DEFAULT_BATCH_SIZE')) {
    define('PHOTOTRACK_DEFAULT_BATCH_SIZE', 1000);
}
// Time in *minutes* between searching for photo uses
if (!defined('PHOTOTRACK_DEFAULT_SEARCH_INTERVAL')) {
    define('PHOTOTRACK_DEFAULT_SEARCH_INTERVAL', 10);
}

function phototrack_install() {
    global $db;

    Addon::registerHook('post_local_end', 'addon/phototrack/phototrack.php', 'phototrack_post_local_end');
    Addon::registerHook('post_remote_end', 'addon/phototrack/phototrack.php', 'phototrack_post_remote_end');
    Addon::registerHook('notifier_end', 'addon/phototrack/phototrack.php', 'phototrack_notifier_end');
    Addon::registerHook('cron', 'addon/phototrack/phototrack.php', 'phototrack_cron');

    if (DI::config()->get('phototrack', 'dbversion') != '0.1') {
        $schema = file_get_contents(dirname(__file__).'/database.sql');
        $arr = explode(';', $schema);
        foreach ($arr as $a) {
            if (!DBA::e($a)) {
                Logger::warning('Unable to create database table: ' . DBA::errorMessage());
                return;
            }
        }
        DI::config()->set('phototrack', 'dbversion', '0.1');
    }
}

function phototrack_uninstall() {
    Addon::unregisterHook('post_local_end', 'addon/phototrack/phototrack.php', 'phototrack_post_local_end');
    Addon::unregisterHook('post_remote_end', 'addon/phototrack/phototrack.php', 'phototrack_post_remote_end');
    Addon::unregisterHook('notifier_end', 'addon/phototrack/phototrack.php', 'phototrack_notifier_end');
    Addon::unregisterHook('cron', 'addon/phototrack/phototrack.php', 'phototrack_cron');
}

function phototrack_module() {}

function phototrack_finished_row($table, $id) {
    $existing = DBA::selectFirst('phototrack_row_check', ['id'], ['table' => $table, 'row-id' => $id]);
    if (!is_bool($existing)) {
        q("UPDATE phototrack_row_check SET checked = NOW() WHERE `table` = '$table' AND `row-id` = '$id'");
    }
    else {
        q("INSERT INTO phototrack_row_check (`table`, `row-id`, `checked`) VALUES ('$table', '$id', NOW())");
    }
}

function phototrack_photo_use($photo, $table, $field, $id) {
    Logger::debug('@@@ phototrack_photo_use ' . $photo);
    foreach (Image::supportedTypes() as $m => $e) {
        $photo = str_replace(".$e", '', $photo);
    }
    if (substr($photo, -2, 1) == '-') {
        $resolution = intval(substr($photo,-1,1));
        $photo = substr($photo,0,-2);
    }
    if (strlen($photo) != 32) {
        return;
    }
    $r = q("SELECT `resource-id` FROM `photo` WHERE `resource-id` = '%s' LIMIT 1", DBA::escape($photo));
    if (!count($r)) {
        return;
    }
    $rid = $r[0]['resource-id'];
    $existing = q("SELECT id FROM phototrack_photo_use WHERE `resource-id` = '$rid' AND `table` = '$table' AND `field` = '$field' AND `row-id` = '$id'");
    if (count($existing)) {
        q("UPDATE phototrack_photo_use SET checked = NOW() WHERE `resource-id` = '$rid' AND `table` = '$table' AND `field` = '$field' AND `row-id` = '$id'");
    }
    else {
        q("INSERT INTO phototrack_photo_use (`resource-id`, `table`, `field`, `row-id`, `checked`) VALUES ('$rid', '$table', '$field', '$id', NOW())");
    }
}

function phototrack_check_field_url($a, $table, $field, $id, $url) {
    Logger::info('@@@ phototrack_check_field_url table ' . $table . ' field ' . $field . ' id ' . $id . ' url ' . $url);
    $baseurl = DI::baseUrl()->get(true);
    if (strpos($url, $baseurl) === FALSE) {
        return;
    }
    else {
        $url = substr($url, strlen($baseurl));
        Logger::info('@@@ phototrack_check_field_url funny url stuff ' . $url . ' base ' . $baseurl);
    }
    if (strpos($url, '/photo/') === FALSE) {
        return;
    }
    else {
        $url = substr($url, strlen('/photo/'));
        Logger::info('@@@ phototrack_check_field_url more url stuff ' . $url);
    }
    if (preg_match('/([0-9a-z]{32})/', $url, $matches)) {
        $rid = $matches[0];
        Logger::info('@@@ phototrack_check_field_url rid ' . $rid);
        phototrack_photo_use($rid, $table, $field, $id);
    }
}

function phototrack_check_field_bbcode($a, $table, $field, $id, $value) {
    $baseurl = DI::baseUrl()->get(true);
    $matches = array();
    preg_match_all("/\[img(\=([0-9]*)x([0-9]*))?\](.*?)\[\/img\]/ism", $value, $matches);
    foreach ($matches[4] as $url) {
        phototrack_check_field_url($a, $table, $field, $id, $url);
    }
}

function phototrack_post_local_end(&$a, &$item) {
    phototrack_check_row($a, 'item', $item);
    phototrack_check_row($a, 'item-content', $item);
}

function phototrack_post_remote_end(&$a, &$item) {
    phototrack_check_row($a, 'item', $item);
    phototrack_check_row($a, 'item-content', $item);
}

function phototrack_notifier_end($item) {
}

function phototrack_check_row($a, $table, $row) {
    switch ($table) {
        case 'item':
            $fields = array(
                'body' => 'bbcode');
            break;
        case 'item-content':
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
    $batch_size = DI::config()->get('phototrack', 'batch_size');
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
    Logger::info('phototrack: searched ' . count($rows) . ' rows in table ' . $table . ', ' . $remaining . ' still remaining to search');
    return $remaining;
}

function phototrack_cron_time() {
    $prev_remaining = DI::config()->get('phototrack', 'remaining_items');
    if ($prev_remaining > 10 * phototrack_batch_size()) {
        Logger::debug('phototrack: more than ' . (10 * phototrack_batch_size()) . ' items remaining');
        return true;
    }
    $last = DI::config()->get('phototrack', 'last_search');
    $search_interval = intval(DI::config()->get('phototrack', 'search_interval'));
    if (!$search_interval) {
        $search_interval = PHOTOTRACK_DEFAULT_SEARCH_INTERVAL;
    }
    if ($last) {
        $next = $last + ($search_interval * 60);
        if ($next > time()) {
            Logger::debug('phototrack: search interval not reached');
            return false;
        }
    }
    return true;
}

function phototrack_cron($a, $b) {
    if (!phototrack_cron_time()) {
        return;
    }
    DI::config()->set('phototrack', 'last_search', time());

    $remaining = 0;
    $remaining += phototrack_search_table($a, 'item');
    $remaining += phototrack_search_table($a, 'item-content');
    $remaining += phototrack_search_table($a, 'contact');
    $remaining += phototrack_search_table($a, 'fcontact');
    $remaining += phototrack_search_table($a, 'fsuggest');
    $remaining += phototrack_search_table($a, 'gcontact');

    DI::config()->set('phototrack', 'remaining_items', $remaining);
    if ($remaining === 0) {
        phototrack_tidy();
    }
}

function phototrack_tidy() {
    $batch_size = phototrack_batch_size();
    q('CREATE TABLE IF NOT EXISTS `phototrack-temp` (`resource-id` char(255) not null)');
    q('INSERT INTO `phototrack-temp` SELECT DISTINCT(`resource-id`) FROM photo WHERE photo.`created` < DATE_SUB(NOW(), INTERVAL 2 MONTH)');
    $rows = q('SELECT `phototrack-temp`.`resource-id` FROM `phototrack-temp` LEFT OUTER JOIN phototrack_photo_use ON (`phototrack-temp`.`resource-id` = phototrack_photo_use.`resource-id`) WHERE phototrack_photo_use.id IS NULL limit ' . /*$batch_size*/1000);
    if (DBA::isResult($ms_item_ids)) {
        foreach ($rows as $row) {
            Logger::debug('phototrack: remove photo ' . $row['resource-id']);
            q('DELETE FROM photo WHERE `resource-id` = "' . $row['resource-id'] . '"');
        }
    }
    q('DROP TABLE `phototrack-temp`');
    Logger::info('phototrack_tidy: deleted ' . count($rows) . ' photos');
    $rows = q('SELECT id FROM phototrack_photo_use WHERE checked < DATE_SUB(NOW(), INTERVAL 14 DAY)');
    foreach ($rows as $row) {
        q('DELETE FROM phototrack_photo_use WHERE id = ' . $row['id']);
    }
    Logger::info('phototrack_tidy: deleted ' . count($rows) . ' phototrack_photo_use rows');
}
