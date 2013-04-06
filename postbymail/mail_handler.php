#!/usr/bin/php
<?php

chdir(dirname(__file__) . "/..");

require_once("boot.php");

function incoming_mail_hooks_run($argv, $argc){
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

    logger('incoming_mail: start');

    $d = datetime_convert();

    call_hooks('incoming_mail', $d);

    return;
}

if (array_search(__file__,get_included_files())===0){
    incoming_mail_hooks_run($argv,$argc);
    killme();
}

?>
