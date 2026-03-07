<?php

declare(strict_types=1);

namespace Drupal\bb_linktree\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Renders public Linktree pages.
 */
final class PublicLinktreeController extends ControllerBase {

  /**
   * The renderer.
   */
  private RendererInterface $renderer;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static();
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->renderer = $container->get('renderer');

    return $instance;
  }

  /**
   * Builds the default linktree route.
   */
  public function buildIndex(): RedirectResponse {
    $page = $this->loadDefaultPublicPage();

    if ($page === NULL) {
      throw new NotFoundHttpException();
    }

    $page_url = Url::fromRoute('bb_linktree.page', [
      'page_path' => $page->get('path_segment')->value,
    ])->toString();

    return new RedirectResponse($page_url);
  }

  /**
   * Builds a public page by path segment.
   */
  public function buildPage(string $page_path): array {
    $normalized_page_path = strtolower($page_path);
    $page = $this->loadPublicPageByPathSegment($normalized_page_path);

    if ($page === NULL) {
      throw new NotFoundHttpException();
    }

    $items = $this->loadPublicItemsForPage((int) $page->id());
    $view_model = $this->derivePageViewModel($page);
    $item_view_models = $this->deriveItemViewModels($items);
    $intro = $this->buildIntro($page);

    return [
      '#title' => $page->label(),
      '#theme' => 'bb_linktree_page',
      '#page' => $view_model,
      '#items' => $item_view_models,
      '#intro' => $intro,
      '#attached' => [
        'library' => [
          'bb_linktree/public_page',
        ],
      ],
    ];
  }

  /**
   * Loads the default public page.
   */
  private function loadDefaultPublicPage(): ?EntityInterface {
    $storage = $this->entityTypeManager->getStorage('bb_linktree_page');

    $default_page_ids = $storage
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', TRUE)
      ->condition('is_default', TRUE)
      ->sort('id', 'ASC')
      ->range(0, 1)
      ->execute();

    if ($default_page_ids) {
      return $storage->load(reset($default_page_ids));
    }

    $first_page_ids = $storage
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', TRUE)
      ->sort('id', 'ASC')
      ->range(0, 1)
      ->execute();

    if (!$first_page_ids) {
      return NULL;
    }

    return $storage->load(reset($first_page_ids));
  }

  /**
   * Loads a public page by path segment.
   */
  private function loadPublicPageByPathSegment(string $page_path): ?EntityInterface {
    $storage = $this->entityTypeManager->getStorage('bb_linktree_page');
    $page_ids = $storage
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', TRUE)
      ->condition('path_segment', $page_path)
      ->range(0, 1)
      ->execute();

    if (!$page_ids) {
      return NULL;
    }

    return $storage->load(reset($page_ids));
  }

  /**
   * Loads public items for a page.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   The ordered public items.
   */
  private function loadPublicItemsForPage(int $page_id): array {
    $storage = $this->entityTypeManager->getStorage('bb_linktree_item');
    $item_ids = $storage
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', TRUE)
      ->condition('link_page', $page_id)
      ->sort('weight', 'ASC')
      ->sort('id', 'ASC')
      ->execute();

    if (!$item_ids) {
      return [];
    }

    return $storage->loadMultiple($item_ids);
  }

  /**
   * Derives the page view model.
   */
  private function derivePageViewModel(EntityInterface $page): array {
    return [
      'id' => (int) $page->id(),
      'title' => $page->label(),
      'path_segment' => (string) $page->get('path_segment')->value,
    ];
  }

  /**
   * Derives public item view models.
   *
   * @param \Drupal\Core\Entity\EntityInterface[] $items
   *   The items to transform.
   */
  private function deriveItemViewModels(array $items): array {
    $view_models = [];

    foreach ($items as $item) {
      $destination_item = $item->get('destination')->first();

      if ($destination_item === NULL) {
        continue;
      }

      $destination_url = Url::fromUri($destination_item->uri, $destination_item->options ?? []);
      $target = $destination_item->options['attributes']['target'] ?? NULL;
      $rel = $destination_item->options['attributes']['rel'] ?? NULL;

      $view_models[] = [
        'id' => (int) $item->id(),
        'label' => (string) $item->label(),
        'description' => $this->renderPlainDescription($item),
        'url' => $destination_url->toString(),
        'target' => is_string($target) ? $target : NULL,
        'rel' => is_string($rel) ? $rel : NULL,
        'meta_label' => $destination_url->isExternal() ? $this->t('External link') : $this->t('Internal link'),
      ];
    }

    return $view_models;
  }

  /**
   * Builds the intro render array.
   */
  private function buildIntro(EntityInterface $page): ?array {
    if ($page->get('intro')->isEmpty()) {
      return NULL;
    }

    $intro_value = $page->get('intro')->value;
    $intro_format = $page->get('intro')->format ?? 'basic_html';

    return [
      '#type' => 'processed_text',
      '#text' => $intro_value,
      '#format' => $intro_format,
    ];
  }

  /**
   * Renders the description field as plain text.
   */
  private function renderPlainDescription(EntityInterface $item): string {
    if ($item->get('description')->isEmpty()) {
      return '';
    }

    $build = [
      '#type' => 'processed_text',
      '#text' => $item->get('description')->value,
      '#format' => $item->get('description')->format ?? 'basic_html',
    ];

    return trim(strip_tags((string) $this->renderer->renderInIsolation($build)));
  }

}
