<?php

/*

https://www.patreon.com/portal/registration/register-clients

URL needed to get the refresh token since if it errors and Patreon
updates the refresh_token, we no longer have a valid refresh_token...

*/

require "../init.php";

use Patreon\OAuth;
use Patreon\API;
use MongoDB\BSON\UTCDateTime;

if ($redis->get("zkb:patreonupdates") == "true") exit();

global $patreon_client_id, $patreon_client_secret, $patreon_redirect_uri, $patroen_campaign_id;
if (!isset($patreon_campaign_id)) exit(); // not setup in local, stop erroring


$client_id = $patreon_client_id;
$client_secret = $patreon_client_secret;

$campaign_id = $patreon_campaign_id;
$refresh_token = $kvc->get('patreon-refresh-token');
$access_token = null;

$oauth = new OAuth($client_id, $client_secret);
$tokens = $oauth->refresh_token($refresh_token, $patreon_redirect_uri . "?scope=identity+campaigns.members+campaigns.members.email");

if (!isset($tokens['access_token'])) {
    Util::out("ERROR: Could not obtain Patreon access token.");
    exit(1);
}

$kvc->set('patreon-refresh-token', $tokens['refresh_token']);

$access_token = $tokens['access_token'];
if (isset($tokens['refresh_token'])) {
    $refresh_token = $tokens['refresh_token'];
}

$base_url = "https://www.patreon.com/api/oauth2/v2/campaigns/{$campaign_id}/members";
$params = [
    'include' => 'user,currently_entitled_tiers',
    'fields[member]' => 'full_name,email,patron_status,last_charge_date,last_charge_status,currently_entitled_amount_cents,pledge_relationship_start',
    'fields[user]'   => 'full_name,email,vanity,thumb_url',
    'page[count]'    => 100
];

$members = [];
$next_url = $base_url . '?' . http_build_query($params);
$page = 1;

do {
    $ch = curl_init($next_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $access_token",
            "User-Agent: PatreonFetcher/1.0"
        ]
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    if (!isset($data['data']) || empty($data['data'])) {
        Util::out("ERROR Patreon refresh failed - No data returned or API error");
        exit(1);
    }

    foreach ($data['data'] as $m) {
        $a = $m['attributes'] ?? [];
        $status = $a['patron_status'] ?? 'unknown';
        $name   = $a['full_name'] ?? '(no name)';
        $email  = $a['email'] ?? '(hidden)';
        $pledge = isset($a['currently_entitled_amount_cents'])
            ? number_format($a['currently_entitled_amount_cents'] / 100, 2)
            : '0.00';

        $members[] = $m;
    }

    // Check for a next page link
    $next_url = $data['links']['next'] ?? null;
    $page++;

    // Safety to avoid infinite loops
    if ($page > 50) break;

    // Avoid rate limits
    usleep(250000); // 0.25 seconds

} while ($next_url);

$total = 0;
$active = 0;
foreach ($members as $m) {
    $attrs = $m['attributes'] ?? [];
    $status = $attrs['patron_status'] ?? $attrs['last_charge_status'] ?? 'unknown';
    $amount = ($attrs['currently_entitled_amount_cents'] ?? 0) / 100;
    $fullName = $attrs['full_name'] ?? '(no name)';
    $email = $attrs['email'] ?? '(hidden)';

    // Find linked user info (Patreon account)
    $userId = null;
    if (isset($m['relationships']['user']['data']['id'])) {
        $userId = (int) $m['relationships']['user']['data']['id'];
    }
    
//if ($userId == 4656951) print_r($m);
    $row = $mdb->findDoc("patreon", ['patreon_id' => $userId]);
    if ($row) {
        if ($amount <= 0) continue;
        $active++;
        $total += (float) $amount;
        $charName = Info::getInfoField("characterID", $row['character_id'], "name");
        $dt = new DateTime();
        // Add 14 days to allow for buffer
        $dt->modify('+14 days');
        $mongoDate = new UTCDateTime($dt->getTimestamp() * 1000);
        $mdb->set("patreon", $row, ['expires' => $mongoDate]);
        $humanDate = $mongoDate->toDateTime()->format(DateTime::ATOM);
        echo " - {$charName} :: pledged=\${$amount} thru $humanDate\n";
    }
}
Util::out("Patreon subs refreshed: $active subs at \$$total");

$redis->setex("zkb:patreonupdates", 88888, "true");
