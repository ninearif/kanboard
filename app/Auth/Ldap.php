<?php

namespace Auth;

use Event\AuthEvent;

/**
 * LDAP model
 *
 * @package  auth
 * @author   Frederic Guillot
 */
class Ldap extends Base
{
    /**
     * Backend name
     *
     * @var string
     */
    const AUTH_NAME = 'LDAP';

    /**
     * Authenticate the user
     *
     * @access public
     * @param  string  $username  Username
     * @param  string  $password  Password
     * @return boolean
     */
    public function authenticate($username, $password)
    {
        $username = LDAP_USERNAME_CASE_SENSITIVE ? $username : strtolower($username);
        $result = $this->findUser($username, $password);

        if (is_array($result)) {

            $user = $this->user->getByUsername($username);

            if (! empty($user)) {

                // There is already a local user with that name
                if ($user['is_ldap_user'] == 0) {
                    return false;
                }
            }
            else {

                // We create automatically a new user
                if (LDAP_ACCOUNT_CREATION && $this->createUser($username, $result['name'], $result['email'])) {
                    $user = $this->user->getByUsername($username);
                }
                else {
                    return false;
                }
            }

            // We open the session
            $this->userSession->refresh($user);
            $this->container['dispatcher']->dispatch('auth.success', new AuthEvent(self::AUTH_NAME, $user['id']));

            return true;
        }

        return false;
    }

    /**
     * Create a new local user after the LDAP authentication
     *
     * @access public
     * @param  string  $username    Username
     * @param  string  $name        Name of the user
     * @param  string  $email       Email address
     * @return bool
     */
    public function createUser($username, $name, $email)
    {
        $values = array(
            'username' => $username,
            'name' => $name,
            'email' => $email,
            'is_admin' => 0,
            'is_ldap_user' => 1,
        );

        return $this->user->create($values);
    }

    /**
     * Find the user from the LDAP server
     *
     * @access public
     * @param  string  $username  Username
     * @param  string  $password  Password
     * @return boolean|array
     */
    public function findUser($username, $password)
    {
        $ldap = $this->connect();

        if ($ldap !== false && $this->bind($ldap, $username, $password)) {
            return $this->search($ldap, $username, $password);
        }

        return false;
    }

    /**
     * LDAP connection
     *
     * @access public
     * @return resource|boolean
     */
    public function connect()
    {
        if (! function_exists('ldap_connect')) {
            $this->logger->error('The PHP LDAP extension is required');
            return false;
        }

        // Skip SSL certificate verification
        if (! LDAP_SSL_VERIFY) {
            putenv('LDAPTLS_REQCERT=never');
        }

        $ldap = ldap_connect(LDAP_SERVER, LDAP_PORT);

        if ($ldap === false) {
            $this->logger->error('Unable to connect to the LDAP server: "'.LDAP_SERVER.'"');
            return false;
        }
        ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($ldap, LDAP_OPT_NETWORK_TIMEOUT, 1);
        ldap_set_option($ldap, LDAP_OPT_TIMELIMIT, 1);

        if (LDAP_START_TLS && ! @ldap_start_tls($ldap)) {
            $this->logger->error('Unable to use ldap_start_tls()');
            return false;
        }

        return $ldap;
    }

    /**
     * LDAP bind
     *
     * @access public
     * @param  resource  $ldap
     * @param  string    $username
     * @param  string    $password
     * @param  string    $ldap_type
     * @param  string    $ldap_username
     * @param  string    $ldap_password
     * @return boolean
     */
    public function bind($ldap, $username, $password, $ldap_type = LDAP_BIND_TYPE, $ldap_username = LDAP_USERNAME, $ldap_password = LDAP_PASSWORD)
    {
        if ($ldap_type === 'user') {
            $ldap_username = sprintf($ldap_username, $username);
            $ldap_password = $password;
        }
        else if ($ldap_type === 'proxy') {
            $ldap_username = $ldap_username;
            $ldap_password = $ldap_password;
        }
        else {
            $ldap_username = null;
            $ldap_password = null;
        }

        if (! @ldap_bind($ldap, $ldap_username, $ldap_password)) {
            return false;
        }

        return true;
    }

    /**
     * LDAP user lookup
     *
     * @access private
     * @param  resource  $ldap      LDAP connection
     * @param  string    $username  Username
     * @param  string    $password  Password
     * @return boolean|array
     */
    private function search($ldap, $username, $password)
    {
        $sr = @ldap_search($ldap, LDAP_ACCOUNT_BASE, sprintf(LDAP_USER_PATTERN, $username), array(LDAP_ACCOUNT_FULLNAME, LDAP_ACCOUNT_EMAIL));

        if ($sr === false) {
            return false;
        }

        $info = ldap_get_entries($ldap, $sr);

        // User not found
        if (count($info) === 0 || $info['count'] == 0) {
            return false;
        }

        // We got our user
        if (@ldap_bind($ldap, $info[0]['dn'], $password)) {
            return array(
                'username' => $username,
                'name' => $this->getFromInfo($info, LDAP_ACCOUNT_FULLNAME),
                'email' => $this->getFromInfo($info, LDAP_ACCOUNT_EMAIL),
            );
        }

        return false;
    }

    /**
     * Retrieve info on LDAP user
     *
     * @param string   $username  Username
     * @param string   $email     Email address
     */
    public function lookup($username = null, $email = null)
    {
        $query = $this->getQuery($username, $email);
        if ($query === false) {
            return false;
        }

        // Connect and attempt anonymous bind
        $ldap = $this->connect();
        if (! is_resource($ldap) || ! $this->bind($ldap, null, null)) {
            return false;
        }

        // Try to find user
        $sr = @ldap_search($ldap, LDAP_ACCOUNT_BASE, $query, array(LDAP_ACCOUNT_FULLNAME, LDAP_ACCOUNT_EMAIL, LDAP_ACCOUNT_ID));
        if ($sr === false) {
            return false;
        }

        $info = ldap_get_entries($ldap, $sr);

        // User not found
        if (count($info) == 0 || $info['count'] == 0) {
            return false;
        }

        // User id not retrieved: LDAP_ACCOUNT_ID not properly configured
        if (empty($username) && ! isset($info[0][LDAP_ACCOUNT_ID][0])) {
            return false;
        }

        return array(
            'username' => $this->getFromInfo($info, LDAP_ACCOUNT_ID, $username),
            'name' => $this->getFromInfo($info, LDAP_ACCOUNT_FULLNAME),
            'email' => $this->getFromInfo($info, LDAP_ACCOUNT_EMAIL, $email),
        );
    }

    /**
     * Get the LDAP query to find a user
     *
     * @param string   $username  Username
     * @param string   $email     Email address
     */
    private function getQuery($username, $email)
    {
        if ($username && $email) {
            return '(&('.sprintf(LDAP_USER_PATTERN, $username).')('.LDAP_ACCOUNT_EMAIL.'='.$email.'))';
        }
        else if ($username) {
            return sprintf(LDAP_USER_PATTERN, $username);
        }
        else if ($email) {
            return '('.LDAP_ACCOUNT_EMAIL.'='.$email.')';
        }
        else {
            return false;
        }
    }

    /**
     * Return a value from the LDAP info
     *
     * @param array    $info     LDAP info
     * @param string   $key      Key
     * @param string   $default  Default value if key not set in entry
     * @return string
     */
    private function getFromInfo($info, $key, $default = '')
    {
         return isset($info[0][$key][0]) ? $info[0][$key][0] : $default;
    }
}
