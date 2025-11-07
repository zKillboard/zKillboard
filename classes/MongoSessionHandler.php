<?php

class MongoSessionHandler implements SessionHandlerInterface {
    private $collection;

    public function __construct($collection) {
        $this->collection = $collection;
    }

    public function open($savePath, $sessionName) { return true; }
    public function close() { return true; }

    public function read($id) {
        $doc = $this->collection->findOne(['_id' => $id]);
        return $doc['data'] ?? '';
    }

    public function write($id, $data) {
        global $hostname, $ip;

        if ($data == 'slim.flash|a:0:{}') return true;

        $this->collection->updateOne(
            ['_id' => $id],
            ['$set' => [
                    'server' => $hostname,
                    'data' => $data,
                    'updatedAt' => new MongoDB\BSON\UTCDateTime(),
                    'characterID' => @$_SESSION['characterID'],
                    'characterName' => @$_SESSION['characterName'],
                ]
            ],
            [
                'upsert' => true,
                'writeConcern' => new \MongoDB\Driver\WriteConcern('majority')
            ]
        );
        //Util::zout("Session saved for $ip " . @$_SESSION['characterID'] . "\n" . print_r($data, true));
        return true;
    }

    public function destroy($id) {
        $this->collection->deleteOne(['_id' => $id]);
        return true;
    }

    public function gc($maxlifetime) {
        return true;
    }
}

