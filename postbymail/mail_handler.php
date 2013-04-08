#!/usr/bin/php
<?php

chdir(dirname(__FILE__) . '/../..');
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

    require_once(dirname(__FILE__) . '/mail.php');
    $incoming = new IncomingMail();
    foreach ($incoming->consume_directive('session') as $session) {
        session_id($session['value']);
    }
    @session_start(); // ignore warnings about headers already being sent

    try {
        $address = '';
        $username = '';
        if (!$_SESSION['uid']) {
            $from = mailparse_rfc822_parse_addresses($incoming->from);
            if (count($from)) {
                throw new Exception('From line contained ' . $count($from) . ' addresses');
            }
            $address = $from[0]['address'];
            $username = $from[0]['display'];
            $from_user = q('SELECT `uid`, `username` FROM `user` WHERE `email` = "%s" ORDER BY `uid` LIMIT 1', $address);
            if (count($from_user)) {
                $_SESSION['uid'] = $from_user[0]['uid'];
                $username = $from_user[0]['username'];
            }
        }

        call_hooks('incoming_mail_directives', $incoming);

        require_once(dirname(__file__).'../mailstream/class.phpmailer.php');

        $frommail = get_config('mailstream', 'frommail');
        if ($frommail == '') {
            $frommail = 'friendica@localhost.local'; //@@@ all kinds of bad
        }

        $outgoing = new PHPmailer;
        $outgoing->XMailer = 'Friendica Post By Mail Plugin';
        $outgoing->SetFrom($frommail);
        $outgoing->AddAddress($address, $username);
        $outgoing->Subject('Re: ' . $incoming->subject);
        $outgoing->MessageID = '<' . hash('md5', 'reply ' . $incoming->message_id) . '@' . $a->get_hostname . '>';
        $outgoing->addCustomHeader('In-Reply-To: ' . $incoming->message_id);

        call_hooks('incoming_mail', $incoming, $outgoing);

        if (!$mail->Body) {
            default_reply($mail);
        }

        if (!$mail->Send()) {
            throw new Exception($mail->ErrorInfo);
        }
        $_SESSION['authenticated'] = true;

    } catch (phpmailerException $e) {
        logger('postbymail_hooks_run: PHPMailer exception sending message ' . $mail->MessageID . ' ' . $mail->Subject . ': ' . $e->errorMessage());
    } catch (Exception $e) {
        logger('postbymail_hooks_run: exception sending message ' . $mail->MessageID . ' ' . $mail->Subject . ': ' . $e->getMessage());
    }
    return;
}

function default_reply(&$mail) {
    $template = get_markup_template('default_reply.tpl', 'addon/postbymail/');
    $mail->Body = replace_macros($template, array());
}

if (array_search(__file__,get_included_files())===0){
    postbymail_hooks_run($argv,$argc);
    killme();
}

?>
