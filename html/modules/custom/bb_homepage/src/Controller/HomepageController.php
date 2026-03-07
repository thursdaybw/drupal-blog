<?php

declare(strict_types=1);

namespace Drupal\bb_homepage\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Builds the dedicated homepage.
 */
final class HomepageController extends ControllerBase {

  /**
   * The entity type manager.
   */
  private EntityTypeManagerInterface $bbEntityTypeManager;

  /**
   * The date formatter.
   */
  private DateFormatterInterface $bbDateFormatter;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = new static();
    $instance->bbEntityTypeManager = $container->get('entity_type.manager');
    $instance->bbDateFormatter = $container->get('date.formatter');

    return $instance;
  }

  /**
   * Builds the homepage render array.
   */
  public function build(): array {
    $hero = $this->deriveHeroSection();
    $focus_areas = $this->deriveFocusAreas();
    $featured_articles = $this->deriveFeaturedArticles();
    $current_work = $this->deriveCurrentWork();
    $about = $this->deriveAboutSection();
    $follow_links = $this->deriveFollowLinks();

    return [
      '#theme' => 'bb_homepage_page',
      '#hero' => $hero,
      '#focus_areas' => $focus_areas,
      '#featured_articles' => $featured_articles,
      '#current_work' => $current_work,
      '#about' => $about,
      '#follow_links' => $follow_links,
      '#attached' => [
        'library' => [
          'bb_homepage/page',
        ],
      ],
    ];
  }

  /**
   * Derives hero content.
   */
  private function deriveHeroSection(): array {
    return [
      'title' => "Bevan's Bench",
      'tagline' => 'Systems, experiments, and tools for a more independent life.',
      'intro' => "Bevan's Bench is where I build and document tools, workflows, media experiments, and small ventures aimed at greater independence.",
      'actions' => [
        [
          'label' => 'Start Reading',
          'url' => Url::fromUri('internal:/node')->toString(),
          'variant' => 'primary',
        ],
        [
          'label' => 'Watch on YouTube',
          'url' => 'https://www.youtube.com/@bevansbench',
          'variant' => 'secondary',
          'target' => '_blank',
          'rel' => 'noopener noreferrer',
        ],
        [
          'label' => 'My Links',
          'url' => Url::fromUri('internal:/linktree')->toString(),
          'variant' => 'ghost',
        ],
      ],
    ];
  }

  /**
   * Derives the homepage focus areas.
   */
  private function deriveFocusAreas(): array {
    return [
      [
        'title' => 'Systems',
        'description' => 'Tools, automation, workflows, and software experiments that reduce friction in creative and business work.',
      ],
      [
        'title' => 'Experiments',
        'description' => 'Reselling, income systems, process testing, and practical trials built to learn what actually works.',
      ],
      [
        'title' => 'Writing and Media',
        'description' => 'Posts, videos, and project updates that document the bench as ideas move from concept to reality.',
      ],
    ];
  }

  /**
   * Derives featured article view models.
   */
  private function deriveFeaturedArticles(): array {
    $nodes = $this->loadFeaturedArticleNodes();
    $article_view_models = [];

    foreach ($nodes as $node) {
      $article_view_models[] = $this->deriveFeaturedArticleViewModel($node);
    }

    return $article_view_models;
  }

  /**
   * Loads recent published article nodes.
   *
   * @return \Drupal\node\NodeInterface[]
   *   The nodes to feature.
   */
  private function loadFeaturedArticleNodes(): array {
    $storage = $this->bbEntityTypeManager->getStorage('node');
    $node_ids = $storage
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('type', 'article')
      ->sort('sticky', 'DESC')
      ->sort('created', 'DESC')
      ->range(0, 3)
      ->execute();

    if (!$node_ids) {
      return [];
    }

    return $storage->loadMultiple($node_ids);
  }

  /**
   * Derives a featured article view model.
   */
  private function deriveFeaturedArticleViewModel(object $node): array {
    $summary = $this->deriveArticleSummary($node);

    return [
      'title' => $node->label(),
      'url' => $node->toUrl()->toString(),
      'summary' => $summary,
      'date' => $this->bbDateFormatter->format((int) $node->getCreatedTime(), 'custom', 'F j, Y'),
    ];
  }

  /**
   * Derives article summary text.
   */
  private function deriveArticleSummary(object $node): string {
    if (!$node->hasField('body') || $node->get('body')->isEmpty()) {
      return 'A recent note from the bench.';
    }

    $body_item = $node->get('body')->first();
    $summary = (string) ($body_item->summary ?? '');

    if ($summary !== '') {
      return $this->truncateText($summary, 180);
    }

    $body_value = strip_tags((string) $body_item->value);

    return $this->truncateText($body_value, 180);
  }

  /**
   * Derives the current work section.
   */
  private function deriveCurrentWork(): array {
    return [
      [
        'title' => 'eBay Listing Automation',
        'description' => 'Building workflows that reduce the time it takes to prepare, enrich, and publish book listings.',
      ],
      [
        'title' => 'AI-Assisted Media Tooling',
        'description' => 'Experimenting with systems that support video, captions, and repeatable content production.',
      ],
      [
        'title' => 'Reselling Systems',
        'description' => 'Testing practical sourcing, listing, and inventory approaches designed for small-scale independence.',
      ],
      [
        'title' => 'Open-Source Utilities',
        'description' => 'Turning useful internal tooling into cleaner, more reusable software where it makes sense.',
      ],
    ];
  }

  /**
   * Derives about section content.
   */
  private function deriveAboutSection(): array {
    return [
      'title' => 'About the Bench',
      'body' => "Bevan's Bench is named after the idea of a workshop bench: a place where things get built, tested, and improved. This site is where I share the systems I'm building, the experiments I'm running, and what they teach me.",
    ];
  }

  /**
   * Derives follow links.
   */
  private function deriveFollowLinks(): array {
    return [
      [
        'title' => 'YouTube',
        'description' => 'Videos about experiments, systems, and the work behind the bench.',
        'url' => 'https://www.youtube.com/@bevansbench',
        'target' => '_blank',
        'rel' => 'noopener noreferrer',
      ],
      [
        'title' => 'Facebook',
        'description' => 'Updates, discussion, and public notes as projects move forward.',
        'url' => 'https://www.facebook.com/profile.php?id=100066474369727',
        'target' => '_blank',
        'rel' => 'noopener noreferrer',
      ],
      [
        'title' => 'Link Page',
        'description' => 'A compact landing page for the main public destinations around the site.',
        'url' => Url::fromUri('internal:/linktree')->toString(),
      ],
    ];
  }

  /**
   * Truncates text cleanly for short summaries.
   */
  private function truncateText(string $text, int $limit): string {
    $normalized_text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

    if (mb_strlen($normalized_text) <= $limit) {
      return $normalized_text;
    }

    return rtrim(mb_substr($normalized_text, 0, $limit - 1)) . '…';
  }

}
