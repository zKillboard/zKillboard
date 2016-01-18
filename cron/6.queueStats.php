<?php

require_once '../init.php';

$timer = new Timer();
$children = [];
$inProgress = [];
$maxChildren = 10;
$maxTime = 295000;

$queueStats = new RedisQueue('queueStats');

$noRowCount = 0;
do {
    if ($redis->llen("queueServer") > 100) exit();
    $row = $queueStats->pop();
    if ($row !== null) {
        $id = $row['id'];
        $type = $row['type'];
        calcStats($row);
    } else {
	$noRowCount++;
	if ($noRowCount >= 5) exit();
    }
} while ($timer->stop() <= $maxTime);
$status = 0;

function calcStats($row)
{
    global $mdb, $debug;

    $type = $row['type'];
    $id = $row['id'];
    $newSequence = $row['sequence'];

    $key = ['type' => $type, 'id' => $id];
    $stats = $mdb->findDoc('statistics', $key);
    if ($stats === null) {
        $stats = [];
        $stats['type'] = $type;
        $stats['id'] = $id;
    }

    $oldSequence = (int) @$stats['sequence'];
    if ($newSequence <= $oldSequence) {
        return;
    }

    for ($i = 0; $i <= 1; ++$i) {
        $isVictim = ($i == 0);
        if (($type == 'locationID' || $type == 'regionID' || $type == 'solarSystemID') && $isVictim == true) {
            continue;
        }

        // build the query
        $query = [$row['type'] => $row['id'], 'isVictim' => $isVictim];
        $query = MongoFilter::buildQuery($query);
        // set the proper sequence values
        $query = ['$and' => [['sequence' => ['$gt' => $oldSequence]], ['sequence' => ['$lte' => $newSequence]], $query]];

        $allTime = $mdb->group('killmails', [], $query, 'killID', ['zkb.points', 'zkb.totalValue']);
        mergeAllTime($stats, $allTime, $isVictim);

        $groups = $mdb->group('killmails', 'vGroupID', $query, 'killID', ['zkb.points', 'zkb.totalValue'], ['vGroupID' => 1]);
        mergeGroups($stats, $groups, $isVictim);

        $months = $mdb->group('killmails', ['year' => 'dttm', 'month' => 'dttm'], $query, 'killID', ['zkb.points', 'zkb.totalValue'], ['year' => 1, 'month' => 1]);
        mergeMonths($stats, $months, $isVictim);
    }

    // Update the sequence
    $stats['sequence'] = $newSequence;
    // save it
    $mdb->getCollection('statistics')->save($stats);

    $r = $mdb->getDb()->command(['getLastError' => 1]);
    if ($r['ok'] != 1) {
        die('stats update failure');
    }
    if ($debug) {
        Util::out("Stats completed for: $type $id $newSequence");
    }
}

function mergeAllTime(&$stats, $result, $isVictim)
{
    if (sizeof($result) == 0) {
        return;
    }

    $row = $result[0];
    $dl = ($isVictim ? 'Lost' : 'Destroyed');
    if (!isset($stats["ships$dl"])) $stats["ships$dl"] = 0;
    $stats["ships$dl"] += $row['killIDCount'];
    if (!isset($stats["points$dl"])) $stats["points$dl"] = 0;
    $stats["points$dl"] += $row['zkb_pointsSum'];
    if (!isset($stats["isk$dl"])) $stats["isk$dl"] = 0;
    $stats["isk$dl"] += (int) $row['zkb_totalValueSum'];
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
