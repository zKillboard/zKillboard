<?php

require_once '../init.php';
$dbName = 'zkillboard';
$m = new MongoClient();
$db = $m->selectDB($dbName);
$mcollections = $db->listCollections();
$collections = array();
foreach ($mcollections as $collection) {
    $colName = str_replace("{$dbName}.", '', "$collection");
    $collections[$colName] = $collection;
}
ksort($collections);
echo "<?php
require_once \"../init.php\";
\$m = new MongoClient();
\$db = \$m->selectDB(\"zkillboard\");\n\n";
foreach ($collections as $colName => $collection) {
    echo "// $colName\n";
    echo "echo \"\\nCreating collection $colName ... \";\n";
    echo "\$$colName = \$db->createCollection(\"{$colName}\");\n";
    echo "echo \"Done\\n\";\n";
    $indexes = $collection->getIndexInfo();
    ksort($indexes);
    foreach ($indexes as $key => $index) {
        $names = array_keys($index['key']);
        if (sizeof($names) == 1 and $names[0] == '_id') {
            continue;
        }
        $fields = array();
        foreach ($names as $name) {
            $fields[$name] = $index['key'][$name];
        }
        $sparse = @$index['sparse'] == true ? 1 : 0;
        $unique = @$index['unique'] == true ? 1 : 0;
        $indexFields = '';
        $first = true;
        foreach ($fields as $field => $value) {
            if (!$first) {
                $indexFields .= ', ';
            }
            $first = false;
            $indexFields .= "'$field' => $value";
        }
        echo "echo \"Creating index : $indexFields, with sparse = $sparse and unique = $unique ... \";\n";
        echo "\$${colName}->ensureIndex(array($indexFields), array(\"sparse\" => $sparse, \"unique\" => $unique));\n";
        echo "echo \"Done\\n\";\n";
    }
    echo "\n";
}
