<?php

use cvweiss\redistools\RedisCache;

/**
 * General stuff for getting kills and manipulating them.
 */
class Kills
{
    /**
     * Gets killmails.
     *
     * @param $parameters an array of parameters to fetch mails for
     * @param $allTime gets all mails from the beginning of time or not
     *
     * @return array
     */
    public static function getKills($parameters = array(), $allTime = true, $includeKillDetails = true, $onlyVicAndFinal = false)
    {
        $kills = MongoFilter::getKills($parameters);

        if ($includeKillDetails == false) {
            return $kills;
        }

        return Kills::getDetails($kills, $onlyVicAndFinal);
    }   

    public static function getDetails($kills, $onlyVicAndFinal = false)
    {
        global $mdb, $redis;

        if ($kills == null) return [];

        $details = [];
        foreach ($kills as $kill) {
            $killID = (isset($kill['killID']) ? (int) $kill['killID'] : (int) $kill);
            //$redis->setex("zkb:killlistrow:" . $killID, 3660, "true");
            $killHashKey = "killmail_cache:$killID:$onlyVicAndFinal";

            $killmail = null;
            $raw = $redis->get($killHashKey);
            if ($raw != null) $killmail = unserialize($raw);

            if ($killmail == null) {
                $killmail = $mdb->findDoc('killmails', ['killID' => $killID]);
                $killmail['victim'] = $killmail['involved'][0];
                $killmail['victim']['killID'] = $killID;
                foreach ($killmail['involved'] as $inv) {
                    if (@$inv['finalBlow'] === true) {
                        $killmail['finalBlow'] = $inv;
                        break;
                    }
                }
                $killmail['finalBlow']['killID'] = $killID;

                if ($onlyVicAndFinal) unset($killmail['involved']);

                Info::addInfo($killmail);
                unset($killmail['_id']);

                $redis->setex($killHashKey, 30, serialize($killmail));
            }
            $details[$killID] = $killmail;
        }

        return $details;
    }

    /**
     * Merges killmail arrays.
     *
     * @param $array1
     * @param string $type
     * @param $array2
     *
     * @return array
     */
    private static function killMerge($array1, $type, $array2)
    {
        foreach ($array2 as $element) {
            $killid = $element['killID'];
            Info::addInfo($element);
            if (!isset($array1[$killid])) {
                $array1[$killid] = array();
            }
            $array1[$killid][$type] = $element;
            $array1[$killid][$type]['commentID'] = $killid;
        }

        return $array1;
    }

    private static $sqldb = null;
    public static function getSqlite()
    {
        if (self::$sqldb == null) {
            self::$sqldb = new SQLite3("/home/kmstorage/sqlite/esi_killmails.sqlite");
            self::$sqldb->busyTimeout(30000);
        }
        return self::$sqldb;
    }

    public static function getEsiKill($killID)
    {
        global $mdb;

        $killID = (int) $killID;
        $esimail = $mdb->findDoc("esimails", ['killmail_id' => $killID]);
        if ($esimail == null && false) {
            $db = self::getSqlite();
            $results = $db->query("SELECT mail FROM killmails where killmail_id = $killID");
            $row = $results->fetchArray(SQLITE3_ASSOC);
            $esimail = json_decode(@$row['mail'], true);
        }
        return $esimail;
    }

    /**
     * Gets details for a kill.
     *
     * @param $killID the killID of the kill you want details for
     *
     * @return array
     */
    public static function getKillDetails($killID)
    {
        global $mdb, $redis;

        $key = "zkb::detail:$killID";
        $stored = RedisCache::get($key);
        if ($stored != null) return $stored;

        $killmail = $mdb->findDoc('killmails', ['killID' => (int) $killID]);
        
        if ($killmail === null) {
            return null; // Killmail not found
        }

        $esimail = Kills::getEsiKill($killID);

        $damage = (int) ($esimail['victim']['damage_taken'] ?? 0);
        $killmail['damage'] = $damage;

        $killmail['dttm'] = date('Y-m-d G:i', $killmail['dttm']->toDateTime()->getTimestamp());
        Info::addInfo($killmail);

        $victim = $killmail['involved'][0];
        $victim['damage'] = $damage;

        $involved = $killmail['involved'];
        array_shift($involved); // remove the victim

        $items = self::getItems($esimail, $killmail);

        $infoInvolved = array();
        $infoItems = array();

        $esimailInv = $esimail['attackers'];
        $attackerCount = sizeof($esimailInv);
        $killmail['number_involved'] = $attackerCount;

        if (isset($esimail['victim']['position']) && isset($killmail['locationID'])) {
            $location = [];
            $location['itemID'] = (int) $killmail['locationID'];
            $location['itemName'] = $mdb->findField('information', 'name', ['cacheTime' => 3600, 'type' => 'locationID', 'id' => (int) $killmail['locationID']]);
            $killmail['location'] = $location;
        }

        for ($index = 0; $index < $attackerCount; ++$index) {
            $rawI = $esimailInv[$index];
            $i = $involved[$index];
            $i['damage'] = $rawI['damage_done'];
            $i['weaponTypeID'] = @$rawI['weapon_type_id'];
            $infoInvolved[] = Info::addInfo($i);
        }

        unset($involved);
        foreach ($items as $i) {
            $infoItems[] = Info::addInfo($i);
        }
        unset($items);

        $stored = array('info' => $killmail, 'victim' => $victim, 'involved' => $infoInvolved, 'items' => $infoItems);
        RedisCache::set($key, $stored, 60);
        return $stored;
    }

    public static function getItems(&$esimail, &$killmail)
    {
        $killTime = $killmail['killTime'];
        $items = array();
        self::addItems($killmail['killID'], $items, $esimail['victim']['items'], $killTime);

        return $items;
    }

    public static function addItems($killID, &$itemArray, $items, $killTime, $inContainer = 0, $parentFlag = 0)
    {
        if ($items == null) {
            return;
        }
        if (is_array($items)) {
            foreach ($items as $item) {
                $typeID = $item['item_type_id'];
                $item['typeID'] = $typeID;
                $item['price'] = Price::getItemPrice($typeID, $killTime);
                $item['inContainer'] = $inContainer;
                if ($inContainer) {
                    $item['flag'] = $parentFlag;
                }
                if ($killID < 21112472 && $inContainer && strpos(Info::getInfoField('typeID', $typeID, 'name'), 'Blueprint')) {
                    $item['singleton'] = 2;
                }
                if ($item['singleton'] == 2 || $item['flag'] == 179) { // bpcs and ships in frigate bay have no real value
                    $item['price'] = 0.01;
                }
                unset($item['_stringValue']);
                $itemArray[] = $item;
                $subItems = isset($item['items']) ? $item['items'] : null;
                unset($item['items']);
                if ($subItems != null) {
                    self::addItems($killID, $itemArray, $subItems, $killTime, 1, $item['flag']);
                }
            }
        }
    }

    /**
     * Merges two kill arrays together.
     *
     * @param $array1
     * @param $array2
     * @param $maxSize
     * @param $key
     * @param $id
     *
     * @return array
     */
    public static function mergeKillArrays($array1, $array2, $maxSize, $key, $id)
    {
        $maxSize = max(0, $maxSize);
        $resultArray = array_diff_key($array1, $array2) + $array2;
        while (sizeof($resultArray) > $maxSize) {
            array_pop($resultArray);
        }
        foreach ($resultArray as $killID => $kill) {
            if (!isset($kill['victim'])) {
                continue;
            }
            $victim = $kill['victim'];
            if (@$victim[$key] == $id) {
                $kill['displayAsLoss'] = true;
            } else {
                $kill['displayAsKill'] = true;
            }
            $resultArray[$killID] = $kill;
        }

        return $resultArray;
    }
}
