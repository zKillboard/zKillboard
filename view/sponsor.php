<?php

global $mdb;

$killID = (int) $killID;
$value = (int) $value;

$userID = User::getUserID();
$charName = Info::getInfoField('characterID', $userID, 'name');
$user = $mdb->findDoc("users", ['userID' => "user:$userID"]);
$adFreeUntil = (int) @$user['adFreeUntil'];
$iskAvailable = floor(max(0, ($adFreeUntil - time()) / (86400 * 30)) * 5000000);

$response = "";
$valueF = "";

$referrer = @$_SERVER['HTTP_REFERER'];
if ($referrer != "https://zkillboard.com/kill/$killID/") {
    $app->redirect("/kill/$killID/");
    return;
}

switch ($type) {
    case "query":
        $value = Mdb::group("sponsored", ['killID'], ['killID' => $killID], [], 'isk', ['iskSum' => -1], 5);
        $value = array_shift($value);
        $value = @$value['iskSum'];
        $valueF = number_format($value, 0);
        break;
    case "sponsor":
        $formatted = number_format($value, 0);
        $timeValue = abs(floor(($value / 5000000) * (86400 * 30)));

        if ($userID == 0) {
            $response = "You aren't even logged in!";
        } else if ($value == 0) {
            $response = "0 ISK? Come on...";
        } else if (($iskAvailable - abs($value)) < -50000) {
            $response = "Not enough ISK, you requested to apply " . abs($value) . " ISK but only have $iskAvailable available.";
        } /*else if ($value % 1000000 != 0) {
            $response = "Please sponsor in increments of 1,000,000 ISK. Yea, yea, I know, 420 and 69 are cool, but don't be cheap.";
        } */else {
            $mdb->insert("sponsored", ['characterID' => User::getUserID(), 'isk' => $value, 'killID' => $killID, 'entryTime' => $mdb->now()]);
            $mdb->set("users", ['userID' => "user:$userID"], ['adFreeUntil' => ($adFreeUntil - $timeValue)]);
            ZLog::add("$charName sponsored $formatted ISK for kill $killID", $userID, true);
            $response = "Thank you! You have sponsored $formatted ISK for this kill! Please give the front page up to 2 minutes and entity pages up to 10 minutes to reflect your kill sponsorship. Please remember sponsorships will expire after 7 days.";
        }      
        break;
    default:
}

$app->render("sponsor.html", ['killID' => $killID, 'iskA' => $iskAvailable, 'response' => $response, 'valueF' => $valueF, 'type' => $type]);
