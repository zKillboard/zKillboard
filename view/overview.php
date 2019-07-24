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
    if ($key == 'system') {
        $searchKey = 'solarSystem';
    }
    $id = $mdb->findField('information', 'id', ['type' => "${searchKey}ID", 'name' => $id]);
    if ($id > 0) {
        $app->redirect("/$key/$id/");
    } else {
        $app->redirect('/');
    }
    die();
}

if (strlen("$id") > 11) {
    $app->redirect('/');
}

$validPageTypes = array('overview', 'kills', 'losses', 'solo', 'stats', 'wars', 'supers', 'top', 'trophies', 'ranks');
if ($key == 'alliance') {
    //$validPageTypes[] = 'corpstats';
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
        'constellation' => array('column' => 'constellation', 'mixed' => true),
        'region' => array('column' => 'region', 'mixed' => true),
        'group' => array('column' => 'group', 'mixed' => true),
        'ship' => array('column' => 'shipType', 'mixed' => true),
        'location' => array('column' => 'location', 'mixed' => true),
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

$padSum = 0;
// PadSum?
if ($key == 'character') {
    $result = Mdb::group("padhash", ['characterID'], ['characterID' => (int) $id, 'isVictim' => false, 'count' => ['$gte' => 5]], [], ['count']);
    $padSum = (int) @$result[0]['countSum'];
}


$validAllTimePages = array('character', 'corporation', 'alliance', 'faction');
$nextTopRecalc = 0;
$topLists = array();
$topKills = array();
if ($pageType == 'top' || $pageType == 'topalltime') {
    $topParameters = $parameters; 
    $topParameters['limit'] = 100;
    $topParameters['npc'] = false;
    $topParameters['cacheTime'] = 86400;

    if ($pageType == 'topalltime') {
        $useType = $key;
        if ($useType == 'ship') {
            $useType = 'shipType';
        } elseif ($useType == 'system') {
            $useType = 'solarSystem';
        }

        $topLists = $mdb->findField('statistics', 'topAllTime', ['type' => "{$useType}ID", 'id' => (int) $id]);
        Info::addInfo($topLists);
        $topKills = null; //$mdb->findField('statistics', 'topIskKills', ['type' => "{$useType}ID", 'id' => (int) $id]);
        $topKills = []; //Kills::getDetails($topKills);
        $nextTopRecalc = (int) $mdb->findField('statistics', 'nextTopRecalc', ['type' => "{$useType}ID", 'id' => (int) $id]);
        $nextTopRecalc = $nextTopRecalc + 1;
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
        $p = $topParameters;
        $p['limit'] = 6;
        $topKills = Stats::getTopIsk($p);
    }
} else {
    $p = $parameters;
    $numDays = 7;
    $p['limit'] = 10;
    $p['pastSeconds'] = $numDays * 86400;
    $p['kills'] = $pageType != 'losses';
    $p['npc'] = false;

    $topLists[] = Info::doMakeCommon('Top Characters', 'characterID', Stats::getTop('characterID', $p));
    $topLists[] = Info::doMakeCommon('Top Corporations', 'corporationID', Stats::getTop('corporationID', $p));
    $topLists[] = Info::doMakeCommon('Top Alliances', 'allianceID', Stats::getTop('allianceID', $p));
    $topLists[] = Info::doMakeCommon('Top Ships', 'shipTypeID', Stats::getTop('shipTypeID', $p));
    $topLists[] = Info::doMakeCommon('Top Systems', 'solarSystemID', Stats::getTop('solarSystemID', $p));
    $topLists[] = Info::doMakeCommon('Top Locations', 'locationID', Stats::getTop('locationID', $p));

    $p['limit'] = 6;
    $topKills = Stats::getTopIsk($p);
}

$activity = ['max' => 0];
if ($pageType == 'overview' && in_array($key, ['character', 'corporation', 'alliance'])) {
    $raw = $redis->hget("zkb:activity", $id);
    if ($raw != null) $activity = unserialize($raw);
    else for ($day = 0; $day <= 6; $day++ ) {
        for ($hour = 0; $hour <= 23; $hour++) {
            $count = $mdb->count("activity", ['id' => (int) $id, 'day' => $day, 'hour' => $hour]);
            if ($count > 0) $activity[$day][$hour] = $count;
            $activity['max'] = max($activity['max'], $count);
        }
    }
    $activity['days'] = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
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
$nextApiCheck = null;

$extra = array();
$extra['padSum'] = $padSum;
$extra['activity'] = $activity;
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

if ($key == 'corporation' || $key == 'alliance' || $key == 'faction') {
    $extra['hasSupers'] = @$statistics['hasSupers'];
    $extra['supers'] = @$statistics['supers'];
}

if ($key == 'character' && $pageType == 'trophies') {
    $extra['trophies'] = Trophies::getTrophies($id);
}

if ($pageType == 'ranks') {
    $alltimeRanks = getNearbyRanks($key, "tq:ranks:alltime:$statType", $id, 'Alltime Rank', $statType);
    $day90Ranks = getNearbyRanks($key, "tq:ranks:recent:$statType", $id, '90 Day Rank', $statType);
    $day7Ranks = getNearbyRanks($key, "tq:ranks:weekly:$statType", $id, '7 Day Rank', $statType);
    $extra['allranks'] = ['7day' => $day7Ranks, '90Day' => $day90Ranks, 'alltime' => $alltimeRanks];
}

$statistics['shipsDestroyedRank'] = Util::rankCheck($redis->zRevRank("tq:ranks:alltime:$statType:shipsDestroyed", $id));
$statistics['shipsLostRank'] = Util::rankCheck($redis->zRevRank("tq:ranks:alltime:$statType:shipsLost", $id));
$statistics['iskDestroyedRank'] = Util::rankCheck($redis->zRevRank("tq:ranks:alltime:$statType:iskDestroyed", $id));
$statistics['iskLostRank'] = Util::rankCheck($redis->zRevRank("tq:ranks:alltime:$statType:iskLost", $id));
$statistics['pointsDestroyedRank'] = Util::rankCheck($redis->zRevRank("tq:ranks:alltime:$statType:pointsDestroyed", $id));
$statistics['pointsLostRank'] = Util::rankCheck($redis->zRevRank("tq:ranks:alltime:$statType:pointsLost", $id));
$statistics['overallRank'] = Util::rankCheck($redis->zRank("tq:ranks:alltime:$statType", $id));

if (@$statistics['shipsLost'] > 0) {
    $destroyed = @$statistics['shipsDestroyed']  + @$statistics['pointsDestroyed'];
    $lost = @$statistics['shipsLost'] + @$statistics['pointsLost'];
    if ($destroyed > 0 || $lost > 0) {
        $ratio = floor(($destroyed / ($lost + $destroyed)) * 100);
        $extra['dangerRatio'] = $ratio;
    }
} else if (@$statistics['shipsDestroyed'] > 0) {
    $extra['dangerRatio'] = 100;
}
if (@$statistics['soloKills'] > 0 && @$statistics['shipsDestroyed'] > 0) {
    $gangFactor = 100 - floor(100 * ($statistics['soloKills'] / $statistics['shipsDestroyed']));
    $extra['gangFactor'] = $gangFactor;
}
else if (@$statistics['shipsDestroyed'] > 0) {
    $gangFactor = floor(@$statistics['pointsDestroyed'] / @$statistics['shipsDestroyed'] * 10 / 2);
    $gangFactor = max(0, min(100, 100 - $gangFactor));
    $extra['gangFactor'] = $gangFactor;
}

$statistics['recentShipsDestroyed'] = $redis->zScore("tq:ranks:recent:$statType:shipsDestroyed", $id);
$statistics['recentShipsDestroyedRank'] = Util::rankCheck($redis->zRevRank("tq:ranks:recent:$statType:shipsDestroyed", $id));
$statistics['recentShipsLost'] = (int) $redis->zScore("tq:ranks:recent:$statType:shipsLost", $id);
$statistics['recentShipsLostRank'] = Util::rankCheck($redis->zRevRank("tq:ranks:recent:$statType:shipsLost", $id));
$statistics['recentIskDestroyed'] = $redis->zScore("tq:ranks:recent:$statType:iskDestroyed", $id);
$statistics['recentIskDestroyedRank'] = Util::rankCheck($redis->zRevRank("tq:ranks:recent:$statType:iskDestroyed", $id));
$statistics['recentIskLost'] = $redis->zScore("tq:ranks:recent:$statType:iskLost", $id);
$statistics['recentIskLostRank'] = Util::rankCheck($redis->zRevRank("tq:ranks:recent:$statType:iskLost", $id));
$statistics['recentPointsDestroyed'] = $redis->zScore("tq:ranks:recent:$statType:pointsDestroyed", $id);
$statistics['recentPointsDestroyedRank'] = Util::rankCheck($redis->zRevRank("tq:ranks:recent:$statType:pointsDestroyed", $id));
$statistics['recentPointsLost'] = $redis->zScore("tq:ranks:recent:$statType:pointsLost", $id);
$statistics['recentPointsLostRank'] = Util::rankCheck($redis->zRevRank("tq:ranks:recent:$statType:pointsLost", $id));
$statistics['recentOverallRank'] = Util::rankCheck($redis->zRank("tq:ranks:recent:$statType", $id));

if (@$statistics['recentShipsLost'] > 0 || @$statistics['recentShipsDestroyed'] > 0) {
    $destroyed = @$statistics['recentShipsDestroyed'] + @$statistics['recentPointsDestroyed'];
    $lost = @$statistics['recentShipsLost'] + @$statistics['recentPointsLost'];
    if ($destroyed > 0 || $lost > 0) {
        $ratio = floor(($destroyed / ($lost + $destroyed)) * 100);
        $extra['recentDangerRatio'] = $ratio;
    }
}

$recentSoloKills = MongoFilter::getCount(['isVictim' => false, "${type}ID" => (int) $id, 'solo' => true, 'pastSeconds' => 7776000]);
if ($recentSoloKills > 0 && $statistics['recentShipsDestroyed'] > 0) {
    $gangFactor = 100 - floor(100 * ($recentSoloKills / ($recentSoloKills + $statistics['recentShipsDestroyed'])));
    $extra['recentGangFactor'] = $gangFactor;
}
else if (@$statistics['shipsDestroyed'] > 0) {
    $extra['recentGangFactor'] = 100;
}
$statistics['recentSoloKills'] = $recentSoloKills;

$statistics['weeklyShipsDestroyed'] = $redis->zScore("tq:ranks:weekly:$statType:shipsDestroyed", $id);
$statistics['weeklyShipsDestroyedRank'] = Util::rankCheck($redis->zRevRank("tq:ranks:weekly:$statType:shipsDestroyed", $id));
$statistics['weeklyShipsLost'] = (int) $redis->zScore("tq:ranks:weekly:$statType:shipsLost", $id);
$statistics['weeklyShipsLostRank'] = Util::rankCheck($redis->zRevRank("tq:ranks:weekly:$statType:shipsLost", $id));
$statistics['weeklyIskDestroyed'] = $redis->zScore("tq:ranks:weekly:$statType:iskDestroyed", $id);
$statistics['weeklyIskDestroyedRank'] = Util::rankCheck($redis->zRevRank("tq:ranks:weekly:$statType:iskDestroyed", $id));
$statistics['weeklyIskLost'] = $redis->zScore("tq:ranks:weekly:$statType:iskLost", $id);
$statistics['weeklyIskLostRank'] = Util::rankCheck($redis->zRevRank("tq:ranks:weekly:$statType:iskLost", $id));
$statistics['weeklyPointsDestroyed'] = $redis->zScore("tq:ranks:weekly:$statType:pointsDestroyed", $id);
$statistics['weeklyPointsDestroyedRank'] = Util::rankCheck($redis->zRevRank("tq:ranks:weekly:$statType:pointsDestroyed", $id));
$statistics['weeklyPointsLost'] = $redis->zScore("tq:ranks:weekly:$statType:pointsLost", $id);
$statistics['weeklyPointsLostRank'] = Util::rankCheck($redis->zRevRank("tq:ranks:weekly:$statType:pointsLost", $id));
$statistics['weeklyOverallRank'] = Util::rankCheck($redis->zRank("tq:ranks:weekly:$statType", $id));

if (@$statistics['weeklyShipsLost'] > 0 || @$statistics['weeklyShipsDestroyed'] > 0) {
    $destroyed = @$statistics['weeklyShipsDestroyed']  + @$statistics['weeklyPointsDestroyed'];
    $lost = @$statistics['weeklyShipsLost'] + @$statistics['weeklyPointsLost'];
    if ($destroyed > 0 || $lost > 0) {
        $ratio = floor(($destroyed / ($lost + $destroyed)) * 100);
        $extra['weeklyDangerRatio'] = $ratio;
    }
}
$weeklySoloKills = MongoFilter::getCount(['isVictim' => false, "${type}ID" => (int) $id, 'solo' => true, 'pastSeconds' => 604800]);
if ($weeklySoloKills > 0 && $statistics['weeklyShipsDestroyed'] > 0) {
    $gangFactor = 100 - floor(100 * ($weeklySoloKills / ($weeklySoloKills + $statistics['weeklyShipsDestroyed'])));
    $extra['weeklyGangFactor'] = $gangFactor;
}
else if ( $statistics['weeklyShipsDestroyed'] > 0) {
    $extra['weeklyGangFactor'] = 100;
}
$statistics['weeklySoloKills'] = $weeklySoloKills;

// Get previous rankings 
$previousTime = time() - (14 * 86400);
$previousDate = date('Ymd');
$previousRank = null;
do {
    $previousDate = date('Ymd', $previousTime);
    $previousRank = Util::rankCheck($redis->zRank("tq:ranks:alltime:$statType:$previousDate", $id));
    if ($previousRank === '-') {
        $previousTime += 86400;
    }
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

$gold = 0;
if ($type == 'character') {
    $user = $mdb->findDoc("users", ['userID' => "user:$id"]);
    if (@$user['adFreeUntil'] >= time()) {
        $gold = 1 + floor(($user['adFreeUntil'] - time()) / (86400 * 365));
    }
    if ($mdb->find("sponsored", ['characterID' => (int) $id])) {
        $extra['hasSponsored'] = true;
    }
    if (@$user['monocle'] == true) $extra['hasMonocle'] = true;
}

// Sponsored killmails
if ($pageType == 'overview' || $pageType == 'losses') {
    $sponsoredKey = "victim.${type}ID";
    $result = Mdb::group("sponsored", ['killID'], [$sponsoredKey => (int) $id, 'entryTime' => ['$gte' => $mdb->now(86400 * -7)]], [], 'isk', ['iskSum' => -1], 6);
    $sponsored = [];
    foreach ($result as $kill) {
        if ($kill['iskSum'] <= 0) continue;
        $killmail = $mdb->findDoc("killmails", ['killID' => $kill['killID']]);
        Info::addInfo($killmail);
        $killmail['victim'] = $killmail['involved'][0];
        $killmail['zkb']['totalValue'] = $kill['iskSum'];

        $sponsored[$kill['killID']] = $killmail;
    }
    $extra['sponsoredMails'] = $sponsored;
}

$extra['statsRecalced'] = $redis->llen('queueStats');

$extra['recentkills'] = $type == 'character' && $redis->get("recentKillmailActivity:$id") == true;

global $twig;
$twig->addGlobal('year', (isset($parameters['year']) ? $parameters['year'] : date('Y')));
$twig->addGlobal('month', (isset($parameters['month']) ? $parameters['month'] : date('m')));


$renderParams = array('pageName' => $pageName, 'kills' => $kills, 'losses' => $losses, 'detail' => $detail, 'page' => $page, 'topKills' => $topKills, 'mixed' => $mixedKills, 'key' => $key, 'id' => $id, 'pageType' => $pageType, 'solo' => $solo, 'topLists' => $topLists, 'corps' => $corpList, 'corpStats' => $corpStats, 'summaryTable' => $stats, 'pager' => $hasPager, 'datepicker' => true, 'nextApiCheck' => $nextApiCheck, 'apiVerified' => false, 'apiCorpVerified' => false, 'prevID' => $prevID, 'nextID' => $nextID, 'extra' => $extra, 'statistics' => $statistics, 'activePvP' => $activePvP, 'nextTopRecalc' => $nextTopRecalc, 'entityID' => $id, 'entityType' => $key, 'gold' => $gold);

$app->render('overview.html', $renderParams);

function getNearbyRanks($key, $rankKeyName, $id, $title, $statType)
{
    global $redis;

    $array = [];
    $rank = $redis->zrank($rankKeyName, $id);
    if ($rank !== false) {
        $start = max($rank - 25, 0);
        $end = max($rank + 25, 50);
        $nearRanks = $redis->zrange($rankKeyName, $start, $end);
        foreach ($nearRanks as $row) {
            $a = [];
            $a['rank'] = $redis->zrank($rankKeyName, $row) + 1;
            $a[$statType] = $row;
            $a['score'] = $redis->zscore($rankKeyName, $row);
            $array['data'][] = $a;
        }
        Info::addInfo($array);
        $title = $title.' #'.number_format($rank + 1, 0);
    }
    $array['title'] = $title;
    $array['type'] = $key;

    return $array;
}
