<?php

class Points
{

    public static function getKillPoints($killID)
    {
        global $mdb;

        $killmail = $mdb->findDoc("rawmails", ['killID' => $killID]);
        $victim = $killmail['victim'];
        $shipTypeID = $victim['shipType']['id'];
        $items = $victim['items'];
        $shipInfo = Info::getInfo('typeID', $shipTypeID);

        $dangerFactor = 0;
        $basePoints =  pow(5, @$shipInfo['rigSize']);
        $points = $basePoints;
        foreach ((array) $items as $item) {
            $itemInfo = Info::getInfo('typeID', $item['itemType']['id']);
            if (!@$itemInfo['fittable']) continue;

            $typeID = $item['itemType']['id'];
            $qty = @$item['quantityDestroyed'] + @$item['quantityDropped'];
            $i = Info::getInfo('typeID', $typeID);
            $meta = 1 + floor(@$i['metaLevel'] / 2);
            $dangerFactor += isset($itemInfo['heatDamage']) * $qty * $meta; // offensive/defensive modules overloading are good for pvp
            $dangerFactor += ($itemInfo['groupID'] == 645) * $qty * $meta; // drone damange multipliers
            $dangerFactor -= ($itemInfo['groupID'] == 54) * $qty * $meta; // Mining ships don't earn as many points
        }
        $points += $dangerFactor;
        $points *= max(0.01, min(1, $dangerFactor / 4));

        // Divide by number of ships on killmail
        $involvedCount = max(1, sizeof($killmail['attackers']));
        $points = ceil($points / $involvedCount);

        // Apply a bonus/penalty from -20% to 20% depending on average size of attacking ships
        // For example: Smaller ships blowing up bigger ships get a bonus
        // or bigger ships blowing up smaller ships get a penalty
        $size = 0;
        foreach ((array) $killmail['attackers'] as $attacker) {
            $shipTypeID = @$attacker['shipType']['id'];
            $aInfo = Info::getInfo('typeID', $shipTypeID);
            $size += pow(5, @$aInfo['rigSize']);
        }
        $avg = max(1, $size / $involvedCount);
        $modifier = min(1.2, max(0.8, $basePoints / $avg));
        $points = ceil($points * $modifier);

        return $points;
    }
}
