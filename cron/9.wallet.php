<?php

require_once '../init.php';

global $walletApis, $mdb;

if (!is_array($walletApis)) {
    return;
}

Status::check('xml');
$redisKey = 'zkb:walletCheck';
if ($redis->get($redisKey) != true) {
    $cacheUntil = 0;
    foreach ($walletApis as $api) {
        $type = $api['type'];
        $keyID = $api['keyID'];
        $vCode = $api['vCode'];
        $charID = $api['charID'];

        try {
            $pheal = getPheal($keyID, $vCode);
            $arr = array('characterID' => $charID, 'rowCount' => 1000);

            if ($type == 'char') {
                $q = $pheal->charScope->WalletJournal($arr);
                $cacheUntil = max($q->cached_until_unixtime, $cacheUntil);
                insertRecords($charID, $q->transactions);
            } elseif ($type == 'corp') {
                $q = $pheal->corpScope->WalletJournal($arr);
                $cacheUntil = max($q->cached_until_unixtime, $cacheUntil);
                insertRecords($charID, $q->entries);
            } else {
                continue;
            }

            Status::addStatus('xml', true);
        } catch (Exception $ex) {
            Status::addStatus('xml', false);
            Util::out('Failed to fetch Wallet API: '.$ex->getMessage());
            return;
        }
    }
    
    $next = $cacheUntil - time() + 1;
    $redis->setex($redisKey, $next, true);
}

applyBalances();

$rows = $mdb->find("payments", ['isk' => ['$exists' => false]]);
foreach ($rows as $row) {
    $date = $row['date'];
    $time = strtotime("$date UTC");
    $mdb->set("payments", $row, ['isk' => (double) $row['amount'], 'characterID' => (int) $row['ownerID1'], 'dttm' => new MongoDate($time)]);
}


function applyBalances()
{
    global $walletCharacterID, $baseAddr, $mdb, $adFreeMonthCost, $redis;

    // First, set any new records to paymentApplied = 0
    $mdb->set('payments', ['paymentApplied' => ['$ne' => 1]], ['paymentApplied' => 0], true);

    // And then iterate through un-applied payments
    $toBeApplied = $mdb->find('payments', ['paymentApplied' => 0]);
    if ($toBeApplied == null) {
        $toBeApplied = [];
    }
    foreach ($toBeApplied as $row) {
        if ($row['ownerID2'] != $walletCharacterID && $row['ownerID2'] != 98207592) {
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
                $charID = (int) $row['ownerID1'];
                $userInfo = $mdb->findDoc("users", ['userID' => "user:$charID"]);
                if ($userInfo == null) {
                    $userInfo = ['userID' => "user:$charID", 'characterID' => $charID];
                    $mdb->insert("users", $userInfo);
                }
                $adFreeUntil = (int) @$userInfo['adFreeUntil'];
                if ($adFreeUntil < time()) {
                    $adFreeUntil = time();
                }

                $adFreeUntil += (86400 * 30 * $months);
                $charName = Info::getInfoField('characterID', $charID, 'name');
                $amount = number_format($amount, 0);
                $mdb->set("users", $userInfo, ['adFreeUntil' => $adFreeUntil]);
                $mdb->set('payments', $row, ['months' => "$months months"]);

                ZLog::add("$months month" . ($months == 1 ? "" : "s")  . " of ad free time has been given to $charName from $amount ISK.", $charID);
                User::sendMessage("Thank you for your payment of $amount ISK. $months month" . ($months == 1 ? "" : "s")  . " of ad free time has been given to $charName", $charID);
                EveMail::send($charID, "ISK Received", "Thank you for your payment of $amount ISK. $months months of ad free time has been given to $charName");
            }
            $mdb->set('payments', $row, ['paymentApplied' => 1]);
        }
    }
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

function getPheal($keyID = null, $vCode = null)
{
    global $apiServer, $baseAddr;

    \Pheal\Core\Config::getInstance()->http_method = 'curl';
    \Pheal\Core\Config::getInstance()->http_user_agent = "API Fetcher for http://$baseAddr";
    \Pheal\Core\Config::getInstance()->http_post = false;
    \Pheal\Core\Config::getInstance()->http_keepalive = true; // default 15 seconds
    \Pheal\Core\Config::getInstance()->http_keepalive = 10; // KeepAliveTimeout in seconds
    \Pheal\Core\Config::getInstance()->http_timeout = 30;
    \Pheal\Core\Config::getInstance()->api_customkeys = true;
    \Pheal\Core\Config::getInstance()->api_base = $apiServer;

    $pheal = new \Pheal\Pheal($keyID, $vCode);

    return $pheal;
}
