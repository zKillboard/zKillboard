<?php

class ZLog
{
    public static function add($message, $charID = 0)
    {
        global $mdb;

        $mdb->save("zlog", ['message' => $message, 'characterID' => $charID, 'entryTime' => new MongoDate()]);
    }

    public static function get($charID)
    {
        global $mdb;

        return $mdb->find("zlog", ['characterID' => $charID], ['entryTime' => -1]);
    }
}
