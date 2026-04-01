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
    $uid = (int) \Drupal::currentUser()->id();
    $targetEnvironment = $environment === 'sandbox' ? 'sandbox' : 'production';
    $accountIds = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->condition('environment', $targetEnvironment)
      ->sort('id', 'DESC')
      ->execute();

    if ($accountIds !== []) {
      $account = $storage->load((int) reset($accountIds));
      if (!$account) {
        return new Response('Could not load existing eBay account record.', 500);
      }
    }
    else {
      $account = $storage->create([
        'label' => 'Primary eBay Account',
        'uid' => $uid,
        'environment' => $targetEnvironment,
      ]);
    }

    $account->set('access_token', $tokenData['access_token']);
    $account->set('refresh_token', $tokenData['refresh_token']);
    $account->set('expires_at', time() + (int) $tokenData['expires_in']);

    $account->save();

    return new Response('eBay account connected successfully.');
  }

}
