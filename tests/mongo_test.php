<?php

// Test MongoDB connections using the new MongoDB\Client driver (mongodblibupdate branch)

require_once __DIR__ . '/../init.php';

echo "Testing MongoDB Connection (New Driver)...\n\n";

try {
    // Test 1: Verify using new MongoDB\Client driver
    echo "1. Testing MongoDB\Client driver... ";
    $client = $mdb->getClient();
    if ($client instanceof MongoDB\Client) {
        echo "✓ Using MongoDB\Client (new driver)\n";
    } else {
        echo "✗ Failed - not using new driver (got " . get_class($client) . ")\n";
        exit(1);
    }

    // Test 2: Get database connection
    echo "2. Testing getDb()... ";
    $db = $mdb->getDb();
    if ($db instanceof MongoDB\Database) {
        echo "✓ Connected (MongoDB\Database)\n";
    } else {
        echo "✗ Failed - returned " . get_class($db) . "\n";
        exit(1);
    }

    // Test 3: Get a collection
    echo "3. Testing getCollection('information')... ";
    $collection = $mdb->getCollection('information');
    if ($collection instanceof MongoDB\Collection) {
        echo "✓ Success (MongoDB\Collection)\n";
    } else {
        echo "✗ Failed - got " . get_class($collection) . "\n";
        exit(1);
    }

    // Test 4: Count documents using countDocuments (new method)
    echo "4. Testing countDocuments on information collection... ";
    $count = $mdb->count('information');
    echo "✓ Found $count documents\n";

    // Test 5: Test exists() method
    echo "5. Testing exists() method... ";
    $exists = $mdb->exists('information', ['type' => 'characterID']);
    if ($exists) {
        echo "✓ exists() returned true\n";
    } else {
        echo "✗ exists() returned false\n";
        exit(1);
    }

    // Test 6: Find a single document
    echo "6. Testing findDoc() on information collection... ";
    $doc = $mdb->findDoc('information', []);
    if ($doc !== null && is_array($doc)) {
        echo "✓ Retrieved array document with type: " . ($doc['type'] ?? 'unknown') . "\n";
    } else {
        echo "✓ No documents found (empty collection)\n";
    }

    // Test 7: Test find with limit
    echo "7. Testing find() with limit... ";
    $docs = $mdb->find('information', [], [], 5);
    if (is_array($docs)) {
        echo "✓ Retrieved " . count($docs) . " documents as array\n";
    } else {
        echo "✗ Failed - returned " . gettype($docs) . "\n";
        exit(1);
    }

    // Test 8: Test findField
    echo "8. Testing findField()... ";
    $field = $mdb->findField('information', 'type', []);
    if ($field !== null) {
        echo "✓ Retrieved field value: $field\n";
    } else {
        echo "✓ No field value found\n";
    }

    // Test 9: Test MongoDB\BSON\UTCDateTime creation
    echo "9. Testing Mdb::now() (UTCDateTime)... ";
    $now = Mdb::now();
    if ($now instanceof MongoDB\BSON\UTCDateTime) {
        echo "✓ Created MongoDB\BSON\UTCDateTime\n";
    } else {
        echo "✗ Failed - got " . get_class($now) . "\n";
        exit(1);
    }

    // Test 10: Test database command (listCollections)
    echo "10. Testing database command (listCollections)... ";
    $collections = iterator_to_array($db->listCollections());
    if (count($collections) > 0) {
        echo "✓ Found " . count($collections) . " collections\n";
    } else {
        echo "✗ Failed - no collections found\n";
        exit(1);
    }

    // Test 11: Test insert and remove (if we have a test collection)
    echo "11. Testing insertOne() and deleteOne()... ";
    $testDoc = ['test' => true, 'timestamp' => Mdb::now(), 'data' => 'test_' . uniqid()];
    $insertResult = $mdb->insert('_test_collection', $testDoc);
    if (isset($insertResult['_id'])) {
        // Try to delete it
        $deleteResult = $mdb->remove('_test_collection', ['_id' => $insertResult['_id']]);
        echo "✓ Insert and delete successful\n";
    } else {
        echo "✗ Insert failed\n";
        exit(1);
    }

    echo "\n✅ All MongoDB new driver tests passed!\n";
    echo "   Using: MongoDB\\Client with mongodb extension\n";
    exit(0);

} catch (Exception $e) {
    echo "\n❌ Test failed with exception:\n";
    echo "   " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    if ($e->getPrevious()) {
        echo "   Previous: " . $e->getPrevious()->getMessage() . "\n";
    }
    exit(1);
}

