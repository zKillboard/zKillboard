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
        $this->client = new \GuzzleHttp\Client(['curl' => [CURLOPT_FRESH_CONNECT => false], 'connect_timeout' => 30, 'timeout' => 30, 'handler' => $this->handler, 'headers' => ['User-Agent' => 'zkillboard.com']]);
        $this->maxConcurrent = ($redis->get("zkb:420prone") == "true") ? 1 : $maxConcurrent;
    }

    public function sleep($seconds = 0, $microseconds = 0)
    {
        $time = max(0, ($seconds * 1000000) + $microseconds);
        $t = new Timer();
        do {
            $this->tick();
            usleep(100);
            $stop = 1000 * ceil($t->stop());
        } while ($time >= $stop);
    }

    public function tick()
    {
        $ms = (int) microtime();
        do {
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
            Util::out("tqCountInt < 100 or 420ed is true");
            $this->tick();
            $this->sleep(1);
            $iterations++;
            if ($iterations > 60) return;
        }

        $statusType = self::getType($uri);

        $etag = null;
        if (@$setup['etag'] == true && $params['callType'] == 'GET') {
            $etag = Etag::get($uri); //$redis->hget("zkb:etags:" . date('m:d'), $uri);
            if ($etag !== false) $setup['If-None-Match'] = $etag;
            unset($setup['etag']);
        }

        //while (((int) $redis->get("concurrent")) > 10) $this->sleep(0, 1000);
        $redis->incr("concurrent");
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

        $request = new \GuzzleHttp\Psr7\Request($callType, $uri, $setup, $body);
        $this->client->sendAsync($request)->then(
                function($response) use (&$guzzler, $fulfilled, $rejected, &$params, $statusType, $etag) {
                global $redis;

                try {
                $guzzler->dec();
                $redis->decr("concurrent");
                $content = (string) $response->getBody();
                Status::addStatus($statusType, true);
                $this->lastHeaders = array_change_key_case($response->getHeaders());
                if (isset($this->lastHeaders['warning'])) Util::out("Warning: " . $params['uri'] . " " . $this->lastHeaders['warning'][0]);
                if (isset($this->lastHeaders['etag']) && strlen($content) > 0 && $etag !== null) Etag::set($params['uri'], $this->lastHeaders['etag'][0]);
                if ($etag !== null) Status::addStatus("cached304", ($response->getStatusCode() == 304));

                $fulfilled($guzzler, $params, $content);
                } catch (Exception $ex) {
                Log::log(print_r($ex, true));
                }
                },
                function($connectionException) use (&$guzzler, &$fulfilled, &$rejected, &$params, $statusType, $uri, $setup, $callType, $body) {
                global $redis;

                try {
                    $guzzler->dec();
                    $redis->decr("concurrent");
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
if (@$params['retryCount'] > 2) Log::log("$uri ($code)\n$h");
                        $this->call($uri, $fulfilled, $rejected, $params, $setup, $callType, $body);
                    } else {
                        //Log::log($params['uri'] . " $code" . ($params['content'] != '' ? "\n" . $params['content'] : ''));
                        $rejected($guzzler, $params, $connectionException);
                    }
                } catch (Exception $ex) {
                    Log::log(print_r($ex, true));
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
        Log::log("Unknown type for $uri");
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
                Log::log("420'ed for $errorReset seconds");
                $redis->setex("zkb:420prone", 300, "true");
            }
        }
    }
}
