<?php
/**
 * Name: Post By Mail
 * Description: Post messages, including photos, by sending a mail to the server
 * Version: 0.1
 * Author: Matthew Exon <http://mat.exon.name>
 */

function postbymail_install() {
    register_hook('plugin_settings', 'addon/postbymail/postbymail.php', 'postbymail_plugin_settings');
    register_hook('plugin_settings_post', 'addon/postbymail/postbymail.php', 'postbymail_plugin_settings_post');
    register_hook('incoming_mail', 'addon/postbymail/postbymail.php', 'postbymail_incoming_mail');
/*
    $schema = file_get_contents(dirname(__file__).'/database.sql');
    $arr = explode(';', $schema);
    foreach ($arr as $a) {
        $r = q($a);
    }
    set_config('postbymail', 'dbversion', '0.1');
*/
}

function postbymail_uninstall() {
    unregister_hook('plugin_settings', 'addon/postbymail/postbymail.php', 'postbymail_plugin_settings');
    unregister_hook('plugin_settings_post', 'addon/postbymail/postbymail.php', 'postbymail_plugin_settings_post');
    unregister_hook('incoming_mail', 'addon/postbymail/postbymail.php', 'postbymail_incoming_mail');
}

function postbymail_plugin_settings(&$a,&$s) {
}

function postbymail_plugin_settings_post($a,$post) {
}

function postbymail_incoming_mail($a, &$mail) {
    // handles basic posting
}

?>
