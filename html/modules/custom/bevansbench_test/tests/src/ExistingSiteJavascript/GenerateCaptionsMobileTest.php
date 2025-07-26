<?php
namespace Drupal\Tests\bevansbench_test\ExistingSiteJavascript;

use thursdaybw\DttMultiDeviceTestBase\MobileTestBase;

class GenerateCaptionsMobileTest extends MobileTestBase {
  public function testLoginLinkVisible() {
    $this->visit('/');
    $this->assertSession()->elementExists('css', 'nav#block-vani-account-menu a');
  }
}

