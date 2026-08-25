<?php

function handler($request, $response, $args, $container) {
    global $mdb;

    $charparsed = [];
    $totalChars = 0;
    $totalShips = 0;
    try {
        $includes = ['_id' => 0, 'id' => 1, 'ticker' => 1, 'name' => 1, 'corporationID' => 1, 'allianceID' => 1, 'factinoID' => 1, 'secStatus' => 1, 'birthday' => 1];
        $statsIncludes = ['_id' => 0, 'id' => 1, 'shipsDestroyed' => 1, 'shipsLost' => 1, 'dangerRatio' => 1, 'gangRatio' => 1, 'avgGangSize' => 1, 'recentShips' => 1, 'recentShipsUpdated' => 1, 'topShips' => 1, 'topShipsUpdated' => 1, 'affiliates' => 1, 'associates' => 1, 'awoxCount' => 1, 'fc' => 1, 'bait' => 1, 'cyno' => 1, 'gankerCount' => 1, 'activityTags' => 1, 'rankings.recent.all.metrics' => 1];

        $postData = $request->getParsedBody();
        $scan = @$postData['scan'];
        if (strlen($scan) > 50000) {
            $response = $response->withStatus(400);
            return $response->withHeader('Cache-Tag', 'www,scanalyzer,error');
        }
        
        $scan = str_replace(",", "", $scan);
        $scan = str_replace("\\n", ",", $scan);
        $scan = str_replace("\n", ",", $scan);
        $scan = str_replace("'", "'", $scan);
        $scan = str_replace("'", "'", $scan);
        $scan = str_replace('"', "", $scan);
        $scan = explode(',', $scan);
        Util::zout("ScanAlyzer: " . sizeof($scan));

        $parsed = [];
        $typeIDs = [];
        $characterNames = [];
        foreach ($scan as $line) {
            $line = trim($line);
            $line = str_replace("\\t", ",", $line);
            $line = str_replace("   ", ",", $line);
            $split = explode(',', $line);
            $entity = trim($split[0]);
            if (strlen($entity) == 0) continue;

            $parsed[] = ['entity' => $entity, 'split' => $split];
            $characterNames[strtolower($entity)] = true;
            if (is_numeric($entity)) {
                $typeIDs[(int) $entity] = true;
                $candidate = trim(@$split[1]);
                $candidate = explode(' - ', $candidate);
                $candidate = isset($candidate[1]) ? trim($candidate[1]) : trim($candidate[0]);
                if ($candidate != '') $characterNames[strtolower($candidate)] = true;
            }
        }

        $typeInfo = [];
        $characterInfo = [];
        $lookupQuery = [];
        if (sizeof($typeIDs)) $lookupQuery[] = ['type' => 'typeID', 'id' => ['$in' => array_keys($typeIDs)]];
        if (sizeof($characterNames)) $lookupQuery[] = ['type' => 'characterID', 'l_name' => ['$in' => array_keys($characterNames)]];
        if (sizeof($lookupQuery)) {
            $lookupIncludes = $includes + ['type' => 1, 'l_name' => 1, 'categoryID' => 1];
            $query = sizeof($lookupQuery) == 1 ? $lookupQuery[0] : ['$or' => $lookupQuery];
            $rows = $mdb->find('information', $query, [], null, $lookupIncludes);
            foreach ($rows as $row) {
                if ($row['type'] == 'typeID') {
                    $typeInfo[(int) $row['id']] = $row;
                } else {
                    $name = strtolower($row['l_name']);
                    unset($row['type'], $row['l_name'], $row['categoryID']);
                    $characterInfo[$name] = $row;
                }
            }
        }

        $chars = [];
        $corps = [];
        $allis = [];
        $ships = [];
        foreach ($parsed as $parsedRow) {
            $row = null;
            $entity = $parsedRow['entity'];
            $split = $parsedRow['split'];

            $isShip = false;
            if (is_numeric($entity)) { // Is this a ship?
                $row = $typeInfo[(int) $entity] ?? null;
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

                $row = $characterInfo[strtolower($entity)] ?? null;
                if ($row == null) $row = ['name' => $entity, 'id' => -1, 'unknown' => true];

                $row['labels'] = [];
                $totalChars++;

                $chars[] = $row;
                add($corps, $row, 'corporationID');
                add($allis, $row, 'allianceID');
            }
        }

        $characterIDs = [];
        $characterNamesByID = [];
        foreach ($chars as $row) {
            if ($row['id'] > 0) {
                $characterIDs[(int) $row['id']] = true;
                $characterNamesByID[(int) $row['id']] = $row['name'];
            }
        }

        $statsByID = [];
        if (sizeof($characterIDs)) {
            $rows = $mdb->find('statistics', ['type' => 'characterID', 'id' => ['$in' => array_keys($characterIDs)]], [], null, $statsIncludes);
            foreach ($rows as $row) $statsByID[(int) $row['id']] = $row;
        }

        foreach ($statsByID as $stats) {
            foreach ($stats['affiliates'] ?? [] as $affiliate) {
                $allianceID = (int) ($affiliate['allianceID'] ?? 0);
                if ($allianceID > 0) $allis[$allianceID] = 1;
            }
        }

        $shipTypeIDs = [];
        $shipGroupIDs = [];
        foreach ($statsByID as $stats) {
            foreach (['recentShips', 'topShips'] as $field) {
                foreach ($stats[$field] ?? [] as $ship) {
                    $shipTypeID = (int) ($ship['shipTypeID'] ?? 0);
                    $groupID = (int) ($ship['groupID'] ?? 0);
                    if ($shipTypeID > 0) $shipTypeIDs[$shipTypeID] = true;
                    if ($groupID > 0) $shipGroupIDs[$groupID] = true;
                }
            }
        }

        $shipInfo = [];
        $shipGroupNames = [];
        $metadataQuery = [];
        if (sizeof($corps)) $metadataQuery[] = ['type' => 'corporationID', 'id' => ['$in' => array_keys($corps)]];
        if (sizeof($allis)) $metadataQuery[] = ['type' => 'allianceID', 'id' => ['$in' => array_keys($allis)]];
        if (sizeof($shipTypeIDs)) $metadataQuery[] = ['type' => 'typeID', 'id' => ['$in' => array_keys($shipTypeIDs)]];
        if (sizeof($shipGroupIDs)) $metadataQuery[] = ['type' => 'groupID', 'id' => ['$in' => array_keys($shipGroupIDs)]];
        if (sizeof($metadataQuery)) {
            $metadataIncludes = $includes + ['type' => 1, 'groupID' => 1, 'pip' => 1];
            $query = sizeof($metadataQuery) == 1 ? $metadataQuery[0] : ['$or' => $metadataQuery];
            $rows = $mdb->find('information', $query, [], null, $metadataIncludes);
            foreach ($rows as $row) {
                $type = $row['type'];
                unset($row['type']);
                $id = (int) $row['id'];
                if ($type == 'corporationID') $corps[$id] = $row;
                else if ($type == 'allianceID') $allis[$id] = $row;
                else if ($type == 'typeID') $shipInfo[$id] = $row;
                else if ($type == 'groupID') $shipGroupNames[$id] = $row['name'];
            }
        }

        foreach ($chars as &$row) {
            $id = (int) $row['id'];
            $stats = $statsByID[$id] ?? [];
            $stats['characterTags'] = Stats::getCharacterTags($stats, $row);
            $shipLists = ['ships' => $stats['recentShips'] ?? [], 'topShips' => $stats['topShips'] ?? []];
            $affiliates = $stats['affiliates'] ?? [];
            $associates = [];
            foreach ($stats['associates'] ?? [] as $associate) {
                $associateID = (int) ($associate['characterID'] ?? 0);
                if (!isset($characterNamesByID[$associateID])) continue;
                $associate['name'] = $characterNamesByID[$associateID];
                $associates[] = $associate;
            }
            $hasRecentActivity = isset($stats['recentShipsUpdated']);
            unset($stats['id']);
            unset($stats['recentShips'], $stats['recentShipsUpdated'], $stats['topShips'], $stats['topShipsUpdated'], $stats['affiliates'], $stats['associates'], $stats['activityTags'], $stats['rankings']);
            unset($row['birthday']);
            $row['stats'] = $stats;
            $row['affiliates'] = $affiliates;
            $row['associates'] = $associates;

            if (!$hasRecentActivity) $row['inactive'] = true;
            foreach ($shipLists as $rowField => $shipsList) {
                $row[$rowField] = [];
                foreach ($shipsList as $topShip) {
                    $shipTypeID = (int) ($topShip['shipTypeID'] ?? 0);
                    if ($shipTypeID <= 0) continue;
                    $groupID = (int) ($topShip['groupID'] ?? 0) ?: (int) ($shipInfo[$shipTypeID]['groupID'] ?? 0);
                    $topShip['shipName'] = $shipInfo[$shipTypeID]['name'] ?? '';
                    $topShip['pip'] = $shipInfo[$shipTypeID]['pip'] ?? '';
                    $topShip['shipTypeID'] = $shipTypeID;
                    $topShip['groupID'] = $groupID;
                    $topShip['groupName'] = $shipGroupNames[$groupID] ?? '';
                    if ($groupID != 29 && sizeof($row[$rowField]) < 9) $row[$rowField][] = $topShip;
                }
            }
        }
        unset($row);

        $ships = array_values($ships);
        $ret = ['chars' => sortem($chars), 'corps' => $corps, 'allis' => $allis, 'ships' => Info::addInfo($ships), 'totalChars' => $totalChars, 'totalShips' => $totalShips];
        if ($ret['ships'] == null) $ret['ships'] = [];

        $response = $response->withHeader('Content-Type', 'application/json')->withHeader('Cache-Tag', 'www,scanalyzer');
        $response->getBody()->write(json_encode($ret));
        return $response;

    } catch (Exception $e) { 
        Util::zout(print_r($e, true));
        $response = $response->withStatus(500)->withHeader('Content-Type', 'application/json')->withHeader('Cache-Tag', 'www,scanalyzer,error');
        $response->getBody()->write(json_encode(['error' => 'Internal server error']));
        return $response;
    }
}

// Legacy compatibility - call handler if accessed directly
if (!function_exists('handler') || !isset($args)) {
    global $mdb, $redis;

/*if (@$_SESSION['characterID'] <= 0) {
    header("HTTP/1.1 403 Must be logged in to use this feature.");
    return;
}*/

$charparsed = [];
$totalChars = 0;
$totalShips = 0;
$lineCount = 0;

try {

    $includes = ['_id' => 0, 'id' => 1, 'ticker' => 1, 'name' => 1, 'corporationID' => 1, 'allianceID' => 1, 'factinoID' => 1, 'secStatus' => 1];
    $statsIncludes = ['_id' => 0, 'shipsDestroyed' => 1, 'shipsLost' => 1, 'dangerRatio' => 1, 'gangRatio' => 1, 'avgGangSize' => 1, 'labels.ganked.shipsDestroyed' => 1];

    $scan = @$_POST['scan'];
    if (strlen($scan) > 50000) exit();
    $scan = str_replace(",", "", $scan);
    $scan = str_replace("\\n", ",", $scan);
    $scan = str_replace("\n", ",", $scan);
    $scan = str_replace("‘", "'", $scan);
    $scan = str_replace("’", "'", $scan);
    $scan = str_replace('"', "", $scan);
    $scan = explode(',', $scan);
    Util::zout("ScanAlyzer: " . sizeof($scan));

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

            $row = $mdb->findDoc("information", ['type' => 'characterID', 'l_name' => strtolower($entity), 'cacheTime' => 3600], [], $includes);
            if ($row == null) $row = ['name' => $entity, 'id' => -1, 'unknown' => true];

            $row['labels'] = [];

            // do they have activity in the last 90 days
            $doc = $mdb->findDoc("ninetyDays", ['involved.characterID' => $row['id']]);
            if ($doc == null) $row['inactive'] = true;

            $totalChars++;
            $stats = $mdb->findDoc("statistics", ['type' => 'characterID', 'id' => $row['id'], 'cacheTime' => 3600], [], $statsIncludes);
            $row['stats'] = ($stats == null ? [] : $stats);
            $row['stats']['ganked-shipsDestroyed'] = (int) @$stats['labels']['ganked']['shipsDestroyed'];
            unset($row['stats']['labels']);

            $p = ['characterID' => [$row['id']], 'limit' => 6, 'pastSeconds' => 7776000, 'cacheTime' => 3600];
            $shipsTop = [];
            $topShips = Stats::getTop('shipTypeID', $p);
            foreach ($topShips as $topShip) {
                if ($topShip['groupID'] != 29 && sizeof($shipsTop) < 5) $shipsTop[] = $topShip;
            }
            $row['ships'] = $shipsTop;

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

} catch (Exception $e) { Util::zout(print_r($e, true)); }
}

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
