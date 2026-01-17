<?php

use cvweiss\redistools\RedisCache;

// Interface for zkillboard's MongoDB
class Mdb
{
    private $mongoClient = null;
    private $db = null;

    private $emptyArray = [];

    /*
       Return a connection to the Mongo Database
     */
    public function getDb($attempt = 0)
    {
        global $mongoServer, $mongoPort, $mongoConnString, $debug;


        try {
            if ($this->mongoClient == null) {
                if ($mongoConnString == null) $mongoConnString = "mongodb://$mongoServer:$mongoPort";
                $this->mongoClient = new MongoDB\Client($mongoConnString, [], [
                    'connectTimeoutMS' => 7200000, 
                    'socketTimeoutMS' => 7200000,
                    'typeMap' => [
                        'root' => 'array',
                        'document' => 'array',
                        'array' => 'array'
                    ]
                ]);
            }
            if ($this->db == null) {
                $this->db = $this->mongoClient->selectDatabase('zkillboard');
            }

            return $this->db;
        } catch (Exception $ex) {
            if ($attempt >= 10) {
                throw $ex;
            }
            ++$attempt;
            sleep($attempt);

            return self::getDb($attempt);
        }
    }

    public function getClient()
    {
        $this->getDb();
        return $this->mongoClient;
    }

    /*
       Return the specified collection from the mongodb
     */
    public function getCollection($collection)
    {
        $db = $this->getDb();

        return $db->$collection;
    }

    /*
       Returns a MongoDate object with a time of now plus delta
       Delta is in seconds
       To go back one hour call now(-3600)
     */
    public static function now($delta = 0)
    {
        return new MongoDB\BSON\UTCDateTime((time() + $delta) * 1000);
    }

    /*
       Returns a count of the number of documents within a collection using the optinal query
     */
    public function count($collection, $query = [])
    {
        $collection = $this->getCollection($collection);

        if (sizeof($query) == 0) return $collection->estimatedDocumentCount();

        return $collection->countDocuments($query);
    }

    public function exists($collection, $query)
    {
        $collection = $this->getCollection($collection);
        
        return $collection->countDocuments($query, ['limit' => 1]) > 0;
    }

    public function insert($collection, $values)
    {
        try {

            $result = $this->getCollection($collection)->insertOne($values);
            return ['ok' => 1, '_id' => $result->getInsertedId()];
        } finally {
        }
    }

    public function save($collection, $document)
    {
        if ($document === null || !is_array($document)) {
            throw new InvalidArgumentException("Document cannot be null");
        }
        
        try {

            $collection = $this->getCollection($collection);
            if (isset($document['_id'])) {
                // Update existing document
                $result = $collection->replaceOne(['_id' => $document['_id']], $document, ['upsert' => true]);
                return ['ok' => 1, 'n' => $result->getModifiedCount() + $result->getUpsertedCount()];
            } else {
                // Insert new document
                $result = $collection->insertOne($document);
                $document['_id'] = $result->getInsertedId();
                return ['ok' => 1, '_id' => $document['_id']];
            }
        } finally {
        }
    }

    public function removeField($collection, $key, $value, $multi = false)
    {
        $key = isset($key['_id']) ? ['_id' => $key['_id']] : $key;

        try {

            $collection = $this->getCollection($collection);
            if ($multi) {
                $result = $collection->updateMany($key, ['$unset' => [$value => 1]]);
            } else {
                $result = $collection->updateOne($key, ['$unset' => [$value => 1]]);
            }
            return ['ok' => 1, 'n' => $result->getModifiedCount()];
        } finally {
        }
    }

    public function set($collection, $key, $value, $multi = false)
    {
        if ($key == null) throw new InvalidArgumentException("key is null");
        
        $key = isset($key['_id']) ? ['_id' => $key['_id']] : $key;
        if ($key === null) {
            throw new Exception('Invalid key');
        }

        try {
            $collection = $this->getCollection($collection);
            if ($multi) {
                $result = $collection->updateMany($key, ['$set' => $value]);
            } else {
                $result = $collection->updateOne($key, ['$set' => $value]);
            }
            return ['ok' => 1, 'n' => $result->getModifiedCount()];
        } finally {
        }
    }

    public function remove($collection, $key)
    {
        if ($key == null) throw new InvalidArgumentException("key is null");
        
        $key = isset($key['_id']) ? ['_id' => $key['_id']] : $key;

        try {

            $result = $this->getCollection($collection)->deleteOne($key);
            return ['ok' => 1, 'n' => $result->getDeletedCount()];
        } finally {
        }
    }

    /*
       Returns a result as an array of documents based on the given query, includes, sort, and limit
     */
    public function find($collection, $query = [], $sort = [], $limit = null, $includes = [], $skip = null)
    {
        global $longQueryMS;

        $cacheTime = isset($query['cacheTime']) ? $query['cacheTime'] : 0;
        $cacheTime = min($cacheTime, 900);
        unset($query['cacheTime']); // reserved zkb field for caching doesn't need to be in queries
        $serialized = "Mdb::find|$collection|".serialize($query).'|'.serialize($sort)."|$limit|".serialize($includes)."|$skip";
        $cacheKey = $serialized;
        if ($cacheTime > 0) {
            try {
            $cached = RedisCache::get($cacheKey);
            } catch (Exception $ex) {
                $cached = null;
                RedisCache::delete($cacheKey);
            }
            if ($cached != null) {
                return $cached;
            }
        }

        $timer = new Timer();
        $collection = $this->getCollection($collection);
        
        // Build options for modern MongoDB driver
        $options = [];
        if (!empty($includes)) {
            $options['projection'] = $includes;
        }
        if (sizeof($sort)) {
            $options['sort'] = $sort;
        }
        if ($limit != null) {
            $options['limit'] = $limit;
        }
        if ($skip != null) {
            $options['skip'] = $skip;
        }
        
        $cursor = $collection->find($query, $options);
        $result = iterator_to_array($cursor);
        $time = $timer->stop();
        if ($time > $longQueryMS) {
            global $uri;
        }

        return $result;
    }

    /*
       Returns a result as a single document based on the given query, includes, snd sort
     */
    public function findDoc($collection, $query = [], $sort = [], $includes = [])
    {
        // https://blog.serverdensity.com/checking-if-a-document-exists-mongodb-slow-findone-vs-find/
        // Using findOne is very slow if the document doesn't exist, so we'll use the existing find code
        $result = $this->find($collection, $query, $sort, 1, $includes);

        return array_shift($result);
    }

    /*
       Returns a single key's value from a single document based on the given query, includes, snd sort
     */
    public function findField($collection, $field, $query = [], $sort = [])
    {
        $includes = array();
        $includes[$field] = 1;
        $result = $this->findDoc($collection, $query, $sort, $includes);

        return @$result[$field];
    }

    /*
       Inserts a single row to a collection if the matching keys do not exist.  If the matching keys
       do exist, update the values accordingly.
     */
    public function insertUpdate($collection, $keys, $values = [])
    {
        try {

            $result = $this->getCollection($collection)->findOneAndUpdate(
                $keys, 
                (sizeof($values) ? ['$set' => $values] : ['$set' => $keys]), 
                ['upsert' => true, 'returnDocument' => MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
            );
            return $result;
        } finally {
        }
    }

    public static function group($collection, $keys = [], $query = [], $count = [], $sum = [], $sort = [], $limit = null)
    {
        global $debug, $foobar;

        // Turn keys into an array if is isn't already an array
        if (!is_array($keys)) {
            $keys = [$keys];
        }
        $unwind = false;
        if (@$keys[0] == '$unwind') {
            $unwind = true;
            array_shift($keys);
        }

        // Start the aggregation pipeline with the query
        $pipeline = [];
        if (sizeof($query)) {
            $pipeline[] = ['$match' => $query];
        }
if ($foobar) print_r($pipeline);
        if ($unwind) {
            $pipeline[] = ['$unwind' => '$' . $keys[0]];
        }

        // Create the group by using the given key(s)
        $ids = [];
        foreach ($keys as $key => $value) {
            if (is_numeric($key)) {
                $ids[] = '$'.$value;
            } else {
                $ids[$key] = ['$'.$key => '$'.$value];
            }
        }
        if (sizeof($ids) == 1 && isset($ids[0])) {
            $ids = $ids[0];
        }
        $group = [];
        $group['_id'] = $ids;

        // If no counts or sums are given, assume a count based on the keys for the $group
        if (is_array($count) && @sizeof($count) == 0 && is_array($sum) && @sizeof($sum) == 0) {
            $group['count'] = ['$sum' => 1];
        }

        // Include counts in the $group
        if (!is_array($count)) {
            $count = [$count];
        }
        foreach ($count as $s) {
            $group[str_replace('.', '_', $s).'Count'] = ['$sum' => 1];
        }

        // Include sums in the $group
        if (!is_array($sum)) {
            $sum = [$sum];
        }
        foreach ($sum as $s) {
            $group[str_replace('.', '_', $s).'Sum'] = ['$sum' => '$'.$s];
        }

        // Add the group to the pipeline
        $pipeline[] = ['$group' => $group];

        // $project the keys into the result
        $project = [];
        $project['_id'] = 0;
        foreach ($keys as $key => $value) {
            if (is_numeric($key)) {
                $project[$value] = '$_id';
            } else {
                $project[$key] = '$_id.'.$key;
            }
        }
        if (sizeof($count) == 0 && sizeof($sum) == 0) {
            $project['count'] = 1;
        }
        if (sizeof($count) > 0) {
            foreach ($count as $s) {
                $project[str_replace('.', '_', $s).'Count'] = 1;
            }
        }
        if (sizeof($sum) > 0) {
            foreach ($sum as $s) {
                $project[str_replace('.', '_', $s).'Sum'] = 1;
            }
        }
        $pipeline[] = ['$project' => $project];

        // Assign the sort to the pipeline
        if (sizeof($sort) > 0) {
            $pipeline[] = ['$sort' => $sort];
        }
        // And add the limit
        if ($limit != null) {
            $pipeline[] = ['$limit' => (int) $limit];
        }

        // Prep the cursor
        $mdb = new self();
        $collection = $mdb->getCollection($collection);

        $options = ['allowDiskUse' => true, 'noCursorTimeout' => true];
        if (php_sapi_name() !== 'cli') $options['maxTimeMS'] = 65000; // web requests should not run longer than 65 seconds

        // Execute the query
        $result = $collection->aggregate($pipeline, $options);
        return iterator_to_array($result);
    }
}
