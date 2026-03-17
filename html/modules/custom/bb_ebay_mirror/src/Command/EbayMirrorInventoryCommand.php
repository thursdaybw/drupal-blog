<?php

declare(strict_types=1);

namespace Drupal\bb_ebay_mirror\Command;

use Drupal\bb_ebay_mirror\Service\EbayInventoryMirrorSyncService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ebay_connector\Entity\EbayAccount;
use Drush\Commands\DrushCommands;

final class EbayMirrorInventoryCommand extends DrushCommands {

  public function __construct(
    private readonly EbayInventoryMirrorSyncService $inventorySyncService,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Sync eBay inventory items into the local mirror table.
   *
   * @command bb-ebay-mirror:sync-inventory
   * @option account-id Explicit eBay account ID.
   * @option page-size API page size to request.
   */
  public function syncInventory(?int $accountId = NULL, array $options = ['account-id' => NULL, 'page-size' => 100]): void {
    $accountId = $this->resolveRequestedAccountId($accountId, $options);
    $pageSize = (int) ($options['page-size'] ?? 100);
    $account = $this->resolveAccount($accountId);
    $results = $this->inventorySyncService->syncAll($account, $pageSize);

    $this->output()->writeln(sprintf(
      'Synced %d inventory items across %d pages into bb_ebay_inventory_item for eBay account %d (%s).',
      $results['upserted'],
      $results['pages'],
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
   * @param array{account-id?:mixed,page-size?:mixed} $options
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
