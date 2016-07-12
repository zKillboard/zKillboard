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
    public static function getKills($parameters = array(), $allTime = true, $includeKillDetails = true)
    {
        global $mdb;

        $hashKey = 'Kills::getKills:'.serialize($parameters);
        $result = RedisCache::get($hashKey);
        if ($result != null) {
            return $result;
        }

        $kills = MongoFilter::getKills($parameters);

        if ($includeKillDetails == false) {
            return $kills;
        }
        $details = [];
        foreach ($kills as $kill) {
            $killID = (int) $kill['killID'];
            $killHashKey = "killDetail:$killID";
            $killmail = RedisCache::get($killHashKey);
            if ($killmail == null) {
                $killmail = $mdb->findDoc('killmails', ['killID' => $killID, 'cacheTime' => 3600]);
                Info::addInfo($killmail);
                $killmail['victim'] = $killmail['involved'][0];
                $killmail['victim']['killID'] = $killID;
                foreach ($killmail['involved'] as $inv) {
                    if (@$inv['finalBlow'] === true) {
                        $killmail['finalBlow'] = $inv;
                    }
                }
                $killmail['finalBlow']['killID'] = $killID;
                unset($killmail['_id']);

                RedisCache::set($killHashKey, $killmail, 3600);
            }
            $details[$killID] = $killmail;
        }
        RedisCache::set($hashKey, $details, 60);

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

    /**
     * Gets details for a kill.
     *
     * @param $killID the killID of the kill you want details for
     *
     * @return array
     */
    public static function getKillDetails($killID)
    {
        global $mdb;
        $killmail = $mdb->findDoc('killmails', ['cacheTime' => 3600, 'killID' => (int) $killID]);
        $rawmail = $mdb->findDoc('rawmails', ['cacheTime' => 3600, 'killID' => (int) $killID]);
        $damage = (int) $rawmail['victim']['damageTaken'];
        $killmail['damage'] = $damage;

        $killmail['dttm'] = date('Y-m-d G:i', $killmail['dttm']->sec);
        Info::addInfo($killmail);

        $victim = $killmail['involved'][0];
        $victim['damage'] = $damage;

        $involved = $killmail['involved'];
        array_shift($involved); // remove the victim

        $items = self::getItems($rawmail, $killmail);

        $infoInvolved = array();
        $infoItems = array();

        $rawmailInv = $rawmail['attackers'];
        $attackerCount = sizeof($rawmailInv);
        $killmail['number_involved'] = $attackerCount;

        if (isset($rawmail['victim']['position'])) {
            $location = [];
            $location['itemID'] = (int) $killmail['locationID'];
            $location['itemName'] = $mdb->findField("information", "name", ['cacheTime' => 3600, 'type' => 'locationID', 'id' => (int) $killmail['locationID']]);
            $killmail['location'] = $location;
        }

        for ($index = 0; $index < $attackerCount; ++$index) {
            $rawI = $rawmailInv[$index];
            $i = $involved[$index];
            $i['damage'] = $rawI['damageDone'];
            $i['weaponTypeID'] = @$rawI['weaponType']['id'];
            $infoInvolved[] = Info::addInfo($i);
        }

        unset($involved);
        foreach ($items as $i) {
            $infoItems[] = Info::addInfo($i);
        }
        unset($items);

        return array('info' => $killmail, 'victim' => $victim, 'involved' => $infoInvolved, 'items' => $infoItems);
    }

    public static function getItems(&$rawmail, &$killmail)
    {
        $killTime = $killmail['killTime'];
        $items = array();
        self::addItems($items, $rawmail['victim']['items'], $killTime);

        return $items;
    }

    public static function addItems(&$itemArray, $items, $killTime, $inContainer = 0, $parentFlag = 0)
    {
        if ($items == null) {
            return;
        }
        if (is_array($items)) {
            foreach ($items as $item) {
                $typeID = $item['itemType']['id'];
                $item['typeID'] = $typeID;
                $item['price'] = Price::getItemPrice($typeID, $killTime);
                $item['inContainer'] = $inContainer;
                if ($inContainer) {
                    $item['flag'] = $parentFlag;
                }
                if ($inContainer && strpos(Info::getInfoField('typeID', $typeID, 'name'), 'Blueprint')) {
                    $item['singleton'] = 2;
                }
                unset($item['_stringValue']);
                $itemArray[] = $item;
                $subItems = isset($item['items']) ? $item['items'] : null;
                unset($item['items']);
                if ($subItems != null) {
                    self::addItems($itemArray, $subItems, $killTime, 1, $item['flag']);
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
