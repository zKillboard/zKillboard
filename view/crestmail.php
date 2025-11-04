<?php

function handler($request, $response, $args, $container) {
    global $mdb;
    
    $killID = $args['killID'] ?? 0;
    $hash = $args['hash'] ?? '';

    if ($killID > 0 && strlen($hash) == 40) {
        if (!$mdb->exists('crestmails', ['killID' => $killID]) && !$mdb->exists('crestmails', ['killID' => (int) $killID, 'hash' => $hash])) {
            $userID = User::getUserID();
            $name = $userID > 0 ? Info::getInfoField('characterID', (int) $userID, 'name') : "?";

            try { 
                $mdb->insert('crestmails', ['killID' => (int) $killID, 'hash' => $hash, 'processed' => false, 'source' => 'crestmail.php', 'delay' => 0]);
                ZLog::add("  1 kills added by $name  $killID $hash (POST)", $userID, true);
            } catch (Exception $ex) {
                // ignore it
            }
            // update the delay to 0, manual posts always take priority
            $mdb->set('crestmails', ['killID' => $killID, 'hash' => $hash], ['delay' => 0]);
        }
    }
    
    return $response->withStatus(204);
}
