<?php

require_once '../init.php';

$i = date('Hi');
if ($i != "400") exit();

$mdb = new Mdb();
$types = ['characterID', 'corporationID', 'allianceID', 'factionID', 'groupID', 'shipTypeID', 'solarSystemID', 'regionID'];
$timer = new Timer();
$now = time();
$now = $now - ($now % 60);
$then = $now - (90 * 6400);
$ninetyDayKillID = null;
do {
	$result = $mdb->getCollection('killmails')->find(['dttm' => new MongoDate($then)], ['killID' => 1])->sort(['killID' => 1])->limit(1);
	if ($row = $result->next()) {
		$ninetyDayKillID = (int) $row['killID'];
	} else {
		$then += 60;
	}
	if ($then > $now) exit();
} while ($ninetyDayKillID === null);

// Clear out ranks more than two weeks old
$mdb->remove('ranksProgress', ['date' => ['$lt' => $mdb->now(-86400 * 14)]]);

foreach ($types as $type) {
    Util::out("Started recent calcs for $type");
    $calcStats = $mdb->find('information', ['type' => $type]);
    foreach ($calcStats as $row) {
        calcStats($row, $ninetyDayKillID);
    }
    Util::out("Completed recent calcs for $type");
}

function calcStats($row, $ninetyDayKillID)
{
    global $mdb, $debug;

    $type = $row['type'];
    $id = $row['id'];

    $killID = (int) @$row['killID'];
    $key = ['type' => $type, 'id' => $id];
    if ($killID < $ninetyDayKillID) {
        $mdb->getCollection('statistics')->update($key, ['$unset' => ['recentShipsLost' => 1, 'recentPointsLost' => 1, 'recentIskLost' => 1, 'recentShipsDestroyed' => 1, 'recentPointsDestroyed' => 1, 'recentIskDestroyed' => 1, 'recentOverallRank' => 1, 'recentOverallScore' => 1]]);

        return;
    }

    $stats = [];
    for ($i = 0; $i <= 1; ++$i) {
        $isVictim = ($i == 0);
        if (($type == 'regionID' || $type == 'solarSystemID') && $isVictim == true) {
            continue;
        }

        // build the query
        $query = [$row['type'] => $row['id'], 'isVictim' => $isVictim];
        $query = MongoFilter::buildQuery($query);
        // set the proper sequence values
        $query = ['$and' => [['killID' => ['$gte' => $ninetyDayKillID]], $query]];

        $recent = $mdb->group('killmails', [], $query, 'killID', ['zkb.points', 'zkb.totalValue']);
        mergeAllTime($stats, $recent, $isVictim);
    }
    $mdb->getCollection('statistics')->update($key, ['$set' => $stats]);
}

function mergeAllTime(&$stats, $result, $isVictim)
{
    if (sizeof($result) == 0) {
        return;
    }

    $row = $result[0];
    $dl = ($isVictim ? 'Lost' : 'Destroyed');
    @$stats["recentShips$dl"] += $row['killIDCount'];
    @$stats["recentPoints$dl"] += $row['zkb_pointsSum'];
    @$stats["recentIsk$dl"] += (int) $row['zkb_totalValueSum'];
}

$categories = ['Ships', 'Isk', 'Points'];

foreach ($types as $type) {
    Util::out("Starting recent ranking for $type");
    $size = $mdb->count('statistics', ['type' => $type]);
    $rankingIDs = [];

    foreach ($categories as $category) {
        for ($i = 0; $i <= 1; ++$i) {
            $field = $category.($i == 0 ? 'Destroyed' : 'Lost');

            $currentValue = -1;
            $currentRank = 0;

            $allIDs = $mdb->find('statistics', ['type' => $type], ["recent$field" => -1], null, ['months' => 0, 'groups' => 0]);
            $currentRank = 0;

            foreach ($allIDs as $row) {
                if (!isset($row["recent$field"])) {
                    continue;
                }
                ++$currentRank;
                $mdb->getCollection('statistics')->update($row, ['$set' => ["recent{$field}Rank" => $currentRank]]);
            }
        }
    }

    $size = $mdb->count('statistics', ['type' => $type]);
    $counter = 0;
    $cursor = $mdb->find('statistics', ['type' => $type], [], null, ['months' => 0, 'groups' => 0]);
    foreach ($cursor as $row) {
        ++$counter;
        $id = $row['id'];

        $shipsDestroyed = getValue($row, 'recentShipsDestroyed', $size);
        $shipsDestroyedRank = getValue($row, 'recentShipsDestroyedRank', $size);
        $shipsLost = getValue($row, 'recentShipsLost', $size);
        $shipsLostRank = getValue($row, 'recentShipsLostRank', $size);
        $shipsEff = ($shipsDestroyed / ($shipsDestroyed + $shipsLost));

        $iskDestroyed = getValue($row, 'recentIskDestroyed', $size);
        $iskDestroyedRank = getValue($row, 'recentIskDestroyedRank', $size);
        $iskLost = getValue($row, 'recentIskLost', $size);
        $iskLostRank = getValue($row, 'recentIskLostRank', $size);
        $iskEff = ($iskDestroyed / ($iskDestroyed + $iskLost));

        $pointsDestroyed = getValue($row, 'recentPointsDestroyed', $size);
        $pointsDestroyedRank = getValue($row, 'recentPointsDestroyedRank', $size);
        $pointsLost = getValue($row, 'recentPointsLost', $size);
        $pointsLostRank = getValue($row, 'recentPointsLostRank', $size);
        $pointsEff = ($pointsDestroyed / ($pointsDestroyed + $pointsLost));

        $avg = ceil(($shipsDestroyedRank + $iskDestroyedRank + $pointsDestroyedRank) / 3);
        $adjuster = (1 + $shipsEff + $iskEff + $pointsEff) / 4;
        $score = ceil($avg / $adjuster);

        $mdb->getCollection('statistics')->update($row, ['$set' => ['recentOverallScore' => (int) $score]]);
    }

    $currentRank = 0;
    $result = $mdb->find('statistics', ['type' => $type], ['recentOverallScore' => 1], null, ['months' => 0, 'groups' => 0]);

    foreach ($result as $row) {
        if (@$row['recentOverallScore'] == null) {
            $mdb->getCollection('statistics')->update($row, ['$unset' => ['recentOverallRank' => 1]]);
        } else {
            ++$currentRank;
            $mdb->getCollection('statistics')->update($row, ['$set' => ['recentOverallRank' => $currentRank]]);
            $mdb->insertUpdate('ranksProgress', ['type' => $type, 'id' => $row['id'], 'date' => $date], ['recentOverallRank' => $currentRank]);
        }
    }
}

function getValue($array, $field, $default)
{
    $value = @$array[$field];
    if (((int) $value) != 0) {
        return $value;
    }

    return $default;
}
