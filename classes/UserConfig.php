<?php

class UserConfig
{
    public static function get($key, $defaultValue = null)
    {
        global $redis;

        $id = User::getUserID();
        $value = $redis->hGet("user:$id", $key);
        if ($value === false) {
            return $defaultValue;
        }

        return json_decode($value, true);
    }

    public static function getAll()
    {
        global $redis, $mdb;
        if (!User::isLoggedIn()) {
            return [];
        }

        $id = User::getUserID();
        $userConfig = $redis->hGetAll("user:$id");
        $userConfig['username'] = $mdb->findField('information', 'name', ['type' => 'characterID', 'id' => (int) $id, 'cacheTime' => 300]);

        foreach ($userConfig as $key => $value) {
            $userConfig[$key] = json_decode($value, true);
        }

        return $userConfig;
    }

    public static function set($key, $value)
    {
        global $redis;
        if (!User::isLoggedIn()) {
            throw new Exception('User is not logged in.');
        }
        $id = User::getUserID();

        if (is_null($value) || (is_string($value) && strlen(trim($value)) == 0)) {
            $redis->hDel("user:$id", $key);

            return true;
        }

        $redis->hSet("user:$id", $key, json_encode($value));

        return true;
    }
}
