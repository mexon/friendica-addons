<?php
/**
 * Name: Mail Stream
 * Description: Mail all items coming into your network feed to an email address
 * Version: 0.1
 * Author: Matthew Exon <http://mat.exon.name>
 */
set_include_path("/Users/mat/system/pear/share/pear");

function mailstream_install() {
    register_hook('plugin_settings', 'addon/mailstream/mailstream.php', 'mailstream_plugin_settings');
    register_hook('plugin_settings_post', 'addon/mailstream/mailstream.php', 'mailstream_plugin_settings_post');
    register_hook('post_remote_end', 'addon/mailstream/mailstream.php', 'mailstream_post_remote_hook');
    register_hook('cron', 'addon/mailstream/mailstream.php', 'mailstream_cron');
    register_hook('incoming_mail', 'addon/mailstream/mailstream.php', 'mailstream_incoming_mail');

    $schema = file_get_contents(dirname(__file__).'/database.sql');
    $arr = explode(';', $schema);
    foreach ($arr as $a) {
        $r = q($a);
    }

    if (get_config('mailstream', 'dbversion') == '0.1') {
        q('ALTER TABLE `mailstream_item` DROP INDEX `uid`');
        q('ALTER TABLE `mailstream_item` DROP INDEX `contact-id`');
        q('ALTER TABLE `mailstream_item` DROP INDEX `plink`');
        q('ALTER TABLE `mailstream_item` CHANGE `plink` `uri` char(255) NOT NULL');
    }
    if (get_config('mailstream', 'dbversion') == '0.2') {
        q('DELETE FROM `pconfig` WHERE `cat` = "mailstream" AND `k` = "delay"');
    }
    set_config('mailstream', 'dbversion', '0.3');
}

function mailstream_uninstall() {
    unregister_hook('plugin_settings', 'addon/mailstream/mailstream.php', 'mailstream_plugin_settings');
    unregister_hook('plugin_settings_post', 'addon/mailstream/mailstream.php', 'mailstream_plugin_settings_post');
    unregister_hook('post_remote', 'addon/mailstream/mailstream.php', 'mailstream_post_remote_hook');
    unregister_hook('post_remote_end', 'addon/mailstream/mailstream.php', 'mailstream_post_remote_hook');
    unregister_hook('cron', 'addon/mailstream/mailstream.php', 'mailstream_cron');
    unregister_hook('incoming_mail', 'addon/mailstream/mailstream.php', 'mailstream_incoming_mail');
}

function mailstream_module() {}

function mailstream_plugin_admin(&$a,&$o) {
    $frommail = get_config('mailstream', 'frommail');
    $template = file_get_contents(dirname(__file__).'/admin.tpl');
    $o .= replace_macros($template, array('$frommail' => array('frommail', 'From Address', $frommail, 'Email address that items from the stream will appear to be from.  This should ideally be a valid place to send replies.')));
}

function mailstream_plugin_admin_post ($a) {
    if (x($_POST, 'frommail')) {
        set_config('mailstream', 'frommail', $_POST['frommail']);
    }
}

function mailstream_incoming_mail($a, $b) {
    require_once 'Mail/mimeDecode.php';
    require_once('include/html2bbcode.php');

    $content = file_get_contents("php://stdin");

    $decoder = new Mail_mimeDecode($content);
    $structure = $decoder->decode(array('include_bodies' => TRUE, 'decode_bodies' => TRUE, 'decode_headers' => TRUE));
    $message = array();
    if ($structure->headers['in-reply-to']) {
        $message['in-reply-to'] = $structure->headers['in-reply-to'];
    }
    if ($structure->headers['subject']) {
        $message['subject'] = $structure->headers['subject'];
    }
    mailstream_process_structure($structure, $message);
    mailstream_handle_permissions($message);
    mailstream_create_images($message);
    mailstream_create_item($a, $message);
}

function mailstream_handle_permissions(&$message) {
    $m = array();
    if (!preg_match('/\[meta\](.*)\[\/meta\]/', $message['body'], $m)) {
        return false;
    }
    $message['body'] = preg_replace('/\[meta\].*\[\/meta\]/', "", $message['body']);
    $meta = json_decode($m[1]);
    print_r($meta);
    echo "\n";
    $encrypted = hash('whirlpool', trim($meta->password));
    echo $meta->username . "\n";
    echo $encrypted . "\n";
    $r = q("SELECT * FROM `user` WHERE `nickname` = '%s' AND `password` = '%s' AND `blocked` = 0 AND `account_expired` = 0 AND `verified` = 1", $meta->username, $encrypted);
    if (!count($r)) {
        echo "no user matched " . $meta->username . "\n";
        return false;
    }
    $_SESSION['authenticated'] = true;
    $_SESSION['uid'] = $r[0]['uid'];
    $message['uid'] = local_user();
    $r = q("SELECT * FROM `contact` WHERE `uid` = %d AND `self` = 1", local_user());
    $message['contact'] = $r[0];
    $message['contact-id'] = $message['contact']['id'];
    $message['postopts'] = $meta->postopts;
    return true;
}

function mailstream_process_structure($structure, &$message) {
    if (!$message['images']) {
        $message['images'] = array();
    }
    if ($structure->headers['date']) {
        $message['date'] = datetime_convert('UTC','UTC',$structure->headers['date']);
    }
    if ($structure->headers['subject']) {
        $message['title'] = $structure->headers['subject'];
    }
    if (($structure->ctype_primary === 'text') && ($structure->ctype_secondary === 'plain')) {
        $message['body'] = $structure->body;
    }
    if (($structure->ctype_primary === 'multipart') && ($structure->ctype_secondary === 'related')) {
        foreach ($structure->parts as $part) {
            mailstream_process_structure($part, $message);
        }
    }
    if (($structure->ctype_primary === 'multipart') && ($structure->ctype_secondary === 'alternative')) {
        $best = null;
        foreach ($structure->parts as $part) {
            if ((($part->ctype_primary === 'text') && ($part->ctype_secondary === 'html')) || !$best) {
                $best = $part;
            }
            if ($best->ctype_secondary === 'html') {
                $best->body = html2bbcode($best->body);
            }
            $message['body'] = $best->body;
        }
    }
    if ($structure->ctype_primary === 'image') {
        $matches = array();
        if (preg_match('/<([^>]+)>/', $structure->headers['content-id'], $matches)) {
            $image = array('cid' => $matches[1], 'data' => $structure->body);
            if (preg_match('/name=([^;]+)/', $structure->headers['content-type'], $matches)) {
                $image['name'] = $matches[1];
            }
            else {
                $image['name'] = 'email-attachment-' . $image['cid'];
            }
            array_push($message['images'], $image);
        }
    }
}

function mailstream_create_images($message) {
    require_once('include/Photo.php');	

    foreach ($message['images'] as $image) {
        $img = new Photo($image['data']);
        $image['hash'] = photo_new_resource();
        $r = $img->store(local_user(), $message['contact-id'], $image['hash'], $image['name'], 'Post by Email', 0);
        $new_url = get_app()->get_baseurl() . '/photo/' . $image['hash'];
        $message['body'] = str_replace('cid:' . $image['cid'], $new_url, $message['body']);
    }
}

function mailstream_create_item($a, $message) {
    $message['type'] = 'wall';
    $message['wall'] = 1;
    $message['gravity'] = 0;
    $message['guid'] = get_guid();
    $message['owner-name'] = $message['contact']['name'];
    $message['owner-link'] = $message['contact']['url'];
    $message['owner-avatar'] = $message['contact']['thumb'];
    $message['author-name'] = $message['contact']['name'];
    $message['author-link']   = $message['contact']['url'];
    $message['author-avatar'] = $message['contact']['thumb'];
    $message['created'] = $message['date'];
    $message['edited'] = $message['date'];
    $message['commented'] = $message['date'];
    $message['received'] = datetime_convert();
    $message['changed'] = datetime_convert();
    $message['uri'] = $uri = item_new_uri($a->get_hostname(), local_user());
    $message['verb'] = ACTIVITY_POST;

    call_hooks('post_local', $message);
    if(x($datarray,'cancel')) {
        logger('mod_item: post cancelled by plugin.', LOGGER_DEBUG);
        return;
    }

    $r = q("INSERT INTO `item` (`guid`, `uid`,`type`,`wall`,`gravity`,`contact-id`,`owner-name`,`owner-link`,`owner-avatar`, 
		`author-name`, `author-link`, `author-avatar`, `created`, `edited`, `commented`, `received`, `changed`, `uri`, `thr-parent`, `title`, `body`, `app`, `location`, `coord`, 
		`tag`, `inform`, `verb`, `postopts`, `allow_cid`, `allow_gid`, `deny_cid`, `deny_gid`, `private`, `pubmail`, `attach`, `bookmark`,`origin`, `moderated`, `file` )
		VALUES( '%s', %d, '%s', %d, %d, %d, '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', %d, %d, '%s', %d, %d, %d, '%s' )",
           dbesc($message['guid']),
           intval($message['uid']),
           dbesc($message['type']),
           intval($message['wall']),
           intval($message['gravity']),
           intval($message['contact-id']),
           dbesc($message['owner-name']),
           dbesc($message['owner-link']),
           dbesc($message['owner-avatar']),
           dbesc($message['author-name']),
           dbesc($message['author-link']),
           dbesc($message['author-avatar']),
           dbesc($message['created']),
           dbesc($message['edited']),
           dbesc($message['commented']),
           dbesc($message['received']),
           dbesc($message['changed']),
           dbesc($message['uri']),
           dbesc($message['thr-parent']),
           dbesc($message['title']),
           dbesc($message['body']),
           dbesc($message['app']),
           dbesc($message['location']),
           dbesc($message['coord']),
           dbesc($message['tag']),
           dbesc($message['inform']),
           dbesc($message['verb']),
           dbesc($message['postopts']),
           dbesc($message['allow_cid']),
           dbesc($message['allow_gid']),
           dbesc($message['deny_cid']),
           dbesc($message['deny_gid']),
           intval($message['private']),
           intval($message['pubmail']),
           dbesc($message['attach']),
           intval($message['bookmark']),
           intval($message['origin']),
           intval($message['moderated']),
           dbesc($message['file'])
        );

	$r = q("SELECT `id` FROM `item` WHERE `uri` = '%s' LIMIT 1",
		dbesc($message['uri']));

        $item['id'] = $r[0]['id'];
        echo 'mailstream_incoming: saved item ' . $message['id'] . "\n";
        $item['plink'] = $a->get_baseurl() . '/display/' . $message['contact']['nick'] . "/" . $item['id'];
        $r = q("UPDATE `item` SET `unseen` = 0, `origin` = 1, `visible` = 1, `parent` = `id`, `parent-uri` = `uri`, `plink` = '%s' WHERE `id` = %d", $item['plink'], $item['id']);

	call_hooks('post_local_end', $message);

	proc_run('php', "include/notifier.php", 'wall-new', $message['id']);
}

function mailstream_generate_id($a, $uri) {
// http://www.jwz.org/doc/mid.html
    $host = $a->get_hostname();
    $resource = hash('md5', $uri);
    return "<" . $resource . "@" . $host . ">";
}

function mailstream_post_remote_hook(&$a, &$item) {
    if (get_pconfig($item['uid'], 'mailstream', 'enabled')) {
        if ($item['uid'] && $item['contact-id'] && $item['uri']) {
            q("INSERT INTO `mailstream_item` (`uid`, `contact-id`, `uri`, `message-id`, `created`) " .
              "VALUES (%d, '%s', '%s', '%s', now())", intval($item['uid']),
              intval($item['contact-id']), dbesc($item['uri']), dbesc(mailstream_generate_id($a, $item['uri'])));
            $r = q('SELECT * FROM `mailstream_item` WHERE `uid` = %d AND `contact-id` = %d AND `uri` = "%s"', intval($item['uid']), intval($item['contact-id']), dbesc($item['uri']));
            if (count($r) != 1) {
                logger('mailstream_post_remote_hook: Unexpected number of items returned from mailstream_item', LOGGER_ERROR);
                return;
            }
            $ms_item = $r[0];
            logger('mailstream_post_remote_hook: created mailstream_item '
                   . $ms_item['id'] . ' for item ' . $item['uri'] . ' '
                   . $item['uid'] . ' ' . $item['contact-id'], LOGGER_DATA);
            $r = q('SELECT * FROM `user` WHERE `uid` = %d', intval($item['uid']));
            if (count($r) != 1) {
                logger('mailstream_post_remote_hook: Unexpected number of users returned', LOGGER_ERROR);
                return;
            }
            $user = $r[0];
            mailstream_send($a, $ms_item, $item, $user);
        }
    }
}

function mailstream_do_images($a, &$item, &$attachments) {
    $baseurl = $a->get_baseurl();
    $id = 1;
    $matches = array();
    preg_match_all("/\[img\=([0-9]*)x([0-9]*)\](.*?)\[\/img\]/ism", $item["body"], $matches);
    if (count($matches)) {
        foreach ($matches[3] as $url) {
            $attachments[$url] = array();
        }
    }
    preg_match_all("/\[img\](.*?)\[\/img\]/ism", $item["body"], $matches);
    if (count($matches)) {
        foreach ($matches[1] as $url) {
            $attachments[$url] = array();
        }
    }
    foreach ($attachments as $url=>$cid) {
        if (strncmp($url, $baseurl, strlen($baseurl))) {
            unset($attachments[$url]); // Not a local image, don't replace
        }
        else {
            $attachments[$url]['guid'] = substr($url, strlen($baseurl) + strlen('/photo/'));
            $r = q("SELECT `data`, `filename`, `type` FROM `photo` WHERE `resource-id` = '%s'", dbesc($attachments[$url]['guid']));
            $attachments[$url]['data'] = $r[0]['data'];
            $attachments[$url]['filename'] = $r[0]['filename'];
            $attachments[$url]['type'] = $r[0]['type'];
            $item['body'] = str_replace($url, 'cid:' . $attachments[$url]['guid'], $item['body']);
        }
    }
}

function mailstream_subject($item) {
    if ($item['title']) {
        return $item['title'];
    }
    if ($item['thr-parent'] && ($item['thr-parent'] != $item['uri'])) {
        $parent = $item['thr-parent'];
        while ($parent) {
            $r = q("SELECT `thr-parent`, `title` FROM `item` WHERE `uri` = '%s'", dbesc($parent));
            if (!count($r)) {
                break;
            }
            if ($r[0]['title']) {
                return 'Re: ' . $r[0]['title'];
            }
            $parent = $r[0]['thr-parent'];
        }
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
    if (!$item['visible']) {
        return;
    }
    require_once(dirname(__file__).'/class.phpmailer.php');
    require_once('include/bbcode.php');
    $attachments = array();
    mailstream_do_images($a, $item, $attachments);
    $frommail = get_config('mailstream', 'frommail');
    if ($frommail == "") {
        $frommail = 'friendica@localhost.local';
    }
    $email = get_pconfig($item['uid'], 'mailstream', 'address');
    $mail = new PHPmailer;
    try {
        $mailer->XMailer = 'Friendica Mailstream Plugin';
        $mail->SetFrom($frommail, $item['author-name']);
        $mail->AddAddress($email, $user['username']);
        $mail->MessageID = $ms_item['message-id'];
        $mail->Subject = mailstream_subject($item);
        if ($item['thr-parent'] != $item['uri']) {
            $mail->addCustomHeader('In-Reply-To: ' . mailstream_generate_id($a, $item['thr-parent']));
        }
        $encoding = 'base64';
        foreach ($attachments as $url=>$image) {
            $mail->AddStringEmbeddedImage($image['data'], $image['guid'], $image['filename'], $encoding, $image['type']);
        }
        $mail->IsHTML(true);
        $mail->CharSet = 'utf-8';
        $template = file_get_contents(dirname(__file__).'/mail.tpl');
        $item['body'] = bbcode($item['body']);
        $mail->Body = replace_macros($template, array('$item' => $item));
        if (!$mail->Send()) {
            throw new Exception($mail->ErrorInfo);
        }
        logger('mailstream_send sent message ' . $mail->MessageID . ' ' . $mail->Subject, LOGGER_DEBUG);
    } catch (phpmailerException $e) {
        logger('mailstream_send PHPMailer exception sending message ' . $ms_item['message-id'] . ': ' . $e->errorMessage(), LOGGER_ERROR);
    } catch (Exception $e) {
        logger('mailstream_send exception sending message ' . $ms_item['message-id'] . ': ' . $e->getMessage(), LOGGER_ERROR);
    }
    // In case of failure, still set the item to completed.  Otherwise
    // we'll just try to send it over and over again and it'll fail
    // every time.
    q("UPDATE `mailstream_item` SET `completed` = now() WHERE `id` = %d", intval($ms_item['id']));
}

function mailstream_cron($a, $b) {
    $ms_items = q("SELECT * FROM `mailstream_item` WHERE `completed` = '0000-00-00 00:00:00' LIMIT 100");
    logger('mailstream_cron processing ' . count($ms_items) . ' items', LOGGER_DEBUG);
    foreach ($ms_items as $ms_item) {
        $items = q("SELECT * FROM `item` WHERE `uid` = %d AND `uri` = '%s' AND `contact-id` = %d",
                   intval($ms_item['uid']), dbesc($ms_item['uri']), intval($ms_item['contact-id']));
        $item = $items[0];
        $users = q("SELECT * FROM `user` WHERE `uid` = %d", intval($ms_item['uid']));
        $user = $users[0];
        if ($user && $item) {
            mailstream_send($a, $ms_item, $item, $user);
        }
        else {
            logger('mailstream_cron: Unable to find item ' . $ms_item['uri'], LOGGER_ERROR);
            q("UPDATE `mailstream_item` SET `completed` = now() WHERE `id` = %d", intval($ms_item['id']));
        }
    }
    mailstream_tidy();
}

function mailstream_plugin_settings(&$a,&$s) {
    $enabled = get_pconfig(local_user(), 'mailstream', 'enabled');
    $enabled_mu = ($enabled == 'on') ? ' checked="true"' : '';
    $address = get_pconfig(local_user(), 'mailstream', 'address');
    $address_mu = $address ? (' value="' . $address . '"') : '';
    $template = file_get_contents(dirname(__file__).'/settings.tpl');
    $s .= replace_macros($template, array('$address' => $address_mu,
                                          '$enabled' => $enabled_mu));
}

function mailstream_plugin_settings_post($a,$post) {
    if ($_POST['address'] != "") {
        set_pconfig(local_user(), 'mailstream', 'address', $_POST['address']);
    }
    if ($_POST['enabled']) {
        set_pconfig(local_user(), 'mailstream', 'enabled', $_POST['enabled']);
    }
    else {
        del_pconfig(local_user(), 'mailstream', 'enabled');
    }
}

function mailstream_tidy() {
    $r = q("SELECT id FROM mailstream_item WHERE completed > '0000-00-00 00:00:00' AND completed < DATE_SUB(NOW(), INTERVAL 1 YEAR)");
    foreach ($r as $rr) {
        q('DELETE FROM mailstream_item WHERE id = %d', intval($rr['id']));
    }
    logger('mailstream_tidy: deleted ' . count($r) . ' old items', LOGGER_DEBUG);
}
