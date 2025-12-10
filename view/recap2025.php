<?php

function recap2025Handler($request, $response, $args, $container) {
    global $mdb, $redis;

    // Extract parameters (comes from overview.php)
    $inputString = $args['input'] ?? '';
    $input = explode('/', trim($inputString, '/'));
    
    $key = $input[0]; // character, corporation, or alliance
    $id = (int) $input[1];
    $type = $key; // For compatibility
    
    if (!in_array($key, ['character', 'corporation', 'alliance']) || $id == 0) {
        return $response->withStatus(302)->withHeader('Location', '/');
    }

    // Check cache first (72 hour TTL)
    $cacheKey = "recap2025:{$type}ID:$id";
    $cached = $mdb->findDoc('keyvalues', ['key' => $cacheKey]);
    if ($cached && isset($cached['value'])) {
		$data = json_decode($cached['value'], true);
		// Add generation time from the updated field
		if (isset($cached['updated'])) {
			$data['generationTime'] = $cached['updated'];
		}
		return $container->get('view')->render($response, 'recap2025.html', $data);
    }
	return $response->withStatus(302)->withHeader('Location', './../');
}
