<?php

declare(strict_types = 1);

namespace Drupal\blog_consumer\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Returns responses for Blog consumer routes.
 */
final class BlogConsumerController extends ControllerBase {

  /**
   * The controller constructor.
   */
  public function __construct(protected $entityTypeManager, readonly RequestStack $requestStack) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('request_stack')
    );
  }

  /**
   * Builds the response.
   */
  public function __invoke(): Response {
    $request = $this->requestStack->getCurrentRequest();
    $title = $request->query->get('title', 'Default Title');
    $body = $request->query->get('body', 'Default Body');
    $uuid = $request->query->get('uuid', NULL);

    if ($uuid) {
      // Update existing node
      $node = $this->entityTypeManager->getStorage('node')->loadByProperties(['uuid' => $uuid]);
      if ($node) {
        $node = reset($node);
        $existing_body = $node->get('body')->value;
        $node->set('body', [
          'value' => $existing_body . "\n" . $body,
          'format' => 'markdown',
        ]);
      } else {
        return new Response('Node not found.', Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain']);
      }
    } else {
      // Create new node
      $node = $this->entityTypeManager->getStorage('node')->create([
        'type' => 'article',
        'title' => $title,
        'body' => [
          'value' => $body,
          'format' => 'markdown',
        ],
      ]);
    }

    $node->save();

    // Return the UUID of the node as plain text
    return new Response($node->uuid(), Response::HTTP_OK, ['Content-Type' => 'text/plain']);
  }
}
