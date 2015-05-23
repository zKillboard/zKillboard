<?php

if (User::isLoggedIn()) {
        $app->redirect("/", 302);
        die();
}

$referer = @$_SERVER["HTTP_REFERER"];
if($_POST)
{
    $username = Util::getPost("username");
    $password = Util::getPost("password");
    $autologin = Util::getPost("autologin");
    $requesturi = Util::getPost("requesturi");

    if(!$username)
    {
        $error = "No username given";
        $app->render("login.html", array("error" => $error));
    }
    elseif(!$password)
    {
        $error = "No password given";
        $app->render("login.html", array("error" => $error));
    }
    elseif($username && $password)
    {
        $check = User::checkLogin($username, $password);
        if($check) // Success
        {
            User::setLogin($username, $password, $autologin);
			$ignoreUris = array("/register/", "/login/", "/logout/");
            if (isset($requesturi) && !in_array($requesturi, $ignoreUris)) {
				$app->redirect($requesturi);
            }
			else
			{
				$app->redirect("/");
			}
        }
        else
        {
            $error = "No such user exists, try again";
            $app->render("login.html", array("error" => $error));
        }
    }
}
else $app->render("login.html", array("requesturi" => $referer));
