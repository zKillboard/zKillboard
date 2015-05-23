<?php

$password = Util::getPost("password");
$password2 = Util::getPost("password2");
if($password && $password2)
{
    $message = "";
    $messagetype = "";
    $password = Util::getPost("password");
    $password2 = Util::getPost("password2");

    if(!$password || !$password2)
    {
        $message = "Password missing, try again..";
        $messagetype = "error";
    }
    elseif($password != $password2)
    {
        $message = "Password mismatch, try again..";
        $messagetype = "error";
    }
    elseif($password == $password2)
    {
        $password = Password::genPassword($password);
        Db::execute("UPDATE zz_users SET password = :password WHERE change_hash = :hash", array(":password" => $password, ":hash" => $hash));
        Db::execute("UPDATE zz_users SET change_hash = NULL, change_expiration = NULL WHERE change_hash = :hash", array(":hash" => $hash));
        $message = "Password updated, click login, and login with your new password";
        $messagetype = "success";
    }
    $app->render("changepassword.html" , array("message" => $message, "messagetype" => $messagetype));
}

else
{
	$date = date("Y-m-d H:i:s");
	$allowed = Db::queryField("SELECT change_expiration FROM zz_users WHERE change_hash = :hash", "change_expiration", array(":hash" => $hash));
	if(isset($allowed) && ($allowed > $date))
	{
		$foruser = Db::queryField("SELECT email FROM zz_users WHERE change_hash = :hash", "email", array(":hash" => $hash));
		$app->render("changepassword.html", array("email" => $foruser, "hash" => $hash));
	}
	else
	{
		$message = "Either your password change hash doesn't exist, or it has expired";
		$messagetype = "error";
		$app->render("changepassword.html", array("message" => $message, "messagetype" => $messagetype));
	}
}
