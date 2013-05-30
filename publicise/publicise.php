<?php
/**
 * Name: Publicise Feeds
 * Description: Convert your feeds into soapbox accounts so you can share them with other users
 * Version: 0.1
 * Author: Matthew Exon <http://mat.exon.name>
 */

function publicise_install() {
    register_hook('post_remote', 'addon/publicise/publicise.php', 'publicise_post_remote_hook');
}

function publicise_uninstall() {
    unregister_hook('post_remote', 'addon/publicise/publicise.php', 'publicise_post_remote_hook');
    unregister_hook('post_remote_end', 'addon/publicise/publicise.php', 'publicise_post_remote_end_hook');
}

function publicise_get_contacts() {
    $query = <<<EOF
SELECT *
 FROM `contact`
 WHERE (`contact`.`uid` = %d and `contact`.`network` = 'feed')
 OR (`reason` = 'publicise')
 ORDER BY `contact`.`id`
EOF;
    return q($query, intval(local_user()));
}

function publicise_get_user($uid) {
    $r = q('SELECT * FROM `user` WHERE `uid` = %d', intval($uid));
    if (count($r) != 1) {
        logger('Publicise: unexpected number of results for uid ' . $uid, LOGGER_NORMAL);
    }
    return $r[0];
}

function publicise_plugin_admin(&$a,&$o) {
    if (!is_site_admin()) {
        $o .= "<p>This page is for site administrators only</p>";
        return;
    }

    $contacts = publicise_get_contacts();
    foreach ($contacts as $k=>$v) {
        $enabled = ($v['reason'] === 'publicise') ? 1 : NULL;
        $expire = 30;
        $comments = 1;
        $url = $v['url'];
        if ($enabled) {
            $r = q('SELECT `expire`, `page-flags` FROM `user` WHERE `uid` = %d', intval($v['uid']));
            $expire = $r[0]['expire'];
            $url = $a->get_baseurl() . '/profile/' . $v['nick'];
            if ($r[0]['page-flags'] == PAGE_SOAPBOX) {
                $comments = NULL;
            }
        }
        $contacts[$k]['enabled'] = array('publicise-enabled-' . $v['id'], NULL, $enabled);
        $contacts[$k]['comments'] = array('publicise-comments-' . $v['id'], NULL, $comments);
        $contacts[$k]['expire'] = $expire;
        $contacts[$k]['url'] = $url;
    }
    $template = get_markup_template('admin.tpl', 'addon/publicise/');
    $o .= replace_macros($template, array(
                             '$feeds' => $contacts,
                             '$feed_t' => t('Feed'),
                             '$publicised_t' => t('Publicised'),
                             '$comments_t' => t('Allow Comments/Likes'),
                             '$expire_t' => t('Expire Articles After (Days)'),
                             '$submit_t' => t('Submit')));
}

function publicise_make_string($in) {
    return "'" . dbesc($in) . "'";
}

function publicise_make_int($in) {
    return intval($in) ? $in : 0;
}

function publicise_create_user($owner, $contact) {

    $nick = $contact['nick'];
    if (!$nick) {
        logger("Publicise: can't convert contact " .
               $contact['id'] . ' ' . $contact['name'] .
               ' because there is no nick', LOGGER_NORMAL);
        return;
    }
    logger('Publicise: create user, beginning key generation...', LOGGER_DATA);
    $res=openssl_pkey_new(array(
        'digest_alg' => 'sha1',
        'private_key_bits' => 4096,
        'encrypt_key' => false ));
    $prvkey = '';
    openssl_pkey_export($res, $prvkey);
    $pkey = openssl_pkey_get_details($res);
    $pubkey = $pkey["key"];
    $sres=openssl_pkey_new(array(
        'digest_alg' => 'sha1',
        'private_key_bits' => 512,
        'encrypt_key' => false ));
    $sprvkey = '';
    openssl_pkey_export($sres, $sprvkey);
    $spkey = openssl_pkey_get_details($sres);
    $spubkey = $spkey["key"];
    $guid = generate_user_guid();

    $newuser = array(
        'guid' => publicise_make_string($guid),
        'username' => publicise_make_string($contact['name']),
        'password' => publicise_make_string($owner['password']),
        'nickname' => publicise_make_string($contact['nick']),
        'email' => publicise_make_string($owner['email']),
        'openid' => publicise_make_string($owner['openid']),
        'timezone' => publicise_make_string($owner['timezone']),
        'language' => publicise_make_string($owner['language']),
        'register_date' => publicise_make_string(datetime_convert()),
        'default-location' => publicise_make_string($owner['default-location']),
        'allow_location' => publicise_make_string($owner['allow_location']),
        'theme' => publicise_make_string($owner['theme']),
        'pubkey' => publicise_make_string($pubkey),
        'prvkey' => publicise_make_string($prvkey),
        'spubkey' => publicise_make_string($spubkey),
        'sprvkey' => publicise_make_string($sprvkey),
        'verified' => publicise_make_int($owner['verified']),
        'blocked' => publicise_make_int(0),
        'blockwall' => publicise_make_int(1),
        'hidewall' => publicise_make_int(0),
        'blocktags' => publicise_make_int(0),
        'notify-flags' => publicise_make_int($owner['notifyflags']),
        'page-flags' => publicise_make_int($comments ? PAGE_COMMUNITY : PAGE_SOAPBOX),
        'expire' => publicise_make_int($expire),
        );
    logger('Publicise: created user ' . print_r($newuser, true), LOGGER_DATA);
    $r = q("INSERT INTO `user` (`" 
			. implode("`, `", array_keys($newuser))
			. "`) VALUES (" 
			. implode(", ", array_values($newuser)) 
			. ")" );
    if (!$r) {
        logger('Publicise: create user failed', LOGGER_NORMAL);
        return;
    }
    $r = q('SELECT * FROM `user` WHERE `guid` = "%s"', dbesc($guid));
    if (count($r) != 1) {
        logger('Publicise: unexpected number of uids returned', LOGGER_NORMAL);
        return;
    }
    return $r[0];
}

function publicise_create_self_contact($a, $contact, $uid) {
    $newcontact = array(
        'uid' => $uid,
        'created' => publicise_make_string(datetime_convert()),
        'self' => publicise_make_int(1),
        'name' => publicise_make_string($contact['name']),
        'nick' => publicise_make_string($contact['nick']),
        'photo' => publicise_make_string($contact['photo']),
        'thumb' => publicise_make_string($contact['thumb']),
        'micro' => publicise_make_string($contact['micro']),
        'blocked' => publicise_make_int(0),
        'pending' => publicise_make_int(0),
        'url' => publicise_make_string($a->get_baseurl() . '/profile/' . $contact['nick']),
        'nurl' => publicise_make_string($a->get_baseurl() . '/profile/' . $contact['nick']),
        'request' => publicise_make_string($a->get_baseurl() . '/dfrn_request/' . $contact['nick']),
        'notify' => publicise_make_string($a->get_baseurl() . '/dfrn_notify/' . $contact['nick']),
        'poll' => publicise_make_string($a->get_baseurl() . '/dfrn_poll/' . $contact['nick']),
        'confirm' => publicise_make_string($a->get_baseurl() . '/dfrn_confirm/' . $contact['nick']),
        'poco' => publicise_make_string($a->get_baseurl() . '/poco/' . $contact['nick']),
        'uri-date' => publicise_make_string(datetime_convert()),
        'avatar-date' => publicise_make_string(datetime_convert()),
        'closeness' => publicise_make_int(0),
        );
    logger('Publicise: create contact ' . print_r($newcontact, true), LOGGER_DATA);
    q("INSERT INTO `contact` (`"
      . implode("`, `", array_keys($newcontact))
      . "`) VALUES ("
      . implode(", ", array_values($newcontact))
      . ")" );
    $newcontact = q("SELECT `id` FROM `contact` WHERE `uid` = %d AND `self` = 1", intval($uid));
    if (count($newcontact) != 1) {
        logger('Publicise: create contact failed', LOGGER_NORMAL);
        $r = q("DELETE FROM user WHERE uid = %d", intval($uid));
        logger('Publicise: deleted failed user ' . $uid, LOGGER_DATA);
        return;
    }
    return $newcontact[0]['id'];
}

function publicise_create_profile($contact, $uid) {
    $newprofile = array(
        'uid' => $uid,
        'profile-name' => publicise_make_string('default'),
        'is-default' => publicise_make_int(1),
        'name' => publicise_make_string($contact['name']),
        'photo' => publicise_make_string($contact['photo']),
        'thumb' => publicise_make_string($contact['thumb']),
        'homepage' => publicise_make_string($contact['url']),
        'publish' => publicise_make_int(1),
        'net-publish' => publicise_make_int(1),
        );
    logger('Publicise: create profile ' . print_r($newprofile, true), LOGGER_DATA);
    $r = q("INSERT INTO `profile` (`" 
			. implode("`, `", array_keys($newprofile)) 
			. "`) VALUES (" 
			. implode(", ", array_values($newprofile))
			. ")" );
    if (!$r) {
        logger('Publicise: create profile failed', LOGGER_NORMAL);
    }
    $newprofile = q('SELECT `id` FROM `profile` WHERE `uid` = %d AND `is-default` = 1', intval($uid));
    if (count($newprofile) != 1) {
        logger('Publicise: create profile produced unexpected number of results', LOGGER_NORMAL);
        return;
    }
    return $newprofile[0]['id'];
}

function publicise_set_up_user($a, $contact, $owner) {
    $user = publicise_create_user($owner, $contact);
    if (!$user) {
        return;
    }
    $self_contact = publicise_create_self_contact($a, $contact, $user['uid']);
    if (!$self_contact) {
        logger("Publicise: unable to create self contact, deleting user " . $user['uid'], LOGGER_NORMAL);
        q('DELETE FROM `user` WHERE `uid` = %d', intval($user['uid']));
        return;
    }
    $profile = publicise_create_profile($contact, $user['uid']);
    if (!$profile) {
        logger("Publicise: unable to create profile, deleting user $uid contact $self_contact", LOGGER_NORMAL);
        q('DELETE FROM `user` WHERE `uid` = %d', intval($user['uid']));
        q('DELETE FROM `contact` WHERE `id` = %d', intval($self_contact));
        return;
    }
    return $user;
}

function publicise($a, &$contact, &$owner) {
    if (!is_site_admin()) {
        logger('Publicise: non-admin tried to publicise', LOGGER_NORMAL);
        return;
    }

    // Check if we're changing our mind about a feed we earlier depublicised
    $existing = q('SELECT * FROM `user` WHERE `account_expires_on` != "0000-00-00 00:00:00" AND `nickname` = "%s" AND `email` = "%s" AND `page-flags` in (%d, %d)',
                  dbesc($contact['nick']), dbesc($owner['email']), intval(PAGE_COMMUNITY), intval(PAGE_SOAPBOX));
    if (count($existing) == 1) {
        $owner = $existing[0];
        q('UPDATE `user` SET `account_expires_on` = "0000-00-00 00:00:00", `account_removed` = 0, `account_expired` = 0 WHERE `uid` = %d', intval($owner['uid']));
        q('UPDATE `profile` SET `publish` = 1, `net-publish` = 1 WHERE `uid` = %d AND `is-default` = 1', intval($owner['uid']));
        logger('Publicise: recycled previous user ' . $owner['uid'], LOGGER_DATA);
    }
    else {
        $owner = publicise_set_up_user($a, $contact, $owner);
        if (!$owner) {
            return;
        }
        logger("Publicise: created new user " . $owner['uid'], LOGGER_DATA);
    }
    logger('Publicise: new contact user is ' . $owner['uid']);

    $r = q("UPDATE `contact` SET `uid` = %d, `reason` = 'publicise', `hidden` = 1 WHERE id = %d", intval($owner['uid']), intval($contact['id']));
    if (!$r) {
        logger('Publicise: update contact failed, user is probably in a bad state ' . $user['uid'], LOGGER_NORMAL);
    }
    $contact['uid'] = $owner['uid'];
    $contact['reason'] = 'publicise';
    $contact['hidden'] = 1;
    $r = q("UPDATE `item` SET `uid` = %d, type = 'wall', wall = 1, private = 0 WHERE `contact-id` = %d",
           intval($owner['uid']), intval($contact['id']));
    logger('Publicise: moved items from contact ' . $contact['id'] . ' to uid ' . $owner['uid'], LOGGER_DEBUG);

    // Update the retriever config
    $r = q("UPDATE `retriever_rule` SET `uid` = %d WHERE `contact-id` = %d",
           intval($owner['uid']), intval($contact['id']));

    return true;
}

function depublicise($a, $contact, $user) {
    require_once('include/Contact.php');

    if (!is_site_admin()) {
        logger('Publicise: non-admin tried to depublicise', LOGGER_NORMAL);
        return;
    }

    logger('Publicise: about to depublicise contact ' . $contact['id'] . ' user ' . $user['uid'], LOGGER_DATA);

    $r = q('SELECT * FROM `contact` WHERE `uid` = %d AND `self` = 1', intval($user['uid']));
    if (count($r) != 1) {
        logger('Publicise: unexpected number of self contacts for user ' . $user['uid'], LOGGER_NORMAL);
        return;
    }
    $self_contact = $r[0];

    // If the local_user() is subscribed to the feed, take ownership
    // of the feed and all its items and photos.  Otherwise they will
    // be deleted when the account expires.
    $r = q('SELECT * FROM `contact` WHERE `uid` = %d AND `url` = "%s"',
           intval(local_user()), dbesc($self_contact['url']));
    if (count($r)) {
        // Delete the contact to the feed user and any
        // copies of its items.  These will be replaced by the originals,
        // which will be brought back into the local_user's feed along
        // with the feed contact itself.
        foreach ($r as $my_contact) {
            q('DELETE FROM `item` WHERE `contact-id` = %d', intval($my_contact['id']));
            q('DELETE FROM `contact` WHERE `id` = %d', intval($my_contact['id']));
        }

        // Move the feed contact to local_user.  Existing items stay
        // attached to the original feed contact, but must have their uid
        // updated.  Also update the fields we scribbled over in
        // publicise_post_remote_hook.
        q('UPDATE `contact` SET `uid` = %d, `reason` = "", hidden = 0 WHERE id = %d',
          intval(local_user()), intval($contact['id']));
        q('UPDATE `item` SET `uid` = %d, `wall` = 0, `type` = "remote", `private` = 2 WHERE `contact-id` = %d',
          intval(local_user()), intval($contact['id']));

        // Take ownership of any photos created by the feed user
        q('UPDATE `photo` SET `uid` = %d WHERE `uid` = %d',
          intval(local_user()), intval($user['uid']));

        // Update the retriever config
        $r = q("UPDATE `retriever_rule` SET `uid` = %d WHERE `contact-id` = %d",
               intval($owner['uid']), intval($contact['id']));
    }

    q('UPDATE `user` SET `account_expires_on` = UTC_TIMESTAMP() + INTERVAL 1 DAY WHERE `uid` = %d',
      intval($user['uid']));
    q('UPDATE `profile` SET `publish` = 0, `net-publish` = 0 WHERE `uid` = %d AND `is-default` = 1', intval($user['uid']));
}

function publicise_plugin_admin_post ($a) {
    if (!is_site_admin()) {
        logger('Publicise: non-admin tried to do admin post', LOGGER_NORMAL);
        return;
    }

    foreach (publicise_get_contacts() as $contact) {
        $user = publicise_get_user($contact['uid']);
        if (!$_POST['publicise-enabled-' . $contact['id']]) {
            if ($contact['reason'] === 'publicise') {
                depublicise($a, $contact, $user);
            }
        }
        else {
            if ($contact['reason'] !== 'publicise') {
                if (!publicise($a, $contact, $user)) {
                    logger('Publicise: failed to publicise contact ' . $contact['id']);
                    continue;
                }
            }
            if ($_POST['publicise-expire-' . $contact['id']] != $user['expire']) {
                q('UPDATE `user` SET `expire` = %d WHERE `uid` = %d',
                  intval($_POST['publicise-expire-' . $contact['id']]), intval($user['uid']));
            }
            if ($_POST['publicise-comments-' . $contact['id']]) {
                if ($user['page-flags'] != PAGE_COMMUNITY) {
                    q('UPDATE `user` SET `page-flags` = %d WHERE `uid` = %d',
                      intval(PAGE_COMMUNITY), intval($user['uid']));
                }
            }
            else {
                if ($user['page-flags'] != PAGE_SOAPBOX) {
                    q('UPDATE `user` SET `page-flags` = %d WHERE `uid` = %d',
                      intval(PAGE_SOAPBOX), intval($user['uid']));
                }
            }
        }
    }
}

function publicise_post_remote_hook(&$a, &$item) {
    $r1 = q("SELECT `uid` FROM `contact` WHERE `id` = %d AND `reason` = 'publicise'", intval($item['contact-id']));
    if (!$r1) {
        return;
    }

    logger('Publicise: moving to wall: ' . $item['uid'] . ' ' . $item['contact-id'] . ' ' . $item['uri'], LOGGER_DEBUG);
    $item['type'] = 'wall';
    $item['wall'] = 1;
    $item['private'] = 0;
}
