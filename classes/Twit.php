<?php

class Twit
{
    /**
     * @param string $message
     */
    public static function sendMessage($message)
    {
        global $consumerKey, $consumerSecret, $accessToken, $accessTokenSecret;
        $twitter = new Twitter($consumerKey, $consumerSecret, $accessToken, $accessTokenSecret);

        return $twitter->send($message);
    }

    public static function getMessages($amount = 1)
    {
        global $consumerKey, $consumerSecret, $accessToken, $accessTokenSecret;
        $twitter = new Twitter($consumerKey, $consumerSecret, $accessToken, $accessTokenSecret);

        return $twitter->load(Twitter::REPLIES, $amount);
    }

    public static function findMessages($amount = 1)
    {
        global $consumerKey, $consumerSecret, $accessToken, $accessTokenSecret;
        $twitter = new Twitter($consumerKey, $consumerSecret, $accessToken, $accessTokenSecret);

        return $twitter->load(Twitter::ME_AND_FRIENDS, $amount);
    }

    public static function shortenUrl($url)
    {
        return file_get_contents('http://is.gd/api.php?longurl='.$url);
    }
}
