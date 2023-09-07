<?php

declare(strict_types = 1);

namespace Drupal\blog_consumer\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

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
  public function __invoke(): array {
    $request = $this->requestStack->getCurrentRequest();
    $title = $request->query->get('title', 'Default Title');
    $body = $request->query->get('body', 'Default Body');

    // Create article node
    $node = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'article',
      'title' => $title,
      'body' => [
        'value' => $body,
        'format' => 'markdown',
      ],
    ]);

    $node->save();

    $build['content'] = [
      '#type' => 'item',
      '#markup' => $this->t('Article with title @title and body @body has been created!', [
        '@title' => $title,
        '@body' => $body,
      ]),
    ];

    return $build;
  }

}
