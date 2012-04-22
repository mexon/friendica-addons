<?php
/**
 * Name: Retrieve Feed Content
 * Description: Follow the permalink of RSS/Atom feed items and replace the summary with the full content.
 * Version: 0.1
 * Author: Matthew Exon <http://mat.exon.name>
 */

function retriever_install() {
    set_config('retriever', 'dbversion', '0.1');
    register_hook('post_remote', 'addon/retriever/retriever.php', 'retriever_post_remote_hook');
    register_hook('plugin_settings',  'addon/retriever/retriever.php', 'retriever_plugin_settings');
    register_hook('plugin_settings_post',  'addon/retriever/retriever.php', 'retriever_plugin_settings_post');
}

function retriever_uninstall() {
    unregister_hook('post_remote', 'addon/retriever/retriever.php', 'retriever_post_remote_hook');
    unregister_hook('plugin_settings',  'addon/retriever/retriever.php', 'retriever_plugin_settings');
    unregister_hook('plugin_settings_post',  'addon/retriever/retriever.php', 'retriever_plugin_settings_post');
}

function get_retriever($item) {
    global $retriever_cache;

    if (array_key_exists($item['contact-id'], $retriever_cache)) {
        return $retriever_cache[$item['contact-id']];
    }

    $params = array('$enable' => get_pconfig($item['uid'], 'retriever' . $item['contact-id'], 'enable'),
                    '$pattern' => get_pconfig($item['uid'], 'retriever' . $item['contact-id'], 'pattern'),
                    '$replace' => get_pconfig($item['uid'], 'retriever' . $item['contact-id'], 'replace'),
                    '$match' => get_pconfig($item['uid'], 'retriever' . $item['contact-id'], 'match'),
                    '$remove' => get_pconfig($item['uid'], 'retriever' . $item['contact-id'], 'remove'));
    if (!$params['$enable']) {
        return $retriever_cache[$item['contact-id']] = false;
    }
    $extracter_template = file_get_contents(dirname(__file__).'/extract.tpl');

    return $retriever_cache[$item['contact-id']] = function($item) use ($extracter_template, $params) {
        if ($params['$pattern']) {
            $url = preg_replace($params['$pattern'], $params['$replace'], $item['plink']);
        }
        else {
            $url = $item['plink'];
        }
        logger('Retrieving content from URL: ' . $url);
        $text = fetch_url($url);
        if (!$text) {
            return;
        }
        $doc = new DOMDocument();
        $doc->loadHTML($text);

        $params['$pageurl'] = $item['plink'];
        $components = array();
        preg_match('/([a-zA-Z]+:\/\/[^\/]+)(\/.*\/)(.*)/', $item['plink'], $components);
        $params['$dirurl'] = $components[1] . $components[2];
        $params['$rooturl'] = $components[1];
        $xslt = replace_macros($extracter_template, $params);
        $xmldoc = new DOMDocument();
        $xmldoc->loadXML($xslt);
        $xp = new XsltProcessor();
        $xp->importStylesheet($xmldoc);
        return $xp->transformToXML($doc);
    };
}

function retriever_post_remote_hook(&$a, &$item) {
    logger('retriever_post_remote_hook: ' . $item['plink'], LOGGER_DEBUG);

    if ($retriever = get_retriever($item)) {
        $retrieved = $retriever($item);
        if (!$retrieved) {
            logger('Unable to retrieve item ' . $item['plink']);
            return;
        }
        $item['body'] = html2bbcode($retrieved);
    }
}

function retriever_plugin_settings(&$a,&$o) {

    $query = <<<EOF
select contact.id, contact.name, contact.micro, k, v
 from contact left outer join pconfig
 on (pconfig.cat = concat('retriever', contact.id))
 where contact.uid = %d
 and contact.network = 'feed'
 order by contact.id
 limit 100
EOF;
    $r = q($query, local_user());
    foreach($r as $rr) {
        $settings[$rr['id']]['id'] = $rr['id'];
        $settings[$rr['id']]['name'] = $rr['name'];
        $settings[$rr['id']]['micro'] = $rr['micro'];
        if ($rr['k']) {
            $settings[$rr['id']][$rr['k']] = htmlentities($rr['v'], ENT_QUOTES);
        }
    }

    $retrievers_template = file_get_contents(dirname(__file__).'/settings.tpl');
    $o .= replace_macros($retrievers_template, array(
        '$title' => t('Retrieve Feed Content'),
        '$submit' => t('Submit'),
        '$feeds' => $settings));
}

function retriever_plugin_settings_post ($a,$post) {

    $r = q("select id from contact where uid = %d and network = 'feed' order by id desc limit 100", local_user());
    foreach ($r as $rr) {
        foreach (array('enable', 'pattern', 'replace', 'match', 'remove') as $setting) {
            if ($_POST[$setting . $rr['id']]) {
                set_pconfig(local_user(), 'retriever' . $rr['id'], $setting, $_POST[$setting . $rr['id']]);
            }
            else {
                del_pconfig(local_user(), 'retriever' . $rr['id'], $setting);
            }
        }
    }
}
