<?php

class KVCache
{
    public static $localCache = [];
    public $mdb, $redis;

    public function __construct($mdb, $redis)
    {
        $this->mdb = $mdb;
        $this->redis = $redis;
    }

    public function get($key, $default = null)
    {
        $key = "kv:$key";
        $value = @KVCache::$localCache[$key];
        if ($value !== null) {
            if ($value['expiresAt'] <= time()) {
                unset(KVCache::$localCache[$key]);
                $value = null;
            } else $value = $value['value'];
        }
        
        if ($value === null) $value = $this->redis->get($key);
        if ($value === null) $value = $this->mdb->findField("keyvalues", "value", ['key' => $key, 'expiresAt' => ['$gt' => time()]]);
        if ($value === null) return $default;
        return json_decode($value);
    }

    public function set($key, $value, $ttl = null)
    {
        $key = "kv:$key";
        $value = json_encode($value);

        if ($ttl === null) $ttl = 86400 * 100; // 100 days
        $expiresAt = time() + $ttl;

        KVCache::$localCache[$key] = ['value' => $value, 'expiresAt' => $expiresAt];
        $this->redis->setex($key, $ttl, $value);
        $this->mdb->insertUpdate("keyvalues", ['key' => $key], ['value' => $value, 'expiresAt' => $expiresAt, $this->mdb->now($ttl)]);
    }

    public function setex($key, $ttl, $value)
    {
        $this->set($key, $value, $ttl);
    }

    public function del($key)
    {
        $key = "kv:$key";
        $this->redis->del($key);
        $this->mdb->remove("keyvalues", ["key" => $key]);
    }

    public function __call($func, $args)
    {
        global $redis;
        return $this->redis->$func(...$args);
    }
}
