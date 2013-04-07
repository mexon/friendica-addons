#!/usr/bin/php
<?php

require_once("boot.php");

function postbymail_hooks_run($argv, $argc){
    global $a, $db;

    if(is_null($a)) {
        $a = new App;
    }

    if(is_null($db)) {
        @include(".htconfig.php");
    	require_once("dba.php");
        $db = new dba($db_host, $db_user, $db_pass, $db_data);
    	unset($db_host, $db_user, $db_pass, $db_data);
    };

    require_once('include/session.php');
    require_once('include/datetime.php');

    load_config('config');
    load_config('system');

    $a->set_baseurl(get_config('system','url'));

    load_hooks();

    logger('postbymail: start');

    postbymail_decode_stdin();

//    call_hooks('postbymail', $d);

    return;
}

function postbymail_decode_stdin() {
    require_once 'Mail/mimeDecode.php';
    require_once('include/html2bbcode.php');

    $content = file_get_contents("php://stdin");

    $decoder = new Mail_mimeDecode($content);
    $structure = $decoder->decode(array('include_bodies' => TRUE, 'decode_bodies' => TRUE, 'decode_headers' => TRUE));

    $from = $message['from'];
    logger('postbymail_decode_stdin: got message from ' . $message['from'] . ' subject ' . $message['subject']);

}

if (array_search(__file__,get_included_files())===0){
    postbymail_hooks_run($argv,$argc);
    killme();
}

?>
