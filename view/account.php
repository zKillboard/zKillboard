<?php

global $mdb;

if (!User::isLoggedIn()) {
	$app->render("login.html");
	die();
}

$userID = User::getUserID();
$key = "me";
$error = "";

$bannerUpdates = array();
$aliasUpdates = array();

if(isset($req))
	$key = $req;

global $twig, $adFreeMonthCost, $baseAddr;
if($_POST)
{
	// Check for adfree purchase
	$purchase = Util::getPost("purchase");
	if ($purchase)
	{
		if ($purchase == "donate")
		{
			$amount = User::getBalance($userID);
			if ($amount > 0) {
				Db::execute("insert into zz_account_history (userID, purchase, amount) values (:userID, :purchase, :amount)",
					array(":userID" => $userID, ":purchase" => "donation", ":amount" => $amount));
				Db::execute("update zz_account_balance set balance = 0 where userID = :userID", array(":userID" => $userID));
				$twig->addGlobal("accountBalance", User::getBalance($userID));
				$error = "Thank you VERY much for your donation!";
			} else $error = "Gee, thanks for nothing...";
		}
		else
		{
			$months = str_replace("buy", "", $purchase);
			if ($months > 12 || $months < 0) $months = 1;
			$balance = User::getBalance($userID);
			$amount = $adFreeMonthCost * $months;
			$bonus = floor($months / 6);
			$months += $bonus;
			if ($balance >= $amount)
			{
				$dttm = UserConfig::get("adFreeUntil", null);
				$now = $dttm == null ? " now() " : "'$dttm'";
				$newDTTM = Db::queryField("select date_add($now, interval $months month) as dttm", "dttm", array(), 0);
				Db::execute("update zz_account_balance set balance = balance - :amount where userID = :userID",
						array(":userID" => $userID, ":amount" => $amount));
				Db::execute("insert into zz_account_history (userID, purchase, amount) values (:userID, :purchase, :amount)",
						array(":userID" => $userID, ":purchase" => $purchase, ":amount" => $amount));
				UserConfig::set("adFreeUntil", $newDTTM);

				$twig->addGlobal("accountBalance", User::getBalance($userID));
				$error = "Funds have been applied for $months month" . ($months == 1 ? "" : "s") . ", you are now ad free until $newDTTM";
				Log::log("Ad free time purchased by user $userID for $months months with " . number_format($amount) . " ISK");
			} else $error = "Insufficient Funds... Nice try though....";
		}
	}


	$keyid = Util::getPost("keyid");
	$vcode = Util::getPost("vcode");
	$label = Util::getPost("label");
	// Apikey stuff
	if(isset($keyid) || isset($vcode))
	{
		$error = Api::addKey($keyid, $vcode, $label);
	}

	$deletesessionid = Util::getPost("deletesessionid");
	// delete a session
	if(isset($deletesessionid))
		User::deleteSession($userID, $deletesessionid);

	$deletekeyid = Util::getPost("deletekeyid");
	$deleteentity = Util::getPost("deleteentity");
	// Delete an apikey
	if(isset($deletekeyid) && !isset($deleteentity))
		$error = Api::deleteKey($deletekeyid);

	// Theme
	$theme = Util::getPost("theme");
	if(isset($theme))
	{
		UserConfig::set("theme", $theme);
		$app->redirect($_SERVER["REQUEST_URI"]);
	}

	// Style
	$style = Util::getPost("style");
	if(isset($style))
	{
		UserConfig::set("style", $style);
		$app->redirect($_SERVER["REQUEST_URI"]);
	}

	// Email
	$email = Util::getPost("email");

	if(isset($email))
	{
		Db::execute("UPDATE zz_users SET email = :email WHERE id = :userID", array(":email" => $email, ":userID" => $userID));
	}

	// Password
	$orgpw = Util::getPost("orgpw");
	$password = Util::getPost("password");
	$password2 = Util::getPost("password2");
	// Password
	if(isset($orgpw) && isset($password) && isset($password2))
	{
		if($password != $password2)
			$error = "Passwords don't match, try again";
		elseif(Password::checkPassword($orgpw) == true)
		{
			Password::updatePassword($password);
			$error = "Password updated";
		}
		else
			$error = "Original password is wrong, please try again";
	}

	$timeago = Util::getPost("timeago");
	if(isset($timeago))
		UserConfig::set("timeago", $timeago);

	$deleteentityid = Util::getPost("deleteentityid");
	$deleteentitytype = Util::getPost("deleteentitytype");
	// Tracker
	if(isset($deleteentityid) && isset($deleteentitytype))
	{
		$q = UserConfig::get("tracker_" . $deleteentitytype);
		foreach($q as $k => $ent)
		{
			if($ent["id"] == $deleteentityid)
			{
				unset($q[$k]);
				$error = $ent["name"]." has been removed";
			}
		}
		UserConfig::set("tracker_" . $deleteentitytype, $q);
	}

	$entity = Util::getPost("entity");
	$entitymetadata = Util::getPost("entitymetadata");
	// Tracker
	if((isset($entity) && $entity != null) && (isset($entitymetadata) && $entitymetadata != null))
	{
		$entitymetadata = json_decode("$entitymetadata", true);
		$entities = UserConfig::get("tracker_" . $entitymetadata['type']);
		$entity = array('id' => $entitymetadata['id'], 'name' => $entitymetadata['name']);

		if(empty($entities) || !in_array($entity, $entities))
		{
			$entities[] = $entity;
			UserConfig::set("tracker_" . $entitymetadata['type'], $entities);
			$error = "{$entitymetadata['name']} has been added to your tracking list";
		}
		else
			$error = "{$entitymetadata['name']} is already being tracked";
	}

	$ddcombine = Util::getPost("ddcombine");
	if(isset($ddcombine))
		UserConfig::set("ddcombine", $ddcombine);

	$ddmonthyear = Util::getPost("ddmonthyear");
	if(isset($ddmonthyear))
		UserConfig::set("ddmonthyear",$ddmonthyear);

	$subdomain = Util::getPost("subdomain");
	if ($subdomain) 
	{
		$banner = Util::getPost("banner");
		$alias = Util::getPost("alias");
		$bannerUpdates = array("$subdomain" => $banner);
		if (strlen($alias) == 0 || (strlen($alias) >= 6 && strlen($alias) <= 64)) $aliasUpdates = array("$subdomain" => $alias);
		// table is updated if user is ceo/executor in code thta loads this information below
	}
}

$data["entities"] = Account::getUserTrackerData();

// Theme
$theme = UserConfig::get("theme", "zkillboard");
$data["themesAvailable"] = Util::themesAvailable();
$data["currentTheme"] = $theme;

// Style
$data["stylesAvailable"] = $theme::availableStyles();
$data["currentStyle"] = UserConfig::get("style");

$data["apiKeys"] = Api::getKeys($userID);
$data["apiChars"] = Api::getCharacters($userID);
$charKeys = Api::getCharacterKeys($userID);
$charKeys = Info::addInfo($charKeys);
$data["apiCharKeys"] = $charKeys;
$data["userInfo"] = User::getUserInfo();
$data["timeago"] = UserConfig::get("timeago");
$data["ddcombine"] = UserConfig::get("ddcombine");
$data["ddmonthyear"] = UserConfig::get("ddmonthyear");
$data["useSummaryAccordion"] = UserConfig::get("useSummaryAccordion", true);
$data["sessions"] = User::getSessions($userID);
$data["history"] = User::getPaymentHistory($userID);

$apiChars = Api::getCharacters($userID);
$domainChars = array();
if ($apiChars != null) foreach($apiChars as $apiChar) {
	$char = Info::getPilotDetails($apiChar["characterID"], null);
	$char["corpTicker"] = modifyTicker($mdb->findField("information", "ticker", ['type' => 'corporationID', 'id' => (int) @$char["corporationID"]]));
	$char["alliTicker"] = modifyTicker($mdb->findField("information", "ticker", ['type' => 'corporationID', 'id' => (int) @$char["allianceID"]]));

	$domainChars[] = $char;
}

$corps = array();
$allis = array();
foreach ($domainChars as $domainChar) {
	if (@$domainChar["isCEO"]) {
		$subdomain = modifyTicker($domainChar["corpTicker"]) . ".$baseAddr";
		if (isset($bannerUpdates[$subdomain])) {
			$banner = $bannerUpdates[$subdomain];

			Db::execute("insert into zz_subdomains (subdomain, banner) values (:subdomain, :banner) on duplicate key update banner = :banner", array(":subdomain" => $subdomain, ":banner" => $banner));
			$error = "$subdomain has been updated, please wait up to 2 minutes for the changes to take effect.";
		}
		if (isset($aliasUpdates[$subdomain])) 
		{
			$alias = $aliasUpdates[$subdomain];
			// Make sure no one else has the alias
			$count = Db::queryField("select count(*) count from zz_subdomains where alias = :alias and subdomain != :subdomain", "count", array(":subdomain" => $subdomain, ":alias" => $alias));
			if ($count == 0 || strlen($alias) == 0)
			{			
				Db::execute("insert into zz_subdomains (subdomain, alias) values (:subdomain, :alias) on duplicate key update alias = :alias", array(":subdomain" => $subdomain, ":alias" => $alias));
				$error = "$subdomain has been updated, please wait up to 2 minutes for the changes to take effect.";
			} else
			{
				$error = "Sorry, someone has already taken the subdomain $alias";
			}
		}

		$corpStatus = Db::queryRow("select adfreeUntil, banner, alias from zz_subdomains where subdomain = :subdomain", array(":subdomain" => $subdomain), 0);
		$domainChar["adfreeUntil"] = @$corpStatus["adfreeUntil"];
		$domainChar["banner"] = @$corpStatus["banner"];
		$domainChar["alias"] = @$corpStatus["alias"];
		$corps[] = $domainChar;
	}
	if (@$domainChar["isExecutorCEO"]) {
		$subdomain = modifyTicker($domainChar["alliTicker"]) . ".$baseAddr";
		if (isset($bannerUpdates[$subdomain])) {
			$banner = $bannerUpdates[$subdomain];
			Db::execute("insert into zz_subdomains (subdomain, banner) values (:subdomain, :banner) on duplicate key update banner = :banner", array(":subdomain" => $subdomain, ":banner" => $banner));
			$error = "Banner updated for $subdomain, please wait 2 minutes for the change to take effect.";
		}
		$status = Db::queryRow("select adfreeUntil, banner from zz_subdomains where subdomain = :subdomain", array(":subdomain" => $subdomain), 0);
		$domainChar["adfreeUntil"] = @$status["adfreeUntil"];
		$domainChar["banner"] = @$status["banner"];
		$allis[] = $domainChar;
	}

	$showDisqus = Util::getPost("showDisqus");
	if ($showDisqus)
	{
		UserConfig::set("showDisqus", $showDisqus == "true");
		$error = "Disqus setting updated to " . ($showDisqus ? " display." : " not display.") . " The next page load will reflect the change.";
	}
}
$data["domainCorps"] = $corps;
$data["domainAllis"] = $allis;
$data["domainChars"] = $domainChars;
$data["showDisqus"] = UserConfig::get("showDisqus", true);

$app->render("account.html", array("data" => $data, "message" => $error, "key" => $key, "reqid" => $reqid));

function modifyTicker($ticker) {
	$ticker = str_replace(" ", "_", $ticker);
	$ticker = preg_replace('/^\./', "dot.", $ticker);
	$ticker = preg_replace('/\.$/', ".dot", $ticker);
	return strtolower($ticker);
}
