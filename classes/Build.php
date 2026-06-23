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
        if ($recalc == false && $price > 0) return $price;

        $price = self::getBuildPrice($redis, $typeID, $kmDate);
        if ($price === null) return 0.01;
        $redis->setex("zkb:built:$typeID:$kmDate", 86400, $price);

        return $price;
    }

    protected static function getBuildPrice($redis, $typeID, $kmDate)
    {
        $bp = self::getBlueprint($redis, $typeID);
        if ($bp == null || $bp['reqs'] == null) return null;

        $price = 0;
        foreach ($bp['reqs'] as $reqTypeID => $qty) {
            if ($typeID == $reqTypeID) continue;
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

        self::import_blueprints($redis);
        $redis->setex($rKey, 86400, "true");
    }

    public static function getBlueprint($redis, $typeID)
    {
        $rKey = "redis:build_qty:check" . date('Ymd');
        if ($redis->get($rKey) != "true") {
            self::import_blueprints($redis);
            $redis->setex($rKey, 86400, "true");
        }
        self::import_reqs($redis);

        $raw = $redis->get("zkb:blueprint:" . $typeID);
        if ($raw == null) return null;
        $bp = unserialize($raw);
        $bp['reqs'] = unserialize($redis->get("zkb:build:" . $bp['typeID']));

        return $bp;
    }

    protected static function import_blueprints($redis)
    {
        global $mdb;

        $rKey = "redis:build_import:check:" . date('Ymd');
        if ($redis->get($rKey) == "true") return;

        Util::zout("Importing build data from sde_blueprints");
        $rows = $mdb->find('sde_blueprints');
        foreach ($rows as $row) {
            $bp = self::normalize_blueprint($row);
            if ($bp == null) continue;

            $redis->setex("zkb:blueprint:" . $bp['productTypeID'], 160000, serialize($bp));
            $redis->setex("zkb:build:" . $bp['typeID'], 160000, serialize($bp['reqs']));
        }
        $redis->setex($rKey, 86400, "true");
    }

    protected static function normalize_blueprint($row)
    {
        $manufacturing = $row['activities']['manufacturing'] ?? null;
        if ($manufacturing == null && isset($row['activities'][1])) $manufacturing = $row['activities'][1];
        if ($manufacturing == null) return null;

        $products = $manufacturing['products'] ?? [];
        $materials = $manufacturing['materials'] ?? [];
        if (sizeof($products) == 0 || sizeof($materials) == 0) return null;

        $blueprintTypeID = $row['blueprintTypeID'] ?? $row['typeID'] ?? $row['key'] ?? $row['_key'] ?? null;
        if (!is_numeric($blueprintTypeID)) return null;
        $blueprintTypeID = (int) $blueprintTypeID;

        $reqs = [];
        foreach ($materials as $material) {
            $materialTypeID = $material['materialTypeID'] ?? $material['typeID'] ?? null;
            $quantity = $material['quantity'] ?? null;
            if (!is_numeric($materialTypeID) || !is_numeric($quantity)) continue;
            $reqs[(int) $materialTypeID] = max(1, ceil(0.9 * (int) $quantity));
        }
        if (sizeof($reqs) == 0) return null;

        $product = array_shift($products);
        $productTypeID = $product['productTypeID'] ?? $product['typeID'] ?? null;
        $quantity = $product['quantity'] ?? 1;
        if (!is_numeric($productTypeID)) return null;

        return [
            'typeID' => $blueprintTypeID,
            'activityID' => 1,
            'productTypeID' => (int) $productTypeID,
            'quantity' => max(1, (int) $quantity),
            'reqs' => $reqs,
        ];
    }
}
