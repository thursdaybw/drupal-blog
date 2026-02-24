<?php

declare(strict_types=1);

namespace Drupal\ebay_connector\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

final class OAuthTokenService {

  private const ENV_PRODUCTION = 'production';
  private const ENV_SANDBOX = 'sandbox';

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  public function requestClientCredentialsToken(array $scopes = [], ?string $environment = null): array {
    $params = [
      'grant_type' => 'client_credentials',
      'scope' => $this->flattenScopes($scopes),
    ];

    return $this->requestToken($params, $environment);
  }

  public function requestAuthorizationCodeToken(string $code, string $redirectUri, array $scopes = [], ?string $environment = null): array {
    $params = [
      'grant_type' => 'authorization_code',
      'code' => $code,
      'redirect_uri' => $redirectUri,
      'scope' => $this->flattenScopes($scopes),
    ];

    return $this->requestToken($params, $environment);
  }

  public function refreshUserToken(string $refreshToken, array $scopes = [], ?string $environment = null): array {
    $params = [
      'grant_type' => 'refresh_token',
      'refresh_token' => $refreshToken,
      'scope' => $this->flattenScopes($scopes),
    ];

    return $this->requestToken($params, $environment);
  }

  private function requestToken(array $formParams, ?string $environment): array {
    $config = $this->configFactory->get('ebay_connector.settings');

    $activeEnv = (string) $config->get('environment');

    $clientId = (string) $config->get($activeEnv . '.client_id');
    $clientSecret = (string) $config->get($activeEnv . '.client_secret');

    if ($clientId === '' || $clientSecret === '') {
      throw new \RuntimeException('eBay OAuth credentials have not been configured.');
    }

    $env = $this->resolveEnvironment($environment, $activeEnv);
    $endpoint = $this->getTokenEndpoint($env);

    $headers = [
      'Content-Type' => 'application/x-www-form-urlencoded',
      'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
    ];

    try {
      $response = $this->httpClient->request('POST', $endpoint, [
        'headers' => $headers,
        'form_params' => array_filter($formParams, static fn($value) => $value !== '' && $value !== null),
        'http_errors' => false,
        'timeout' => 30,
      ]);
    }
    catch (GuzzleException $e) {
      throw new \RuntimeException('Failed to reach eBay OAuth endpoint: ' . $e->getMessage(), 0, $e);
    }

    $body = (string) $response->getBody();
    $data = json_decode($body, true);

    if (!is_array($data)) {
      throw new \RuntimeException('Unexpected response from eBay OAuth endpoint.');
    }

    if ($response->getStatusCode() >= 400) {
      $message = $data['error_description'] ?? $data['error'] ?? 'Unknown OAuth error';
      throw new \RuntimeException('eBay OAuth error: ' . $message);
    }

    if (empty($data['access_token'])) {
      throw new \RuntimeException('eBay OAuth response did not include an access token.');
    }

    return $data;
  }

  private function getTokenEndpoint(string $environment): string {
    return $environment === self::ENV_SANDBOX
      ? 'https://api.sandbox.ebay.com/identity/v1/oauth2/token'
      : 'https://api.ebay.com/identity/v1/oauth2/token';
  }

  private function resolveEnvironment(?string $override, ?string $configured): string {
    $candidate = $override ?? $configured;

    if ($candidate === self::ENV_SANDBOX) {
      return self::ENV_SANDBOX;
    }

    return self::ENV_PRODUCTION;
  }

  private function flattenScopes(array $scopes): string {
    $filtered = array_filter($scopes, static fn($scope) => is_string($scope) && trim($scope) !== '');
    return implode(' ', array_map('trim', $filtered));
  }

  public function revokeToken(string $token, ?string $environment = null): void {

    $config = $this->configFactory->get('ebay_connector.settings');
    $activeEnv = (string) $config->get('environment');

    $clientId = (string) $config->get($activeEnv . '.client_id');
    $clientSecret = (string) $config->get($activeEnv . '.client_secret');

    $env = $environment ?? $activeEnv;

    $endpoint = $env === self::ENV_SANDBOX
      ? 'https://api.sandbox.ebay.com/identity/v1/oauth2/revoke_token'
      : 'https://api.ebay.com/identity/v1/oauth2/revoke_token';

    $headers = [
      'Content-Type' => 'application/x-www-form-urlencoded',
      'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
    ];

    $this->httpClient->request('POST', $endpoint, [
      'headers' => $headers,
      'form_params' => [
        'token' => $token,
        'token_type_hint' => 'refresh_token',
      ],
    ]);
  }

}
