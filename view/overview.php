<?php

global $mdb, $redis;

$key = $input[0];
if (!isset($input[1])) {
    $app->redirect('/');
}
$id = $input[1];
$pageType = @$input[2];

if ((int) $id == 0) {
	$searchKey = $key;
	if ($key == "system") $searchKey = "solarSystem";
	$id = $mdb->findField("information", "id", ['type' => "${searchKey}ID", 'name' => $id]);
	if ($id > 0) $app->redirect("/$key/$id/");
	else $app->redirect("/");
	die();
}

if (strlen("$id") > 11) {
    $app->redirect('/');
}

$validPageTypes = array('overview', 'kills', 'losses', 'solo', 'stats', 'wars', 'supers', 'top', 'trophies');
if ($key == 'alliance') {
    $validPageTypes[] = 'api';
    $validPageTypes[] = 'corpstats';
}
$validPageTypes[] = 'top';
$validPageTypes[] = 'topalltime';

if (!in_array($pageType, $validPageTypes)) {
    $pageType = 'overview';
}

$map = array(
        'corporation' => array('column' => 'corporation', 'mixed' => true),
        'character' => array('column' => 'character', 'mixed' => true),
        'alliance' => array('column' => 'alliance', 'mixed' => true),
        'faction' => array('column' => 'faction', 'mixed' => true),
        'system' => array('column' => 'solarSystem', 'mixed' => true),
        'region' => array('column' => 'region', 'mixed' => true),
        'group' => array('column' => 'group', 'mixed' => true),
        'ship' => array('column' => 'shipType', 'mixed' => true),
	'location' => array('column' => 'item', 'mixed' => true),
        );
if (!array_key_exists($key, $map)) {
    $app->notFound();
}

if (!is_numeric($id)) {
    $app->redirect('./../');
    exit();
    $function = $map[$key]['id'];
    $id = call_user_func($function, $id);
    if ($id > 0) {
        $app->redirect('/'.$key.'/'.$id.'/', 302);
    } else {
        $app->notFound();
    }
}

if ($id <= 0) {
    $app->notFound();
}

$parameters = Util::convertUriToParameters();
@$page = max(1, $parameters['page']);
global $loadGroupShips; // Can't think of another way to do this just yet
$loadGroupShips = $key == 'group';

$limit = 50;
$parameters['limit'] = $limit;
$parameters['page'] = $page;
try {
    $type = $map[$key]['column'];
    $detail = Info::getInfoDetails("${type}ID", $id);
    if (isset($detail['valid']) && $detail['valid'] == false) {
        $app->notFound();
    }
} catch (Exception $ex) {
    $app->render('error.html', array('message' => "There was an error fetching information for the $key you specified."));

    return;
}
$pageName = isset($detail[$map[$key]['column'].'Name']) ? $detail[$map[$key]['column'].'Name'] : '???';
if ($pageName == '???' && !$mdb->exists('information', ['id' => $id])) {
    return $app->render('404.html', array('message' => 'This entity is not in our database.'), 404);
    die();
}
$columnName = $map[$key]['column'].'ID';
$mixedKills = $pageType == 'overview' && $map[$key]['mixed'] && UserConfig::get('mixKillsWithLosses', true);

$mixed = $pageType == 'overview' ? Kills::getKills($parameters) : array();
$kills = $pageType == 'kills'    ? Kills::getKills($parameters) : array();
$losses = $pageType == 'losses'  ? Kills::getKills($parameters) : array();

if ($pageType != 'solo' || $key == 'faction') {
    $soloKills = array();
    //$soloCount = 0;
} else {
    $soloParams = $parameters;
    if (!isset($parameters['kills']) || !isset($parameters['losses'])) {
        $soloParams['mixed'] = true;
    }
    $soloKills = Kills::getKills($soloParams);
}
//$soloPages = ceil($soloCount / $limit);
$solo = Kills::mergeKillArrays($soloKills, array(), $limit, $columnName, $id);

$validAllTimePages = array('character', 'corporation', 'alliance', 'faction');
$topLists = array();
$topKills = array();
if ($pageType == 'top' || $pageType == 'topalltime') {
    $topParameters = $parameters; // array("limit" => 10, "kills" => true, "$columnName" => $id);
    $topParameters['limit'] = 10;
    $topParameters['cacheTime'] = 86400;

    if ($pageType == 'topalltime' && $key != 'character') {
        $useType = $key;
        if ($useType == 'ship') {
            $useType = 'shipType';
        } elseif ($useType == 'system') {
            $useType = 'solarSystem';
        }
        $topLists = $mdb->findField('statistics', 'topAllTime', ['type' => "{$useType}ID", 'id' => (int) $id]);
    } else {
        if ($pageType != 'topalltime') {
            if (!isset($topParameters['year'])) {
                $topParameters['year'] = date('Y');
            }
            if (!isset($topParameters['month'])) {
                $topParameters['month'] = date('m');
            }
        }
        if (!array_key_exists('kills', $topParameters) && !array_key_exists('losses', $topParameters)) {
            $topParameters['kills'] = true;
        }

        $topLists[] = array('type' => 'character', 'data' => Stats::getTop('characterID', $topParameters));
        $topLists[] = array('type' => 'corporation', 'data' => Stats::getTop('corporationID', $topParameters));
        $topLists[] = array('type' => 'alliance', 'data' => Stats::getTop('allianceID', $topParameters));
        $topLists[] = array('type' => 'ship', 'data' => Stats::getTop('shipTypeID', $topParameters));
        $topLists[] = array('type' => 'system', 'data' => Stats::getTop('solarSystemID', $topParameters));
        $topLists[] = array('type' => 'location', 'data' => Stats::getTop('locationID', $topParameters));

        if (isset($detail['factionID']) && $detail['factionID'] != 0 && $key != 'faction') {
            $topParameters['!factionID'] = 0;
            $topLists[] = array('name' => 'Top Faction Characters', 'type' => 'character', 'data' => Stats::getTop('characterID', $topParameters));
            $topLists[] = array('name' => 'Top Faction Corporations', 'type' => 'corporation', 'data' => Stats::getTop('corporationID', $topParameters));
            $topLists[] = array('name' => 'Top Faction Alliances', 'type' => 'alliance', 'data' => Stats::getTop('allianceID', $topParameters));
        }
    }
} else {
    $p = $parameters;
    $numDays = 7;
    $p['limit'] = 10;
    $p['pastSeconds'] = $numDays * 86400;
    $p['kills'] = $pageType != 'losses';

    if ($key != 'character') {
        $topLists[] = Info::doMakeCommon('Top Characters', 'characterID', Stats::getTop('characterID', $p));
        if ($key != 'corporation') {
            $topLists[] = Info::doMakeCommon('Top Corporations', 'corporationID', Stats::getTop('corporationID', $p));
            if ($key != 'alliance') {
                $topLists[] = Info::doMakeCommon('Top Alliances', 'allianceID', Stats::getTop('allianceID', $p));
            }
        }
    }
    if ($key != 'ship') {
        $topLists[] = Info::doMakeCommon('Top Ships', 'shipTypeID', Stats::getTop('shipTypeID', $p));
    }
    if ($key != 'system' && $key != 'location') {
        $topLists[] = Info::doMakeCommon('Top Systems', 'solarSystemID', Stats::getTop('solarSystemID', $p));
    }
    if ($key != 'location') { 
        $topLists[] = Info::doMakeCommon('Top Locations', 'locationID', Stats::getTop('locationID', $p));
    }
    $p['limit'] = 5;
    $topKills = Stats::getTopIsk($p);
}

$corpList = array();
if ($pageType == 'api') {
    $corpList = Info::getCorps($id);
}

$corpStats = array();
if ($pageType == 'corpstats') {
    $corpStats = Info::getCorpStats($id, $parameters);
}

$onlyHistory = array('character', 'corporation', 'alliance');
if ($pageType == 'stats' && in_array($key, $onlyHistory)) {
    $months = $mdb->findField('statistics', 'months', ['type' => $key.'ID', 'id' => (int) $id]);
    if ($months != null) {
        krsort($months);
    }
    $detail['history'] = $months == null ? [] : $months;
} else {
    $detail['history'] = array();
}

// Figure out if the character or corporation has any API keys in the database
$apiVerified = false;
$nextApiCheck = null;
if (in_array($key, array('character', 'corporation'))) {
    $apiVerifiedSet = new RedisTtlSortedSet('ttlss:apiVerified', 86400);
    $apiVerified = $apiVerifiedSet->getTime((int) $id);
    if ($apiVerified == null) {
        $apiVerified = $apiVerifiedSet->getTime((int) @$detail['corporationID']);
    }
    if ($apiVerified != null) {
        $nextApiCheck = date('H:i', $apiVerified + 3600);
    }
}

$extra = array();
$tracked = false;
if (User::isLoggedIn()) {
	$trackers = [];
	$t = UserConfig::get("tracker_$type", []);
	$tracked = in_array((int) $id, $t);
}
$extra['isTracked'] = $tracked;
$extra['canTrack'] = in_array($type, ['character', 'corporation', 'alliance']);

$cnt = 0;
$cnid = 0;
$stats = array();
$totalcount = ceil(count($detail['stats']) / 4);
if ($detail['stats'] != null) {
	foreach ($detail['stats'] as $q) {
		if ($cnt == $totalcount) {
			++$cnid;
			$cnt = 0;
		}
		$stats[$cnid][] = $q;
		++$cnt;
	}
}
if ($mixedKills) {
	$kills = Kills::mergeKillArrays($mixed, array(), $limit, $columnName, $id);
}

$prevID = null;
$nextID = null;

$warID = (int) $id;
$extra['hasWars'] = false; //Db::queryField("select count(distinct warID) count from zz_wars where aggressor = $warID or defender = $warID", "count");
$extra['wars'] = array();
if (false && $pageType == 'wars' && $extra['hasWars']) {
	$extra['wars'][] = War::getNamedWars('Active Wars - Aggressor', "select * from zz_wars where aggressor = $warID and timeFinished is null order by timeStarted desc");
	$extra['wars'][] = War::getNamedWars('Active Wars - Defending', "select * from zz_wars where defender = $warID and timeFinished is null order by timeStarted desc");
	$extra['wars'][] = War::getNamedWars('Closed Wars - Aggressor', "select * from zz_wars where aggressor = $warID and timeFinished is not null order by timeFinished desc");
	$extra['wars'][] = War::getNamedWars('Closed Wars - Defending', "select * from zz_wars where defender = $warID and timeFinished is not null order by timeFinished desc");
}

if ($key == 'system') {
	$statType = 'solarSystemID';
} elseif ($key == 'ship') {
	$statType = 'shipTypeID';
} else {
	$statType = "{$key}ID";
}
$statistics = $mdb->findDoc('statistics', ['type' => $statType, 'id' => (int) $id]);

if ($key == 'corporation' || $key == 'alliance' || $key == 'faction')
{
	$extra['hasSupers'] = @$statistics['hasSupers'];
	$extra['supers'] = @$statistics['supers'];
}

if ($key == 'character' && $pageType == 'trophies')
{
	$extra['trophies'] = Trophies::getTrophies($id);
}

$statistics['shipsDestroyedRank'] = Util::rankCheck($redis->zRevRank("tq:ranks:alltime:$statType:shipsDestroyed", $id));
$statistics['shipsLostRank'] = Util::rankCheck($redis->zRevRank("tq:ranks:alltime:$statType:shipsLost", $id));
$statistics['iskDestroyedRank'] = Util::rankCheck($redis->zRevRank("tq:ranks:alltime:$statType:iskDestroyed", $id));
$statistics['iskLostRank'] = Util::rankCheck($redis->zRevRank("tq:ranks:alltime:$statType:iskLost", $id));
$statistics['pointsDestroyedRank'] = Util::rankCheck($redis->zRevRank("tq:ranks:alltime:$statType:pointsDestroyed", $id));
$statistics['pointsLostRank'] = Util::rankCheck($redis->zRevRank("tq:ranks:alltime:$statType:pointsLost", $id));
$statistics['recentOverallRank'] = Util::rankCheck($redis->zRank("tq:ranks:recent:$statType", $id));
$statistics['overallRank'] = Util::rankCheck($redis->zRank("tq:ranks:alltime:$statType", $id));

// Get previous rankings 
$previousTime = time() - (14 * 86400);
$previousDate = date('Ymd');
$previousRank = null;
do {
	$previousDate = date('Ymd', $previousTime);
	$previousRank = Util::rankCheck($redis->zRank("tq:ranks:alltime:$statType:$previousDate", $id));
	if ($previousRank === '-') $previousTime += 86400;
} while ($previousRank == '-' && $previousTime < time());
$prevRanks = ['overallRank' => Util::rankCheck($previousRank), 'date' => date('Y-m-d', $previousTime)];
$prevRanks['recentOverallRank'] = Util::rankCheck($redis->zRank("tq:ranks:recent:$statType:$previousDate", $id));
$statistics['prevRanks'] = $prevRanks;

$groups = @$statistics['groups'];
if (is_array($groups) and sizeof($groups) > 0) {
	Info::addInfo($groups);
	$g = [];
	foreach ($groups as $group) {
		@$g[$group['groupName']] = $group;
	}
	ksort($g);

	// Divide the stats into 4 columns...
	$chunkSize = ceil(sizeof($g) / 4);
	$statistics['groups'] = array_chunk($g, $chunkSize);
} else {
	$statistics['groups'] = null;
}

$months = @$statistics['months'];
// Ensure the months are sorted in descending order
if (is_array($months) && sizeof($months) > 0) {
	krsort($months);
	$statistics['months'] = array_values($months);
} else {
	$statistics['months'] = null;
}

// Collect active PVP stats
$activePvP = Stats::getActivePvpStats($parameters);

$hasPager = in_array($pageType, ['overview', 'kills', 'losses', 'solo']);

$renderParams = array('pageName' => $pageName, 'kills' => $kills, 'losses' => $losses, 'detail' => $detail, 'page' => $page, 'topKills' => $topKills, 'mixed' => $mixedKills, 'key' => $key, 'id' => $id, 'pageType' => $pageType, 'solo' => $solo, 'topLists' => $topLists, 'corps' => $corpList, 'corpStats' => $corpStats, 'summaryTable' => $stats, 'pager' => $hasPager, 'datepicker' => true, 'nextApiCheck' => $nextApiCheck, 'apiVerified' => $apiVerified, 'prevID' => $prevID, 'nextID' => $nextID, 'extra' => $extra, 'statistics' => $statistics, 'activePvP' => $activePvP);

$app->render('overview.html', $renderParams);
