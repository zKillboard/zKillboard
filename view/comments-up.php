<?php

use cvweiss\redistools\RedisTtlCounter;

global $mdb, $ip, $redis;

$votes = new RedisTtlCounter("ttlc:votes:$ip", 300);
$key = "comment:$pageID";
$publish = false;
if ($commentID >= 0 && $commentID < count(Comments::$defaultComments) && $redis->get("validUser:$ip") == "true" && $votes->count() < 1500) {
    $votes->add(uniqid());
    $comment = $mdb->findDoc("comments", ['pageID' => $pageID, 'commentID' => $commentID]);
    if ($comment == null) {
        $comment = ['pageID' => $pageID, 'commentID' => $commentID, 'dttm' => $mdb->now(), 'upvotes' => 0, 'comment' => Comments::$defaultComments[$commentID]];
    }
    $comment['upvotes'] = $comment['upvotes'] + 1;

    $mdb->save("comments", $comment);
    $redis->del($key);
    $publish = true;
} else if ($votes->count() >= 1500) {
    Util::zout("Banning $ip for excessive comments");
    $redis->setex("IP:ban:$ip", 900, "true");
}

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

global $twig;
$out = $twig->render("components/commentblock.html", ['comments' => $comments]);
if ($publish) $redis->publish("comment:$pageID", json_encode(['action' => 'comment', 'html' => $out]));
echo $out;
