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

		$value = serialize($value);
		return (null !== $redis->zScore($this->queueName, $value));
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

		$value = null;
                try {
                        $array = $redis->zRangeByScore($this->queueName, 0, time(), ['limit' => [0, 1]]);
			$next = sizeof($array) > 0 ? array_pop($array) : null;

			if ($next !== null) {
                        	$redis->zAdd($this->queueName, time() + $this->deltaSeconds, $next);
                        	$value = unserialize($next);
			}
                } finally {
                        sem_release($sem);
                }
		$doBlock = $value === null && $block === true;
		sleep($doBlock ? 1 : 0);
		return $doBlock ? $this->next(false) : $value;
        }
}
