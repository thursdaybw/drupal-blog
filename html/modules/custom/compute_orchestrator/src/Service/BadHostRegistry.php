<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

use Drupal\Core\State\StateInterface;

/**
 * Persists "bad" Vast host IDs to avoid repeated infra failures.
 */
final class BadHostRegistry {

  private const STATE_KEY = 'compute_orchestrator.bad_hosts';

  public function __construct(
    private readonly StateInterface $state,
  ) {}

  /**
   * Returns all known bad host IDs.
   *
   * @return string[]
   *   Host ID strings.
   */
  public function all(): array {
    $value = $this->state->get(self::STATE_KEY, []);
    if (!is_array($value)) {
      return [];
    }

    return array_values(array_unique(array_map('strval', $value)));
  }

  /**
   * Adds a host ID to the registry.
   */
  public function add(string $hostId): void {
    $hostId = trim($hostId);
    if ($hostId === '') {
      return;
    }

    $all = $this->all();
    if (!in_array($hostId, $all, TRUE)) {
      $all[] = $hostId;
      $this->state->set(self::STATE_KEY, $all);
    }
  }

  /**
   * Clears the registry.
   */
  public function clear(): void {
    $this->state->delete(self::STATE_KEY);
  }

}
