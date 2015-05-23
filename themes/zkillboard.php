<?php
class zkillboard
{
	public static function availableStyles()
	{
		$json = json_decode(Util::getData("http://api.bootswatch.com/3/"));

		$available = array();
		foreach($json->themes as $theme)
			$available[] = strtolower($theme->name);

		$available[] = "default";
		return $available;
	}
}