<?php

class RedisSessionHandler implements SessionHandlerInterface
{
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
        global $redis, $cookie_time;

	if ($data == '' || $data == 'slim.flash|a:0:{}') {
		$redis->del("sess:$id");
		return true;
	}

        $redis->setex("sess:$id", $cookie_time, $data);

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
