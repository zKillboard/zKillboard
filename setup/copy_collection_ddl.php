<?php

require_once "../init.php";

$from = @$argv[1];
$to = @$argv[2];

if ($from == "" || $to == "") {
    echo "Usage: " . $argv[0] . " from to\n";
    exit(1);
}

$cFrom = $mdb->getCollection($from);
$cTo = $mdb->getCollection($to);
foreach ($cFrom->listIndexes() as $index) {
    print_r($index);
    $keys = (array)$index['key'];
    $options = (array)$index;
    unset($options['key']);
    unset($options['ns']);
    unset($options['name']);
    unset($options['v']);
    print_r($cTo->createIndex($keys, $options));
}
