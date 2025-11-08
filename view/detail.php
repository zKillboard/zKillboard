<?php

function handler($request, $response, $args, $container) {
    global $mdb, $redis, $twig;

    // Extract route parameters
    $id = $args['id'] ?? '';
    $where = $args['where'] ?? '';	
    
    // Determine pageview from request URI
    $uri = $request->getUri()->getPath();
    if (strpos($uri, '/remaining/') !== false) {
        $pageview = 'remaining';
    } elseif (strpos($uri, '/involved/') !== false) {
        $pageview = 'involved';
    } elseif (strpos($uri, '/items/') !== false) {
        $pageview = 'items';
    } else {
        $pageview = '';
    }

    if ($pageview == 'overview') {
        return $response->withStatus(302)->withHeader('Location', "/kill/$id/");
    }
	if ($pageview == '') {
		$pageview = 'overview';
	}
	if ($where != "") {
		echo $where;
		$crest = $mdb->findDoc('crestmails', ['killID' => (int) $id, 'processed' => true]);
		$hash = $crest['hash'] ?? '';
        switch ($where) {
            case 'esi':
                return $response->withStatus(302)->withHeader('Location', "https://esi.evetech.net/latest/killmails/$id/$hash/");
            case 'eveshipfit':
                return $response->withStatus(302)->withHeader('Location', "https://eveship.fit/?fit=killmail:$id/$hash");
            case 'eveworkbench':
                return $response->withStatus(302)->withHeader('Location', "https://www.eveworkbench.com/import/killmail/$id/$hash");
        }
        return $response->withStatus(302)->withHeader('Location', "/kill/$id/");
    }
    if ($pageview != 'overview' && $pageview != 'involved' && $pageview != 'remaining' && $pageview != 'items') {
        return $response->withStatus(302)->withHeader('Location', "/kill/$id/");
    }

    $involved = array();
    $message = '';

    $oID = $id;
    $id = (int) $id;
    if ("$oID" !== "$id") {
        Util::zout("redirecting $oID to $id");
        return $response->withStatus(302)->withHeader('Location', "/kill/$id/");
    }

	while ($mdb->count('queueInfo', ['killID' => $id])) {
		sleep(1);
	}

	$exists = $mdb->exists('killmails', ['killID' => $id]);
	if (!$exists) {
			return $container->get('view')->render($response->withStatus(404), '404.html', array('message' => "KillID $id does not exist."));
	}

	// Create the details on this kill
	$killdata = Kills::getKillDetails($id);
	$rawmail = Kills::getEsiKill($id);

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
	if (sizeof($killdata['involved']) > 0) {
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
		if (sizeof($extra['items']) > 100) {
			$extra['items'] = 'asyncload';
		}
		$extra['invAll'] = involvedCorpsAndAllis(md5($id), $killdata['involved']);
		$extra['involved'] = $involved;
		$extra['allinvolved'] = $allinvolved;
	} else if  ($pageview == 'items') {
		$extra['items'] = Detail::combineditems(md5($id), $killdata['items']);
		if (sizeof($extra['items']) <= 100) {
			return $response->withStatus(302)->withHeader('Location', "/kill/$id/");
		}
	}
	$insDate = (int) str_replace('-', '', substr($killdata['info']['dttm'], 0, 10));
	$extra['insurance'] = $mdb->findDoc('insurance', ['typeID' => (int) $killdata['victim']['shipTypeID'], 'date' => ['$lte' => $insDate]], ['date' => -1]);
	if (isset($extra['insurance']['Platinum']['payout'])) {
		// No insurance is 40% of platinum
		// https://wiki.eveuniversity.org/Insurance
		$extra['insurance']['None'] = ['cost' => 0, 'payout' => floor(0.4 * $extra['insurance']['Platinum']['payout'])];
	}

$extra['location'] = @$killdata['info']['location']['itemName'];
if (isset($rawmail['victim']['position']) && isset($killdata['info']['location']['itemID'])) {
	$position = $rawmail['victim']['position'];
	$locationID = $killdata['info']['location']['itemID'];
	$auDistance = Util::getAuDistance($position, $locationID, $killdata['info']['system']['solarSystemID']);
	if ($auDistance > 0.01) {
		$extra['locationDistance'] = $auDistance;
		$extra['locationDistanceType'] = "au";
	} else {
		$extra['locationDistance'] = round(Util::get3dDistance($position, $locationID, $killdata['info']['system']['solarSystemID']) / 1000, 3);
		$extra['locationDistanceType'] = "km";
	}
}
$extra['npcOnly'] = @$killdata['info']['npc'];
$extra['atShip'] = in_array('atShip', @$killdata['info']['labels'] ?: []);
$extra['totalisk'] = $killdata['info']['zkb']['totalValue'];
$extra['droppedisk'] = droppedIsk(md5($id), $killdata['items']);
$extra['shipprice'] = Price::getItemPrice($killdata['victim']['shipTypeID'], date('Y-m-d H:i', strtotime($killdata['info']['dttm'])));
$extra['destroyedisk'] = destroyedIsk(md5($id), $killdata['items']);
$extra['destroyediskWship'] = $extra['shipprice'] + destroyedIsk(md5($id), $killdata['items']);
$extra['destroyedprice'] = Util::iskToUsdEurGbp($extra['destroyedisk']);
$extra['destroyedpriceWship'] = Util::iskToUsdEurGbp($extra['destroyediskWship']);
$extra['fittedisk'] = fittedIsk(md5($id), $killdata['items']) + $extra['shipprice'];
$extra['relatedtime'] = date('YmdH00', strtotime($killdata['info']['dttm']));
$extra['fittingwheel'] = Detail::eftarray($killdata['items']);
$extra['involvedships'] = involvedships($killdata['involved']);
$extra['involvedshipscount'] = count($extra['involvedships']);
$extra['totalprice'] = Util::iskToUsdEurGbp($killdata['info']['zkb']['totalValue']);
$extra['droppedprice'] = Util::iskToUsdEurGbp($extra['droppedisk']);
$extra['fittedprice'] = Util::iskToUsdEurGbp($extra['fittedisk']);
$extra['efttext'] = Fitting::EFT($extra['fittingwheel']);
$extra['dnatext'] = Fitting::DNA($killdata['items'], $killdata['victim']['shipTypeID']);
$extra['edkrawmail'] = 'deprecated - use CREST';
$extra['zkbrawmail'] = 'deprecated - use CREST';
$extra['slotCounts'] = Info::getSlotCounts($killdata['victim']['shipTypeID']);
$extra['commentID'] = $id;
$extra['crest'] = $mdb->findDoc('crestmails', ['killID' => $id, 'processed' => true]);
$extra['prevKillID'] = $mdb->findField('killmails', 'killID', ['cacheTime' => 300, 'killID' => ['$lt' => $id]], ['killID' => -1]);
$extra['nextKillID'] = $mdb->findField('killmails', 'killID', ['cacheTime' => 300, 'killID' => ['$gt' => $id]], ['killID' => 1]);
$extra['warInfo'] = War::getKillIDWarInfo($id);
$sponsored = Mdb::group("sponsored", ['killID'], ['killID' => $id], [], 'isk', ['iskSum' => -1], 1);
if (sizeof($sponsored)) {
	$sponsored = array_shift($sponsored);
	$isk = $sponsored['iskSum'];
	if ($isk > 0) $extra['sponsoredIsk'] = $isk;
}
$systemID = $killdata['info']['system']['solarSystemID'];
$data = Info::getWormholeSystemInfo($systemID);
$extra['wormhole'] = $data;

$url = 'https://' . $_SERVER['SERVER_NAME'] . "/detail/$id/";

$relatedShip = null;
$query = ['$and' => [['involved' => ['$elemMatch' => ['isVictim' => true, 'characterID' => (int) @$killdata['victim']['characterID']]]], ['killID' => ['$gte' => ($id - 200)]], ['killID' => ['$lt' => $id]], ['labels' => 'cat:6'], ['vGroupID' => ['$ne' => 29]]]];
$relatedKill = $mdb->findDoc('killmails', $query);
if ($relatedKill) {
	$relatedShip = ['killID' => $relatedKill['killID'], 'shipTypeID' => $relatedKill['involved'][0]['shipTypeID']];
}
if ($relatedShip == null) {
	$query = ['$and' => [['involved.characterID' => (int) @$killdata['victim']['characterID']], ['killID' => ['$lte' => ($id + 200)]], ['killID' => ['$gt' => $id]], ['labels' => 'cat:6'], ['vGroupID' => 29]]];
	$relatedKill = $mdb->findDoc('killmails', $query);
	if ($relatedKill) {
		$relatedShip = ['killID' => $relatedKill['killID'], 'shipTypeID' => $relatedKill['involved'][0]['shipTypeID']];
	}
}
Info::addInfo($relatedShip);
$killdata['victim']['related'] = $relatedShip;

$extra['isExploit'] = in_array($id, [55403284]);

$details = array('pageview' => $pageview, 'killdata' => $killdata, 'extra' => $extra, 'message' => $message, 'flags' => Info::$effectToSlot, 'topDamage' => $topDamage, 'finalBlow' => $finalBlow, 'url' => $url);

// Comments
$pageID = "kill-$id";
$comments = [];

/*$c = $mdb->find("comments", ['pageID' => $pageID], ["upvotes" => -1, "dttm" => 1]);
foreach ($c as $cc) {
	$comments[$cc['comment']] = $cc;
}*/
$index = 0;
foreach (Comments::$defaultComments as $dc) {
	if (!isset($comments[$dc])) $comments[$dc] = ['pageID' => $pageID, 'commentID' => $index, 'comment' => $dc, "upvotes" => 0];
	$index++;
}
$details['comments'] = array_values($comments);

    if ($pageview == 'remaining') {
        return $container->get('view')->render($response, "components/attackers_list.html", [
            'attackList' => array_slice($killdata['involved'], 10),
            'isDelayed' => false,
            'hideTableHeading' => true
        ]);
    }

	if ($pageview == 'items') {
        return $container->get('view')->render($response, "components/item_list.html", $details);
    }

    return $container->get('view')->render($response, 'detail.html', $details);
}

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

function buildItemKey($itm)
{
	$key = $itm['typeName'] . ($itm['singleton'] == 2 ? ' (Copy)' : '');
	$key .= '|' . ($itm['quantity_dropped'] > 0 ? 'dropped' : 'destroyed');
	if (!isset($itm['flagName'])) {
		$itm['flagName'] = Info::getFlagName($itm['flag']);
	}
	$key .= '|' . $itm['flagName'];
	if ($itm['groupID'] == 649) {
		$key .= microtime() . rand(0, 10000);
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
		$itemisk += $item['price'] * (@$item['singleton'] ? @$item['quantity_destroyed'] / 100 : @$item['quantity_destroyed']);
	}

	return $itemisk;
}
function droppedIsk($md5, $items)
{
	$itemisk = 0;
	foreach ($items as $item) {
		$itemisk += $item['price'] * (@$item['singleton'] ? @$item['quantity_dropped'] / 100 : @$item['quantity_dropped']);
	}

	return $itemisk;
}

function fittedIsk($md5, $items)
{
	$fittedIsk = 0;
	$flags = array('High Slots', 'Mid Slots', 'Low Slots', 'SubSystems', 'Rigs', 'Drone Bay', 'Fuel Bay');
	foreach ($items as $item) {
		if (isset($item['flagName']) && in_array($item['flagName'], $flags)) {
			$qty = isset($item['quantity_dropped']) ? $item['quantity_dropped'] : 0;
			$qty += isset($item['quantity_destroyed']) ? $item['quantity_destroyed'] : 0;
			$fittedIsk = $fittedIsk + ($item['price'] * $qty);
		}
	}

	return $fittedIsk;
}
