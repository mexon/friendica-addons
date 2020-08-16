<?php
/**
 * Name: Publicise Feeds
 * Description: Convert your feeds into soapbox accounts so you can share them with other users
 * Version: 1.0
 * Author: Matthew Exon <http://mat.exon.name>
 */

use Friendica\Core\Addon;
use Friendica\Core\Logger;
use Friendica\Core\Renderer;
use Friendica\Database\DBA;

function publicise_install() {
    Addon::registerHook('post_remote', 'addon/publicise/publicise.php', 'publicise_post_remote_hook');
}

function publicise_uninstall() {
    Addon::unregisterHook('post_remote', 'addon/publicise/publicise.php', 'publicise_post_remote_hook');
    Addon::unregisterHook('post_remote_end', 'addon/publicise/publicise.php', 'publicise_post_remote_end_hook');
}

function publicise_get_contacts() {
    $query = <<<EOF
SELECT *
 FROM `contact`
 WHERE (`contact`.`uid` = %d and `contact`.`network` = 'feed')
 OR (`reason` = 'publicise')
 ORDER BY `contact`.`name`
EOF;
    return q($query, intval(local_user()));
}

function publicise_get_user($uid) {
    $r = q('SELECT * FROM `user` WHERE `uid` = %d', intval($uid));
    if (count($r) != 1) {
        Logger::warning('Publicise: unexpected number of results for uid ' . $uid);
    }
    return $r[0];
}

function publicise_addon_admin(&$a,&$o) {
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
            $r = q('SELECT * FROM `user` WHERE `uid` = %d', intval($v['uid']));
            $expire = $r[0]['expire'];
            $url = $a->get_baseurl() . '/profile/' . $v['nick'];
            if ($r[0]['page-flags'] == PAGE_SOAPBOX) {
                $comments = NULL;
            }
            if ($r[0]['account_expired']) {
                $enabled = NULL;
            }
        }
        $contacts[$k]['enabled'] = array('publicise-enabled-' . $v['id'], NULL, $enabled);
        $contacts[$k]['comments'] = array('publicise-comments-' . $v['id'], NULL, $comments);
        $contacts[$k]['expire'] = $expire;
        $contacts[$k]['url'] = $url;
    }
    $template = Renderer::getMarkupTemplate('admin.tpl', 'addon/publicise/');
    $o .= Renderer::replaceMacros($template, array(
                             '$feeds' => $contacts,
                             '$feed_t' => DI::l10n()->t('Feed'),
                             '$publicised_t' => DI::l10n()->t('Publicised'),
                             '$comments_t' => DI::l10n()->t('Allow Comments/Likes'),
                             '$expire_t' => DI::l10n()->t('Expire Articles After (Days)'),
                             '$submit_t' => DI::l10n()->t('Submit')));
}

function publicise_make_string($in) {
    return "'" . DBA::escape($in) . "'";
}

function publicise_make_int($in) {
    return intval($in) ? $in : 0;
}

function publicise_create_user($owner, $contact) {

    $nick = $contact['nick'];
    if (!$nick) {
        notice(sprintf(t("Can't publicise feed \"%s\" because it doesn't have a nickname"), $contact['name']) . EOL);
        return;
    }
    Logger::info('Publicise: create user, beginning key generation...');
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
    Logger::debug('Publicise: creating user ' . print_r($newuser, true));
    $r = q("INSERT INTO `user` (`" 
			. implode("`, `", array_keys($newuser))
			. "`) VALUES (" 
			. implode(", ", array_values($newuser)) 
			. ")" );
    if (!$r) {
        Logger::warning('Publicise: create user failed');
        return;
    }
    $r = q('SELECT * FROM `user` WHERE `guid` = "%s"', DBA::escape($guid));
    if (count($r) != 1) {
        Logger::warning('Publicise: unexpected number of uids returned');
        return;
    }
    Logger::debug('Publicise: created user ID ' . $r[0]);
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
    $existing = q("SELECT * FROM `contact` WHERE `uid` = %d AND `self` = 1", intval($uid));
    if (count($existing)) {
        $newcontact = $existing[0];
        Logger::debug('Publicise: self contact already exists for user ' . $uid . ' id ' . $newcontact['id']);
    } else {
        Logger::debug('Publicise: create contact ' . print_r($newcontact, true));
        q("INSERT INTO `contact` (`"
          . implode("`, `", array_keys($newcontact))
          . "`) VALUES ("
          . implode(", ", array_values($newcontact))
          . ")" );
        $results = q("SELECT `id` FROM `contact` WHERE `uid` = %d AND `self` = 1", intval($uid));
        if (count($results) != 1) {
            Logger::warning('Publicise: create self contact failed, will delete uid ' . $uid);
            $r = q("DELETE FROM `user` WHERE `uid` = %d", intval($uid));
            return;
        }
        $newcontact = $results[0];
        Logger::debug('Publicise: created self contact for user ' . $uid . ' id ' . $newcontact['id']);
    }
    Logger::debug('Publicise: self contact for ' . $uid . ' nick ' . $contact['nick'] . ' is ' . $newcontact['id']);
    return $newcontact['id'];
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
    Logger::debug('Publicise: create profile ' . print_r($newprofile, true));
    $r = q("INSERT INTO `profile` (`" 
			. implode("`, `", array_keys($newprofile)) 
			. "`) VALUES (" 
			. implode(", ", array_values($newprofile))
			. ")" );
    if (!$r) {
        Logger::warning('Publicise: create profile failed');
    }
    $newprofile = q('SELECT `id` FROM `profile` WHERE `uid` = %d AND `is-default` = 1', intval($uid));
    if (count($newprofile) != 1) {
        Logger::warning('Publicise: create profile produced unexpected number of results');
        return;
    }
    Logger::debug('Publicise: created profile ' . $newprofile[0]['id']);
    return $newprofile[0]['id'];
}

function publicise_set_up_user($a, $contact, $owner) {
    $user = publicise_create_user($owner, $contact);
    if (!$user) {
        notice(sprintf(t("Failed to create user for feed \"%s\""), $contact['name']) . EOL);
        return;
    }
    $self_contact = publicise_create_self_contact($a, $contact, $user['uid']);
    if (!$self_contact) {
        notice(sprintf(t("Failed to create self contact for user \"%s\""), $contact['name']) . EOL);
        Logger::warning("Publicise: unable to create self contact, deleting user " . $user['uid']);
        q('DELETE FROM `user` WHERE `uid` = %d', intval($user['uid']));
        return;
    }
    $profile = publicise_create_profile($contact, $user['uid']);
    if (!$profile) {
        notice(sprintf(t("Failed to create profile for user \"%s\""), $contact['name']) . EOL);
        Logger::warning("Publicise: unable to create profile, deleting user $uid contact $self_contact");
        q('DELETE FROM `user` WHERE `uid` = %d', intval($user['uid']));
        q('DELETE FROM `contact` WHERE `id` = %d', intval($self_contact));
        return;
    }
    return $user;
}

function publicise($a, &$contact, &$owner) {
    Logger::info('@@@ Publicise: publicise');
    if (!is_site_admin()) {
        notice(t("Only admin users can publicise feeds"));
        Logger::warning('Publicise: non-admin tried to publicise');
        return;
    }

    // Check if we're changing our mind about a feed we earlier depublicised
    Logger::info('@@@ Publicise: ' . 'SELECT * FROM `user` WHERE `account_expires_on` != "0000-00-00 00:00:00" AND `nickname` = "' . $contact['nick'] . '" AND `email` = "' . $owner['email'] . '" AND `page-flags` in (' . intval(PAGE_COMMUNITY) . ', ' . intval(PAGE_SOAPBOX) . ')');
    $existing = q('SELECT * FROM `user` WHERE `account_expires_on` != "0000-00-00 00:00:00" AND `nickname` = "%s" AND `email` = "%s" AND `page-flags` in (%d, %d)',
                  DBA::escape($contact['nick']), DBA::escape($owner['email']), intval(PAGE_COMMUNITY), intval(PAGE_SOAPBOX));
    if (count($existing) == 1) {
        Logger::info('@@@ Publicise: there is existing');
        $owner = $existing[0];
        q('UPDATE `user` SET `account_expires_on` = "0000-00-00 00:00:00", `account_removed` = 0, `account_expired` = 0 WHERE `uid` = %d', intval($owner['uid']));
        q('UPDATE `profile` SET `publish` = 1, `net-publish` = 1 WHERE `uid` = %d AND `is-default` = 1', intval($owner['uid']));
        Logger::debug('Publicise: recycled previous user ' . $owner['uid']);
    }
    else {
        Logger::info('@@@ Publicise: there is not existing');
        $owner = publicise_set_up_user($a, $contact, $owner);
        if (!$owner) {
            return;
        }
        Logger::debug("Publicise: created new user " . $owner['uid']);
    }
    Logger::info('Publicise: new contact user is ' . $owner['uid']);

    $r = q("UPDATE `contact` SET `uid` = %d, `reason` = 'publicise', `hidden` = 1 WHERE id = %d", intval($owner['uid']), intval($contact['id']));
    if (!$r) {
        Logger::warning('Publicise: update contact failed, user is probably in a bad state ' . $user['uid']);
    }
    $contact['uid'] = $owner['uid'];
    $contact['reason'] = 'publicise';
    $contact['hidden'] = 1;
    $r = q("UPDATE `item` SET `uid` = %d, type = 'wall', wall = 1, private = 0 WHERE `contact-id` = %d",
           intval($owner['uid']), intval($contact['id']));
    Logger::debug('Publicise: moved items from contact ' . $contact['id'] . ' to uid ' . $owner['uid']);

    // Update the retriever config
    $r = q("UPDATE `retriever_rule` SET `uid` = %d WHERE `contact-id` = %d",
           intval($owner['uid']), intval($contact['id']));

    info(sprintf(t("Moved feed \"%s\" to dedicated account"), $contact['name']) . EOL);
    return true;
}

function publicise_self_contact($uid) {
    $r = q('SELECT * FROM `contact` WHERE `uid` = %d AND `self` = 1', intval($uid));
    if (count($r) != 1) {
        Logger::warning('Publicise: unexpected number of self contacts for user ' . $uid);
        return;
    }
    return $r[0];
}

function depublicise($a, $contact, $user) {
    require_once('include/Contact.php');

    if (!is_site_admin()) {
        notice("Only admin users can depublicise feeds");
        Logger::warning('Publicise: non-admin tried to depublicise');
        return;
    }

    Logger::debug('Publicise: about to depublicise contact ' . $contact['id'] . ' user ' . $user['uid']);

    $self_contact = publicise_self_contact($user['uid']);

    // If the local_user() is subscribed to the feed, take ownership
    // of the feed and all its items and photos.  Otherwise they will
    // be deleted when the account expires.
    $r = q('SELECT * FROM `contact` WHERE `uid` = %d AND `url` = "%s"',
           intval(local_user()), DBA::escape($self_contact['url']));
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

    // Set the account to removed and expired right now.  It will be cleaned up by cron after 3 days, giving a chance to change your mind
    q('UPDATE `user` SET `account_removed` = 1, `account_expired` = 1, `account_expires_on` = UTC_TIMESTAMP() WHERE `uid` = %d',
      intval($user['uid']));
    q('UPDATE `profile` SET `publish` = 0, `net-publish` = 0 WHERE `uid` = %d AND `is-default` = 1', intval($user['uid']));

    info(sprintf(t("Removed dedicated account for feed \"%s\""), $contact['name']) . EOL);
}

function publicise_addon_admin_post ($a) {
    Logger::info('@@@ publicise_addon_admin_post');
    if (!is_site_admin()) {
        Logger::warning('Publicise: non-admin tried to do admin post');
        return;
    }

    foreach (publicise_get_contacts() as $contact) {
        Logger::info('@@@ publicise_addon_admin_post contact ' . $contact['id'] . ' ' . $contact['name']);
        $user = publicise_get_user($contact['uid']);
        if (!$_POST['publicise-enabled-' . $contact['id']]) {
            if ($contact['reason'] === 'publicise') {
                Logger::info('@@@ depublicise');
                depublicise($a, $contact, $user);
            }
        }
        else {
            if ($contact['reason'] !== 'publicise') {
                Logger::info('@@@ publicise');
                if (!publicise($a, $contact, $user)) {
                    Logger::warning('Publicise: failed to publicise contact ' . $contact['id']);
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
                    q('UPDATE `contact` SET `rel` = %d WHERE `uid` = %d AND `network` = "dfrn"',
                      intval(CONTACT_IS_SHARING), intval($user['uid']));
                }
            }
            else {
                if ($user['page-flags'] != PAGE_SOAPBOX) {
                    q('UPDATE `user` SET `page-flags` = %d WHERE `uid` = %d',
                      intval(PAGE_SOAPBOX), intval($user['uid']));
                    q('UPDATE `contact` SET `rel` = %d WHERE `uid` = %d AND `network` = "dfrn"',
                      intval(CONTACT_IS_FOLLOWER), intval($user['uid']));
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

    Logger::debug('Publicise: moving to wall: ' . $item['uid'] . ' ' . $item['contact-id'] . ' ' . $item['uri']);
    $item['type'] = 'wall';
    $item['wall'] = 1;
    $item['private'] = 0;
}

