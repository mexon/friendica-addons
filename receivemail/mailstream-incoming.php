<?php

    register_hook('incoming_mail', 'addon/mailstream/mailstream.php', 'mailstream_incoming_mail');

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


?>