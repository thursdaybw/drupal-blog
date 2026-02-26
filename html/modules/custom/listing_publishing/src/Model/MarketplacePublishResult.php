<?php

declare(strict_types=1);

namespace Drupal\listing_publishing\Model;

final class MarketplacePublishResult {

  public function __construct(
    private readonly bool $success,
    private readonly string $message,
    private readonly ?string $marketplaceListingId = null,
    private readonly ?string $marketplacePublicationId = null,
    private readonly ?string $publicationType = null,
  ) {}

  public function isSuccess(): bool {
    return $this->success;
  }

  public function getMessage(): string {
    return $this->message;
  }

  public function getMarketplaceId(): ?string {
    return $this->marketplaceListingId;
  }

  public function getMarketplaceListingId(): ?string {
    return $this->marketplaceListingId;
  }

  public function getMarketplacePublicationId(): ?string {
    return $this->marketplacePublicationId;
  }

  public function getPublicationType(): ?string {
    return $this->publicationType;
  }

}
