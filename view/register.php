<?php

if ($_POST) {
    $username = Util::getPost('username');
    $password = Util::getPost('password');
    $password2 = Util::getPost('password2');
    $email = Util::getPost('email');

    if (isset($_POST['username'])) {
        $username = $_POST['username'];
    }
    if (isset($_POST['password'])) {
        $password = $_POST['password'];
    }
    if (isset($_POST['password2'])) {
        $password2 = $_POST['password2'];
    }
    if (isset($_POST['email'])) {
        $email = $_POST['email'];
    }

    if (!$password || !$password2) {
        $error = 'Missing password, please retry';
        $app->render('register.html', array('error' => $error));
    } elseif (!$email) {
        $error = 'Missing email, please retry';
        $app->render('register.html', array('error' => $error));
    } elseif ($password != $password2) {
        $error = "Passwords don't match, please retry";
        $app->render('register.html', array('error' => $error));
    } elseif (!$username) {
        $error = 'Missing username, please retry';
        $app->render('register.html', array('error' => $error));
    } elseif ($username && $email && ($password == $password2)) {
        // woohoo

        // Lets check if the user isn't already registered
        if (Registration::checkRegistration($username, $email) == null) {
            // He hasn't already registered, lets do et!

            $message = Registration::registerUser($username, $password, $email);
            $app->render('register.html', array('type' => $message['type'], 'message' => $message['message']));
        }
    }
} else {
    $app->render('register.html');
}
