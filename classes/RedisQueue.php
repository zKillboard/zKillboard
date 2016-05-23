<?php

class RedisQueue
{
	private $queueName = null;

	public function __construct($queueName)
	{
		global $redis;

		$this->queueName = $queueName;
		$redis->sadd('queues', $queueName);
	}

	public function push($value)
	{
		global $redis;

		$redis->rPush($this->queueName, serialize($value));
	}

	public function pop()
	{
		global $redis;

		$array = $redis->blPop($this->queueName, 1);
		if (sizeof($array) == 0) {
			return;
		}

		return unserialize($array[1]);
	}

	public function clear()
	{
		global $redis;

		$redis->del($this->queueName);
	}

	public function size()
	{
		global $redis;

		return $redis->lLen($this->queueName);
	}
}
