<?php

namespace SocialAuther\Bridge;

use OrbisTools\Exception;
use OrbisTools\Request;
use OrbisTools\Session;
use TwitterAuth\TwitterAuth;

class TwitterAuthSA
{
    const SESSION_VAR_PREFIX = 'TwitterAuthSA';
    const VAR_OAUTH_TOKEN = 'oauth_token';
    const VAR_OAUTH_TOKEN_SECRET = 'oauth_token_secret';
    const VAR_OAUTH_VERIFIER = 'oauth_verifier';
    const VAR_OAUTH_ACCESS_TOKEN = 'oauth_access_token';


    protected $consumerKey;

    protected $consumerSecret;

    protected $oauthCallback;

    /**
     * @var Request;
     */
    protected $request;

    /**
     * @var \TwitterAuth\TwitterAuth
     */
    protected $toolGlobal;

    /**
     * @var \TwitterAuth\TwitterAuth
     */
    protected $toolsLocal = [];

    /**
     * @var Session
     */
    protected $session;

    protected $userAccessToken;

    function __construct($consumerKey, $consumerSecret, $oauthCallback, Session $session = null)
    {
        $this->consumerKey = $consumerKey;
        $this->consumerSecret = $consumerSecret;
        $this->oauthCallback = $oauthCallback;
        $this->session = $session;

        if (!$this->isSessionStart()) {
            $this->session->start();
            if (!$this->isSessionStart()) {
                throw new Exception('Session not started');
            }
        }
    }

    public function getRequest()
    {
        if (is_null($this->request)) {
            $this->request = new Request();
        }
        return $this->request;
    }

    /**
     * @return \OrbisTools\Session
     */
    public function getSession()
    {
        if (is_null($this->session)) {
            $this->session = new Session();
        }
        return $this->session;
    }

    public function isSessionStart()
    {
        return $this->getSession()->status() === PHP_SESSION_ACTIVE;
    }

    public function getToolGlobal()
    {
        if (is_null($this->toolGlobal)) {
            $this->toolGlobal = new TwitterAuth($this->consumerKey, $this->consumerSecret);
        }
        return $this->toolGlobal;
    }

    /**
     * @param $oauthToken
     * @param $oauthTokenSecret
     * @return TwitterAuth
     */
    public function getToolLocal($oauthToken, $oauthTokenSecret)
    {
        $key = $oauthToken . $oauthTokenSecret;
        if (!isset($this->toolsLocal[$key])) {
            $this->toolsLocal[$key] = new TwitterAuth($this->consumerKey, $this->consumerSecret, $oauthToken, $oauthTokenSecret);
        }
        return $this->toolsLocal[$key];
    }

    protected function sessionVarName($name)
    {
        return self::SESSION_VAR_PREFIX .'__' . $name;
    }

    public function sessionSet($name, $value)
    {
        $name = $this->sessionVarName($name);
        return $this->getSession()->set($name, $value);
    }

    public function sessionGet($name, $default = null)
    {
        $name = $this->sessionVarName($name);
        return $this->getSession()->get($name);
    }

    public function sessionUnset($name)
    {
        $name  = $this->sessionVarName($name);
        return $this->getSession()->rm($name);
    }

    public function sessionHas($name)
    {
        $name = $this->sessionVarName($name);
        return $this->getSession()->has($name);
    }

    public function getAuthUrl()
    {
        $tool = $this->getToolGlobal();
        /* Get temporary credentials. */
        $request_token = $tool->getRequestToken($this->oauthCallback);

        if ($tool->http_code !== 200) {
            return null;
        }

        /* Save temporary credentials to session. */
        $token = $request_token[self::VAR_OAUTH_TOKEN];
        $token_secret = $request_token[self::VAR_OAUTH_TOKEN_SECRET];

        $this->sessionSet(self::VAR_OAUTH_TOKEN, $token);
        $this->sessionSet(self::VAR_OAUTH_TOKEN_SECRET, $token_secret);

        $url = $tool->getAuthorizeURL($token);
        return $url;
    }

    public function checkAuthToken()
    {
        $oauthToken = $this->getRequest()->getParam(self::VAR_OAUTH_TOKEN);
        if (is_null($oauthToken)) {
            return false;
        }
        $oauthTokenSession = $this->sessionGet(self::VAR_OAUTH_TOKEN);
        if (is_null($oauthTokenSession)) {
            return false;
        }
        return $oauthToken === $oauthTokenSession;
    }

    protected function objToArray($obj){
        $rc = (array)$obj;
        foreach($rc as $key => &$field){
            if(is_object($field))$field = $this->objToArray($field);
        }
        if (!empty($this->userAccessToken)) {
            $rc['token'] = $this->userAccessToken;
        }
        return $rc;
    }

    public function authenticate()
    {
        $oauthToken = $this->sessionGet(self::VAR_OAUTH_TOKEN);
        $oauthTokenSecret = $this->sessionGet(self::VAR_OAUTH_TOKEN_SECRET);
        $tool = $this->getToolLocal($oauthToken, $oauthTokenSecret);
        $oauthVerifier = $this->getRequest()->getVar(self::VAR_OAUTH_VERIFIER);

        $this->userAccessToken = $tool->getAccessToken($oauthVerifier);
        $this->sessionSet(self::VAR_OAUTH_ACCESS_TOKEN, $this->userAccessToken);
        $this->sessionUnset(self::VAR_OAUTH_TOKEN);
        $this->sessionUnset(self::VAR_OAUTH_TOKEN_SECRET);
        if ($tool->http_code !== 200) {
            return false;
        }
        $content = $tool->get('account/verify_credentials');

        return (empty($content)) ? false : $this->objToArray($content);
    }


}