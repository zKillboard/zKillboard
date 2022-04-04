<?php

$master = true;
$pid = pcntl_fork();
$master = ($pid != 0);
pcntl_fork();
pcntl_fork();
pcntl_fork();


use cvweiss\redistools\RedisQueue;
use cvweiss\redistools\RedisTtlCounter;

require_once '../init.php';

if ($redis->get("zkb:universeLoaded") != "true") exit("Universe not yet loaded...\n");
$fittedArray = [11, 12, 13, 87, 89, 93, 158, 159, 172, 2663, 3772];

$dateToday = date('Y-m-d');
$dateYesterday = date('Y-m-d', time() - 86400);
$date7Days = time() - (86400 * 7);
$date90Days = time() - (86400 * 90);
$redis->expire("zkb:loot:green:$dateToday", 86400);
$redis->expire("zkb:loot:red:$dateToday", 86400);
$redis->expire("zkb:loot:green:$dateYesterday", 86400);
$redis->expire("zkb:loot:red:$dateYesterday", 86400);

$crestmails = $mdb->getCollection('crestmails');
$killmails = $mdb->getCollection('killmails');
$queueInfo = new RedisQueue('queueInfo');
$storage = $mdb->getCollection('storage');

$counter = 0;
$minute = date('Hi');

while ($minute == date('Hi')) {
    if ($redis->get("zkb:universeLoaded") != "true") break;
    if ($redis->llen("queueInfo") > 100) sleep(1);
    $row = null;
    $sem = sem_get(3175);
    try {
        sem_acquire($sem);
        $killID = $redis->zrevrange("tobeparsed", 0, 0);
        if ($killID != null) {
            $killID = (int) $killID[0];
            $redis->zrem("tobeparsed", $killID);

            $row = $mdb->findDoc('crestmails', ['killID' => $killID, 'processed' => false], ['killID' => -1]);
            if ($row == null) $row = $mdb->findDoc('crestmails', ['killID' => $killID]);
        }

        if ($row != null) $mdb->set('crestmails', $row, ['processed' => 'processing']);
    } finally {
        sem_release($sem);
    }
    if ($row != null) {
        $killID = (int) $row['killID'];
        $mail = Kills::getEsiKill($killID);

        $kill = array();
        $kill['killID'] = $killID;
        $kill['labels'] = [];

        $date = substr($mail['killmail_time'], 0, 19);
        $date = str_replace('.', '-', $date);

        $kill['dttm'] = new MongoDate(strtotime($date . " UTC"));

        $systemID = (int) $mail['solar_system_id'];
        $system = Info::getInfo('solarSystemID', $systemID);
        $system = Info::getSystemByEpoch($systemID, $kill['dttm']->sec);
        if ($system == null) {
            $redis->zadd("tobeparsed", $killID, $killID);
            $redis->del("zkb:universeLoaded");
            throw new Exception("NULL SYSTEM");
        }

        $solarSystem = array();
        $solarSystem['solarSystemID'] = $systemID;
        $solarSystem['security'] = (double) @$system['secStatus'];
        $solarSystem['constellationID'] = (int) @$system['constellationID'];
        $solarSystem['regionID'] = (int) @$system['regionID'];
        $kill['system'] = $solarSystem;
        if (isset($mail['victim']['position'])) {
            $locationID = Info::getLocationID($systemID, $mail['victim']['position']);
            $kill['locationID'] = (int) $locationID;
        }

        $sequence = Util::getSequence($mdb, $redis);
        $kill['sequence'] = $sequence;

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
        $kill['npc'] = isNPC($kill);
        $kill['awox'] = ($kill['npc'] == true) ? false : isAwox($kill);
        $kill['solo'] = ($kill['npc'] == true) ? false : isSolo($kill);

        $items = $mail['victim']['items'];
        $i = array();
        $destroyedValue = getDValue($killID, $items, $date, false);
        $droppedValue = getDValue($killID, $items, $date, true);

        $shipValue = Price::getItemPrice($mail['victim']['ship_type_id'], $date);
        $destroyedValue += $shipValue;
        $fittedValue = getFittedValue($killID, $mail['victim']['items'], $date);
        $fittedValue += $shipValue;
        $totalValue = processItems($killID, $mail['victim']['items'], $date);
        $totalValue += $shipValue;

        $isPaddedKill = false;
        $padhash = getPadHash($kill);
        if ($padhash != null) {
            $isPaddedKill = ($mdb->count("killmails", ['padhash' => $padhash]) >= 5);
            $kill['padhash'] = $padhash;
        }

        addLabel($kill, $isPaddedKill, 'padding');
        addLabel($kill, true, "cat:" . $kill['categoryID']); 
        $countflag = addLabel($kill, $kill['solo'], 'solo');
        if ($countflag == false) $countflag = addLabel($kill, $kill['attackerCount'] >= 1000, '1000+');
        if ($countflag == false) $countflag = addLabel($kill, $kill['attackerCount'] >= 100, '100+');
        if ($countflag == false) $countflag = addLabel($kill, $kill['attackerCount'] >= 50, '50+');
        if ($countflag == false) $countflag = addLabel($kill, $kill['attackerCount'] >= 25, '25+');
        if ($countflag == false) $countflag = addLabel($kill, $kill['attackerCount'] >= 10, '10+');
        if ($countflag == false) $countflag = addLabel($kill, $kill['attackerCount'] >= 5, '5+');
        if ($countflag == false) $countflag = addLabel($kill, $kill['attackerCount'] >= 2, '2+');
        addLabel($kill, $kill['npc'], 'npc');
        addLabel($kill, !($kill['npc'] == true || $isPaddedKill), 'pvp');
        addLabel($kill, $kill['awox'], 'awox');
        addLabel($kill, $solarSystem['security'] >= 0.45, 'highsec');
        addLabel($kill, $solarSystem['security'] < 0.45 && $solarSystem['security'] >= 0.05, 'lowsec');
        addLabel($kill, $solarSystem['security'] < 0.05 && $solarSystem['regionID'] < 11000001, 'nullsec');
        addLabel($kill, $solarSystem['regionID'] >= 11000000 && $solarSystem['regionID'] < 12000000, 'w-space');
        addLabel($kill, $solarSystem['regionID'] >= 12000000 && $solarSystem['regionID'] < 13000000, 'abyssal');
        addLabel($kill,  $totalValue > 1000000000, '1b+');
        addLabel($kill,  $totalValue > 5000000000, '5b+');
        addLabel($kill,  $totalValue > 10000000000, '10b+');
        addLabel($kill,  $totalValue > 100000000000, '100b+');
        addLabel($kill,  $totalValue > 1000000000000, '1t+');
        addLabel($kill, isCapital($victim['shipTypeID']), 'capital');

        $zkb = array();

        if (isset($mail['war_id']) && $mail['war_id'] != 0) {
            $kill['warID'] = (int) $mail['war_id'];
        }
        if (isset($kill['locationID'])) {
            $zkb['locationID'] = $kill['locationID'];
        }

        $zkb['hash'] = $row['hash'];
        $zkb['fittedValue'] = round((double) $fittedValue, 2);
        $zkb['droppedValue'] = round((double) $droppedValue, 2);
        $zkb['destroyedValue'] = round((double) $destroyedValue, 2);
        $zkb['totalValue'] = round((double) $totalValue, 2);
        $zkb['points'] = ($kill['npc'] == true) ? 1 : (int) Points::getKillPoints($killID);
        $kill['zkb'] = $zkb;

        saveMail($mdb, 'killmails', $kill);
        if ($kill['dttm']->sec >= $date7Days) saveMail($mdb, 'oneWeek', $kill);
        if ($kill['dttm']->sec >= $date90Days) saveMail($mdb, 'ninetyDays', $kill);

        $queueInfo->push($killID);
        $redis->incr('zkb:totalKills');
        ++$counter;

        $killsLastHour = new RedisTtlCounter('killsLastHour');
        $killsLastHour->add($row['killID']);
        $mdb->set('crestmails', $row, ['processed' => true]);
    } else if (!$master) break;
    else usleep(50000);
}

function addLabel(&$kill, $condition, $label)
{
    if ($condition === true) {
        $kill['labels'][] = $label;
        return true;
    }
    return false;
}

function saveMail($mdb, $collection, $kill)
{
    $error = false; 
    do {
        try {
            if ($mdb->exists($collection, ['killID' => $kill['killID']])) return;
            $mdb->getCollection($collection)->save($kill);
            $error = false; 
        } catch (MongoDuplicateKeyException $ex) {
            return;
            // Ignore it...
        } catch (Exception $ex) {
            if ($ex->getCode() != 16759) throw $ex;
            $error = true;
            usleep(100000);
        }
    } while ($error == true);
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

function getFittedValue($killID, $items, $dttm)
{
    global $fittedArray;

    $fittedValue = 0;
    foreach ($items as $item) {
        $typeID = (int) $item['item_type_id'];
        if ($typeID == 0) continue;

        $infernoFlag = Info::getFlagLocation($item['flag']);
        $add = in_array($infernoFlag, $fittedArray);
        if ($add) {
            $qty = ((int) @$item['quantity_dropped'] + (int) @$item['quantity_destroyed']);
            $price = Price::getItemPrice($typeID, $dttm);
            $fittedValue += ($qty * $price);
        }
    }
    return $fittedValue;
}

function getDValue($killID, $items, $dttm, $dropped)
{
    global $fittedArray;

    $droppedOrDestroyed = 'quantity_' . ($dropped == true ? 'dropped' : 'destroyed');

    $dValue = 0;
    foreach ($items as $item) {
        $typeID = (int) $item['item_type_id'];
        if ($typeID == 0) continue;

        $qty = ((int) @$item[$droppedOrDestroyed]);
        $price = Price::getItemPrice($typeID, $dttm);
        $dValue += ($qty * $price);
    }
    return $dValue;
}


function processItems($killID, $items, $dttm, $isCargo = false, $parentFlag = 0)
{
    $totalCost = 0;
    foreach ($items as $item) {
        $totalCost += processItem($killID, $item, $dttm, $isCargo, $parentFlag);
        if (@is_array($item['items'])) {
            $itemContainerFlag = $item['flag'];
            $totalCost += processItems($killID, $item['items'], $dttm, true, $itemContainerFlag);
        }
    }

    return $totalCost;
}

function processItem($killID, $item, $dttm, $isCargo = false, $parentContainerFlag = -1)
{
    global $mdb;

    $typeID = (int) $item['item_type_id'];

    if ($typeID == 33329 && $item['flag'] == 89) $price = 0.01; // Golden pod implant can't be destroyed
    else if ($item['flag'] == 179) $price = 0.01; // Ships in frigate bay have no real value for killmail
    else $price = Price::getItemPrice($typeID, $dttm);

    if ($killID < 21112472 && $isCargo) {
        $itemName = Info::getInfoField("typeID", $typeID, "name");
        if ($itemName == null) $itemName = "TypeID $typeID";
        if (strpos($itemName, 'Blueprint') !== false) $item['singleton'] = 2;
    }
    if ($item['singleton'] == 2) {
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
        $shipTypeID = @$attacker['shipTypeID'];
        $groupID = Info::getInfoField('shipTypeID', $shipTypeID, 'groupID');
        $catID = Info::getInfoField('groupID', $groupID, 'categoryID');
        if ($catID == 65) return false; // If a citadel is on the killmail, its not solo
    }
    // Ensure that at least 1 player is on the kill so as not to count losses against NPC's
    return $numPlayers == 1;
}

function isNPC(&$killmail)
{
    $involved = $killmail['involved'];
    $victim = array_shift($involved);
    if (!isset($victim['characterID']) && $victim['corporationID'] > 1 && $victim['corporationID'] < 1999999) return true;

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

// https://forums.eveonline.com/default.aspx?g=posts&m=4900335#post4900335
function getPadHash($killmail)
{
    global $mdb;

    if ($killmail['npc'] == true) return;

    $victim = array_shift($killmail['involved']);
    $victimID = (int) @$victim['characterID'] == 0 ? 'None' : $victim['characterID'];
    if ($victimID == 0) return;
    $shipTypeID = (int) $victim['shipTypeID'];
    if ($shipTypeID == 0) return;
    $categoryID = (int) Info::getInfoField('groupID', $victim['groupID'], 'categoryID');
    if ($categoryID != 6) return; // Only ships, ignore POS modules, etc.

    $attackers = $killmail['involved'];
    while ($next = array_shift($attackers)) {
        if (@$next['finalBlow'] == false) continue;
        $attacker = $next;
        break;
    }
    if ($attacker == null) $attacker = $attackers[0];
    $attackerID = (int) @$attacker['characterID'];

    $dttm = $killmail['dttm']->sec;
    $dttm = $dttm - ($dttm % 60);

    $aString = "$victimID:$attackerID:$shipTypeID:$dttm";
    $aSha = sha1($aString);
    return $aSha;
}

// Determining if a ship is a Capital Ship by the definition of the game's market data,
// and if that ship falls under "Capital Ships" or not.
function isCapital($typeID) {
    global $mdb, $redis;

    $key = "market:isCapital:$typeID";
    $is = $redis->get($key);
    if ($is !== false) return (bool) $is;

    $mGroupID = Info::getInfoField("typeID", $typeID, "market_group_id");
    do {
        $mGroup = Info::getInfo("marketGroupID", $mGroupID);
        $mGroupID = (int) @$mGroup['parent_group_id'];
        if ($mGroupID == 1381) $is = true;
    } while ($is == false && $mGroupID > 0);

    $redis->setex($key, 86400, "$is");
    return $is;
}
