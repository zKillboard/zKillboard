<?php

$mt = 8; do { $mt--; $pid = pcntl_fork(); } while ($pid > 0 && $mt > 0); if ($pid > 0) exit(); $pid = $mt;

use cvweiss\redistools\RedisQueue;

require_once '../init.php';

//if ($mt > 0 && $redis->get("zkb:load") >= 12) exit();

if ($redis->get("tobefetched") < 10) $redis->del("zkb:statsStop");
//if ($redis->get("tobefetched") > 10) exit();
if ($mdb->findDoc("killmails", ['reset' => true]) != null) exit();
if ($redis->get("zkb:statsStop") == "true") exit();
if ($redis->get("zkb:reinforced") == true) exit();

if ($mt == 0) $mdb->getCollection("statistics")->update(['reset' => false], ['$unset' => ['reset' => true]], ['multiple' => true]);

MongoCursor::$timeout = -1;
$queueStats = new RedisQueue('queueStats');
$minute = date('Hi');

function checkForResets() {
    global $mdb, $redis;

    $count = 0;

    // Look for resets in statistics and add them to the queue
    $cursor = $mdb->getCollection("statistics")->find(['reset' => true])->sort(['_id' => -1])->limit(10000);
    while ($cursor->hasNext()) {
        $row = $cursor->next();
        $raw = $row['type'] . ":" . $row['id'];
        $redis->sadd("queueStatsSet", $raw);
        $count++;
        $hasResets = true;
    }
    if ($count > 0) Util::out("Added $count reset records for stats processing");
    return ($count > 0);
}

$noStatsCount = 0;
if ($mt == 0 && $redis->scard("queueStatsSet") < 5000) checkForResets();
while ($minute == date('Hi')) {
    $raw = $redis->srandmember("queueStatsSet");
    if ($raw == ":" || $raw == ":0") {
        $redis->srem("queueStatsSet", $raw);
        continue;
    }
    if ($raw == null) {
        if ($mt == 0) checkForResets();
        sleep(1);
        continue;
    }

    $arr = explode(":", $raw);
    $type = $arr[0];
    if ($type == "itemID" || $type == "typeID") {
        Util::out("Invalid stats request: $raw");        
        $redis->srem("queueStatsSet", $raw);
        continue;
    }

    if ($type != 'label') $id = (int) $arr[1];
    else {
        array_shift($arr);
        $id = implode(":", $arr);
    }
    $key = "$type";
    if ($key == 'characterID' || $key == 'corporationID' || $key == "allianceID" || $key == "locationID") $lockKey = "zkb:stats:$key:$id";
    else $lockKey = "zkb:stats:$key";
    if ($redis->set($lockKey, "true", ['nx', 'ex' => 3600]) === true) {
        try {
            $redis->setex("zkb:stats:current:$type:$id", 10800, "true");
            $maxSequence = $mdb->findField("killmails", "sequence", [], ['sequence' => -1]);
            $row = ['type' => $type, 'id' => $id, 'sequence' => $maxSequence];

            do {
                $complete = calcStats($row, $maxSequence);
            } while ($complete == false && date("Hi") == $minute);

            if ($complete) {
                Util::statsBoxUpdate($type, $id);
                $redis->srem("queueStatsSet", $raw);
                if ($type == 'characterID') $mdb->set("statistics", ['type' => $type, 'id' => $id], ['calcTrophies' => true]);
            }
        } catch (Exception $ex) {
            Log::log(print_r($ex, true));
            throw $ex;
        } finally {
            if ($redis->ping() != 1) connectRedis();
            $redis->del("zkb:stats:current:$type:$id");
            $redis->del($lockKey);
        }
    } else {
        usleep(25000); // 1/4th of a second
        $redis->sadd("queueStatsSet", $raw);
    }
}

function calcStats($row, $maxSequence)
{
    global $mdb, $debug;

    $type = $row['type'];
    $id = $row['id'];
    $key = ['type' => $type, 'id' => $id];
    $stats = $mdb->findDoc('statistics', $key);
    $resetInProgress = false;
    if ($stats === null || @$stats['reset'] === true) {
        $resetInProgress = true;
        $id_ = isset($stats['_id']) ? $stats['_id'] : null;
        $topAllTime = isset($stats['topAllTime']) ? $stats['topAllTime'] : null;
        $stats = [];
        $stats['type'] = $type;
        $stats['id'] = $id;
        if ($id_ !== null) {
            $stats['_id'] = $id_;
            $stats['topAllTime'] = $topAllTime;
        }
        $row['sequence'] = 0;
    }
    $stats['reset'] = false;
    $newSequence = (int) $row['sequence'];

    $delta = 100000; // default for following switch
    switch ($type) {
        case "characterID":
            $delta = 10000000;
            break;
        case "locationID":
        case "allianceID":
        case "corporationID":
        case "groupID":
        case "constellationID":
        case "solarSystemID";
            $delta = 1000000;
            break;
        case "shipTypeID":
        case "factionID":
        case "regionID":
        case "label":
            $delta = 100000;
            break;
        default:
            throw new Exception("Unknown type for stats processing: '$type'");
    }

    $oldSequence = (int) @$stats['sequence'];
    $newSequence = min($oldSequence + $delta, $maxSequence);

    //Util::out("next $type $id $oldSequence $newSequence");

    for ($i = 0; $i <= 1; ++$i) {
        $isVictim = ($i == 0);
        if (($type == 'label' || $type == 'locationID' || $type == 'regionID' || $type == 'constellationID' || $type == 'solarSystemID') && $isVictim == true) {
            continue;
        }

        // build the query
        $query = [$row['type'] => $row['id'], 'isVictim' => $isVictim, 'labels' => 'pvp'];
        unset($query['label']);

        if ($type == 'label' && $id == 'all') unset($query['labels']);
        else if ($type == 'label') $query['labels'] = $id;
        else if ($isVictim == false) $query['labels'] = 'pvp'; // Allows NPCs to count their kills

        if ($type != 'label') {
            unset($query['labels']);
            $query['npc'] = false;
        }

        if ($type == 'label' || $type == 'locationID' || $type == 'regionID' || $type == 'constellationID' || $type == 'solarSystemID') unset($query['isVictim']);


        $query = MongoFilter::buildQuery($query);

        // set the proper sequence values
        $and = [['sequence' => ['$gt' => $oldSequence]], ['sequence' => ['$lte' => $newSequence]]];
        if (!($type == 'label' && $id == 'all')) $and[] = $query;
        $query = ['$and' => $and];

        $allTime = $mdb->group('killmails', [], $query, 'killID', ['zkb.points', 'zkb.totalValue', 'attackerCount']);
        mergeAllTime($stats, $allTime, $isVictim);

        $groups = $mdb->group('killmails', 'vGroupID', $query, 'killID', ['zkb.points', 'zkb.totalValue', 'attackerCount'], ['vGroupID' => 1]);
        mergeGroups($stats, $groups, $isVictim);

        $months = $mdb->group('killmails', ['year' => 'dttm', 'month' => 'dttm'], $query, 'killID', ['zkb.points', 'zkb.totalValue', 'attackerCount'], ['year' => 1, 'month' => 1]);
        mergeMonths($stats, $months, $isVictim);

        $labels = $mdb->group('killmails', ['$unwind', 'labels'], $query, 'killID', ['zkb.points', 'zkb.totalValue', 'attackerCount'], ['labels' => 1]);
        mergeLabels($stats, $labels, $isVictim);

        $query = [$row['type'] => $row['id'], 'isVictim' => $isVictim, 'labels' => 'pvp','solo' => true];
        if ($isVictim == false) unset($query['labels']); // Allows NPCs to count their kills
        if ($type == 'locationID' || $type == 'regionID' || $type == 'constellationID' || $type == 'solarSystemID') unset($query['isVictim']);
        $query = MongoFilter::buildQuery($query);
        $key = "solo" . ($isVictim ? "Losses" : "Kills");
        $query = ['$and' => [['sequence' => ['$gt' => $oldSequence]], ['sequence' => ['$lte' => $newSequence]], $query]];

        $count = $mdb->count('killmails', $query);
        $stats[$key] = isset($stats[$key]) ? $stats[$key] + $count : $count;
    }

    // Update the sequence
    $stats['sequence'] = $newSequence;
    $stats['epoch'] = time();

    if (@$stats['shipsLost'] > 0) {
        $destroyed = @$stats['shipsDestroyed']  + @$stats['pointsDestroyed'];
        $lost = @$stats['shipsLost'] + @$stats['pointsLost'];
        if ($destroyed > 0 && $lost > 0) {
            $ratio = floor(($destroyed / ($lost + $destroyed)) * 100);
            $stats['dangerRatio'] = $ratio;
        }
    }

    if (@$stats['soloKills'] > 0 && @$stats['shipsDestroyed'] > 0) {
        $gangFactor = 100 - floor(100 * ($stats['soloKills'] / $stats['shipsDestroyed']));
        $stats['gangRatio'] = $gangFactor;
    }
    else if (@$stats['shipsDestroyed'] > 0) {
        $gangFactor = floor(@$stats['pointsDestroyed'] / @$stats['shipsDestroyed'] * 10 / 2);
        $gangFactor = max(0, min(100, 100 - $gangFactor));
        $stats['gangRatio'] = $gangFactor;
    }

    $invChecks = ['solo', '#:2+', '#:5+', '#:10+', '#:25+', '#:50+', '#:100+', '#:1000+'];
    $invCounts = [];
    $total = 0;
    $invCountSolo = 0;
    $invCountsSum = 0;
    $invCountsAvgSum = 0;
    foreach ($invChecks as $invCheck) {
        if (isset($stats['labels'][$invCheck]['shipsDestroyed'])) {
            $invCountsSum += $stats['labels'][$invCheck]['shipsDestroyed'];
            $num = ($invCheck == 'solo' ? 1 : str_replace('+', '', str_replace('#:', '', $invCheck)));
            $total +=  $stats['labels'][$invCheck]['shipsDestroyed'];
            if ($invCheck == 'solo') $invCountSolo = $stats['labels'][$invCheck]['shipsDestroyed'];
            $invCountsAvgSum += ($num * $stats['labels'][$invCheck]['shipsDestroyed']);
        }
    }
    $avg = ($invCountsSum == 0 ? 0 : round($invCountsAvgSum / $invCountsSum, 1));
    $soloRatio = ($total == 0 ? 0 : round(($invCountSolo / $total) * 100, 1));
    $stats['avgGangSize'] = $avg;
    $stats['soloRatio'] = $soloRatio;


    if (@$stats['shipsDestroyed'] > 10 && @$stats['shipsDestroyed'] > @$stats['nextTopRecalc']) $stats['calcAlltime'] = true;
    // save it
    $mdb->getCollection('statistics')->save($stats);


    return ($newSequence == $maxSequence);
}

function mergeAllTime(&$stats, $result, $isVictim)
{
    if (sizeof($result) == 0) {
        return;
    }

    $row = $result[0];
    $dl = ($isVictim ? 'Lost' : 'Destroyed');
    if (!isset($stats["ships$dl"])) {
        $stats["ships$dl"] = 0;
    }
    $stats["ships$dl"] += $row['killIDCount'];
    if (!isset($stats["points$dl"])) {
        $stats["points$dl"] = 0;
    }
    $stats["points$dl"] += $row['zkb_pointsSum'];
    if (!isset($stats["isk$dl"])) {
        $stats["isk$dl"] = 0;
    }
    if (!isset($stats["attackers$dl"])) $stats["attackers$dl"] = 0;
    $stats["isk$dl"] += (int) $row['zkb_totalValueSum'];
    $stats["attackers$dl"] += (int) $row['attackerCountSum'];
}

function mergeGroups(&$stats, $result, $isVictim)
{
    if (sizeof($result) == 0) {
        return;
    }

    $dl = ($isVictim ? 'Lost' : 'Destroyed');
    if (!isset($stats['groups'])) {
        $stats['groups'] = [];
    }
    $groups = $stats['groups'];
    foreach ($result as $row) {
        $groupID = $row['vGroupID'];
        if (!isset($groups[$groupID])) {
            $groups[$groupID] = [];
        }
        $groupStats = $groups[$groupID];
        $groupStats['groupID'] = $groupID;

        @$groupStats["ships$dl"] += $row['killIDCount'];
        @$groupStats["points$dl"] += $row['zkb_pointsSum'];
        @$groupStats["isk$dl"] += (int) $row['zkb_totalValueSum'];

        $groups[$groupID] = $groupStats;
    }
    $stats['groups'] = $groups;
}

function mergeMonths(&$stats, $result, $isVictim)
{
    if (sizeof($result) == 0) {
        return;
    }

    $dl = ($isVictim ? 'Lost' : 'Destroyed');
    if (!isset($stats['months'])) {
        $stats['months'] = [];
    }
    $months = $stats['months'];
    foreach ($result as $row) {
        $year = $row['year'];
        $month = $row['month'];
        if (strlen($month) < 2) {
            $month = "0$month";
        }
        $yearMonth = "$year$month";

        if (!isset($months[$yearMonth])) {
            $months[$yearMonth] = [];
        }
        $monthStats = $months[$yearMonth];
        $monthStats['year'] = $year;
        $monthStats['month'] = (int) $month;

        @$monthStats["ships$dl"] += $row['killIDCount'];
        @$monthStats["points$dl"] += $row['zkb_pointsSum'];
        @$monthStats["isk$dl"] += (int) $row['zkb_totalValueSum'];

        $months[$yearMonth] = $monthStats;
    }
    $stats['months'] = $months;
}

function mergeLabels(&$stats, $result, $isVictim) 
{
    if (sizeof($result) == 0) return;

    $dl = ($isVictim ? 'Lost' : 'Destroyed');
    if (!isset($stats['labels'])) $stats['labels'] = [];
    $labels = $stats['labels'];
    foreach ($result as $row) {
        $label = $row['labels'];
        if (!isset($labels[$label])) $labels[$label] = [];

        $labelStats = $labels[$label];
        @$labelStats["ships$dl"] += $row['killIDCount'];
        @$labelStats["points$dl"] += $row['zkb_pointsSum'];
        @$labelStats["isk$dl"] += (int) $row['zkb_totalValueSum'];

        $labels[$label] = $labelStats;
    }

    $stats['labels'] = $labels;
}
