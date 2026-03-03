<?php

declare(strict_types=1);

namespace Drupal\Tests\bb_ebay_mirror\Kernel;

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
 * Tests the eBay offer mirror sync service.
 *
 * What this is testing:
 * this service reads offers for the mirrored inventory SKUs of one account
 * and writes those offers into the local mirror table.
 *
 * Why this is a kernel test:
 * we want real Drupal database writes, but fake eBay responses. So the test
 * uses the real sync service and the real SellApiClient with a recorded fake
 * HTTP client underneath.
 */
final class EbayOfferMirrorSyncServiceTest extends KernelTestBase {

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
    $this->installSchema('bb_ebay_mirror', ['bb_ebay_inventory_item', 'bb_ebay_offer']);

    $this->httpClient = new RecordedHttpClient();
  }

  public function testSyncAllCreatesMirrorRowsForOffers(): void {
    $account = $this->createAccount();
    $this->seedMirroredInventorySku((int) $account->id(), 'sku-1');
    $this->seedMirroredInventorySku((int) $account->id(), 'sku-2');
    $syncService = $this->createSyncService();

    $this->httpClient->queueJsonResponse([
      'offers' => [
        [
          'offerId' => 'offer-1',
          'sku' => 'sku-1',
          'marketplaceId' => 'EBAY_AU',
          'format' => 'FIXED_PRICE',
          'listingDescription' => 'A short listing description.',
          'availableQuantity' => 1,
          'pricingSummary' => [
            'price' => [
              'value' => '29.95',
              'currency' => 'AUD',
            ],
          ],
          'listingPolicies' => [
            'paymentPolicyId' => 'pay-1',
            'returnPolicyId' => 'return-1',
            'fulfillmentPolicyId' => 'ship-1',
            'eBayPlusIfEligible' => TRUE,
          ],
          'categoryId' => '1234',
          'merchantLocationKey' => 'BRNCBD004',
          'tax' => [
            'applyTax' => FALSE,
          ],
          'listing' => [
            'listingId' => 'listing-1',
            'listingStatus' => 'ACTIVE',
            'soldQuantity' => 2,
          ],
          'status' => 'PUBLISHED',
          'listingDuration' => 'GTC',
          'includeCatalogProductDetails' => FALSE,
          'hideBuyerDetails' => FALSE,
        ],
      ],
    ]);
    $this->httpClient->queueJsonResponse(['offers' => []]);

    $results = $syncService->syncAll($account);

    $this->assertSame(['skus' => 2, 'offers_seen' => 1, 'offers_upserted' => 1], $results);

    $row = $this->container->get('database')
      ->select('bb_ebay_offer', 'o')
      ->fields('o')
      ->condition('account_id', (int) $account->id())
      ->condition('offer_id', 'offer-1')
      ->execute()
      ->fetchObject();

    $this->assertNotFalse($row);
    $this->assertSame('sku-1', $row->sku);
    $this->assertSame('EBAY_AU', $row->marketplace_id);
    $this->assertSame('FIXED_PRICE', $row->format);
    $this->assertSame('A short listing description.', $row->listing_description);
    $this->assertSame('29.95', $row->price_value);
    $this->assertSame('AUD', $row->price_currency);
    $this->assertSame('listing-1', $row->listing_id);
    $this->assertSame('ACTIVE', $row->listing_status);
    $this->assertSame('PUBLISHED', $row->status);
    $this->assertStringContainsString('"offerId":"offer-1"', (string) $row->raw_json);
    $this->assertGreaterThan(0, (int) $row->last_seen);
  }

  public function testSyncAllUpdatesAnExistingOfferRow(): void {
    $account = $this->createAccount();
    $this->seedMirroredInventorySku((int) $account->id(), 'sku-1');
    $syncService = $this->createSyncService();

    $this->httpClient->queueJsonResponse([
      'offers' => [
        [
          'offerId' => 'offer-1',
          'sku' => 'sku-1',
          'pricingSummary' => [
            'price' => [
              'value' => '19.95',
            ],
          ],
          'status' => 'PUBLISHED',
        ],
      ],
    ]);
    $syncService->syncAll($account);

    $this->httpClient->queueJsonResponse([
      'offers' => [
        [
          'offerId' => 'offer-1',
          'sku' => 'sku-1',
          'pricingSummary' => [
            'price' => [
              'value' => '24.95',
            ],
          ],
          'status' => 'ENDED',
        ],
      ],
    ]);
    $syncService->syncAll($account);

    $row = $this->container->get('database')
      ->select('bb_ebay_offer', 'o')
      ->fields('o')
      ->condition('account_id', (int) $account->id())
      ->condition('offer_id', 'offer-1')
      ->execute()
      ->fetchObject();

    $this->assertNotFalse($row);
    $this->assertSame('24.95', $row->price_value);
    $this->assertSame('ENDED', $row->status);
  }

  public function testSyncAllDeletesOfferRowsThatWereNotSeen(): void {
    $account = $this->createAccount();
    $this->seedMirroredInventorySku((int) $account->id(), 'sku-1');
    $syncService = $this->createSyncService();

    $this->httpClient->queueJsonResponse([
      'offers' => [
        [
          'offerId' => 'offer-keep',
          'sku' => 'sku-1',
          'status' => 'PUBLISHED',
        ],
      ],
    ]);
    $syncService->syncAll($account);

    $this->container->get('database')->insert('bb_ebay_offer')
      ->fields([
        'account_id' => (int) $account->id(),
        'offer_id' => 'offer-stale',
        'sku' => 'sku-1',
        'status' => 'UNPUBLISHED',
        'last_seen' => time() - 100,
      ])
      ->execute();

    $this->httpClient->queueJsonResponse([
      'offers' => [
        [
          'offerId' => 'offer-keep',
          'sku' => 'sku-1',
          'status' => 'PUBLISHED',
        ],
      ],
    ]);
    $syncService->syncAll($account);

    $offerIds = $this->container->get('database')
      ->select('bb_ebay_offer', 'o')
      ->fields('o', ['offer_id'])
      ->condition('account_id', (int) $account->id())
      ->orderBy('offer_id')
      ->execute()
      ->fetchCol();

    $this->assertSame(['offer-keep'], $offerIds);
  }

  private function createAccount(): EbayAccount {
    $user = User::create([
      'name' => 'mirror-offer-test-user',
    ]);
    $user->save();

    $account = EbayAccount::create([
      'label' => 'Mirror Offer Test Account',
      'uid' => $user->id(),
      'environment' => 'production',
      'access_token' => 'test-access-token',
      'refresh_token' => 'test-refresh-token',
      'expires_at' => time() + 3600,
    ]);
    $account->save();

    return $account;
  }

  private function seedMirroredInventorySku(int $accountId, string $sku): void {
    $this->container->get('database')->insert('bb_ebay_inventory_item')
      ->fields([
        'account_id' => $accountId,
        'sku' => $sku,
        'title' => 'Seeded title',
        'last_seen' => time(),
      ])
      ->execute();
  }

  private function createSyncService(): EbayOfferMirrorSyncService {
    $accountManager = new EbayAccountManager(
      $this->container->get('entity_type.manager'),
      new OAuthTokenService(
        $this->createMock(ClientInterface::class),
        $this->createMock(ConfigFactoryInterface::class),
      ),
    );

    $sellApiClient = new SellApiClient($this->httpClient, $accountManager);

    return new EbayOfferMirrorSyncService(
      $sellApiClient,
      $this->container->get('database'),
    );
  }

}
