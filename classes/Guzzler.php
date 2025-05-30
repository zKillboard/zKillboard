<?php

class Guzzler
{
    private $curl;
    private $handler;
    private $client;
    private $concurrent = 0;
    private $maxConcurrent;
    private $lastHeaders = [];

    public function __construct($maxConcurrent = 10)
    {
        global $redis;

        $this->curl = new \GuzzleHttp\Handler\CurlMultiHandler();
        $this->handler = \GuzzleHttp\HandlerStack::create($this->curl);
        $this->client = new \GuzzleHttp\Client(['curl' => [CURLOPT_FRESH_CONNECT => false], 'connect_timeout' => 11, 'timeout' => 11, 'handler' => $this->handler, 'headers' => ['User-Agent' => 'zkillboard.com']]);
        $this->maxConcurrent = ($redis->get("zkb:420prone") == "true") ? 1 : $maxConcurrent;
    }

    public function sleep($seconds = 0, $microseconds = 0)
    {
        $time = max(0, ($seconds * 1000000) + $microseconds);
        $t = new Timer();
        do {
            $this->tick();
            usleep(10000);
            $stop = 1000 * ceil($t->stop());
        } while ($time >= $stop);
    }

    public function tick()
    {
        $ms = (int) microtime();
        do {
            usleep(10000);
            $this->curl->tick();
        } while ($this->concurrent >= $this->maxConcurrent);
        return max(0, ((int) microtime()) - $ms);
    }

    public function finish()
    {
        $ms = (int) microtime();
        $this->curl->execute();
        return max(0, ((int) microtime()) - $ms);
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
        global $redis, $debug;

        $this->verifyCallable($fulfilled);
        $this->verifyCallable($rejected);
        $params['uri'] = $uri;
        $params['callType'] = strtoupper($callType);
        $params['fulfilled'] = $fulfilled;
        $params['rejected'] = $rejected;

        while ($this->concurrent >= $this->maxConcurrent) $this->tick();

        $iterations = 0;
        while ($redis->get("tqCountInt") < 100 || $redis->get("zkb:420ed") == "true") {
            //Util::out("tqCountInt < 100 or 420ed is true");
            $this->tick();
            $this->sleep(1);
            $iterations++;
            if ($iterations > 60) return;
        }

        $statusType = self::getType($uri);

        $guzzler = $this;

        if ($callType == "POST" && $body != null) {
            $uri = $uri . "?";
            $p = json_decode($body, true);
            foreach ($p as $k => $v) {
                $uri .= "$k=$v&";
            }
            $uri =  substr_replace($uri ,"", -1);

            $setup['Content-Type'] = 'application/x-www-form-urlencoded';
            $body = null;
        }

        if ($callType == "POST_JSON") {
            $callType = "POST";
            $setup['Content-Type'] = 'application/json';
        }

        $request = new \GuzzleHttp\Psr7\Request($callType, $uri, $setup, $body);
        $this->client->sendAsync($request)->then(
                function($response) use (&$guzzler, $fulfilled, $rejected, &$params, $statusType) {
                global $redis;

                try {
                $guzzler->dec();
                $content = (string) $response->getBody();
                Status::addStatus($statusType, true);
                $this->lastHeaders = array_change_key_case($response->getHeaders());
                if (isset($this->lastHeaders['warning'])) Util::out("Warning: " . $params['uri'] . " " . $this->lastHeaders['warning'][0]);

                $fulfilled($guzzler, $params, $content);
                } catch (Exception $ex) {
                Util::zout(print_r($ex, true));
                }
                },
                function($connectionException) use (&$guzzler, &$fulfilled, &$rejected, &$params, $statusType, $uri, $setup, $callType, $body) {
                global $redis;

                try {
                    $guzzler->dec();
                    Status::addStatus($statusType, false);
                    $response = $connectionException->getResponse();
                    $this->lastHeaders = $response == null ? [] : array_change_key_case($response->getHeaders());
                    $params['content'] = method_exists($connectionException->getResponse(), "getBody") ? (string) $connectionException->getResponse()->getBody() : "";
                    $code = $connectionException->getCode();
                    $sleep = $this->setEsiErrorCount();
                    sleep(1);

                    if (($code == 0 || $code >= 500) && @$params['retryCount'] <= 3) {
                        $params['retryCount'] = @$params['retryCount'] + 1;
                        //if (@$params['retryCount'] > 2) Util::out("guzzler retrying $uri (http error $code) retry number " . $params['retryCount']);
                        $h = $params['content'] . "\n";
                        foreach ($this->lastHeaders as $name => $values) {
                            $h = $h . "$name: "  . implode(',', $values) . "\n";
                        }
                        if (@$params['retryCount'] > 2) Util::zout("$uri ($code)\n$h");
                        $this->call($uri, $fulfilled, $rejected, $params, $setup, $callType, $body);
                    } else {
                        $rejected($guzzler, $params, $connectionException);
                    }
                } catch (Exception $ex) {
                    Util::zout(print_r($ex, true));
                }
                });
        $this->inc();
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
        if (strpos($uri, 'evewho') !== false) return 'evewho';
        Util::zout("Unknown type for $uri");
        return 'unknown';
    }

    public function getLastHeaders()
    {
        return $this->lastHeaders;
    }

    public function setEsiErrorCount()
    {
        global $redis;

        $headers = $this->getLastHeaders();
        if (!isset($headers['x-esi-error-limit-reset'])) return;

        $errorCount = $headers['x-esi-error-limit-remain'][0];
        $errorReset = max(1, (int) $headers['x-esi-error-limit-reset'][0]) + 1;
        if ($errorCount == 0) {
            $i = $redis->set("zkb:420ed", "true", ['nx', 'ex' => $errorReset]);
            if ($i === true) {
                Util::zout("420'ed for $errorReset seconds");
                $redis->setex("zkb:420prone", 300, "true");
            }
        }
    }
}
