<?php

global $battleID;

$mc = null;
try {
    if (isset($GLOBALS['route_args'])) {
        $mc = RelatedReport::generateReport($system, $time, $options, $battleID, null);
        if (is_array($mc)) {
            $GLOBALS['render_template'] = 'related.html';
            $GLOBALS['render_data'] = $mc;
        } else {
            $GLOBALS['render_template'] = 'related_wait.html';
            $GLOBALS['render_data'] = ['showAds' => false];
        }
    } else {
        $mc = RelatedReport::generateReport($system, $time, $options, $battleID, $app);
        if (is_array($mc)) $app->render('related.html', $mc);
        else $app->render('related_wait.html', ['showAds' => false]);
    }
} catch (Exception $ex) {
    if (isset($GLOBALS['route_args'])) {
        $GLOBALS['render_template'] = 'related_wait.html';
        $GLOBALS['render_data'] = ['showAds' => false];
    } else {
        return $app->render('related_wait.html', ['showAds' => false]);
    }
}
