<?php

class ESI {

    public static function saveFitting($killID, $charID = 0)
    {
        global $mdb, $esiServer, $redis;

        $charID = $charID == 0 ? User::getUserID() : $charID;
        if ($charID == 0) {
            return ['message' => 'You should probably try logging into zKillboard first.'];
        }
        if ($redis->get("tqCountInt") < 100) return ['message' => "TQ doesn't appear to be online. Try again later."];

        $row = $mdb->findDoc("scopes", ['characterID' => $charID, 'scope' => 'esi-fittings.write_fittings.v1']);
        if ($row == null) {
            return ['message' => 'You have not given zkillboard permission to save fits to your account. Log out, and then log back in and make sure you give the appropriate fitting scope.'];
        }
        $sso = ZKillSSO::getSSO();
        $accessToken = $sso->getAccessToken($row['refreshToken']);

        $killmail = Kills::getEsiKill($killID);
        $parsed = $mdb->findDoc("killmails", ['killID' => (int) $killID]);

        if (@$parsed['vGroupID'] == 29) return ['message' => 'Sorry, ESI does not accept saving capsule fittings. (I wish it did though.)'];
        if (@$parsed['categoryID'] !== 6) return ['message' => 'Sorry, only ship fittings can be saved.'];

        $victim = $killmail['victim'];

        header('Content-Type: application/json');

        $export = [];
        $charName = Info::getInfoField('characterID', (int) @$victim['character_id'], 'name');
        $shipName = Info::getInfoField('shipTypeID', $victim['ship_type_id'], 'name');
        $export['name'] = $charName == "" ? "$shipName" : "$charName's $shipName";
        if (strlen($export['name']) > 50) $export['name'] = substr($export['name'], 0, 50);
        $export['description'] = "Imported from https://zkillboard.com/kill/$killID/";
        $export['ship_type_id'] = $victim['ship_type_id'];

        $items = $victim['items'];
        $export['items'] = [];
        foreach ($items as $item) {
            $flag = $item['flag'];
			switch ($flag) {
				// --- Valid ESI flags (fall-through, no change) ---

				// Cargo / Bays
				case 5:   // Cargo
				case 87:  // DroneBay
				case 158: // FighterBay

				// High Slots (HiSlot0..HiSlot7)
				case 27:  // HiSlot0
				case 28:  // HiSlot1
				case 29:  // HiSlot2
				case 30:  // HiSlot3
				case 31:  // HiSlot4
				case 32:  // HiSlot5
				case 33:  // HiSlot6
				case 34:  // HiSlot7

				// Low Slots (LoSlot0..LoSlot7)
				case 11:  // LoSlot0
				case 12:  // LoSlot1
				case 13:  // LoSlot2
				case 14:  // LoSlot3
				case 15:  // LoSlot4
				case 16:  // LoSlot5
				case 17:  // LoSlot6
				case 18:  // LoSlot7

				// Mid Slots (MedSlot0..MedSlot7)
				case 19:  // MedSlot0
				case 20:  // MedSlot1
				case 21:  // MedSlot2
				case 22:  // MedSlot3
				case 23:  // MedSlot4
				case 24:  // MedSlot5
				case 25:  // MedSlot6
				case 26:  // MedSlot7

				// Rigs (RigSlot0..RigSlot2)
				case 92:  // RigSlot0
				case 93:  // RigSlot1
				case 94:  // RigSlot2

				// Structure Service Slots (ServiceSlot0..ServiceSlot7)
				case 164: // ServiceSlot0
				case 165: // ServiceSlot1
				case 166: // ServiceSlot2
				case 167: // ServiceSlot3
				case 168: // ServiceSlot4
				case 169: // ServiceSlot5
				case 170: // ServiceSlot6
				case 171: // ServiceSlot7

				// Subsystems (SubSystemSlot0..SubSystemSlot3)
				case 125: // SubSystemSlot0
				case 126: // SubSystemSlot1
				case 127: // SubSystemSlot2
				case 128: // SubSystemSlot3
					// Valid → no change
					break;

				// Anything else → force Cargo
				default:
					$flag = 5; // Cargo
					break;
			}


            $nextItem = [];
            $nextItem ['flag'] = $flag;
            $nextItem['quantity'] = @$item['quantity_dropped'] + @$item['quantity_destroyed'];
            $nextItem['type_id'] = $item['item_type_id'];

            $export['items'][] = $nextItem;
        }
        if (sizeof($export['items']) == 0) {
            return ['message' => 'Cannot save this fit, no hardware.'];
        }

        $sso = ZKillSSO::getSSO();
        $result = $sso->doCall($esiServer . "/v1/characters/$charID/fittings/", $export, $accessToken, 'POST_JSON');
        if ($result != "") {
            $json = json_decode($result, true);
            Util::zout("$charID successfully saved fit $killID");
            if (isset($json['fitting_id'])) return ['message' => "Fit successfully saved to your character's fittings."];
        }
        Util::zout("$killID Fit save error: $result ($charID)");
        file_put_contents("/tmp/export_$killID.txt", print_r($export, true));
        return ['message' => "<strong>ERROR importing killID $killID</STRONG><br/><code>" . print_r($result, true) . "</code><br/>Something went wrong trying to save that fit... Please let Squizz know about this problem via Discord, in the #zkillboard-com channel, <a target='_blank' href='https://discord.gg/sV2kkwg8UD'>here</a>."];            
    }

    private static $infernoFlags = array(
            //5 => array(5,5), // Cargo
            12 => array(27, 34), // Highs
            13 => array(19, 26), // Mids
            11 => array(11, 18), // Lows
            87 => array(87, 87), // Drones
            //133 => array(133, 133), // Fuel Bay
            2663 => array(92, 98), // Rigs
            3772 => array(125, 132), // Subs
            );

    public static function isFit($flag)
    {
        foreach (self::$infernoFlags as $range) {
            if ($flag >= $range[0] && $flag <= $range[1]) {
                return true;
            }
        }

        return false;
    }
}
