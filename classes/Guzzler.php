<?php

class Guzzler
{
    private $curl;
    private $handler;
    private $client;
    private $concurrent = 0;
    private $maxConcurrent;
    private $usleep;
    private $lastHeaders = [];

    public function __construct($maxConcurrent = 10, $usleep = 100000)
    {
        global $redis;

        $this->curl = new \GuzzleHttp\Handler\CurlMultiHandler();
        $this->handler = \GuzzleHttp\HandlerStack::create($this->curl);
        $this->client = new \GuzzleHttp\Client(['curl' => [CURLOPT_FRESH_CONNECT => false], 'connect_timeout' => 10, 'timeout' => 60, 'handler' => $this->handler, 'headers' => ['User-Agent' => 'zkillboard.com']]);
        $this->maxConcurrent = max($maxConcurrent, 1);
        $this->usleep = max(0, min(1000000, (int) $usleep));
    }

    public function tick()
    {
        $ms = microtime();
        do {
            $this->curl->tick();
        } while ($this->concurrent >= $this->maxConcurrent);
        return max(0, microtime() - $ms);
    }

    public function finish()
    {
        $ms = microtime();
        $this->curl->execute();
        return max(0, microtime() - $ms);
    }

    public function count()
    {
        return $this->concurrent;
    }

    public function inc()
    {
        $this->concurrent++;
    }

    public function dec()
    {
        $this->concurrent--;
    }

    public function call($uri, $fulfilled, $rejected, $params = [], $setup = [], $callType = 'GET', $body = null)
    {
        global $redis;

        $this->verifyCallable($fulfilled);
        $this->verifyCallable($rejected);
        $params['uri'] = $uri;
        $params['callType'] = strtoupper($callType);

        $statusType = self::getType($uri);

        $etag = $redis->hget("zkb:etags", $uri);
        if ($etag && $params['callType'] == 'GET') $setup['If-None-Match'] = $etag;

        $guzzler = $this;
        $request = new \GuzzleHttp\Psr7\Request($callType, $uri, $setup, $body);
        $this->client->sendAsync($request)->then(
            function($response) use (&$guzzler, $fulfilled, $rejected, &$params, $statusType) {
                global $redis;

                $guzzler->dec();
                $content = (string) $response->getBody();
                Status::addStatus($statusType, true);
                $this->lastHeaders = array_change_key_case($response->getHeaders());
                if (isset($this->lastHeaders['warning'])) Util::out("Warning: " . $params['uri'] . " " . $this->lastHeaders['warning'][0]);
                if (isset($this->lastHeaders['etag'])) $redis->hset("zkb:etags", $params['uri'], $this->lastHeaders['etag'][0]);
                $code = $response->getStatusCode();
                Status::addStatus("cached304", ($response->getStatusCode() == 304));

                $fulfilled($guzzler, $params, $content);
            },
            function($connectionException) use (&$guzzler, &$rejected, &$params, $statusType) {
                $guzzler->dec();
                Status::addStatus($statusType, false);
                $response = $connectionException->getResponse();
                $this->lastHeaders = $response == null ? [] : array_change_key_case($response->getHeaders());
                $params['content'] = method_exists($connectionException->getResponse(), "getBody") ? (string) $connectionException->getResponse()->getBody() : "";
                $code = $connectionException->getCode();

                $rejected($guzzler, $params, $connectionException);
            });
        $this->inc();
        $ms = $this->tick();
        $sleep = min(1000000, max(0, $this->usleep - $ms));
        usleep($sleep);
    }

    public function verifyCallable($callable)
    {
        if (!is_callable($callable)) {
            throw new InvalidArgumentException(print_r($callable, true) . " is not a callable function");
        }
    }

    public function getType($uri)
    {
        if (strpos($uri, 'esi.evetech') !== false) return 'esi';
        if (strpos($uri, 'esi.tech') !== false) return 'esi';
        if (strpos($uri, 'crest-tq') !== false) return 'crest';
        if (strpos($uri, 'login') !== false) return 'sso';
        if (strpos($uri, 'api.eve') !== false) return 'xml';
        Log::log("Unknown type for $uri");
        return 'unknown';
    }

    public function getLastHeaders()
    {
        return $this->lastHeaders;
    }
}
