<?php

class Price
{
	public static function getItemPrice($typeID, $kmDate, $fetch = false)
	{
		global $mdb, $redis;
		$typeID = (int) $typeID;
		if ($kmDate == null) $kmDate = date('Y-m-d H:m');

		$price = static::getFixedPrice($typeID);
		if ($price !== null) return $price;
		$price = static::getCalculatedPrice($typeID, $kmDate);
		if ($price !== null) return $price;

		// Have we fetched prices for this typeID today?
		$today = date('Ymd', time() - 7200); // Back one hour because of CREST cache
		$fetchedKey = "tq:pricesFetched:$today";
		if ($fetch === true)
		{
			if ($redis->hGet($fetchedKey, $typeID) != true) static::getCrestPrices($typeID);
			$redis->hSet($fetchedKey, $typeID, true);
			$redis->expire($fetchedKey, 86400);
		}

		// Have we already determined the price for this item at this date?
		$date = date('Y-m-d', strtotime($kmDate) - 7200); // Back one hour because of CREST cache
		$priceKey = "tq:prices:$date";
		$price = $redis->hGet($priceKey, $typeID);
		if ($price != null) return $price;

		$marketHistory = $mdb->findDoc("prices", ['typeID' => $typeID]);
		unset($marketHistory['_id']);
		unset($marketHistory['typeID']);
		if ($marketHistory == null) $marketHistory = [];
		krsort($marketHistory);

		$useTime = strtotime($date);
		$iterations = 0;
		$priceList = [];
		do {
			$useDate = date('Y-m-d', $useTime);
			$price = @$marketHistory[$useDate];
			if ($price != null) $priceList[] = $price;
			$useTime = $useTime - 86400;
			$iterations++;
		} while (sizeof($priceList) < 34 && $iterations < sizeof($marketHistory));
		if (sizeof($priceList) < 24) $priceList = $marketHistory;

		asort($priceList);
		if (sizeof($priceList) == 34) {
			// remove 2 endpoints from each end, helps fight against wild prices from market speculation and scams
			$priceList = array_splice($priceList, 2, 32);
			$priceList = array_splice($priceList, 0, 30);
		} else if (sizeof($priceList) > 6) {
			$priceList = array_splice($priceList, 0, sizeof($priceList) - 2);
		}
		if (sizeof($priceList) == 0) $priceList[] = 0.01;

		$total = 0;
		foreach ($priceList as $price) $total += $price;
		$avgPrice = round($total / sizeof($priceList), 2);

		$redis->hSet($priceKey, $typeID, $avgPrice);
		$redis->expire($priceKey, 86400);
		return $avgPrice;
	}

	protected static function getFixedPrice($typeID)
	{
		// Some typeID's have hardcoded prices
		switch ($typeID)
		{
			case 35834:
				return 300000000000; // 300b Keepstar, not likely to end up on market at this price
			case 12478: // damn Khumaak and people thinking fake killmail isk value means something
				return 0.01;
			case 2834: // Utu
			case 3516: // Malice
			case 11375: // Freki
				return 80000000000; // 80b
			case 3518: // Vangel
			case 3514: // Revenant
			case 32788: // Cambion
			case 32790: // Etana
			case 32209: // Mimir
			case 33673: // Whiptail
				return 100000000000; // 100b
			case 33397: // Chremoas
			case 35779: // Imp
				return 120000000000; // 120b
			case 2836: // Adrestia
			case 33675: // Chameleon
			case 35781: // Fiend
				return 150000000000; // 150b
			case 9860: // Polaris
			case 11019: // Cockroach
				return 1000000000000; // 1 trillion, rare dev ships
				// Rare cruisers
			case 11940: // Gold Magnate
			case 11942: // Silver Magnate
			case 635: // Opux Luxury Yacht
			case 11011: // Guardian-Vexor
			case 25560: // Opux Dragoon Yacht
				return 500000000000; // 500b
				// Rare battleships
			case 13202: // Megathron Federate Issue
			case 26840: // Raven State Issue
			case 11936: // Apocalypse Imperial Issue
			case 11938: // Armageddon Imperial Issue
			case 26842: // Tempest Tribal Issue
				return 750000000000; // 750b
		}

		// Some groupIDs have hardcoded prices
		$groupID = Info::getGroupID($typeID);
		switch ($groupID)
		{
			case 30: // Titans
				return 100000000000; // 100b
			case 659: // Supercarriers
				return 20000000000; // 20b
			case 29: // Capsules
				return 10000; // 10k
		}

		return null;
	}

	protected static function getCalculatedPrice($typeID, $date)
	{
		switch ($typeID)
		{
			case 2233: // Gantry
				$gantry = self::getItemPrice(3962, $date, true);
				$nodes = self::getItemPrice(2867, $date, true);
				$modules = self::getItemPrice(2871, $date, true);
				$mainframes = self::getItemPrice(2876, $date, true);
				$cores = self::getItemPrice(2872, $date, true);
				$total = $gantry + (($nodes + $modules + $mainframes + $cores) * 8);

				return $total;
		}
		return null;
	}

	protected static function getCrestPrices($typeID)
	{
		global $mdb, $debug, $crestServer;

		$marketHistory = $mdb->findDoc("prices", ['typeID' => $typeID]);
		if ($marketHistory === null)
		{
			$marketHistory = ['typeID' => $typeID];
			$mdb->save("prices", $marketHistory);
		}

		$url = "$crestServer/market/10000002/history/?type=$crestServer/inventory/types/$typeID/"
		$json = CrestTools::getJSON($url);

		if (is_array($json["items"])) foreach ($json["items"] as $row)
		{
			$avgPrice = $row['avgPrice'];
			$date = substr($row['date'], 0, 10);
			if (isset($marketHistory[$date])) continue;
			$mdb->set("prices", ['typeID' => $typeID], [$date => $avgPrice]);
		}
	}
}
