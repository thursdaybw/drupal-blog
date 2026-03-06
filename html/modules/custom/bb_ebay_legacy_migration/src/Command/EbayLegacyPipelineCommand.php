<?php

declare(strict_types=1);

namespace Drupal\bb_ebay_legacy_migration\Command;

use Drupal\bb_ebay_legacy_migration\Service\EbayLegacyListingAdoptionService;
use Drupal\bb_ebay_legacy_migration\Service\EbayLegacyImportBlocklistService;
use Drupal\bb_ebay_legacy_migration\Service\EbayLegacyListingMirrorSyncService;
use Drupal\bb_ebay_legacy_migration\Service\EbayLegacyMigrationService;
use Drupal\bb_ebay_mirror\Service\EbayMirrorAuditService;
use Drupal\ebay_infrastructure\Service\EbayAccountManager;
use Drush\Commands\DrushCommands;

final class EbayLegacyPipelineCommand extends DrushCommands {

  public function __construct(
    private readonly EbayLegacyListingMirrorSyncService $legacyMirrorSyncService,
    private readonly EbayLegacyMigrationService $migrationService,
    private readonly EbayLegacyListingAdoptionService $adoptionService,
    private readonly EbayLegacyImportBlocklistService $blocklistService,
    private readonly EbayMirrorAuditService $mirrorAuditService,
    private readonly EbayAccountManager $accountManager,
  ) {
    parent::__construct();
  }

  /**
   * Fully import legacy eBay listings into the local system.
   *
   * This is the end-to-end command:
   * - sync legacy mirror once
   * - convert all remaining unmigrated legacy listings in batches
   * - sync mirror rows only for touched SKUs
   * - adopt converted listings into bb_ai_listing
   *
   * The command is resumable:
   * - rerunning skips already migrated/adopted rows through existing guards.
   *
   * @command bb-ebay-legacy-migration:import-all
   * @option batch-size Number of listings to process per loop batch.
   * @option max-batches Optional loop cap for controlled runs (0 = no cap).
   * @option dry-run Preview current unmigrated scope without mutating data.
   *
   * @usage bb-ebay-legacy-migration:import-all
   *   Run full legacy import with default batch size.
   *
   * @usage bb-ebay-legacy-migration:import-all --batch-size=50 --max-batches=2
   *   Run only two batches of 50 listings.
   */
  public function importAll(array $options = [
    'batch-size' => 50,
    'max-batches' => 0,
    'dry-run' => FALSE,
    'skip-sell-refresh' => FALSE,
  ]): void {
    $startedAt = microtime(TRUE);
    $batchSize = max(1, (int) ($options['batch-size'] ?? 50));
    $maxBatches = max(0, (int) ($options['max-batches'] ?? 0));
    $dryRun = (bool) ($options['dry-run'] ?? FALSE);
    $skipSellRefresh = (bool) ($options['skip-sell-refresh'] ?? FALSE);

    $account = $this->accountManager->loadPrimaryAccount();
    $accountId = (int) $account->id();

    $this->output()->writeln(sprintf(
      'Starting full import for account %d (%s). batchSize=%d maxBatches=%d dryRun=%s skipSellRefresh=%s',
      $accountId,
      (string) $account->label(),
      $batchSize,
      $maxBatches,
      $dryRun ? 'true' : 'false',
      $skipSellRefresh ? 'true' : 'false'
    ));

    if ($dryRun) {
      $unmigratedRows = $this->mirrorAuditService->findLegacyListingsMissingMirroredSellOffer($accountId);
      $blockedListingIds = $this->blocklistService->getBlockedListingIdsForAccount($accountId);
      $eligibleRows = $this->filterRowsExcludingBlockedListings($unmigratedRows, $blockedListingIds, $batchSize);
      $this->output()->writeln(sprintf('Current unmigrated legacy listings: %d', count($unmigratedRows)));
      $this->output()->writeln(sprintf('Current blocked listings: %d', count($blockedListingIds)));
      $previewRows = $eligibleRows;
      foreach ($previewRows as $previewRow) {
        $this->output()->writeln(sprintf(
          '- [dry-run] listingId %s | sku %s | %s',
          (string) ($previewRow['ebay_listing_id'] ?? ''),
          (string) ($previewRow['sku'] ?? 'unset'),
          (string) ($previewRow['title'] ?? '')
        ));
      }
      $this->output()->writeln('Elapsed: ' . $this->formatElapsed(microtime(TRUE) - $startedAt));
      return;
    }

    $legacySyncResult = $this->legacyMirrorSyncService->syncAccount($account);
    $this->output()->writeln(sprintf(
      'Legacy sync complete: synced=%d pages=%d deleted_stale=%d',
      $legacySyncResult['synced_count'],
      $legacySyncResult['pages'],
      $legacySyncResult['deleted_count']
    ));

    if ($skipSellRefresh) {
      $this->output()->writeln('Skipping initial full Sell mirror refresh (--skip-sell-refresh).');
    }
    else {
      $this->output()->writeln('Refreshing full Sell mirrors once before import loop...');
      $this->migrationService->resyncMirrorsForPrimaryAccount();
    }

    $totalSummary = [
      'batches' => 0,
      'conversion' => [
        'migrated' => 0,
        'already_migrated' => 0,
        'failed_migration' => 0,
        'missing_sku_fixed_then_migrated' => 0,
        'duplicate_sku_fixed_then_migrated' => 0,
      ],
      'adoption' => [
        'book_adopted' => 0,
        'generic_adopted' => 0,
        'failed' => 0,
        'not_ready' => 0,
      ],
    ];

    while (TRUE) {
      $unmigratedRows = $this->mirrorAuditService->findLegacyListingsMissingMirroredSellOffer($accountId);
      if ($unmigratedRows === []) {
        break;
      }

      if ($maxBatches > 0 && $totalSummary['batches'] >= $maxBatches) {
        $this->output()->writeln('Reached max-batches limit. Stopping early.');
        break;
      }

      $blockedListingIds = $this->blocklistService->getBlockedListingIdsForAccount($accountId);
      $batchRows = $this->filterRowsExcludingBlockedListings($unmigratedRows, $blockedListingIds, $batchSize);
      $batchListingIds = $this->extractListingIds($batchRows);
      if ($batchListingIds === []) {
        $this->output()->writeln('No eligible listing IDs found in current batch after excluding blocked rows. Stopping.');
        break;
      }

      $totalSummary['batches']++;
      $this->output()->writeln(sprintf(
        'Batch %d: processing %d listing(s)...',
        $totalSummary['batches'],
        count($batchListingIds)
      ));

      $conversionResult = $this->runConversionForListingIds($batchListingIds);
      $adoptionSummary = $this->runAdoptionStep($conversionResult['successful_listing_ids'], $accountId);

      $this->writePipelineSummary($conversionResult, $adoptionSummary);
      $this->accumulateBatchSummary($totalSummary, $conversionResult, $adoptionSummary);

      $convertedThisBatch = (int) ($conversionResult['conversion_buckets']['migrated'] ?? 0);
      $adoptedThisBatch = (int) ($adoptionSummary['book_adopted'] ?? 0) + (int) ($adoptionSummary['generic_adopted'] ?? 0);
      if ($convertedThisBatch === 0 && $adoptedThisBatch === 0) {
        $this->output()->writeln('No import progress in this batch. Stopping to avoid an infinite retry loop.');
        break;
      }
    }

    $remaining = count($this->mirrorAuditService->findLegacyListingsMissingMirroredSellOffer($accountId));

    $this->output()->writeln('Full import summary:');
    $this->output()->writeln(sprintf('- batches: %d', $totalSummary['batches']));
    $this->output()->writeln(sprintf('- conversion migrated: %d', $totalSummary['conversion']['migrated']));
    $this->output()->writeln(sprintf('- conversion already_migrated: %d', $totalSummary['conversion']['already_migrated']));
    $this->output()->writeln(sprintf('- conversion failed_migration: %d', $totalSummary['conversion']['failed_migration']));
    $this->output()->writeln(sprintf('- conversion missing_sku_fixed_then_migrated: %d', $totalSummary['conversion']['missing_sku_fixed_then_migrated']));
    $this->output()->writeln(sprintf('- conversion duplicate_sku_fixed_then_migrated: %d', $totalSummary['conversion']['duplicate_sku_fixed_then_migrated']));
    $this->output()->writeln(sprintf('- adoption book_adopted: %d', $totalSummary['adoption']['book_adopted']));
    $this->output()->writeln(sprintf('- adoption generic_adopted: %d', $totalSummary['adoption']['generic_adopted']));
    $this->output()->writeln(sprintf('- adoption failed: %d', $totalSummary['adoption']['failed']));
    $this->output()->writeln(sprintf('- adoption not_ready: %d', $totalSummary['adoption']['not_ready']));
    $this->output()->writeln(sprintf('- remaining unmigrated: %d', $remaining));
    $this->output()->writeln(sprintf('- blocked listings recorded: %d', count($this->blocklistService->getBlockedListingIdsForAccount($accountId))));
    $this->output()->writeln('Elapsed: ' . $this->formatElapsed(microtime(TRUE) - $startedAt));
  }

  /**
   * Run the legacy -> Sell -> local adoption pipeline in one command.
   *
   * Pipeline steps:
   * - sync legacy Trading mirror (optional)
   * - prepare and convert ready legacy listings into Sell objects
   * - adopt successfully converted listings into bb_ai_listing
   *
   * @command bb-ebay-legacy-migration:run-ready-pipeline
   * @option account-id Process this eBay account ID (defaults to primary).
   * @option limit Maximum number of ready listings to process.
   * @option dry-run Preview the pipeline without changing data.
   *
   * @usage bb-ebay-legacy-migration:run-ready-pipeline --limit=25
   *   Process the next 25 ready legacy listings through convert and adopt.
   *
   * @usage bb-ebay-legacy-migration:run-ready-pipeline --limit=25 --dry-run
   *   Preview the next 25 ready listings without mutating local or remote data.
   */
  public function runReadyPipeline(array $options = [
    'account-id' => NULL,
    'limit' => 0,
    'dry-run' => FALSE,
  ]): void {
    $startedAt = microtime(TRUE);

    $accountId = isset($options['account-id']) && $options['account-id'] !== NULL
      ? (int) $options['account-id']
      : NULL;
    $limit = (int) ($options['limit'] ?? 0);
    $dryRun = (bool) ($options['dry-run'] ?? FALSE);

    $account = $accountId === NULL
      ? $this->accountManager->loadPrimaryAccount()
      : $this->accountManager->loadAccount($accountId);
    $resolvedAccountId = (int) $account->id();

    $this->output()->writeln(sprintf(
      'Starting pipeline for account %d (%s). limit=%d dryRun=%s',
      $resolvedAccountId,
      (string) $account->label(),
      $limit,
      $dryRun ? 'true' : 'false'
    ));

    $readyRows = $this->mirrorAuditService->findLegacyListingsReadyToMigrate($resolvedAccountId);
    if ($limit > 0) {
      $readyRows = array_slice($readyRows, 0, $limit);
    }

    if ($readyRows === []) {
      $this->output()->writeln('No legacy listings are currently ready for conversion.');
      $this->output()->writeln('Elapsed: ' . $this->formatElapsed(microtime(TRUE) - $startedAt));
      return;
    }

    if ($dryRun) {
      $this->writeDryRunReadyRows($readyRows);
      $this->output()->writeln(sprintf(
        'Dry run complete. Would process %d listing(s).',
        count($readyRows)
      ));
      $this->output()->writeln('Elapsed: ' . $this->formatElapsed(microtime(TRUE) - $startedAt));
      return;
    }

    $conversionResult = $this->runConversionStep($readyRows);
    $adoptionSummary = $this->runAdoptionStep($conversionResult['successful_listing_ids'], $resolvedAccountId);

    $this->writePipelineSummary($conversionResult, $adoptionSummary);
    $this->output()->writeln('Elapsed: ' . $this->formatElapsed(microtime(TRUE) - $startedAt));
  }

  /**
   * @param array<int,array<string,mixed>> $readyRows
   *
   * @return array{
   *   successful_listing_ids:string[],
   *   migrated_skus:string[],
   *   conversion_buckets:array<string,int>
   * }
   */
  private function runConversionStep(array $readyRows): array {
    $this->output()->writeln(sprintf('Converting %d ready listing(s)...', count($readyRows)));

    $conversionBuckets = [
      'migrated' => 0,
      'already_migrated' => 0,
      'failed_migration' => 0,
      'missing_sku_fixed_then_migrated' => 0,
      'duplicate_sku_fixed_then_migrated' => 0,
    ];
    $successfulListingIds = [];
    $migratedSkus = [];

    foreach ($readyRows as $readyRow) {
      $listingId = (string) ($readyRow['ebay_listing_id'] ?? '');
      if ($listingId === '') {
        continue;
      }

      $result = $this->migrationService->prepareAndMigrateListingId($listingId, FALSE);
      $this->writeConversionResultLine($result);
      $this->incrementConversionBuckets($conversionBuckets, $result);
      $this->collectMigratedSku($migratedSkus, $result);

      if (($result['status'] ?? NULL) === 'migrated' || ($result['status'] ?? NULL) === 'already_migrated') {
        $successfulListingIds[] = $listingId;
      }
    }

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

    return [
      'successful_listing_ids' => array_values(array_unique($successfulListingIds)),
      'migrated_skus' => array_values(array_unique($migratedSkus)),
      'conversion_buckets' => $conversionBuckets,
    ];
  }

  /**
   * @param string[] $listingIds
   *
   * @return array{
   *   successful_listing_ids:string[],
   *   migrated_skus:string[],
   *   conversion_buckets:array<string,int>
   * }
   */
  private function runConversionForListingIds(array $listingIds): array {
    $conversionBuckets = [
      'migrated' => 0,
      'already_migrated' => 0,
      'failed_migration' => 0,
      'missing_sku_fixed_then_migrated' => 0,
      'duplicate_sku_fixed_then_migrated' => 0,
    ];
    $successfulListingIds = [];
    $migratedSkus = [];

    foreach ($listingIds as $listingId) {
      try {
        $result = $this->migrationService->prepareAndMigrateListingId($listingId, FALSE);
        $this->writeConversionResultLine($result);
        $this->incrementConversionBuckets($conversionBuckets, $result);
        $this->collectMigratedSku($migratedSkus, $result);
        $this->recordOrClearBlocklistFromResult($listingId, $result);

        if (($result['status'] ?? NULL) === 'migrated' || ($result['status'] ?? NULL) === 'already_migrated') {
          $successfulListingIds[] = $listingId;
        }
      }
      catch (\Throwable $e) {
        $conversionBuckets['failed_migration']++;
        $this->blocklistService->recordFailure($listingId, $e->getMessage());
        $this->output()->writeln(sprintf(
          '- listingId %s | status failed_migration | error %s',
          $listingId,
          $e->getMessage()
        ));
      }
    }

    if ($migratedSkus !== []) {
      $this->output()->writeln(sprintf(
        'Syncing mirrors for migrated SKUs (%d touched)...',
        count($migratedSkus)
      ));
      $this->migrationService->syncMirrorsForPrimaryAccountSkus($migratedSkus);
    }

    return [
      'successful_listing_ids' => array_values(array_unique($successfulListingIds)),
      'migrated_skus' => array_values(array_unique($migratedSkus)),
      'conversion_buckets' => $conversionBuckets,
    ];
  }

  /**
   * @param string[] $listingIds
   *
   * @return array{book_adopted:int,generic_adopted:int,failed:int,not_ready:int}
   */
  private function runAdoptionStep(array $listingIds, int $accountId): array {
    $summary = [
      'book_adopted' => 0,
      'generic_adopted' => 0,
      'failed' => 0,
      'not_ready' => 0,
    ];

    if ($listingIds === []) {
      $this->output()->writeln('No converted listings are eligible for adoption.');
      return $summary;
    }

    $readyToAdoptRows = $this->adoptionService->findReadyToAdoptListings($accountId);
    $readyToAdoptByListingId = [];
    foreach ($readyToAdoptRows as $readyToAdoptRow) {
      $readyListingId = (string) ($readyToAdoptRow['ebay_listing_id'] ?? '');
      if ($readyListingId !== '') {
        $readyToAdoptByListingId[$readyListingId] = $readyToAdoptRow;
      }
    }

    $this->output()->writeln(sprintf('Adopting converted listings into bb_ai_listing (%d candidate(s))...', count($listingIds)));

    foreach ($listingIds as $listingId) {
      if (!isset($readyToAdoptByListingId[$listingId])) {
        $summary['not_ready']++;
        $this->output()->writeln(sprintf('- listingId %s | adopt skipped | not in ready-to-adopt set', $listingId));
        continue;
      }

      $row = $readyToAdoptByListingId[$listingId];
      $categoryId = is_string($row['category_id'] ?? NULL) ? $row['category_id'] : NULL;
      $adoptionType = $this->adoptionService->resolveAdoptionTypeForCategory($categoryId);

      try {
        $result = $adoptionType === 'book'
          ? $this->adoptionService->adoptBookListing($listingId, $accountId)
          : $this->adoptionService->adoptGenericListing($listingId, $accountId);

        if ($adoptionType === 'book') {
          $summary['book_adopted']++;
        }
        else {
          $summary['generic_adopted']++;
        }

        $this->output()->writeln(sprintf(
          '- listingId %s | adopted_as %s | local %d | sku %s | offer %s',
          $result['ebay_listing_id'],
          $adoptionType,
          $result['local_listing_id'],
          $result['sku'],
          $result['offer_id']
        ));
      }
      catch (\Throwable $e) {
        $summary['failed']++;
        $this->output()->writeln(sprintf('- listingId %s | adopt failed | %s', $listingId, $e->getMessage()));
      }
    }

    return $summary;
  }

  /**
   * @param array<int,array<string,mixed>> $readyRows
   */
  private function writeDryRunReadyRows(array $readyRows): void {
    foreach ($readyRows as $readyRow) {
      $this->output()->writeln(sprintf(
        '- [dry-run] listingId %s | sku %s | %s',
        (string) ($readyRow['ebay_listing_id'] ?? ''),
        (string) ($readyRow['sku'] ?? ''),
        (string) ($readyRow['title'] ?? '')
      ));
    }
  }

  /**
   * @param array{listing_id:string,status:string,prepared_sku:?string,sku_change_reason:?string,migrate_response:?array} $result
   */
  private function writeConversionResultLine(array $result): void {
    $line = sprintf(
      '- listingId %s | status %s | preparedSku %s',
      $result['listing_id'],
      $result['status'],
      $result['prepared_sku'] ?? 'none'
    );

    $reason = $result['sku_change_reason'] ?? NULL;
    if (is_string($reason) && $reason !== '') {
      $line .= ' | skuChangeReason ' . $reason;
    }

    $errorMessage = $this->extractFirstErrorMessage($result['migrate_response'] ?? NULL);
    if ($errorMessage !== NULL) {
      $line .= ' | error ' . $errorMessage;
    }

    $this->output()->writeln($line);
  }

  /**
   * @param array<string,int> $conversionBuckets
   * @param array{status:string,sku_change_reason:?string} $result
   */
  private function incrementConversionBuckets(array &$conversionBuckets, array $result): void {
    $status = $result['status'] ?? '';
    if (isset($conversionBuckets[$status])) {
      $conversionBuckets[$status]++;
    }

    $reason = $result['sku_change_reason'] ?? NULL;
    if ($status !== 'migrated' || !is_string($reason)) {
      return;
    }

    if ($reason === 'missing_legacy_sku') {
      $conversionBuckets['missing_sku_fixed_then_migrated']++;
    }
    elseif ($reason === 'duplicate_legacy_sku') {
      $conversionBuckets['duplicate_sku_fixed_then_migrated']++;
    }
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

  /**
   * @param array<string,mixed> $conversionResult
   * @param array{book_adopted:int,generic_adopted:int,failed:int,not_ready:int} $adoptionSummary
   */
  private function writePipelineSummary(array $conversionResult, array $adoptionSummary): void {
    $conversionBuckets = $conversionResult['conversion_buckets'];

    $this->output()->writeln('Pipeline summary:');
    $this->output()->writeln('- conversion:');
    $this->output()->writeln(sprintf('  - migrated: %d', $conversionBuckets['migrated']));
    $this->output()->writeln(sprintf('  - already_migrated: %d', $conversionBuckets['already_migrated']));
    $this->output()->writeln(sprintf('  - failed_migration: %d', $conversionBuckets['failed_migration']));
    $this->output()->writeln(sprintf('  - missing_sku_fixed_then_migrated: %d', $conversionBuckets['missing_sku_fixed_then_migrated']));
    $this->output()->writeln(sprintf('  - duplicate_sku_fixed_then_migrated: %d', $conversionBuckets['duplicate_sku_fixed_then_migrated']));

    $this->output()->writeln('- adoption:');
    $this->output()->writeln(sprintf('  - book_adopted: %d', $adoptionSummary['book_adopted']));
    $this->output()->writeln(sprintf('  - generic_adopted: %d', $adoptionSummary['generic_adopted']));
    $this->output()->writeln(sprintf('  - failed: %d', $adoptionSummary['failed']));
    $this->output()->writeln(sprintf('  - not_ready: %d', $adoptionSummary['not_ready']));
  }

  /**
   * @param array<int,array<string,mixed>> $rows
   *
   * @return string[]
   */
  private function extractListingIds(array $rows): array {
    $listingIds = [];
    foreach ($rows as $row) {
      $listingId = trim((string) ($row['ebay_listing_id'] ?? ''));
      if ($listingId !== '') {
        $listingIds[] = $listingId;
      }
    }

    return array_values(array_unique($listingIds));
  }

  /**
   * @param array<int,array<string,mixed>> $rows
   * @param string[] $blockedListingIds
   *
   * @return array<int,array<string,mixed>>
   */
  private function filterRowsExcludingBlockedListings(array $rows, array $blockedListingIds, int $limit): array {
    if ($rows === []) {
      return [];
    }

    $blockedMap = [];
    foreach ($blockedListingIds as $blockedListingId) {
      $normalizedBlockedListingId = trim((string) $blockedListingId);
      if ($normalizedBlockedListingId !== '') {
        $blockedMap[$normalizedBlockedListingId] = TRUE;
      }
    }

    $filteredRows = [];
    foreach ($rows as $row) {
      $listingId = trim((string) ($row['ebay_listing_id'] ?? ''));
      if ($listingId === '') {
        continue;
      }

      if (isset($blockedMap[$listingId])) {
        continue;
      }

      $filteredRows[] = $row;
      if ($limit > 0 && count($filteredRows) >= $limit) {
        break;
      }
    }

    return $filteredRows;
  }

  /**
   * @param array{
   *   batches:int,
   *   conversion:array<string,int>,
   *   adoption:array<string,int>
   * } $totalSummary
   * @param array{conversion_buckets:array<string,int>} $conversionResult
   * @param array{book_adopted:int,generic_adopted:int,failed:int,not_ready:int} $adoptionSummary
   */
  private function accumulateBatchSummary(array &$totalSummary, array $conversionResult, array $adoptionSummary): void {
    foreach ($conversionResult['conversion_buckets'] as $bucket => $count) {
      if (isset($totalSummary['conversion'][$bucket])) {
        $totalSummary['conversion'][$bucket] += $count;
      }
    }

    foreach ($adoptionSummary as $bucket => $count) {
      if (isset($totalSummary['adoption'][$bucket])) {
        $totalSummary['adoption'][$bucket] += $count;
      }
    }
  }

  /**
   * @param array{status:string,migrate_response:?array} $result
   */
  private function recordOrClearBlocklistFromResult(string $listingId, array $result): void {
    $status = $result['status'] ?? '';
    if ($status === 'migrated' || $status === 'already_migrated') {
      $this->blocklistService->clearFailure($listingId);
      return;
    }

    if ($status !== 'failed_migration') {
      return;
    }

    $errorMessage = $this->extractFirstErrorMessage($result['migrate_response'] ?? NULL) ?? 'Legacy migration failed.';
    $this->blocklistService->recordFailure($listingId, $errorMessage);
  }

  private function extractFirstErrorMessage(?array $migrateResponse): ?string {
    if (!is_array($migrateResponse)) {
      return NULL;
    }

    $responses = $migrateResponse['responses'] ?? NULL;
    if (!is_array($responses)) {
      return NULL;
    }

    foreach ($responses as $response) {
      if (!is_array($response)) {
        continue;
      }

      $errors = $response['errors'] ?? NULL;
      if (!is_array($errors) || $errors === []) {
        continue;
      }

      $firstError = $errors[0] ?? NULL;
      if (!is_array($firstError)) {
        continue;
      }

      $message = $firstError['message'] ?? NULL;
      if (is_string($message) && trim($message) !== '') {
        return trim($message);
      }
    }

    return NULL;
  }

  private function formatElapsed(float $seconds): string {
    $totalSeconds = (int) round($seconds);
    $minutes = intdiv($totalSeconds, 60);
    $remainingSeconds = $totalSeconds % 60;

    if ($minutes === 0) {
      return sprintf('%ds', $remainingSeconds);
    }

    return sprintf('%dm%02ds', $minutes, $remainingSeconds);
  }

}
