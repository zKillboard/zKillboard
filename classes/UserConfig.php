<?php

class UserConfig
{
    public static function get($key, $defaultValue = null)
    {
        $info = User::getUserInfo();

        return isset($info[$key]) ? json_decode($info[$key], true) : $defaultValue;
    }

    public static function getAll()
    {
        if (!User::isLoggedIn()) {
            return [];
        }

        $userConfig = User::getUserInfo();

        foreach ($userConfig as $key => $value) {
            $userConfig[$key] = json_decode($value, true);
        }

        return $userConfig;
    }

    public static function set($key, $value)
    {
        global $mdb;

        
        if (!User::isLoggedIn()) {
            throw new Exception('User is not logged in.');
        }

        $id = User::getUserID();
        $mdb->set("users", ['userID' => "user:$id"], [$key => json_encode($value)]);

        return true;
    }
}
