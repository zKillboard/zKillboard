<?php

// Interface for zkillboard's MongoDB
class Mdb
{
    private $mongoClient = null;
    private $db = null;

    private $queryCount = 0;
    private $emptyArray = [];

    /*
       Return a connection to the Mongo Database
     */
    public function getDb($attempt = 0)
    {
        try {
            if ($this->mongoClient == null) {
                $this->mongoClient = new MongoClient();
            }
            if ($this->db == null) {
                $this->db = $this->mongoClient->selectDB('zkillboard');
            }

            ++$this->queryCount;

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

    /*
       Return the specified collection from the mongodb
     */
    public function getCollection($collection)
    {
        $db = $this->getDb();

        return $db->$collection;
    }

    /*
       Returns the number of queries performed by this instance
     */
    public function getQueryCount()
    {
        return $this->queryCount;
    }

    /*
       Returns a MongoDate object with a time of now plus delta
       Delta is in seconds
       To go back one hour call now(-3600)
     */
    public function now($delta = 0)
    {
        return new MongoDate(time() + $delta);
    }

    /*
       Returns a count of the number of documents within a collection using the optinal query
     */
    public function count($collection, $query = [])
    {
        $collection = $this->getCollection($collection);

        return $collection->find($query)->timeout(-1)->count();
    }

    public function exists($collection, $query)
    {
        $collection = $this->getCollection($collection);
        $cursor = $collection->find($query);

        return $cursor->hasNext();
    }

    public function insert($collection, $values)
    {
        return $this->getCollection($collection)->insert($values);
    }

    public function save($collection, $document)
    {
        return $this->getCollection($collection)->save($document);
    }

    public function removeField($collection, $key, $value)
    {
        $key = isset($key['_id']) ? ['_id' => $key['_id']] : $key;

        return $this->getCollection($collection)->update($key, ['$unset' => [$value => 1]]);
    }

    public function set($collection, $key, $value, $multi = false)
    {
        $key = isset($key['_id']) ? ['_id' => $key['_id']] : $key;

        return $this->getCollection($collection)->update($key, ['$set' => $value], ['multiple' => $multi]);
    }

    public function remove($collection, $key)
    {
        $key = isset($key['_id']) ? ['_id' => $key['_id']] : $key;

        return $this->getCollection($collection)->remove($key);
    }

    /*
       Returns a result as an array of documents based on the given query, includes, sort, and limit
     */
    public function find($collection, $query = [], $sort = [], $limit = null, $includes = [])
    {
	global $longQueryMS;

        $cacheKey = null;
        $cacheTime = isset($query['cacheTime']) ? $query['cacheTime'] : 0;
        unset($query['cacheTime']); // reserved zkb field for caching doesn't need to be in queries
        $serialized = "Mdb::find|$collection|".serialize($query).'|'.serialize($sort)."|$limit|".serialize($includes);
        $cacheKey = $serialized;
        if ($cacheTime > 0) {
            $cached = RedisCache::get($cacheKey);
            if ($cached != null) {
                return $cached;
            }
        }
        if (php_sapi_name() != 'cli' && $cacheTime == 0) {
            $params = "$collection|".serialize($query).'|'.serialize($sort)."|$limit|".serialize($includes);
        }

        $timer = new Timer();
        $collection = $this->getCollection($collection);
        $cursor = $collection->find($query, $includes);

        // Set an appropriate timeout for the query
        if (php_sapi_name() == 'cli') {
            $cursor->timeout(-1);
        } else {
            $cursor->timeout(35000);
        }

        // Set the sort and limit...
        if (sizeof($sort)) {
            $cursor->sort($sort);
        }
        if ($limit != null) {
            $cursor->limit($limit);
        }
        $result = iterator_to_array($cursor);
        $time = $timer->stop();
        if ($time > $longQueryMS) {
            Log::log("Long query (${time}ms): $serialized");
        }

        if ($cacheTime > 0 && sizeof($result) > 0) {
            RedisCache::set($cacheKey, $result, $cacheTime);
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
        return $this->getCollection($collection)->findAndModify($keys, (sizeof($values) ? ['$set' => $values] : $keys), $this->emptyArray, ['upsert' => true]);
    }

    public static function group($collection, $keys = [], $query = [], $count = [], $sum = [], $sort = [], $limit = null)
    {
        global $debug;

        // Turn keys into an array if is isn't already an array
        if (!is_array($keys)) {
            $keys = [$keys];
        }

        // Start the aggregation pipeline with the query
        $pipeline = [];
        if (sizeof($query)) {
            $pipeline[] = ['$match' => $query];
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
        if (sizeof($count) == 0 && sizeof($sum) == 0) {
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
        if (!$debug) {
            MongoCursor::$timeout = -1;
        } // this should be deprecated but aggregate doesn't have a timeout
        // Execute the query
        $result = $collection->aggregate($pipeline);
        if ($result['ok'] == 1) {
            return $result['result'];
        }
        throw new Exception('pipeline query failure');
    }
}
