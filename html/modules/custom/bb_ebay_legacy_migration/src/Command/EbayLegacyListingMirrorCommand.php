<?php

declare(strict_types=1);

namespace Drupal\bb_ebay_legacy_migration\Command;

use Drupal\bb_ebay_legacy_migration\Service\EbayLegacyListingMirrorSyncService;
use Drupal\ebay_infrastructure\Service\EbayAccountManager;
use Drush\Commands\DrushCommands;

final class EbayLegacyListingMirrorCommand extends DrushCommands {

  public function __construct(
    private readonly EbayLegacyListingMirrorSyncService $syncService,
    private readonly EbayAccountManager $accountManager,
  ) {
    parent::__construct();
  }

  /**
   * Sync active legacy eBay listings from the Trading API into a local mirror.
   *
   * @command bb-ebay-legacy-migration:sync-legacy-listings
   */
  public function syncLegacyListings(?int $accountId = NULL): void {
    $account = $accountId === NULL
      ? $this->accountManager->loadPrimaryAccount()
      : $this->accountManager->loadAccount($accountId);

    $result = $this->syncService->syncAccount($account);

    $this->output()->writeln(sprintf(
      'Synced %d legacy listings across %d pages into bb_ebay_legacy_listing for eBay account %d (%s). Deleted %d stale rows.',
      $result['synced_count'],
      $result['pages'],
      (int) $account->id(),
      (string) $account->label(),
      $result['deleted_count'],
    ));
  }

}
