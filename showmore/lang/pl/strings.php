<?php

if(! function_exists("string_plural_select_pl")) {
function string_plural_select_pl($n){
	return ($n==1 ? 0 : ($n%10>=2 && $n%10<=4) && ($n%100<12 || $n%100>14) ? 1 : $n!=1 && ($n%10>=0 && $n%10<=1) || ($n%10>=5 && $n%10<=9) || ($n%100>=12 && $n%100<=14) ? 2 : 3);;
}}
;
$a->strings["\"Show more\" Settings"] = "\"Pokaż więcej\" ustawień";
$a->strings["Enable Show More"] = "Włącz Pokaż więcej";
$a->strings["Cutting posts after how much characters"] = "Cięcie postów po ilości znaków";
$a->strings["Save Settings"] = "Zapisz ustawienia";
$a->strings["Show More Settings saved."] = "Pokaż więcej zapisanych ustawień.";
$a->strings["show more"] = "Pokaż więcej";
