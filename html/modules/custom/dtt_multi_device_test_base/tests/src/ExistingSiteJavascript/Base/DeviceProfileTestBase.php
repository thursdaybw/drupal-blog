<?php
namespace Drupal\Tests\dtt_multi_device_test_base\ExistingSiteJavascript\Base;

use Drupal\Component\Serialization\Yaml;
use weitzman\DrupalTestTraits\ExistingSiteSelenium2DriverTestBase;
use Behat\Mink\Session;
use Behat\Mink\Driver\DriverInterface;
use Behat\Mink\Driver\Selenium2Driver;
use Drupal\FunctionalJavascriptTests\JSWebAssert;
use Composer\InstalledVersions;

/**
 * Base class that reads its Selenium args from tests/device_profiles.yaml.
 */
abstract class DeviceProfileTestBase extends ExistingSiteSelenium2DriverTestBase {

  /** @var \Behat\Mink\Session */
  protected $session;

  protected function getDriverInstance(): DriverInterface {
    $profile = $this->getDeviceProfileKey();
    $path = $this->getDeviceProfilesPath();

    $raw = file_get_contents($path);
    $profiles = Yaml::decode($raw);

    if (empty($profiles[$profile]) || !is_array($profiles[$profile])) {
      throw new \RuntimeException("No device profile '$profile' in $path");
    }

    return new Selenium2Driver(...$profiles[$profile]);
  }

  protected function getDeviceProfilesPath(): string {
    // Get the env var value.
    $path = getenv('DTT_DEVICE_PROFILE_YAML') ?: ($_ENV['DTT_DEVICE_PROFILE_YAML'] ?? null);

    if ($path && !str_starts_with($path, '/')) {
      // ⚠️ This attempts to resolve the path relative to the directory PHPUnit was *invoked* from.
      // In theory, getcwd() should be that directory — typically the project root.
      // However, in practice, some setups (e.g. DTT, vendor/bin/phpunit, test runner shims, or IDEs)
      // mysteriously shift getcwd() to the web root (e.g. /var/www/html/web) or elsewhere.
      // So we prepend ".." to try and land back in the actual project root.
      // It's a compromise: avoids requiring a full path, but might still break in exotic setups.
      $path = getcwd() . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . $path;
    }

    // If still not valid, fallback to bundled default file (if you want that behavior).
    if (!$path || !file_exists($path)) {
      $fallback = __DIR__ . '/../../device_profiles.default.yaml';
      if (file_exists($fallback)) {
        return $fallback;
      }
      throw new \RuntimeException("Device profiles YAML not found. Looked for: $path");
    }

    return $path;
  }

  /**
   * Subclasses must return their profile key e.g. 'desktop' or 'small_mobile'.
   *
   * @return string
   *   A key from device_profiles.yaml.
   */
  abstract protected function getDeviceProfileKey(): string;

}

