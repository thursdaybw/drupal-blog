<?php

declare(strict_types=1);

namespace Drupal\bb_ebay_mirror\Command;

use Drupal\bb_ebay_mirror\Service\EbayOfferMirrorSyncService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ebay_connector\Entity\EbayAccount;
use Drush\Commands\DrushCommands;

final class EbayMirrorOfferCommand extends DrushCommands {

  public function __construct(
    private readonly EbayOfferMirrorSyncService $offerSyncService,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Sync eBay offers into the local mirror table.
   *
   * @command bb-ebay-mirror:sync-offers
   * @option account-id Explicit eBay account ID.
   */
  public function syncOffers(?int $accountId = NULL, array $options = ['account-id' => NULL]): void {
    $accountId = $this->resolveRequestedAccountId($accountId, $options);
    $account = $this->resolveAccount($accountId);
    $results = $this->offerSyncService->syncAll($account);

    $this->output()->writeln(sprintf(
      'Synced %d offers across %d mirrored SKUs into bb_ebay_offer for eBay account %d (%s).',
      $results['offers_upserted'],
      $results['skus'],
      (int) $account->id(),
      (string) $account->label()
    ));
  }

  private function resolveAccount(?int $accountId): EbayAccount {
    $storage = $this->entityTypeManager->getStorage('ebay_account');

    if ($accountId !== NULL) {
      $account = $storage->load($accountId);
      if (!$account instanceof EbayAccount) {
        throw new \RuntimeException(sprintf('eBay account %d was not found.', $accountId));
      }

      return $account;
    }

    $accounts = $storage->loadByProperties(['environment' => 'production']);
    $account = reset($accounts);
    if (!$account instanceof EbayAccount) {
      throw new \RuntimeException('No production eBay account found.');
    }

    return $account;
  }

  /**
   * @param array{account-id?:mixed} $options
   */
  private function resolveRequestedAccountId(?int $accountId, array $options): ?int {
    $namedAccountId = $options['account-id'] ?? NULL;
    if ($namedAccountId === NULL || $namedAccountId === '') {
      return $accountId;
    }

    $namedAccountId = (int) $namedAccountId;
    if ($accountId !== NULL && $accountId !== $namedAccountId) {
      throw new \RuntimeException(sprintf(
        'Conflicting account IDs provided: positional %d and --account-id=%d.',
        $accountId,
        $namedAccountId
      ));
    }

    return $namedAccountId;
  }

}
