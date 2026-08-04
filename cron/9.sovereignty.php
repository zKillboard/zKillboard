<?php

require_once '../init.php';

$options = getopt('', ['force']);
$force = isset($options['force']);

if (!$force && $redis->set("zkb:sovereignty:fetch", "true", ['nx', 'ex' => 300]) === false) exit();
if ($kvc->get("zkb:noapi") == "true") exit($force ? "No API atm, force ignored": "");
if ($redis->get("tqCountInt") < 100 || $redis->get("zkb:420ed") == "true") exit($force ? "TQ count too low, force ignored": "");

$snapshot = (array) ($kvc->get('zkb:sovereignty:map') ?? []);
$headers = ['X-Compatibility-Date' => '2026-07-21'];
if (!$force) {
    if (!empty($snapshot['etag'])) $headers['If-None-Match'] = $snapshot['etag'];
    if (!empty($snapshot['lastModified'])) $headers['If-Modified-Since'] = $snapshot['lastModified'];
}

$guzzler = new Guzzler();
$guzzler->call("$esiServer/sovereignty/systems/", "success", "fail", [], $headers);
$guzzler->finish();

function success(&$guzzler, &$params, $content)
{
    global $kvc, $mdb, $redis;

    if ($content == "") {
        if (@$params['STATUS_CODE'] == 304) $redis->setex("zkb:sovereignty:fetch", 3600, "true");
        return;
    }

    $json = json_decode($content, true);
    if (!is_array($json) || !isset($json['solar_systems']) || !is_array($json['solar_systems'])) return;

    $allianceSystemIDs = [];
    $systemLookup = [];
    $systemIDs = [];
    foreach ($json['solar_systems'] as $row) {
        $allianceID = (int) ($row['claim']['alliance']['alliance_id'] ?? 0);
        $systemID = (int) ($row['solar_system_id'] ?? 0);
        if ($allianceID <= 0 || $systemID <= 0) continue;
        $allianceSystemIDs[$allianceID][] = $systemID;
        $systemLookup[$systemID] = $allianceID;
        $systemIDs[] = $systemID;
    }

    $systemDetails = [];
    $constellationIDs = [];
    $regionIDs = [];
    $systems = $mdb->find('information', ['type' => 'solarSystemID', 'id' => ['$in' => $systemIDs]], [], null, ['id' => 1, 'name' => 1, 'constellationID' => 1, 'regionID' => 1, 'secStatus' => 1]);
    foreach ($systems as $system) {
        $constellationIDs[] = (int) ($system['constellationID'] ?? 0);
        $regionIDs[] = (int) ($system['regionID'] ?? 0);
        $systemDetails[(int) $system['id']] = $system;
    }

    $constellationNames = [];
    $constellations = $mdb->find('information', ['type' => 'constellationID', 'id' => ['$in' => array_values(array_unique($constellationIDs))]], [], null, ['id' => 1, 'name' => 1]);
    foreach ($constellations as $constellation) $constellationNames[(int) $constellation['id']] = $constellation['name'];

    $regionNames = [];
    $regions = $mdb->find('information', ['type' => 'regionID', 'id' => ['$in' => array_values(array_unique($regionIDs))]], [], null, ['id' => 1, 'name' => 1]);
    foreach ($regions as $region) $regionNames[(int) $region['id']] = $region['name'];

    $allianceDetails = [];
    $allianceIDs = array_keys($allianceSystemIDs);
    $allianceRows = $mdb->find('information', ['type' => 'allianceID', 'id' => ['$in' => $allianceIDs]], [], null, ['id' => 1, 'name' => 1, 'ticker' => 1]);
    foreach ($allianceRows as $alliance) $allianceDetails[(int) $alliance['id']] = $alliance;

    $alliances = [];
    $allianceLookup = [];
    $leaderboard = [];
    foreach ($allianceSystemIDs as $allianceID => $ownedSystemIDs) {
        $ownedSystems = [];
        foreach ($ownedSystemIDs as $systemID) {
            $system = $systemDetails[$systemID] ?? [];
            $constellationID = (int) ($system['constellationID'] ?? 0);
            $regionID = (int) ($system['regionID'] ?? 0);
            $ownedSystems[] = [
                'solarSystemID' => $systemID,
                'solarSystemName' => $system['name'] ?? "System $systemID",
                'constellationID' => $constellationID,
                'constellationName' => $constellationNames[$constellationID] ?? 'Unknown',
                'regionID' => $regionID,
                'regionName' => $regionNames[$regionID] ?? 'Unknown',
                'secStatus' => (double) ($system['secStatus'] ?? 0),
            ];
        }
        usort($ownedSystems, function ($a, $b) {
            return strcasecmp($a['regionName'], $b['regionName']) ?: strcasecmp($a['constellationName'], $b['constellationName']) ?: strcasecmp($a['solarSystemName'], $b['solarSystemName']);
        });

        $alliance = $allianceDetails[$allianceID] ?? [];
        $alliances[$allianceID] = ['systems' => $ownedSystems];
        $allianceLookup[$allianceID] = count($ownedSystemIDs);
        $leaderboard[] = [
            'allianceID' => $allianceID,
            'allianceName' => $alliance['name'] ?? "Alliance $allianceID",
            'ticker' => $alliance['ticker'] ?? '',
            'systems' => count($ownedSystemIDs),
        ];
    }
    ksort($alliances);
    ksort($systemLookup);
    usort($leaderboard, function ($a, $b) {
        return $b['systems'] <=> $a['systems'] ?: strcasecmp($a['allianceName'], $b['allianceName']);
    });

    $headers = $params['HEADERS'] ?? [];
    $updatedAt = isset($headers['last-modified'][0]) ? strtotime($headers['last-modified'][0]) : time();
    $update = ['alliances' => $alliances, 'leaderboard' => $leaderboard, 'totals' => ['systems' => count($systemIDs), 'alliances' => count($allianceSystemIDs)], 'updatedAt' => $updatedAt ?: time()];
    if (isset($headers['etag'][0])) $update['etag'] = $headers['etag'][0];
    if (isset($headers['last-modified'][0])) $update['lastModified'] = $headers['last-modified'][0];
    $kvc->set('zkb:sovereignty:map', $update);
    $kvc->set('zkb:sovereignty:alliances', $allianceLookup);
    $kvc->set('zkb:sovereignty:systems', $systemLookup);
    $redis->setex("zkb:sovereignty:fetch", 3333, "true");
    $redis->sadd("queueCacheTags", "sovereignty");
}

function fail(&$guzzler, &$params, &$ex)
{
    Util::out("Sovereignty fetch failed with http code " . $ex->getCode());
}
