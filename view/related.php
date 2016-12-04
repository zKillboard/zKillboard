<?php

$mc = RelatedReport::generateReport($system, $time, $options, $app);
$app->render('related.html', $mc);
