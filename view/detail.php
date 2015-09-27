<?php

global $mdb;

$involved = array();
$message = '';

if ($pageview == 'comments') {
    $app->redirect("/detail/$id/#comment", 302);
}

$info = User::getUserInfo();
$name = $info['username'];
$userID = $info['id'];
$email = $info['email'];

$id = (int) $id;

while ($mdb->count('queueInfo', ['killID' => $id])) {
    sleep(1);
}

$exists = $mdb->exists('killmails', ['killID' => $id]);
if (!$exists) {
    return $app->render('404.html', array('message' => "KillID $id does not exist."), 404);
}

$killKey = "CacheKill:$id:$pageview";
$details = RedisCache::get($killKey);
if ($details == null) {
    // Create the details on this kill
    $killdata = Kills::getKillDetails($id);

    // create the dropdown involved array
    $allinvolved = $killdata['involved'];
    $cnt = 0;
    while ($cnt < 10) {
        if (isset($allinvolved[$cnt])) {
            $involved[] = $allinvolved[$cnt];
            unset($allinvolved[$cnt]);
        }
        ++$cnt;
        continue;
    }
    $topDamage = $finalBlow = null;
    $first = null;
    if (sizeof($killdata['involved']) > 1) {
        foreach ($killdata['involved'] as $inv) {
            if ($first == null) {
                $first = $inv;
            }
            if (@$inv['finalBlow'] == 1) {
                $finalBlow = $inv;
            }
            if ($topDamage == null && @$inv['characterID'] != 0) {
                $topDamage = $inv;
            }
        }
        // If only NPC's are on the mail give them credit for top damage...
        if ($topDamage == null) {
            $topDamage = $first;
        }
    }

    $extra = array();
    // And now give all the arrays and whatnots to twig..
    if ($pageview == 'overview') {
        $extra['items'] = Detail::combineditems(md5($id), $killdata['items']);
        $extra['invAll'] = involvedCorpsAndAllis(md5($id), $killdata['involved']);
        $extra['involved'] = $involved;
        $extra['allinvolved'] = $allinvolved;
    }
    if ($pageview == 'comments') {
        $extra['cmtChars'] = Api::getCharacters($userID);
        $extra['cmtChars'][] = array('characterID' => 0, 'characterName' => 'Anonymous');
    }

    $extra['totalisk'] = $killdata['info']['zkb']['totalValue'];
    $extra['droppedisk'] = droppedIsk(md5($id), $killdata['items']);
    $extra['shipprice'] = Price::getItemPrice($killdata['victim']['shipTypeID'], date('Ymd', strtotime($killdata['info']['dttm'])));
    $extra['lostisk'] = $extra['shipprice'] + destroyedIsk(md5($id), $killdata['items']);
    $extra['fittedisk'] = fittedIsk(md5($id), $killdata['items']);
    $extra['relatedtime'] = date('YmdH00', strtotime($killdata['info']['dttm']));
    $extra['fittingwheel'] = Detail::eftarray(md5($id), $killdata['items'], @$killdata['victim']['characterID']);
    $extra['involvedships'] = involvedships($killdata['involved']);
    $extra['involvedshipscount'] = count($extra['involvedships']);
    $extra['totalprice'] = usdeurgbp($killdata['info']['zkb']['totalValue']);
    $extra['destroyedprice'] = usdeurgbp($extra['lostisk']);
    $extra['droppedprice'] = usdeurgbp($extra['droppedisk']);
    $extra['fittedprice'] = usdeurgbp($extra['fittedisk']);
    $extra['efttext'] = Fitting::EFT($extra['fittingwheel']);
    $extra['dnatext'] = Fitting::DNA($killdata['items'], $killdata['victim']['shipTypeID']);
    $extra['edkrawmail'] = 'deprecated - use CREST';
    $extra['zkbrawmail'] = 'deprecated - use CREST';
    $extra['reports'] = Db::queryField('SELECT count(*) as cnt FROM zz_tickets WHERE killID = :killid', 'cnt', array(':killid' => $id), 0);
    $extra['slotCounts'] = Info::getSlotCounts($killdata['victim']['shipTypeID']);
    $extra['commentID'] = $id;
    $extra['crest'] = $mdb->findDoc('crestmails', ['killID' => $id, 'processed' => true]);
    $extra['prevKillID'] = $mdb->findField('killmails', 'killID', ['cacheTime' => 300, 'killID' => ['$lt' => $id]], ['killID' => -1]);
    $extra['nextKillID'] = $mdb->findField('killmails', 'killID', ['cacheTime' => 300, 'killID' => ['$gt' => $id]], ['killID' => 1]);
    $extra['warInfo'] = War::getKillIDWarInfo($id);
    //$extra["insertTime"] = Db::queryField("select insertTime from zz_killmails where killID = :killID", "insertTime", array(":killID" => $id), 300);

    $systemID = $killdata['info']['system']['solarSystemID'];
    $data = Info::getWormholeSystemInfo($systemID);
    $extra['wormhole'] = $data;

    $url = 'https://'.$_SERVER['SERVER_NAME']."/detail/$id/";

    if ($killdata['victim']['groupID'] == 29) {
        $query = ['$and' => [['involved.characterID' => (int) $killdata['victim']['characterID']], ['killID' => ['$gte' => ($id - 200)]], ['killID' => ['$lt' => $id]], ['vGroupID' => ['$ne' => 29]]]];
        $relatedKill = $mdb->findDoc('killmails', $query);
        if ($relatedKill) {
            $relatedShip = ['killID' => $relatedKill['killID'], 'shipTypeID' => $relatedKill['involved'][0]['shipTypeID']];
        }
    } else {
        $query = ['$and' => [['involved.characterID' => (int) @$killdata['victim']['characterID']], ['killID' => ['$lte' => ($id + 200)]], ['killID' => ['$gt' => $id]], ['vGroupID' => 29]]];
        $relatedKill = $mdb->findDoc('killmails', $query);
        if ($relatedKill) {
            $relatedShip = ['killID' => $relatedKill['killID'], 'shipTypeID' => $relatedKill['involved'][0]['shipTypeID']];
        }
    }
    Info::addInfo($relatedShip);
    $killdata['victim']['related'] = $relatedShip;

    $details = array('pageview' => $pageview, 'killdata' => $killdata, 'extra' => $extra, 'message' => $message, 'flags' => Info::$effectToSlot, 'topDamage' => $topDamage, 'finalBlow' => $finalBlow, 'url' => $url);
    RedisCache::set($killKey, $details, 3600);
}

header('Expires: '.gmdate('D, d M Y H:i:s \G\M\T', time() + (24 * 3600)));
$app->render('detail.html', $details);

function involvedships($array)
{
    $involved = array();
    foreach ($array as $inv) {
        if (isset($involved[@$inv['shipTypeID']]) && isset($inv['shipName'])) {
            $involved[$inv['shipTypeID']] = array('shipName' => $inv['shipName'], 'shipTypeID' => $inv['shipTypeID'], 'count' => $involved[$inv['shipTypeID']]['count'] + 1);
        } elseif (isset($inv['shipTypeID']) && isset($inv['shipName'])) {
            $involved[$inv['shipTypeID']] = array('shipName' => $inv['shipName'], 'shipTypeID' => $inv['shipTypeID'], 'count' => 1);
        } else {
            continue;
        }
    }

    usort($involved, 'sortByOrder');

    return $involved;
}

function sortByOrder($a, $b)
{
    return $a['count'] < $b['count'];
}

function usdeurgbp($totalprice)
{
    $usd = 17;
    $eur = 13;
    $gbp = 10;
    $plex = Price::getItemPrice('29668', date('Ymd'));
    $usdval = $plex / $usd;
    $eurval = $plex / $eur;
    $gbpval = $plex / $gbp;

    return array('usd' => $totalprice / $usdval, 'eur' => $totalprice / $eurval, 'gbp' => $totalprice / $gbpval);
}

function buildItemKey($itm)
{
    $key = $itm['typeName'].($itm['singleton'] == 2 ? ' (Copy)' : '');
    $key .= '|'.($itm['quantityDropped'] > 0 ? 'dropped' : 'destroyed');
    if (!isset($itm['flagName'])) {
        $itm['flagName'] = Info::getFlagName($itm['flag']);
    }
    $key .= '|'.$itm['flagName'];
    if ($itm['groupID'] == 649) {
        $key .= microtime().rand(0, 10000);
    }

    return $key;
}

function involvedCorpsAndAllis($md5, $involved)
{
    $involvedAlliCount = 0;
    $involvedCorpCount = 0;
    // Create the involved corps / alliances list
    $invAll = array();
    foreach ($involved as $inv) {
        $allianceID = @$inv['allianceID'];
        $corporationID = @$inv['corporationID'];
        if (!isset($invAll["$allianceID"])) {
            ++$involvedAlliCount;
            $invAll["$allianceID"] = array();
            if ($allianceID != 0) {
                $invAll["$allianceID"]['allianceName'] = $inv['allianceName'];
            }
            if ($allianceID != 0) {
                $invAll["$allianceID"]['name'] = $inv['allianceName'];
            }
            if ($allianceID != 0) {
                $invAll["$allianceID"]['allianceID'] = $allianceID;
            }
            $invAll["$allianceID"]['corporations'] = array();
            $invAll["$allianceID"]['involved'] = 0;
        }
        $involvedCount = $invAll["$allianceID"]['involved'];
        $invAll["$allianceID"]['involved'] = $involvedCount + 1;

        if (!isset($invAll["$allianceID"]['corporations']["$corporationID"])) {
            ++$involvedCorpCount;
            $invAll["$allianceID"]['corporations']["$corporationID"] = array();
            $invAll["$allianceID"]['corporations']["$corporationID"]['corporationName'] = isset($inv['corporationName']) ? $inv['corporationName'] : '';
            $invAll["$allianceID"]['corporations']["$corporationID"]['name'] = isset($inv['corporationName']) ? $inv['corporationName'] : '';
            $invAll["$allianceID"]['corporations']["$corporationID"]['corporationID'] = $corporationID;
            $invAll["$allianceID"]['corporations']["$corporationID"]['involved'] = 0;
        }
        $involvedCount = $invAll["$allianceID"]['corporations']["$corporationID"]['involved'];
        $invAll["$allianceID"]['corporations']["$corporationID"]['involved'] = $involvedCount + 1;
    }
    uasort($invAll, 'involvedSort');
    foreach ($invAll as $id => $alliance) {
        $corps = $alliance['corporations'];
        uasort($corps, 'involvedSort');
        $invAll["$id"]['corporations'] = $corps;
    }
    if ($involvedCorpCount <= 1 && $involvedAlliCount <= 1) {
        $invAll = array();
    }

    return $invAll;
}

function involvedSort($field1, $field2)
{
    if ($field1['involved'] == $field2['involved'] && isset($field1['name']) && isset($field2['name'])) {
        return strcasecmp($field1['name'], $field2['name']);
    }

    return $field2['involved'] - $field1['involved'];
}

function destroyedIsk($md5, $items)
{
    $itemisk = 0;
    foreach ($items as $item) {
        $itemisk += $item['price'] * (@$item['singleton'] ? @$item['quantityDestroyed'] / 100 : @$item['quantityDestroyed']);
    }

    return $itemisk;
}
function droppedIsk($md5, $items)
{
    $itemisk = 0;
    foreach ($items as $item) {
        $itemisk += $item['price'] * (@$item['singleton'] ? @$item['quantityDropped'] / 100 : @$item['quantityDropped']);
    }

    return $itemisk;
}

function fittedIsk($md5, $items)
{
    $key = $md5.'fittedIsk';

    $fittedIsk = 0;
    $flags = array('High Slots', 'Mid Slots', 'Low Slots', 'SubSystems', 'Rigs', 'Drone Bay', 'Fuel Bay');
    foreach ($items as $item) {
        if (isset($item['flagName']) && in_array($item['flagName'], $flags)) {
            $qty = isset($item['quantityDropped']) ? $item['quantityDropped'] : 0;
            $qty += isset($item['quantityDestroyed']) ? $item['quantityDestroyed'] : 0;
            $fittedIsk = $fittedIsk + ($item['price'] * $qty);
        }
    }
    return $fittedIsk;
}
