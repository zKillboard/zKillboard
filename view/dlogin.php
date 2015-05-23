<?php
$loggedIn = (isset($_SESSION["loggedin"]) ? $_SESSION["loggedin"] : false);


if(!empty($loggedIn))
{
    $app->render("dlogin.html", array("close" => true));
}

if($_POST)
{
    $username = Util::getPost("username");
    $password = Util::getPost("password");
    $autologin = Util::getPost("autologin");

    if(!$username)
    {
        $error = "No username given";
        $app->render("dlogin.html", array("error" => $error));
    }
    elseif(!$password)
    {
        $error = "No password given";
        $app->render("dlogin.html", array("error" => $error));
    }
    elseif($username && $password)
    {
        $check = User::checkLogin($username, $password);
        if($check) // Success
        {
            $bool = User::setLogin($username, $password, $autologin);
            $app->render("dlogin.html", array("close" => $bool));
        }
        else
        {
            $error = "No such user exists, try again";
            $app->render("dlogin.html", array("error" => $error));
        }
    }
}
else $app->render("dlogin.html");
