<?php

class Points
{

    public static function getKillPoints($killID)
    {
        global $mdb;

        $killmail = Kills::getEsiKill($killID);
        $victim = $killmail['victim'];
        $shipTypeID = $victim['ship_type_id'];
        $items = $victim['items'];
        $shipInfo = Info::getInfo('typeID', $shipTypeID);
        $rigSize = self::getRigSize($shipTypeID);;
        $shipInfo['rigSize'] = $rigSize;

        $dangerFactor = 0;
        $basePoints =  pow(5, @$shipInfo['rigSize']);
        $points = $basePoints;
        foreach ((array) $items as $item) {
            $itemInfo = Info::getInfo('typeID', $item['item_type_id']);
            if (@$itemInfo['categoryID'] != 7) continue;

			$flagName = Info::getFlagName($item['flag']); 
            if (($flagName == "Low Slots" || $flagName == "Mid Slots" || $flagName == "High Slots" || $flagName == 'SubSystems') || ($killID < 23970577 && $item['flag'] == 0) ) {
                $typeID = $item['item_type_id'];
                $qty = @$item['quantity_destroyed'] + @$item['quantity_dropped'];
                $metaLevel = Info::getDogma($typeID, 633);
                $meta = 1 + floor($metaLevel / 2);
                $heatDamage = Info::getDogma($typeID, 1211);
                $dangerFactor += ((int) is_numeric($heatDamage)) * $qty * $meta; // offensive/defensive modules overloading are good for pvp
                $dangerFactor += ($itemInfo['groupID'] == 645) * $qty * $meta; // drone damange multipliers
                $dangerFactor -= ($itemInfo['groupID'] == 54) * $qty * $meta; // Mining ships don't earn as many points
            }
        }
        $points += $dangerFactor;
        $points *= max(0.01, min(1, $dangerFactor / 4));

        // Divide by number of involved players on killmail
        $numAttackers = self::getInvolvedCount($killmail['attackers']);
        $involvedPenalty = max(1, $numAttackers * max(1, $numAttackers / 2));
		$points = $points / $involvedPenalty;

        // Apply a bonus/penalty from -50% to 20% depending on average size of attacking ships
        // For example: Smaller ships blowing up bigger ships get a bonus
        // or bigger ships blowing up smaller ships get a penalty
        $size = 0;
        $hasChar = false;
        foreach ((array) $killmail['attackers'] as $attacker) {
            $hasChar |= @$attacker['character_id'] > 0;
            $shipTypeID = @$attacker['ship_type_id'];
            $categoryID = Info::getInfoField("typeID", $shipTypeID, "categoryID");
            if ($categoryID == 65) return 1; // Structure on your mail, only 1 point

            $aInfo = Info::getInfo('typeID', $shipTypeID);
            $aInfo['rigSize'] = self::getRigSize($shipTypeID);
            $size += pow(5, ((@$aInfo['groupID'] != 29) ? @$aInfo['rigSize'] : @$shipInfo['rigSize'] + 1));
        }
        if ($hasChar == false) return 1;
        $avg = max(1, $size / $numAttackers);
        $modifier = min(1.2, max(0.5, $basePoints / $avg));
        $points = (int) floor($points * $modifier);

        return max(1, $points);
    }

    private static function getRigSize($typeID)
    {
        global $mdb, $redis;

        $groupID = Info::getInfoField("typeID", $typeID, "groupID");
        if ($groupID == 963) return 2;

        $p = $redis->get("zkb:rigSize:$typeID");
        if ($p != null) return (int) $p;


        $r = $mdb->find("information", ['type' => 'typeID', 'id' => $typeID], [], null, ['dogma_attributes' => [ '$elemMatch' => [ 'attribute_id' => 1547 ] ]]);
        foreach ($r as $row ) {
            if (!isset($row['dogma_attributes'])) break;
            $row = $row['dogma_attributes'][0];
            $p = max(1, $row['value']);
            $redis->setex("zkb:rigSize:$typeID", 300, $p);
            return $p;
        }
        $redis->setex("zkb:rigSize:$typeID", 300, 1);
        return 1;
    }

	// Do not count NPCs (any involved that do NOT have a characterID)
	private static function getInvolvedCount($involved)
	{
		$count = 0;
		foreach ($involved as $inv) {
			if (@$inv['character_id'] > 0) $count++;
		}
		return $count;
	}
}
