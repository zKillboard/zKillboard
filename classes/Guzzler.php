<?php

class Guzzler
{
    private $curl;
    private $handler;
    private $client;
    private $concurrent = 0;
    private $maxConcurrent;
    private $usleep;

    public function __construct($maxConcurrent = 10, $usleep = 100000)
    {
        $this->curl = new \GuzzleHttp\Handler\CurlMultiHandler();
        $this->handler = \GuzzleHttp\HandlerStack::create($this->curl);
        $this->client = new \GuzzleHttp\Client(['connect_timeout' => 10, 'timeout' => 30, 'handler' => $this->handler, 'User-Agent' => 'zkillboard.com']);
        $this->maxConcurrent = max($maxConcurrent, 1);
        $this->usleep = max((int) $usleep, min(1000000, (int) $usleep));
    }

    public function tick()
    {
        $ms = microtime();
        do {
            $this->curl->tick();
        } while ($this->concurrent >= $this->maxConcurrent);
        return max(1, microtime() - $ms);
    }

    public function finish()
    {
        $ms = microtime();
        $this->curl->execute();
        return max(1, microtime() - $ms);
    }

    public function inc()
    {
        $this->concurrent++;
    }

    public function dec()
    {
        $this->concurrent--;
    }

    public function call($url, $fulfilled, $rejected, $params)
    {
        $guzzler = $this;
        $this->client->getAsync($url)->then(
            function($response) use (&$guzzler, $fulfilled, $rejected, &$params) {
                $guzzler->dec();
                $content = (string) $response->getBody();
                $fulfilled($guzzler, $params, $content);
            },
            function($connectionException) use (&$guzzler, &$rejected, &$params) {
                $guzzler->dec();
                $rejected($guzzler, $params, $connectionException->getCode());
            });
        $this->inc();
        $ms = $this->tick();
        usleep(min(1000000, max(1, $this->usleep - $ms)));
    }
}
