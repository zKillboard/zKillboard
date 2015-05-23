<?php

class Ranks
{
	public static function getRanks($type, $rankType, $recent)
	{
		$table = $recent == true ? "zz_ranks" : "zz_ranks_recent";
		switch ($rankType) {
			case "shipsDestroyed":
				$valueColumn = "shipsDestroyed";
				$rankColumn = "sdRank";
				break;
			case "pointsDestroyed":
				$valueColumn = "pointsDestroyed";
				$rankColumn = "pdRank";
				break;
			case "iskDestroyed":
				$valueColumn = "iskDestroyed";
				$rankColumn = "idRank";
				break;
			case "overallRank":
				$valueColumn = "overallRank";
				$rankColumn = "overallRank";
				break;
			default:
				throw new Exception("Unknown rankType passed to getRanks: $rankType");
		}

		switch ($type) {
			case "pilot":
				$idColumn = "characterID";
				break;
			case "corp":
				$idColumn = "corporationID";
				break;
			case "alli":
				$idColumn = "allianceID";
				break;
			case "faction":
				$idColumn = "factionID";
				break;
			case "ship":
				$idColumn = "shipTypeID";
				break;
			case "group":
				$idColumn = "groupID";
				break;
			case "system":
				$idColumn = "solarSystemID";
				break;
			case "region":
				$idColumn = "regionID";
				break;
			default:
				throw new Exception("Unknown type passed to getRanks: $type");
		}

		$result = Db::query("select typeID $idColumn, $rankColumn rank, $valueColumn kills from $table where type = '$type' order by $rankColumn limit 10");
		Info::addInfo($result);
		return $result;
	}
}
