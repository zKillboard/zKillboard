<?php

use cvweiss\redistools\RedisCache;

class Build
{
    public static function getItemPrice($typeID, $kmDate, $fetch = false, $recalc = false)
    {
        global $mdb, $redis;
        $typeID = (int) $typeID;

        $itemName = Info::getInfoField("typeID", $typeID, "name");
        if (strpos($itemName, " SKIN ") !== false) return 0.01;

        if ($kmDate == null) {
            $kmDate = date('Y-m-d');
        } else $kmDate = substr($kmDate, 0, 10);

        $price = $redis->get("zkb:built:$typeID:$kmDate");
        if ($price > 0) return $price;

        $today = date('Y-m-d', time() - 7200);
        $price = self::getBuildPrice($redis, $typeID, $kmDate);
        if ($price === null) {
            if ($redis->get("zkb:prices:$typeID:$today") != "true") {
                // Fetch latest prices...
                $price = Price::getCrestPrices($typeID);
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

    protected static function getBuildPrice($redis, $typeID, $kmDate)
    {
        $bp = self::getBlueprint($redis, $typeID);
        if ($bp == null || $bp['reqs'] == null) return null;

        $price = 0;
        foreach ($bp['reqs'] as $reqTypeID => $qty) {
            $p = Price::getItemPrice($reqTypeID, $kmDate);
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
