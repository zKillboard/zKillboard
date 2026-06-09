<?php

use MongoDB\Driver\Exception\BulkWriteException;

require_once "../init.php";

$batchSize = 1000;

$killmails = $mdb->getCollection("killmails");
$collections = [
    "killmails" => $killmails,
    "oneWeek" => $mdb->getCollection("oneWeek"),
    "ninetyDays" => $mdb->getCollection("ninetyDays"),
];
$date7Days = time() - (86400 * 7);
$date90Days = time() - (86400 * 90);

$processed = 0;

Util::out("Starting totalDroppableValue backfill for all killmails");

$cursorOptions = [
    "projection" => [
        "killID" => 1,
        "dttm" => 1,
        "_id" => 0,
    ],
    "sort" => ["killID" => -1],
    "batchSize" => $batchSize,
];

$bulkWrites = newBulkWrites();

foreach ($killmails->find([], $cursorOptions) as $row) {
    $killID = (int) $row["killID"];
    if ($killID < 21112472) {
        break;
    }

    $esiKill = Kills::getEsiKill($killID);
    if ($esiKill == null || !isset($esiKill["victim"])) {
        $processed++;
        continue;
    }

    $items = $esiKill["victim"]["items"] ?? [];
    $date = getKillDate($row, $esiKill);
    $totalDroppableValue = round((double) getTotalDroppableValueForBackfill($killID, $items, $date), 2);
    $update = ["updateOne" => [
        ["killID" => $killID],
        ["\$set" => ["zkb.totalDroppableValue" => $totalDroppableValue]],
    ]];

    $bulkWrites["killmails"][] = $update;
    $killTimestamp = getKillTimestamp($row, $esiKill);
    if ($killTimestamp >= $date7Days) {
        $bulkWrites["oneWeek"][] = $update;
    }
    if ($killTimestamp >= $date90Days) {
        $bulkWrites["ninetyDays"][] = $update;
    }

    $processed++;

    if (sizeof($bulkWrites["killmails"]) >= $batchSize) {
        flushAllBulkWrites($collections, $bulkWrites);
        $bulkWrites = newBulkWrites();
        Util::out("Processed $processed");
    }
}

flushAllBulkWrites($collections, $bulkWrites);

Util::out("Done. Processed $processed killmails");

function newBulkWrites()
{
    return [
        "killmails" => [],
        "oneWeek" => [],
        "ninetyDays" => [],
    ];
}

function flushAllBulkWrites($collections, $bulkWrites)
{
    foreach ($bulkWrites as $collectionName => $writes) {
        flushBulkWrites($collections[$collectionName], $writes, $collectionName);
    }
}

function flushBulkWrites($collection, $writes, $collectionName)
{
    if (sizeof($writes) == 0) {
        return;
    }

    try {
        $collection->bulkWrite($writes, ["ordered" => false]);
    } catch (BulkWriteException $ex) {
        Util::out("bulkWrite partially failed for $collectionName: " . $ex->getMessage());
    } catch (Exception $ex) {
        Util::out("bulkWrite failed for $collectionName: " . $ex->getMessage());
    }
}

function getKillDate($row, $esiKill)
{
    return date("Y-m-d H:i:s", getKillTimestamp($row, $esiKill));
}

function getKillTimestamp($row, $esiKill)
{
    if (isset($row["dttm"])) {
        return $row["dttm"]->toDateTime()->getTimestamp();
    }

    return strtotime(str_replace(".", "-", substr($esiKill["killmail_time"], 0, 19)) . " UTC");
}

function getTotalDroppableValueForBackfill($killID, $items, $dttm, $isCargo = false)
{
    if ($killID < 21112472) {
        return 0;
    }

    $totalDroppableValue = 0;
    foreach ($items as $item) {
        $flagLocation = Info::getFlagLocation((int) @$item["flag"]);
        if ($flagLocation == 2663 || $flagLocation == 89) {
            continue;
        }

        $totalDroppableValue += getBackfillItemValue($killID, $item, $dttm, $isCargo);

        if (@is_array($item["items"])) {
            $totalDroppableValue += getTotalDroppableValueForBackfill($killID, $item["items"], $dttm, true);
        }
    }

    return $totalDroppableValue;
}

function getBackfillItemValue($killID, $item, $dttm, $isCargo = false)
{
    $typeID = (int) $item["item_type_id"];
    if ($typeID == 0) {
        return 0;
    }

    $flag = (int) @$item["flag"];
    if ($typeID == 33329 && $flag == 89) {
        $price = 0.01;
    } else if ($flag == 179) {
        $price = 0.01;
    } else {
        $price = Price::getItemPrice($typeID, $dttm);
    }

    if ($killID < 21112472 && $isCargo) {
        $itemName = Info::getInfoField("typeID", $typeID, "name");
        if ($itemName == null) {
            $itemName = "TypeID $typeID";
        }
        if (strpos($itemName, "Blueprint") !== false) {
            $item["singleton"] = 2;
        }
    }
    if (@$item["singleton"] == 2) {
        $price = 0.01;
    }

    return $price * ((int) @$item["quantity_dropped"] + (int) @$item["quantity_destroyed"]);
}
