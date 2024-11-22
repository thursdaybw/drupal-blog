<?php
namespace Drupal\video_forge\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Provides access control for media type-specific routes.
 */
class MediaTypeAccessCheck {
  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $logger;

  /**
   * Constructs the MediaTypeAccessCheck.
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory) {
    $this->logger = $logger_factory->get('fancy_captions');
  }

  /**
   * Checks access for the media type.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   The access result.
   */
  public function access(RouteMatchInterface $route_match) {

	  $media = $route_match->getParameter('media');

	  $this->logger->info('Route accessed: @route, Media: @media', [
		  '@route' => $route_match->getRouteName(),
		  '@media' => is_object($media) ? $media->id() : 'NULL',
	  ]);



	  if (!$media) {
		  $this->logger->error('Media parameter is missing or null.');
		  return AccessResult::forbidden();
	  }

	  if ($media instanceof \Drupal\media\Entity\Media) {
		  $this->logger->info('Media ID: @id, Bundle: @bundle', [
			  '@id' => $media->id(),
			  '@bundle' => $media->bundle(),
		  ]);
	  } else {
		  $this->logger->error('Media is not an instance of Media entity.');
	  }

	  if ($media->bundle() === 'forge_video') {
		  return AccessResult::allowed();
	  }

	  return AccessResult::forbidden();
  }

}
