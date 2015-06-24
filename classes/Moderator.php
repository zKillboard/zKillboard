<?php

/**
 * Various Moderator Actions.
 */
class Moderator
{
    /**
     * Gets the User info.
     *
     * @static
     *
     * @param $userID the userid of the user to query
     *
     * @return The array with the userinfo in it
     */
    public static function getUserInfo($userID)
    {
        if (!User::isModerator() and !User::isAdmin()) {
            throw new Exception('Invalid Access!');
        }
        $info = Db::query('SELECT * FROM zz_users WHERE id = :id', array(':id' => $userID), 0); // should this be star
        return $info;
    }

    public static function getUsers($page)
    {
        if (!User::isModerator() and !User::isAdmin()) {
            throw new Exception('Invalid Access!');
        }
        $limit = 30;
        $offset = ($page - 1) * $limit;
        $users = Db::query("SELECT * FROM zz_users ORDER BY id LIMIT $offset, $limit", array(), 0);

        return $users;
    }
}
