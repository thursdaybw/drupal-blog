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
   */
  public function syncOffers(?int $accountId = NULL): void {
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

}
