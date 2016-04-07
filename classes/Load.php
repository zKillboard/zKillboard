<?php

class Load
{
	public static function getLoad()
	{
		$output = array();
		$result = exec('cat /proc/loadavg', $output);

		$split = explode(' ', $result);
		$load = $split[0];

		return $load;
	}
}
