<?php

declare(strict_types=1);

namespace Drupal\ebay_connector\Service;

final class ConditionMapper {

  public function toEbayCondition(string $grade): string {

    return match ($grade) {
      'like_new' => 'USED_LIKE_NEW',
      'very_good' => 'USED_VERY_GOOD',
      'good' => 'USED_GOOD',
      'acceptable' => 'USED_ACCEPTABLE',
      default => 'USED_GOOD',
    };
  }

}
