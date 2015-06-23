<?php

class User
{
	/**
	 * @param string $username
	 * @param string $password
	 * @param bool $autoLogin
	 * @return bool
	*/
	public static function setLogin($username, $password, $autoLogin)
	{
		global $cookie_name, $cookie_time, $cookie_ssl, $baseAddr, $app;
		$hash = Password::genPassword($password);
		if ($autoLogin) {
			$hash = $username."/".hash("sha256", $username.$hash.time());
			$validTill = date("Y-m-d H:i:s", time() + $cookie_time);
			$userID = Db::queryField("SELECT id FROM zz_users WHERE username = :username", "id", array(":username" => $username), 30);
			$userAgent = $_SERVER["HTTP_USER_AGENT"];
			$ip = IP::get();
			Db::execute("INSERT INTO zz_users_sessions (userID, sessionHash, validTill, userAgent, ip) VALUES (:userID, :sessionHash, :validTill, :userAgent, :ip)", 
				array(":userID" => $userID, ":sessionHash" => $hash, ":validTill" => $validTill, ":userAgent" => $userAgent, ":ip" => $ip));
			$app->setEncryptedCookie($cookie_name, $hash, time() + $cookie_time, "/", $baseAddr, $cookie_ssl, true);
		}
		$_SESSION["loggedin"] = $username;
		return true;
	}

	/**
	 * @param string $username
	 * @param string $password
	 * @return bool
	*/
	public static function checkLogin($username, $password)
	{
		$p = Db::query("SELECT username, password FROM zz_users WHERE username = :username", array(":username" => $username), 0);
		if(!empty($p[0]))
		{
			$pw = $p[0]["password"];

			if(Password::checkPassword($password, $pw))
				return true;
			return false;
		}
		return false;
	}

	/**
	 * @param int $userID
	 * @return array|null
	*/
	public static function checkLoginHashed($userID)
	{
		return Db::query("SELECT sessionHash FROM zz_users_sessions WHERE userID = :userID AND now() < validTill", array(":userID" => $userID), 0);
	}

	/**
	 * @return bool
	*/
	public static function autoLogin()
	{
		global $cookie_name, $cookie_time, $app;
		$sessionCookie = $app->getEncryptedCookie($cookie_name, false);

		if (!empty($sessionCookie)) {
			$cookie = explode("/", $sessionCookie);
			$username = $cookie[0];
			//$cookieHash = $cookie[1];
			$userID = Db::queryField("SELECT id FROM zz_users WHERE username = :username", "id", array(":username" => $username), 30);
			$hashes = self::checkLoginHashed($userID);
			foreach($hashes as $hash)
			{
				$hash = $hash["sessionHash"];
				if ($sessionCookie == $hash) {
					$_SESSION["loggedin"] = $username;
					return true;
				}
			}
			return false;
		}
		return false;
	}

	/**
	 * @return bool
	*/
	public static function isLoggedIn()
	{
		return isset($_SESSION["loggedin"]);
	}

	/**
	 * @return array|null
	*/
	public static function getUserInfo()
	{
		if (isset($_SESSION["loggedin"])) {
			$id = Db::query("SELECT id, username, email, dateCreated, admin, moderator, characterID FROM zz_users WHERE username = :username", array(":username" => $_SESSION["loggedin"]), 1);
			return @array("id" => $id[0]["id"], "username" => $id[0]["username"], "admin" => $id[0]["admin"], "moderator" => $id[0]["moderator"], "email" => $id[0]["email"], "characterID" => $id[0]["characterID"], "dateCreated" => $id[0]["dateCreated"]);
		}
		return null;
	}

	/**
	 * @return int
	*/
	public static function getUserID()
	{
		if (isset($_SESSION["loggedin"])) {
			$id = Db::queryField("SELECT id FROM zz_users WHERE username = :username", "id", array(":username" => $_SESSION["loggedin"]), 30);
			return (int) $id;
		}
		return 0;
	}

	/**
	 * @return bool
	*/
	public static function isModerator()
	{
		$info = self::getUserInfo();
		return $info["moderator"] == 1;
	}

	/**
	 * @return bool
	*/
	public static function isAdmin()
	{
		$info = self::getUserInfo();
		return $info["admin"] == 1;
	}

	/**
	 * @param int $userID
	 * @return string
	*/
	public static function getUsername($userID)
	{
		return Db::queryField("SELECT username FROM zz_users WHERE userID = :userID", array(":userID" => $userID));
	}

	/**
	 * @param int $userID
	 * @return array|null
	*/
	public static function getSessions($userID)
	{
		return Db::query("SELECT sessionHash, dateCreated, validTill, userAgent, ip FROM zz_users_sessions WHERE userID = :userID", array(":userID" => $userID), 0);
	}

	/**
	 * @param int $userID
	 * @param string $sessionHash
	*/
	public static function deleteSession($userID, $sessionHash)
	{
		Db::execute("DELETE FROM zz_users_sessions WHERE userID = :userID AND sessionHash = :sessionHash", array(":userID" => $userID, ":sessionHash" => $sessionHash));
	}

	public static function getBalance($userID)
	{
		$balance = Db::queryField("select balance from zz_account_balance where userID = :userID", "balance", array(":userID" => $userID), 3600);
		if ($balance == null) $balance = 0;
		return $balance;
	}

	public static function getPaymentHistory($userID)
	{
		return Db::query("select * from zz_account_history where userID = :userID", array(":userID" => $userID), 0);
	}
}
