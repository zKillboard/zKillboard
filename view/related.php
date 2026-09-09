<?php

function handler($request, $response, $args, $container) {
    global $battleID, $mdb;
    
    $system = $args['system'];
    $time = $args['time'];
    $options = $args['options'] ?? '';
    $cacheTag = "www,related,related:system:$system";
    
    $queryParams = $request->getQueryParams();
    $json_options = Related::normalizeOptions(json_decode(urldecode($options), true));
    if (isset($queryParams['hoursBefore']) || isset($queryParams['hoursAfter'])) {
        foreach (['hoursBefore', 'hoursAfter'] as $key) {
            if (isset($queryParams[$key])) $json_options[$key] = $queryParams[$key];
        }
        $json = urlencode(json_encode(Related::normalizeOptions($json_options)));
        return $response->withHeader('Location', "/related/$system/$time/o/$json/")->withStatus(302);
    }
    if (isset($queryParams['addSystem']) || isset($queryParams['removeSystem'])) {
        $selection = Related::getSystems($system, $json_options['systems'] ?? []);
        $systems = $selection['selected'];
        $add = $queryParams['addSystem'] ?? null;
        $remove = $queryParams['removeSystem'] ?? null;
        if (is_scalar($add) && ctype_digit((string) $add) && count($systems) < 10 && in_array((int) $add, $selection['adjacent'])) $systems[] = (int) $add;
        if (is_scalar($remove) && count($systems) > 1) $systems = array_values(array_diff($systems, [$remove]));
        sort($systems, SORT_NUMERIC);
        if (!in_array((int) $system, $systems)) $system = $systems[0];
        $json_options['systems'] = $systems;
        $json = urlencode(json_encode($json_options));
        return $response->withHeader('Location', "/related/$system/$time/o/$json/")->withStatus(302);
    }
    $entity = $queryParams['entity'] ?? null;
    $side = $queryParams['side'] ?? null;
    if (is_scalar($entity) && ctype_digit((string) $entity) && $entity > 0 && is_string($side) && ($side == 'excluded' || preg_match('/^[A-Z]+$/D', $side))) {
        foreach ($json_options as $key => &$entities) {
            if (in_array($key, ['systems', 'hoursBefore', 'hoursAfter'])) continue;
            $entities = array_values(array_diff($entities, [$entity]));
        }
        unset($entities);
        $json_options[$side][] = (int) $entity;
        $json = urlencode(json_encode(Related::normalizeOptions($json_options)));
        return $response->withHeader('Location', "/related/$system/$time/o/$json/")->withStatus(302);
    }

    try {
        $mc = RelatedReport::generateReport($system, $time, $options, $battleID, null);
        if (@$mc['complete'] !== true) {
			return $container->get('view')->render($response->withStatus(202)->withHeader('Cache-Tag', $cacheTag), 'related_wait.pug', ['showAds' => false]);
        }

		return $container->get('view')->render($response->withHeader('Cache-Tag', $cacheTag), 'related.pug', $mc);
    } catch (\InvalidArgumentException $ex) {
        // Invalid time format - redirect to rounded time
        $roundedTime = substr($time, 0, strlen("$time") - 2) . "00";
        return $response->withHeader('Location', "/related/$system/$roundedTime/")->withStatus(302);
    } catch (\RuntimeException $ex) {
        // System reinforced or queue busy
        $systemID = (int) $system;
        $unixTime = strtotime($time);
        if ($ex->getMessage() === "System is reinforced") {
            return $container->get('view')->render($response->withStatus(202)->withHeader('Cache-Tag', $cacheTag), 'related_reinforced.pug', ['showAds' => false]);
        } else if (str_contains($ex->getMessage(), "Queue is too busy")) {
            return $container->get('view')->render($response->withStatus(202)->withHeader('Cache-Tag', $cacheTag), 'related_notnow.pug', ['showAds' => false, 'solarSystemID' => $systemID, 'unixtime' => $unixTime]);
        } else {
            return $container->get('view')->render($response->withStatus(202)->withHeader('Cache-Tag', $cacheTag), 'related_wait.pug', ['showAds' => false]);
        }
    } catch (Exception $ex) {
        return $container->get('view')->render($response->withHeader('Cache-Tag', $cacheTag), 'related_wait.pug', ['showAds' => false]);
    }
}
