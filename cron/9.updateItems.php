<?php

require_once "../init.php";

if (date('i') != 45) exit();

if ($redis->get("tq:itemsPopulated") != true)
{
        Util::out("Waiting for items to be populated...");
        exit();
}

$assign = ['capacity', 'name', 'portionSize', 'mass', 'volume', 'description', 'radius', 'published'];
$attrs = ['lowSlots', 'medSlots', 'hiSlots', 'rigSlots'];

$rows = $mdb->find("information", ['type' => 'typeID']);
foreach ($rows as $row) {
	$typeID = (int) $row['id'];
	$lastCrestUpdate = @$row['lastCrestUpdate'];
	if ($lastCrestUpdate != null && $lastCrestUpdate->sec > (time() - 86400)) continue;
	$crest = CrestTools::getJSON("$crestServer/types/$typeID/");

	foreach ($assign as $key) {
		if (isset($crest[$key])) $row[$key] = $crest[$key];
	}

        unset($row['lowSlotCount']);
        unset($row['midSlotCount']);
        unset($row['highSlotCount']);
        unset($row['rigSlotCount']);
        // Dogma
        if (isset($crest['dogma']['attributes'])) {
                foreach ($crest['dogma']['attributes'] as $attribute) {
                        $name = $attribute['attribute']['name'];
                        $value = $attribute['value'];
                        if (in_array($name, $attrs)) {
                                $row[$name] = $value;
                        }
                }
        }

	$row['lastCrestUpdate'] = $mdb->now();
	$mdb->save("information", $row);
}
