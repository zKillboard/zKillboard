<?php

require_once "../init.php";

$typeID = 45649;
$date = "2024-11-10";
$price = number_format(Price::getItemPrice($typeID, $date), 2);
$build = number_format(Build::getItemPrice($typeID, $date, true, true), 2);

echo "typeID: $typeID\nprice: $price\nbuild: $build\n";
