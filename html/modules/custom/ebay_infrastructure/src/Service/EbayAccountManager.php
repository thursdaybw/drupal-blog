<?php

declare(strict_types=1);

namespace Drupal\ebay_infrastructure\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ebay_connector\Entity\EbayAccount;
use Drupal\ebay_infrastructure\Service\OAuthTokenService;

final class EbayAccountManager {

  private ?EbayAccount $cachedAccount = null;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly OAuthTokenService $oauthTokenService,
  ) {}

  public function getValidAccessToken(): string {
    $account = $this->loadPrimaryAccount();

    if (((int) $account->get('expires_at')->value) > time()) {
      return (string) $account->get('access_token')->value;
    }

    $tokenData = $this->oauthTokenService->refreshUserToken(
      (string) $account->get('refresh_token')->value
    );

    $account->set('access_token', $tokenData['access_token']);
    $account->set('expires_at', time() + (int) $tokenData['expires_in']);
    $account->save();

    return (string) $tokenData['access_token'];
  }

  private function loadPrimaryAccount(): EbayAccount {
    if ($this->cachedAccount !== null) {
      return $this->cachedAccount;
    }

    $storage = $this->entityTypeManager->getStorage('ebay_account');
    $accounts = $storage->loadByProperties(['environment' => 'production']);

    if (!$accounts) {
      throw new \RuntimeException('No connected eBay account found.');
    }

    $this->cachedAccount = reset($accounts);

    if (!$this->cachedAccount instanceof EbayAccount) {
      throw new \RuntimeException('Invalid eBay account entity.');
    }

    return $this->cachedAccount;
  }

}
