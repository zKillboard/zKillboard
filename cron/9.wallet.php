<?php

require_once '../init.php';

global $esiServer, $adminCharacter;

$sso = ZKillSSO::getSSO();

if ($redis->get("zkb:noapi") == "true") exit();
if ($redis->get("zkb:420prone") == "true") exit();

$redisKey = 'zkb:walletCheck';
if ($redis->get($redisKey) != true) {
    $guzzler = new Guzzler();
    $scope = $mdb->findDoc("scopes", ['characterID' => $adminCharacter, 'scope' => 'esi-wallet.read_character_wallet.v1']);
    if (isset($scope['refreshToken'])) {
        $refreshToken = $scope['refreshToken'];
        $params = ['redis' => $redis];
        $accessToken = $sso->getAccessToken($refreshToken);
        $content = $sso->doCall("$esiServer/characters/$adminCharacter/wallet/journal/", [], $accessToken);
        if ($content != "") {
            success($params, $content);
            $redis->del("zkb:monocled");
        }
    }
}

$rows = $mdb->find("payments", ['isk' => ['$exists' => false]]);
foreach ($rows as $row) {
    $date = $row['date'];
    $time = strtotime("$date UTC");
    $mdb->set("payments", $row, ['isk' => (double) $row['amount'], 'characterID' => (int) $row['ownerID1'], 'dttm' => new MongoDate($time)]);
}

function success(&$params, $content)
{
    if ($content == "") return;

    $response = json_decode($content, true);
    insertRecords($response);
    applyBalances();
    $redis = $params['redis'];
    $redis->setex("zkb:walletCheck", 3600, "true");
}

function applyBalances()
{
    global $adminCharacter, $baseAddr, $mdb, $adFreeMonthCost, $redis;

    // First, set any new records to paymentApplied = 0
    $mdb->set('payments', ['paymentApplied' => ['$ne' => 1]], ['paymentApplied' => 0], true);

    // And then iterate through un-applied payments
    $toBeApplied = $mdb->find('payments', ['paymentApplied' => 0], ['date' => -1]);
    if ($toBeApplied == null) {
        $toBeApplied = [];
    }
    foreach ($toBeApplied as $row) {
        if ($row['ownerID2'] != $adminCharacter && $row['ownerID2'] != 98207592) {
            continue;
        }

        $date = $row['date'];
        $time = strtotime($date);
        $amount = $row['amount'];

        $multiplier = (date_format(date_create($date), "N") == 2) ? 2 : 1;
        if ($multiplier == 2) {
            Log::log("ISK DOUBLED! \o/");
            $amount = $multiplier * $amount;
        }

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
                $shortAmount = Util::formatIsk($amount, true);
                $amount = number_format($amount, 0);
                $mdb->set("users", $userInfo, ['adFreeUntil' => $adFreeUntil]);
                $mdb->set('payments', $row, ['months' => "$months months"]);

                ZLog::add("$months month" . ($months == 1 ? "" : "s")  . " of ad free time has been given to $charName from $amount ISK.", $charID);
                User::sendMessage("Thank you for your payment of $amount ISK. $months month" . ($months == 1 ? "" : "s")  . " of ad free time has been given to $charName", $charID);
                Util::sendEveMail($charID, "$shortAmount ISK Received", "Thank you for your payment of $amount ISK. $months months of ad free time has been given to $charName.\n\n<a href=\"https://zkillboard.com/character/$charID/\">Your zKillboard character page.</a><br/><br/>- Tuesday's (UTC) your ISK is automatically doubled.<br/><br/>- This is an automated message triggered by your ISK transfer.");
            }
        }
        $mdb->set('payments', $row, ['paymentApplied' => 1]);
    }
}

function insertRecords($records)
{
    global $mdb;

    foreach ($records as $record) {
        if ($record['amount'] < 0) {
            continue;
        }
        if ($mdb->count('payments', ['refID' => (string) $record['id']]) > 0) {
            continue;
        }
        $record['refID'] = (string) $record['id'];
        $record['esi'] = true;
        $record['refTypeID'] = $record['ref_type'] == 'player_donation' ? 10 : 0;
        $record['ownerID1'] = $record['first_party_id'];
        $record['ownerID2'] = $record['second_party_id'];

        $mdb->save('payments', $record);
    }
}
