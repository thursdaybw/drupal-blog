<?php
namespace Drupal\Tests\dtt_multi_device_test_base\ExistingSiteJavascript\Base;

class DesktopTestBase extends DeviceProfileTestBase {
  protected function getDeviceProfileKey(): string {
    return 'desktop';
  }
}

