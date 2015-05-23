<?php

require_once "../init.php";
$agents = [];

while (!Util::exitNow())
{
	$queueServer = $mdb->find("queueServer");
	foreach ($queueServer as $row)
	{
		$agent = strtolower(@$row["HTTP_USER_AGENT"]);
		if (!isBot($agent))
		{
			if (isset($row["REQUEST_URI"]))
			{
				$uri = $row["REQUEST_URI"];
				if (Util::startsWith($uri, "/kill/") || $uri == "/")
				{
					if (!$mdb->exists("htmlCache", ['uri' => $uri]))
					{
						$contents = @file_get_contents("https://zkillboard.com{$uri}");
						if ($contents != "")
						{
							$mdb->save("htmlCache", ['uri' => $uri, 'dttm' => $mdb->now(), 'contents' => $contents]);
							//echo strlen($contents) . " $uri\n";
						}
					}

				}
			}
		}
		$mdb->remove("queueServer", $row);
	}
	usleep(100000);
}

function isBot($agent)
{
	if (strpos($agent, "chrome") !== false) return false;
	if (strpos($agent, "chrome") !== false) return false;
	if (strpos($agent, "eve-igb") !== false) return false;

	if ($agent == "") return true;
	if (strpos($agent, "bot") !== false) return true;
	if (strpos($agent, "curl") !== false) return true;
	if (strpos($agent, "evekb") !== false) return true;
	if (strpos($agent, "ltx71") !== false) return true;
	if (strpos($agent, "slurp") !== false) return true;
	if (strpos($agent, "www.admantx.com") !== false) return true;
	if (strpos($agent, "spider") !== false) return true;
	if (strpos($agent, "disqus") !== false) return true;
	if (strpos($agent, "dotlan") !== false) return true;
	if (strpos($agent, "crawler") !== false) return true;
	if (strpos($agent, "googledocs") !== false) return true;
	if (strpos($agent, "mediapartners-google") !== false) return true;
	return false;
}
