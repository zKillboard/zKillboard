<?php

class Password
{
	public static function genPassword($password)
	{
		return password_hash($password, PASSWORD_BCRYPT);
	}

	public static function updatePassword($password)
	{
		$userID = user::getUserID();
		$password = self::genPassword($password);
		Db::execute("UPDATE zz_users SET password = :password WHERE id = :userID", array(":password" => $password, ":userID" => $userID));
		return "Updated password";
	}

	/**
	 * @param string $plainTextPassword
	 */
	public static function checkPassword($plainTextPassword, $storedPassword = NULL)
	{
		if($plainTextPassword && $storedPassword)
			return self::pwCheck($plainTextPassword, $storedPassword);
		else
		{
			$userID = user::getUserID();
			if($userID)
			{
				$storedPw = Db::queryField("SELECT password FROM zz_users WHERE id = :userID", "password", array(":userID" => $userID), 0);
				return self::pwCheck($plainTextPassword, $storedPw);
			}
		}
	}

	private static function pwCheck($plainTextPassword, $storedPassword)
	{
		if (!password_verify($plainTextPassword, $storedPassword))
			return false;
		return true;
	}
}
