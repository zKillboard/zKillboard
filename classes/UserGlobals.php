<?php

class UserGlobals extends Twig_Extension
{
	public function getName()
	{
		return 'UserGlobals';
	}

	public function getGlobals()
	{
		$result = array();
		if (User::isLoggedIn()) {
			$userID = User::getUserID();
			$u = User::getUserInfo();

			$config = UserConfig::getAll();

			foreach ($config as $key => $val) {
				$this->addGlobal($result, $key, $val);
			}

			$this->addGlobal($result, 'sessionusername', @$u['username']);
			$this->addGlobal($result, 'sessionuserid', (int) @$_SESSION['characterID']);
			$this->addGlobal($result, 'sessionadmin', (bool) @$u['admin']);
			$this->addGlobal($result, 'sessionmoderator', (bool) @$u['moderator']);

			$this->addTrackers($result, $userID);
		}

		global $mdb;
		$killsLastHour = new RedisTtlCounter('killsLastHour', 3600);
		$this->addGlobal($result, 'killsLastHour', $killsLastHour->count(), 0);

		return $result;
	}

	private function addGlobal(&$array, $key, $value, $defaultValue = null)
	{
		if ($value == null && $defaultValue == null) {
			return;
		} elseif ($value == null) {
			$array[$key] = $defaultValue;
		} else {
			$array[$key] = $value;
		}
	}

	private function addTrackers(&$result, $userID)
	{
		global $mdb;

		if ($userID == 1633218082) {
			$info = $mdb->findDoc("information", ['type' => 'characterID', 'id' => $userID, 'cacheTime' => 300]);
			$charName = Info::getInfoField('characterID', $userID, 'name');
			$corpID = $info['corporationID'];
			$corpName = Info::getInfoField('corporationID', $corpID, 'name');
			$alliID = (int) @$info['allianceID'];
			$alliName = $alliID > 0 ? Info::getInfoField('allianceID', $alliID, 'name') : null;

			$result['tracker_character'] = $this->addTracker(@$result['tracker_character'], $userID, $charName);
			$result['tracker_corporation'] = $this->addTracker(@$result['tracker_corporation'], $corpID, $corpName);
			$result['tracker_alliance'] = $this->addTracker(@$result['tracker_alliance'], $alliID, $alliName);
		}
	}

	private function addTracker($array, $id, $name)
	{
		if ($id == 0) return;
		if ($array == null) $array = [];

		$addIt = true;
		foreach ($array as $a)
		{
			if ($a['id'] == $id) $addIt = false;
		}
		if ($addIt) $array[] = ['id' => $id, 'name' => $name];

		usort($array, "UserGlobals::sortIt");
		return $array;
	}

	private static function sortIt($a, $b)
	{
		return $a['name'] > $b['name'];
	}
}
