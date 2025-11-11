<?php

function handler($request, $response, $args, $container) {
    global $battleID, $mdb;
    
    $system = $args['system'];
    $time = $args['time'];
    $options = $args['options'] ?? '';
    
    // Handle query parameters like ?right=1000167 or ?left=12345
    $queryParams = $request->getQueryParams();
    if (!empty($queryParams)) {
        // Build the redirect URL with options parameter
        $json_options = [];
        if ($options) {
            $json_options = json_decode(urldecode($options), true) ?: [];
        }
        
        $redirect = false;
        if (isset($queryParams['left'])) {
            $entity = $queryParams['left'];
            if (!isset($json_options['A'])) {
                $json_options['A'] = array();
            }
            if (isset($json_options['B']) && ($key = array_search($entity, $json_options['B'])) !== false) {
                unset($json_options['B'][$key]);
            }
            if (!in_array($entity, $json_options['A'])) {
                $json_options['A'][] = $entity;
            }
            $redirect = true;
        }
        if (isset($queryParams['right'])) {
            $entity = $queryParams['right'];
            if (!isset($json_options['B'])) {
                $json_options['B'] = array();
            }
            if (isset($json_options['A']) && ($key = array_search($entity, $json_options['A'])) !== false) {
                unset($json_options['A'][$key]);
            }
            if (!in_array($entity, $json_options['B'])) {
                $json_options['B'][] = $entity;
            }
            $redirect = true;
        }
        
        if ($redirect) {
            $json = urlencode(json_encode($json_options));
            $url = "/related/$system/$time/o/$json/";
            return $response->withHeader('Location', $url)->withStatus(302);
        }
    }
    
    try {
        $mc = RelatedReport::generateReport($system, $time, $options, $battleID, null);
        if (@$mc['complete'] !== true) {
			return $container->get('view')->render($response->withStatus(202), 'related_wait.html', ['showAds' => false]);
        }

		return $container->get('view')->render($response, 'related.html', $mc);
    } catch (\InvalidArgumentException $ex) {
        // Invalid time format - redirect to rounded time
        $roundedTime = substr($time, 0, strlen("$time") - 2) . "00";
        return $response->withHeader('Location', "/related/$system/$roundedTime/")->withStatus(302);
    } catch (\RuntimeException $ex) {
        // System reinforced or queue busy
        $systemID = (int) $system;
        $unixTime = strtotime($time);
        if ($ex->getMessage() === "System is reinforced") {
            return $container->get('view')->render($response->withStatus(202), 'related_reinforced.html', ['showAds' => false]);
        } else if (str_contains($ex->getMessage(), "Queue is too busy")) {
            return $container->get('view')->render($response->withStatus(202), 'related_notnow.html', ['showAds' => false, 'solarSystemID' => $systemID, 'unixtime' => $unixTime]);
        } else {
            return $container->get('view')->render($response->withStatus(202), 'related_wait.html', ['showAds' => false]);
        }
    } catch (Exception $ex) {
        return $container->get('view')->render($response, 'related_wait.html', ['showAds' => false]);
    }
}
