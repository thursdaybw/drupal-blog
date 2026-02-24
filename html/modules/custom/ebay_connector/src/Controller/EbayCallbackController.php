<?php

declare(strict_types=1);

namespace Drupal\ebay_connector\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ebay_infrastructure\Service\OAuthTokenService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class EbayCallbackController implements ContainerInjectionInterface {

  public function __construct(
    private readonly OAuthTokenService $oauthTokenService,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('drupal.ebay_infrastructure.oauth_token'),
      $container->get('entity_type.manager'),
    );
  }

  public function handle(Request $request): Response {

    $code = $request->query->get('code');

    if (!$code) {
      return new Response('Missing OAuth code.', 400);
    }

    $config = \Drupal::config('ebay_connector.settings');
    $environment = (string) $config->get('environment');
    $ruName = (string) $config->get($environment . '.ru_name');

    $tokenData = $this->oauthTokenService
                      ->requestAuthorizationCodeToken($code, $ruName);

    $storage = $this->entityTypeManager->getStorage('ebay_account');

    $account = $storage->create([
      'label' => 'Primary eBay Account',
      'uid' => \Drupal::currentUser()->id(),
      'environment' => 'production',
      'access_token' => $tokenData['access_token'],
      'refresh_token' => $tokenData['refresh_token'],
      'expires_at' => time() + (int) $tokenData['expires_in'],
    ]);

    $account->save();

    return new Response('eBay account connected successfully.');
  }

}
