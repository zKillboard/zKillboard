<?php

require_once '../init.php';

if ($redis->get("tq:itemsPopulated") != true)
{
        Util::out("Waiting for all items to be populated...");
        exit();
}

$timer = new Timer();
$crestmails = $mdb->getCollection('crestmails');
$killmails = $mdb->getCollection('killmails');
$queueInfo = new RedisQueue('queueInfo');
$queueProcess = new RedisQueue('queueProcess');
$storage = $mdb->getCollection('storage');

$counter = 0;
$timer = new Timer();

while ($timer->stop() < 59000) {
    $killID = $queueProcess->pop();
    if ($killID !== null) {
        $raw = $mdb->findDoc('rawmails', ['killID' => $killID]);
        $mail = $raw;

        $kill = array();
        $kill['killID'] = $killID;

        $crestmail = $crestmails->findOne(['killID' => $killID, 'processed' => true]);
        if ($crestmail == null) {
            continue;
        }

        $date = substr($mail['killTime'], 0, 16);
        $date = str_replace('.', '-', $date);
        $today = date('Y-m-d');
        $kill['dttm'] = new MongoDate(strtotime(str_replace('.', '-', $mail['killTime']).' UTC'));

        $system = $mdb->findDoc('information', ['type' => 'solarSystemID', 'id' => (int) $mail['solarSystem']['id']]);
        if ($system == null) {
            // system doesn't exist in our database yet
            $crestSystem = CrestTools::getJSON($mail['solarSystem']['href']);
            $name = $mail['solarSystem']['name'];
            if ($crestSystem == '') {
                exit("no system \o/ $killID $id".$system['href']);
            }

            $ex = explode('/', $crestSystem['constellation']['href']);
            $constID = (int) $ex[4];
            if (!$mdb->exists('information', ['type' => 'constellationID', 'id' => $constID])) {
                $crestConst = CrestTools::getJSON($crestSystem['constellation']['href']);
                if ($crestConst == '') {
                    exit();
                }
                $constName = $crestConst['name'];

                $regionURL = $crestConst['region']['href'];
                $ex = explode('/', $regionURL);
                $regionID = (int) $ex[4];

                $mdb->insertUpdate('information', ['type' => 'constellationID', 'id' => $constID], ['name' => $constName, 'regionID' => $regionID]);
                if ($debug) {
                    Util::out("Added constellation: $constName");
                }
            }
            $constellation = $mdb->findDoc('information', ['type' => 'constellationID', 'id' => $constID]);
            $regionID = (int) $constellation['regionID'];
            if (!$mdb->exists('information', ['type' => 'regionID', 'id' => $regionID])) {
                $regionURL = "https://public-crest.eveonline.com/regions/$regionID/";
                $crestRegion = CrestTools::getJSON($regionURL);
                if ($crestRegion == '') {
                    exit();
                }

                $regionName = $crestRegion['name'];
                $mdb->insertUpdate('information', ['type' => 'regionID', 'id' => $regionID], ['name' => $regionName]);
                if ($debug) {
                    Util::out("Added region: $regionName");
                }
            }
            $mdb->insertUpdate('information', ['type' => 'solarSystemID', 'id' => (int) $mail['solarSystem']['id']], ['name' => $name, 'regionID' => $regionID, 'secStatus' => ((double) $crestSystem['securityStatus']), 'secClass' => $crestSystem['securityClass']]);
            Util::out("Added system: $name");

            $system = $mdb->findDoc('information', ['type' => 'solarSystemID', 'id' => (int) $mail['solarSystem']['id']]);
        }
        $solarSystem = array();
        $solarSystem['solarSystemID'] = (int) $mail['solarSystem']['id'];
        $solarSystem['security'] = (double) $system['secStatus'];
        $solarSystem['regionID'] = (int) $system['regionID'];
        $kill['system'] = $solarSystem;
	if (isset($raw['victim']['position'])) {
		$locationID = Info::getLocationID($mail['solarSystem']['id'], $raw['victim']['position']);
		$kill['locationID'] = (int) $locationID;
	}

        $sequence = $mdb->findField('killmails', 'sequence', ['sequence' => ['$ne' => null]], ['sequence' => -1]);
        if ($sequence == null) {
            $sequence = 0;
        }
        $kill['sequence'] = $sequence + 1;

        $kill['attackerCount'] = (int) $mail['attackerCount'];
        $victim = createInvolved($mail['victim']);
        $victim['isVictim'] = true;
        $kill['vGroupID'] = $victim['groupID'];
	$victimPoints = Points::getPoints($victim['groupID']);
	if ($victimPoints === 0) Util::out("WARNING: groupID does not have points assigned: " . $victim['groupID']);

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

        $items = $mail['victim']['items'];
        $i = array();
        $destroyedValue = 0;
        $droppedValue = 0;

        $totalValue = processItems($mail['victim']['items'], $date);
        $totalValue += Price::getItemPrice($mail['victim']['shipType']['id'], $date, true);

        $zkb = array();

        if (isset($mail['war']['id']) && $mail['war']['id'] != 0) {
            $kill['warID'] = (int) $mail['war']['id'];
        }
	if (isset($kill['locationID'])) $zkb['locationID'] = $kill['locationID'];

        $zkb['hash'] = $crestmail['hash'];
        $zkb['totalValue'] = (double) $totalValue;
        $zkb['points'] = (int) Points::getKillPoints($kill, $zkb['totalValue']);
        $kill['zkb'] = $zkb;

        $exists = $killmails->count(['killID' => $killID]);
        if ($exists == 0) {
            $killmails->save($kill);
        }
        $oneWeekExists = $mdb->exists('oneWeek', ['killID' => $killID]);
        if (!$oneWeekExists) {
            $mdb->getCollection('oneWeek')->save($kill);
        }

        $queueInfo->push($killID);
	$redis->incr("zkb:totalKills");

        ++$counter;
    }
}
if ($debug && $counter > 0) {
    Util::out('Processed '.number_format($counter, 0).' Kills.');
}

function createInvolved($data)
{
    global $mdb;
    $dataArray = array('character', 'corporation', 'alliance', 'faction', 'shipType');
    $array = array();

    foreach ($dataArray as $index) {
        if (isset($data[$index]['id']) && $data[$index]['id'] != 0) {
            $array["${index}ID"] = (int) $data[$index]['id'];
        }
    }
    if (isset($array['shipTypeID']) && Info::getGroupID($array['shipTypeID']) == -1) {
        $mdb->getCollection('information')->update(['type' => 'group'], ['$set' => ['lastCrestUpdate' => new MongoDate(1)]]);
        Util::out('Bailing on processing a kill, unable to find groupID for '.$array['shipTypeID']);
        exit();
    }
    if (isset($array['shipTypeID'])) {
        $array['groupID'] = (int) Info::getGroupID($array['shipTypeID']);
    }
    if (isset($data['finalBlow']) && $data['finalBlow'] == true) {
        $array['finalBlow'] = true;
    }

    return $array;
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

    $typeID = $item['itemType']['id'];
    $itemName = $mdb->findField("information", 'name', ['type' => 'typeID', 'id' => (int) $typeID]);
    if ($itemName == null) {
        $itemName = "TypeID $typeID";
    }

    if ($typeID == 33329 && $item['flag'] == 89) {
        $price = 0.01;
    } // Golden pod implant can't be destroyed
    else {
        $price = Price::getItemPrice($typeID, $dttm, true);
    }
    if ($isCargo && strpos($itemName, 'Blueprint') !== false) {
        $item['singleton'] = 2;
    }
    if ($item['singleton'] == 2) {
        $price = $price / 100;
    }

    return ($price * (@$item['quantityDropped'] + @$item['quantityDestroyed']));
}

function isAwox($row)
{
    $isAwox = false;
    $victim = $row['involved'][0];
    $vGroupID = $row['vGroupID'];
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
                $isAwox |= $vicCorpID == $invCorpID;
            }
        }
    }

    return $isAwox;
}

function isSolo($row)
{
    $notSolo = [29, 31, 237];

    if ($row['attackerCount'] > 1) {
        return false;
    }

    // make sure the victim isn't a pod, shuttle, or noobship
    $vGroupID = $row['vGroupID'];

    return !in_array($vGroupID, $notSolo);
}
