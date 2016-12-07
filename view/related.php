<?php

global $battleID;

$mc = RelatedReport::generateReport($system, $time, $options, $battleID, $app);
$app->render('related.html', $mc);
