<?php

class RedisTtlSortedSet
{
    private $queueName;
    private $ttl = 3600;

    public function __construct($queueName, $ttl)
    {
        $this->queueName = $queueName;
        $this->ttl = $ttl;
    }

    public function add($time, $value)
    {
        global $redis;

        if ($time < (time() - $this->ttl)) {
            return;
        }

        $value = serialize($value);
        $redis->zAdd($this->queueName, $time, $value);
    }

    public function cleanup()
    {
        global $redis;

        $redis->zRemRangeByScore($this->queueName, 0, (time() - $this->ttl));
    }

    public function count()
    {
        global $redis;

        $this->cleanup();

        return $redis->zCard($this->queueName);
    }

    public function getTime($value)
    {
        global $redis;

        $this->cleanup();
        $value = serialize($value);
        $time = $redis->zScore($this->queueName, $value);

        return $time;
    }

    public function getRevResult($page, $numPerPage)
    {
        global $redis;

        $this->cleanup();
        $start = $page * $numPerPage;
        $values = $redis->zRevRange($this->queueName, $start, $numPerPage - 1);

        $result = [];
        foreach ($values as $value) {
            $result[] = unserialize($value);
        }

        return $result;
    }
}
