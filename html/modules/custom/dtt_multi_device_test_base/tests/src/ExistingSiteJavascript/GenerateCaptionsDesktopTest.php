<?php
namespace Drupal\Tests\dtt_multi_device_test_base\ExistingSiteJavascript;

use Drupal\Tests\dtt_multi_device_test_base\ExistingSiteJavascript\Base\DesktopTestBase;

class GenerateCaptionsDesktopTest extends DesktopTestBase {
  public function testLoginLinkVisible() {
    $this->visit('/');
    $this->assertSession()->elementExists('css', 'nav#block-vani-account-menu a');
  }
}

