<?php

require_once "../init.php";
$agents = [];
$qServer = new RedisQueue("queueServer");

while (!Util::exitNow())
{
	$row = $qServer->pop();
	if ($row != null)
	{
		$agent = strtolower(@$row["HTTP_USER_AGENT"]);
		if (!isBot($agent))
		{
			if (isset($row["REQUEST_URI"]))
			{
				$uri = $row["REQUEST_URI"];
				$key = "cache:$uri";
				if (Util::startsWith($uri, "/kill/") || $uri == "/")
				{
					if (!$redis->exists($key))
					{
						$contents = @file_get_contents("http://zkillboard.com{$uri}");
						if ($contents != "")
						{
							$redis->set($key, $contents);
							$redis->setTimeout($key, 300);
						}
					}

				}
			}
		}
	}
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
