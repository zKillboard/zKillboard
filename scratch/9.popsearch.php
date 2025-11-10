<?php

require_once "../init.php";

//$filter = [ 'name' => [ '$type' => 'string' ], '$expr' => [ '$ne' => [ [ '$toLower' => '$name' ], [ '$toLower' => '$l_name' ] ] ] ];
//$update = [ [ '$set' => [ 'l_name' => [ '$toLower' => '$name' ] ] ] ];

$types = $mdb->getCollection("information")->distinct("type");
foreach ($types as $type) {
    iterate($mdb, $type, "l_name", false);
    if ($type == "corporationID" || $type == "allianceID") iterate($mdb, $type, "ticker", true);
}


function iterate($mdb, $type, $field, $doTicker = false) {
    $cursor = $mdb->getCollection("information")->find([$field => ['$type' => 'string'], 'type' => $type])->sort([$field => 1]);

    if ($doTicker) $type .= "-ticker";

    $matches = [];
    $now = $mdb->now();

    $dbdocs = [];

    $count = 0;
    $lastkeyc = 0;
    $matches = [];
    $lastletter = null;
    while ($cursor->hasNext()) {
        $count++;
        $doc = $cursor->next();

        $firstletter = mb_substr($doc[$field], 0, 1, 'UTF-8');
        if ($firstletter != $lastletter) {
            $dbdocs = []; // saves on memory
            $lastletter = $firstletter;
        }

        $doc[$field] = trim($doc[$field]);
        if ($doTicker) $doc[$field] = strtolower($doc[$field]);
        else if (strtolower($doc[$field]) != $doc[$field]) continue; // wtf, shouldn't be here

        $len = min(5, strlen($doc[$field]));
        $lastkey = null;
        $dbdoc = null;
        for ($i = 1; $i <= $len; $i++) {
            $thiskey = mb_substr($doc[$field], 0, $i, 'UTF-8');

            if ($thiskey !== $lastkey) {
                $lastkeyc = 0;
                $matches = [];
                $lastkey = $thiskey;
                $dbdoc = @$dbdocs[$thiskey];
                if ($dbdoc === true) continue;
                if ($dbdoc == null) $dbdoc = ['matches' => []];
                $matches = $dbdoc['matches'];
            }
            $lastkeyc++;

            $matches[] = ['id' => $doc['id'], $doc['name']];
            $dbdoc['matches'] = $matches;
            $dbdocs[$thiskey] = $dbdoc;
            if (sizeof($matches) == 10) {
                $mdb->insertUpdate("search", ['type' => $type, 'key' => $thiskey], ['matches' => $matches, 'dttm' => $now]);
                Util::out("$type $lastkey $lastkeyc ($count)");
                $dbdocs[$thiskey] = true;
            }
        }
    }
    Util::out("Processed $type $count records");
}
