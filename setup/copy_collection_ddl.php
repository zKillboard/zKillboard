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
foreach ($cFrom->getIndexInfo() as $index) {
    print_r($index);
    $keys = $index['key'];
    unset($index['keys']);
    unset($index['ns']);
    unset($index['name']);
    unset($index['clustering']);
    $options = $index;
    print_r($cTo->ensureIndex($keys, $options));
}
