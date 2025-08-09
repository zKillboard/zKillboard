<?php

use MongoDB\BSON\ObjectId;

global $mdb;

$record = $mdb->findDoc("shortener", ['_id' => new ObjectId($id)]);
if ($record == null) $app->redirect('/', 302);
else $app->redirect($record['url'], 302);
