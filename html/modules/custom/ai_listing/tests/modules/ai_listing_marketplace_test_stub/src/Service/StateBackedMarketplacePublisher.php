<?php

declare(strict_types=1);

namespace Drupal\ai_listing_marketplace_test_stub\Service;

use Drupal\Core\State\StateInterface;
use Drupal\listing_publishing\Contract\MarketplacePublisherInterface;
use Drupal\listing_publishing\Model\ListingPublishRequest;
use Drupal\listing_publishing\Model\MarketplacePublishResult;

final class StateBackedMarketplacePublisher implements MarketplacePublisherInterface {

  private const STATE_KEY = 'ai_listing_marketplace_test_stub.publications';

  public function __construct(
    private readonly StateInterface $state,
  ) {}

  public function publish(ListingPublishRequest $request): MarketplacePublishResult {
    $publications = $this->loadPublications();
    $sequence = count($publications) + 1;

    $publications[$request->getSku()] = [
      'sku' => $request->getSku(),
      'title' => $request->getTitle(),
      'description' => $request->getDescription(),
      'price' => $request->getPrice(),
      'quantity' => $request->getQuantity(),
      'condition' => $request->getCondition(),
      'image_url_count' => count($request->getImageUrls()),
      'published_at' => time(),
      'marketplace_listing_id' => 'stub-listing-' . $sequence,
      'marketplace_publication_id' => 'stub-publication-' . $sequence,
      'publication_type' => 'FIXED_PRICE',
    ];

    $this->state->set(self::STATE_KEY, $publications);

    return new MarketplacePublishResult(
      TRUE,
      'Published via marketplace test stub.',
      $publications[$request->getSku()]['marketplace_listing_id'],
      $publications[$request->getSku()]['marketplace_publication_id'],
      $publications[$request->getSku()]['publication_type'],
    );
  }

  public function updatePublication(
    string $marketplacePublicationId,
    ListingPublishRequest $request,
    ?string $publicationType = null,
  ): MarketplacePublishResult {
    $publications = $this->loadPublications();
    $existing = $publications[$request->getSku()] ?? [];
    $listingId = (string) ($existing['marketplace_listing_id'] ?? ('stub-listing-update-' . (count($publications) + 1)));
    $resolvedType = $publicationType ?: (string) ($existing['publication_type'] ?? 'FIXED_PRICE');

    $publications[$request->getSku()] = [
      'sku' => $request->getSku(),
      'title' => $request->getTitle(),
      'description' => $request->getDescription(),
      'price' => $request->getPrice(),
      'quantity' => $request->getQuantity(),
      'condition' => $request->getCondition(),
      'image_url_count' => count($request->getImageUrls()),
      'published_at' => time(),
      'marketplace_listing_id' => $listingId,
      'marketplace_publication_id' => $marketplacePublicationId,
      'publication_type' => $resolvedType,
    ];

    $this->state->set(self::STATE_KEY, $publications);

    return new MarketplacePublishResult(
      TRUE,
      'Updated via marketplace test stub.',
      $listingId,
      $marketplacePublicationId,
      $resolvedType,
    );
  }

  public function deleteSku(string $sku): void {
    $publications = $this->loadPublications();
    unset($publications[$sku]);
    $this->state->set(self::STATE_KEY, $publications);
  }

  public function getMarketplaceKey(): string {
    return 'ebay';
  }

  /**
   * @return array<string,array<string,mixed>>
   */
  private function loadPublications(): array {
    $stored = $this->state->get(self::STATE_KEY, []);
    return is_array($stored) ? $stored : [];
  }

}
