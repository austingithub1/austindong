<?php
namespace Destiny\Youtube;

use Destiny\Common\Authentication\AuthProvider;
use Destiny\Common\Authentication\OAuthResponse;
use Destiny\Common\Config;
use Destiny\Common\Exception;
use Destiny\Common\Session\Session;
use Destiny\Common\Utils\FilterParams;
use Destiny\Common\Utils\Http;
use Destiny\Google\GoogleAuthHandler;

class YouTubeBroadcasterAuthHandler extends GoogleAuthHandler {
    private $apiBase = 'https://www.googleapis.com/youtube/v3';
    public $authProvider = AuthProvider::YOUTUBE_BROADCASTER;

    function getAuthorizationUrl(
        $scope = [
            'https://www.googleapis.com/auth/youtube',
            'https://www.googleapis.com/auth/youtube.force-ssl',
            'https://www.googleapis.com/auth/youtube.readonly'
        ],
        $claims = ''
    ): string {
        if (Config::$a[$this->authProvider]['sync_memberships']) {
            $scope[] = 'https://www.googleapis.com/auth/youtube.channel-memberships.creator';
        }

        $conf = $this->getAuthProviderConf();
        return "$this->authBase/auth?" . http_build_query([
            'response_type' => 'code',
            'scope' => join(' ', $scope),
            'state' => 'security_token=' . Session::getSessionId(),
            'client_id' => $conf['client_id'],
            'redirect_uri' => sprintf($conf['redirect_uri'], $this->authProvider),
            'access_type' => 'offline',
            'include_granted_scopes' => 'true'
        ], null, '&');
    }

    /**
     * @throws Exception
     */
    public function mapTokenResponse(array $token): OAuthResponse {
        return new OAuthResponse([
            'accessToken' => $token['access_token'],
            'refreshToken' => $token['refresh_token'],
            'authProvider' => $this->authProvider,
            'username' => '',
            'authId' => '',
            'authDetail' => '',
            'authEmail' => '',
            'verified' => true,
        ]);
    }

    /**
     * @throws Exception
     */
    public function renewToken(string $refreshToken): array {
        $conf = $this->getAuthProviderConf();
        $response = $this->getHttpClient()->post("$this->authBase/token", [
            'headers' => ['User-Agent' => Config::userAgent()],
            'form_params' => [
                'grant_type' => 'refresh_token',
                'client_id' => $conf['client_id'],
                'client_secret' => $conf['client_secret'],
                'refresh_token' => $refreshToken
            ]
        ]);

        if (!empty($response) && $response->getStatusCode() == Http::STATUS_OK) {
            $data = json_decode($response->getBody(), true);
            FilterParams::required($data, 'access_token');
            return $data;
        }

        throw new Exception('Failed to refresh access token.');
    }
}
