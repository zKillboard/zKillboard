<?php

class EveOnlineSSO
{
    private static $defaultScopes = ['esi-killmails.read_killmails.v1', 'esi-killmails.read_corporation_killmails.v1', 'esi-fittings.write_fittings.v1'];

    public static function getSSO($scopes = null)
    {
        global $ccpCallback, $ccpClientID, $ccpSecret;

        if ($scopes === null) $scopes = self::$defaultScopes;

        return new EveOnlineSSO($ccpClientID, $ccpSecret, $ccpCallback, $scopes, "zkillboard.com (Squizz Caphinator)");
    }


    protected $clientID;
    protected $secretKey;
    protected $callbackURL;
    protected $scopes;
    protected $state;
    protected $userAgent;
    protected $curlHandle;

    protected $loginURL = "https://login.eveonline.com/v2/oauth/authorize";
    protected $tokenURL = "https://login.eveonline.com/v2/oauth/token";
    protected $verifyURL = "https://login.eveonline.com/v2/oauth/verify";

    public function __construct($clientID, $secretKey, $callbackURL, $scopes = [], $userAgent = null)
    {
        $this->clientID = $clientID;
        $this->secretKey = $secretKey;
        $this->callbackURL = $callbackURL;
        $this->scopes = $scopes;
        $this->userAgent = ($userAgent === null ? $callbackURL : $userAgent);
    }

    public function createState()
    {
        $factory = new \RandomLib\Factory;
        $generator = $factory->getGenerator(new \SecurityLib\Strength(\SecurityLib\Strength::MEDIUM));
        $state = $generator->generateString(128, "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ");

        return $state;
    }

    public function getState()
    {
        return $this->state;
    }

    /*
        Allows the developer to set their own state if they aren't happy with the
        state created by RandomLib.
    */
    public function setState($state)
    {
        $this->state = $state;
    }

    public function getLoginURL(&$session)
    {
        $state = ($this->state === null) ? $this->createState() : $this->state;
        $this->state = $state;
        $this->setSessionState($state, $session);
        session_write_close();

        $fields = [
            "response_type" => "code", 
            "client_id" => $this->clientID,
            "redirect_uri" => $this->callbackURL, 
            "scope" => implode(' ', $this->scopes),
            "state" => $state
        ];
        $params = $this->buildParams($fields);

        $url = $this->loginURL . "?" . $params;
        return $url;
    }

    protected function setSessionState($state, &$session)
    {
        $class = is_array($session) ? "Array" : get_class($session);
        switch ($class) {
            case "Array":
                $session["oauth2State"] = $state;
                break;
            case "Nette\Http\SessionSection":
                $session->oauth2State = $state;
                break;
            case "Aura\Session\Segment":
                $session->set("oauth2State", $state);
                break;
            default:
                throw new \Exception("Unknown session type");
        }
    }

    protected function getSessionState($session)
    {
        if (isset($_SESSION['oauth2State'])) return $_SESSION['oauth2State'];
        $class = is_array($session) ? "Array" : get_class($session);
        switch ($class) {
            case "Array":
                return @$session["oauth2State"];
            case "Nette\Http\SessionSection":
                return $session->oauth2State;
            case "Aura\Session\Segment":
                return $session->get("oauth2State");
            default:
                throw new \Exception("Unknown session type");
        }
    }

    protected function validateStates($state, $oauth2State)
    {
        if (!is_string($state) || $state === '' || !is_string($oauth2State) || $oauth2State === '' || !hash_equals($oauth2State, $state)) {
            throw new \Exception("Invalid state returned - possible hijacking attempt", -99);
        }
    }

    public function handleCallback($code, $state, $session)
    {
        global $ip, $resCode;

        $oauth2State = $this->getSessionState($session);
        $this->validateStates($state, $oauth2State);

        $fields = ['grant_type' => 'authorization_code', 'code' => $code];
        $tokenString = $this->doCall($this->tokenURL, $fields, null, 'POST');
        if ($resCode < 200 || $resCode >= 300) {
            throw new \Exception("EVE SSO token request failed");
        }

        try {
            $tokenJson = json_decode($tokenString, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \Exception("Invalid response from EVE SSO", 0, $e);
        }

        $accessToken = $tokenJson['access_token'] ?? null;
        $refreshToken = $tokenJson['refresh_token'] ?? null;
        if (!is_string($accessToken) || $accessToken === '' || !is_string($refreshToken) || $refreshToken === '') {
            throw new \Exception("Invalid token response from EVE SSO");
        }

        $decoded = $this->validateAccessToken($accessToken);

        $retValue = [
            'characterID' => $decoded['characterID'],
            'characterName' => $decoded['name'],
            'scopes' => implode(' ', $decoded['scp']),
            'tokenType' => 'Character',
            'ownerHash' => $decoded['owner'] ?? null,
            'refreshToken' => $refreshToken,
            'accessToken' => $accessToken,
        ];

        return $retValue;
    }

    public function validateAccessToken($accessToken)
    {
        global $resCode;

        if (!is_string($accessToken) || $accessToken === '' || substr_count($accessToken, '.') !== 2) {
            throw new \Exception("Invalid JWT returned by EVE SSO");
        }

        $verifyString = $this->doCall($this->verifyURL, [], $accessToken);
        if ($resCode < 200 || $resCode >= 300) {
            throw new \Exception("EVE SSO rejected the JWT");
        }

        try {
            $verified = json_decode($verifyString, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \Exception("Invalid JWT verification response from EVE SSO", 0, $e);
        }
        if (!is_array($verified)) {
            throw new \Exception("Invalid JWT verification response from EVE SSO");
        }

        $parts = explode('.', $accessToken);
        if ($parts[0] === '' || $parts[1] === '' || $parts[2] === '' || preg_match('/[^A-Za-z0-9_-]/', $parts[1])) {
            throw new \Exception("Invalid JWT returned by EVE SSO");
        }

        $payload = strtr($parts[1], '-_', '+/');
        $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
        $payload = base64_decode($payload, true);
        if ($payload === false) {
            throw new \Exception("Invalid JWT returned by EVE SSO");
        }

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \Exception("Invalid JWT returned by EVE SSO", 0, $e);
        }
        if (!is_array($decoded)) {
            throw new \Exception("Invalid JWT returned by EVE SSO");
        }

        $acceptedIssuers = ['https://login.eveonline.com', 'https://login.eveonline.com/', 'login.eveonline.com'];
        if (!isset($decoded['iss']) || !in_array($decoded['iss'], $acceptedIssuers, true)) {
            throw new \Exception("Invalid EVE SSO JWT issuer");
        }
        if (!isset($decoded['aud']) || !is_array($decoded['aud']) || !in_array('EVE Online', $decoded['aud'], true) || !in_array($this->clientID, $decoded['aud'], true)) {
            throw new \Exception("Invalid EVE SSO JWT audience");
        }

        $now = time();
        if (!isset($decoded['exp']) || !is_int($decoded['exp']) || $decoded['exp'] < $now - 60) {
            throw new \Exception("Expired EVE SSO JWT");
        }
        if (isset($decoded['nbf']) && (!is_int($decoded['nbf']) || $decoded['nbf'] > $now + 60)) {
            throw new \Exception("EVE SSO JWT is not valid yet");
        }

        if (!isset($decoded['sub']) || !is_string($decoded['sub']) || !preg_match('/^CHARACTER:EVE:([1-9][0-9]*)$/', $decoded['sub'], $matches)) {
            throw new \Exception("Invalid EVE SSO JWT subject");
        }
        $characterID = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($characterID === false || !isset($decoded['name']) || !is_string($decoded['name']) || $decoded['name'] === '') {
            throw new \Exception("Invalid EVE SSO JWT character");
        }

        if (!isset($decoded['scp'])) {
            $decoded['scp'] = ['publicData'];
        }
        if (is_string($decoded['scp'])) {
            $decoded['scp'] = [$decoded['scp']];
        }
        if (!is_array($decoded['scp'])) {
            throw new \Exception("Invalid EVE SSO JWT scopes");
        }
        foreach ($decoded['scp'] as $scope) {
            if (!is_string($scope) || $scope === '') {
                throw new \Exception("Invalid EVE SSO JWT scopes");
            }
        }

        $decoded['characterID'] = $characterID;
        return $decoded;
	}


    public function getAccessToken($refreshToken, $scopes = [])
    {
        $fields = ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken];
        $accessString = $this->doCall($this->tokenURL, $fields, null, 'POST');
        $accessJson = json_decode($accessString, true);
        return $accessJson;
    }

    public function doCall($url, $fields = [], $accessToken = null, $callType = 'GET', $headers = [])
    {
        $statusType = self::getType($url);

        $callType = strtoupper($callType);
        $header = $accessToken !== null ? 'Authorization: Bearer ' . $accessToken : 'Authorization: Basic ' . base64_encode($this->clientID . ':' . $this->secretKey);
        $headers[] = $header;

        $url = $callType != 'GET' ? $url : $url . $this->buildParams($fields);

        if ($this->curlHandle == null) $this->curlHandle = curl_init();
        else curl_reset($this->curlHandle);
        $ch = $this->curlHandle;
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        switch ($callType) {
            case 'DELETE':
            case 'PUT':
            case 'POST_JSON':
                $headers[] = "Content-Type: application/json";
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(empty($fields) ? (object) NULL : $fields, JSON_UNESCAPED_SLASHES));
                $callType = $callType == 'POST_JSON' ? 'POST' : $callType;
                break;
            case 'POST':
                curl_setopt($ch, CURLOPT_POSTFIELDS, $this->buildParams($fields));
                break;
        }
        global $resHeaders, $resCode;
        $resHeaders = [];

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $callType);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        // capture headers line-by-line into global $resHeaders
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $headerLine) {
                global $resHeaders;
                $len = strlen($headerLine);
                $header = explode(":", $headerLine, 2);

                if (count($header) == 2) {
                $name  = strtolower(trim($header[0]));
                $value = trim($header[1]);
                $resHeaders[$name] = $value;
                }
                return $len;
                });

        $result = curl_exec($ch);
        $resCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        Status::addEsiStatus($url, $resCode, $resHeaders);

        if (curl_errno($ch) !== 0) {
            Status::addStatus($statusType, false);
            throw new \Exception(curl_error($ch), curl_errno($ch));
        }

        Status::addStatus($statusType, true);
        return $result;

    }

    protected function buildParams($fields)
    {
        if ($fields == null || sizeof($fields) == 0) return "";
        $string = "?";
        foreach ($fields as $field=>$value) {
            $string .= $string == "" ? "" : "&";
            $string .= "$field=" . rawurlencode($value);
        }
        return $string;
    }

    public function getType($uri)
    {
        if (strpos($uri, 'esi.evetech') !== false) return 'esi';
        if (strpos($uri, 'esi.tech') !== false) return 'esi';
        if (strpos($uri, 'login') !== false) return 'sso';
        if (strpos($uri, 'evewho') !== false) return 'evewho';
        Util::zout("Unknown type for $uri");
        return 'unknown';
    }
}
