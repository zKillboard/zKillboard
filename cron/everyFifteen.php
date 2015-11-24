<?php

require_once '../init.php';

global $redis;

if (date('i') % 15 != 0) exit();

$p = array();
$numDays = 7;
$p['limit'] = 10;
$p['pastSeconds'] = $numDays * 86400;
$p['kills'] = true;

$redis->setex('RC:Kills5b+', 3600, json_encode(Kills::getKills(array('iskValue' => 5000000000), true, false)));
$redis->setex('RC:Kills10b+', 3600, json_encode(Kills::getKills(array('iskValue' => 10000000000), true, false)));

$redis->setex('RC:TopChars', 3600, json_encode(Info::doMakeCommon('Top Characters', 'characterID', getStats('characterID'))));
$redis->setex('RC:TopCorps', 3600, json_encode(Info::doMakeCommon('Top Corporations', 'corporationID', getStats('corporationID'))));
$redis->setex('RC:TopAllis', 3600, json_encode(Info::doMakeCommon('Top Alliances', 'allianceID', getStats('allianceID'))));
$redis->setex('RC:TopShips', 3600, json_encode(Info::doMakeCommon('Top Ships', 'shipTypeID', getStats('shipTypeID'))));
$redis->setex('RC:TopSystems', 3600, json_encode(Info::doMakeCommon('Top Systems', 'solarSystemID', getStats('solarSystemID'))));
$redis->setex('RC:TopLocations', 3600, json_encode(Info::doMakeCommon('Top Locations', 'locationID', getStats('locationID'))));
$redis->setex('RC:TopIsk', 3600, json_encode(Stats::getTopIsk(array('pastSeconds' => ($numDays * 86400), 'limit' => 5))));

// Cleanup subdomain stuff
Db::execute('update zz_subdomains set adfreeUntil = null where adfreeUntil < now()');
Db::execute("update zz_subdomains set banner = null where banner = ''");
Db::execute("delete from zz_subdomains where adfreeUntil is null and banner is null and (alias is null or alias = '')");

function getStats($column)
{
    $result = Stats::getTop($column, ['isVictim' => false, 'pastSeconds' => 604800]);

    return $result;
}

$redis->keys('*'); // Helps purge expired ttl's
