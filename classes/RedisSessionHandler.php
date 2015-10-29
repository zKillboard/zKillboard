<?php

class RedisSessionHandler implements SessionHandlerInterface
{
    private $ttl = 1209600;

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

        return $redis->get("sess:$id");
    }

    public function write($id, $data)
    {
        global $redis;

        $redis->setex("sess:$id", $this->ttl, $data);

        return true;
    }

    public function destroy($id)
    {
        global $redis;

        $redis->del("sess:$id");

        return true;
    }

    public function gc($maxlifetime)
    {
        return true;
    }
}
