<?php

require_once "../init.php";

var_dump(isCapital(3764));

// Determining if a ship is a Capital Ship by the definition of the game's market data,
// and if that ship falls under "Capital Ships" or not.
function isCapital($typeID) {
    global $mdb, $redis;

    $key = "market:isCapital:$typeID";
    $is = $redis->get($key);
    var_dump($is);
    if ($is !== false) return (bool) $is;
    $is = false;
    echo "looking it up\n";

    $mGroupID = Info::getInfoField("typeID", $typeID, "market_group_id");
    do {
        $mGroup = Info::getInfo("marketGroupID", $mGroupID);
        $mGroupID = (int) @$mGroup['parent_group_id'];
        if ($mGroupID == 1381) $is = true;
    } while ($is == false && $mGroupID > 0);

    $redis->setex($key, 86400, "$is");
    return $is;
}
