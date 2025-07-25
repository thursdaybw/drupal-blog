<?php

namespace Drupal\Tests\video_forge\ExistingSiteJavascript;

use weitzman\DrupalTestTraits\ExistingSiteSelenium2DriverTestBase;

/**
 * Verifies the DDEV site front page renders and shows the login link.
 *
 * @group video_forge
 */
class SimpleJsReachTest extends ExistingSiteSelenium2DriverTestBase {

  /**
   * Test that the front page contains the account/login link in the header.
   */
  public function testJsFrontPageLoads() {
    // Visit the front page.
    $this->visit('/');

    // Wait up to 5s for the body to appear.
    $this->getSession()->wait(5000, "document.querySelector('body') !== null");

    // Assert that the <body> element exists.
    $this->assertSession()->elementExists('css', 'body');

    // Assert that the login link is present.
    $this->assertSession()->elementExists(
      'css',
      'html.js body.homepage.no-sidebar div.dialog-off-canvas-main-canvas '
      . 'header.header div.container div.header-main div.header-main-right '
      . 'div.primary-menu-wrapper div.menu-wrap div.block-region.region-primary-menu '
      . 'nav#block-vani-account-menu.block.block-menu ul.menu li.menu-item.menu-item-level-1 '
      . 'a'
    );
  }

}

