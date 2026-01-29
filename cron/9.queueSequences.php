<?php

require_once "../init.php";

$r2 = CloudFlare::getR2Client(
        $CF_ACCOUNT_ID,
        $CF_R2_ACCESS_KEY,
        $CF_R2_SECRET_KEY
        );

$options = [
    'ACL' => 'public-read',
    'CacheControl' => 'public, max-age=86400',
    'ContentType'  => 'application/json'
];
$ephSequenceKey = "zkb:ephemeral-sequence";

$minute = date("Hi");
do {
    $doc = $mdb->findDoc("queues", ['queue' => 'sequences']);
    $sequence = (int) @$doc['value'];
    if ($sequence > 0) {
        $kill = clean($mdb->findDoc("killmails", ['sequence' => $sequence]));
        $raw = clean($mdb->findDoc("esimails", ['killmail_id' => $kill['killID']]));
        $doc = [
            'killmail_id' => $kill['killID'],
            'hash' => $kill['zkb']['hash'],
            'esi' => $raw,
            'zkb' => $kill['zkb']
        ];
        CloudFlare::r2sendArray($r2, $CF_R2_BUCKET, $doc, "ephemeral/$sequence.json", $options);
        if ($sequence - ((int) $redis->get($ephSequenceKey)) >= 50) {
            // Update the current sequences file once per hour
            $array = ['sequence' => $sequence];
            CloudFlare::r2sendArray($r2, $CF_R2_BUCKET, $array, "ephemeral/sequence.json", $options);
            CloudFlare::purgeUrls($CF_ZONE_ID, $CF_API_TOKEN, ["https://r2z2.zkillboard.com/ephemeral/sequence.json"]);
            $redis->setex($ephSequenceKey, 3600, $sequence);
        }
        $mdb->remove("queues", ['queue' => 'sequences', 'value' => $sequence]);
    } else {
        sleep(1);
    }
} while ($minute == date("Hi"));

function clean($doc) {
    unset($doc['_id']);
    return $doc;
}
