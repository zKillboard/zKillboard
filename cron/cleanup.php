<?php

require_once "../init.php";

$m = date("i");
if ($m != 0) exit();

$stompmails = $mdb->find("stompmails");
foreach ($stompmails as $mail)
{
	$killID = (int) $mail["killID"];
	if ($mdb->exists("crestmails", ['killID' => $killID, 'processed' => true]))
	{
		$mdb->remove("stompmails", $mail);
	}
}

$apimails = $mdb->find("apimails");
foreach ($apimails as $mail)
{
        $killID = (int) $mail["killID"];
        if ($mdb->exists("crestmails", ['killID' => $killID, 'processed' => true]))
        {
		$mdb->remove("apimails", $mail);
	} 
	//else $mdb->set("crestmails", ['killID' => $killID], ['processed' => false]);
}
