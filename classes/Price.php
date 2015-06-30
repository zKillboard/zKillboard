<?php

class Price
{
    /**
     * Obtain the price of an item.
     *
     * @static
     *
     * @param	$typeID     int  The typeID of the item
     * @param   $date       date The date of the item price value
     * @param   $doPopulate bool If set, retrieve the market values from CCP
     *
     * @return float The price of the item.
     */
    public static function getItemPrice($typeID, $date, $doPopulate = false)
    {
        if (in_array($typeID, array(588, 596, 601, 670, 606, 33328))) {
            return 10000;
        } // Pods and noobships
        if (in_array($typeID, array(25, 51, 29148, 3468))) {
            return 1;
        } // Male Corpse, Female Corpse, Bookmarks, Plastic Wrap

        $price = static::getMarketPrice($typeID, $date, $doPopulate);
        if ($price == 0) {
            $price = 0.01;
        } // Give up

        return $price;
    }

    /**
     * @static
     *
     * @param	$typeID     int  The typeID of the item
     * @param   $date       date The date of the item price value
     * @param   $doPopulate bool If set, retrieve the market values from CCP
     *
     * @return float The price of the item.
     */
    protected static function getMarketPrice($typeID, $date, $doPopulate)
    {
        if ($doPopulate) {
            static::doPopulatePrice($typeID, $date);
        }
        $price = Db::queryField('select truncate(avg(avgPrice), 2) avgPrice from zz_item_price_lookup where typeID = :typeID and priceDate >= date_sub(:date, interval 3 day) and priceDate <= :date', 'avgPrice', array(':typeID' => $typeID, ':date' => $date), 3600);
        if ($price == 0) {
            $price = Db::queryField('select avgPrice from zz_item_price_lookup where typeID = :typeID order by abs(datediff(date(date_sub(:date, interval 1 day)), date(priceDate))) limit 1', 'avgPrice', array(':typeID' => $typeID, ':date' => $date), 3600);
        }
        if ($price != null) {
            return $price;
        }

        return 0;
    }

    protected static function doPopulatePrice($typeID, $date)
    {
        $todaysLookup = 'CREST-Market:'.date('Ymd');
        $todaysLookupTypeID = $todaysLookup.":$typeID";

        $isDone = (bool) Storage::retrieve($todaysLookupTypeID, false);
        if ($typeID != 2233 && $isDone) {
            return;
        }

        static::doPopulateRareItemPrices($todaysLookup); // Populate rare items and today's lookup and do some cleanup

        if ($typeID == 2233) {
            $gantry = self::getItemPrice(3962, $date, true);
            $nodes = self::getItemPrice(2867, $date, true);
            $modules = self::getItemPrice(2871, $date, true);
            $mainframes = self::getItemPrice(2876, $date, true);
            $cores = self::getItemPrice(2872, $date, true);
            $total = $gantry + (($nodes + $modules + $mainframes + $cores) * 8);
            Db::execute('replace into zz_item_price_lookup (typeID, priceDate, lowPrice, avgPrice, highPrice) values (:typeID, :date, :low, :avg, :high)', array(':typeID' => $typeID, ':date' => $date, ':low' => $total, ':avg' => $total, ':high' => $total));
            Storage::store($todaysLookupTypeID, 'true'); // Add today's lookup entry for this item
            return $total;
        }

        //Log::log("Fetching price for $typeID: " . Info::getItemName($typeID));
        $url = "https://public-crest.eveonline.com/market/10000002/types/$typeID/history/";
        $raw = Util::getData($url);
        $json = json_decode($raw, true);
        if (isset($json['items'])) {
            foreach ($json['items'] as $row) {
                $hasRow = Db::queryField('select count(1) count from zz_item_price_lookup where typeID = :typeID and priceDate = :date', 'count', array(':typeID' => $typeID, ':date' => $row['date']));
                if ($hasRow == 0) {
                    Db::execute('insert ignore into zz_item_price_lookup (typeID, priceDate, lowPrice, avgPrice, highPrice) values (:typeID, :date, :low, :avg, :high)', array(':typeID' => $typeID, ':date' => $row['date'], ':low' => $row['lowPrice'], ':avg' => $row['avgPrice'], ':high' => $row['highPrice']));
                }
            }
        }
        Storage::store($todaysLookupTypeID, 'true'); // Add today's lookup entry for this item
    }

    /**
     * Enters values into the lookup table that are not generally found on the market.
     *
     * @pararm $todaysLookup string Today's lookup value
     */
    protected static function doPopulateRareItemPrices($todaysLookup)
    {
        global $mdb;

        $isDone = (bool) Storage::retrieve($todaysLookup, false);
        if ($isDone) {
            return;
        }

        // Base lookups for today have been populated - do it here to allow later recursion
        Storage::store($todaysLookup, 'true');

        $motherships = $mdb->find('information', ['type' => 'typeID', 'groupID' => 659]);
        if (sizeof($motherships) == 0) {
            exit('no motherships, bailing');
        } // Haven't loaded all data yet, bail!
        foreach ($motherships as $mothership) {
            $typeID = $mothership['typeid'];
            if ($typeID == 3514) {
                continue;
            }
            static::setPrice($typeID, 20000000000); // 20b
        }
        static::setPrice(3514, 100000000000); // Revenant, 100b

        $titans = $mdb->find('information', ['type' => 'typeID', 'groupID' => 30]);
        if (sizeof($titans) == 0) {
            exit('no titans, bailing');
        } // Haven't loaded all data yet, bail!
        foreach ($titans as $titan) {
            $typeID = $titan['typeid'];
            static::setPrice($typeID, 100000000000); // 100b
        }

        // We don't need daily prices on the following ships...
        Db::execute('delete from zz_item_price_lookup where typeID in (2834, 3516, 11375, 33397, 32788, 2836, 3518, 32790, 33395, 32209, 33673, 33675, 11940, 11942, 635, 11011, 25560, 13202, 26840, 11936, 11938, 26842)');

        $tourneyFrigates = array(
                2834, // Utu
                3516, // Malice
                11375, // Freki
                );
        foreach ($tourneyFrigates as $typeID) {
            static::setPrice($typeID, 80000000000);
        } // 80b
        static::setPrice(33397, 120000000000); // Chremoas, 120b
        static::setPrice(32788, 100000000000); // Cambion, 100b

        static::setPrice(2836, 150000000000); // Adrestia, 150b
        static::setPrice(3518,  90000000000); // Vangel, 90b
        static::setPrice(32790, 100000000000); // Etana, 100b
        static::setPrice(33395, 125000000000); // Moracha, 125b
        static::setPrice(32209, 100000000000); // Mimir, 100b

        // AT XII Prizes
        static::setPrice(33675, 120000000000); // Chameleon
        static::setPrice(33673, 100000000000); // Whiptail

        // Rare CCP Ships (1 trillion! cuz why not)
        static::setPrice(9860, 1000000000000); // Polaris
        static::setPrice(11019, 1000000000000); // Cockroach

        $rareCruisers = array(// Ships we should never see get blown up!
                11940, // Gold Magnate
                11942, // Silver Magnate
                635, // Opux Luxury Yacht
                11011, // Guardian-Vexor
                25560// Opux Dragoon Yacht
                );
        foreach ($rareCruisers as $typeID) {
            static::setPrice($typeID, 500000000000);
        } // 500b

        $rareBattleships = array(// More ships we should never see get blown up!
                13202, // Megathron Federate Issue
                26840, // Raven State Issue
                11936, // Apocalypse Imperial Issue
                11938, // Armageddon Imperial Issue
                26842// Tempest Tribal Issue
                );
        foreach ($rareBattleships as $typeID) {
            static::setPrice($typeID, 750000000000);
        } // 750b

        // Clear all older lookup entries and leave today's lookup entries
        Db::execute("delete from zz_storage where locker not like '$todaysLookup%' and locker like 'CREST-Market%'");
    }

    protected static function setPrice($typeID, $price, $low = -1, $high = -1)
    {
        if ($low == -1) {
            $low = $price;
        }
        if ($high == -1) {
            $high = $price;
        }
        Db::execute('replace into zz_item_price_lookup (typeID, priceDate, lowPrice, avgPrice, highPrice) values (:typeID, date(now()), :low, :avg, :high)', array(':typeID' => $typeID, ':low' => $price, ':avg' => $low, ':high' => $high));
    }
}
