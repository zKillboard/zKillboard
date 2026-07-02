<?php

require_once '../init.php';

$key = 'zkb:lNameUpdate:' . date('YmdH');
if ($kvc->get($key) == true) {
    exit();
}

$result = $mdb->getCollection('information')->updateMany(
    [
        'name' => ['$type' => 'string'],
        '$expr' => [
            '$ne' => [
                ['$toLower' => '$name'],
                ['$toLower' => '$l_name'],
            ],
        ],
    ],
    [
        [
            '$set' => [
                'l_name' => ['$toLower' => '$name'],
            ],
        ],
    ]
);

$kvc->setex($key, 3600, true);

Util::out('l_name has been updated: ' . $result->getModifiedCount() . ' rows modified.', 'l_name update');
