<?php

if(! function_exists("string_plural_select_fi_fi")) {
function string_plural_select_fi_fi($n){
	$n = intval($n);
	return ($n != 1);;
}}
;
$a->strings["Could NOT install Libravatar successfully.<br>It requires PHP >= 5.3"] = "Libravataria ei voitu asentaa.<br>Vaatii PHP-version >=5.3";
$a->strings["generic profile image"] = "Yleinen profiilikuva";
$a->strings["random geometric pattern"] = "satunnainen geometrinen kuvio";
$a->strings["monster face"] = "hirviö";
$a->strings["computer generated face"] = "tietokoneella tuotettut kasvot";
$a->strings["retro arcade style face"] = "retro-videopeli kasvot";
$a->strings["Warning"] = "Varoitus";
$a->strings["Your PHP version %s is lower than the required PHP >= 5.3."] = "PHP-versiosi on %s. Friendica vaatii PHP >= 5.3.";
$a->strings["This addon is not functional on your server."] = "Tämä lisäosa ei toimi palvelimellasi.";
$a->strings["Information"] = "Tietoja";
$a->strings["Gravatar addon is installed. Please disable the Gravatar addon.<br>The Libravatar addon will fall back to Gravatar if nothing was found at Libravatar."] = "";
$a->strings["Submit"] = "Lähetä";
$a->strings["Default avatar image"] = "Avatarin oletuskuva";
$a->strings["Select default avatar image if none was found. See README"] = "Valitse oletusavatarikuva jos avatari puuttuu. Katso lisätietoja README:stä.";
$a->strings["Libravatar settings updated."] = "Libravatar -asetukset päivitetty";
