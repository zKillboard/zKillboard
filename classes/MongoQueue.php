<?php

class MongoQueue
{
    private $mdb;
    private $name;
    private $legacySet;
    private $legacyRedis;

    public function __construct($mdb, $name, $legacySet = false, $legacyRedis = true)
    {
        $this->mdb = $mdb;
        $this->name = $name;
        $this->legacySet = $legacySet;
        $this->legacyRedis = $legacyRedis;
    }

    public function push($value)
    {
        $this->mdb->insert('queues', ['queue' => $this->name, 'value' => $value, 'created' => Mdb::now()]);
        global $redis;
        $redis->incr('zkb:queue:count:' . $this->name);
    }

    public function add($value)
    {
        if (!$this->mdb->exists('queues', ['queue' => $this->name, 'value' => $value])) $this->push($value);
    }

    public function pop()
    {
        global $redis;

        if (!$this->legacyRedis) $value = false;
        else $value = $this->legacySet ? $redis->spop($this->name) : $redis->lPop($this->name);
        if ($value !== false && $value !== null) {
            $this->decrementCounter($redis);
            return $this->legacySet ? $value : unserialize($value);
        }

        $doc = $this->mdb->getCollection('queues')->findOneAndDelete(['queue' => $this->name], ['sort' => ['_id' => 1]]);
        if ($doc !== null) $this->decrementCounter($redis);
        return $doc === null ? null : $doc['value'];
    }

    private function decrementCounter($redis)
    {
        $redis->eval(
            "local value = redis.call('DECR', KEYS[1]); if value < 0 then redis.call('SET', KEYS[1], 0); return 0; end; return value;",
            ['zkb:queue:count:' . $this->name],
            1
        );
    }

    public function remove($value)
    {
        $this->mdb->remove('queues', ['queue' => $this->name, 'value' => $value]);
    }

    public function count()
    {
        global $redis;
        $count = $this->mdb->count('queues', ['queue' => $this->name]);
        if ($this->legacyRedis) $count += $this->legacySet ? $redis->sCard($this->name) : $redis->lLen($this->name);
        return $count;
    }
}
