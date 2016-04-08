<?php

function auth_error($error)
{
	global $app;

	$app->render("error.html", ['message' => $error]);
	exit();
}

CrestSSO::callback();

die();
