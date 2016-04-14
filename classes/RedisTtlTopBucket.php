<?php

class RedisTtlTopBucket
{
	private $ttl;
	private $bucketOne;
	private $bucketTwo;

	public function __construct($queueName, $ttl = 0)
	{
		$this->bucketOne = "rttb:$queueName:b1";
		$this->bucketTwo = "rttb:$queueName:b2";
		$this->ttl = $ttl;
	}

	public function add($time, $value, $key)
	{
		global $redis;
		
		$time = (int) $time;
		if ($time < (time() - $this->ttl)) return false;

		$multi = $redis->multi();
		$multi->zAdd($this->bucketOne, $time, $key);
		$multi->zAdd($this->bucketTwo, $value, $key);
		$multi->expire($this->bucketOne, $this->ttl);
		$multi->expire($this->bucketTwo, $this->ttl);
		$multi->exec();

		return true;
	}

	public function cleanup()
	{
		global $redis;

		$range = time() - $this->ttl;
		$expired = $redis->zRangeByScore($this->bucketOne, 0, $range);
		$multi = $redis->multi();
		foreach ($expired as $expire=>$value)
		{
			$multi->zRem($this->bucketOne, $expire);
			$multi->zRem($this->bucketTwo, $expire);
		}
		$multi->exec();
	}

	public function getTop($x = 10)
	{
		global $redis;

		$this->cleanup();

		$result = $redis->zRevRange($this->bucketTwo, 0, $x - 1);
		return $result;
	}
}
