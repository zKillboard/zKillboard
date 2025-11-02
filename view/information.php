<?php

global $baseDir, $redis;

$page = preg_replace('[^\W]', '', $page);
$path = $baseDir."/information/$page.md";
if (!is_file($path)) {
    // Handle redirect for Slim 3 compatibility
    if (isset($GLOBALS['capture_render_data']) && $GLOBALS['capture_render_data']) {
        $GLOBALS['redirect_response'] = $GLOBALS['slim3_response']->withStatus(302)->withHeader('Location', '/');
        return;
    } else {
        $app->redirect('/');
    }
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
// Handle rendering for Slim 3 compatibility
if (isset($GLOBALS['capture_render_data']) && $GLOBALS['capture_render_data']) {
	$GLOBALS['render_template'] = 'information.html';
	$GLOBALS['render_data'] = array('data' => $output, 'pageTitle' => $title);
} else {
	// Fallback for any remaining Slim 2 usage
	$app->render('information.html', array('data' => $output, 'pageTitle' => $title));
}
