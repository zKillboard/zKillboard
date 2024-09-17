<?php

global $mdb, $redis;

$charparsed = [];
$totalChars = 0;
$totalShips = 0;
$lineCount = 0;

try {

    $includes = ['_id' => 0, 'id' => 1, 'ticker' => 1, 'name' => 1, 'corporationID' => 1, 'allianceID' => 1, 'factinoID' => 1, 'secStatus' => 1];
    $statsIncludes = ['_id' => 0, 'shipsDestroyed' => 1, 'shipsLost' => 1, 'dangerRatio' => 1, 'gangRatio' => 1, 'avgGangSize' => 1, 'labels.ganked.shipsDestroyed' => 1];

    $scan = @$_POST['scan'];
    if (strlen($scan) > 25000) exit();
Log::log("ScanAlyzer $scan");
    $scan = str_replace(",", "", $scan);
    $scan = str_replace("\\n", ",", $scan);
    $scan = str_replace("\n", ",", $scan);
    $scan = str_replace("‘", "'", $scan);
    $scan = str_replace("’", "'", $scan);
    //$scan = str_replace("\r", ",", $scan);
    //$scan = str_replace("\\t", ",", $scan);
    //$scan = str_replace(" - ", "*,", $scan);
    $scan = str_replace('"', "", $scan);
    $scan = explode(',', $scan);

    $chars = [];
    $corps = [];
    $allis = [];
    $ships = [];
    foreach ($scan as $line) {
        $row = null;
        $line = trim($line);
        $line = str_replace("\\t", ",", $line);
        $line = str_replace("   ", ",", $line);
        $split = explode(',', $line);
        $entity = trim($split[0]);

        if (strlen($entity) == 0) continue;
        $row = null;

        $isShip = false;
        if (is_numeric($entity)) { // Is this a ship?
            $row = $mdb->findDoc("information", ['type' => 'typeID', 'id' => (int) $entity, 'cacheTime' => 3600]);
            if ($row != null) {
                if (((int) $row['categoryID']) == 6) {
                    $isShip = true;
                    $ship = isset($ships[$entity]) ? $ships[$entity] : ['shipTypeID' => $entity, 'count' => 0];
                    $ship['count']++;
                    $ships[$entity] = $ship;
                    $totalShips++;
                }
            }
        }

        if ($isShip) {
            $entity = @$split[1];
            $split = explode(' - ', $entity);
            $entity = isset($split[1]) ? trim($split[1]) : trim($split[0]);
        }

        if ($isShip || $row == null) { // Let's see if this is a character
            if (isset($charparsed[$entity])) continue;
            $charparsed[$entity] = true;

            $row = $mdb->findDoc("information", ['type' => 'characterID', 'name' => $entity, 'cacheTime' => 3600], [], $includes);
            if ($row == null) continue;

            $row['labels'] = [];

            // do they have activity in the last 90 days
            $doc = $mdb->findDoc("ninetyDays", ['involved.characterID' => $row['id']]);
            if ($doc == null) $row['inactive'] = true;

            $totalChars++;
            $stats = $mdb->findDoc("statistics", ['type' => 'characterID', 'id' => $row['id'], 'cacheTime' => 3600], [], $statsIncludes);
            $row['stats'] = ($stats == null ? [] : $stats);
            $row['stats']['ganked-shipsDestroyed'] = (int) @$stats['labels']['ganked']['shipsDestroyed'];
            unset($row['stats']['labels']);

            $p = ['characterID' => [$row['id']], 'limit' => 5, 'pastSeconds' => 7776000, 'kills' => true, 'cacheTime' => 3600];
            $topShips = Stats::getTop('shipTypeID', $p);
            $row['ships'] = $topShips;

            $chars[] = $row;
            add($corps, $row, 'corporationID');
            add($allis, $row, 'allianceID');
        }
    }

    foreach (array_keys($corps) as $corp) {
        $row = $mdb->findDoc("information", ['type' => 'corporationID', 'id' => $corp], [], $includes);
        if ($row != null) $corps[$corp] = $row;
    }

    foreach (array_keys($allis) as $alli) {
        $row = $mdb->findDoc("information", ['type' => 'allianceID', 'id' => $alli], [], $includes);
        if ($row != null) $allis[$alli] = $row;
    }

    $ships = array_values($ships);
    $ret = ['chars' => sortem($chars), 'corps' => $corps, 'allis' => $allis, 'ships' => Info::addInfo($ships), 'totalChars' => $totalChars, 'totalShips' => $totalShips];
    if ($ret['ships'] == null) $ret['ships'] = [];

    header('Content-Type: application/json');
    $json = json_encode($ret);
    echo $json;

} catch (Exception $e) { Log::log(print_r($e, true)); }

function add(&$arr, $row, $type) {
    if (isset($row[$type])) $arr[$row[$type]] = 1;
}

function sortem($array) {
    $vals = array_values($array);
    usort($vals, "sortByName");
    return $vals;
}

function sortByName($a, $b) {
    if (@$b['stats']['dangerRatio'] == @$a['stats']['dangerRatio']) return @$a['name'] > @$b['name'];
    return @$b['stats']['dangerRatio'] > @$a['stats']['dangerRatio'];
}
