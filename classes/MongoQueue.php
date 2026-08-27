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
        if ($this->name != 'zkb:updatenames') $redis->incr('zkb:queue:count:' . $this->name);
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
            if ($this->name != 'zkb:updatenames') $this->decrementCounter($redis);
            return $this->legacySet ? $value : unserialize($value);
        }

        $doc = $this->mdb->getCollection('queues')->findOneAndDelete(['queue' => $this->name], ['sort' => ['_id' => 1]]);
        if ($doc !== null && $this->name != 'zkb:updatenames') $this->decrementCounter($redis);
        return $doc === null ? null : $doc['value'];
    }

    public function popMany($limit)
    {
        global $redis;

        $values = [];
        while (sizeof($values) < $limit && $this->legacyRedis) {
            $value = $this->legacySet ? $redis->spop($this->name) : $redis->lPop($this->name);
            if ($value === false || $value === null) break;
            $values[] = $this->legacySet ? $value : unserialize($value);
        }

        $remaining = $limit - sizeof($values);
        if ($remaining > 0) {
            $docs = $this->mdb->getCollection('queues')->find(
                ['queue' => $this->name],
                ['sort' => ['_id' => 1], 'limit' => $remaining, 'projection' => ['_id' => 1, 'value' => 1]]
            );
            $ids = [];
            foreach ($docs as $doc) {
                $values[] = $doc['value'];
                $ids[] = $doc['_id'];
            }
            if (sizeof($ids) > 0) {
                $this->mdb->getCollection('queues')->deleteMany(['_id' => ['$in' => $ids]]);
            }
        }

        return $values;
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
        global $redis;
        do {
            $removed = $this->mdb->remove('queues', ['queue' => $this->name, 'value' => $value]);
            if (($removed['n'] ?? 0) > 0 && $this->name != 'zkb:updatenames') {
                $this->decrementCounter($redis);
            }
        } while (($removed['n'] ?? 0) > 0);
    }

    public function count()
    {
        global $redis;
        $count = $this->mdb->count('queues', ['queue' => $this->name]);
        if ($this->legacyRedis) $count += $this->legacySet ? $redis->sCard($this->name) : $redis->lLen($this->name);
        return $count;
    }
}
