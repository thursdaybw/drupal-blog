<?php

declare(strict_types=1);

namespace Drupal\bb_ebay_legacy_migration\Command;

use Drupal\bb_ebay_legacy_migration\Service\EbayLegacyMigrationService;
use Drupal\bb_ebay_mirror\Service\EbayMirrorAuditService;
use Drupal\ebay_infrastructure\Service\EbayAccountManager;
use Drush\Commands\DrushCommands;
use InvalidArgumentException;

final class EbayLegacyMigrationCommand extends DrushCommands {

  public function __construct(
    private readonly EbayLegacyMigrationService $migrationService,
    private readonly EbayMirrorAuditService $mirrorAuditService,
    private readonly EbayAccountManager $accountManager,
  ) {
    parent::__construct();
  }

  /**
   * Convert old eBay Item IDs into Sell Inventory objects in chunks of five.
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
   * @command bb-ebay-legacy-migration:convert-listings-to-sell-bulk
   *
   * @usage bb-ebay-legacy-migration:convert-listings-to-sell-bulk "176577811710,176582430935,176604590528,176604596280,176779515895"
   *   Convert one or more legacy eBay Item IDs into the Sell Inventory model.
   */
  public function migrateListings(string $listingIdList): void {
    $startedAt = microtime(TRUE);

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

    $this->output()->writeln(sprintf(
      'Elapsed: %.3f seconds',
      microtime(TRUE) - $startedAt
    ));
  }

  /**
   * Prepare one legacy listing SKU if needed, then convert it immediately.
   *
   * What this does:
   * - loads one listing from the local legacy mirror
   * - skips if the listing is already visible in the Sell mirror
   * - generates `legacy-ebay-<ItemID>` when the legacy SKU is missing
   * - appends a deterministic `-M<n>` suffix when the legacy SKU is duplicated
   * - updates the legacy listing SKU through the Trading API only when needed
   * - calls Sell API `bulkMigrateListing` for that one Item ID
   * - resyncs mirrored inventory and offers
   *
   * @command bb-ebay-legacy-migration:prepare-and-convert-listing-to-sell
   *
   * @usage bb-ebay-legacy-migration:prepare-and-convert-listing-to-sell 176577811710
   *   Prepare one legacy listing SKU if needed, then convert it into the Sell model.
   */
  public function normalizeSkuAndMigrate(string $listingId): void {
    $startedAt = microtime(TRUE);

    $result = $this->migrationService->prepareAndMigrateListingId($listingId);

    $this->output()->writeln('- listingId ' . $result['listing_id']);
    $this->output()->writeln('- status ' . $result['status']);

    if ($result['previous_sku'] !== NULL) {
      $this->output()->writeln('- previousSku ' . $result['previous_sku']);
    }

    if ($result['prepared_sku'] !== NULL) {
      $this->output()->writeln('- preparedSku ' . $result['prepared_sku']);
    }

    if ($result['sku_change_reason'] !== NULL) {
      $this->output()->writeln('- skuChangeReason ' . $result['sku_change_reason']);
    }

    if (is_array($result['migrate_response'])) {
      $this->writeChunkSummary($result['migrate_response']);
    }

    $this->output()->writeln(sprintf(
      'Elapsed: %.3f seconds',
      microtime(TRUE) - $startedAt
    ));
  }

  /**
   * Prepare and convert legacy listings from the "ready to migrate" bucket.
   *
   * "Ready" means this listing is:
   * - present in the legacy mirror
   * - not yet present in the Sell offer mirror
   * - has a non-empty SKU
   * - not in a duplicate legacy SKU group
   *
   * @command bb-ebay-legacy-migration:prepare-and-convert-ready-batch-to-sell
   * @option limit Maximum number of ready listings to process in this run.
   * @option dry-run Do not mutate or migrate, only print what would run.
   *
   * @usage bb-ebay-legacy-migration:prepare-and-convert-ready-batch-to-sell --limit=25
   *   Prepare and convert up to 25 legacy listings from the ready-to-migrate bucket.
   *
   * @usage bb-ebay-legacy-migration:prepare-and-convert-ready-batch-to-sell --limit=25 --dry-run
   *   Preview the next 25 ready listings without changing eBay or local data.
   */
  public function migrateReadyBatch(array $options = [
    'limit' => 0,
    'dry-run' => FALSE,
  ]): void {
    $startedAt = microtime(TRUE);

    $limit = (int) ($options['limit'] ?? 0);
    $dryRun = (bool) ($options['dry-run'] ?? FALSE);

    $account = $this->accountManager->loadPrimaryAccount();
    $readyRows = $this->mirrorAuditService->findLegacyListingsReadyToMigrate((int) $account->id());
    if ($limit > 0) {
      $readyRows = array_slice($readyRows, 0, $limit);
    }

    if ($readyRows === []) {
      $this->output()->writeln('No legacy listings are currently ready to migrate.');
      $this->output()->writeln(sprintf('Elapsed: %.3f seconds', microtime(TRUE) - $startedAt));
      return;
    }

    $this->output()->writeln(sprintf(
      'Processing %d ready listing(s) for account %d (%s). dryRun=%s',
      count($readyRows),
      (int) $account->id(),
      $account->label(),
      $dryRun ? 'true' : 'false'
    ));

    $bucketCounts = [
      'migrated' => 0,
      'already_migrated' => 0,
      'failed_migration' => 0,
      'missing_sku_fixed_then_migrated' => 0,
      'duplicate_sku_fixed_then_migrated' => 0,
    ];
    $migratedSkus = [];

    foreach ($readyRows as $readyRow) {
      $listingId = (string) ($readyRow['ebay_listing_id'] ?? '');
      if ($listingId === '') {
        continue;
      }

      if ($dryRun) {
        $this->output()->writeln('- [dry-run] listingId ' . $listingId . ' | sku ' . (string) ($readyRow['sku'] ?? ''));
        continue;
      }

      $result = $this->migrationService->prepareAndMigrateListingId($listingId, FALSE);
      $this->writePipelineResultSummary($result);
      $this->incrementBucketCounts($bucketCounts, $result);
      $this->collectMigratedSku($migratedSkus, $result);
    }

    if ($dryRun) {
      $this->output()->writeln(sprintf(
        'Dry run complete. Would process %d listing(s).',
        count($readyRows)
      ));
    }
    else {
      if ($migratedSkus !== []) {
        $this->output()->writeln(sprintf(
          'Syncing mirrors for migrated SKUs (%d touched)...',
          count($migratedSkus)
        ));
        $syncResult = $this->migrationService->syncMirrorsForPrimaryAccountSkus($migratedSkus);
        if ($syncResult['failed_skus'] !== []) {
          $this->output()->writeln(sprintf(
            'Mirror sync failed for %d SKU(s): %s',
            count($syncResult['failed_skus']),
            implode(', ', $syncResult['failed_skus'])
          ));
        }
      }
      $this->output()->writeln('Batch summary:');
      foreach ($bucketCounts as $bucket => $count) {
        $this->output()->writeln(sprintf('- %s: %d', $bucket, $count));
      }
    }

    $this->output()->writeln(sprintf(
      'Elapsed: %.3f seconds',
      microtime(TRUE) - $startedAt
    ));
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

  /**
   * @param array{
   *   listing_id:string,
   *   status:string,
   *   previous_sku:?string,
   *   prepared_sku:?string,
   *   sku_change_reason:?string,
   *   migration_attempts?:int,
   *   migrate_response:?array
   * } $result
   */
  private function writePipelineResultSummary(array $result): void {
    $this->output()->writeln('- listingId ' . $result['listing_id']);
    $this->output()->writeln('- status ' . $result['status']);

    if ($result['previous_sku'] !== NULL) {
      $this->output()->writeln('- previousSku ' . $result['previous_sku']);
    }

    if ($result['prepared_sku'] !== NULL) {
      $this->output()->writeln('- preparedSku ' . $result['prepared_sku']);
    }

    if ($result['sku_change_reason'] !== NULL) {
      $this->output()->writeln('- skuChangeReason ' . $result['sku_change_reason']);
    }

    $attempts = (int) ($result['migration_attempts'] ?? 0);
    if ($attempts > 1) {
      $this->output()->writeln('- attempts ' . $attempts);
    }

    if (is_array($result['migrate_response'])) {
      $this->writeChunkSummary($result['migrate_response']);
    }
  }

  /**
   * @param array<string,int> $bucketCounts
   * @param array{
   *   status:string,
   *   sku_change_reason:?string
   * } $result
   */
  private function incrementBucketCounts(array &$bucketCounts, array $result): void {
    if ($result['status'] === 'already_migrated') {
      $bucketCounts['already_migrated']++;
      return;
    }

    if ($result['status'] === 'failed_migration') {
      $bucketCounts['failed_migration']++;
      return;
    }

    if ($result['status'] !== 'migrated') {
      return;
    }

    $reason = $result['sku_change_reason'];
    if ($reason === 'missing_legacy_sku') {
      $bucketCounts['missing_sku_fixed_then_migrated']++;
      return;
    }

    if ($reason === 'duplicate_legacy_sku') {
      $bucketCounts['duplicate_sku_fixed_then_migrated']++;
      return;
    }

    $bucketCounts['migrated']++;
  }

  /**
   * @param string[] $migratedSkus
   * @param array{status:string,prepared_sku:?string} $result
   */
  private function collectMigratedSku(array &$migratedSkus, array $result): void {
    if (($result['status'] ?? '') !== 'migrated') {
      return;
    }

    $preparedSku = $result['prepared_sku'] ?? NULL;
    if (!is_string($preparedSku) || trim($preparedSku) === '') {
      return;
    }

    $migratedSkus[] = trim($preparedSku);
  }

}
