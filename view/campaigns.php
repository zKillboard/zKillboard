<?php

die("We haven't finished the campaign code yet - please come back later (and bring some beer)");

switch($type)
{
	case "all": // All campaigns.
		$data = Campaigns::getAllCampaigns();
	break;
}

$app->render("campaigns.html", array("data" => $data));
