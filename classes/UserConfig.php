<?php

class UserConfig
{
    private static $userConfig = null;

    /**
     * @param int|null $id
     */
    private static function loadUserConfig($id)
    {
        if (self::$userConfig != null) {
            return;
        }
        self::$userConfig = array();
        $result = Db::query('select * from zz_users_config where id = :id', array(':id' => $id), 0);
        foreach ($result as $row) {
            self::$userConfig[$row['locker']] = $row['content'];
        }
    }

    public static function get($key, $defaultValue = null)
    {
        if (!User::isLoggedIn()) {
            return $defaultValue;
        }
        $id = User::getUserID();
        self::loadUserConfig($id);

        $value = isset(self::$userConfig["$key"]) ? self::$userConfig["$key"] : null;
        if ($value === null) {
            return $defaultValue;
        }
        $value = json_decode($value, true);

        return $value;
    }

    public static function getAll()
    {
        if (!user::isLoggedIn()) {
            return;
        }

        $id = User::getUserID();
        self::loadUserConfig($id);

        foreach (self::$userConfig as $key => $value) {
            self::$userConfig[$key] = json_decode($value, true);
        }

        return self::$userConfig;
    }

    public static function set($key, $value)
    {
        if (!User::isLoggedIn()) {
            throw new Exception('User is not logged in.');
        }
        $id = User::getUserID();
        self::$userConfig = null;

        if (is_null($value) || (is_string($value) && strlen(trim($value)) == 0)) {
            // Just remove the row and let the defaults take over
            return Db::execute('delete from zz_users_config where id = :id and locker = :key', array(':id' => $id, ':key' => $key));
        }

        $value = json_encode($value);

        return Db::execute('insert into zz_users_config (id, locker, content) values (:id, :key, :value)
                                on duplicate key update content = :value', array(':id' => $id, ':key' => $key, ':value' => $value));
    }
}
