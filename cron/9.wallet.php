<?php

require_once '../init.php';

$redisKey = 'zkb:walletCheck';
if ($redis->get($redisKey) == true) {
    exit();
}

global $walletApis, $mdb;

if (!is_array($walletApis)) {
    return;
}

foreach ($walletApis as $api) {
    $type = $api['type'];
    $keyID = $api['keyID'];
    $vCode = $api['vCode'];
    $charID = $api['charID'];

    try {
        $pheal = Util::getPheal($keyID, $vCode, true);
        $arr = array('characterID' => $charID, 'rowCount' => 1000);

        if ($type == 'char') {
            $q = $pheal->charScope->WalletJournal($arr);
        } elseif ($type == 'corp') {
            $q = $pheal->corpScope->WalletJournal($arr);
        } else {
            continue;
        }

        if (count($q->transactions)) {
            insertRecords($charID, $q->transactions);
        }
    } catch (Exception $ex) {
        Util::out('Failed to fetch Wallet API: '.$ex->getMessage());
        return;
    }
}

applyBalances();
$redis->setex($redisKey, 1800, true);

function applyBalances()
{
    global $walletCharacterID, $baseAddr, $mdb, $adFreeMonthCost, $redis;

    $flushNeeded = false;
    // First, set any new records to paymentApplied = 0
    $mdb->set('payments', ['paymentApplied' => ['$ne' => 1]], ['paymentApplied' => 0], true);

    // And then iterate through un-applied payments
    $toBeApplied = $mdb->find('payments', ['paymentApplied' => 0]);
    if ($toBeApplied == null) {
        $toBeApplied = [];
    }
    foreach ($toBeApplied as $row) {
        if ($row['ownerID2'] != $walletCharacterID) {
            continue;
        }

        $date = $row['date'];
        $time = strtotime($date);
        $amount = $row['amount'];
        $months = floor($amount / $adFreeMonthCost);
        $bonusMonths = floor($months / 6);
        $months += $bonusMonths;

        if ($row['refTypeID'] == 10) { // Character donation
            if ($amount >= $adFreeMonthCost) {
                $charID = $row['ownerID1'];
                $adFreeUntil = (int) $redis->hGet("user:$charID", 'adFreeUntil');
                if ($adFreeUntil < time()) {
                    $adFreeUntil = time();
                }

                $adFreeUntil += (86400 * 30 * $months);
                $charName = Info::getInfoField('characterID', $charID, 'name');
                $amount = number_format($amount, 0);
                $redis->hSet("user:$charID", 'adFreeUntil', $adFreeUntil);
                $mdb->set('payments', $row, ['months' => "$months months"]);

                Util::out("$charID $charName $amount $months $adFreeUntil ".date('Y-m-d', $adFreeUntil));
                User::sendMessage("Thank you for your payment. $months month" . ($months == 1 ? "" : "s")  . " of ad free time has been given to $charName", $charID);
                EveMail::send($charID, "ISK Received", "Thank you for your payment. $months months of ad free time has been given to $charName");
                $flushNeeded = true;
            }
            $mdb->set('payments', $row, ['paymentApplied' => 1]);
        }
    }
    if ($flushNeeded) $redis->del("zkb:userFlush");
}

function insertRecords($charID, $records)
{
    global $mdb;

    foreach ($records as $record) {
        if ($record['amount'] < 0) {
            continue;
        }
        if ($mdb->count('payments', ['refID' => $record['refID']]) > 0) {
            continue;
        }
        $mdb->save('payments', $record);
    }
}
