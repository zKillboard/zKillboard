<?php

class zKBSession implements SessionHandlerInterface
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
        return Cache::get($id);
    }

    public function write($id, $data)
    {
        Cache::set($id, $data, $this->ttl);

        return true;
    }

    public function destroy($id)
    {
        Cache::delete($id);

        return true;
    }

    public function gc($maxlifetime)
    {
        return true;
    }
}
