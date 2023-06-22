<?php

use cvweiss\redistools\RedisCache;

class Price
{
    public static function getItemPrice($typeID, $kmDate, $fetch = false, $recalc = false)
    {
        global $mdb, $redis;
        $typeID = (int) $typeID;

        $categoryID = Info::getInfoField("typeID", $typeID, "categoryID");
        if ($categoryID == 91) return 0.01; // Skins are worth nothing

        if ($kmDate == null) {
            $kmDate = date('Y-m-d H:i');
        }

        $price = static::getFixedPrice($typeID, $kmDate);
        if ($price !== null) {
            return $price;
        }
        $price = static::getCalculatedPrice($typeID, $kmDate);
        if ($price !== null) {
            return $price;
        }

        if ($categoryID == 66) { // "Build" all rigs
            $price = Build::getItemPrice($typeID, $kmDate, true, true);
            if ($price > 0.01) return $price;
        }

        // Have we fetched prices for this typeID today?
        $today = date('Ymd', time() - 3601); // Back one hour because of CREST cache
        $fetchedKey = "RC:tq:pricesFetched:$today";
        if ($fetch === true) {
            if ($redis->hGet($fetchedKey, $typeID) != true) {
                static::getCrestPrices($typeID);
            }
            $redis->hSet($fetchedKey, $typeID, true);
            $redis->expire($fetchedKey, 86400);
        }

        // Have we already determined the price for this item at this date?
        $date = date('Y-m-d', strtotime($kmDate) - 3601); // Back one hour because of CREST cache
        $priceKey = "tq:prices:$date";
        $price = $redis->hGet($priceKey, $typeID);
        if ($price != null && $recalc == false) {
            //return $price;
        }

        $marketHistory = $mdb->findDoc('prices', ['typeID' => $typeID]);
        $mHistory = $marketHistory;
        unset($marketHistory['_id']);
        unset($marketHistory['typeID']);
        if ($marketHistory == null) {
            $marketHistory = [];
        }
        krsort($marketHistory);

        $maxSize = 34;
        $useTime = strtotime($date);
        $iterations = 0;
        $priceList = [];
        do {
            $useDate = date('Y-m-d', $useTime);
            $price = @$marketHistory[$useDate];
            if ($price != null) {
                $priceList[] = $price;
            }
            $useTime = $useTime - 86400;
            ++$iterations;
        } while (sizeof($priceList) < $maxSize && $iterations < sizeof($marketHistory));

        asort($priceList);
        if (sizeof($priceList) == $maxSize) {
            // remove 2 endpoints from each end, helps fight against wild prices from market speculation and scams
            $priceList = array_splice($priceList, 2, $maxSize - 2);
            $priceList = array_splice($priceList, 0, $maxSize - 4);
        } elseif (sizeof($priceList) > 6) {
            $priceList = array_splice($priceList, 0, sizeof($priceList) - 2);
        }
        if (sizeof($priceList) == 0) {
            $priceList[] = 0.01;
        }

        $total = 0;
        foreach ($priceList as $price) {
            $total += $price;
        }
        $avgPrice = round($total / sizeof($priceList), 2);
        
        // Don't have a decent price? Let's try to build it!
        if ($avgPrice <= 0.01) $avgPrice = Build::getItemPrice($typeID, $date, true);
        $datePrice = isset($mHistory[$date]) ? $mHistory[$date] : 0;
        if ($datePrice > 0 && $datePrice < $avgPrice) $avgPrice = $datePrice;

        $redis->hSet($priceKey, $typeID, $avgPrice);
        $redis->expire($priceKey, 86400);

        return $avgPrice;
    }

    protected static function getFixedPrice($typeID, $date)
    {
        // Some typeID's have hardcoded prices
        switch ($typeID) {
            case 12478: // Khumaak
            case 34559: // Conflux Element
                return 0.01; // Items that get market manipulated and abused will go here
            case 44265: // Victory Firework
                return 0.01; // Items that drop from sites will go here

            // Items that have been determined to be obnoxiously market
            // manipulated will go here
            case 34558:
            case 34556:
            case 34560:
            case 36902:
            case 34559:
            case 34557:
            case 44264:
                return 0.01;
            case 2834: // Utu
            case 3516: // Malice
            case 11375: // Freki
                return 80000000000; // 80b
            case 3518: // Vangel
            case 3514: // Revenant
            case 32788: // Cambion
            case 32790: // Etana
            case 32209: // Mimir
            case 11942: // Silver Magnate
            case 33673: // Whiptail
                return 100000000000; // 100b
            case 35779: // Imp
            case 42125: // Vendetta
            case 42246: // Caedes
            case 74141: // Geri
                return 120000000000; // 120b
            case 2836: // Adrestia
            case 33675: // Chameleon
            case 35781: // Fiend
            case 45530: // Virtuoso
            case 48636: // Hydra
            case 60765: // Raiju
            case 74316: // Bestla
                return 150000000000; // 150b
            case 33397: // Chremoas
            case 42245: // Rabisu
            case 45649: // Komodo
                return 200000000000; // 200b
            case 45531: // Victor
                return 230000000000;
            case 48635: // Tiamat
            case 60764: // Laelaps
                return 230000000000;
            case 47512: // 'Moreau' Fortizar
            case 45647: // Caiman
                return 60000000000; // 60b 
            case 9860: // Polaris
            case 11019: // Cockroach
                return 1000000000000; // 1 trillion, rare dev ships
            case 42126: // Vanquisher
                return 650000000000;
            case 42241: // Molok
                if ($date <= "2019-07-01") return 350000000000; // 350b 
                return 650000000000;
            // Rare cruisers
            case 11940: // Gold Magnate
		    if ($date <= "2020-01-25") return 500000000; // 500b
		    return 3400000000000;	// 3.2t
            case 635: // Opux Luxury Yacht
            case 11011: // Guardian-Vexor
            case 25560: // Opux Dragoon Yacht
            case 33395: // Moracha
                return 500000000000; // 500b
                // Rare battleships
            case 13202: // Megathron Federate Issue
            case 11936: // Apocalypse Imperial Issue
            case 11938: // Armageddon Imperial Issue
            case 26842: // Tempest Tribal Issue
                return 750000000000; // 750b
            case 26840: // Raven State Issue
                return 2500000000000;
            case 47514: // 'Horizon' Fortizar
                return 60000000000; // Too much market bugginess, hardcoding price
        }

        // Some groupIDs have prices based on their group
        $groupID = Info::getGroupID($typeID);
        switch ($groupID) {
            case 30: // Titans
            case 659: // Supercarriers
                $p = Build::getItemPrice($typeID, $date);
                if ($p > 1) return $p; 
                return;
            case 29: // Capsules
                return 10000; // 10k
        }

        return;
    }

    public static function getCalculatedPrice($typeID, $date)
    {
        switch ($typeID) {
            case 2233: // Gantry
                $gantry = self::getItemPrice(3962, $date, true);
                $nodes = self::getItemPrice(2867, $date, true);
                $modules = self::getItemPrice(2871, $date, true);
                $mainframes = self::getItemPrice(2876, $date, true);
                $cores = self::getItemPrice(2872, $date, true);
                $total = $gantry + (($nodes + $modules + $mainframes + $cores) * 8);

                return $total;
        }

        return;
    }

    public static function getCrestPrices($typeID)
    {
        global $mdb, $esiServer;

        $marketHistory = $mdb->findDoc('prices', ['typeID' => $typeID]);
        if ($marketHistory === null) {
            $marketHistory = ['typeID' => $typeID];
            $mdb->save('prices', $marketHistory);
        }

        $url = "$esiServer/v1/markets/10000002/history/?type_id=$typeID";
        $sso = ZKillSSO::getSSO();
        $json = json_decode($sso->doCall($url), true);

        foreach ($json as $row) {
            $avgPrice = $row['average'];
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
                $market = json_decode($sso->doCall("$esiServer/v1/markets/prices/"), true);
                RedisCache::set($key, $market, 3600);
            }
            $date = date('Y-m-d');
            foreach ($market as $item) {
                if (@$item['type']['id'] == $typeID) {
                    $price = @$item['average_price'];
                    if ($price > 0) $mdb->set('prices', ['typeID' => $typeID], [$date => $price]);
                }
            }
        }
    }
}
