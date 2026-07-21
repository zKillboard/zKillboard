<?php

use cvweiss\redistools\RedisTtlCounter;

class Status
{
    private static $esiBucketSet = 'zkb:esi:status:buckets';
    private static $esiBucketNames = 'zkb:esi:status:bucketNames';
    private static $esiConditionalHeaders = 'zkb:esi:conditional:headers:';

    public static function addStatus($apiType, $success, $seconds = 300)
    {
        $apiType = strtolower($apiType);
//if ($apiType == 'crest') throw new Exception('what, where?');
        $status = $success == true ? "Success" : "Failure";
        $rtc = new RedisTtlCounter("ttlc:{$apiType}{$status}", $seconds);
        $rtc->add(uniqid());
    }

    public static function getStatus($apiType, $success, $seconds = 300)
    {
        $apiType = strtolower($apiType);
        $status = $success == true ? "Success" : "Failure";
        $rtc = new RedisTtlCounter("ttlc:{$apiType}{$status}", $seconds);
        return $rtc->count();
    }

    public static function addEsiStatus($uri, $code, $headers = [], $seconds = 300)
    {
        global $redis;

        if (strpos($uri, 'esi.evetech') === false && strpos($uri, 'esi.tech') === false) return;

        $headers = self::normalizeHeaders($headers);

        $bucket = self::getHeader($headers, 'x-ratelimit-group');
        if ($bucket === null || $bucket === '') $bucket = self::normalizeEsiUri($uri);
        $bucket = preg_replace('/^endpoint:/', '', $bucket);
        if (preg_match('/^markets(\/|$)/', $bucket)) $bucket = 'markets';
        $bucketKey = trim(preg_replace('/[^a-z0-9_.-]+/', '_', strtolower($bucket)), '_') ?: 'unknown';
        $code = (int) $code;
        $codeLabel = $code > 0 ? "$code" : '0';
        $id = uniqid('', true);

        $redis->sAdd(self::$esiBucketSet, $bucketKey);
        $redis->hSet(self::$esiBucketNames, $bucketKey, $bucket);
        $redis->sAdd("zkb:esi:status:codes:$bucketKey", $codeLabel);
        $redis->expire("zkb:esi:status:codes:$bucketKey", 86400);

        $all = new RedisTtlCounter("ttlc:esi:status:$bucketKey:all", $seconds);
        $all->add($id);
        $codes = new RedisTtlCounter("ttlc:esi:status:$bucketKey:code:$codeLabel", $seconds);
        $codes->add($id);

        $lastKey = "zkb:esi:status:last:$bucketKey";
        $redis->hSet($lastKey, 'bucket', $bucket);
        $redis->hSet($lastKey, 'code', $codeLabel);
        $redis->hSet($lastKey, 'uri', self::normalizeEsiUri($uri));
        $redis->hSet($lastKey, 'updated', time());
        self::setLastHeaderValue($lastKey, 'limit', self::getHeader($headers, 'x-ratelimit-limit'));
        self::setLastHeaderValue($lastKey, 'remaining', self::getHeader($headers, 'x-ratelimit-remaining'));
        self::setLastHeaderValue($lastKey, 'used', self::getHeader($headers, 'x-ratelimit-used'));
        self::setLastHeaderValue($lastKey, 'retry_after', self::getHeader($headers, 'retry-after'));
        self::setLastHeaderValue($lastKey, 'error_remain', self::getHeader($headers, 'x-esi-error-limit-remain'));
        self::setLastHeaderValue($lastKey, 'error_reset', self::getHeader($headers, 'x-esi-error-limit-reset'));
        $redis->expire($lastKey, 86400);
    }

    public static function addEsiStatusFromHttpResponseHeaders($uri, $httpResponseHeaders = [], $seconds = 300)
    {
        $code = 0;
        $headers = [];
        foreach ($httpResponseHeaders as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $match)) {
                $code = (int) $match[1];
                $headers = [];
                continue;
            }
            $header = explode(':', $line, 2);
            if (count($header) != 2) continue;
            $name = strtolower(trim($header[0]));
            $headers[$name][] = trim($header[1]);
        }
        self::addEsiStatus($uri, $code, $headers, $seconds);
    }

    public static function getEsiConditionalHeaders($uri, $forCurl = false)
    {
        global $redis;

        $values = $redis->hGetAll(self::getEsiConditionalHeadersKey($uri));
        if (!is_array($values)) return [];

        $headers = [];
        if (!empty($values['etag'])) {
            if ($forCurl) $headers[] = "If-None-Match: " . $values['etag'];
            else $headers['If-None-Match'] = $values['etag'];
        }
        if (!empty($values['last-modified'])) {
            if ($forCurl) $headers[] = "If-Modified-Since: " . $values['last-modified'];
            else $headers['If-Modified-Since'] = $values['last-modified'];
        }
        return $headers;
    }

    public static function saveEsiConditionalHeaders($uri, $headers, $seconds = 2592000)
    {
        global $redis;

        $headers = self::normalizeHeaders($headers);
        $etag = self::getHeader($headers, 'etag');
        $lastModified = self::getHeader($headers, 'last-modified');
        if (($etag === null || $etag === '') && ($lastModified === null || $lastModified === '')) return;

        $key = self::getEsiConditionalHeadersKey($uri);
        if ($etag !== null && $etag !== '') $redis->hSet($key, 'etag', $etag);
        if ($lastModified !== null && $lastModified !== '') $redis->hSet($key, 'last-modified', $lastModified);
        $redis->expire($key, $seconds);
    }

    public static function getEsiStatus($seconds = 300)
    {
        global $redis;

        $bucketKeys = $redis->sMembers(self::$esiBucketSet);
        if (!is_array($bucketKeys)) return [];

        $rows = [];
        foreach ($bucketKeys as $bucketKey) {
            $bucket = $redis->hGet(self::$esiBucketNames, $bucketKey);
            if ($bucket == false) $bucket = $bucketKey;
            $bucket = preg_replace('/^endpoint:/', '', $bucket);
            if (preg_match('/^markets(\/|$)/', $bucket)) $bucket = 'markets';
            $aggregateKey = trim(preg_replace('/[^a-z0-9_.-]+/', '_', strtolower($bucket)), '_') ?: 'unknown';
            if (!isset($rows[$aggregateKey])) $rows[$aggregateKey] = ['bucket' => $bucket, 'total' => 0, 'success' => 0, 'failure' => 0, 'token_cost' => 0, 'codes' => [], 'last' => []];

            $all = new RedisTtlCounter("ttlc:esi:status:$bucketKey:all", $seconds);
            $rows[$aggregateKey]['total'] += $all->count();

            $codes = $redis->sMembers("zkb:esi:status:codes:$bucketKey");
            if (!is_array($codes)) $codes = [];

            foreach ($codes as $code) {
                $counter = new RedisTtlCounter("ttlc:esi:status:$bucketKey:code:$code", $seconds);
                $count = $counter->count();
                if ($count == 0) continue;
                if (!isset($rows[$aggregateKey]['codes'][$code])) $rows[$aggregateKey]['codes'][$code] = ['code' => $code, 'count' => 0];
                $rows[$aggregateKey]['codes'][$code]['count'] += $count;
                if ((int) $code >= 200 && (int) $code < 400) $rows[$aggregateKey]['success'] += $count;
                else $rows[$aggregateKey]['failure'] += $count;
                $statusCode = (int) $code;
                if ($statusCode >= 200 && $statusCode < 300) $rows[$aggregateKey]['token_cost'] += 2 * $count;
                else if ($statusCode >= 300 && $statusCode < 400) $rows[$aggregateKey]['token_cost'] += $count;
                else if ($statusCode >= 400 && $statusCode < 500 && $statusCode != 429) $rows[$aggregateKey]['token_cost'] += 5 * $count;
            }

            $last = $redis->hGetAll("zkb:esi:status:last:$bucketKey");
            if (!is_array($last)) $last = [];
            if (((int) @$last['updated']) >= ((int) @$rows[$aggregateKey]['last']['updated'])) $rows[$aggregateKey]['last'] = $last;
        }

        foreach ($rows as &$row) {
            ksort($row['codes'], SORT_NATURAL);
            $row['codes'] = array_values($row['codes']);
        }
        unset($row);

        usort($rows, function ($a, $b) {
            return strnatcasecmp($a['bucket'], $b['bucket']);
        });
        return $rows;
    }


    public static function check($apiType, $exitIfOffline = true, $exitIfFailure = true, $seconds = 300)
    {
        global $redis;

        $apiType = strtolower($apiType);
        $rtcs = new RedisTtlCounter("ttlc:{$apiType}Success", $seconds);
        $rtcf = new RedisTtlCounter("ttlc:{$apiType}Failure", $seconds);

        $fail = false;
        $fail |= $rtcf->count() >= 100 && $exitIfFailure;
        $fail |= $redis->get("tqStatus") != "ONLINE" && $exitIfOffline;
        $fail |= $redis->get("tqCountInt") < 1000;

        if ($fail & $exitIfFailure) exit();
    }

    public static function checkStatus($guzzler = null, $apiType = '', $exitIfOffline = true, $exitIfFailure = true, $seconds = 300)
    {  
        global $redis;

        $apiType = strtolower($apiType);
        $rtcs = new RedisTtlCounter("ttlc:{$apiType}Success", $seconds);
        $rtcf = new RedisTtlCounter("ttlc:{$apiType}Failure", $seconds);

        $fail = false;
        $fail |= $rtcf->count() >= 100 && $exitIfFailure;
        $fail |= $redis->get("tqStatus") != "ONLINE" && $exitIfOffline;
        $fail |= $redis->get("tqCountInt") < 1000;

        if ($fail) {
            $guzzle = $guzzler == null ? null : $guzzler->finish();
            exit();
        }
    }

    public static function throttle($apiType)
    {
        global $redis, $ssoThrottle;

        while (true) {
            $now = date('His');
            $key = "throttle:$apiType:$now";
            $current = (int) $redis->get($key);
            if ($current < $ssoThrottle) {
                $redis->incr($key);
                $redis->expire($key, 3);
                return;
            }
            usleep(100000);
        }
    }

    private static function getHeader($headers, $name)
    {
        $name = strtolower($name);
        if (!isset($headers[$name])) return null;
        $value = $headers[$name];
        if (is_array($value)) return @$value[0];
        return $value;
    }

    private static function normalizeHeaders($headers)
    {
        if (!is_array($headers)) return [];

        $normalized = [];
        foreach ($headers as $name => $value) {
            if (is_int($name) && is_string($value)) {
                $header = explode(':', $value, 2);
                if (count($header) != 2) continue;
                $name = $header[0];
                $value = trim($header[1]);
            }

            $name = strtolower(trim($name));
            if ($name == '') continue;
            $values = is_array($value) ? $value : [$value];
            foreach ($values as $item) {
                if ($item === null || $item === false || $item === '') continue;
                $normalized[$name][] = $item;
            }
        }
        return $normalized;
    }

    private static function getEsiConditionalHeadersKey($uri)
    {
        return self::$esiConditionalHeaders . md5($uri);
    }

    private static function setLastHeaderValue($key, $field, $value)
    {
        global $redis;

        if ($value === null || $value === false || $value === '') $redis->hDel($key, $field);
        else $redis->hSet($key, $field, $value);
    }

    private static function normalizeEsiUri($uri)
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if ($path == null || $path == '') return 'unknown';

        $parts = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
        if (count($parts) == 0) return 'unknown';
        if (in_array($parts[0], ['latest', 'legacy', 'dev']) || preg_match('/^v\d+$/', $parts[0])) array_shift($parts);
        if (count($parts) == 0) return 'unknown';

        $first = $parts[0];
        if ($first == 'killmails') return 'killmails';
        if ($first == 'status') return 'status';
        if ($first == 'wars') return @$parts[2] == 'killmails' ? 'wars/killmails' : 'wars';
        if ($first == 'markets') {
            if (@$parts[1] == 'prices') return 'markets/prices';
            return isset($parts[2]) ? 'markets/' . $parts[2] : 'markets';
        }
        if ($first == 'universe') return isset($parts[1]) ? 'universe/' . $parts[1] : 'universe';
        if ($first == 'characters' || $first == 'corporations') {
            if (isset($parts[1]) && !ctype_digit($parts[1])) return $first . '/' . $parts[1];
            if (!isset($parts[2])) return $first;
            return $first . '/' . $parts[2] . (isset($parts[3]) ? '/' . $parts[3] : '');
        }
        if (isset($parts[1]) && !ctype_digit($parts[1])) return $first . '/' . $parts[1];
        return $first;
    }
}
