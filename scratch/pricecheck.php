<?php

require_once "../init.php";

if (!isset($argv[1]) || !isset($argv[2])) die("use typeID yyyy-mm-dd as parameters...\n"); 

echo Price::getItemPrice($argv[1], $argv[2], true, true);
echo "\n";
