# 🧪 dtt_multi_device_test_base

Reusable base classes for running **Drupal Test Traits (DTT)** tests in **multiple browser/device contexts** — such as desktop, mobile, tablet, or anything Selenium supports.

This module enables **per-test-class device profiles**, using custom base classes that read from a YAML config and spawn a Mink session accordingly.
It lets you assert responsive behavior directly within PHPUnit.

It fills a gap that cannot be solved by multiple PHPUnit configs or CI matrix runs alone.

This gives you:

    Multiple device profiles in one test suite

    Semantically clear test classes like GenerateCaptionsMobileTest vs GenerateCaptionsDesktopTest

    Flexibility beyond what DTT’s env-var-based driver loading allows

---

## Features

- Base class (`DeviceProfileTestBase`) that:
  - Loads custom Selenium2Driver capabilities from a YAML config file
  - Allows subclasses to declare a target device profile (`getDeviceProfileKey()`)
  - Works with DTT’s `ExistingSiteSelenium2DriverTestBase`
- Compatible with Composer-based Drupal installs
- Supports fallback default profiles

---

## 💡 Why This Exists

Drupal Test Traits (DTT) allows browser testing using Selenium, but all tests run in a single environment defined via env vars or `phpunit.xml`.

That’s fine for:

- “Run all tests in Chrome”
- “Run the same test in Firefox, too”

But it **doesn’t allow** writing a test like:

```php
class HomepageDesktopTest extends DesktopTestBase
class HomepageMobileTest extends MobileTestBase
```

### "Why not just use multiple PHPUnit configs or CI matrix?"

That approach can run the same test suite in different environments but it can't let a single test class declare its device context and behave differently.

Consider this use case:

    GenerateCaptionsMobileTest asserts mobile behavior

    GenerateCaptionsDesktopTest asserts desktop behavior

They are different tests with different expectations. Running the same class in different configs won't help, you'd either:

    * Skip or fail assertions that don’t apply to the current env

    * Or make the test logic messier with branching like if (MOBILE) {...}


## Installation

Install as a custom Drupal module (in `modules/custom` or `modules/contrib`):


## Configuration

You must define an environment variable to point to your device profile YAML file:

<!-- Inside your phpunit.dtt.xml -->
<php>
  <env name="DTT_DEVICE_PROFILE_YAML" value="/full/path/to/dtt_device_profiles.yaml"/>
</php>

✅ Recommended: Use a full absolute path to avoid inconsistencies across CI, IDEs, shells, and PHPUnit runners.

While the library attempts to resolve relative paths as if they’re anchored to the project root, this behavior depends on how PHPUnit is executed and may break in some environments (e.g., getcwd() shifting unpredictably).
Your mileage may vary.

This file should live in your project root (where phpunit.dtt.xml and composer.json are located), with contents like:

```yaml
small_mobile:
  - "chrome"
  - chromeOptions:
      mobileEmulation: { deviceName: "Pixel 2" }
      args: ["--window-size=412,732", "--disable-gpu", "--no-sandbox"]
  - "http://selenium-chrome:4444/wd/hub"

desktop:
  - "chrome"
  - chromeOptions:
      args: ["--window-size=1920,1080", "--disable-gpu", "--no-sandbox"]
  - "http://selenium-chrome:4444/wd/hub"
```

✅ The base class resolves this path relative to the project root, using a getcwd() + '..' trick to sidestep quirks introduced by test runners that change working directory.

Notes

    A fallback file (device_profiles.default.yaml) is bundled in this module but not maintained. Copy it and customize your own.

    This setup requires you to use bootstrap.php (not bootstrap-fast.php) in your phpunit.dtt.xml to ensure custom base classes load.

    If your tests aren't being picked up, double-check:

        Your base class file is in tests/src/.../Base/

        You're not using the fast bootstrap file

## Usage

Extend DeviceProfileTestBase in your test classes:

```php
use Drupal\Tests\dtt_multi_device_test_base\ExistingSiteJavascript\Base\DeviceProfileTestBase;

class MyMobileTest extends DeviceProfileTestBase {
  protected function getDeviceProfileKey(): string {
    return 'small_mobile';
  }

  public function testMobileStuff() {
    $this->visit('/');
    $this->assertSession()->elementNotExists('css', '.desktop-nav');
  }
}
```


