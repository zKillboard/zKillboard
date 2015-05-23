<?php

class Fitting
{
	public static function EFT($array)
	{
		$eft = "";
		$item = "";
		if (isset($array["low"])) foreach ($array["low"] as $flags)
		{
			foreach ($flags as $items)
			{
				$item = $items["typeName"] . "\n";
			}
			$eft .= $item;
		}
		$eft .= "\n";
		$item = "";
		if (isset($array["mid"])) foreach ($array["mid"] as $flags)
		{
			$cnt = 0;
			foreach ($flags as $items)
			{
				if ($cnt == 0)
					$item = $items["typeName"];
				else
					$item .= "," . $items["typeName"];
				$cnt++;
			}
			$item .= "\n";
			$eft .= $item;
		}
		$eft .= "\n";
		$item = "";
		if (isset($array["high"])) foreach ($array["high"] as $flags)
		{
			$cnt = 0;
			foreach ($flags as $items)
			{
				if ($cnt == 0)
					$item = $items["typeName"];
				else
					$item .= "," . $items["typeName"];
				$cnt++;
			}
			$item .= "\n";
			$eft .= $item;
		}
		$eft .= "\n";
		$item = "";
		if (isset($array["rig"])) foreach ($array["rig"] as $flags)
		{
			foreach ($flags as $items)
			{
				$item = $items["typeName"] . "\n";
			}
			$eft .= $item;
		}
		$eft .= "\n";
		$item = "";
		if (isset($array["sub"])) foreach ($array["sub"] as $flags)
		{
			foreach ($flags as $items)
			{
				$item = $items["typeName"] . "\n";
			}
			$eft .= $item;
		}
		$eft .= "\n";
		$item = "";
		if (isset($array["drone"])) foreach ($array["drone"] as $flags)
		{
			foreach ($flags as $items)
			{
				$item .= $items["typeName"] . " x" . $items["qty"] . "\n";
			}
			$eft .= $item;
		}
		return trim($eft);
	}

	public static function DNA($array = array(), $ship)
	{
		$goodspots = array("High Slots", "SubSystems", "Rigs", "Low Slots", "Mid Slots", "Drone Bay", "Fuel Bay");
		$fitArray = array();
		$fitString = $ship.":";

		foreach($array as $item)
		{
			if (isset($item["flagName"]) && in_array($item["flagName"], $goodspots))
			{
				if(isset($fitArray[$item["typeID"]]))
					$fitArray[$item["typeID"]]["count"] = $fitArray[$item["typeID"]]["count"] + (@$item["quantityDropped"] + @$item["quantityDestroyed"]);
				else
					$fitArray[$item["typeID"]] = array("count" => (@$item["quantityDropped"] + @$item["quantityDestroyed"]));
			}
		}

		foreach($fitArray as $key => $item)
		{
			$fitString .= "$key;".$item["count"].":";
		}
		$fitString .= ":";
		return $fitString;
	}
}
