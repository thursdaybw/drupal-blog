<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Model;

/**
 * Summary of a stacked listing cull action.
 */
final class ListingCullResult {

  /**
   * @param string[] $marketplaces
   */
  public function __construct(
    public readonly int $unpublishedCount,
    public readonly array $marketplaces,
  ) {}

}
