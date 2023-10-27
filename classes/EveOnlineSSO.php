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

    protected $loginURL = "https://login.eveonline.com/v2/oauth/authorize";
    protected $tokenURL = "https://login.eveonline.com/v2/oauth/token";

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
        if ($oauth2State !== $state) {
            throw new \Exception("Invalid state returned - possible hijacking attempt");
        }
    }

    public function handleCallback($code, $state, $session)
    {
        $oauth2State = $this->getSessionState($session);
        $this->validateStates($state, $oauth2State);

        $fields = ['grant_type' => 'authorization_code', 'code' => $code];
        $tokenString = $this->doCall($this->tokenURL, $fields, null, 'POST');
        $tokenJson = json_decode($tokenString, true);
        $accessToken = $tokenJson['access_token'];
        $decoded = json_decode(base64_decode(str_replace('_', '/', str_replace('-', '+', explode('.', $accessToken)[1]))), true);

        $accessToken = @$tokenJson['access_token'];
        $refreshToken = @$tokenJson['refresh_token'];

        if (!isset($decoded['scp'])) $decoded['scp'] = 'publicData';
        if (!is_array($decoded['scp'])) $decoded['scp'] = [$decoded['scp']];

        $retValue = [
            'characterID' => str_replace("CHARACTER:EVE:", "", $decoded['sub']),
            'characterName' => $decoded['name'],
            'scopes' => implode(' ', $decoded['scp']),
            'tokenType' => 'Character',
            'ownerHash' => $decoded['owner'],
            'refreshToken' => $refreshToken,
            'accessToken' => $accessToken,
        ];

        return $retValue;
    }

    public function getAccessToken($refreshToken, $scopes = [])
    {
        $fields = ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken];
        $accessString = $this->doCall($this->tokenURL, $fields, null, 'POST', true);
        $accessJson = json_decode($accessString, true);
        return $accessJson;
    }

    public function doCall($url, $fields = [], $accessToken = null, $callType = 'GET')
    {
        $statusType = self::getType($url);


        $callType = strtoupper($callType);
        $header = $accessToken !== null ? 'Authorization: Bearer ' . $accessToken : 'Authorization: Basic ' . base64_encode($this->clientID . ':' . $this->secretKey);
        $headers = [$header];

        $url = $callType != 'GET' ? $url : $url . $this->buildParams($fields);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

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
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $callType);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	curl_setopt($ch, CURLOPT_FORBID_REUSE, true);
	curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 );

        $result = curl_exec($ch);

        if (curl_errno($ch) !== 0) {
            Status::addStatus($statusType, false);
            throw new \Exception(curl_error($ch), curl_errno($ch));
        }
	curl_close($ch);

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
        Log::log("Unknown type for $uri");
        return 'unknown';
    }
}
