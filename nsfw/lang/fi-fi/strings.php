<?php

if(! function_exists("string_plural_select_fi_fi")) {
function string_plural_select_fi_fi($n){
	$n = intval($n);
	return ($n != 1);;
}}
;
$a->strings["Content Filter (NSFW and more)"] = "Sisällönsuodatin (NSFW yms.)";
$a->strings["This addon searches for specified words/text in posts and collapses them. It can be used to filter content tagged with for instance #NSFW that may be deemed inappropriate at certain times or places, such as being at work. It is also useful for hiding irrelevant or annoying content from direct view."] = "";
$a->strings["Enable Content filter"] = "Ota sisällönsuodatin käyttöön";
$a->strings["Comma separated list of keywords to hide"] = "";
$a->strings["Save Settings"] = "Tallenna asetukset";
$a->strings["Use /expression/ to provide regular expressions"] = "";
$a->strings["NSFW Settings saved."] = "NSFW-asetukset tallennettu.";
$a->strings["Filtered tag: %s"] = "Suodatettu tunniste: %s";
$a->strings["Filtered word: %s"] = "Suodatettu sana: %s";
