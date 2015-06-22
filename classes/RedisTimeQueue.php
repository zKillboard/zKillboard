<?php

class RedisTimeQueue
{
	private $queueName;

	function __construct($queueName)
	{
		$this->queueName = $queueName;
	}

	public function add($value, $deltaSeconds = 0)
	{
		global $redis;

		$value = serialize($value);
		if ($redis->zScore($this->queueName, $value) === false) $redis->zAdd($this->queueName, (0 + $deltaSeconds), $value);
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

		$next = $redis->zRange($this->queueName, 0, 0, true);
		if ($next === null) return null;
		$value = key($next);
		$time = $next[$value];

		if ($time >= time() && $block == false) return null;
		if ($time >= time())
		{
			sleep(1); // Block...
			return $this->next(false);
		}

		$redis->zAdd($this->queueName, time(), $value);
		$value = unserialize($value);
		return $value;
	}
}
