<?php
namespace thursdaybw\DttMultiDeviceTestBase;

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

/*
  protected function setUp(): void {
    parent::setUp();

    $this->driver = $this->getDriverInstance();
    $this->session = new Session($this->driver);
    $this->session->start();
  }
 */

/*
protected function setUp(): void {
  parent::setUp();

  file_put_contents('/tmp/test-debug.log', "--- setUp running ---\n", FILE_APPEND);

  $driver = $this->getDriverInstance();
  file_put_contents('/tmp/test-debug.log', "getDriverInstance(): " . get_class($driver) . "\n", FILE_APPEND);

  $session = $this->getSession();
  file_put_contents('/tmp/test-debug.log', "getSession(): " . get_class($session) . "\n", FILE_APPEND);

  try {
    $driverProp = (new \ReflectionObject($session))->getProperty('driver');
    $driverProp->setAccessible(true);
    $originalDriver = $driverProp->getValue($session);
    file_put_contents('/tmp/test-debug.log', "Original session driver: " . get_class($originalDriver) . "\n", FILE_APPEND);
  } catch (\Throwable $e) {
    file_put_contents('/tmp/test-debug.log', "ERROR reading session driver: " . $e->getMessage() . "\n", FILE_APPEND);
  }

  file_put_contents('/tmp/test-debug.log', "--- setUp done ---\n", FILE_APPEND);
}
 */

protected function setUp(): void {
  $profile = $this->getDeviceProfileKey();
  $yamlPath = $this->getDeviceProfilesPath(); // ← reuse the same logic

  $raw = file_get_contents($yamlPath);
  $profiles = \Symfony\Component\Yaml\Yaml::parse($raw);

  if (empty($profiles[$profile])) {
    throw new \RuntimeException("No profile '$profile' in $yamlPath");
  }

  putenv('DTT_MINK_DRIVER_ARGS=' . json_encode($profiles[$profile]));

  parent::setUp();

  $this->driver = $this->getDriverInstance();
}

/*
  protected function setUp(): void {
  parent::setUp();

  $driver = $this->getDriverInstance();

  $session = $this->getSession();
  $reflection = new \ReflectionObject($session);
  $driverProp = $reflection->getProperty('driver');
  $driverProp->setAccessible(true);
  $driverProp->setValue($session, $driver);

  // Assign both to ensure all base class usage is covered
  */
  /*
  $this->driver = $driver; // for your own direct calls
  \Closure::bind(function () use ($driver) {
    $this->driver = $driver; // for ExistingSiteBase::$driver
  }, $this, \weitzman\DrupalTestTraits\ExistingSiteBase::class)();
   */
/*
}
 */

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

