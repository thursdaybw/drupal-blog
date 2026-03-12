<?php

declare(strict_types=1);

namespace Drupal\marketplace_orders\Command;

use Drupal\marketplace_orders\Service\SyncMarketplaceOrdersSinceService;
use Drush\Commands\DrushCommands;

/**
 * Drush command surface for marketplace order synchronization.
 */
final class MarketplaceOrdersCommand extends DrushCommands {

  public function __construct(
    private readonly SyncMarketplaceOrdersSinceService $syncService,
  ) {
    parent::__construct();
  }

  /**
   * Synchronize marketplace orders into local order tables.
   *
   * @command marketplace-orders:sync
   * @aliases mosync
   *
   * @option marketplace
   *   Marketplace key, defaults to ebay.
   * @option since
   *   Optional UNIX timestamp or ISO-8601 timestamp.
   */
  public function sync(array $options = [
    'marketplace' => 'ebay',
    'since' => '',
  ]): void {
    $marketplace = trim((string) ($options['marketplace'] ?? 'ebay'));
    if ($marketplace === '') {
      $marketplace = 'ebay';
    }

    $sinceTimestamp = $this->parseSinceOption((string) ($options['since'] ?? ''));

    $summary = $this->syncService->sync($marketplace, $sinceTimestamp);

    $this->output()->writeln('Marketplace order sync complete.');
    $this->output()->writeln('- marketplace: ' . $summary->getMarketplace());
    $this->output()->writeln('- since_timestamp: ' . $summary->getSinceTimestamp());
    $this->output()->writeln('- fetched_orders: ' . $summary->getFetchedOrders());
    $this->output()->writeln('- upserted_orders: ' . $summary->getUpsertedOrders());
    $this->output()->writeln('- next_since_timestamp: ' . $summary->getNextSinceTimestamp());
    $this->output()->writeln('- next_since_iso: ' . gmdate('c', $summary->getNextSinceTimestamp()));
  }

  private function parseSinceOption(string $value): ?int {
    $trimmedValue = trim($value);
    if ($trimmedValue === '') {
      return NULL;
    }

    if (ctype_digit($trimmedValue)) {
      return (int) $trimmedValue;
    }

    $parsed = strtotime($trimmedValue);
    if ($parsed === FALSE) {
      throw new \InvalidArgumentException(sprintf(
        'Invalid --since value "%s". Use UNIX timestamp or ISO-8601.',
        $trimmedValue
      ));
    }

    return $parsed;
  }

}
