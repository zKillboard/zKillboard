<?php

class Points
{
	public static function getPoints($typeID) {
		$mass = Info::getInfoField('typeID', $typeID, 'mass');
		return floor(log($mass));
		$power = 1;
		while ($mass >= 2) {
			$power ++;
			$mass = floor($mass / 2);
		}
		return floor(log(pow(2, $power)));
	}

	public static function getPointValues()
	{
		return [];
	}

	public static function getKillPoints($kill, $price)
	{
		$vicpoints = self::getPoints($kill['involved']['0']['shipTypeID']);
		$vicpoints += $price / 10000000;
		$maxpoints = round($vicpoints * 1.2);

		$invpoints = 0;
		foreach ($kill['involved'] as $key => $inv) {
			if ($key == 0) {
				continue;
			}
			$invpoints += isset($inv['shipTypeID']) ? self::getPoints($inv['shipTypeID']) : 1;
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
