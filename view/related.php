<?php

global $battleID;

$mc = null;
try {
    $mc = RelatedReport::generateReport($system, $time, $options, $battleID, $app);
    if (is_array($mc)) $app->render('related.html', $mc);
    else $app->render('related_wait.html', ['showAds' => false]);
} catch (Exception $ex) {
    return $app->render('related_wait.html', ['showAds' => false]);
}
