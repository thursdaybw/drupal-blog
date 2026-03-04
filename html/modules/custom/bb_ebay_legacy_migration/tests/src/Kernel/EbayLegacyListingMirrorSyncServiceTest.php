<?php

declare(strict_types=1);

namespace Drupal\Tests\bb_ebay_legacy_migration\Kernel;

use Drupal\bb_ebay_legacy_migration\Service\EbayLegacyListingMirrorSyncService;
use Drupal\bb_ebay_legacy_migration\Service\EbayTradingLegacyClient;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\ebay_connector\Entity\EbayAccount;
use Drupal\ebay_infrastructure\Service\EbayAccountManager;
use Drupal\ebay_infrastructure\Service\OAuthTokenService;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\ebay_infrastructure\Support\RecordedHttpClient;
use Drupal\user\Entity\User;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;

/**
 * Tests syncing the legacy listing mirror from the Trading API.
 *
 * What this proves:
 * - active legacy listings can be mirrored into a local table
 * - rows are updated when the same listing is seen again
 * - rows that disappear from the active legacy set are deleted
 *
 * Why this is a kernel test:
 * the value here is the database write behaviour. We use the real sync service
 * and the real table, but a fake HTTP client underneath.
 */
final class EbayLegacyListingMirrorSyncServiceTest extends KernelTestBase {

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
    'bb_ebay_legacy_migration',
  ];

  private RecordedHttpClient $httpClient;

  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('ebay_account');
    $this->installSchema('bb_ebay_legacy_migration', ['bb_ebay_legacy_listing']);

    $this->httpClient = new RecordedHttpClient();
  }

  public function testSyncCreatesUpdatesAndDeletesLegacyMirrorRows(): void {
    $service = $this->createSyncService();
    $account = $this->createPrimaryAccount();

    $this->httpClient->queueResponse(new Response(200, ['Content-Type' => 'text/xml'], $this->buildActiveListingsResponse([
      [
        'ItemID' => '176582430935',
        'SKU' => '2024 September A01',
        'Title' => 'Official AFL NAB AusKick 20 Yr T-Shirt - 2015 Celebration - Size L - Great Cond',
        'StartTime' => '2024-09-15T13:15:00.000Z',
        'ListingStatus' => 'Active',
        'PrimaryCategoryID' => '260994',
      ],
      [
        'ItemID' => '176779515895',
        'SKU' => 'brn-cbrd-DVDWB01 - 2025-08-01 002',
        'Title' => 'Sci-Fi & Action DVD Lot',
        'StartTime' => '2025-08-01T12:00:00.000Z',
        'ListingStatus' => 'Active',
        'PrimaryCategoryID' => '617',
      ],
    ], 1)));

    $firstResult = $service->syncAccount($account);
    $this->assertSame(2, $firstResult['synced_count']);
    $this->assertSame(0, $firstResult['deleted_count']);

    $rows = $this->loadLegacyRows();
    $this->assertCount(2, $rows);
    $this->assertSame('Official AFL NAB AusKick 20 Yr T-Shirt - 2015 Celebration - Size L - Great Cond', $rows['176582430935']->title);
    $this->assertSame('2024 September A01', $rows['176582430935']->sku);

    // Second sync changes the title for one listing and drops the other one.
    $this->httpClient->queueResponse(new Response(200, ['Content-Type' => 'text/xml'], $this->buildActiveListingsResponse([
      [
        'ItemID' => '176582430935',
        'SKU' => '2024 September A01',
        'Title' => 'Official AFL NAB AusKick 20 Yr T-Shirt Updated',
        'StartTime' => '2024-09-15T13:15:00.000Z',
        'ListingStatus' => 'Active',
        'PrimaryCategoryID' => '260994',
      ],
    ], 1)));

    $secondResult = $service->syncAccount($account);
    $this->assertSame(1, $secondResult['synced_count']);
    $this->assertSame(1, $secondResult['deleted_count']);

    $rows = $this->loadLegacyRows();
    $this->assertCount(1, $rows);
    $this->assertSame('Official AFL NAB AusKick 20 Yr T-Shirt Updated', $rows['176582430935']->title);
    $this->assertArrayNotHasKey('176779515895', $rows);
  }

  private function createPrimaryAccount(): EbayAccount {
    $user = User::create([
      'name' => 'legacy-listing-mirror-test-user',
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

  private function createSyncService(): EbayLegacyListingMirrorSyncService {
    $accountManager = new EbayAccountManager(
      $this->container->get('entity_type.manager'),
      new OAuthTokenService(
        $this->createMock(ClientInterface::class),
        $this->createMock(ConfigFactoryInterface::class),
      ),
    );

    $tradingClient = new EbayTradingLegacyClient(
      $this->httpClient,
      $accountManager,
    );

    return new EbayLegacyListingMirrorSyncService(
      $tradingClient,
      $this->container->get('database'),
    );
  }

  /**
   * @return array<string,object>
   */
  private function loadLegacyRows(): array {
    $records = $this->container->get('database')
      ->select('bb_ebay_legacy_listing', 'l')
      ->fields('l')
      ->execute()
      ->fetchAllAssoc('ebay_listing_id');

    return is_array($records) ? $records : [];
  }

  /**
   * @param array<int,array<string,string>> $items
   */
  private function buildActiveListingsResponse(array $items, int $totalPages): string {
    $itemXml = '';
    foreach ($items as $item) {
      $itemXml .= '<Item>'
        . '<ItemID>' . htmlspecialchars($item['ItemID'], ENT_XML1) . '</ItemID>'
        . '<SKU>' . htmlspecialchars($item['SKU'], ENT_XML1) . '</SKU>'
        . '<Title>' . htmlspecialchars($item['Title'], ENT_XML1) . '</Title>'
        . '<ListingDetails><StartTime>' . htmlspecialchars($item['StartTime'], ENT_XML1) . '</StartTime></ListingDetails>'
        . '<SellingStatus><ListingStatus>' . htmlspecialchars($item['ListingStatus'], ENT_XML1) . '</ListingStatus></SellingStatus>'
        . '<PrimaryCategory><CategoryID>' . htmlspecialchars($item['PrimaryCategoryID'], ENT_XML1) . '</CategoryID></PrimaryCategory>'
        . '</Item>';
    }

    return '<?xml version="1.0" encoding="utf-8"?>'
      . '<GetMyeBaySellingResponse xmlns="urn:ebay:apis:eBLBaseComponents">'
      . '<Ack>Success</Ack>'
      . '<ActiveList>'
      . '<PaginationResult><TotalNumberOfPages>' . $totalPages . '</TotalNumberOfPages></PaginationResult>'
      . '<ItemArray>'
      . $itemXml
      . '</ItemArray>'
      . '</ActiveList>'
      . '</GetMyeBaySellingResponse>';
  }

}
