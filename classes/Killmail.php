<?php

class Killmail
{
	public static function get($killID)
	{
		$kill = Cache::get("Kill$killID");
		if ($kill != null) return $kill;

		$kill = Db::queryField("select kill_json from zz_killmails where killID = :killID", "kill_json", array(":killID" => $killID));
		if ($kill != '')
		{
			Cache::set("Kill$killID", $kill);
			return $kill;
		}
		return null; // No such kill in database
	}

	public static function put($killID, $raw)
	{
		$file = static::getFile($killID, true);

		$sem = sem_get(5632); // kmdb is 5632 on a phone
		if (!sem_acquire($sem)) throw new Exception("Unable to obtain kmdb semaphore");

		// Thread safe from here until sem_release
		if (!file_exists($file)) $kills = array();
		else
		{
			$contents = file_get_contents($file);
			$deflated = gzdecode($contents);
			$kills = unserialize($deflated);
			$contents = null;
		}
		if (!isset($kills["$killID"]))
		{
			$kills["$killID"] = $raw;
			$contents = serialize($kills);
			$compressed = gzencode($contents);
			file_put_contents($file, $compressed, LOCK_EX);
		}
		sem_release($sem);
	}

	// https://forums.eveonline.com/default.aspx?g=posts&m=4900335#post4900335
	public static function getCrestHash($killID, $killmail = null)
	{
		if ($killmail == null) $killmail = json_decode(Killmail::get($killID), true);

		$victim = $killmail["victim"];
		$victimID = $victim["characterID"] == 0 ? "None" : $victim["characterID"];

		$attackers = $killmail["attackers"];
		$attacker = null;
		if ($attackers != null) foreach($attackers as $att)
		{
			if ($att["finalBlow"] != 0) $attacker = $att;
		}
		if ($attacker == null) $attacker = $attackers[0];
		$attackerID = $attacker["characterID"] == 0 ? "None" : $attacker["characterID"];

		$shipTypeID = $victim["shipTypeID"];

		$dttm = (strtotime($killmail["killTime"]) * 10000000) + 116444736000000000;

		$string = "$victimID$attackerID$shipTypeID$dttm";

		$sha = sha1($string);
		return $sha;
	}

	protected static function getFile($killID, $createDir = false)
	{
		global $baseDir;
		$kmBase = "$baseDir/kmdb/";

		$id = $killID;
		$botDir = abs($id % 1000);
		while (strlen("$botDir") < 3) $botDir = "0" . $botDir;
		$id = (int) $id / 1000;
		$midDir = abs($id % 1000);
		while (strlen("$midDir") < 3) $midDir = "0" . $midDir;
		$id = (int) $id / 1000;
		$topDir = $id % 1000;

		while (strlen("$topDir") < 4) $topDir = "0" . $topDir;
		$dir = "$kmBase/d$topDir/";
		if ($createDir) @mkdir($dir, 0700, true);

		$file = "$dir/k$midDir.gz";
		return $file;
	}
}
