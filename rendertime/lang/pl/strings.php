<?php

if(! function_exists("string_plural_select_pl")) {
function string_plural_select_pl($n){
	return ($n==1 ? 0 : ($n%10>=2 && $n%10<=4) && ($n%100<12 || $n%100>14) ? 1 : $n!=1 && ($n%10>=0 && $n%10<=1) || ($n%10>=5 && $n%10<=9) || ($n%100>=12 && $n%100<=14) ? 2 : 3);;
}}
;
$a->strings["Performance: Database: %s, Network: %s, Rendering: %s, Parser: %s, I/O: %s, Other: %s, Total: %s"] = "Wydajność: Baza danych: %s, Sieć: %s, Renderowanie: %s, Analizator składni: %s, I/O: %s, Inne: %s, Razem: %s";
