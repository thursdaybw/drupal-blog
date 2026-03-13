<?php

declare(strict_types=1);

namespace Drupal\marketplace_orders\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\marketplace_orders\Model\PickPackQueueResult;
use Drupal\marketplace_orders\Model\PickPackQueueRow;

/**
 * Application read-model query for pick-pack queue rows.
 */
final class PickPackQueueQueryService {

  /**
   * @var string[]
   */
  private const DEFAULT_ACTIONABLE_PAYMENT_STATUSES = ['paid'];

  /**
   * @var string[]
   */
  private const DEFAULT_ACTIONABLE_FULFILLMENT_STATUSES = ['not_started', 'in_progress'];

  /**
   * @var string[]
   */
  private const DEFAULT_ACTIONABLE_WAREHOUSE_STATUSES = ['new', 'picked', 'packed', 'label_purchased'];

  public function __construct(
    private readonly Connection $connection,
  ) {}

  /**
   * Returns queue rows ready for pick-pack style operational review.
   *
   * @param array{
   *   actionable_only?: bool,
     *   marketplace?: string,
     *   offset?: int,
     *   limit?: int,
     *   payment_statuses?: array<int, string>,
     *   fulfillment_statuses?: array<int, string>,
     *   warehouse_statuses?: array<int, string>
     * } $options
     */
  public function query(array $options = []): PickPackQueueResult {
    $actionableOnly = (bool) ($options['actionable_only'] ?? TRUE);
    $marketplace = trim((string) ($options['marketplace'] ?? ''));
    $offset = $this->normalizeOffset($options['offset'] ?? 0);
    $limit = $this->normalizeLimit($options['limit'] ?? 100);

    $paymentStatuses = $this->normalizeStatusList(
      $options['payment_statuses'] ?? self::DEFAULT_ACTIONABLE_PAYMENT_STATUSES,
      self::DEFAULT_ACTIONABLE_PAYMENT_STATUSES,
    );

    $fulfillmentStatuses = $this->normalizeStatusList(
      $options['fulfillment_statuses'] ?? self::DEFAULT_ACTIONABLE_FULFILLMENT_STATUSES,
      self::DEFAULT_ACTIONABLE_FULFILLMENT_STATUSES,
    );

    $warehouseStatuses = $this->normalizeStatusList(
      $options['warehouse_statuses'] ?? self::DEFAULT_ACTIONABLE_WAREHOUSE_STATUSES,
      self::DEFAULT_ACTIONABLE_WAREHOUSE_STATUSES,
    );

    $select = $this->connection->select('marketplace_order_line', 'line');
    $select->innerJoin('marketplace_order', 'order_header', 'order_header.id = line.order_id');
    $select->leftJoin('bb_ai_listing', 'listing', 'listing.uuid = line.listing_uuid');

    $select->addField('order_header', 'id', 'order_id');
    $select->addField('order_header', 'marketplace', 'marketplace');
    $select->addField('order_header', 'external_order_id', 'external_order_id');
    $select->addField('order_header', 'status', 'status');
    $select->addField('order_header', 'payment_status', 'payment_status');
    $select->addField('order_header', 'fulfillment_status', 'fulfillment_status');
    $select->addField('order_header', 'ordered_at', 'ordered_at');
    $select->addField('order_header', 'buyer_handle', 'buyer_handle');

    $select->addField('line', 'id', 'order_line_id');
    $select->addField('line', 'external_line_id', 'external_line_id');
    $select->addField('line', 'sku', 'sku');
    $select->addField('line', 'quantity', 'quantity');
    $select->addField('line', 'title_snapshot', 'title_snapshot');
    $select->addField('line', 'price_snapshot', 'price_snapshot');
    $select->addField('line', 'listing_uuid', 'listing_uuid');
    $select->addField('line', 'warehouse_status', 'warehouse_status');

    $select->addField('listing', 'ebay_title', 'listing_title');
    $select->addField('listing', 'storage_location', 'storage_location');

    if ($marketplace !== '') {
      $select->condition('order_header.marketplace', $marketplace);
    }

    if ($actionableOnly) {
      $select->condition('order_header.payment_status', $paymentStatuses, 'IN');
      $select->condition('order_header.fulfillment_status', $fulfillmentStatuses, 'IN');
      $select->condition('line.warehouse_status', $warehouseStatuses, 'IN');
    }

    $select->orderBy('order_header.ordered_at', 'ASC');
    $select->orderBy('order_header.id', 'ASC');
    $select->orderBy('line.id', 'ASC');

    $totalRows = $this->countQueryRows($select);

    $select->range($offset, $limit);
    $records = $select->execute()->fetchAll();

    $rows = [];
    foreach ($records as $record) {
      $rows[] = $this->mapRecordToRow($record);
    }

    return new PickPackQueueResult($rows, $totalRows, $offset, $limit);
  }

  private function normalizeOffset(mixed $value): int {
    $offset = is_numeric($value) ? (int) $value : 0;
    if ($offset < 0) {
      return 0;
    }

    return $offset;
  }

  private function normalizeLimit(mixed $value): int {
    $limit = is_numeric($value) ? (int) $value : 100;
    if ($limit < 1) {
      return 100;
    }

    if ($limit > 1000) {
      return 1000;
    }

    return $limit;
  }

  /**
   * @param mixed $value
   * @param string[] $fallback
   *
   * @return string[]
   */
  private function normalizeStatusList(mixed $value, array $fallback): array {
    if (!is_array($value)) {
      return $fallback;
    }

    $statuses = [];
    foreach ($value as $statusValue) {
      if (!is_string($statusValue)) {
        continue;
      }

      $normalized = trim(strtolower($statusValue));
      if ($normalized === '') {
        continue;
      }

      $statuses[] = $normalized;
    }

    if ($statuses === []) {
      return $fallback;
    }

    return array_values(array_unique($statuses));
  }

  private function countQueryRows(SelectInterface $select): int {
    $count = $select->countQuery()->execute()->fetchField();
    if ($count === FALSE || $count === NULL) {
      return 0;
    }

    return (int) $count;
  }

  /**
   * @param object{order_id:mixed,marketplace:mixed,external_order_id:mixed,status:mixed,payment_status:mixed,fulfillment_status:mixed,warehouse_status:mixed,ordered_at:mixed,buyer_handle:mixed,order_line_id:mixed,external_line_id:mixed,sku:mixed,quantity:mixed,title_snapshot:mixed,price_snapshot:mixed,listing_uuid:mixed,listing_title:mixed,storage_location:mixed} $record
   */
  private function mapRecordToRow(object $record): PickPackQueueRow {
    return new PickPackQueueRow(
      orderId: (int) $record->order_id,
      marketplace: (string) $record->marketplace,
      externalOrderId: (string) $record->external_order_id,
      status: (string) $record->status,
      paymentStatus: $this->toNullableString($record->payment_status),
      fulfillmentStatus: $this->toNullableString($record->fulfillment_status),
      warehouseStatus: $this->toNullableString($record->warehouse_status) ?? 'new',
      orderedAt: $this->toNullableInt($record->ordered_at),
      buyerHandle: $this->toNullableString($record->buyer_handle),
      orderLineId: (int) $record->order_line_id,
      externalLineId: (string) $record->external_line_id,
      sku: $this->toNullableString($record->sku),
      quantity: (int) $record->quantity,
      lineTitle: $this->toNullableString($record->title_snapshot),
      linePrice: $this->toNullableString($record->price_snapshot),
      listingUuid: $this->toNullableString($record->listing_uuid),
      listingTitle: $this->toNullableString($record->listing_title),
      storageLocation: $this->toNullableString($record->storage_location),
    );
  }

  private function toNullableString(mixed $value): ?string {
    if ($value === NULL) {
      return NULL;
    }

    $stringValue = trim((string) $value);
    if ($stringValue === '') {
      return NULL;
    }

    return $stringValue;
  }

  private function toNullableInt(mixed $value): ?int {
    if ($value === NULL || $value === '') {
      return NULL;
    }

    if (!is_numeric($value)) {
      return NULL;
    }

    return (int) $value;
  }

}
