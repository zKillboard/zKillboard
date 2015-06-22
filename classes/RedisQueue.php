<?php

class RedisQueue
{
	private $queueName = null;

	function __construct($queueName)
	{
		$this->queueName = $queueName;
	}

	public function push($value)
	{
		global $redis;

		$r = $redis->rPush($this->queueName, serialize($value));
	}

	public function pop()
	{
		global $redis;

		$array = $redis->blPop($this->queueName, 1);
		if (sizeof($array) == 0) return null;
		return unserialize($array[1]);
	}
}
