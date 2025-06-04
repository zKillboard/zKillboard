<?php

require_once "../init.php";

//$filter = [ 'name' => [ '$type' => 'string' ], '$expr' => [ '$ne' => [ [ '$toLower' => '$name' ], [ '$toLower' => '$l_name' ] ] ] ];
//$update = [ [ '$set' => [ 'l_name' => [ '$toLower' => '$name' ] ] ] ];

$types = $mdb->getCollection("information")->distinct("type");
foreach ($types as $type) {
    $cursor = $mdb->getCollection("information")->find(['l_name' => ['$type' => 'string'], 'type' => $type])->sort(['l_name' => 1]);

    $matches = [];
    $now = $mdb->now();

    $count = 0;
    $lastkeyc = 0;
    $lastkey = null;
    $matches = [];
    while ($cursor->hasNext()) {
        $count++;
        $doc = $cursor->next();

        $doc['l_name'] = trim($doc['l_name']);
        $len = min(5, strlen($doc['l_name']));

        $thiskey = substr($doc['l_name'], 0, $len);
        if ($thiskey !== $lastkey) {
            $lastkeyc = 0;
            $matches = [];
            $lastkey = $thiskey;
        }
        $lastkeyc++;

        $matches[] = ['id' => $doc['id'], $doc['name']];
        if (sizeof($matches) == 10) {
            $mdb->insertUpdate("search", ['type' => $type, 'key' => $lastkey], ['matches' => $matches, 'dttm' => $now]);
            Util::out("$type $lastkey $lastkeyc ($count)");
        }
    }
    Util::out("Processed $type $count records");
}
