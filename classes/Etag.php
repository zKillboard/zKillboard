<?php

class Etag
{
    public static function get($key)
    {
        global $redis;

        $now = time();
        $date = $now - ($now % 86400);
        $today = date('Y-m-d', $date);
        for ($i = 0; $i < 7; $i++)
        {
            $hkey = "zkb:etags:" . date('Y-m-d', $date - (86400 * $i));
            $value = $redis->hget($hkey, $key);
            if ($value !== false) {
                if ($i > 0) {
                    $redis->hdel($hkey, $key);
                    $redis->hset("zkb:etags:$today", $key, $value);
                    $redis->expire("zkb:etags:$today", (86400 * 7));
                }
                return $value;
            }
        }
        return false;
    }

    public static function set($key, $value)
    {
        global $redis;

        $now = time();
        $date = $now - ($now % 86400);
        $today = date('Y-m-d', $date);
        for ($i = 1; $i < 7; $i++)
        {
            $hkey = "zkb:etags:" . date('Y-m-d', $date - (86400 * $i));
            $redis->hdel($hkey, $key);
        }
        $redis->hset("zkb:etags:$today", $key, $value);
        $redis->expire("zkb:etags:$today", (86400 * 7));
    }
}
