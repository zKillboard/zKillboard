<?php

use cvweiss\redistools\RedisCache;

class Build
{
    public static function getItemPrice($typeID, $kmDate, $fetch = false, $recalc = false)
    {
        global $mdb, $redis;
        $typeID = (int) $typeID;

        $price = self::getFixedPrice($typeID);
        if ($price !== null) return $price;

        $itemName = Info::getInfoField("typeID", $typeID, "name");
        if (strpos($itemName, " SKIN ") !== false) return 0.01;

        if ($kmDate == null) {
            $kmDate = date('Y-m-d');
        } else $kmDate = substr($kmDate, 0, 10);

        $price = $redis->get("zkb:built:$typeID:$kmDate");
        if ($price > 0) return $price;

        $price = $mdb->findField("prices", $kmDate, ['typeID' => $typeID]);
        if ($price > 0) return $price;

        // Have we fetched prices for today?
        $today = date('Y-m-d', time() - 7200);
        //$price = $mdb->findField("prices", "$today", ['typeID' => $typeID]);
        //if ($price != null) return $price;

        $price = self::getBuildPrice($redis, $typeID, $kmDate);
        if ($price === null) {
            if ($redis->get("zkb:prices:$typeID:$today") != "true") {
                // Fetch latest prices...
                $price = self::getCrestPrices($typeID);
                $redis->setex("zkb:prices:$typeID:$today", 86400, "true");
            }
            $row = $mdb->findDoc("prices", ['typeID' => $typeID]);
            unset($row['typeID']);
            unset($row['_id']);
            ksort($row);
            $price = (sizeof($row)) > 0 ? array_pop($row) : 0;
        }
        if ($price == 0) $price = 0.01;
        $redis->setex("zkb:built:$typeID:$kmDate", 86400, $price);
        //$mdb->set("prices", ['typeID' => $typeID], ["$today" => $price]);

        return $price;
    }

    protected static function getFixedPrice($typeID)
    {
        // Some typeID's have hardcoded prices
        switch ($typeID) {
            case 12478: // Khumaak
            case 34559: // Conflux Element
                return 0.01; // Items that get market manipulated and abused will go here
            case 44265: // Victory Firework
                return 0.01; // Items that drop from sites will go here
            case 2834: // Utu
            case 3516: // Malice
            case 11375: // Freki
                return 80000000000; // 80b
            case 3518: // Vangel
            case 32788: // Cambion
            case 32790: // Etana
            case 32209: // Mimir
            case 11942: // Silver Magnate
            case 33673: // Whiptail
                return 100000000000; // 100b
            case 35779: // Imp
            case 42246: // Caedes
                return 120000000000; // 120b
            case 2836: // Adrestia
            case 33675: // Chameleon
            case 35781: // Fiend
            case 45530: // Virtuoso
                return 150000000000; // 150b
            case 33397: // Chremoas
            case 42245: // Rabisu
                return 200000000000; // 200b
            case 45531: // Victor
                return 230000000000;
            case 9860: // Polaris
            case 11019: // Cockroach
                return 1000000000000; // 1 trillion, rare dev ships
                // Rare cruisers
            case 11940: // Gold Magnate
            case 635: // Opux Luxury Yacht
            case 11011: // Guardian-Vexor
            case 25560: // Opux Dragoon Yacht
                return 500000000000; // 500b
                // Rare battleships
            case 13202: // Megathron Federate Issue
            case 26840: // Raven State Issue
            case 11936: // Apocalypse Imperial Issue
            case 11938: // Armageddon Imperial Issue
            case 26842: // Tempest Tribal Issue
                return 750000000000; // 750b
        }

        // Some groupIDs have hardcoded prices
        $groupID = Info::getGroupID($typeID);
        switch ($groupID) {
            //case 30: // Titans
                //return 100000000000; // 100b
            //case 659: // Supercarriers
                //return 20000000000; // 20b
            case 29: // Capsules
                return 10000; // 10k
        }

        return;
    }

    protected static function getCrestPrices($typeID)
    {
        global $mdb, $esiServer;

        $marketHistory = $mdb->findDoc('prices', ['typeID' => $typeID]);
        if ($marketHistory === null) {
            $marketHistory = ['typeID' => $typeID];
            $mdb->save('prices', $marketHistory);
        }

        $url = "$esiServer/v1/markets/10000002/history/?type_id=$typeID";
        Log::log("Fetching $url");
        $json = CrestTools::getJSON($url);

        $price = 0;
        foreach ($json as $row) {
            $avgPrice = $row['average'];
            $price = $avgPrice;
            $date = substr($row['date'], 0, 10);
            if (isset($marketHistory[$date])) {
                continue;
            }
            $mdb->set('prices', ['typeID' => $typeID], [$date => $avgPrice]);
        }
        if (sizeof($json) == 0) {
            $key = "zkb:market:" . date('H');
            $market = RedisCache::get($key);
            if ($market == null) {
                $market = CrestTools::getJSON("$esiServer/v1/markets/prices/");
                RedisCache::set($key, $market, 3600);
            }
            $date = date('Y-m-d');
            if (sizeof($market) > 0) foreach ($market as $item) {
                if ($item['type_id'] == $typeID) {
                    $price = @$item['adjustedPrice'];
                    if ($price > 0) $mdb->set('prices', ['typeID' => $typeID], [$date => $price]);
                }
            }
        }
        return $price;
    }

    protected static function getBuildPrice($redis, $typeID, $kmDate)
    {
        $bp = self::getBlueprint($redis, $typeID);
        if ($bp == null || $bp['reqs'] == null) return null;

        $price = 0;
        foreach ($bp['reqs'] as $reqTypeID => $qty) {
            $p = self::getItemPrice($reqTypeID, $kmDate);
            $p = $p * $qty;
            $price += $p;
        }
        $price = ($price / max(1, $bp['quantity']));
        return $price;
    }

    protected static function import_reqs($redis)
    {
        $rKey = "redis:build:check:" . date('Ymd');
        if ($redis->get($rKey) == "true") return;
        Util::out("Importing http://sde.zzeve.com/industryActivityMaterials.json");
        $raw = file_get_contents("http://sde.zzeve.com/industryActivityMaterials.json");
        $json = json_decode($raw, true);
        $raw = null;

        $builds = [];
        foreach ($json as $row) {
            if ($row['activityID'] != 1) continue;
            $typeID = $row['typeID'];
            $reqs = isset($builds[$typeID]) ? $builds[$typeID] : [];
            $reqs[$row['materialTypeID']] = max(1, ceil(0.9 * $row['quantity']));
            $builds[$typeID] = $reqs;
        }
        foreach ($builds as $build => $reqs) {
            $redis->setex("zkb:build:" . $build, 160000, serialize($reqs));
        }
        $redis->setex($rKey, 86400, "true");
    }

    protected static function getBlueprint($redis, $typeID)
    {
        $rKey = "redis:build_qty:check" . date('Ymd');
        if ($redis->get($rKey) != "true") {
            Util::out("Fetching http://sde.zzeve.com/industryActivityProducts.json");
            $raw = file_get_contents("http://sde.zzeve.com/industryActivityProducts.json");
            $json = json_decode($raw, true);
            $raw = null;

            foreach ($json as $row) {
                $redis->setex("zkb:blueprint:" . $row['productTypeID'], 160000, serialize($row));
            }
            $redis->setex($rKey, 86400, "true");
        }
        self::import_reqs($redis);

        $raw = $redis->get("zkb:blueprint:" . $typeID);
        if ($raw == null) return null;
        $bp = unserialize($raw);
        $bp['reqs'] = unserialize($redis->get("zkb:build:" . $bp['typeID']));
        
        return $bp;
    }
}
