<?php
namespace Drupal\Tests\bevansbench_test\ExistingSiteJavascript;

use thursdaybw\DttMultiDeviceTestBase\MobileTestBase;

class GenerateCaptionsMobileTest extends MobileTestBase {
  public function testLoginLinkVisible() {
    file_put_contents(
      '/tmp/dtt-env.log',
      'DTT_MINK_DRIVER_ARGS=' . (getenv('DTT_MINK_DRIVER_ARGS') ?: '<unset>') . "\n" .
      'MINK_DRIVER_ARGS_WEBDRIVER=' . (getenv('MINK_DRIVER_ARGS_WEBDRIVER') ?: '<unset>') . "\n\n",
      FILE_APPEND
    );

    $this->visit('/');
    $this->assertSession()->elementExists('css', 'nav#block-vani-account-menu a');
  }
}

