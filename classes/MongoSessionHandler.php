<?php

class MongoSessionHandler implements SessionHandlerInterface, SessionIdInterface, SessionUpdateTimestampHandlerInterface {
    private const IDLE_TIMEOUT = 2592000;
    private const ABSOLUTE_TIMEOUT = 7776000;

    private $collection;
    private $redis;
    private $lockID;
    private $lockKey;
    private $lockToken;
    private $newID;
    private $legacyID;
    private $validatedID;
    private $validatedData;

    public function __construct($collection, $redis = null) {
        $this->collection = $collection;
        $this->redis = $redis;
    }

    public function open($savePath, $sessionName) { return true; }
    public function close() {
        $this->releaseLock();
        return true;
    }

    public function create_sid() {
        $this->newID = bin2hex(random_bytes(32));
        return $this->newID;
    }

    private function activeQuery($id) {
        return [
            '_id' => $id,
            'updatedAt' => ['$gte' => new MongoDB\BSON\UTCDateTime((time() - self::IDLE_TIMEOUT) * 1000)],
            '$or' => [
                ['createdAt' => ['$gte' => new MongoDB\BSON\UTCDateTime((time() - self::ABSOLUTE_TIMEOUT) * 1000)]],
                ['createdAt' => ['$exists' => false]],
            ],
        ];
    }

    private function acquireLock($id) {
        if ($this->redis == null || $this->lockID === $id) return;

        $key = 'session-lock:' . hash('sha256', $id);
        $token = bin2hex(random_bytes(16));
        $deadline = microtime(true) + 30;
        do {
            if ($this->redis->set($key, $token, ['nx', 'ex' => 60]) === true) {
                $this->lockID = $id;
                $this->lockKey = $key;
                $this->lockToken = $token;
                return;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Timed out waiting for the session lock.');
    }

    private function releaseLock() {
        if ($this->redis != null && $this->lockKey != null) {
            $this->redis->eval(
                'if redis.call("get", KEYS[1]) == ARGV[1] then return redis.call("del", KEYS[1]) else return 0 end',
                [$this->lockKey, $this->lockToken],
                1
            );
        }
        $this->lockID = null;
        $this->lockKey = null;
        $this->lockToken = null;
    }

    public function validateId($id) {
        $this->acquireLock($id);
        $doc = $this->collection->findOne($this->activeQuery($id));
        if ($doc == null) {
            $this->releaseLock();
            return false;
        }

        $this->newID = null;
        $this->legacyID = array_key_exists('createdAt', $doc) ? null : $id;
        $this->validatedID = $id;
        $this->validatedData = $doc['data'] ?? '';
        return true;
    }

    public function read($id) {
        if ($this->validatedID === $id) {
            $data = $this->validatedData;
            $this->validatedID = null;
            $this->validatedData = null;
            return $data;
        }

        if ($this->newID !== $id) $this->acquireLock($id);
        $doc = $this->collection->findOne($this->activeQuery($id));
        if ($doc == null && $this->newID !== $id) $this->releaseLock();
        if ($doc != null && !array_key_exists('createdAt', $doc)) $this->legacyID = $id;
        return $doc['data'] ?? '';
    }

    public function write($id, $data) {
        global $hostname, $ip;

        if ($data == 'slim.flash|a:0:{}') return true;

        $isNew = $this->newID === $id;
        $values = [
            'server' => $hostname,
            'data' => $data,
            'updatedAt' => new MongoDB\BSON\UTCDateTime(),
            'characterID' => @$_SESSION['characterID'],
            'characterName' => @$_SESSION['characterName'],
        ];
        if ($this->legacyID === $id) $values['createdAt'] = new MongoDB\BSON\UTCDateTime();
        $result = $this->collection->updateOne(
            $isNew ? ['_id' => $id] : $this->activeQuery($id),
            ['$set' => $values,
                '$setOnInsert' => ['createdAt' => new MongoDB\BSON\UTCDateTime()],
            ],
            [
                'upsert' => $isNew,
                'writeConcern' => new \MongoDB\Driver\WriteConcern('majority')
            ]
        );
        $this->newID = null;
        $this->legacyID = null;
        //Util::zout("Session saved for $ip " . @$_SESSION['characterID'] . "\n" . print_r($data, true));
        return $result->getMatchedCount() + $result->getUpsertedCount() == 1;
    }

    public function updateTimestamp($id, $data) {
        $values = ['updatedAt' => new MongoDB\BSON\UTCDateTime()];
        if ($this->legacyID === $id) $values['createdAt'] = new MongoDB\BSON\UTCDateTime();
        $result = $this->collection->updateOne(
            $this->activeQuery($id),
            ['$set' => $values],
            ['writeConcern' => new \MongoDB\Driver\WriteConcern('majority')]
        );
        if ($result->getMatchedCount() == 1) $this->legacyID = null;
        return $result->getMatchedCount() == 1;
    }

    public function destroy($id) {
        $this->collection->deleteOne(['_id' => $id]);
        if ($this->lockID === $id) $this->releaseLock();
        if ($this->newID === $id) $this->newID = null;
        if ($this->legacyID === $id) $this->legacyID = null;
        $this->validatedID = null;
        $this->validatedData = null;
        return true;
    }

    public function gc($maxlifetime) {
        $this->collection->deleteMany(['$or' => [
            ['updatedAt' => ['$lt' => new MongoDB\BSON\UTCDateTime((time() - self::IDLE_TIMEOUT) * 1000)]],
            ['createdAt' => ['$lt' => new MongoDB\BSON\UTCDateTime((time() - self::ABSOLUTE_TIMEOUT) * 1000)]],
        ]]);
        return true;
    }
}
