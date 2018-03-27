<?php

use cvweiss\redistools\RedisTtlCounter;

class Status
{
    public static function addStatus($apiType, $success, $seconds = 300)
    {
        $apiType = strtolower($apiType);
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

        if ($fail) exit();
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

    public static function throttle($apiType, $perSecond, $seconds = 300)
    {
        $rtcs = new RedisTtlCounter("ttlc:{$apiType}Success", $seconds);
        $rtce = new RedisTtlCounter("ttlc:{$apiType}Failure", $seconds);
        $perSecond--;
        do {
            $sum = $rtcs->count() + $rtce->count();
            $wait = $sum > ($perSecond * $seconds);
            if ($wait) sleep(1);
        } while ($wait);
    }
}
