<?php

class Entities
{
    public static function populateList($mdb, $redis, $listName, $type)
    {
        $information = $mdb->getCollection('information');

        // All rows of type by default
        $query = ['type' => $type];
        // If the list isn't emtpy only search for rows that haven't been updated yet
        if ($redis->llen($listName) != 0) {
            $query['lastApiUpdate'] = ['$exists' => false];
        }

        // Populate the list
        $rows = $information->find($query)->sort(['lastApiUpdate' => 1]);
        foreach ($rows as $row) {
            $redis->lpush($listName, $row['id']);
        }
    }

    public static function iterateList($mdb, $redis, $listName, $type, $uri, $callable, $maxConcurrent = 30)
    {
        $maxConcurrent = min(30, max(1, $maxConcurrent));

        // Prepare curl, handler, and guzzler
        $curl = new \GuzzleHttp\Handler\CurlMultiHandler();
        $handler = \GuzzleHttp\HandlerStack::create($curl);
        $client = new \GuzzleHttp\Client(['connect_timeout' => 10, 'timeout' => 30, 'handler' => $handler]);

        $minute = date('Hi');
        $count = 0;
        while ($minute == date('Hi') && ($id = $redis->lpop($listName)) !== false) {
            $id = (int) $id;
            $url = str_replace("{id}", $id, $uri);
            $client->getAsync($url)->then(function($response) use ($mdb, $id, $listName, &$count, $callable) {
                    $count--;
                    $callable($mdb, $id, (string) $response->getBody());
                }, function($connectionException) use ($id, &$count, $mdb, $redis, $listName, $type) {
                    $count--;
                    Entities::handleRejection($connectionException->getCode(), $mdb, $redis, $listName, $type, $id);
                });

            $count++;
            do {
                $curl->tick();
            } while ($count >= $maxConcurrent) ;
            usleep(50000);
        }
        $curl->execute();
    }

    public static function handleRejection($code, $mdb, $redis, $listName, $type, $id) {
        $xmlFailure = new \cvweiss\redistools\RedisTtlCounter('ttlc:XmlFailure', 300);
        $xmlFailure->add(uniqid());
        switch ($code) {
            case 0: // timeout, try again later
                $redis->rpush($listName, $id);
                break;
            case 500:
                Util::out("Removing invalid $type $id $code");
                $mdb->remove("information", ['type' => $type, 'id' => $id]);
                break;
            default:
                Util::out("$listName $id Rejected for $code");
        }
    }
}
