<?php

global $mdb;

if ($killID > 0 && strlen($hash) == 40) {
    if (!$mdb->exists('crestmails', ['killID' => (int) $killID, 'hash' => $hash])) {
        $userID = User::getUserID();
        $name = $userID > 0 ? Info::getInfoField('characterID', (int) $userID, 'name') : "?anonymous?";
        Log::log("  1 kills added by $name (POST)");
        ZLog::add("  1 kills added by $name (POST)", $userID);
        $mdb->insertUpdate('crestmails', ['killID' => (int) $killID, 'hash' => $hash], ['processed' => false]);
    }
}
