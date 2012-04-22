<?php
/**
 * Name: Publicise Feed
 * Description: Convert a feed contact to a soapbox account so it can be easily added from the directory
 * Version: 0.1
 * Author: Matthew Exon <http://mat.exon.name>
 */

function publicise_install() {
    register_hook('post_remote', 'addon/publicise/publicise.php', 'publicise_post_remote_hook');
}

function publicise_uninstall() {
    unregister_hook('post_remote', 'addon/publicise/publicise.php', 'publicise_post_remote_hook');
}

function publicise_plugin_admin(&$a,&$o) {

    $query = <<<EOF
select id, name, micro
 from contact
 where contact.uid = %d
 and contact.network = 'feed'
 order by contact.id
 limit 100
EOF;
    $r = q($query, local_user());
    $settings = array();
    $template = file_get_contents(dirname(__file__).'/admin.tpl');
    $o .= replace_macros($template, array('$feeds' => $r, '$submit' => t('Publicise')));
}

function make_string_field($in) {
    return "'" . dbesc($in) . "'";
}

function make_int_field($in) {
    return $in ? $in : 0;
}

function publicise($a, $contact, $owner) {

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

    $newuser = array(
        'guid' => make_string_field(generate_user_guid()),
        'username' => make_string_field($contact['name']),
        'password' => make_string_field($owner['password']),
        'nickname' => make_string_field($contact['nick']),
        'email' => make_string_field($owner['email']),
        'openid' => make_string_field($owner['openid']),
        'timezone' => make_string_field($owner['timezone']),
        'language' => make_string_field($owner['language']),
        'register_date' => make_string_field(datetime_convert()),
        'default-location' => make_string_field($owner['default-location']),
        'allow_location' => make_string_field($owner['allow_location']),
        'theme' => make_string_field($owner['theme']),
        'pubkey' => make_string_field($pubkey),
        'prvkey' => make_string_field($prvkey),
        'spubkey' => make_string_field($spubkey),
        'sprvkey' => make_string_field($sprvkey),
        'verified' => make_int_field($owner['verified']),
        'blocked' => make_int_field(0),
        'blockwall' => make_int_field(1),
        'hidewall' => make_int_field(1),
        'blocktags' => make_int_field(0),
        'notify-flags' => make_int_field($owner['notifyflags']),
        'page-flags' => make_int_field(PAGE_SOAPBOX),
        );
    logger('Publicise: created user ' . print_r($newuser, true), LOGGER_DATA);
    $r = q("INSERT INTO `user` (`" 
			. implode("`, `", array_keys($newuser))
			. "`) VALUES (" 
			. implode(", ", array_values($newuser)) 
			. ")" );
    if (!$r) {
        logger('Publicise: create user failed', LOGGER_ERROR);
        return;
    }
    $r = q("SELECT uid FROM user ORDER BY uid DESC LIMIT 1");
    $newuid = $r[0]['uid'];
    $newcontact = array(
        'uid' => $newuid,
        'created' => make_string_field(datetime_convert()),
        'self' => make_int_field(1),
        'name' => make_string_field($contact['name']),
        'nick' => make_string_field($contact['nick']),
        'photo' => make_string_field($contact['photo']),
        'thumb' => make_string_field($contact['thumb']),
        'micro' => make_string_field($contact['micro']),
        'blocked' => make_int_field(0),
        'pending' => make_int_field(0),
        'url' => make_string_field($a->get_baseurl() . '/profile/' . $contact['nick']),
        'nurl' => make_string_field($a->get_baseurl() . '/profile/' . $contact['nick']),
        'request' => make_string_field($a->get_baseurl() . '/dfrn_request/' . $contact['nick']),
        'notify' => make_string_field($a->get_baseurl() . '/dfrn_notify/' . $contact['nick']),
        'poll' => make_string_field($a->get_baseurl() . '/dfrn_poll/' . $contact['nick']),
        'confirm' => make_string_field($a->get_baseurl() . '/dfrn_confirm/' . $contact['nick']),
        'poco' => make_string_field($a->get_baseurl() . '/poco/' . $contact['nick']),
        'uri-date' => make_string_field(datetime_convert()),
        'avatar-date' => make_string_field(datetime_convert()),
        'closeness' => make_int_field(0),
        );
    logger('Publicise: create contact ' . print_r($newcontact, true), LOGGER_DATA);
    $r = q("INSERT INTO `contact` (`"
			. implode("`, `", array_keys($newcontact))
			. "`) VALUES ("
			. implode(", ", array_values($newcontact))
			. ")" );
    if (!$r) {
        logger('Publicise: create contact failed', LOGGER_ERROR);
        $r = q("DELETE FROM user WHERE uid = %d", $newuid);
        logger('Publicise: deleted failed user ' . $newuid, LOGGER_DATA);
        return;
    }
    $r = q("SELECT id FROM contact ORDER BY id DESC LIMIT 1");
    $newcontactid = $r[0]['id'];
    $newprofile = array(
        'uid' => $newuid,
        'profile-name' => make_string_field('default'),
        'is-default' => make_int_field(1),
        'name' => make_string_field($contact['name']),
        'photo' => make_string_field($contact['photo']),
        'thumb' => make_string_field($contact['thumb']),
        'homepage' => make_string_field($contact['url']),
        'publish' => make_int_field(1),
        'net-publish' => make_int_field(1),
        );
    logger('Publicise: create profile ' . print_r($newprofile, true), LOGGER_DATA);
    $r = q("INSERT INTO `profile` (`" 
			. implode("`, `", array_keys($newprofile)) 
			. "`) VALUES (" 
			. implode(", ", array_values($newprofile))
			. ")" );
    if (!$r) {
        logger('Publicise: create profile failed', LOGGER_ERROR);
    }
    $r = q("UPDATE `contact` SET `uid` = %d, `reason` = 'publicise' WHERE id = %d", $newuid, $contact['id']);
    if (!$r) {
        logger('Publicise: update contact failed', LOGGER_ERROR);
    }
    $r = q("UPDATE `item` SET `uid` = %d, `contact-id` = %d, type = 'wall', wall = 1 WHERE `contact-id` = %d",
           $newuid, $newcontactid, $contact['id']);
    logger('Publicise: moved items from contact ' . $contact['id'] . ' to ' . $newcontact, LOGGER_DATA);
    $r = q("UPDATE `pconfig` SET `uid` = %d WHERE `uid` = %d AND `cat` = 'retriever%d'",
           $newuid, $contact['uid'], $contact['id']);
    logger('Publicise: Updated retriever config from uid ' . $contact['uid'] . ' to ' . $newuid, LOGGER_DATA);
}

//function publicise_plugin_admin_post ($a,$post) {
function publicise_plugin_admin_post ($a) {
    $r = q("select * from user where uid = %d", local_user());
    foreach ($r[0] as $k=>$v) {
        $user[$k] = $v;
    }
    $r = q("select * from contact where contact.uid = %d and contact.network = 'feed'", local_user());
    foreach ($r as $rr) {
        if ($_POST['publicise' . $rr['id']]) {
            publicise($a, $rr, $user);
        }
    }
}

function publicise_post_remote_hook(&$a, &$item) {
    $r1 = q("select uid from contact where id = %d and reason = 'publicise'", $item['contact-id']);
    if (!$r1) {
        return;
    }
    $r2 = q("select id from contact where uid = %d and self = 1", $r1[0]['uid']);
    if (!$r2) {
        logger('Publicise: user has no "self" contact: ' . $r1[0]['uid']);
        return;
    }

    logger('Publicise: moving to wall: ' . $item['plink']);
    $item['contact-id'] = $r2[0]['id'];
    $item['type'] = 'wall';
    $item['wall'] = 1;
}
