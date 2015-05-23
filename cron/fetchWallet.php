<?php

require_once "../init.php";

$lastWalletFetch = Storage::retrieve("NextWalletFetch");
$time = strtotime($lastWalletFetch);
if ($time >= time()) exit();

if (Util::isMaintenanceMode()) return;
if (Util::is904Error()) return;
global $walletApis;

if (!is_array($walletApis)) return;

foreach ($walletApis as $api)
{
	$type = $api["type"];
	$keyID = $api["keyID"];
	$vCode = $api["vCode"];
	$charID = $api["charID"];

	$pheal = Util::getPheal($keyID, $vCode, true);
	$arr = array("characterID" => $charID, "rowCount" => 1000);

	if ($type == "char") $q = $pheal->charScope->WalletJournal($arr);
	else if ($type == "corp") $q = $pheal->corpScope->WalletJournal($arr);
	else continue;

	//$cachedUntil = $q->cached_until;
	if (count($q->transactions)) insertRecords($charID, $q->transactions);
}
Db::execute("replace into zz_storage values ('NextWalletFetch', date_add(now(), interval 35 minute))");

applyBalances();

function applyBalances()
{
	global $walletCharacterID, $baseAddr;
	$toBeApplied = Db::query("select * from zz_account_wallet where paymentApplied = 0", array(), 0);
	foreach($toBeApplied as $row)
	{
		if ($row["ownerID2"] != $walletCharacterID) continue;
		$userID = null;

		$reason = $row["reason"];
		if (strpos($reason, ".{$baseAddr}") !== false) {
			global $adFreeMonthCost;
			$months = $row["amount"] / $adFreeMonthCost;
			$bonusMonths = floor($months / 6);
			$months += $bonusMonths;
			$subdomain = trim(str_replace("DESC: ", "", $reason));
			$subdomain = str_replace("http://", "", $subdomain);
			$subdomain = str_replace("https://", "", $subdomain);
			$subdomain = str_replace("/", "", $subdomain);

			$aff = Db::execute("insert into zz_subdomains (subdomain, adfreeUntil) values (:subdomain, date_add(now(), interval $months month)) on duplicate key update adfreeUntil = date_add(if(adfreeUntil is null, now(), adfreeUntil), interval $months month)", array(":subdomain" => $subdomain));
			if ($aff) Db::execute("update zz_account_wallet set paymentApplied = 1 where refID = :refID", array(":refID" => $row["refID"]));
			continue;
		}
		if ($reason)
		{
			$reason = trim(str_replace("DESC: ", "", $reason));
			$userID = Db::queryField("select id from zz_users where username = :reason", "id", array(":reason" => $reason));
		}

		if ($userID == null) 
		{
			$charID = $row["ownerID1"];
			$keyIDs = Db::query("select keyID from zz_api_characters where characterID = :charID", array(":charID" => $charID), 1);
			foreach($keyIDs as $keyIDRow) {
				if ($userID) continue;
				$keyID = $keyIDRow["keyID"];
				$userID = Db::queryField("select userID from zz_api where keyID = :keyID", "userID", array(":keyID" => $keyID), 1);
			}
		}

		if ($userID)
		{
			Db::execute("insert into zz_account_balance values (:userID, :amount) on duplicate key update balance = balance + :amount", array(":userID" => $userID, ":amount" => $row["amount"]));
			Db::execute("update zz_account_wallet set paymentApplied = 1 where refID = :refID", array(":refID" => $row["refID"]));
		}
	}
}

function insertRecords($charID, $records) {
	foreach ($records as $record) {
		Db::execute("insert ignore into zz_account_wallet (characterID, dttm, refID, refTypeID, ownerName1, ownerID1, ownerName2, ownerID2, argName1, argID1,amount, balance, reason, taxReceiverID, taxAmount) values (:charID, :dttm , :refID, :refTypeID, :ownerName1, :ownerID1, :ownerName2, :ownerID2, :argName1, :argID1, :amount, :balance, :reason, :taxReceiverID, :taxAmount)",
				array(
					":charID"        => $charID,
					":dttm"          => $record["date"],
					":refID"         => $record["refID"],
					":refTypeID"     => $record["refTypeID"],
					":ownerName1"    => $record["ownerName1"],
					":ownerID1"      => $record["ownerID1"],
					":ownerName2"    => $record["ownerName2"],
					":ownerID2"      => $record["ownerID2"],
					":argName1"      => $record["argName1"],
					":argID1"        => $record["argID1"],
					":amount"        => $record["amount"],
					":balance"       => $record["balance"],
					":reason"        => $record["reason"],
					":taxReceiverID" => $record["taxReceiverID"],
					":taxAmount"     => $record["taxAmount"]
				     )
			    );
	}
}
