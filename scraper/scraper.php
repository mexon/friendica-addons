<?php
/**
 * Name: Scraper
 * Description: Follow links and extract content
 * Version: 0.1
 * Author: Matthew Exon <http://mat.exon.name>
 */

function scraper_install() {
    register_hook('post_remote', 'addon/scraper/scraper.php', 'scraper_post_remote_hook');
    register_hook('notifier_normal', 'addon/scraper/scraper.php', 'scraper_notifier_normal_hook');
    register_hook('enotify', 'addon/scraper/scraper.php', 'scraper_enotify');
    register_hook('post_local', 'addon/scraper/scraper.php', 'scraper_post_local_hook');
    register_hook('post_local_end', 'addon/scraper/scraper.php', 'scraper_post_local_end_hook');
    register_hook('prepare_body', 'addon/scraper/scraper.php', 'scraper_prepare_body_hook');
	register_hook('notifier_normal',  'addon/scraper/scraper.php', 'scraper_post_hook');
	register_hook('connector_settings',  'addon/scraper/scraper.php', 'scraper_connector_settings');
	register_hook('connector_settings_post',  'addon/scraper/scraper.php', 'scraper_connector_settings_post');
	register_hook('cron',             'addon/scraper/scraper.php', 'scraper_cron');
	register_hook('plugin_settings',  'addon/scraper/scraper.php', 'scraper_plugin_settings');
	register_hook('plugin_settings_post',  'addon/scraper/scraper.php', 'scraper_plugin_settings_post');
}


function scraper_post_remote_hook() {
    logger('scraper_post_remote_hook', LOGGER_DEBUG);
}

function scraper_notifier_normal_hook() { logger('1', LOGGER_DEBUG); }
function scraper_enotify_hook() { logger('2', LOGGER_DEBUG); }
function scraper_post_local_hook() { logger('3', LOGGER_DEBUG); }
function scraper_post_local_end_hook() { logger('4', LOGGER_DEBUG); }
function scraper_prepare_body_hook() { logger('5', LOGGER_DEBUG); }

function scraper_uninstall() {
    unregister_hook('notifier_normal', 'addon/scraper/scraper.php', 'scraper_notifier_normal_hook');
    unregister_hook('enotify', 'addon/scraper/scraper.php', 'scraper_enotify');
    unregister_hook('post_local', 'addon/scraper/scraper.php', 'scraper_post_local_hook');
    unregister_hook('post_local_end', 'addon/scraper/scraper.php', 'scraper_post_local_end_hook');
    unregister_hook('prepare_body', 'addon/scraper/scraper.php', 'scraper_prepare_body_hook');
	unregister_hook('notifier_normal',  'addon/scraper/scraper.php', 'scraper_post_hook');
	unregister_hook('connector_settings',  'addon/scraper/scraper.php', 'scraper_connector_settings');
	unregister_hook('connector_settings_post',  'addon/scraper/scraper.php', 'scraper_connector_settings_post');
	unregister_hook('cron',             'addon/scraper/scraper.php', 'scraper_cron');
	unregister_hook('plugin_settings',  'addon/scraper/scraper.php', 'scraper_plugin_settings');
	unregister_hook('plugin_settings_post',  'addon/scraper/scraper.php', 'scraper_plugin_settings_post');
}

function socscr_get_self($uid) {
    logger('socscr_get_self', LOGGER_DEBUG);
}

function scraper_cron($a,$b) {
    $r = q("SELECT `id`, `uid`, `name`, `nick`, `thumb`, `poll` FROM contact WHERE `poll` like 'plugin:scrape:%%'");
    foreach ($r as $rr) {
        scrape_feed(substr($rr['poll'], 14), $rr['id'], $rr['uid'], $rr['name'], $rr['nick'], $rr['thumb']);
    }
}

function retrieve_dom($url) {
    $req = new HTTP_Request($url, array('allowRedirects' => true ));
    $req->sendRequest();
    $doc = new DOMDocument();
    if (!$req->getResponseBody()) {
        return;
    }
    $doc->loadHTML($req->getResponseBody());
    return $doc;
}

function create_scraper($uid, $feed) {
    $template = file_get_contents(dirname(__file__).'/scrape.tpl');

    $r = q("SELECT `k`, `v` FROM pconfig WHERE `uid` = %d AND `cat` = 'scraper%s'", $uid, $feed);
    if (!count($r)) {
        logger('No configuration for feed ' . $feed);
        return;
    }
    $params = array();
    foreach ($r as $rr) {
        $params['$' . $rr['k']] = $rr['v'];
    }
    if (!$params['$match']) {
        return;
    }
    $xp = new XsltProcessor();
    $doc = new DOMDocument();
    $xslt = replace_macros($template, $params);
    $doc->loadXML($xslt);
    $xp->importStylesheet($doc); 
    return function($item) use ($xp) {
        $doc = retrieve_dom($item['plink'] . $params['suffix']);
        if (!$doc) {
            return;
        }
        return $xp->transformToXML($doc);
    };
}

function scrape_feed($input, $output, $uid, $name, $nick, $thumb) {
    $scraper = create_scraper($uid, $output);
    if (!$scraper) {
        return;
    }

    $r = q("select source.* from item as source left outer join item as dest on (source.`contact-id` = %d and dest.`contact-id` = %d and source.plink = dest.plink) where source.`contact-id` = $input and dest.id is null limit 10", $input, $output, $input);
    foreach ($r as $item) {
        reset($item);
        $item['uri'] = $item['parent-uri'] = random_string();
        if (!($scraped = $scraper($item))) {
            logger('Unable to scrape item ' . $item['plink']);
            return;
        }
        unset($item['id']);
        $item['body'] = html2bbcode($scraped);
        $item['contact-id'] = $output;
        $item['uid'] = $uid;
        $post = item_store($item);
        if ($post) {
            q("UPDATE `item` SET `uid` = %d WHERE `id` = %d", $uid, $post);
        }
    }
}

function scraper_connector_settings(&$a,&$b) {

    logger('scraper_connector_settings', LOGGER_DEBUG);

	$b .= '<div class="settings-block">';
	$b .= '<h3>' . t('Scraper Connector') . '</h3>';
	$b .= '<a href="scraper">' . t('Scraper Connector Settings') . '</a><br />';
	$b .= '</div>';
}



function scraper_connector_settings_post ($a,$post) {
    logger('scraper_connector_settings_post', LOGGER_DEBUG);
}
function individual_scraper_settings($scraper, &$b) {
        $b .= '<p>There is a scraper ' . $scraper['id'] . '</p>';

        $r = q("SELECT * FROM  `contact` WHERE `id` = %d", $scraper['id']);

$my_template = file_get_contents( dirname(__file__).'/scraper_settings.tpl');

        $contact_template = get_markup_template("contact_template.tpl");
        foreach ($r as $rr) {
            $b .= replace_macros($my_template, array('$contact' => $rr, '$scraper' => $scraper));
        }
}

function scraper_plugin_settings(&$a,&$b) {
    logger('scraper_plugin_settings', LOGGER_DEBUG);

//@@@ do this as templates, like in viewcontact_template.tpl

	$b .= '<div class="settings-block">';

        $r = q("select substr(cat, 8), k, v from pconfig where uid = %d and cat like 'scraper%%'", local_user());
        foreach($r as $rr) {
            $id = $rr['substr(cat, 8)'];
            logger('@@@ scraper id is ' . $id);
            $scrapers[$id]['id'] = $id;
            $scrapers[$id][$rr['k']] = $rr['v'];
        }
        foreach($scrapers as $scraper) {
            individual_scraper_settings($scraper, $b);
        }
}
function scraper_plugin_settings_post ($a,$post) {
    logger('scraper_plugin_settings_post', LOGGER_DEBUG);
}


function scraper_plugin_admin(&$a, &$o){
	
    logger('scraper_plugin_admin', LOGGER_DEBUG);

	$activated = scraper_check_realtime_active();
	if ($activated) {
		$o = t('Real-Time Updates are activated.') . '<br><br>';
		$o .= '<input type="submit" name="real_time_deactivate" value="' . t('Deactivate Real-Time Updates') . '">';
	} else {
		$o = t('Real-Time Updates not activated.') . '<br><input type="submit" name="real_time_activate" value="' . t('Activate Real-Time Updates') . '">';
	}
}

function scraper_plugin_admin_post(&$a, &$o){
    logger('scraper_plugin_admin_post', LOGGER_DEBUG);

	if (x($_REQUEST,'real_time_activate')) {
		scraper_subscription_add_users();
	}
	if (x($_REQUEST,'real_time_deactivate')) {
		scraper_subscription_del_users();
	}
}
