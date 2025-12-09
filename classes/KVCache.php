<?php

class KVCache
{
    public static $localCache = [];
    public $mdb, $redis;

    public function __construct($mdb, $redis)
    {
		// Check parameters to ensure they are not null
		if ($mdb === null || $redis === null) {
			throw new InvalidArgumentException("MDB and Redis instances cannot be null");
		}
        $this->mdb = $mdb;
        $this->redis = $redis;
    }

    public function get($key, $default = null)
    {
        $key = "kv:$key";
        
        // Check local cache
        if (isset(KVCache::$localCache[$key])) {
            $cached = KVCache::$localCache[$key];
            if ($cached['expiresAt'] <= time()) {
                unset(KVCache::$localCache[$key]);
            } else {
                return json_decode($cached['value']);
            }
        }
        
        // Check Redis
        $value = $this->redis->get($key);
        if ($value === false || $value === null) {
            // Check MongoDB as fallback
            $value = $this->mdb->findField("keyvalues", "value", ['key' => $key, 'expiresAt' => ['$gt' => $this->mdb->now()]]);
        }
        
        if ($value === null || $value === false) return $default;
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
        $this->mdb->insertUpdate("keyvalues", ['key' => $key], ['value' => $value, 'expiresAt' => $this->mdb->now($ttl), 'updated' => $this->mdb->now()]);
    }

    public function setex($key, $ttl, $value)
    {
        $this->set($key, $value, $ttl);
    }

    public function del($key)
    {
        $key = "kv:$key";
        unset(KVCache::$localCache[$key]);
        $this->redis->del($key);
        $this->mdb->remove("keyvalues", ["key" => $key]);
    }

    public function __call($func, $args)
    {
        return $this->redis->$func(...$args);
    }
}
