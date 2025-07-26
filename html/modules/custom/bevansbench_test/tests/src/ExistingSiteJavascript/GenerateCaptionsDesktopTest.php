<?php
namespace Drupal\Tests\bevansbench_test\ExistingSiteJavascript;

use thursdaybw\DttMultiDeviceTestBase\DesktopTestBase;

class GenerateCaptionsDesktopTest extends DesktopTestBase {
  public function testLoginLinkVisible() {
    $this->visit('/');
    $this->assertSession()->elementExists('css', 'nav#block-vani-account-menu a');
  }
}

