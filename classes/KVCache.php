<?php

class KVCache
{
	private $collection;

	public function __construct($mdb)
	{
		if ($mdb === null) {
			throw new InvalidArgumentException('MDB instance cannot be null');
		}
		$this->collection = $mdb->getCollection('keyvalues');
	}

	private static function now($delta = 0): MongoDB\BSON\UTCDateTime
	{
		return new MongoDB\BSON\UTCDateTime((time() + $delta) * 1000);
	}

	public function get($key, $default = null)
	{
		$doc = $this->collection->findOne(
			['key' => $key, 'expiresAt' => ['$gt' => self::now()]],
			['projection' => ['value' => 1, '_id' => 0]]
		);
		if ($doc === null || !isset($doc['value']))
			return $default;
		return json_decode($doc['value']);
	}

	public function set($key, $value, $ttl = null)
	{
		$value = json_encode($value);
		if ($ttl === null)
			$ttl = 86400 * 100;  // 100 days
		$this->collection->findOneAndUpdate(
			['key' => $key],
			['$set' => ['value' => $value, 'expiresAt' => self::now($ttl), 'updated' => self::now()]],
			['upsert' => true]
		);
	}

	public function setex($key, $ttl, $value)
	{
		$this->set($key, $value, $ttl);
	}

	public function del($key)
	{
		$this->collection->deleteOne(['key' => $key]);
	}
}
