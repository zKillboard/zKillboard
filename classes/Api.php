<?php

class Api
{
	public static function addKey($keyID, $vCode, $label = null)
	{
		global $mdb;
		$keyID = (int) $keyID;

		$userID = User::getUserID();
		if ($userID == null) $userID = 0;

		$exists = $mdb->exists("apis", ['keyID' => $keyID, 'vCode' => $vCode]);
		if ($exists)
		{
			if ($userID > 0)
			{
				$mdb->set("apis", ['keyID' => $keyID, 'vCode' => $vCode], ['userID' => $userID]);
				return "We have assigned this API key to your account.";
			}
			return "We already have this API in our database.";
		}

		$mdb->save("apis", ['keyID' => $keyID, 'vCode' => $vCode, 'label' => $label, 'lastApiUpdate' => new MongoDate(2), 'userID' => $userID]);

		return "Success, your API has been added.";
	}

	public static function deleteKey($keyID)
	{
		global $mdb;
		$keyID = (int) $keyID;

		$userID = (int) user::getUserID();
		if ($userID == null) return "You do not have access to remove any keys.";

		$result = $mdb->remove("apis", ['keyID' => $keyID, 'userID' => $userID]);
		if ($result["n"] > 0)
		{
			$result = $mdb->remove("apiCharacters", ['keyID' => $keyID]);
		}

		return "$keyID has been deleted";
	}

	public static function getKeys($userID)
	{
		global $mdb;
		$userID = (int) $userID;
		if ($userID == 0) return [];

		$result = $mdb->find("apis", ['userID' => $userID]);
		return $result;
	}

	public static function getCharacterKeys($userID)
	{
		global $mdb;

		$keys = self::getKeys($userID);
		$result = [];
		foreach ($keys as $key)	
		{
			$chars = $mdb->find("apiCharacters", ['keyID' => $key["keyID"]]);
			foreach ($chars as $char) $result[] = $char;
		}
		return $result;
	}

	/**
	 * Returns an array of the characters assigned to this user.
	 *
	 * @static
	 * @param $userID int
	 * @return array
	 */
	public static function getCharacters($userID)
	{
		$result = self::getCharacterKeys($userID);
		Info::addInfo($result);
		return $result;
	}

	/**
	 * Tests the access mask for KillLog access
	 *
	 * @static
	 * @param int $accessMask
	 * @return bool
	 */
	public static function hasBits($accessMask)
	{
		return ((int)($accessMask & 256) > 0);
	}
}
