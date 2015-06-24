<?php

require_once '../init.php';

$minute = (int) date('i');
$hour = (int) date('H');
if ($hour != 14) {
    exit();
}
if ($minute != 41) {
    exit();
}

$date = new MongoDate(strtotime(date('Y-m-d')));
$types = ['corporationID', 'allianceID', 'factionID', 'shipTypeID', 'groupID', 'solarSystemID', 'regionID', 'characterID'];
$categories = ['ships', 'isk', 'points'];

foreach ($types as $type) {
    Util::out("Starting all time ranks for $type");
    $size = $mdb->count('statistics', ['type' => $type]);
    $rankingIDs = [];

    foreach ($categories as $category) {
        for ($i = 0; $i <= 1; ++$i) {
            $field = $category.($i == 0 ? 'Destroyed' : 'Lost');
            Util::out("$type Overall ranking $field");

            $currentValue = -1;
            $currentRank = 0;

            $mdb->getCollection('statistics')->update(['type' => $type, $field => null], ['$set' => [$field => 0]], ['multiple' => true, 'socketTimeoutMS' => -1]);
            $allIDs = $mdb->find('statistics', ['type' => $type], [$field => -1], null, ['months' => 0, 'groups' => 0]);
            $currentRank = 0;
            foreach ($allIDs as $row) {
                ++$currentRank;
                $mdb->getCollection('statistics')->update($row, ['$set' => ["{$field}Rank" => $currentRank]]);
            }
        }
    }

    $size = $mdb->count('statistics', ['type' => $type]);
    $counter = 0;
    Util::out("$type Overall rank calcing");
    $cursor = $mdb->find('statistics', ['type' => $type], [], null, ['months' => 0, 'groups' => 0]);
    foreach ($cursor as $row) {
        ++$counter;
        $id = $row['id'];

        $shipsDestroyed = getValue($row, 'shipsDestroyed', $size);
        $shipsDestroyedRank = getValue($row, 'shipsDestroyedRank', $size);
        $shipsLost = getValue($row, 'shipsLost', $size);
        $shipsLostRank = getValue($row, 'shipsLostRank', $size);
        $shipsEff = ($shipsDestroyed / ($shipsDestroyed + $shipsLost));

        $iskDestroyed = getValue($row, 'iskDestroyed', $size);
        $iskDestroyedRank = getValue($row, 'iskDestroyedRank', $size);
        $iskLost = getValue($row, 'iskLost', $size);
        $iskLostRank = getValue($row, 'iskLostRank', $size);
        $iskEff = ($iskDestroyed / ($iskDestroyed + $iskLost));

        $pointsDestroyed = getValue($row, 'pointsDestroyed', $size);
        $pointsDestroyedRank = getValue($row, 'pointsDestroyedRank', $size);
        $pointsLost = getValue($row, 'pointsLost', $size);
        $pointsLostRank = getValue($row, 'pointsLostRank', $size);
        $pointsEff = ($pointsDestroyed / ($pointsDestroyed + $pointsLost));

        $avg = ceil(($shipsDestroyedRank + $iskDestroyedRank + $pointsDestroyedRank) / 3);
        $adjuster = (1 + $shipsEff + $iskEff + $pointsEff) / 4;
        $score = ceil($avg / $adjuster);

        $mdb->getCollection('statistics')->update($row, ['$set' => ['overallScore' => (int) $score]]);
    }

    Util::out("$type Overall rank updating");
    $currentRank = 0;
    $result = $mdb->find('statistics', ['type' => $type], ['overallScore' => 1], null, ['months' => 0, 'groups' => 0]);
    foreach ($result as $row) {
        if (@$row['overallScore'] == null) {
            $mdb->getCollection('statistics')->update($row, ['$unset' => ['overallRank' => 1]]);
        } else {
            ++$currentRank;
            $mdb->getCollection('statistics')->update($row, ['$set' => ['overallRank' => $currentRank]]);
            $mdb->insertUpdate('ranksProgress', ['type' => $type, 'id' => $row['id'], 'date' => $date], ['overallRank' => $currentRank]);
        }
    }
    Util::out("Completed all time ranks for $type");
}

function getValue($array, $field, $default)
{
    $value = @$array[$field];
    if (((int) $value) != 0) {
        return $value;
    }

    return $default;
}
