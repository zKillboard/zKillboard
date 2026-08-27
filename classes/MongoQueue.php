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
        if ($value !== false && $value !== null) return $this->legacySet ? $value : unserialize($value);

        $doc = $this->mdb->getCollection('queues')->findOneAndDelete(['queue' => $this->name], ['sort' => ['_id' => 1]]);
        return $doc === null ? null : $doc['value'];
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
