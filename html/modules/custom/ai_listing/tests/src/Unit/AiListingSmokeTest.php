<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_listing\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\ai_listing\Form\AiListingWorkbenchForm;

final class AiListingSmokeTest extends UnitTestCase {

  public function testAiListingModuleClassesAutoload(): void {
    $this->assertTrue(class_exists(AiListingWorkbenchForm::class));
  }

}
