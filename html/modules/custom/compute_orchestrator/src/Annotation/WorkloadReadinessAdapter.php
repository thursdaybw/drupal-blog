<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Annotation;

use Drupal\Component\Annotation\Plugin;

/**
 * Defines a workload readiness adapter plugin annotation object.
 *
 * @Annotation
 */
final class WorkloadReadinessAdapter extends Plugin {

  /**
   * The plugin ID.
   *
   * @var string
   */
  public string $id;

  /**
   * Human readable label.
   *
   * @var \Drupal\Core\Annotation\Translation
   */
  public $label;

  /**
   * Startup timeout in seconds.
   *
   * @var int
   */
  public int $startup_timeout = 900;

}
