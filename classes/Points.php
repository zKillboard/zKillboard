<?php

class Points
{
    public static function getPoints($typeID)
    {
        $groupID = Info::getInfoField('typeID', $typeID, 'groupID');
        if ($groupID == 29) {
            return 1;
        }
        $categoryID = Info::getInfoField('groupID', $groupID, 'categoryID');
        if ($categoryID != 6) {
            return 1;
        }

        $dogma = ['hp', 'armorHP', 'shieldCapacity'];
        $sum = 0;
        foreach ($dogma as $attr) {
            $sum += (int) Info::getInfoField('typeID', $typeID, $attr);
        }
        $points = ceil($sum / log($sum));
        if ($groupID == 963) {
            $points = $points * 3;
        } // Strategic Cruisers

        return max(1, $points);
    }

    public static function getKillPoints($kill, $price)
    {
        if (!isset($kill['involved']['0']['shipTypeID'])) {
            return 1;
        }
        $vicpoints = self::getPoints($kill['involved']['0']['shipTypeID']);
        $vicpoints += floor(log($price / 10000000));

        $invpoints = 0;
        foreach ($kill['involved'] as $key => $inv) {
            if ($key == 0) {
                continue;
            }
            $invpoints += isset($inv['shipTypeID']) ? self::getPoints($inv['shipTypeID']) : 1;
        }

        if (($vicpoints + $invpoints) == 0) {
            return 1;
        }
        $gankfactor = ($vicpoints / ($vicpoints + $invpoints)) / $kill['attackerCount'];
        $points = ceil($vicpoints * $gankfactor);

        $points = max(1, ceil($points / $kill['attackerCount']));
        $points = ceil($points / max(1, log($points)));

        return max(1, $points); // a kill is always worth at least one point
    }

    public static function getPointValues()
    {
        return [];
    }
}
