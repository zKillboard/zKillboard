<?php

use MongoDB\BSON\ObjectId;

global $mdb;

// Extract route parameters for compatibility
if (isset($GLOBALS['route_args'])) {
    $id = $GLOBALS['route_args']['id'] ?? '';
} else {
    // Legacy parameter passing still works
}

try {
    $record = $mdb->findDoc("shortener", ['_id' => new ObjectId($id)]);
    if ($record == null) {
        // Handle redirect for compatibility
        if (isset($GLOBALS['capture_render_data'])) {
            $GLOBALS['redirect_response'] = $GLOBALS['slim3_response']->withStatus(302)->withHeader('Location', '/');
            return;
        } else {
            $app->redirect('/', 302);
        }
    } else {
        // Handle redirect for compatibility
        if (isset($GLOBALS['capture_render_data'])) {
            $GLOBALS['redirect_response'] = $GLOBALS['slim3_response']->withStatus(302)->withHeader('Location', $record['url']);
            return;
        } else {
            $app->redirect($record['url'], 302);
        }
    }
} catch (Exception $e) {
    // Invalid ObjectId format, redirect to home
    if (isset($GLOBALS['capture_render_data'])) {
        $GLOBALS['redirect_response'] = $GLOBALS['slim3_response']->withStatus(302)->withHeader('Location', '/');
        return;
    } else {
        $app->redirect('/', 302);
    }
}
