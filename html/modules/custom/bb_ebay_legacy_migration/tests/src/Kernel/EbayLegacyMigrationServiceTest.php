<?php

declare(strict_types=1);

namespace Drupal\Tests\bb_ebay_legacy_migration\Kernel;

use Drupal\bb_ebay_legacy_migration\Service\EbayLegacyMigrationService;
use Drupal\bb_ebay_mirror\Service\EbayInventoryMirrorSyncService;
use Drupal\bb_ebay_mirror\Service\EbayOfferMirrorSyncService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\ebay_connector\Entity\EbayAccount;
use Drupal\ebay_infrastructure\Service\EbayAccountManager;
use Drupal\ebay_infrastructure\Service\OAuthTokenService;
use Drupal\ebay_infrastructure\Service\SellApiClient;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\ebay_infrastructure\Support\RecordedHttpClient;
use Drupal\user\Entity\User;
use GuzzleHttp\ClientInterface;

/**
 * Tests the legacy eBay migration service.
 *
 * What this is for:
 * old eBay listings can be moved into the Sell Inventory model by calling
 * eBay's bulk migrate API. After each migrate chunk, we resync the local
 * eBay mirror so we can inspect the result without guessing.
 *
 * Why this is a kernel test:
 * we want real Drupal database writes into the mirror tables, but no real
 * eBay calls. So this test uses the real migration service and the real
 * mirror sync services, with a fake recorded HTTP client underneath.
 */
final class EbayLegacyMigrationServiceTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'options',
    'bb_platform',
    'ebay_infrastructure',
    'ebay_connector',
    'bb_ebay_mirror',
    'bb_ebay_legacy_migration',
  ];

  private RecordedHttpClient $httpClient;

  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('ebay_account');
    $this->installSchema('bb_ebay_mirror', ['bb_ebay_inventory_item', 'bb_ebay_offer']);

    $this->httpClient = new RecordedHttpClient();
  }

  public function testMigrateListingIdsChunksAtFiveAndResyncsMirror(): void {
    $service = $this->createMigrationService();
    $this->createPrimaryAccount();

    // First migrate chunk.
    $this->httpClient->queueJsonResponse([
      'responses' => [
        ['listingId' => '176577811710', 'statusCode' => 200],
        ['listingId' => '176582430935', 'statusCode' => 200],
        ['listingId' => '176604590528', 'statusCode' => 200],
        ['listingId' => '176604596280', 'statusCode' => 200],
        ['listingId' => '176779515895', 'statusCode' => 200],
      ],
    ]);
    $this->queueMirrorSyncResponses('sku-pilot-1', 'offer-pilot-1');

    // Second migrate chunk.
    $this->httpClient->queueJsonResponse([
      'responses' => [
        ['listingId' => '176800000001', 'statusCode' => 200],
      ],
    ]);
    $this->queueMirrorSyncResponses('sku-pilot-2', 'offer-pilot-2');

    $results = $service->migrateListingIds([
      '176577811710',
      '176582430935',
      '176604590528',
      '176604596280',
      '176779515895',
      '176800000001',
    ]);

    $this->assertCount(2, $results);
    $this->assertSame([
      '176577811710',
      '176582430935',
      '176604590528',
      '176604596280',
      '176779515895',
    ], $results[0]['listing_ids']);
    $this->assertSame(['176800000001'], $results[1]['listing_ids']);

    $migratePayloads = $this->findJsonPayloadsByPath('POST', '/sell/inventory/v1/bulk_migrate_listing');
    $this->assertCount(2, $migratePayloads);
    $this->assertSame([
      ['listingId' => '176577811710'],
      ['listingId' => '176582430935'],
      ['listingId' => '176604590528'],
      ['listingId' => '176604596280'],
      ['listingId' => '176779515895'],
    ], $migratePayloads[0]['requests']);
    $this->assertSame([
      ['listingId' => '176800000001'],
    ], $migratePayloads[1]['requests']);

    // The mirror should now hold the rows from the second reconcile run.
    $inventorySkus = $this->container->get('database')
      ->select('bb_ebay_inventory_item', 'i')
      ->fields('i', ['sku'])
      ->orderBy('sku')
      ->execute()
      ->fetchCol();
    $offerIds = $this->container->get('database')
      ->select('bb_ebay_offer', 'o')
      ->fields('o', ['offer_id'])
      ->orderBy('offer_id')
      ->execute()
      ->fetchCol();

    $this->assertSame(['sku-pilot-2'], $inventorySkus);
    $this->assertSame(['offer-pilot-2'], $offerIds);
  }

  private function queueMirrorSyncResponses(string $sku, string $offerId): void {
    // Inventory sync after this migrate chunk.
    $this->httpClient->queueJsonResponse([
      'inventoryItems' => [
        [
          'sku' => $sku,
          'product' => [
            'title' => 'Migrated listing for ' . $sku,
          ],
        ],
      ],
    ]);

    // Offer sync after this migrate chunk.
    $this->httpClient->queueJsonResponse([
      'offers' => [
        [
          'offerId' => $offerId,
          'sku' => $sku,
          'status' => 'UNPUBLISHED',
        ],
      ],
    ]);
  }

  private function createPrimaryAccount(): EbayAccount {
    $user = User::create([
      'name' => 'legacy-migration-test-user',
    ]);
    $user->save();

    $account = EbayAccount::create([
      'label' => 'Primary Test Account',
      'uid' => $user->id(),
      'environment' => 'production',
      'access_token' => 'test-access-token',
      'refresh_token' => 'test-refresh-token',
      'expires_at' => time() + 3600,
    ]);
    $account->save();

    return $account;
  }

  private function createMigrationService(): EbayLegacyMigrationService {
    $accountManager = new EbayAccountManager(
      $this->container->get('entity_type.manager'),
      new OAuthTokenService(
        $this->createMock(ClientInterface::class),
        $this->createMock(ConfigFactoryInterface::class),
      ),
    );

    $sellApiClient = new SellApiClient($this->httpClient, $accountManager);
    $inventorySyncService = new EbayInventoryMirrorSyncService(
      $sellApiClient,
      $this->container->get('database'),
    );
    $offerSyncService = new EbayOfferMirrorSyncService(
      $sellApiClient,
      $this->container->get('database'),
    );

    return new EbayLegacyMigrationService(
      $sellApiClient,
      $accountManager,
      $inventorySyncService,
      $offerSyncService,
    );
  }

  /**
   * @return array<int,array>
   */
  private function findJsonPayloadsByPath(string $method, string $exactPath): array {
    $payloads = [];

    foreach ($this->httpClient->getRequests() as $request) {
      if ($request['method'] !== $method) {
        continue;
      }

      $path = parse_url($request['url'], PHP_URL_PATH);
      if ($path !== $exactPath) {
        continue;
      }

      $payloads[] = $request['options']['json'] ?? [];
    }

    return $payloads;
  }

}
