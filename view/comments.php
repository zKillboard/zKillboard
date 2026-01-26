<?php

use cvweiss\redistools\RedisTtlCounter;

function handler($request, $response, $args, $container) {
    global $mdb, $ip, $redis, $twig;

    $pageID = $args['pageID'] ?? '';

    $votes = new RedisTtlCounter("ttlc:votes:$ip", 300);
    $key = "comment:$pageID";
    $publish = false;

    $comments = $redis->get($key);
    if ($comments !== false) {
        $comments = json_decode($comments, true);
    } else {
        // Comments
        $c = $mdb->find("comments", ['pageID' => $pageID], ["upvotes" => -1, "dttm" => 1]);
        $comments = [];
        foreach ($c as $cc) {
            $comments[$cc['comment']] = $cc;
        }
        $index = 0;
        foreach (Comments::$defaultComments as $dc) {
            if (!isset($comments[$dc])) $comments[$dc] = ['pageID' => $pageID, 'commentID' => $index, 'comment' => $dc, "upvotes" => 0];
            $index++;
        }
        $redis->setex($key, 60, json_encode($comments));
    }

    $out = $twig->render("components/commentblock.html", ['comments' => $comments]);
    if ($publish) $redis->publish("comment:$pageID", json_encode(['action' => 'comment', 'html' => $out]));

    $response->getBody()->write($out);
    return $response->withHeader('Cache-Control', 'max-age:86400')->withHeader('Cache-Tag', "comments,$key");
}
