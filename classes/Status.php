<?php

use cvweiss\redistools\RedisTtlCounter;

class Status
{
    private static $esiBucketSet = 'zkb:esi:status:buckets';
    private static $esiBucketNames = 'zkb:esi:status:bucketNames';

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

        $normalized = [];
        foreach ($headers as $name => $value) {
            $name = strtolower(trim($name));
            if ($name == '') continue;
            $normalized[$name] = is_array($value) ? $value : [$value];
        }
        $headers = $normalized;

        $bucket = self::getHeader($headers, 'x-ratelimit-group');
        if ($bucket === null || $bucket === '') $bucket = self::normalizeEsiUri($uri);
        $bucketKey = strtolower($bucket);
        $bucketKey = preg_replace('/[^a-z0-9_.-]+/', '_', $bucketKey);
        $bucketKey = trim($bucketKey, '_') ?: 'unknown';
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

    public static function getEsiStatus($seconds = 300)
    {
        global $redis;

        $bucketKeys = $redis->sMembers(self::$esiBucketSet);
        if (!is_array($bucketKeys)) return [];

        $rows = [];
        foreach ($bucketKeys as $bucketKey) {
            $all = new RedisTtlCounter("ttlc:esi:status:$bucketKey:all", $seconds);
            $total = $all->count();
            if ($total == 0) continue;

            $codes = $redis->sMembers("zkb:esi:status:codes:$bucketKey");
            if (!is_array($codes)) $codes = [];
            sort($codes, SORT_NATURAL);

            $codeRows = [];
            $success = 0;
            $failure = 0;
            $tokenCost = 0;
            foreach ($codes as $code) {
                $counter = new RedisTtlCounter("ttlc:esi:status:$bucketKey:code:$code", $seconds);
                $count = $counter->count();
                if ($count == 0) continue;
                $codeRows[] = ['code' => $code, 'count' => $count];
                if ((int) $code >= 200 && (int) $code < 400) $success += $count;
                else $failure += $count;
                $statusCode = (int) $code;
                if ($statusCode >= 200 && $statusCode < 300) $tokenCost += 2 * $count;
                else if ($statusCode >= 300 && $statusCode < 400) $tokenCost += $count;
                else if ($statusCode >= 400 && $statusCode < 500 && $statusCode != 429) $tokenCost += 5 * $count;
            }

            $last = $redis->hGetAll("zkb:esi:status:last:$bucketKey");
            if (!is_array($last)) $last = [];
            $bucket = $redis->hGet(self::$esiBucketNames, $bucketKey);
            if ($bucket == false) $bucket = $bucketKey;
            $bucket = preg_replace('/^endpoint:/', '', $bucket);

            $rows[] = [
                'bucket' => $bucket,
                'total' => $total,
                'success' => $success,
                'failure' => $failure,
                'token_cost' => $tokenCost,
                'codes' => $codeRows,
                'last' => $last
            ];
        }

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
