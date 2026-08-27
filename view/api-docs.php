<?php

function handler($request, $response, $args, $container) {
	global $mdb;

	$documentation = json_decode(
		file_get_contents(__DIR__ . '/../public/api/api.json'),
		true,
		512,
		JSON_THROW_ON_ERROR
	);

	try {
		$latestSequence = (int) $mdb->findField('killmails', 'sequence', [], ['sequence' => -1]);
	} catch (Throwable $ex) {
		$latestSequence = 0;
	}

	if ($latestSequence > 0) {
		foreach ($documentation['sections'] as &$section) {
			foreach ($section['endpoints'] as &$endpoint) {
				if ($endpoint['path'] == 'r2z2.zkillboard.com/ephemeral/sequence.json') {
					$endpoint['response'] = '{ "sequence": ' . $latestSequence . ' }';
				}

				if ($endpoint['path'] != 'r2z2.zkillboard.com/ephemeral/{sequence}.json') continue;

				foreach ($endpoint['form']['fields'] as &$field) {
					if ($field['name'] == 'sequence') $field['default'] = (string) $latestSequence;
				}
				unset($field);

				foreach ($endpoint['parameters'] as &$parameter) {
					if ($parameter['name'] == 'sequence') $parameter['example'] = (string) $latestSequence;
				}
				unset($parameter);

				foreach ($endpoint['examples'] as &$example) {
					$example['code'] = preg_replace('#/ephemeral/\d+\.json#', "/ephemeral/$latestSequence.json", $example['code']);
				}
				unset($example);
			}
			unset($endpoint);
		}
		unset($section);
	}

	return $container->get('view')->render(
		$response->withHeader('Cache-Tag', 'www,api,documentation')->withHeader('Cache-Control', 'public, max-age=3600'),
		'api.pug',
		['sections' => $documentation['sections'], 'showAds' => 0]
	);
}
