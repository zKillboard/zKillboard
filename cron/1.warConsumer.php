<?php

require_once '../init.php';

$timer = new Timer();
$queueWars = new RedisTimeQueue('tqWars', 9600);

$minute = date('i');
if ($minute == 30) {
    $allWars = $mdb->getCollection('information')->find(['type' => 'warID'], ['id' => 1]);
    foreach ($allWars as $war) {
        if (@$war['finished'] == 1) {
            $queueWars->remove($war['id']);
        } else {
            $queueWars->add($war['id']);
        }
    }
}

$added = 0;
while ($timer->stop() < 58000) {
    sleep(1);
    $id = $queueWars->next();
    if ($id == null) {
        exit();
    }
    $warRow = $mdb->findDoc('information', ['type' => 'warID', 'id' => $id]);

    if (@$warRow['timeFinished'] != null) {
        $threeDays = date('Y-m-d', (time() - (86400 * 3)));

        $warFinished = substr($warRow['timeFinished'], 0, 10);
        if ($warFinished <= $threeDays) {
            $mdb->set('information', ['type' => 'warID', 'id' => $id], ['finished' => true]);
            continue;
        }
    }

    $href = "https://public-crest.eveonline.com/wars/$id/";
    $war = CrestTools::getJSON($href);

    if (!isset($warRow['agrShipsKilled']) || !isset($warRow['dfdShipsKilled'])) {
        continue;
    }

    $war['lastCrestUpdate'] = $mdb->now();
    $war['id'] = $id;
    $war['finished'] = false;
    $mdb->insertUpdate('information', ['type' => 'warID', 'id' => $id], $war);

    $prevKills = @$warRow['agrShipsKilled'] + @$warRow['dfdShipsKilled'];
    $currKills = $war['aggressor']['shipsKilled'] + $war['defender']['shipsKilled'];
    //echo "$id - $prevKills $currKills " . $warRow["lastCrestUpdate"] . "\n";

    // Don't fetch killmail api for wars with no kill count change
    if ($prevKills != $currKills) {
        $kmHref = $war['killmails'];
        $page = floor($mdb->count('warmails', ['warID' => $id]) / 2000);
        if ($page == 0) {
            $page = 1;
        } elseif ($page > 1) {
            $kmHref .= "?page=$page";
        }
        while ($kmHref != null) {
            //echo "$kmHref\n";
            $killmails = CrestTools::getJSON($kmHref);

            foreach ($killmails['items'] as $kill) {
                $href = $kill['href'];
                $exploded = explode('/', $href);
                $killID = (int) $exploded[4];
                $hash = $exploded[5];

                $mdb->insertUpdate('warmails', ['warID' => $id, 'killID' => $killID]);
                if (!$mdb->exists('crestmails',  ['killID' => $killID, 'hash' => $hash])) {
                    $mdb->insert('crestmails', ['killID' => (int) $killID, 'hash' => $hash], ['processed' => false]);
                    Util::out("New WARmail $killID");
                }
                //$added += $aff;
            }
            $next = @$killmails['next']['href'];
            if ($next != $kmHref) {
                $kmHref = $next;
            } else {
                $kmHref = null;
            }
        }
    }
}
