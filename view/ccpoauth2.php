<?php

$sso = EveOnlineSSO::getSSO();
$app->redirect($sso->getLoginURL($_SESSION), 302);
