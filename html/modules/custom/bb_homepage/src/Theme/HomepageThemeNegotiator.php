<?php

declare(strict_types=1);

namespace Drupal\bb_homepage\Theme;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Theme\ThemeNegotiatorInterface;

/**
 * Selects the custom homepage theme for the homepage route.
 */
final class HomepageThemeNegotiator implements ThemeNegotiatorInterface {

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match): bool {
    $route = $route_match->getRouteObject();

    if ($route === NULL) {
      return FALSE;
    }

    return $route->getOption('_custom_theme') === 'bevansbench';
  }

  /**
   * {@inheritdoc}
   */
  public function determineActiveTheme(RouteMatchInterface $route_match): ?string {
    return 'bevansbench';
  }

}
