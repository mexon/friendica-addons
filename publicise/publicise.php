<?php
/**
 * Name: Publicise Feed
 * Description: Convert a feed contact to a soapbox account so you can share it with other users
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
    return q($query, local_user());
}

function publicise_get_user($uid) {
    $r = q('SELECT * FROM `user` WHERE `uid` = %d', $uid);
    if (count($r) != 1) {
        logger('Publicise: unexpected number of results for uid ' . $uid);
    }
    return $r[0];
}

function publicise_plugin_admin(&$a,&$o) {
    logger('@@@ hello world 2');
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
            $r = q('SELECT `expire`, `page-flags` FROM `user` WHERE `uid` = %d', $v['uid']);
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
    $template = file_get_contents(dirname(__file__).'/admin.tpl');
    $o .= replace_macros($template, array(
                             '$feeds' => $contacts,
                             '$submit' => t('Submit')));
}

function publicise_make_string($in) {
    return "'" . dbesc($in) . "'";
}

function publicise_make_int($in) {
    return intval($in) ? $in : 0;
}

function publicise($a, $contact, $owner, $comments, $expire) {
    logger('@@@ Publicise: the contact is ' . print_r($contact, true) . ' owner ' . print_r($owner, true) . ' comments ' . $comments . ' expire ' . $expire);
    if (!is_site_admin()) {
        logger('Publicise: non-admin tried to publicise', LOGGER_NORMAL);
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
    logger('@@@ guid is ' . $newuser['guid']);
    $r = q('SELECT `uid` FROM `user` WHERE `guid` = "%s"', dbesc($guid));
    if (count($r) != 1) {
        logger('Publicise: unexpected number of uids returned', LOGGER_NORMAL);
        logger('@@@ ' . print_r($r, true));
        return;
    }
    $newuid = $r[0]['uid'];
    $newcontact = array(
        'uid' => $newuid,
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
    $newcontact = q("SELECT * FROM `contact` WHERE `uid` = %d AND `self` = 1", $newuid);
    if (count($newcontact) != 1) {
        logger('Publicise: create contact failed', LOGGER_NORMAL);
        $r = q("DELETE FROM user WHERE uid = %d", $newuid);
        logger('Publicise: deleted failed user ' . $newuid, LOGGER_DATA);
        return;
    }
    $newcontactid = $newcontact[0]['id'];
    $newprofile = array(
        'uid' => $newuid,
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
    $r = q("UPDATE `contact` SET `uid` = %d, `reason` = 'publicise', `hidden` = 1 WHERE id = %d", $newuid, $contact['id']);
    if (!$r) {
        logger('Publicise: update contact failed', LOGGER_NORMAL);
    }
    $r = q("UPDATE `item` SET `uid` = %d, type = 'wall', wall = 1, private = 0 WHERE `contact-id` = %d",
           $newuid, $contact['id']);
    logger('Publicise: moved items from contact ' . $contact['id'] . ' to uid ' . $newuid, LOGGER_DEBUG);
    $r = q("UPDATE `pconfig` SET `uid` = %d WHERE `uid` = %d AND `cat` = 'retriever%d'",
           $newuid, $contact['uid'], $contact['id']);
    logger('Publicise: Updated retriever config from uid ' . $contact['uid'] . ' to ' . $newuid, LOGGER_DEBUG);
    return $newcontact[0];
}

// This function takes a feed which was leading an independent life as
// a soapbox user and converts it into a private feed owned by the
// local_user().
function depublicise($a, $contact, $user) {
    require_once('include/Contact.php');

    if (!is_site_admin()) {
        logger('Publicise: non-admin tried to depublicise', LOGGER_NORMAL);
        return;
    }

    logger('@@@ the contact is ' . print_r($contact, true));
    logger('Publicise: about to depublicise contact ' . $contact['id'] . ' user ' . $user['uid']);

    $r = q('SELECT * FROM `contact` WHERE `uid` = %d AND `self` = 1', $user['uid']);
    if (count($r) != 1) {
        logger('Publicise: unexpected number of self contacts for user ' . $user['uid'], LOGGER_NORMAL);
        return;
    }
    $self_contact = $r[0];
    logger('@@@ got self contact ' . print_r($self_contact, true));

    // For the local_user, delete the contact to the feed user and any
    // copies of its items.  These will be replaced by the originals,
    // which will be brought back into the local_user's feed along
    // with the feed contact itself.
    $r = q('SELECT * FROM `contact` WHERE `uid` = %d AND `url` = "%s"',
           intval(local_user()), dbesc($self_contact['url']));
    logger('@@@ deleting from contacts uid ' . print_r($r, true));
    foreach ($r as $my_contact) {
        logger('@@@ delete from contacts where contact-id = ' . $my_contact['id']);
        $r = q('DELETE FROM `item` WHERE `contact-id` = %d', $my_contact['id']);
        logger('@@@ delete from item result ' . print_r($r, true));
        $r = q('DELETE FROM `contact` WHERE `id` = %d', $my_contact['id']);
        logger('@@@ delete from contact result ' . print_r($r, true));
    }

    // Move the feed contact to local_user.  Existing items stay
    // attached to the original feed contact, but must have their uid
    // updated.  Also update the fields we scribbled over in
    // publicise_post_remote_hook.
    logger('@@@ change contact ' . $contact['id'] . ' to uid ' . local_user());
    q('UPDATE `contact` SET `uid` = %d, `reason` = "" WHERE id = %d',
      intval(local_user()), intval($contact['id']));
    logger('@@@ change items of contact id ' . $contact['id'] . ' to uid ' . local_user());
    $r = q('UPDATE `item` SET `uid` = %d, `wall` = 0, `type` = "remote", `private` = 2 WHERE `contact-id` = %d',
      intval(local_user()), intval($contact['id']));
    //@@@ seriously, do something with the results
    logger('@@@ changing the items result was ' . print_r($r, true));

    // Take ownership of any photos created by the feed user
    $r = q('UPDATE `photo` SET `uid` = %d WHERE `uid` = %d',
      intval(local_user()), intval($user['uid']));
    logger('@@@ changing the photos result was ' . print_r($r, true));

    q('DELETE FROM `contact` WHERE `uid` = %d', intval($user['uid']));
    logger('@@@ deleted from contact result is ' . print_r($r, true));
    q('DELETE FROM `contact` WHERE `url` = "%s"', dbesc($self_contact['url']));

    user_remove($user['uid']);
}

function publicise_plugin_admin_post ($a) {
    logger('@@@ hello world?');
    if (!is_site_admin()) {
        logger('Publicise: non-admin tried to do admin post', LOGGER_NORMAL);
        return;
    }

    //@@@ check that the local_user() actually has rights to do this stuff!
    foreach (publicise_get_contacts() as $contact) {
        $user = publicise_get_user($contact['uid']);
        if (!$_POST['publicise-enabled-' . $contact['id']]) {
            if ($contact['reason'] === 'publicise') {
                logger('@@@ publicise_plugin_admin_post would depublicise ' . $contact['id']);
                depublicise($a, $contact, $user);
            }
        }
        else {
            if ($contact['reason'] !== 'publicise') {
                logger('@@@ publicise_plugin_admin_post would publicise ' . $contact['id']);
                $contact = publicise($a, $contact, $user,
                                     $_POST['publicise-comments-' . $contact['id']],
                                     $_POST['publicise-expire-' . $contact['id']]);
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
    $r1 = q("SELECT `uid` FROM `contact` WHERE `id` = %d AND `reason` = 'publicise'", $item['contact-id']);
    if (!$r1) {
        return;
    }

    logger('Publicise: moving to wall: ' . $item['plink'], LOGGER_DEBUG);
    $item['type'] = 'wall';
    $item['wall'] = 1;
    $item['private'] = 0;
}
