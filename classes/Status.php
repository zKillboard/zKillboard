<?php

use cvweiss\redistools\RedisTtlCounter;

class Status
{
    public static function addStatus($apiType, $success)
    {
        $apiType = strtolower($apiType);
        $status = $success == true ? "Success" : "Failure";
        $rtc = new RedisTtlCounter("ttlc:{$apiType}{$status}", 300);
        $rtc->add(uniqid());
    }

    public static function getStatus($apiType, $success)
    {
        $apiType = strtolower($apiType);
        $status = $success == true ? "Success" : "Failure";
        $rtc = new RedisTtlCounter("ttlc:{$apiType}{$status}", 300);
        return $rtc->count();
    }


    public static function check($apiType, $exitIfOffline = true, $exitIfFailure = true)
    {
        global $redis;

        $apiType = strtolower($apiType);
        $rtcs = new RedisTtlCounter("ttlc:{$apiType}Success", 300);
        $rtcf = new RedisTtlCounter("ttlc:{$apiType}Failure", 300);

        $fail = false;
        $fail |= $rtcf->count() >= 100 && $exitIfFailure;
        $fail |+ $redis->get("tqStatus") != "ONLINE" && $exitIfOffline;

        if ($fail) exit();
    }

    public static function checkStatus($guzzler = null, $apiType = '', $exitIfOffline = true, $exitIfFailure = true)
    {  
        global $redis;

        $apiType = strtolower($apiType);
        $rtcs = new RedisTtlCounter("ttlc:{$apiType}Success", 300);
        $rtcf = new RedisTtlCounter("ttlc:{$apiType}Failure", 300);

        $fail = false;
        $fail |= $rtcf->count() >= 100 && $exitIfFailure;
        $fail |+ $redis->get("tqStatus") != "ONLINE" && $exitIfOffline;

        if ($fail) {
            $guzzle = $guzzler == null ? null : $guzzler->finish();
            exit();
        }
    }
}
