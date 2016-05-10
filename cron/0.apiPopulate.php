<?php

require_once '../init.php';

if (date('i') % 5  != 0) exit();

$apis = $mdb->getCollection('apis');
$tqApis = new RedisTimeQueue('tqApis', 9600);
$maxKillID = $mdb->findField("killmails", "killID", [], ['killID' => -1]);

$allApis = $mdb->find('apis');
foreach ($allApis as $api) {
	$errorCode = (int) @$api['errorCode'];
	if ($errorCode == 220) remove($api);
	if (in_array($errorCode, [106, 203, 220, 222, 404])) continue;

	$keyID = $api['keyID'];
	$vCode = $api['vCode'];

        $keyID = (int) $keyID;
        if ($keyID <= 0) remove($api);

        $before = $vCode;
        $vCode = preg_replace( '/[^a-z0-9]/i', "", (string) $vCode);
        if ($before != $vCode) {
		remove($api);
		continue;
	}

	$tqApis->add($api['_id']);
}

function remove($api)
{
	global $mdb;

	$mdb->remove("apis", $api);
}
