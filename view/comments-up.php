<?php

if (!User::isLoggedIn()) {
//    die("Nope, you have to be <a href='/ccplogin/'>logged in</a> to have this sort of fun.");
}

global $mdb, $ip, $redis;

$key = "comment:$pageID";
if ($commentID >= 0 && $commentID < count(Comments::$defaultComments)) {
    $comment = $mdb->findDoc("comments", ['pageID' => $pageID, 'commentID' => $commentID]);
    if ($comment == null) {
        $comment = ['pageID' => $pageID, 'commentID' => $commentID, 'dttm' => $mdb->now(), 'upvotes' => 0, 'comment' => Comments::$defaultComments[$commentID]];
    }
    $comment['upvotes'] = $comment['upvotes'] + 1;

    $mdb->save("comments", $comment);
    $redis->del($key);
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

$app->render("components/commentblock.html", ['comments' => $comments]);
