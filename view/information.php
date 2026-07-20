<?php

function handler($request, $response, $args, $container) {
	global $baseDir, $redis;

	$page = $args['page'];
	$page = preg_replace('[^\W]', '', $page);
	$path = $baseDir."/information/$page.md";
	if (!is_file($path)) {
		return $response->withHeader('Location', '/')->withStatus(302);
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
	$output = preg_replace('/href="#([^"]+)"/', 'class=\'hrefit\' id="$1" name="$1" href="#$1"', $output);

	$titles = [
		"faq" => "FAQ"
	];

	if (isset($titles[$page])) $title = $titles[$page];
	else $title = ucfirst($page);

	$data = array('data' => $output, 'pageTitle' => $title);
	return $container->get('view')->render($response->withHeader('Cache-Tag', "www,information,information:$page"), 'information.pug', $data);
}
