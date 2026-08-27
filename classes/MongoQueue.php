<?php

class MongoQueue
{
    private $mdb;
    private $name;
    private $legacySet;

    public function __construct($mdb, $name, $legacySet = false)
    {
        $this->mdb = $mdb;
        $this->name = $name;
        $this->legacySet = $legacySet;
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

        $value = $this->legacySet ? $redis->spop($this->name) : $redis->lPop($this->name);
        if ($value !== false && $value !== null) return unserialize($value);

        $doc = $this->mdb->getCollection('queues')->findOneAndDelete(['queue' => $this->name], ['sort' => ['_id' => 1]]);
        return $doc === null ? null : $doc['value'];
    }

    public function remove($value)
    {
        $this->mdb->remove('queues', ['queue' => $this->name, 'value' => $value]);
    }

    public function count()
    {
        return $this->mdb->count('queues', ['queue' => $this->name]);
    }
}
