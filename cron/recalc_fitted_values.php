<?php

$mt = 2; do { $mt--; $pid = pcntl_fork(); } while ($pid > 0 && $mt > 0); if ($pid > 0) exit();

require_once "../init.php";

$fittedArray = [11, 12, 13, 87, 89, 93, 158, 159, 172, 2663, 3772];

$minute = date("Hi");
while ($minute == date("Hi")) {
    $killID = (int) $redis->spop("fittedSet");
    if ($killID <= 0) exit();

    $mail = $mdb->findDoc("esimails", ['killmail_id' => $killID]);
    $km = $mdb->findDoc("killmails", ['killID' => $killID]);

    $date = substr($mail['killmail_time'], 0, 19);
    $date = str_replace('.', '-', $date);

    $shipValue = Price::getItemPrice($mail['victim']['ship_type_id'], $date);
    $fittedValue = getFittedValue($killID, $mail['victim']['items'], $date);
    $fittedValue += $shipValue;
    if ($fittedValue != $km['zkb']['fittedValue']) {
        //Log::log($killID . ": " . number_format($fittedValue, 2));
        $mdb->getCollection("killmails")->updateOne(['killID' => $killID], ['$set' => ['zkb.fittedValue' => $fittedValue]]);
    }
}

function getFittedValue($killID, $items, $dttm)
{
    global $fittedArray;

    $fittedValue = 0;
    foreach ($items as $item) {
        $typeID = (int) $item['item_type_id'];
        if ($typeID == 0) continue;

        $infernoFlag = Info::getFlagLocation($item['flag']);
        $add = in_array($infernoFlag, $fittedArray);
        if ($add) {
            $qty = ((int) @$item['quantity_dropped'] + (int) @$item['quantity_destroyed']);
            $price = Price::getItemPrice($typeID, $dttm);
            $fittedValue += ($qty * $price);
        }
    }
    return $fittedValue;
}

