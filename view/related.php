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
        if (is_array($mc)) {
            return $container->view->render($response, 'related.html', $mc);
        } else {
            return $container->view->render($response, 'related_wait.html', ['showAds' => false]);
        }
    } catch (Exception $ex) {
        return $container->view->render($response, 'related_wait.html', ['showAds' => false]);
    }
}
