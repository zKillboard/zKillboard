<?php

class RedisCache
{
    public static function get($key)
    {
        global $redis;

	$key = md5($key);
        $result = $redis->get("RC:$key");
        if ($result != null) {
            return unserialize($result);
        }

        return;
    }

    public static function set($key, $value, $expireSeconds = 30)
    {
        global $redis;

        if ($expireSeconds < 1) {
            return;
        }
	$key = md5($key);
        $redis->setex("RC:$key", $expireSeconds, serialize($value));
    }
}
