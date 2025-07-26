<?php
namespace Drupal\Tests\dtt_multi_device_test_base\ExistingSiteJavascript;

class GenerateCaptionsMobileTest extends MobileTestBase {
  public function testLoginLinkVisible() {
    $this->visit('/');
    $this->assertSession()->elementExists('css', 'body');
  }
}

