<?php

declare(strict_types=1);

namespace Drupal\marketplace_orders\Model;

/**
 * Paginated read-model result for pick-pack queue queries.
 */
final class PickPackQueueResult {

  /**
   * @param \Drupal\marketplace_orders\Model\PickPackQueueRow[] $rows
   */
  public function __construct(
    private readonly array $rows,
    private readonly int $totalRows,
    private readonly int $offset,
    private readonly int $limit,
  ) {}

  /**
   * @return \Drupal\marketplace_orders\Model\PickPackQueueRow[]
   */
  public function getRows(): array {
    return $this->rows;
  }

  public function getTotalRows(): int {
    return $this->totalRows;
  }

  public function getOffset(): int {
    return $this->offset;
  }

  public function getLimit(): int {
    return $this->limit;
  }

}
