<?php

require_once "../init.php";

$serverVersion = $redis->get("tqServerVersion");
$loadedVersion = $redis->get("zkb:tqServerVersion");
if ($serverVersion == $loadedVersion) {
    $redis->set("zkb:universeLoaded", "true");
    exit();
}

$guzzler = new Guzzler(25, 10);

$guzzler->call("$esiServer/v1/universe/categories/", "categoriesSuccess", "fail");
$guzzler->finish();
$guzzler->call("$esiServer/v1/universe/regions/", "regionsSuccess", "fail");
$guzzler->finish();

$redis->set("zkb:tqServerVersion", $serverVersion);
$redis->set("zkb:universeLoaded", "true");

function fail($guzzler, $params, $error) 
{
    $uri = $params['uri'];
    Util::out("Failure! $uri");
}

function categoriesSuccess($guzzler, $params, $content)
{
    global $esiServer;

    $cats = json_decode($content, true);

    foreach ($cats as $cat) {
        $guzzler->call("$esiServer/v1/universe/categories/$cat/", "categorySuccess", "fail");
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

    $mdb->insertUpdate("information", ['type' => 'categoryID', 'id' => $id], ['name' => $name]);

    foreach ($groups as $group) {
        $guzzler->call("$esiServer/v1/universe/groups/$group/", "groupSuccess", "fail");
    }
}

function groupSuccess($guzzler, $params, $content)
{
    global $mdb, $esiServer;

    $group = json_decode($content, true);
    $id = $group['group_id'];
    $name = $group['name'];
    Util::out("Group $name $id");

    $mdb->insertUpdate("information", ['type' => 'groupID', 'id' => $id], ['name' => $name]);

    foreach ($group['types'] as $type) {
        $guzzler->call("$esiServer/v3/universe/types/$type/", "typeSuccess", "fail", ['categoryID' => $group['category_id']]);
    }
}

function typeSuccess($guzzler, $params, $content)
{
    global $mdb;

    $type = json_decode($content, true);
    $id = $type['type_id'];
    $name = $type['name'];
    $groupID = $type['group_id'];
    $type['groupID'] = $type['group_id'];
    $type['categoryID'] = $params['categoryID'];
    $type['portionSize'] = @$params['portion_size'];
    unset($type['group_id']);
    unset($type['type_id']);
    unset($type['portion_size']);
    Util::out("Type $name $id");

    $mdb->insertUpdate("information", ['type' => 'typeID', 'id' => $id], $type);
}

function regionsSuccess($guzzler, $params, $content)
{
    global $esiServer;

    $regions = json_decode($content, true);

    foreach ($regions as $regionID) {
        $guzzler->call("$esiServer/v1/universe/regions/$regionID/", "regionSuccess", "fail");
    }
}

function regionSuccess($guzzler, $params, $content)
{
    global $mdb, $esiServer;

    $region = json_decode($content, true);
    $name = $region['name'];
    $regionID = (int) $region['region_id'];
    $constellations = $region['constellations'];
    Util::out("Region: $name");

    $mdb->insertUpdate("information", ['type' => 'regionID', 'id' => $regionID], ['name' => $name]);

    foreach ($constellations as $constellation) {
        $guzzler->call("$esiServer/v1/universe/constellations/$constellation/", "constellationSuccess", "fail");
    }
}

function constellationSuccess($guzzler, $params, $content)
{
    global $mdb, $esiServer;

    $const = json_decode($content, true);
    $constID = (int) $const['constellation_id'];
    $name = $const['name'];
    $regionID = $const['region_id'];
    $systems = $const['systems'];
    Util::out("Constellation: $name");

    $mdb->insertUpdate("information", ['type' => 'constellationID', 'id' => $constID], ['name' => $name, 'regionID' => $regionID]);

    foreach ($systems as $system) {
        $guzzler->call("$esiServer/v3/universe/systems/$system/", "systemSuccess", "fail", ['regionID' => $regionID]);
    }
}

function systemSuccess($guzzler, $params, $content)
{
    global $mdb;

    $system = json_decode($content, true);
    $constID = $system['constellation_id'];
    $regionID = $params['regionID'];
    $id = $system['system_id'];
    $name = $system['name'];
    Util::out("System $name $id");
    
    $mdb->insertUpdate("information", ['type' => 'solarSystemID', 'id' => $id], ['name' => $name, 'secClass' => @$system['security_class'], 'secStatus' => $system['security_status']]);
}
