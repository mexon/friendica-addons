<?php

if(! function_exists("string_plural_select_pl")) {
function string_plural_select_pl($n){
	return ($n==1 ? 0 : ($n%10>=2 && $n%10<=4) && ($n%100<12 || $n%100>14) ? 1 : $n!=1 && ($n%10>=0 && $n%10<=1) || ($n%10>=5 && $n%10<=9) || ($n%100>=12 && $n%100<=14) ? 2 : 3);;
}}
;
$a->strings[":-)"] = ":-)";
$a->strings[":-("] = ":-(";
$a->strings["lol"] = "lol";
$a->strings["Quick Comment Settings"] = "Ustawienia szybkiego komentowania";
$a->strings["Quick comments are found near comment boxes, sometimes hidden. Click them to provide simple replies."] = "Szybkie komentarze znajdują się w pobliżu pól komentarza, czasami są ukryte. Kliknij je, aby zapewnić proste odpowiedzi.";
$a->strings["Enter quick comments, one per line"] = "";
$a->strings["Submit"] = "Wyślij";
$a->strings["Quick Comment settings saved."] = "Zapisano szybkie ustawienia komentarzy.";
