<?php

declare(strict_types=1);

namespace Drupal\ebay_connector\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;

final class EbayAuthorizeController implements ContainerInjectionInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('config.factory'),
    );
  }

  public function redirectToEbay(): TrustedRedirectResponse {

    $config = $this->configFactory->get('ebay_connector.settings');
    $environment = (string) $config->get('environment');
    $clientId = (string) $config->get($environment . '.client_id');


    $base = $environment === 'sandbox'
      ? 'https://auth.sandbox.ebay.com/oauth2/authorize'
      : 'https://auth.ebay.com/oauth2/authorize';

    $ruName = (string) $config->get($environment . '.ru_name');
    $currentUserId = \Drupal::currentUser()->id();

    $scopes = [
      'https://api.ebay.com/oauth/api_scope',
      'https://api.ebay.com/oauth/api_scope/sell.inventory',
      'https://api.ebay.com/oauth/api_scope/sell.account',
      'https://api.ebay.com/oauth/api_scope/sell.fulfillment',
      'https://api.ebay.com/oauth/api_scope/sell.stores',
    ];
    $query = http_build_query([
      'client_id' => $clientId,
      'response_type' => 'code',
      'redirect_uri' => $ruName,
      'scope' => implode(' ', $scopes),
      'state' => (string) $currentUserId,
      'prompt' => 'login consent',
    ]);
    $finalUrl = $base . '?' . $query;

    file_put_contents('/tmp/ebay_authorize.txt', $finalUrl);

    return new TrustedRedirectResponse($finalUrl);
  }

}
