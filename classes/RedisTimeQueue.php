<?php

class RedisTimeQueue
{
    private $queueName;
    private $deltaSeconds;
    private $queueSemNumber;

    public function __construct($queueName, $deltaSeconds = 3600)
    {
        $this->queueName = $queueName;
        $this->deltaSeconds = $deltaSeconds;

	$name = strtolower($queueName);
	while (strlen($name) > 0) {
		$this->queueSemNumber += ord(substr($name, 0, 1));
		$name = substr($name, 1);
	}
    }

    public function size()
    {
	global $redis;

        return $redis->zCard($this->queueName);
    }

    public function add($value, $deltaSeconds = 0)
    {
        global $redis;

        $value = serialize($value);
        if ($redis->zScore($this->queueName, $value) === false) {
            $redis->zAdd($this->queueName, (0 + $deltaSeconds), $value);
        }
    }

    public function isMember($value)
    {
	global $redis;

	return (null != $redis->zScore($this->queueName, $value));
    }

    public function remove($value)
    {
        global $redis;

        $value = serialize($value);
        $redis->zRem($this->queueName, $value);
    }

    public function setTime($value, $time)
    {
        global $redis;

        $value = serialize($value);
        $redis->zAdd($this->queueName, $time, $value);
    }

    public function next($block = true)
    {
        global $redis;

        $sem = sem_get($this->queueSemNumber);
        if (!sem_acquire($sem)) {
            throw new Exception('Unable to obtain kmdb semaphore');
        }

        $next = $redis->zRange($this->queueName, 0, 0, true);
        if ($next === null) {
	    sem_release($sem);
            return;
        }
        if (!is_array($next)) {
	    sem_release($sem);
            return;
        }
        $value = key($next);
        if (!isset($next[$value])) {
	    sem_release($sem);
            return;
        }
        $time = $next[$value];

        if ($time >= time() && $block == false) {
	    sem_release($sem);
            return;
        }
        if ($time >= time()) {
	    sem_release($sem);
            sleep(1); // Block...
            return $this->next(false);
        }

        $redis->zAdd($this->queueName, time() + $this->deltaSeconds, $value);
	sem_release($sem);
        $value = unserialize($value);

        return $value;
    }
}
