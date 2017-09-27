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

}
