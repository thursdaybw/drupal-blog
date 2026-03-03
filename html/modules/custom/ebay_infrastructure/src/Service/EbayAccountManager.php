<?php

declare(strict_types=1);

namespace Drupal\ebay_infrastructure\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ebay_connector\Entity\EbayAccount;
use Drupal\ebay_infrastructure\Service\OAuthTokenService;

final class EbayAccountManager {

  private ?EbayAccount $cachedAccount = null;
  /**
   * @var array<int,\Drupal\ebay_connector\Entity\EbayAccount>
   */
  private array $cachedAccountsById = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly OAuthTokenService $oauthTokenService,
  ) {}

  public function getValidAccessToken(): string {
    $account = $this->loadPrimaryAccount();

    return $this->getValidAccessTokenForAccount($account);
  }

  public function getValidAccessTokenForAccount(EbayAccount $account): string {
    $accountId = (int) $account->id();
    if ($accountId > 0) {
      $this->cachedAccountsById[$accountId] = $account;
    }

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

  public function loadAccount(int $accountId): EbayAccount {
    if (isset($this->cachedAccountsById[$accountId])) {
      return $this->cachedAccountsById[$accountId];
    }

    $account = $this->entityTypeManager->getStorage('ebay_account')->load($accountId);
    if (!$account instanceof EbayAccount) {
      throw new \RuntimeException(sprintf('Invalid eBay account entity: %d', $accountId));
    }

    $this->cachedAccountsById[$accountId] = $account;
    return $account;
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

    $accountId = (int) $this->cachedAccount->id();
    if ($accountId > 0) {
      $this->cachedAccountsById[$accountId] = $this->cachedAccount;
    }

    return $this->cachedAccount;
  }

}
