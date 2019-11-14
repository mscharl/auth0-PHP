<?php
/**
 * Main entry point to the Auth0 SDK
 *
 * @package Auth0\SDK
 */

namespace Auth0\SDK;

use Auth0\SDK\Exception\CoreException;
use Auth0\SDK\Exception\ApiException;
use Auth0\SDK\Exception\InvalidTokenException;
use Auth0\SDK\Helpers\Cache\CacheHandler;
use Auth0\SDK\Helpers\Cache\NoCacheHandler;
use Auth0\SDK\Helpers\JWKFetcher;
use Auth0\SDK\Helpers\Tokens\IdTokenVerifier;
use Auth0\SDK\Helpers\Tokens\AsymmetricVerifier;
use Auth0\SDK\Helpers\Tokens\SymmetricVerifier;
use Auth0\SDK\Store\CookieStore;
use Auth0\SDK\Store\EmptyStore;
use Auth0\SDK\Store\SessionStore;
use Auth0\SDK\Store\StoreInterface;
use Auth0\SDK\API\Authentication;
use Auth0\SDK\API\Helpers\State\StateHandler;
use Auth0\SDK\API\Helpers\State\SessionStateHandler;
use Auth0\SDK\API\Helpers\State\DummyStateHandler;

use GuzzleHttp\Exception\RequestException;

/**
 * Class Auth0
 * Provides access to Auth0 authentication functionality.
 *
 * @package Auth0\SDK
 */
class Auth0
{

    /**
     * Available keys to persist data.
     *
     * @var array
     */
    public $persistantMap = [
        'refresh_token',
        'access_token',
        'user',
        'id_token',
    ];

    /**
     * Auth0 URL Map (not currently used in the SDK)
     *
     * @var array
     */
    public static $URL_MAP = [
        'api'           => 'https://{domain}/api/',
        'authorize'     => 'https://{domain}/authorize/',
        'token'     => 'https://{domain}/oauth/token/',
        'user_info'     => 'https://{domain}/userinfo/',
    ];

    /**
     * Auth0 Domain, found in Application settings
     *
     * @var string
     */
    protected $domain;

    /**
     * Auth0 Client ID, found in Application settings
     *
     * @var string
     */
    protected $clientId;

    /**
     * Auth0 Client Secret, found in Application settings
     *
     * @var string
     */
    protected $clientSecret;

    /**
     * Response mode
     *
     * @var string
     */
    protected $responseMode;

    /**
     * Response type
     *
     * @var string
     */
    protected $responseType;

    /**
     * Audience for the API being used
     *
     * @var string
     */
    protected $audience;

    /**
     * Scope for ID tokens and /userinfo endpoint
     *
     * @var string
     */
    protected $scope = 'openid profile email';

    /**
     * Auth0 Refresh Token
     *
     * @var string
     */
    protected $refreshToken;

    /**
     * Redirect URI needed on OAuth2 requests, aka callback URL
     *
     * @var string
     */
    protected $redirectUri;

    /**
     * The access token retrieved after authorization.
     * NULL means that there is no authorization yet.
     *
     * @var string
     */
    protected $accessToken;

    /**
     * JWT for identity information
     *
     * @var string
     */
    protected $idToken;

    /**
     * Decoded version of the ID token
     *
     * @var array
     */
    protected $idTokenDecoded;

    /**
     * Storage engine for persistence
     *
     * @var StoreInterface
     */
    protected $store;

    /**
     * The user object provided by Auth0
     *
     * @var string
     */
    protected $user;

    /**
     * Authentication Client.
     *
     * @var \Auth0\SDK\API\Authentication
     */
    protected $authentication;

    /**
     * Configuration options for Guzzle HTTP client.
     *
     * @var array
     *
     * @see http://docs.guzzlephp.org/en/stable/request-options.html
     */
    protected $guzzleOptions;

    /**
     * Skip the /userinfo endpoint call and use the ID token.
     *
     * @var boolean
     */
    protected $skipUserinfo;

    /**
     * Algorithm used for ID token validation.
     * Can be "HS256" or "RS256" only.
     *
     * @var string
     */
    protected $idTokenAlg;

    /**
     * Leeway for ID token validation.
     *
     * @var integer
     */
    protected $idTokenLeeway;

    /**
     * State Handler.
     *
     * @var StateHandler
     */
    protected $stateHandler;

    /**
     * Maximum time allowed between authentication and ID token verification.
     *
     * @var integer
     */
    protected $maxAge;

    /**
     * Authorization storage used for state, nonce, and max_age.
     *
     * @var StoreInterface
     */
    protected $authStore;

    /**
     * Cache Handler.
     *
     * @var CacheHandler
     */
    protected $cacheHandler;

    /**
     * BaseAuth0 Constructor.
     *
     * @param array $config - Required configuration options.
     *     - domain                 (String)  Required. Auth0 domain for your tenant
     *     - client_id              (String)  Required. Client ID found in the Application settings
     *     - redirect_uri           (String)  Required. Authentication callback URI
     *     - client_secret          (String)  Optional. Client Secret found in the Application settings
     *     - secret_base64_encoded  (Boolean) Optional. Client Secret base64 encoded (true) or not (false, default)
     *     - audience               (String)  Optional. API identifier to generate an access token
     *     - response_mode          (String)  Optional. Response mode from the authorization server
     *     - response_type          (String)  Optional. Response type from the authorization server
     *     - scope                  (String)  Optional. Scope for ID and access tokens.
     *     - guzzle_options         (Object)  Optional. Options passed to the Guzzle HTTP library
     *     - skip_userinfo          (Boolean) Optional. Use the ID token for user identity (true, default) or the
     *                                                  userinfo endpoint (false)
     *     - max_age                (Integer) Optional. Maximum time allowed between authentication and callback
     *     - id_token_alg           (String)  Optional. ID token algorithm expected; RS256 (default) or HS256 only
     *     - id_token_leeway        (Integer) Optional. Leeway, in seconds, for ID token validation.
     *     - store                  (Mixed)   Optional. StorageInterface for identity and token persistence;
     *                                                  leave empty to default to SessionStore, false for none
     *     - auth_store             (Mixed)  Optional.  StorageInterface for transient auth data;
     *                                                  leave empty to default to CookieStore, false for none
     *     - state_handler          (Mixed)   Optional. A class that implements StateHandler or false for none;
     *                                                  leave empty to default to SessionStore SessionStateHandler
     *     - cache_handler          (Mixed)   Optional. A class that implements CacheHandler of false for none
     *     - persist_user           (Boolean) Optional. Persist the user info, default true
     *     - persist_access_token   (Boolean) Optional. Persist the access token, default false
     *     - persist_refresh_token  (Boolean) Optional. Persist the refresh token, default false
     *     - persist_id_token       (Boolean) Optional. Persist the ID token, default false
     *
     * @throws CoreException If `domain`, `client_id`, or `redirect_uri` is not provided.
     * @throws CoreException If `id_token_alg` is provided and is not supported.
     */
    public function __construct(array $config)
    {
        $this->domain = $config['domain'] ?? $_ENV['AUTH0_DOMAIN'] ?? null;
        if (empty($this->domain)) {
            throw new CoreException('Invalid domain');
        }

        $this->clientId = $config['client_id'] ?? $_ENV['AUTH0_CLIENT_ID'] ?? null;
        if (empty($this->clientId)) {
            throw new CoreException('Invalid client_id');
        }

        $this->redirectUri = $config['redirect_uri'] ?? $_ENV['AUTH0_REDIRECT_URI'] ?? null;
        if (empty($this->redirectUri)) {
            throw new CoreException('Invalid redirect_uri');
        }

        $this->clientSecret = $config['client_secret'] ?? null;
        if ($this->clientSecret && ($config['secret_base64_encoded'] ?? false)) {
            $this->clientSecret = self::urlSafeBase64Decode($this->clientSecret);
        }

        $this->audience      = $config['audience'] ?? null;
        $this->responseMode  = $config['response_mode'] ?? 'query';
        $this->responseType  = $config['response_type'] ?? 'code';
        $this->scope         = $config['scope'] ?? 'openid profile email';
        $this->guzzleOptions = $config['guzzle_options'] ?? [];
        $this->skipUserinfo  = $config['skip_userinfo'] ?? true;
        $this->maxAge        = $config['max_age'] ?? null;
        $this->idTokenLeeway = $config['id_token_leeway'] ?? null;

        $this->idTokenAlg = $config['id_token_alg'] ?? 'RS256';
        if (! in_array( $this->idTokenAlg, ['HS256', 'RS256'] )) {
            throw new CoreException('Invalid id_token_alg; must be "HS256" or "RS256"');
        }

        // User info is persisted by default.
        if (isset($config['persist_user']) && false === $config['persist_user']) {
            $this->dontPersist('user');
        }

        // Access token is not persisted by default.
        if (! isset($config['persist_access_token']) || false === $config['persist_access_token']) {
            $this->dontPersist('access_token');
        }

        // Refresh token is not persisted by default.
        if (! isset($config['persist_refresh_token']) || false === $config['persist_refresh_token']) {
            $this->dontPersist('refresh_token');
        }

        // ID token is not persisted by default.
        if (! isset($config['persist_id_token']) || false === $config['persist_id_token']) {
            $this->dontPersist('id_token');
        }

        $this->store = $config['store'] ?? null;
        if ($this->store === false) {
            $this->store = new EmptyStore();
        } else if (! $this->store instanceof StoreInterface) {
            $this->store = new SessionStore();
        }

        $this->authStore = $config['auth_store'] ?? null;
        if (! $this->authStore instanceof StoreInterface) {
            $this->authStore = new CookieStore();
        }

        if (isset($config['state_handler'])) {
            if ($config['state_handler'] === false) {
                $this->stateHandler = new DummyStateHandler();
            } else {
                $this->stateHandler = $config['state_handler'];
            }
        } else {
            $stateStore         = new SessionStore();
            $this->stateHandler = new SessionStateHandler($stateStore);
        }

        if (isset($config['cache_handler']) && $config['cache_handler'] instanceof CacheHandler) {
            $this->cacheHandler = $config['cache_handler'];
        } else {
            $this->cacheHandler = new NoCacheHandler();
        }

        $this->authentication = new Authentication(
            $this->domain,
            $this->clientId,
            $this->clientSecret,
            $this->audience,
            $this->scope,
            $this->guzzleOptions
        );

        $this->user         = $this->store->get('user');
        $this->accessToken  = $this->store->get('access_token');
        $this->idToken      = $this->store->get('id_token');
        $this->refreshToken = $this->store->get('refresh_token');
    }

    /**
     * Redirect to the hosted login page for a specific client
     *
     * @param null  $state            - state value.
     * @param null  $connection       - connection to use.
     * @param array $additionalParams - additional, valid parameters.
     *
     * @return void
     *
     * @see \Auth0\SDK\API\Authentication::get_authorize_link()
     * @see https://auth0.com/docs/api/authentication#login
     */
    public function login($state = null, $connection = null, array $additionalParams = [])
    {
        $params = [];

        if ($state) {
            $params['state'] = $state;
        }

        if ($connection) {
            $params['connection'] = $connection;
        }

        if (! empty($additionalParams) && is_array($additionalParams)) {
            $params = array_replace($params, $additionalParams);
        }

        $login_url = $this->getLoginUrl($params);

        header('Location: '.$login_url);
        exit;
    }

    /**
     * Build the login URL.
     *
     * @param array $params Array of authorize parameters to use.
     *
     * @return string
     */
    public function getLoginUrl(array $params = [])
    {
        $default_params = [
            'scope' => $this->scope,
            'audience' => $this->audience,
            'response_mode' => $this->responseMode,
            'response_type' => $this->responseType,
            'redirect_uri' => $this->redirectUri,
            'max_age' => $this->maxAge,
        ];

        $auth_params = array_replace( $default_params, $params );
        $auth_params = array_filter( $auth_params );

        if (empty( $auth_params['state'] )) {
            // No state provided by application so generate, store, and send one.
            $auth_params['state'] = $this->stateHandler->issue();
        } else {
            // Store the passed-in value.
            $this->stateHandler->store($auth_params['state']);
        }

        // ID token nonce validation is required so auth params must include one.
        if (empty( $auth_params['nonce'] )) {
            $auth_params['nonce'] = self::getNonce();
        }

        $this->authStore->set( 'nonce', $auth_params['nonce'] );

        if (isset($auth_params['max_age'])) {
            $this->authStore->set( 'max_age', $auth_params['max_age'] );
        }

        return $this->authentication->get_authorize_link(
            $auth_params['response_type'],
            $auth_params['redirect_uri'],
            null,
            null,
            $auth_params
        );
    }

    /**
     * Get userinfo from persisted session or from a code exchange
     *
     * @return array|null
     *
     * @throws ApiException (see self::exchange()).
     * @throws CoreException (see self::exchange()).
     */
    public function getUser()
    {
        if (! $this->user) {
            $this->exchange();
        }

        return $this->user;
    }

    /**
     * Get access token from persisted session or from a code exchange
     *
     * @return string|null
     *
     * @throws ApiException (see self::exchange()).
     * @throws CoreException (see self::exchange()).
     */
    public function getAccessToken()
    {
        if (! $this->accessToken) {
            $this->exchange();
        }

        return $this->accessToken;
    }

    /**
     * Get ID token from persisted session or from a code exchange
     *
     * @return string|null
     *
     * @throws ApiException (see self::exchange()).
     * @throws CoreException (see self::exchange()).
     */
    public function getIdToken()
    {
        if (! $this->idToken) {
            $this->exchange();
        }

        return $this->idToken;
    }

    /**
     * Get refresh token from persisted session or from a code exchange
     *
     * @return string|null
     *
     * @throws ApiException (see self::exchange()).
     * @throws CoreException (see self::exchange()).
     */
    public function getRefreshToken()
    {
        if (! $this->refreshToken) {
            $this->exchange();
        }

        return $this->refreshToken;
    }

    /**
     * Exchange authorization code for access, ID, and refresh tokens
     *
     * @throws CoreException If the state value is missing or invalid.
     * @throws CoreException If there is already an active session.
     * @throws ApiException If access token is missing from the response.
     * @throws RequestException If HTTP request fails (e.g. access token does not have userinfo scope).
     *
     * @return boolean
     *
     * @see https://auth0.com/docs/api-auth/tutorials/authorization-code-grant
     */
    public function exchange()
    {
        $code = $this->getAuthorizationCode();
        if (! $code) {
            return false;
        }

        $state = $this->getState();
        if (! $this->stateHandler->validate($state)) {
            throw new CoreException('Invalid state');
        }

        if ($this->user) {
            throw new CoreException('Can\'t initialize a new session while there is one active session already');
        }

        $response = $this->authentication->code_exchange($code, $this->redirectUri);

        if (empty($response['access_token'])) {
            throw new ApiException('Invalid access_token - Retry login.');
        }

        $this->setAccessToken($response['access_token']);

        if (isset($response['refresh_token'])) {
            $this->setRefreshToken($response['refresh_token']);
        }

        if (isset($response['id_token'])) {
            $this->setIdToken($response['id_token']);
        }

        if ($this->skipUserinfo) {
            $user = $this->idTokenDecoded;
        } else {
            $user = $this->authentication->userinfo($this->accessToken);
        }

        if ($user) {
            $this->setUser($user);
        }

        return true;
    }

    /**
     * Renews the access token and ID token using an existing refresh token.
     * Scope "offline_access" must be declared in order to obtain refresh token for later token renewal.
     *
     * @param array $options Options for the token endpoint request.
     *      - options.scope         Access token scope requested; optional.
     *
     * @throws CoreException If the Auth0 object does not have access token and refresh token
     * @throws ApiException If the Auth0 API did not renew access and ID token properly
     * @link   https://auth0.com/docs/tokens/refresh-token/current
     */
    public function renewTokens(array $options = [])
    {
        if (! $this->accessToken) {
            throw new CoreException('Can\'t renew the access token if there isn\'t one valid');
        }

        if (! $this->refreshToken) {
            throw new CoreException('Can\'t renew the access token if there isn\'t a refresh token available');
        }

        $response = $this->authentication->refresh_token( $this->refreshToken, $options );

        if (empty($response['access_token']) || empty($response['id_token'])) {
            throw new ApiException('Token did not refresh correctly. Access or ID token not provided.');
        }

        $this->setAccessToken($response['access_token']);

        if (isset($response['id_token'])) {
            $this->setIdToken($response['id_token']);
        }
    }

    /**
     * Set the user property to a userinfo array and, if configured, persist
     *
     * @param array $user - userinfo from Auth0.
     *
     * @return $this
     */
    public function setUser(array $user)
    {
        if (in_array('user', $this->persistantMap)) {
            $this->store->set('user', $user);
        }

        $this->user = $user;
        return $this;
    }

    /**
     * Sets and persists the access token.
     *
     * @param string $accessToken - access token returned from the code exchange.
     *
     * @return \Auth0\SDK\Auth0
     */
    public function setAccessToken($accessToken)
    {
        if (in_array('access_token', $this->persistantMap)) {
            $this->store->set('access_token', $accessToken);
        }

        $this->accessToken = $accessToken;
        return $this;
    }

    /**
     * Sets, validates, and persists the ID token.
     *
     * @param string $idToken - ID token returned from the code exchange.
     *
     * @return \Auth0\SDK\Auth0
     *
     * @throws CoreException
     * @throws InvalidTokenException
     */
    public function setIdToken($idToken)
    {
        $idTokenIss  = 'https://'.$this->domain.'/';
        $sigVerifier = null;
        if ('RS256' === $this->idTokenAlg) {
            $jwksFetcher = new JWKFetcher($this->cacheHandler, $this->guzzleOptions);
            $jwks        = $jwksFetcher->getKeys($idTokenIss.'.well-known/jwks.json');
            $sigVerifier = new AsymmetricVerifier($jwks);
        } else if ('HS256' === $this->idTokenAlg) {
            $sigVerifier = new SymmetricVerifier($this->clientSecret);
        }

        $verifierOptions = [
            // Set a custom leeway if one was passed to the constructor.
            'leeway' => $this->idTokenLeeway,
            'max_age' => $this->authStore->get('max_age') ?? $this->maxAge,
        ];
        $this->authStore->delete('max_age');

        $verifierOptions['nonce'] = $this->authStore->get('nonce');
        if (empty( $verifierOptions['nonce'] )) {
            throw new InvalidTokenException('Nonce value not found in application store');
        }

        $this->authStore->delete('nonce');

        $idTokenVerifier      = new IdTokenVerifier($idTokenIss, $this->clientId, $sigVerifier);
        $this->idTokenDecoded = $idTokenVerifier->verify($idToken, $verifierOptions);

        if (in_array('id_token', $this->persistantMap)) {
            $this->store->set('id_token', $idToken);
        }

        $this->idToken = $idToken;
        return $this;
    }

    /**
     * Sets and persists the refresh token.
     *
     * @param string $refreshToken - refresh token returned from the code exchange.
     *
     * @return \Auth0\SDK\Auth0
     */
    public function setRefreshToken($refreshToken)
    {
        if (in_array('refresh_token', $this->persistantMap)) {
            $this->store->set('refresh_token', $refreshToken);
        }

        $this->refreshToken = $refreshToken;
        return $this;
    }

    /**
     * Get the authorization code from POST or GET, depending on response_mode
     *
     * @return string|null
     *
     * @see https://auth0.com/docs/api-auth/tutorials/authorization-code-grant
     */
    protected function getAuthorizationCode()
    {
        $code = null;
        if ($this->responseMode === 'query' && isset($_GET['code'])) {
            $code = $_GET['code'];
        } else if ($this->responseMode === 'form_post' && isset($_POST['code'])) {
            $code = $_POST['code'];
        }

        return $code;
    }

    /**
     * Get the state from POST or GET, depending on response_mode
     *
     * @return string|null
     *
     * @see https://auth0.com/docs/api-auth/tutorials/authorization-code-grant
     */
    protected function getState()
    {
        $state = null;
        if ($this->responseMode === 'query' && isset($_GET['state'])) {
            $state = $_GET['state'];
        } else if ($this->responseMode === 'form_post' && isset($_POST['state'])) {
            $state = $_POST['state'];
        }

        return $state;
    }

    /**
     * Delete any persistent data and clear out all stored properties
     *
     * @return void
     */
    public function logout()
    {
        $this->deleteAllPersistentData();
        $this->accessToken  = null;
        $this->user         = null;
        $this->idToken      = null;
        $this->refreshToken = null;
    }

    /**
     * Delete all persisted data
     *
     * @return void
     */
    public function deleteAllPersistentData()
    {
        foreach ($this->persistantMap as $key) {
            $this->store->delete($key);
        }
    }

    /**
     * Removes $name from the persistantMap, thus not persisting it when we set the value.
     *
     * @param string $name - value to remove from persistence.
     *
     * @return void
     */
    private function dontPersist($name)
    {
        $key = array_search($name, $this->persistantMap);
        if ($key !== false) {
            unset($this->persistantMap[$key]);
        }
    }

    /**
     * Set the storage engine that implements StoreInterface
     *
     * @param StoreInterface $store - storage engine to use.
     *
     * @return \Auth0\SDK\Auth0
     */
    public function setStore(StoreInterface $store)
    {
        $this->store = $store;
        return $this;
    }

    /**
     * @param integer $length
     *
     * @return string
     */
    public static function getNonce(int $length = 16) : string
    {
        try {
            $random_bytes = random_bytes($length);
        } catch (\Exception $e) {
            $random_bytes = openssl_random_pseudo_bytes($length);
        }

        return bin2hex($random_bytes);
    }

    /**
     * Decode a URL-safe base64-encoded string.
     *
     * @param string $input Base64 encoded string to decode.
     *
     * @return string
     */
    public static function urlSafeBase64Decode(string $input) : string
    {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $input .= str_repeat('=', 4 - $remainder);
        }

        $input = strtr($input, '-_', '+/');
        return base64_decode($input);
    }
}
