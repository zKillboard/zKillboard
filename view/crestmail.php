<?php

global $mdb;

if ($killID > 0 && strlen($hash) == 40) {
    if (!$mdb->exists('crestmails', ['killID' => (int) $killID, 'hash' => $hash])) {
        Log::log("New CRESTmail $killID");
        $mdb->insertUpdate('crestmails', ['killID' => (int) $killID, 'hash' => $hash], ['processed' => false]);
    }
}
