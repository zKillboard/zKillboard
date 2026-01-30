<?php

require_once "../init.php";

if (@$sendSequences !== true) exit();

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
        $killID = $kill['killID'];
        $raw = clean($mdb->findDoc("esimails", ['killmail_id' => $kill['killID']]));

        $zkb = $kill['zkb'];
        $zkb['npc'] = @$kill['npc'];
        $zkb['solo'] = @$kill['solo'];
        $zkb['awox'] = @$kill['awox'];
        $zkb['labels'] = @$kill['labels'];
        $zkb['attackerCount'] = @$kill['attackerCount'];
        $zkb['href'] = "$esiServer/killmails/$killID/".$zkb['hash'].'/';

        $doc = [
            'killmail_id' => $kill['killID'],
            'hash' => $kill['zkb']['hash'],
            'esi' => $raw,
            'zkb' => $zkb
        ];
        CloudFlare::r2sendArray($r2, $CF_R2_BUCKET, $doc, "ephemeral/$sequence.json", $options);
        $redis->sadd("queueCacheUrls", "https://r2z2.zkillboard.com/ephemeral/$sequence.json");
        if ($sequence - ((int) $redis->get($ephSequenceKey)) >= 50) {
            // Update the current sequences file once per hour
            $array = ['sequence' => $sequence];
            CloudFlare::r2sendArray($r2, $CF_R2_BUCKET, $array, "ephemeral/sequence.json", $options);
            $redis->sadd("queueCacheUrls", "https://r2z2.zkillboard.com/ephemeral/sequence.json");
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
