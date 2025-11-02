<?php

// Extract route parameters for compatibility
if (isset($GLOBALS['route_args'])) {
    $killID = $GLOBALS['route_args']['killID'] ?? 0;
} else {
    // Legacy parameter passing still works
}

try {
    $result = ESI::saveFitting($killID);
    echo "CCP's Response: ".@$result['message'];
    if (isset($result['refid'])) {
        echo '<br/>refID: '.$result['refid'];
    }
} catch (Exception $ex) {
    echo 'Great Scott! An unexpected error occurred: '.$ex->getMessage();
}
