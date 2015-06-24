<?php

global $mdb;

if ($killID > 0 && strlen($hash) == 40) {
    if (!$mdb->exists('crestmails', ['killID' => (int) $killID, 'hash' => $hash])) {
        Log::log("New CRESTmail $killID (remote)");
    }
    if (!$mdb->exists('crestmails', ['killID' => (int) $killID, 'hash' => $hash])) {
        $mdb->insertUpdate('crestmails', ['killID' => (int) $killID, 'hash' => $hash], ['processed' => false]);
    }
}
