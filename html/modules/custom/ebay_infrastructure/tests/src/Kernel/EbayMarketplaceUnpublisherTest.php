<?php

declare(strict_types=1);

namespace Drupal\Tests\ebay_infrastructure\Kernel;

use Drupal\bb_ebay_mirror\Service\EbayInventoryMirrorSyncService;
use Drupal\bb_ebay_mirror\Service\EbayOfferMirrorSyncService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\ebay_connector\Entity\EbayAccount;
use Drupal\ebay_infrastructure\Service\EbayAccountManager;
use Drupal\ebay_infrastructure\Service\EbayMarketplaceUnpublisher;
use Drupal\ebay_infrastructure\Service\EbaySkuRemovalService;
use Drupal\ebay_infrastructure\Service\OAuthTokenService;
use Drupal\ebay_infrastructure\Service\SellApiClient;
use Drupal\Tests\ebay_infrastructure\Support\RecordedHttpClient;
use Drupal\KernelTests\KernelTestBase;
use Drupal\listing_publishing\Exception\MarketplaceAlreadyUnpublishedException;
use Drupal\listing_publishing\Model\MarketplaceUnpublishRequest;
use Drupal\user\Entity\User;
use GuzzleHttp\ClientInterface;

final class EbayMarketplaceUnpublisherTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'options',
    'bb_platform',
    'ai_listing',
    'listing_publishing',
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

    $user = User::create(['name' => 'ebay-unpublish-test-user']);
    $user->save();

    EbayAccount::create([
      'label' => 'Primary Test Account',
      'uid' => $user->id(),
      'environment' => 'production',
      'access_token' => 'test-access-token',
      'refresh_token' => 'test-refresh-token',
      'expires_at' => time() + 3600,
    ])->save();
  }

  public function testAlreadyUnpublishedStillDeletesMirrorRows(): void {
    $unpublisher = $this->createUnpublisher();

    $this->container->get('database')->insert('bb_ebay_inventory_item')
      ->fields([
        'account_id' => 1,
        'sku' => 'sku-gone',
        'title' => 'Stale title',
        'last_seen' => time(),
      ])
      ->execute();
    $this->container->get('database')->insert('bb_ebay_offer')
      ->fields([
        'account_id' => 1,
        'offer_id' => 'offer-gone',
        'sku' => 'sku-gone',
        'status' => 'PUBLISHED',
        'last_seen' => time(),
      ])
      ->execute();

    $this->httpClient->queueJsonResponse([
      'errors' => [['errorId' => 25713, 'message' => 'Offer not found.']],
    ], 404);
    $this->httpClient->queueJsonResponse([
      'errors' => [['errorId' => 25710, 'message' => 'Inventory item not found.']],
    ], 404);

    $this->expectException(MarketplaceAlreadyUnpublishedException::class);
    try {
      $unpublisher->unpublish(new MarketplaceUnpublishRequest(
        publicationId: 1,
        marketplaceKey: 'ebay',
        sku: 'sku-gone',
        marketplacePublicationId: 'publication-gone',
        marketplaceListingId: 'listing-gone',
      ));
    }
    finally {
      $inventoryCount = $this->container->get('database')
        ->select('bb_ebay_inventory_item', 'i')
        ->condition('account_id', 1)
        ->condition('sku', 'sku-gone')
        ->countQuery()
        ->execute()
        ->fetchField();
      $offerCount = $this->container->get('database')
        ->select('bb_ebay_offer', 'o')
        ->condition('account_id', 1)
        ->condition('sku', 'sku-gone')
        ->countQuery()
        ->execute()
        ->fetchField();

      $this->assertSame('0', (string) $inventoryCount);
      $this->assertSame('0', (string) $offerCount);
    }
  }

  private function createUnpublisher(): EbayMarketplaceUnpublisher {
    $accountManager = new EbayAccountManager(
      $this->container->get('entity_type.manager'),
      new OAuthTokenService(
        $this->createMock(ClientInterface::class),
        $this->createMock(ConfigFactoryInterface::class),
      ),
    );

    $sellApiClient = new SellApiClient($this->httpClient, $accountManager);
    $inventoryMirrorSyncService = new EbayInventoryMirrorSyncService(
      $sellApiClient,
      $this->container->get('database'),
    );
    $offerMirrorSyncService = new EbayOfferMirrorSyncService(
      $sellApiClient,
      $this->container->get('database'),
    );
    $skuRemovalService = new EbaySkuRemovalService(
      $sellApiClient,
      $accountManager,
      $inventoryMirrorSyncService,
      $offerMirrorSyncService,
    );

    return new EbayMarketplaceUnpublisher($skuRemovalService);
  }

}
