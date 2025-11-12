<?php

require_once "../init.php";

$kvc = new KVCache($mdb, $redis);

if ($redis->get("zkb:noapi") == "true") exit();
if ($redis->get("tqCountInt") < 100 || $redis->get("zkb:420ed") == "true") exit();

$serverVersion = $kvc->get("tqServerVersion");
$loadedVersion = $kvc->get("zkb:tqServerVersion");

// get distinct shipTypeIDs to adjust published on typeIDs
$distinctTypeIDs = $mdb->getCollection('killmails')->distinct('involved.shipTypeID');

if ($serverVersion != "" && $serverVersion == $loadedVersion && $kvc->get("zkb:universeLoaded") == true) {
    exit();
}

Util::out("Prepping to load universe");
$kvc->del("zkb:universeLoaded");
$kvc->del("zkb:tqServerVersion");
$guzzler = new Guzzler(25, 10);

$guzzler->call("$esiServer/universe/regions/", "regionsSuccess", "fail");
$guzzler->finish();
$guzzler->call("$esiServer/universe/categories/", "categoriesSuccess", "fail");
$guzzler->finish();

$kvc->set("zkb:tqServerVersion", $serverVersion);
$kvc->set("zkb:universeLoaded", "true");

function fail($guzzler, $params, $error) 
{
    $uri = $params['uri'];
    Util::out("Failure! $uri");
    $guzzler->call($uri, $params['fulfilled'], $params['rejected']);
    exit();
}

function categoriesSuccess($guzzler, $params, $content)
{
    global $esiServer;

    $cats = json_decode($content, true);

    foreach ($cats as $cat) {
        $guzzler->call("$esiServer/universe/categories/$cat/", "categorySuccess", "fail");
    }
}

function categorySuccess($guzzler, $params, $content)
{
    global $mdb, $esiServer;

    $cat = json_decode($content, true);
    $id = $cat['category_id'];
    $name = $cat['name'];
    $groups = $cat['groups'];
    Util::out("Category $name $id");

    $mdb->insertUpdate("information", ['type' => 'categoryID', 'id' => $id], $cat);

    foreach ($groups as $group) {
        $guzzler->call("$esiServer/universe/groups/$group/", "groupSuccess", "fail", ['categoryID' => $id]);
    }
}

function groupSuccess($guzzler, $params, $content)
{
    global $mdb, $esiServer;

    $group = json_decode($content, true);
    $id = $group['group_id'];
    $name = $group['name'];
    Util::out("Group $name $id");

    $update = $group;
    $update['categoryID'] = $params['categoryID'];
    $mdb->insertUpdate("information", ['type' => 'groupID', 'id' => $id], $update);

    foreach ($group['types'] as $type) {
        $guzzler->call("$esiServer/universe/types/$type/", "typeSuccess", "fail", ['categoryID' => $group['category_id']]);
    }
}

function typeSuccess($guzzler, $params, $content)
{
    global $mdb, $distinctTypeIDs;

    $type = json_decode($content, true);
    $id = $type['type_id'];
    $name = $type['name'];
    $groupID = $type['group_id'];
    $type['groupID'] = $type['group_id'];
    $type['categoryID'] = $params['categoryID'];
    $type['portionSize'] = @$params['portion_size'];

	// check the distinctTypeIDs array to see if this typeID has kills
	$hasKills = array_search($id, $distinctTypeIDs) !== false;
	if ($id > 0 && $hasKills && $type['published'] == false) {
		Util::out("Type {$type['name']} $id - has kills, setting published to true");
		$type['published'] = true;
	} else Util::out("Type $name $id");
	
    $mdb->insertUpdate("information", ['type' => 'typeID', 'id' => $id], $type);
}

function regionsSuccess($guzzler, $params, $content)
{
    global $esiServer;

    $regions = json_decode($content, true);

    foreach ($regions as $regionID) {
        $guzzler->call("$esiServer/universe/regions/$regionID/", "regionSuccess", "fail");
    }
}

function regionSuccess($guzzler, $params, $content)
{
    global $serverVersion, $mdb, $esiServer;

    $region = json_decode($content, true);
    $regionID = (int) $region['region_id'];
    $name = ($regionID >= 12000000 && $regionID < 13000000) ? Info::getMangledSystemName($regionID, $regionID)  : $region['name'];
    $constellations = $region['constellations'];
    Util::out("Region: $name");

    $mdb->insertUpdate("information", ['type' => 'regionID', 'id' => $regionID], $region);
    $mdb->insertUpdate("geography", ['type' => 'regionID', 'id' => $regionID, 'serverVersion' => $serverVersion], $region);

    foreach ($constellations as $constellation) {
        $guzzler->call("$esiServer/universe/constellations/$constellation/", "constellationSuccess", "fail");
    }
}

function constellationSuccess($guzzler, $params, $content)
{
    global $mdb, $esiServer, $serverVersion;

    $const = json_decode($content, true);
    $constID = (int) $const['constellation_id'];
    $name = ($constID >= 22000000 && $constID < 23000000) ? Info::getMangledSystemName($constID, 0) : $const['name'];
    $regionID = $const['region_id'];
    $systems = $const['systems'];
    Util::out("Constellation: $name");

    $update = $const;
    $update['regionID'] = $regionID;
    $mdb->insertUpdate("information", ['type' => 'constellationID', 'id' => $constID], $update);
    $mdb->insertUpdate("geography", ['type' => 'constellationID', 'id' => $constID, 'serverVersion' => $serverVersion], $update);

    foreach ($systems as $system) {
        $guzzler->call("$esiServer/universe/systems/$system/", "systemSuccess", "fail", ['regionID' => $regionID, 'constellationID' => $constID]);
    }
}

function systemSuccess($guzzler, $params, $content)
{
    global $mdb, $esiServer, $serverVersion;

    $system = json_decode($content, true);
    $constID = $system['constellation_id'];
    $regionID = $params['regionID'];
    $id = $system['system_id'];
    $name = ($id >= 32000000 && $id < 33000000) ? Info::getMangledSystemName($id, 0) : $system['name'];
    Util::out("System $name $id");
    
    $update = array_merge($system, ['name' => $name, 'secClass' => @$system['security_class'], 'secStatus' => $system['security_status'], 'regionID' => $params['regionID'], 'constellationID' => $params['constellationID']]);
    $mdb->insertUpdate("information", ['type' => 'solarSystemID', 'id' => $id], $update);
    $mdb->insertUpdate("geography", ['type' => 'solarSystemID', 'id' => $id, 'serverVersion' => $serverVersion], $update);

    if (isset($system['star_id'])) $guzzler->call("$esiServer/latest/universe/stars/" . $system['star_id'] . "/", "starSuccess", "fail", ['starID' => $system['star_id']]);
}

function starSuccess($guzzler, $params, $content)
{
    global $mdb, $serverVersion; 

    $star = json_decode($content, true);
    $starID = $params['starID'];
    $id = (int) $params['starID'];
    if ($id <= 1) return;

    $star['type'] = 'starID';
    $star['id'] = $id;
    Util::out("Star $id");

    $mdb->insertUpdate("information", ['type' => 'starID', 'id' => $id], $star);
    $mdb->insertUpdate("geography", ['type' => 'starID', 'id' => $id, 'serverVersion' => $serverVersion], $star);
}
