<?php

class UserGlobals extends Twig_Extension
{
	public function getName()
	{
		return "UserGlobals";
	}

	public function getGlobals()
	{
		$result = array();
		if (isset($_SESSION["loggedin"])) {
			$u = User::getUserInfo();

			$config = UserConfig::getAll();

			foreach($config as $key => $val) $this->addGlobal($result, $key, $val);

			$this->addGlobal($result, "sessionusername", $u["username"]);
			$this->addGlobal($result, "sessionuserid", $u["id"]);
			$this->addGlobal($result, "sessionadmin", (bool)$u["admin"]);
			$this->addGlobal($result, "sessionmoderator", (bool)$u["moderator"]);
    	}

		global $mdb;
		$kills = $mdb->count("oneHour");
		$this->addGlobal($result, "killsLastHour", $kills, 0);
		return $result;
	}

	private function addGlobal(&$array, $key, $value, $defaultValue = null)
	{
		if ($value == null && $defaultValue == null) return;
		else if ($value == null) $array[$key] = $defaultValue;
		else $array[$key] = $value;
	}
}
