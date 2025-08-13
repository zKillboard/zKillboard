#!/usr/bin/env php
<?php

require_once "../init.php";

while (($line = fgets(STDIN)) !== false) {
    Util::out($line, "errlog");
}

