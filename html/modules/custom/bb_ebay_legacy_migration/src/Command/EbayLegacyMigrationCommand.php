<?php

declare(strict_types=1);

namespace Drupal\bb_ebay_legacy_migration\Command;

use Drupal\bb_ebay_legacy_migration\Service\EbayLegacyMigrationService;
use Drush\Commands\DrushCommands;
use InvalidArgumentException;

final class EbayLegacyMigrationCommand extends DrushCommands {

  public function __construct(
    private readonly EbayLegacyMigrationService $migrationService,
  ) {
    parent::__construct();
  }

  /**
   * Migrate old eBay Item IDs into the Sell Inventory model in chunks of five.
   *
   * What this does:
   * - calls eBay bulkMigrateListing with up to five Item IDs per request
   * - resyncs mirrored inventory after each migration chunk
   * - resyncs mirrored offers after each migration chunk
   *
   * What this does not do:
   * - it does not create local bb_ai_listing rows
   * - it does not use the old Trading API
   *
   * @command bb-ebay-legacy-migration:migrate-listings
   *
   * @usage bb-ebay-legacy-migration:migrate-listings "176577811710,176582430935,176604590528,176604596280,176779515895"
   *   Migrate one or more legacy eBay Item IDs into the Sell Inventory model.
   */
  public function migrateListings(string $listingIdList): void {
    $listingIds = $this->parseListingIdList($listingIdList);
    if ($listingIds === []) {
      throw new InvalidArgumentException('At least one eBay Item ID must be provided.');
    }

    $results = $this->migrationService->migrateListingIds($listingIds);

    foreach ($results as $result) {
      $this->output()->writeln(sprintf(
        'Migrated chunk: %s',
        implode(', ', $result['listing_ids'])
      ));
      $this->writeChunkSummary($result['response']);
    }
  }

  /**
   * @return string[]
   */
  private function parseListingIdList(string $listingIdList): array {
    $listingIds = preg_split('/[\s,]+/', trim($listingIdList)) ?: [];

    return array_values(array_filter($listingIds, static fn (string $listingId): bool => $listingId !== ''));
  }

  private function writeChunkSummary(array $response): void {
    $responses = $response['responses'] ?? [];
    if (!is_array($responses) || $responses === []) {
      $this->output()->writeln('No per-listing responses were returned by eBay.');
      return;
    }

    foreach ($responses as $listingResponse) {
      if (!is_array($listingResponse)) {
        continue;
      }

      $this->output()->writeln($this->buildListingSummaryLine($listingResponse));
    }
  }

  private function buildListingSummaryLine(array $listingResponse): string {
    $listingId = (string) ($listingResponse['listingId'] ?? 'unknown');
    $statusCode = (string) ($listingResponse['statusCode'] ?? 'unknown');
    $inventoryItems = $listingResponse['inventoryItems'] ?? [];
    $firstInventoryItem = is_array($inventoryItems) ? ($inventoryItems[0] ?? []) : [];
    $sku = is_array($firstInventoryItem) ? (string) ($firstInventoryItem['sku'] ?? '') : '';
    $offerId = is_array($firstInventoryItem) ? (string) ($firstInventoryItem['offerId'] ?? '') : '';
    $errorMessage = $this->extractFirstErrorMessage($listingResponse);

    $parts = [
      'listingId ' . $listingId,
      'statusCode ' . $statusCode,
    ];

    if ($sku !== '') {
      $parts[] = 'sku ' . $sku;
    }

    if ($offerId !== '') {
      $parts[] = 'offerId ' . $offerId;
    }

    if ($errorMessage !== NULL) {
      $parts[] = 'error ' . $errorMessage;
    }

    return '- ' . implode(' | ', $parts);
  }

  private function extractFirstErrorMessage(array $listingResponse): ?string {
    $errors = $listingResponse['errors'] ?? [];
    if (!is_array($errors) || $errors === []) {
      return NULL;
    }

    $firstError = $errors[0] ?? NULL;
    if (!is_array($firstError)) {
      return NULL;
    }

    $message = trim((string) ($firstError['message'] ?? ''));
    if ($message === '') {
      return NULL;
    }

    $sku = $this->extractErrorParameter($firstError, 'SKU');
    if ($sku === NULL) {
      return $message;
    }

    return $message . ' (SKU ' . $sku . ')';
  }

  private function extractErrorParameter(array $error, string $name): ?string {
    $parameters = $error['parameters'] ?? [];
    if (!is_array($parameters)) {
      return NULL;
    }

    foreach ($parameters as $parameter) {
      if (!is_array($parameter)) {
        continue;
      }

      if (($parameter['name'] ?? NULL) !== $name) {
        continue;
      }

      $value = trim((string) ($parameter['value'] ?? ''));
      return $value === '' ? NULL : $value;
    }

    return NULL;
  }

}
