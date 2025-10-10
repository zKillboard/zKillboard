<?php

global $mdb;

$URLBASE = "https://zkillboard.com/asearch/";

try {
    $url = rawurldecode((string) @$_GET['url']);
    $record = $mdb->findDoc("shortener", ['url' => $url]);
    if ($record == null) {
        if (substr($url, 0, strlen($URLBASE)) != $URLBASE) throw new Exception("invalid domain: $url");

        $mdb->insert("shortener", ['url' => $url]);
        $record = $mdb->findDoc("shortener", ['url' => $url]);
    }
    $id = (string) $record['_id'];
    echo "https://zkillboard.com/asearchsaved/$id/";
} catch (Exception $ex) {
    echo $ex->getMessage();
}
