<?php

class Points
{
    private static $pointsArray = array(
            324 => array('Assault Ship', 100),
            397 => array('Assembly Array', 10),
            1201 => array('Attack Battlecruiser', 300),
            419 => array('Battlecruiser', 250),
            27 => array('Battleship', 750),
            898 => array('Black Ops', 1800),
            1202 => array('Blockade Runner', 125),
            883 => array('Capital Industrial Ship', 1000),
            29 => array('Capsule', 5),
            547 => array('Carrier', 3000),
            906 => array('Combat Recon Ship', 350),
	    1534 => array("Command Destroyers", 250),
            540 => array('Command Ship', 450),
	    1282 => array('Compression Array', 10),
            365 => array('Control Tower', 250),
            471 => array('Corporate Hangar Array', 50),
            830 => array('Covert Ops', 80),
            26 => array('Cruiser', 100),
            838 => array('Cynosural Generator Array', 10),
            839 => array('Cynosural System Jammer', 50),
            420 => array('Destroyer', 60),
            485 => array('Dreadnought', 4000),
            893 => array('Electronic Attack Ship', 200),
            439 => array('Electronic Warfare Battery', 50),
            837 => array('Energy Neutralizing Battery', 50),
	    1273 => array('Encounter Surveillance System', 10),
            543 => array('Exhumer', 20),
	    1283 => array('Expedition Frigate', 70),
            833 => array('Force Recon Ship', 350),
            513 => array('Freighter', 300),
            25 => array('Frigate', 50),
            358 => array('Heavy Assault Ship', 400),
            894 => array('Heavy Interdictor', 600),
            28 => array('Industrial', 20),
            941 => array('Industrial Command Ship', 800),
	    351064 => array('Infantry Dropsuits', 1),
	    351210 => array('Infantry Vehicles', 2),
            1012 => array('Infrastructure Hubs', 500),
            831 => array('Interceptor', 60),
            541 => array('Interdictor', 60),
            902 => array('Jump Freighter', 500),
            707 => array('Jump Portal Array', 10),
            832 => array('Logistics', 175),
	    1527 => array('Logistics Frigate', 60),
            900 => array('Marauder', 1000),
            463 => array('Mining Barge', 20),
	    1246 => array('Mobile Depot', 10),
            449 => array('Mobile Hybrid Sentry', 10),
            413 => array('Mobile Laboratory', 10),
            430 => array('Mobile Laser Sentry', 10),
            417 => array('Mobile Missile Sentry', 10),
            426 => array('Mobile Projectile Sentry', 10),
            438 => array('Mobile Reactor', 10),
	    1247 => array('Mobile Siphon Unit', 10),
            1250 => array('Mobile Tractor Unit', 10),
            416 => array('Moon Mining', 10),
            1106 => array('Orbital Construction Platform', 5),
            1025 => array('Orbital Infrastructure', 500),
	    1212 => array('Personal Hangar', 10),
            1022 => array('Prototype Exploration Ship', 5),
            311 => array('Refining Array', 10),
            237 => array('Rookie ship', 5),
            709 => array('Scanner Array', 10),
            440 => array('Sensor Dampening Battery', 10),
            444 => array('Shield Hardening Array', 10),
            363 => array('Ship Maintenance Array', 10),
            31 => array('Shuttle', 5),
            404 => array('Silo', 10),
            1005 => array('Sovereignty Blockade Units', 250),
            441 => array('Stasis Webification Battery', 10),
            834 => array('Stealth Bomber', 80),
            963 => array('Strategic Cruiser', 750),
            659 => array('Supercarrier', 6000),
            1305 => array('Tactical Destroyer', 250),
            1003 => array('Territorial Claim Units', 500),
            30 => array('Titan', 20000),
            473 => array('Tracking Array', 10),
	    1250 => array('Tractor Unit', 10),
            380 => array('Transport Ship', 30),
	    367594 => array('Visual Customization', 1),
            443 => array('Warp Scrambling Battery', 10),
            );

    public static function getPointValues()
    {
        return self::$pointsArray;
    }

    public static function getPoints($groupID)
    {
        if (!isset(self::$pointsArray[$groupID])) {
            return 0;
        }
        $arr = self::$pointsArray[$groupID];
        if (!isset($arr[1])) {
            return 0;
        }

        return $arr[1];
    }

    public static function getKillPoints($kill, $price)
    {
        $vicpoints = self::getPoints($kill['involved']['0']['groupID']);
        $vicpoints += $price / 10000000;
        $maxpoints = round($vicpoints * 1.2);

        $invpoints = 0;
        foreach ($kill['involved'] as $key => $inv) {
            if ($key == 0) {
                continue;
            }
            $invpoints += isset($inv['groupID']) ? self::getPoints($inv['groupID']) : 1;
        }

        if (($vicpoints + $invpoints) == 0) {
            return 0;
        }
        $gankfactor = $vicpoints / ($vicpoints + $invpoints);
        $points = ceil($vicpoints * ($gankfactor / 0.75));

        if ($points > $maxpoints) {
            $points = $maxpoints;
        }
        $points = $points / (sizeof($kill['involved']) - 1);

        return (int) max(1, round($points, 0)); // a kill is always worth at least one point
    }
}
