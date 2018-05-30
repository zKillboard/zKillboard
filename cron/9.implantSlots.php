<?php

require_once '../init.php';

$redisKey = 'zkb:implantSlots';
$redisKeyMd5 = 'zkb:implantSlots:md5';
if ($redis->get($redisKey) == true) {
    exit();
}

$md5 = file_get_contents('http://sde.zzeve.com/installed.md5');
if ($redis->get($redisKeyMd5) != $md5) {
    Util::out("Updating implant slots from http://sde.zzeve.com/dgmTypeAttributes.json");
    $href = 'http://sde.zzeve.com/dgmTypeAttributes.json';
    $raw = file_get_contents($href);
    $json = json_decode($raw, true);

    foreach ($json as $row) {
        $attrID = (int) $row['attributeID'];
        $typeID = (int) $row['typeID'];
        $implantSlot = $row['valueInt'] === null ? (int) $row['valueFloat'] : (int) $row['valueInt'];
        if ($attrID == 331 && $implantSlot <= 10 && $implantSlot) {
            $name = Info::getInfoField('typeID', $typeID, 'name');
            Util::out("Setting $name to implantSlot $implantSlot");
            $mdb->set('information', ['type' => 'typeID', 'id' => $typeID], ['implantSlot' => $implantSlot]);
        }
    }

    $redis->set($redisKeyMd5, $md5);
}

$redis->setex($redisKey, 9600, true);
