<?php 

require_once "../init.php";

$killID = (int) @$argv[1];
if ($killID > 0) Killmail::deleteKillmail($killID);
echo "$killID has been reset\n";
