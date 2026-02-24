<?php

declare(strict_types=1);

namespace Drupal\listing_publishing\Model;

final class MarketplacePublishResult {

  public function __construct(
    private readonly bool $success,
    private readonly string $message,
    private readonly ?string $marketplaceId = null,
  ) {}

  public function isSuccess(): bool {
    return $this->success;
  }

  public function getMessage(): string {
    return $this->message;
  }

  public function getMarketplaceId(): ?string {
    return $this->marketplaceId;
  }

}
