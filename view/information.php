<?php

global $baseDir, $redis;

$page = preg_replace('[^\W]', '', $page);
$path = $baseDir."/information/$page.md";
if (!is_file($path)) {
    $app->redirect('/');
}

// Load the markdown file
$markdown = file_get_contents($path);

// Load the markdown parser
$parsedown = new Parsedown();
$output = $parsedown->text($markdown);

if ($page == 'payments') {
    global $adFreeMonthCost;
    $output = str_replace('{cost}', number_format($adFreeMonthCost, 0), $output);
}
$output = str_replace("href=\"#", "class='hrefit' name=\"", $output);

$titles = [
	"faq" => "FAQ"
];

if (isset($titles[$page])) $title = $titles[$page];
else $title = ucfirst($page);

// Load the information page html, which is just the bare minimum to load base.html and whatnot, and then spit out the markdown output!
$app->render('information.html', array('data' => $output, 'pageTitle' => $title));
