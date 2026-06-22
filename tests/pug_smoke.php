<?php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../classes/PugTemplateEnvironment.php';

$baseDir = dirname(__DIR__);
$templates = new PugTemplateEnvironment($baseDir . '/templates/');

$pugOutput = $templates->render('simple_test.pug', ['message' => 'Pug OK']);
if (strpos($pugOutput, 'Pug OK') === false) {
    fwrite(STDERR, "Pug smoke render failed.\n");
    exit(1);
}

$staticOutput = $templates->render('xml/zkbsearch.xml');
if (strpos($staticOutput, '<ShortName>zKillboard</ShortName>') === false) {
    fwrite(STDERR, "Static XML render failed.\n");
    exit(1);
}

echo "Pug smoke test passed.\n";
