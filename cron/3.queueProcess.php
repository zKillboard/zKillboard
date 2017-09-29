<?php

use cvweiss\redistools\RedisQueue;
use cvweiss\redistools\RedisTtlCounter;

require_once '../init.php';

$dateToday = date('Y-m-d');
$dateYesterday = date('Y-m-d', time() - 86400);
$date7Days = time() - (86400 * 7);
$redis->expire("zkb:loot:green:$dateToday", 86400);
$redis->expire("zkb:loot:red:$dateToday", 86400);
$redis->expire("zkb:loot:green:$dateYesterday", 86400);
$redis->expire("zkb:loot:red:$dateYesterday", 86400);

$crestmails = $mdb->getCollection('crestmails');
$killmails = $mdb->getCollection('killmails');
$queueInfo = new RedisQueue('queueInfo');
$queueProcess = new RedisQueue('queueProcess');
$storage = $mdb->getCollection('storage');

$counter = 0;
$minute = date('Hi');

while ($minute == date('Hi')) {
    $killID = $queueProcess->pop();
    if ($killID !== null) {
        $killID = (int) $killID;

        $mail = $mdb->findDoc('esimails', ['killmail_id' => $killID]);
        if ($mail == null) continue;

        $kill = array();
        $kill['killID'] = $killID;

        $crestmail = $crestmails->findOne(['killID' => $killID, 'processed' => true]);
        if ($crestmail == null) {
            $mdb->set("crestmails", ['killID' => $killID, 'processed' => 'fetching'], ['processed' => false]);
            usleep(10000);
            continue;
        }

        $date = substr($mail['killmail_time'], 0, 19);
        $date = str_replace('.', '-', $date);

        $kill['dttm'] = new MongoDate(strtotime($date . " UTC"));


        $systemID = (int) $mail['solar_system_id'];
        $system = Info::getInfo('solarSystemID', $systemID);

        $solarSystem = array();
        $solarSystem['solarSystemID'] = $systemID;
        $solarSystem['security'] = (double) $system['secStatus'];
        $solarSystem['constellationID'] = (int) $system['constellationID'];
        $solarSystem['regionID'] = (int) $system['regionID'];
        $kill['system'] = $solarSystem;
        if (isset($mail['victim']['position'])) {
            $locationID = Info::getLocationID($systemID, $mail['victim']['position']);
            $kill['locationID'] = (int) $locationID;
        }

        $sequence = $mdb->findField('killmails', 'sequence', ['sequence' => ['$ne' => null]], ['sequence' => -1]);
        if ($sequence == null) {
            $sequence = 0;
        }
        $kill['sequence'] = $sequence + 1;

        $kill['attackerCount'] = sizeof($mail['attackers']);
        $victim = createInvolved($mail['victim']);
        $victim['isVictim'] = true;
        $kill['vGroupID'] = $victim['groupID'];
        $kill['categoryID'] = (int) Info::getInfoField('groupID', $victim['groupID'], 'categoryID');

        $involved = array();
        $involved[] = $victim;

        foreach ($mail['attackers'] as $attacker) {
            $att = createInvolved($attacker);
            $att['isVictim'] = false;
            $involved[] = $att;
        }
        $kill['involved'] = $involved;
        $kill['awox'] = isAwox($kill);
        $kill['solo'] = isSolo($kill);
        $kill['npc'] = isNPC($kill);

        $items = $mail['victim']['items'];
        $i = array();
        $destroyedValue = 0;
        $droppedValue = 0;

        $shipValue = Price::getItemPrice($mail['victim']['ship_type_id'], $date);
        $fittedValue = getFittedValue($mail['victim']['items'], $date);
        $fittedValue += $shipValue;
        $totalValue = processItems($mail['victim']['items'], $date);
        $totalValue += $shipValue;

        $zkb = array();

        if (isset($mail['war_id']) && $mail['war_id'] != 0) {
            $kill['warID'] = (int) $mail['war_id'];
        }
        if (isset($kill['locationID'])) {
            $zkb['locationID'] = $kill['locationID'];
        }

        $zkb['hash'] = $crestmail['hash'];
        $zkb['fittedValue'] = round((double) $fittedValue, 2);
        $zkb['totalValue'] = round((double) $totalValue, 2);
        $zkb['points'] = (int) Points::getKillPoints($killID);
        $kill['zkb'] = $zkb;

        $exists = $killmails->count(['killID' => $killID]);
        if ($exists == 0) {
            $killmails->save($kill);
        }
        $oneWeekExists = $mdb->exists('oneWeek', ['killID' => $killID]);
        if (!$oneWeekExists && $kill['npc'] == false && $kill['dttm']->sec >= $date7Days) {
            $mdb->getCollection('oneWeek')->save($kill);
        }

        $queueInfo->push($killID);
        $redis->incr('zkb:totalKills');
        $multi = $redis->multi();
        $time = $kill['dttm']->sec;
        $time = $time - ($time % 86400);
        $date = date('Ymd', $time);
        $multi->hSet("zkb:day:$date", $killID, $zkb['hash']);
        $multi->sadd("zkb:days", $date);
        $multi->exec();

        ++$counter;
    } else usleep(50000);
}
if ($debug && $counter > 0) {
    Util::out('Processed '.number_format($counter, 0).' Kills.');
}

function createInvolved($data)
{
    global $mdb;

    $dataArray = array('character', 'corporation', 'alliance', 'faction');
    $array = array();
    if (isset($data['ship_type_id'])) {
        $array['shipTypeID'] = $data['ship_type_id'];
    }

    foreach ($dataArray as $index) {
        if (isset($data[$index . '_id']) && $data[$index . '_id'] != 0) {
            $array["${index}ID"] = (int) $data[$index . '_id'];
        }
    }
    if (isset($array['shipTypeID'])) {
        $array['groupID'] = (int) Info::getGroupID($array['shipTypeID']);
    }
    if (isset($data['final_blow']) && $data['final_blow'] == true) {
        $array['finalBlow'] = true;
    }

    return $array;
}

function getFittedValue($items, $dttm)
{
    $fittedValue = 0;
    foreach ($items as $item) {
        $infernoFlag = Info::getFlagLocation($item['flag']);
        $add = ($infernoFlag != 0) || in_array($item['flag'], [87, 89, 93, 155, 158, 159, 172, 2663, 3772]);
        if ($add) $fittedValue += processItem($item, $dttm, false, 0);
    }
    return $fittedValue;
}

function processItems($items, $dttm, $isCargo = false, $parentFlag = 0)
{
    $totalCost = 0;
    foreach ($items as $item) {
        $totalCost += processItem($item, $dttm, $isCargo, $parentFlag);
        if (@is_array($item['items'])) {
            $itemContainerFlag = $item['flag'];
            $totalCost += processItems($item['items'], $dttm, true, $itemContainerFlag);
        }
    }

    return $totalCost;
}

function processItem($item, $dttm, $isCargo = false, $parentContainerFlag = -1)
{
    global $mdb;

    $typeID = (int) $item['item_type_id'];
    $itemName = $mdb->findField('information', 'name', ['type' => 'typeID', 'id' => $typeID]);
    if ($itemName == null) {
        $itemName = "TypeID $typeID";
    }

    if ($typeID == 33329 && $item['flag'] == 89) {
        $price = 0.01;
    } // Golden pod implant can't be destroyed
    else {
        $price = Price::getItemPrice($typeID, $dttm);
    }
    if ($isCargo && strpos($itemName, 'Blueprint') !== false) {
        $item['singleton'] = 2;
    }
    if ($item['singleton'] == 2) {
        $price = 0.01;
    }
    if (strpos($itemName, " SKIN ") !== false) {
        $price = 0.01;
    }

    trackItem($typeID, (int) @$item['quantity_dropped'], (int) @$item['quantity_destroyed'], $price, $dttm, $item['flag']);

    return $price * (@$item['quantity_dropped'] + @$item['quantity_destroyed']);
}

function trackItem($typeID, $dropped, $destroyed, $price, $dttm, $flag)
{
    global $redis, $dateToday, $dateYesterday;
    $dttm = substr($dttm, 0, 10);

    switch ($typeID) {
        case 40520:
        case 44992:
            $d = new RedisTtlCounter("ttlc:item:$typeID:dropped", 86400 * 7);
            $l = new RedisTtlCounter("ttlc:item:$typeID:destroyed", 86400 * 7);
            trackItemLoop($d, $dropped);
            trackItemLoop($l, $destroyed);
            break;
    }
    if ($flag != 2663 && $flag != 3772 && $flag != 89) {
        if ($dttm == $dateToday || $dttm == $dateYesterday) {
            $redis->incrBy("zkb:loot:green:$dttm", ($price * $dropped));
            $redis->incrBy("zkb:loot:red:$dttm", ($price * $destroyed));
        }
    }
}

function trackItemLoop($ttlc, $j)
{
    for ($i = 0; $i < $j; $i++) {
        $ttlc->add(uniqid("", true));
    }
}

function isAwox($row)
{
    $victim = $row['involved'][0];
    $vGroupID = $row['vGroupID'];
    if ($vGroupID == 237 || $vGroupID == 29) {
        return false;
    }
    if (isset($victim['corporationID']) && $vGroupID != 29) {
        $vicCorpID = $victim['corporationID'];
        if ($vicCorpID > 0) {
            foreach ($row['involved'] as $key => $involved) {
                if ($key == 0) {
                    continue;
                }
                if (!isset($involved['finalBlow'])) {
                    continue;
                }
                if ($involved['finalBlow'] != true) {
                    continue;
                }

                if (!isset($involved['corporationID'])) {
                    continue;
                }
                $invCorpID = $involved['corporationID'];
                if ($invCorpID == 0) {
                    continue;
                }
                if ($invCorpID <= 1999999) {
                    continue;
                }
                if ($vicCorpID == $invCorpID) {
                    return true;
                }
            }
        }
    }

    return false;
}

function isSolo($killmail)
{
    // Rookie ships, shuttles, and capsules are not considered as solo
    $victimGroupID = $killmail['vGroupID'];
    if (in_array($victimGroupID, [29, 31, 237])) {
        return false;
    }

    // Only ships can be solo'ed
    $categoryID = Info::getInfoField('groupID', $victimGroupID, 'categoryID');
    if ($categoryID != 6) {
        return false;
    }

    $numPlayers = 0;
    $involved = $killmail['involved'];
    array_shift($involved);
    foreach ($involved as $attacker) {
        if (@$attacker['characterID'] > 3999999) {
            ++$numPlayers;
        }
        if ($numPlayers > 1) {
            return false;
        }
    }
    // Ensure that at least 1 player is on the kill so as not to count losses against NPC's
    return $numPlayers == 1;
}

function isNPC(&$killmail)
{
    $involved = $killmail['involved'];
    array_shift($involved);

    foreach ($involved as $attacker) {
        if (@$attacker['characterID'] > 3999999) {
            return false;
        }
        if (@$attacker['corporationID'] > 1999999) {
            return false;
        }
    }

    return true;
}
