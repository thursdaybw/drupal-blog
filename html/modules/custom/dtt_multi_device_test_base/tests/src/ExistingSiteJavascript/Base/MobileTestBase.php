<?php
namespace Drupal\Tests\dtt_multi_device_test_base\ExistingSiteJavascript\Base;

class MobileTestBase extends DeviceProfileTestBase {
  protected function getDeviceProfileKey(): string {
    return 'small_mobile';
  }
}
