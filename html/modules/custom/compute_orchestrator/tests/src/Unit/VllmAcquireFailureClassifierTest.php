<?php

declare(strict_types=1);

namespace Drupal\Tests\compute_orchestrator\Unit;

require_once __DIR__ . '/../../../src/Exception/AcquirePendingException.php';
require_once __DIR__ . '/../../../src/Exception/WorkloadReadinessException.php';
require_once __DIR__ . '/../../../src/Service/Workload/FailureClass.php';
require_once __DIR__ . '/../../../src/Service/VllmAcquireFailureClassifier.php';

use Drupal\compute_orchestrator\Exception\WorkloadReadinessException;
use Drupal\compute_orchestrator\Service\VllmAcquireFailureClassifier;
use Drupal\compute_orchestrator\Service\Workload\FailureClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests classification of noisy Vast/vLLM acquire failures.
 */
final class VllmAcquireFailureClassifierTest extends TestCase {

  /**
   * Warmup evidence should win over noisy transient SSH probe text.
   */
  public function testWarmupForwardProgressBeatsNoisyTransientSshText(): void {
    $classifier = new VllmAcquireFailureClassifier();
    $exception = new \RuntimeException(
      'Readiness polling slice timed out after 10 seconds for workload vllm. '
      . 'Last probe failure (workload): class=warmup | '
      . 'models_8000(ok=0 stderr=curl: (7) Failed to connect to 127.0.0.1 port 8000) | '
      . 'processes(ok=1) | gpu(ok=1) | logs(ok=1) | '
      . 'previous transient ssh stderr=kex_exchange_identification: read: Connection reset by peer'
    );

    self::assertSame(
      VllmAcquireFailureClassifier::WARMUP_PENDING,
      $classifier->classify($exception, TRUE, TRUE),
    );
  }

  /**
   * Trusted runtime-lost readiness exceptions should abandon the runtime.
   */
  public function testRuntimeLostExceptionClassifiesAsRuntimeLost(): void {
    $classifier = new VllmAcquireFailureClassifier();
    $exception = new WorkloadReadinessException(
      FailureClass::RUNTIME_LOST,
      'Runtime lost: Instance entered failure state: exited.',
    );

    self::assertSame(
      VllmAcquireFailureClassifier::RUNTIME_LOST,
      $classifier->classify($exception, TRUE, TRUE),
    );
  }

  /**
   * A single SSH reset string is not enough to poison a host.
   */
  public function testSingleConnectionResetDoesNotClassifyAsRuntimeLost(): void {
    $classifier = new VllmAcquireFailureClassifier();
    $exception = new \RuntimeException(
      'SSH probe failed once: kex_exchange_identification: read: Connection reset by peer',
    );

    self::assertNotSame(
      VllmAcquireFailureClassifier::RUNTIME_LOST,
      $classifier->classify($exception, TRUE, TRUE),
    );
  }

}
