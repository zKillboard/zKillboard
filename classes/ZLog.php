<?php

class ZLog
{
    public static function add($message, $charID = 0, $useLogLog = false)
    {
        global $mdb;

        if ($charID > 0) $mdb->save("zlog", ['message' => $message, 'characterID' => $charID, 'entryTime' => new MongoDate()]);
        if ($useLogLog) Util::zout($message);
        else Util::out($message);
    }

    public static function get($charID)
    {
        global $mdb;

        return $mdb->find("zlog", ['characterID' => $charID], ['entryTime' => -1]);
    }
}
