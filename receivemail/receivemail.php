<?php
/**
 * Name: Receive Mail
 * Description: Process incoming mail commands
 * Version: 0.1
 * Author: Matthew Exon <http://mat.exon.name>
 */

function receivemail_install() {
    register_hook('plugin_settings', 'addon/receivemail/receivemail.php', 'receivemail_plugin_settings');
    register_hook('plugin_settings_post', 'addon/receivemail/receivemail.php', 'receivemail_plugin_settings_post');
    register_hook('incoming_mail', 'addon/receivemail/receivemail.php', 'receivemail_incoming_mail');
/*
    $schema = file_get_contents(dirname(__file__).'/database.sql');
    $arr = explode(';', $schema);
    foreach ($arr as $a) {
        $r = q($a);
    }
    set_config('receivemail', 'dbversion', '0.1');
*/
}

function receivemail_uninstall() {
    unregister_hook('plugin_settings', 'addon/receivemail/receivemail.php', 'receivemail_plugin_settings');
    unregister_hook('plugin_settings_post', 'addon/receivemail/receivemail.php', 'receivemail_plugin_settings_post');
    unregister_hook('incoming_mail', 'addon/receivemail/receivemail.php', 'receivemail_incoming_mail');
}

function receivemail_plugin_settings(&$a,&$s) {
}

function receivemail_plugin_settings_post($a,$post) {
}

function receivemail_incoming_mail($a, &$mail) {
    // handles basic posting
}

?>
