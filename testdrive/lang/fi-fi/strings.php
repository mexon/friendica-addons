<?php

if(! function_exists("string_plural_select_fi_fi")) {
function string_plural_select_fi_fi($n){
	$n = intval($n);
	return ($n != 1);;
}}
;
$a->strings["Administrator"] = "Ylläpitäjä";
$a->strings["Your account on %s will expire in a few days."] = "%s -tilisi vanhenee muutaman päivän kuluttua.";
$a->strings["Your Friendica test account is about to expire."] = "Koetilisi Friendicassa umpeutuu kohta.";
$a->strings["Hi %1\$s,\n\nYour test account on %2\$s will expire in less than five days. We hope you enjoyed this test drive and use this opportunity to find a permanent Friendica website for your integrated social communications. A list of public sites is available at %s/siteinfo - and for more information on setting up your own Friendica server please see the Friendica project website at http://friendica.com."] = "";
