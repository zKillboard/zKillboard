<?php

try {
    $result = ESI::saveFitting($killID);
    echo "CCP's Response: ".@$result['message'];
    if (isset($result['refid'])) {
        echo '<br/>refID: '.$result['refid'];
    }
} catch (Exception $ex) {
    echo 'Great Scott! An unexpected error occurred: '.$ex->getMessage();
}
