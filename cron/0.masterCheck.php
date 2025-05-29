<?php

require_once "../init.php";

if ($mdb->isMaster()) touch("isMaster.lock");
else unlink("isMaster.lock");
