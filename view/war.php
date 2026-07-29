<?php

function handler($request, $response, $args, $container) {
	$warID = (int) $args['warID'];
	$warData = War::getWarInfo($warID);
	$swapped = str_ends_with($request->getUri()->getPath(), '/swap/');
	if ($swapped) $warData = War::swapSides($warData);

	$page = 1;
	$pageTitle = "War $warID";

	return $container->get('view')->render($response->withHeader('Cache-Tag', "www,wars,war,war:$warID"), 'index.pug', array(
		'war' => $warData,
		'wars' => array($warData),
		'page' => $page,
		'pageType' => 'war',
		'pager' => false,
		'pageTitle' => $pageTitle,
		'warUrl' => "/war/$warID/" . ($swapped ? 'swap/' : ''),
		'warSwapUrl' => "/war/$warID/" . ($swapped ? '' : 'swap/'),
		'warAsyncBase' => War::asearchQueryBase($warID, $swapped),
		'warAttackerEntities' => War::sideEntities($warData, 'aggressor'),
		'warDefenderEntities' => War::sideEntities($warData, 'defender'),
		'warSwapped' => $swapped,
	));
}
