<?php

class RedisTtlCounter
{
	private $queueName;
	private $ttl = 3600;

	function __construct($queueName, $ttl = 3600)
	{
		$this->queueName = $queueName;
		$this->ttl = $ttl;
	}

	public function add($value)
	{
		global $redis;

		$value = serialize($value);
		$redis->zAdd($this->queueName, (time() + $this->ttl), $value);
	}

	public function count()
	{
		global $redis;
		
		$redis->zRemRangeByScore($this->queueName, 0, time());
		return $redis->zCard($this->queueName);
	}
}
