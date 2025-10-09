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
				// Explicit bays
				case 5:   $slot = 'Cargo'; break;
				case 87:  $slot = 'DroneBay'; break;
				case 158: $slot = 'FighterBay'; break;

				// High slots (27–34)
				case 27: $slot = 'HiSlot0'; break;
				case 28: $slot = 'HiSlot1'; break;
				case 29: $slot = 'HiSlot2'; break;
				case 30: $slot = 'HiSlot3'; break;
				case 31: $slot = 'HiSlot4'; break;
				case 32: $slot = 'HiSlot5'; break;
				case 33: $slot = 'HiSlot6'; break;
				case 34: $slot = 'HiSlot7'; break;

				// Low slots (11–18)
				case 11: $slot = 'LoSlot0'; break;
				case 12: $slot = 'LoSlot1'; break;
				case 13: $slot = 'LoSlot2'; break;
				case 14: $slot = 'LoSlot3'; break;
				case 15: $slot = 'LoSlot4'; break;
				case 16: $slot = 'LoSlot5'; break;
				case 17: $slot = 'LoSlot6'; break;
				case 18: $slot = 'LoSlot7'; break;

				// Mid slots (19–26)
				case 19: $slot = 'MedSlot0'; break;
				case 20: $slot = 'MedSlot1'; break;
				case 21: $slot = 'MedSlot2'; break;
				case 22: $slot = 'MedSlot3'; break;
				case 23: $slot = 'MedSlot4'; break;
				case 24: $slot = 'MedSlot5'; break;
				case 25: $slot = 'MedSlot6'; break;
				case 26: $slot = 'MedSlot7'; break;

				// Rigs (92–94)
				case 92: $slot = 'RigSlot0'; break;
				case 93: $slot = 'RigSlot1'; break;
				case 94: $slot = 'RigSlot2'; break;

				// Structure service slots (164–171)
				case 164: $slot = 'ServiceSlot0'; break;
				case 165: $slot = 'ServiceSlot1'; break;
				case 166: $slot = 'ServiceSlot2'; break;
				case 167: $slot = 'ServiceSlot3'; break;
				case 168: $slot = 'ServiceSlot4'; break;
				case 169: $slot = 'ServiceSlot5'; break;
				case 170: $slot = 'ServiceSlot6'; break;
				case 171: $slot = 'ServiceSlot7'; break;

				// Subsystems (125–128)
				case 125: $slot = 'SubSystemSlot0'; break;
				case 126: $slot = 'SubSystemSlot1'; break;
				case 127: $slot = 'SubSystemSlot2'; break;
				case 128: $slot = 'SubSystemSlot3'; break;

				default:
					$slot = 'Cargo';
					$flag = 5; // default flag to Cargo for everything else
					break;
			}
			$flag = $slot;  // Can switch between int or string value easily, with this line commented, int value, uncommented, string value

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
