<?php


namespace Sngular\Auth\Provider\Keycloak\Protocol;

use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Tool\BearerAuthorizationTrait;
use Psr\Http\Message\ResponseInterface;
use Sngular\Auth\Provider\Keycloak\Exception\EncryptionConfigurationException;
use Sngular\Auth\Provider\Keycloak\ResourceOwner\KeycloakResourceOwner;

/**
 * This manage the keycloak configuration
 * Class Connect
 * @package Sngular\Auth\Provider\Keycloak\Protocol
 */
class Connect extends AbstractProvider
{
    use BearerAuthorizationTrait;

    const AUTHORIZATION_CODE = 'authorization_code';
    const REFRESH_TOKEN = 'refresh_token';

    /**
     * Keycloak URL, eg. http://localhost:8080/auth.
     *
     * @var string
     */
    protected $authServerUrl = null;

    /**
     * Realm name.
     *
     * @var string
     */
    protected $realm = null;

    /**
     * Encryption algorithm.
     *
     * You must specify supported algorithms for your application. See
     * https://tools.ietf.org/html/rfc7518#section-3
     * for a list of spec-compliant algorithms.
     *
     * @var string
     */
    protected $encryptionAlgorithm = null;

    /**
     * Encryption key.
     *
     * @var string
     */
    protected $encryptionKey = null;

    /**
     * Connect constructor.
     * @param array $options
     * @param array $collaborators
     */
    public function __construct(array $options = [], array $collaborators = [])
    {
        $options['encryptionKey'] = $this->buildPublicKeyWithFormat($options['encryptionKeyString']);

        parent::__construct($options, $collaborators);
    }

    /*
    * Add the footer/header to the encryption key provided by keycloak
    * @return string
    */
    protected function buildPublicKeyWithFormat($key)
    {
        $keyFormatted = chunk_split($key, 64, "\n");

        return "-----BEGIN PUBLIC KEY-----\n{$keyFormatted}-----END PUBLIC KEY-----";
    }

    /**
     * Returns the base URL for authorizing a client.
     *
     * Eg. https://oauth.service.com/authorize
     *
     * @return string
     */
    public function getBaseAuthorizationUrl()
    {
        return $this->getIdentityProviderBaseUrl() . '/protocol/openid-connect/auth';
    }

    /**
     * Returns the base URL for requesting an access token.
     *
     * Eg. https://oauth.service.com/token
     *
     * @param array $params
     * @return string
     */
    public function getBaseAccessTokenUrl(array $params)
    {
        return $this->getIdentityProviderBaseUrl() . '/protocol/openid-connect/token';
    }

    /**
     * Returns the URL for requesting the resource owner's details.
     *
     * @param AccessToken $token
     * @return string
     */
    public function getResourceOwnerDetailsUrl(AccessToken $token)
    {
        return $this->getIdentityProviderBaseUrl() . '/protocol/openid-connect/userinfo';
    }

    /**
     * Builds the logout URL.
     *
     * @param array $options
     * @return string Authorization URL
     */
    public function getLogoutUrl(array $options = [])
    {
        $base   = $this->getBaseLogoutUrl();
        $params = $this->getAuthorizationParameters($options);
        $query  = $this->getAuthorizationQuery($params);
        return $this->appendQuery($base, $query);
    }

    /**
     * Get logout url to logout of session token
     *
     * @return string
     */
    private function getBaseLogoutUrl()
    {
        return $this->getIdentityProviderBaseUrl() . '/protocol/openid-connect/logout';
    }

    /**
     * Build the identity provider base url.
     *
     * @return string
     */
    protected function getIdentityProviderBaseUrl()
    {
        return $this->authServerUrl . '/realms/' . $this->realm;
    }

    /**
     * Builds the authorization URL for Docker Environments
     * This method build the authorization url with a provided getBaseAuthorizationUrl ($url)
     *
     * @param string $url
     * @param array $options
     * @return string Authorization URL
     */
    public function getAuthorizationUrlDocker(string $url, array $options = [])
    {
        $base   = $url . '/realms/' . $this->realm . '/protocol/openid-connect/auth';
        $params = $this->getAuthorizationParameters($options);
        $query  = $this->getAuthorizationQuery($params);

        return $this->appendQuery($base, $query);
    }

    /**
     * Returns the default scopes used by this provider.
     *
     * This should only be the scopes that are required to request the details
     * of the resource owner, rather than all the available scopes.
     *
     * @return array
     */
    protected function getDefaultScopes()
    {
        return ['name', 'email'];
    }

    /**
     * Checks a provider response for errors.
     *
     * @param ResponseInterface $response
     * @param array|string $data Parsed response data
     * @return void
     * @throws IdentityProviderException
     */
    protected function checkResponse(ResponseInterface $response, $data)
    {
        if (!empty($data['error'])) {
            $error = $data['error'] . ': ' . $data['error_description'];
            throw new IdentityProviderException($error, 0, $data);
        }
    }

    /**
     * Generates a resource owner object from a successful resource owner
     * details request.
     *
     * @param array $response
     * @param AccessToken $token
     * @return ResourceOwnerInterface|KeycloakResourceOwner
     */
    protected function createResourceOwner(array $response, AccessToken $token)
    {
        return new KeycloakResourceOwner($response);
    }

    /**
     * Requests and returns the resource owner of given access token.
     *
     * @param AccessToken $token
     * @return KeycloakResourceOwner
     */
    public function getResourceOwner(AccessToken $token)
    {
        $response = $this->decryptResponse($token->getToken());

        return $this->createResourceOwner($response, $token);
    }

    /**
     * Attempts to decrypt the given response.
     *
     * @param string|array|null $response
     *
     * @return string|array|null
     */
    public function decryptResponse($response)
    {
        if (!is_string($response)) {
            return $response;
        }

        // Added some seconds in the iat/nbf token date validation
        // Reference: https://github.com/auth0/auth0-PHP/issues/56#issuecomment-171944422
        JWT::$leeway = 5;

        if ($this->usesEncryption()) {
            return json_decode(
                json_encode(
                    JWT::decode(
                        $response,
                        $this->encryptionKey,
                        array($this->encryptionAlgorithm)
                    )
                ),
                true
            );
        }

        throw EncryptionConfigurationException::undeterminedEncryption();
    }

    /**
     * Checks if provider is configured to use encryption.
     *
     * @return bool
     */
    public function usesEncryption()
    {
        return (bool)$this->encryptionAlgorithm && $this->encryptionKey;
    }

    /**
     * @param string $code
     * @return AccessToken
     */
    public function authByCode(string $code)
    {
        return $this->getAccessToken(self::AUTHORIZATION_CODE, [
            'code' => $code
        ]);
    }

    /**
     * @param string $refreshToken
     * @return AccessToken
     */
    public function authByRefreshToken(string $refreshToken)
    {
        return $this->getAccessToken(self::REFRESH_TOKEN, [
            'refresh_token' => $refreshToken
        ]);
    }

    /**
     * TODO: http://docs.identityserver.io/en/latest/endpoints/introspection.html
     * @param $token
     */
    public function introspectCode($token)
    {
        $client = new Client();

        $response = $client->post(
            'http://localhost:8181/auth/realms/master/protocol/openid-connect/token/introspect',
            [
                'headers' => [
                    'Authorization' => $this->generateAuthorizationBasic(),
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ],
                'form_params' => [
                    'token' => $token
                ]
            ]
        );


        dump($response->getBody()->getContents());
        die();

    }

    /**
     * @return string
     */
    private function generateAuthorizationBasic()
    {
        return 'Basic ' . base64_encode("{$this->clientId}:{$this->clientSecret}");
    }
}