<?php

require_once '../init.php';
$dbName = 'zkillboard';
$mdb = new Mdb();
$db = $mdb->getDb();
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
    echo "\$db->createCollection(\"{$colName}\");\n";
    echo "\$collection = \"{$colName}\";\n";
    echo "\$$colName = \$db->\$collection;\n";
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
        $expireAfterSeconds = @$index['expireAfterSeconds'] > 0 ? $index['expireAfterSeconds'] : null;
        $partialFilterExpression = isset($index['partialFilterExpression']) ? $index['partialFilterExpression'] : null;

        // index fields inline
        $indexFields = '';
        $first = true;
        foreach ($fields as $field => $value) {
            if (!$first) $indexFields .= ', ';
            $first = false;
            $indexFields .= "'$field' => $value";
        }

        // build options inline just like indexFields
        $optionsFields = [];
        if ($sparse) $optionsFields[] = "'sparse' => true";
        if ($unique) $optionsFields[] = "'unique' => true";
        if ($expireAfterSeconds !== null) $optionsFields[] = "'expireAfterSeconds' => $expireAfterSeconds";
        if ($partialFilterExpression !== null) {
            $optionsFields[] = "'partialFilterExpression' => " . var_export($partialFilterExpression, true);
        }
        $optionsFieldsStr = implode(', ', $optionsFields);

        echo "echo \"Creating index : $indexFields ... \";\n";
        echo "\$${colName}->ensureIndex([$indexFields], [$optionsFieldsStr]);\n";
        echo "echo \"Done\\n\";\n";
    }
    echo "\n";
}

