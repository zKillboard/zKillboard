<?php

require_once "../init.php";

$tasks = $mdb->getCollection("zest3_tasks");

setter($tasks, "killmails");
setter($tasks, "mixed");
setter($tasks, "stats");
setter($tasks, "activity");
setter($tasks, "topisk");
setter($tasks, "topisk");

function setter($c, $task) {
    $match = [$task => ['$exists' => false]];
    $update = ['$set' => [$task => true]];
    $c->updateMany($match, $update);
}
