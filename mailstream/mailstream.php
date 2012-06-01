<?php
/**
 * Name: Mail Stream
 * Description: Mail all items coming into your network feed to an email address
 * Version: 0.1
 * Author: Matthew Exon <http://mat.exon.name>
 */

function mailstream_install() {
    register_hook('plugin_settings', 'addon/mailstream/mailstream.php', 'mailstream_plugin_settings');
    register_hook('plugin_settings_post', 'addon/mailstream/mailstream.php', 'mailstream_plugin_settings_post');
    register_hook('post_remote', 'addon/mailstream/mailstream.php', 'mailstream_post_remote_hook');
    register_hook('cron', 'addon/mailstream/mailstream.php', 'mailstream_cron');
    register_hook('incoming_mail', 'addon/mailstream/mailstream.php', 'mailstream_incoming_mail');

    $schema = file_get_contents(dirname(__file__).'/database.sql');
    $arr = explode(';', $schema);
    foreach ($arr as $a) {
        $r = q($a);
    }

    set_config('mailstream', 'dbversion', '0.1');
}

function mailstream_uninstall() {
    unregister_hook('plugin_settings', 'addon/mailstream/mailstream.php', 'mailstream_plugin_settings');
    unregister_hook('plugin_settings_post', 'addon/mailstream/mailstream.php', 'mailstream_plugin_settings_post');
    unregister_hook('post_remote', 'addon/mailstream/mailstream.php', 'mailstream_post_remote_hook');
    unregister_hook('cron', 'addon/mailstream/mailstream.php', 'mailstream_cron');
    unregister_hook('incoming_mail', 'addon/mailstream/mailstream.php', 'mailstream_incoming_mail');
}

function mailstream_module() {}

function mailstream_incoming_mail($a, $b) {
    logger('@@@ mailstream_incoming_mail');
    $content = file_get_contents("php://stdin");
    logger($content);
}

function mailstream_generate_id($a) {
// http://www.jwz.org/doc/mid.html
    $host = $a->get_hostname();
    $resource = hash('md5',uniqid(mt_rand(),true)); // NOT especially safe from the birthday paradox
    return "<" . $resource . "@" . $host . ">";
}

function mailstream_post_remote_hook(&$a, &$item) {
    if (get_pconfig($item['uid'], 'mailstream', 'enabled')) {
        if ($item['uid'] && $item['contact-id'] && $item['plink']) {
            q("INSERT INTO `mailstream_item` (`uid`, `contact-id`, `plink`, `message-id`, `created`) " .
              "VALUES (%d, '%s', '%s', '%s', now())", intval($item['uid']),
              intval($item['contact-id']), dbesc($item['plink']), dbesc(mailstream_generate_id($a)));
        }
    }
}

function mailstream_do_images($a, &$item, &$attachments) {
logger('@@@ mailstream_do_images ' . $item['plink']);
    $baseurl = $a->get_baseurl();
    $id = 1;
    $matches = array();
    preg_match_all("/\[img\=([0-9]*)x([0-9]*)\](.*?)\[\/img\]/ism", $item["body"], $matches);
    if (count($matches)) {
        foreach ($matches[3] as $url) {
logger('@@@ found image ' . $url);
            $attachments[$url] = array();
        }
    }
    preg_match_all("/\[img\](.*?)\[\/img\]/ism", $item["body"], $matches);
    if (count($matches)) {
        foreach ($matches[1] as $url) {
logger('@@@ found image ' . $url);
            $attachments[$url] = array();
        }
    }
    foreach ($attachments as $url=>$cid) {
        if (strncmp($url, $baseurl, strlen($baseurl))) {
logger('@@@ ' . $url . ' does not match ' . $baseurl);
            unset($attachments[$url]); // Not a local image, don't replace
        }
        else {
            $attachments[$url]['guid'] = substr($url, strlen($baseurl) + strlen('/photo/'));
            $r = q("SELECT `data`, `filename` FROM `photo` WHERE `resource-id` = '%s'", dbesc($attachments[$url]['guid']));
            $attachments[$url]['data'] = $r[0]['data'];
            $attachments[$url]['filename'] = $r[0]['filename'];
            $item['body'] = str_replace($url, 'cid:' . $attachments[$url]['guid'], $item['body']);
logger('@@@ attaching ' . $url . ' with guid ' . $attachments[$url]['guid']);
        }
    }
}

function mailstream_subject($item) {
    if ($item['title']) {
        return $item['title'];
    }
    $r = q("SELECT * FROM `contact` WHERE `id` = %d AND `uid` = %d",
           intval($item['contact-id']), intval($item['uid']));
    $contact = $r[0];
    if ($contact['network'] === 'dfrn') {
        return "Friendica post";
    }
    if ($contact['network'] === 'dspr') {
        return "Diaspora post";
    }
    if ($contact['network'] === 'face') {
        $subject = (strlen($item['body']) > 150) ? (substr($item['body'], 0, 140) . '...') : $item['body'];
        return preg_replace('/\\s+/', ' ', $subject);
    }
    if ($contact['network'] === 'feed') {
        return "Feed item";
    }
    if ($contact['network'] === 'mail') {
        return "Email";
    }
    return "Friendica Item";
}

function mailstream_send($a, $ms_item, $item, $user) {
    require_once(dirname(__file__).'/class.phpmailer.php');
    require_once('include/bbcode.php');
    $attachments = array();
    mailstream_do_images($a, $item, $attachments);
    $email = get_pconfig($item['uid'], 'mailstream', 'address');
    $mail = new PHPmailer;
    try {
        $mailer->XMailer = 'Friendica Mailstream Plugin';
        $mail->SetFrom('friendica@localhost.local', $item['author-name']);
        $mail->AddAddress($email, $user['username']);
        $mail->MessageID = $ms_item['message-id'];
        $mail->Subject = mailstream_subject($item);
        foreach ($attachments as $url=>$image) {
            $mail->AddStringEmbeddedImage($image['data'], $image['guid'], $image['filename']);
        }
        $mail->IsHTML(true);
        $mail->CharSet = 'utf-8';
        $template = file_get_contents(dirname(__file__).'/mail.tpl');
        $item['body'] = bbcode($item['body']);
        $mail->Body = replace_macros($template, array('$item' => $item));
        $mail->Send();
        q("UPDATE `mailstream_item` SET `completed` = now() WHERE `id` = %d", intval($ms_item['id']));
    } catch (phpmailerException $e) {
        logger('PHPMailer exception: ' . $e->errorMessage()); //Pretty error messages from PHPMailer
    }
}

function mailstream_cron($a, $b) {
    $query = <<<EOF
SELECT `mailstream_item`.*
 FROM `mailstream_item`, `pconfig`
 WHERE `mailstream_item`.`uid` = `pconfig`.`uid`
 AND `pconfig`.`cat` = 'mailstream'
 AND `pconfig`.`k` = 'delay'
 AND `completed` = '0000-00-00 00:00:00'
 AND timestampadd(MINUTE, convert(`pconfig`.`v`, DECIMAL), `created`) < now()
 LIMIT 100
EOF;
    $ms_items = q($query);
    foreach ($ms_items as $ms_item) {
        $items = q("SELECT * FROM `item` WHERE `uid` = %d AND `plink` = '%s' AND `contact-id` = %d",
                   intval($ms_item['uid']), dbesc($ms_item['plink']), intval($ms_item['contact-id']));
        $item = $items[0];
        $users = q("SELECT * FROM `user` WHERE `uid` = %d", intval($ms_item['uid']));
        $user = $users[0];
        if ($user && $item) {
            mailstream_send($a, $ms_item, $item, $user);
        }
        else {
            logger('mailstream_cron: Unable to find item ' . $ms_item['plink']);
        }
    }
}

function mailstream_plugin_settings(&$a,&$s) {
    $enabled = get_pconfig(local_user(), 'mailstream', 'enabled');
    $enabled_mu = ($enabled == 'on') ? ' checked="true"' : '';
    $address = get_pconfig(local_user(), 'mailstream', 'address');
    $address_mu = $address ? (' value="' . $address . '"') : '';
    $delay = get_pconfig(local_user(), 'mailstream', 'delay');
    $delay_mu = ' value="' . (($delay > 0) ? $delay : 60) . '"';
    $template = file_get_contents(dirname(__file__).'/settings.tpl');
    $s .= replace_macros($template, array('$address' => $address_mu,
                                          '$delay' => $delay_mu,
                                          '$enabled' => $enabled_mu));
}

function mailstream_plugin_settings_post($a,$post) {
    if ($_POST['address'] != "") {
        set_pconfig(local_user(), 'mailstream', 'address', $_POST['address']);
    }
    if ($_POST['delay'] > 0) {
        set_pconfig(local_user(), 'mailstream', 'delay', $_POST['delay']);
    }
    if ($_POST['enabled']) {
        set_pconfig(local_user(), 'mailstream', 'enabled', $_POST['enabled']);
    }
    else {
        del_pconfig(local_user(), 'mailstream', 'enabled');
    }
}
