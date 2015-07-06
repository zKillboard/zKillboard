<?php

require_once '../init.php';

global $baseAddr;

$crestmails = $mdb->getCollection('crestmails');
$rawmails = $mdb->getCollection('rawmails');
$queueProcess = new RedisQueue('queueProcess');
$queueShare = new RedisQueue('queueShare');
$killsLastHour = new RedisTtlCounter('killsLastHour');

$counter = 0;
$timer = new Timer();
while (!Util::exitNow() && $timer->stop() < 115000) {
    $unprocessed = $crestmails->find(array('processed' => false))->sort(['killID' => -1])->limit(10);

    if (!$unprocessed->hasNext()) {
        sleep(1);
    }
    foreach ($unprocessed as $crestmail) {
        if (Util::exitNow()) {
            break;
        }
        $id = $crestmail['killID'];
        $hash = $crestmail['hash'];

        if ($mdb->exists('killmails', ['killID' => $id])) {
            $crestmails->update($crestmail, array('$set' => array('processed' => true)));
            continue;
        }

        $killmail = CrestTools::fetch($id, $hash);
        if ($killmail == null || $killmail == '') {
            $crestmails->update($crestmail, array('$set' => array('processed' => null)));
            continue;
        }
	if ($killmail == 415 || $killmail == 500) {
            $crestmails->update($crestmail, array('$set' => array('processed' => null, 'errorCode' => $killmail)));
            continue;
	}
        unset($crestmail['npcOnly']);
        unset($killmail['zkb']);
        unset($killmail['_id']);

        $killsLastHour->add($id);
        if (!$mdb->exists('rawmails', ['killID' => (int) $id])) {
            $rawmails->save($killmail);
        }

        if (!validKill($killmail)) {
            $crestmail['npcOnly'] = true;
            $crestmail['processed'] = true;
            $crestmails->save($crestmail);
            continue;
        }

        $killID = @$killmail['killID'];
        if ($killID != 0) {
            $crestmail['processed'] = true;
            $crestmails->save($crestmail);
            $queueProcess->push($killID);
            ++$counter;

            $queueShare->push($killID);
        } else {
            $crestmails->update($crestmail, array('$set' => array('processed' => null)));
        }
    }
}
if ($debug && $counter > 0) {
    Util::out('Added '.number_format($counter, 0).' Kills.');
}

function validKill(&$kill)
{
    // Show all pod kills
    $victimShipID = $kill['victim']['shipType']['id'];
    if ($victimShipID == 670 || $victimShipID == 33328) {
        return true;
    }

    $npcOnly = true;
    $victimCorp = $kill['victim']['corporation']['id'] < 1000999 ? 0 : $kill['victim']['corporation']['id'];

    $blueOnBlue = true;
    foreach ($kill['attackers'] as $attacker) {
        if (isset($attacker['shipType']['id'])) {
            $attackerGroupID = Info::getGroupID($attacker['shipType']['id']);
            if ($attackerGroupID == 365) {
                return true;
            } // A tower is involved
            if ($attackerGroupID == 99) {
                return true;
            } // A sentry gun is involved
        }

        if (isset($attacker['shipType']['id']) && $attacker['shipType']['id'] == 34495) {
            return true;
        } // A drifter is involved

        // Don't process the kill if it's NPC only
        if (isset($attacker['corporation']['id']) && $attacker['corporation']['id'] == 1000125) {
            return true;
        }
        //if (!isset($attacker["character"]["id"]) || !isset($attacker["corporation"]["id"])) continue;
        $npcOnly &= @$attacker['character']['id'] == 0 && (@$attacker['corporation']['id'] < 1999999 && @$attacker['corporation']['id'] != 1000125);
    }
    if ($npcOnly) {
        return false;
    }

    return true;
}
