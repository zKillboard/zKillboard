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
        if ($data == 'slim.flash|a:0:{}') return true;

        $this->collection->update(
            ['_id' => $id],
            ['$set' => ['data' => $data, 'updatedAt' => new MongoDB\BSON\UTCDateTime()]],
            ['upsert' => true]
        );
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

