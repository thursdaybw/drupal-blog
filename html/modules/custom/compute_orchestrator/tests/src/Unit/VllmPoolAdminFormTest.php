<?php

declare(strict_types=1);

namespace Drupal\Tests\compute_orchestrator\Unit;

require_once __DIR__ . '/../../../src/Form/VllmPoolAdminForm.php';

use Drupal\compute_orchestrator\Form\VllmPoolAdminForm;
use PHPUnit\Framework\TestCase;

/**
 * Tests admin display state for the vLLM pool table.
 */
final class VllmPoolAdminFormTest extends TestCase {

  /**
   * Available but already-stopped instances should not say reap-eligible.
   */
  public function testStoppedAvailableRuntimeDescribesAsStoppedNotReapEligible(): void {
    $form = $this->formWithoutConstructor();

    self::assertSame(
      'Stopped — already stopped',
      $this->describeReapStatus($form, [
        'lease_status' => 'available',
        'runtime_state' => 'stopped',
        'last_used_at' => 100,
        'last_stopped_at' => 400,
      ], 1_000, 60),
    );
  }

  /**
   * Stopped status wins even when post-lease reap is disabled.
   */
  public function testStoppedRuntimeStatusWinsWhenReapDisabled(): void {
    $form = $this->formWithoutConstructor();

    self::assertSame(
      'Stopped — already stopped',
      $this->describeReapStatus($form, [
        'lease_status' => 'available',
        'vast_actual_status' => 'inactive',
        'last_used_at' => 100,
      ], 1_000, 0),
    );
  }

  /**
   * Running available instances still show eligible after grace passes.
   */
  public function testRunningAvailableRuntimeStillDescribesAsReapEligible(): void {
    $form = $this->formWithoutConstructor();

    self::assertSame(
      'Yes — eligible now',
      $this->describeReapStatus($form, [
        'lease_status' => 'available',
        'runtime_state' => 'running',
        'last_used_at' => 100,
      ], 1_000, 60),
    );
  }

  /**
   * Creates the form without service dependencies for private display tests.
   */
  private function formWithoutConstructor(): VllmPoolAdminForm {
    return (new \ReflectionClass(VllmPoolAdminForm::class))->newInstanceWithoutConstructor();
  }

  /**
   * Calls the private reap-status formatter.
   *
   * @param \Drupal\compute_orchestrator\Form\VllmPoolAdminForm $form
   *   Form under test.
   * @param array<string,mixed> $record
   *   Pool record.
   * @param int $now
   *   Current timestamp.
   * @param int $idleSeconds
   *   Reap grace period.
   */
  private function describeReapStatus(VllmPoolAdminForm $form, array $record, int $now, int $idleSeconds): string {
    $method = new \ReflectionMethod($form, 'describeReapStatus');
    $method->setAccessible(TRUE);
    return (string) $method->invoke($form, $record, $now, $idleSeconds);
  }

}
