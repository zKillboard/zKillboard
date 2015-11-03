<?php

class User
{
    public static function setLogin($username, $password, $autoLogin)
    {
        return true;
    }

    public static function checkLogin($username, $password)
    {
        return false;
    }

    public static function checkLoginHashed($userID)
    {
	return null;
    }

    public static function autoLogin()
    {
        return false;
    }

    public static function isLoggedIn()
    {
	
	return (int) @$_SESSION['characterID'] != null;
    }

    /**
     * @return array|null
     */
    public static function getUserInfo()
    {
	global $redis, $mdb;
	$id = self::getUserID();
        $info = $redis->hGetAll("user:$id");
	$info['username'] = $mdb->findField('information', 'name', ['type' => 'characterID', 'id' => (int) $id, 'cacheTime' => 300]);
	return $info;
    }

    /**
     * @return int
     */
    public static function getUserID()
    {
         return (int) @$_SESSION['characterID'];
    }

    /**
     * @return bool
     */
    public static function isModerator()
    {
	global $redis;
	$id = self::getUserID();
	return $redis->hGet("user:$id", "moderator") == 'true';
    }

    /**
     * @return bool
     */
    public static function isAdmin()
    {
	return false;
    }

    /**
     * @param int $userID
     *
     * @return string
     */
    public static function getUsername($userID)
    {
        return null;
    }

    /**
     * @param int $userID
     *
     * @return array|null
     */
    public static function getSessions($userID)
    {
        return null; 
    }

    public static function getBalance($userID)
    {
        $balance = Db::queryField('select balance from zz_account_balance where userID = :userID', 'balance', array(':userID' => $userID), 3600);
        if ($balance == null) {
            $balance = 0;
        }

        return $balance;
    }

    public static function getPaymentHistory($userID)
    {
        return Db::query('select * from zz_account_history where userID = :userID', array(':userID' => $userID), 0);
    }

    public static function getUserTrackerData()
    {
        $entities = array('character', 'corporation', 'alliance', 'faction', 'ship', 'item', 'system', 'region');
        $entlist = array();

        foreach ($entities as $ent) {
            Db::execute("update zz_users_config set locker = 'tracker_$ent' where locker = '$ent'");
            $result = UserConfig::get("tracker_$ent");
            $part = array();

            if ($result != null) {
                foreach ($result as $row) {
                    switch ($ent) {
                    case 'system':
                        $row['solarSystemID'] = $row['id'];
                        $row['solarSystemName'] = $row['name'];
                        break;

                    case 'item':
                        $row['typeID'] = $row['id'];
                        $row['shipName'] = $row['name'];
                        break;

                    case 'ship':
                        $row['shipTypeID'] = $row['id'];
                        $row["${ent}Name"] = $row['name'];
                        break;

                    default:
                        $row["${ent}ID"] = $row['id'];
                        $row["${ent}Name"] = $row['name'];
                        break;
                }
                    $part[] = $row;
                }
            }
            $entlist[$ent] = $part;
        }

        return $entlist;
    }
}
