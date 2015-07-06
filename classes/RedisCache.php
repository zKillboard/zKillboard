<?php

class RedisCache
{
    public static function get($key) {
	global $redis;

	$result = $redis->get("RC:$key");
	if ($result != null) return unserialize($result);
	return null;
    }

    public static function set($key, $value, $expireSeconds = 30) {
	global $redis;

	if ($expireSeconds < 1) return;
	if ($expireSeconds > 3600) $expireSeconds = 3600;
	$redis->setex("RC:$key", $expireSeconds, serialize($value));
    }
}
