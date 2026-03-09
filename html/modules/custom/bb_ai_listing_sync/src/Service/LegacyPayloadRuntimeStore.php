<?php

declare(strict_types=1);

namespace Drupal\bb_ai_listing_sync\Service;

final class LegacyPayloadRuntimeStore {

  /**
   * @var array<string, array<string, mixed>>
   */
  private array $payloadByListingUuid = [];

  /**
   * @param array<string, mixed> $payload
   */
  public function setPayload(string $listingUuid, array $payload): void {
    if ($listingUuid === '') {
      return;
    }
    $this->payloadByListingUuid[$listingUuid] = $payload;
  }

  /**
   * @return array<string, mixed>|null
   */
  public function getPayload(string $listingUuid): ?array {
    if (!isset($this->payloadByListingUuid[$listingUuid])) {
      return NULL;
    }

    return $this->payloadByListingUuid[$listingUuid];
  }

  public function clearPayload(string $listingUuid): void {
    unset($this->payloadByListingUuid[$listingUuid]);
  }

}
