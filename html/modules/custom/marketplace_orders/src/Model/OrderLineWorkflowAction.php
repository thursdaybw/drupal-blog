<?php

declare(strict_types=1);

namespace Drupal\marketplace_orders\Model;

/**
 * Named action constants for internal warehouse workflow transitions.
 */
final class OrderLineWorkflowAction {

  public const PICKED = 'picked';
  public const PACKED = 'packed';
  public const LABEL_PURCHASED = 'label_purchased';
  public const DISPATCHED = 'dispatched';

  /**
   * @return string[]
   */
  public static function all(): array {
    return [
      self::PICKED,
      self::PACKED,
      self::LABEL_PURCHASED,
      self::DISPATCHED,
    ];
  }

}
