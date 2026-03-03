<?php

declare(strict_types=1);

namespace Drupal\Tests\bb_ebay_mirror\Kernel;

use Drupal\bb_ebay_mirror\Service\EbayInventoryMirrorSyncService;
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
 * Tests the eBay inventory mirror sync service.
 *
 * What this is testing:
 * this service reads inventory items from eBay and writes them into the local
 * mirror table. The mirror gives us a local copy we can audit later without
 * calling eBay every time.
 *
 * Why this is a kernel test:
 * we want real Drupal database writes into the mirror table, but we do not
 * want real eBay API calls. So the test uses the real sync service and the
 * real SellApiClient, with a fake recorded HTTP client underneath.
 */
final class EbayInventoryMirrorSyncServiceTest extends KernelTestBase {

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
  ];

  private RecordedHttpClient $httpClient;

  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('ebay_account');
    $this->installSchema('bb_ebay_mirror', ['bb_ebay_inventory_item']);

    $this->httpClient = new RecordedHttpClient();
  }

  public function testSyncAllCreatesMirrorRowsForInventoryItems(): void {
    $account = $this->createAccount();
    $syncService = $this->createSyncService();

    $this->httpClient->queueJsonResponse([
      'inventoryItems' => [
        [
          'sku' => 'sku-1',
          'locale' => 'en_AU',
          'product' => [
            'title' => 'Birdy',
            'description' => 'A short description.',
            'aspects' => [
              'Book Title' => ['Birdy'],
            ],
            'imageUrls' => [
              'https://images.example.com/birdy.jpg',
            ],
          ],
          'condition' => 'USED_GOOD',
          'conditionDescription' => 'Clean copy with light edge wear.',
          'availability' => [
            'shipToLocationAvailability' => [
              'quantity' => 1,
            ],
          ],
        ],
        [
          'sku' => 'sku-2',
          'product' => [
            'title' => 'Second Book',
          ],
        ],
      ],
    ]);

    $results = $syncService->syncAll($account, 100);

    $this->assertSame(['pages' => 1, 'seen' => 2, 'upserted' => 2], $results);

    $rows = $this->container->get('database')
      ->select('bb_ebay_inventory_item', 'i')
      ->fields('i')
      ->orderBy('sku')
      ->execute()
      ->fetchAllAssoc('sku');

    $this->assertCount(2, $rows);
    $this->assertSame('1', (string) $rows['sku-1']->account_id);
    $this->assertSame('Birdy', $rows['sku-1']->title);
    $this->assertSame('USED_GOOD', $rows['sku-1']->condition);
    $this->assertSame('1', (string) $rows['sku-1']->available_quantity);
    $this->assertStringContainsString('"Book Title":["Birdy"]', (string) $rows['sku-1']->aspects_json);
    $this->assertStringContainsString('birdy.jpg', (string) $rows['sku-1']->image_urls_json);
    $this->assertStringContainsString('"sku":"sku-1"', (string) $rows['sku-1']->raw_json);
    $this->assertGreaterThan(0, (int) $rows['sku-1']->last_seen);
  }

  public function testSyncAllUpdatesAnExistingMirrorRow(): void {
    $account = $this->createAccount();
    $syncService = $this->createSyncService();

    $this->httpClient->queueJsonResponse([
      'inventoryItems' => [
        [
          'sku' => 'sku-1',
          'product' => [
            'title' => 'Original title',
          ],
          'availability' => [
            'shipToLocationAvailability' => [
              'quantity' => 1,
            ],
          ],
        ],
      ],
    ]);
    $syncService->syncAll($account, 100);

    $this->httpClient->queueJsonResponse([
      'inventoryItems' => [
        [
          'sku' => 'sku-1',
          'product' => [
            'title' => 'Updated title',
          ],
          'availability' => [
            'shipToLocationAvailability' => [
              'quantity' => 3,
            ],
          ],
        ],
      ],
    ]);
    $syncService->syncAll($account, 100);

    $row = $this->container->get('database')
      ->select('bb_ebay_inventory_item', 'i')
      ->fields('i')
      ->condition('account_id', (int) $account->id())
      ->condition('sku', 'sku-1')
      ->execute()
      ->fetchObject();

    $this->assertNotFalse($row);
    $this->assertSame('Updated title', $row->title);
    $this->assertSame('3', (string) $row->available_quantity);
  }

  public function testSyncAllDeletesMirrorRowsThatWereNotSeen(): void {
    $account = $this->createAccount();
    $syncService = $this->createSyncService();

    $this->httpClient->queueJsonResponse([
      'inventoryItems' => [
        [
          'sku' => 'sku-keep',
          'product' => [
            'title' => 'Keep me',
          ],
        ],
      ],
    ]);
    $syncService->syncAll($account, 100);

    $this->container->get('database')->insert('bb_ebay_inventory_item')
      ->fields([
        'account_id' => (int) $account->id(),
        'sku' => 'sku-stale',
        'title' => 'Stale row',
        'last_seen' => time() - 100,
      ])
      ->execute();

    $this->httpClient->queueJsonResponse([
      'inventoryItems' => [
        [
          'sku' => 'sku-keep',
          'product' => [
            'title' => 'Keep me updated',
          ],
        ],
      ],
    ]);
    $syncService->syncAll($account, 100);

    $skus = $this->container->get('database')
      ->select('bb_ebay_inventory_item', 'i')
      ->fields('i', ['sku'])
      ->condition('account_id', (int) $account->id())
      ->orderBy('sku')
      ->execute()
      ->fetchCol();

    $this->assertSame(['sku-keep'], $skus);
  }

  private function createAccount(): EbayAccount {
    $user = User::create([
      'name' => 'mirror-test-user',
    ]);
    $user->save();

    $account = EbayAccount::create([
      'label' => 'Mirror Test Account',
      'uid' => $user->id(),
      'environment' => 'production',
      'access_token' => 'test-access-token',
      'refresh_token' => 'test-refresh-token',
      'expires_at' => time() + 3600,
    ]);
    $account->save();

    return $account;
  }

  private function createSyncService(): EbayInventoryMirrorSyncService {
    $accountManager = new EbayAccountManager(
      $this->container->get('entity_type.manager'),
      new OAuthTokenService(
        $this->createMock(ClientInterface::class),
        $this->createMock(ConfigFactoryInterface::class),
      ),
    );

    $sellApiClient = new SellApiClient($this->httpClient, $accountManager);

    return new EbayInventoryMirrorSyncService(
      $sellApiClient,
      $this->container->get('database'),
    );
  }

}
