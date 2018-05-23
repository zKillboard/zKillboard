<?php

class Points
{

    public static function getKillPoints($killID)
    {
        global $mdb;

        $killmail = $mdb->findDoc("esimails", ['killmail_id' => (int) $killID]);
        $victim = $killmail['victim'];
        $shipTypeID = $victim['ship_type_id'];
        $items = $victim['items'];
        $shipInfo = Info::getInfo('typeID', $shipTypeID);

        $dangerFactor = 0;
        $basePoints =  pow(5, @$shipInfo['rigSize']);
        $points = $basePoints;
        foreach ((array) $items as $item) {
            $itemInfo = Info::getInfo('typeID', $item['item_type_id']);
            if (!@$itemInfo['fittable']) continue;

            $flagName = Info::getFlagName($item['flag']); 
            if (($flagName == "Low Slots" || $flagName == "Mid Slots" || $flagName == "High Slots" || $flagName == 'SubSystems') 
                || ($killID < 23970577 && $item['flag'] == 0) ) {
                $typeID = $item['item_type_id'];
                $qty = @$item['quantity_destroyed'] + @$item['quantity_dropped'];
                $i = Info::getInfo('typeID', $typeID);
                $meta = 1 + floor(@$i['metaLevel'] / 2);
                $dangerFactor += isset($itemInfo['heatDamage']) * $qty * $meta; // offensive/defensive modules overloading are good for pvp
                $dangerFactor += ($itemInfo['groupID'] == 645) * $qty * $meta; // drone damange multipliers
                $dangerFactor -= ($itemInfo['groupID'] == 54) * $qty * $meta; // Mining ships don't earn as many points
            }
        }
        $points += $dangerFactor;
        $points *= max(0.01, min(1, $dangerFactor / 4));

        // Divide by number of ships on killmail
        $numAttackers = sizeof($killmail['attackers']);
        $involvedPenalty = max(1, $numAttackers * max(1, $numAttackers / 2));
        $points = $points / $involvedPenalty;

        // Apply a bonus/penalty from -50% to 20% depending on average size of attacking ships
        // For example: Smaller ships blowing up bigger ships get a bonus
        // or bigger ships blowing up smaller ships get a penalty
        $size = 0;
        foreach ((array) $killmail['attackers'] as $attacker) {
            $shipTypeID = @$attacker['ship_type_id'];
            $categoryID = Info::getInfoField("typeID", $shipTypeID, "categoryID");
            if ($categoryID == 65) return 1; // Structure on your mail, only 1 point

            $aInfo = Info::getInfo('typeID', $shipTypeID);
            $size += pow(5, ((@$aInfo['groupID'] != 29) ? @$aInfo['rigSize'] : @$shipInfo['rigSize'] + 1));
        }
        $avg = max(1, $size / $numAttackers);
        $modifier = min(1.2, max(0.5, $basePoints / $avg));
        $points = (int) floor($points * $modifier);

        return max(1, $points);
    }
}
