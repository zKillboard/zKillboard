<?php

try {
	$result = CrestFittings::saveFitting($killID);
	echo "CCP's Response: " . $result['message'];
	die();
} catch (Exception $ex) { print_r($ex); }
