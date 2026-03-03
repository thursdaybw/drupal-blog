<?php

declare(strict_types=1);

namespace Drupal\bb_ebay_mirror\Controller;

use Drupal\bb_ebay_mirror\Service\EbayMirrorAuditService;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\ebay_connector\Entity\EbayAccount;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class EbayMirrorReportController extends ControllerBase {

  public function __construct(
    private readonly EbayMirrorAuditService $auditService,
    private readonly EntityTypeManagerInterface $bbEntityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('bb_ebay_mirror.audit_service'),
      $container->get('entity_type.manager'),
    );
  }

  public function build(): array {
    $account = $this->resolveAccount();
    $accountId = (int) $account->id();

    $missingInventoryRows = $this->auditService->findPublishedListingsMissingMirroredInventory($accountId);
    $missingOfferRows = $this->auditService->findPublishedListingsMissingMirroredOffer($accountId);
    $orphanedInventoryRows = $this->auditService->findMirroredInventoryMissingLocalListing($accountId);
    $orphanedOfferRows = $this->auditService->findMirroredOffersMissingLocalListing($accountId);
    $skuLinkMismatchRows = $this->auditService->findSkuLinkMismatches($accountId);

    $build = [];
    $build['summary'] = [
      '#type' => 'container',
      'title' => [
        '#markup' => '<h2>' . $this->t('Summary') . '</h2>',
      ],
      'items' => [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('Account: @label (@id)', [
            '@label' => (string) $account->label(),
            '@id' => (string) $account->id(),
          ]),
          $this->t('Mirrored inventory rows: @count', [
            '@count' => (string) $this->auditService->countMirroredInventoryRows($accountId),
          ]),
          $this->t('Mirrored offer rows: @count', [
            '@count' => (string) $this->auditService->countMirroredOfferRows($accountId),
          ]),
          $this->t('Local published listings missing mirrored inventory: @count', [
            '@count' => (string) count($missingInventoryRows),
          ]),
          $this->t('Local published listings missing mirrored offers: @count', [
            '@count' => (string) count($missingOfferRows),
          ]),
          $this->t('Mirrored inventory rows with no local published listing: @count', [
            '@count' => (string) count($orphanedInventoryRows),
          ]),
          $this->t('Mirrored offers with no local published listing: @count', [
            '@count' => (string) count($orphanedOfferRows),
          ]),
          $this->t('Mirrored SKU/link mismatches: @count', [
            '@count' => (string) count($skuLinkMismatchRows),
          ]),
        ],
      ],
    ];

    $build['missing_inventory'] = $this->buildLocalListingAuditTable(
      'Local Published Listings Missing Mirrored Inventory',
      $missingInventoryRows
    );
    $build['missing_offers'] = $this->buildLocalListingAuditTable(
      'Local Published Listings Missing Mirrored Offers',
      $missingOfferRows
    );
    $build['orphaned_inventory'] = $this->buildOrphanedInventoryTable($orphanedInventoryRows);
    $build['orphaned_offers'] = $this->buildOrphanedOfferTable($orphanedOfferRows);
    $build['sku_link_mismatch'] = $this->buildSkuLinkMismatchTable($skuLinkMismatchRows);

    return $build;
  }

  /**
   * @param array<int,array{listing_id:int,ebay_title:?string,storage_location:?string,sku:string,marketplace_listing_id:?string}> $rows
   */
  private function buildLocalListingAuditTable(string $title, array $rows): array {
    $header = [
      $this->t('Listing'),
      $this->t('Listing code'),
      $this->t('eBay title'),
      $this->t('Location'),
      $this->t('SKU'),
      $this->t('eBay listing ID'),
    ];

    $tableRows = [];

    foreach ($rows as $row) {
      $tableRows[] = [
        $this->buildListingLinkCell($row['listing_id']),
        $this->buildListingCodeCell((int) $row['listing_id']),
        $row['ebay_title'] ?? (string) $this->t('Untitled listing'),
        $row['storage_location'] ?? (string) $this->t('Unset'),
        $row['sku'],
        $row['marketplace_listing_id'] ?? (string) $this->t('Unknown'),
      ];
    }

    return $this->buildSectionTable($title, $header, $tableRows, 'No rows in this bucket.');
  }

  /**
   * @param array<int,array{sku:string,title:?string,available_quantity:?int,condition:?string}> $rows
   */
  private function buildOrphanedInventoryTable(array $rows): array {
    $header = [
      $this->t('SKU'),
      $this->t('eBay title'),
      $this->t('Quantity'),
      $this->t('Condition'),
    ];

    $tableRows = [];

    foreach ($rows as $row) {
      $tableRows[] = [
        $row['sku'],
        $row['title'] ?? (string) $this->t('Untitled inventory item'),
        $row['available_quantity'] === NULL ? (string) $this->t('Unknown') : (string) $row['available_quantity'],
        $row['condition'] ?? (string) $this->t('Unknown'),
      ];
    }

    return $this->buildSectionTable('Mirrored Inventory With No Local Published Listing', $header, $tableRows, 'No rows in this bucket.');
  }

  /**
   * @param array<int,array{offer_id:string,sku:string,listing_id:?string,listing_status:?string,status:?string}> $rows
   */
  private function buildOrphanedOfferTable(array $rows): array {
    $header = [
      $this->t('Offer ID'),
      $this->t('SKU'),
      $this->t('eBay listing ID'),
      $this->t('Listing status'),
      $this->t('Offer status'),
    ];

    $tableRows = [];

    foreach ($rows as $row) {
      $tableRows[] = [
        $row['offer_id'],
        $row['sku'],
        $row['listing_id'] ?? (string) $this->t('Unknown'),
        $row['listing_status'] ?? (string) $this->t('Unknown'),
        $row['status'] ?? (string) $this->t('Unknown'),
      ];
    }

    return $this->buildSectionTable('Mirrored Offers With No Local Published Listing', $header, $tableRows, 'No rows in this bucket.');
  }

  /**
   * @param array<int,array{
   *   sku:string,
   *   sku_identifier:?string,
   *   resolved_listing_id:?int,
   *   resolved_listing_code:?string,
   *   resolved_ebay_title:?string,
   *   publication_listing_id:?int,
   *   publication_marketplace_listing_id:?string,
   *   offer_id:?string,
   *   offer_status:?string,
   *   reason:string
   * }> $rows
   */
  private function buildSkuLinkMismatchTable(array $rows): array {
    $header = [
      $this->t('SKU'),
      $this->t('Identifier in SKU'),
      $this->t('Resolved local listing'),
      $this->t('Resolved listing code'),
      $this->t('Publication listing'),
      $this->t('Offer'),
      $this->t('Reason'),
    ];

    $tableRows = [];

    foreach ($rows as $row) {
      $tableRows[] = [
        $row['sku'],
        $row['sku_identifier'] ?? (string) $this->t('Unknown'),
        $row['resolved_listing_id'] === NULL ? (string) $this->t('Unknown') : $this->buildListingLinkCell($row['resolved_listing_id']),
        $row['resolved_listing_code'] ?? (string) $this->t('Unset'),
        $row['publication_listing_id'] === NULL ? (string) $this->t('None') : $this->buildListingLinkCell($row['publication_listing_id']),
        $row['offer_id'] ?? (string) $this->t('None'),
        str_replace('_', ' ', $row['reason']),
      ];
    }

    return $this->buildSectionTable('Mirrored SKU Link Mismatches', $header, $tableRows, 'No rows in this bucket.');
  }

  /**
   * @param array<int,array<int|string,\Drupal\Core\StringTranslation\TranslatableMarkup>> $rows
   */
  private function buildSectionTable(string $title, array $header, array $rows, string $emptyText): array {
    return [
      '#type' => 'container',
      'title' => [
        '#markup' => '<h2>' . $this->t($title) . '</h2>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t($emptyText),
      ],
    ];
  }

  private function buildListingLinkCell(int $listingId): string|\Drupal\Component\Render\MarkupInterface {
    $url = Url::fromRoute('entity.bb_ai_listing.canonical', [
      'bb_ai_listing' => $listingId,
    ]);

    return Link::fromTextAndUrl((string) $listingId, $url)->toString();
  }

  private function buildListingCodeCell(int $listingId): string {
    $listing = $this->bbEntityTypeManager
      ->getStorage('bb_ai_listing')
      ->load($listingId);

    if (!$listing instanceof \Drupal\ai_listing\Entity\BbAiListing) {
      return (string) $this->t('Unknown');
    }

    $listingCode = trim((string) ($listing->get('listing_code')->value ?? ''));
    if ($listingCode === '') {
      return (string) $this->t('Unset');
    }

    return $listingCode;
  }

  private function resolveAccount(): EbayAccount {
    $accounts = $this->bbEntityTypeManager
      ->getStorage('ebay_account')
      ->loadByProperties(['environment' => 'production']);

    $account = reset($accounts);
    if (!$account instanceof EbayAccount) {
      throw new \RuntimeException('No production eBay account found.');
    }

    return $account;
  }

}
