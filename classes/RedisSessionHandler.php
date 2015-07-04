<?php

class RedisSessionHandler implements SessionHandlerInterface
{
    private $ttl = 7200; // 2hrs of cache

    public function open($savePath, $sessionName)
    {
        return true;
    }

    public function close()
    {
        return true;
    }

    public function read($id)
    {
	global $redis;

	return $redis->get($id);
    }

    public function write($id, $data)
    {
	global $redis;
	
	$redis->setex($id, $this->ttl, $data);

        return true;
    }

    public function destroy($id)
    {
	global $redis;

	$redis->del($id);

        return true;
    }

    public function gc($maxlifetime)
    {
        return true;
    }
}
