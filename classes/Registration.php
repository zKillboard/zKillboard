<?php

class Registration
{
    public static function checkRegistration($email, $username)
    {
        $check = Db::query('SELECT username, email FROM zz_users WHERE email = :email OR username = :username', array(':email' => $email, ':username' => $username), 0);

        return $check;
    }

    public static function registerUser($username, $password, $email)
    {
        global $baseAddr;

        if (strtolower($username) == 'evekill' || strtolower($username) == 'eve-kill') {
            return array('type' => 'error', 'message' => 'Restrictd user name');
        }

        $check = Db::queryField('SELECT count(*) count FROM zz_users WHERE email = :email OR username = :username', 'count', array(':email' => $email, ':username' => $username), 0);
        if ($check == 0) {
            $hashedpassword = Password::genPassword($password);
            Db::execute('INSERT INTO zz_users (username, password, email) VALUES (:username, :password, :email)', array(':username' => $username, ':password' => $hashedpassword, ':email' => $email));
            $subject = "$baseAddr Registration";
            $message = "Thank you, $username, for registering at $baseAddr";
            //Email::send($email, $subject, $message);
            $message = 'You have been registered!';

            return array('type' => 'success', 'message' => $message);
        } else {
            $message = 'Username / email is already registered';

            return array('type' => 'error', 'message' => $message);
        }
    }
}
