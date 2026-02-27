<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Command;

use Drush\Commands\DrushCommands;
use Drupal\Core\Entity\EntityTypeManagerInterface;

final class AiListingFakeCommand extends DrushCommands {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Generate fake AI book listings for development.
   *
   * @command ai_listing:fake
   * @aliases ailf
   * @param int $count
   *   Number of listings to generate.
   */
  public function generate(int $count = 5): void {

    $storage = $this->entityTypeManager->getStorage('bb_ai_listing');

    for ($i = 1; $i <= $count; $i++) {

      $listing = $storage->create([
        'listing_type' => 'book',
        'status' => 'ready_for_review',
        'field_title' => "Test Book {$i}",
        'field_subtitle' => '',
        'field_full_title' => "Test Book {$i}",
        'field_author' => 'Dev Author',
        'field_isbn' => '0000000000',
        'field_publisher' => 'Dev Press',
        'field_publication_year' => '2025',
        'field_format' => 'paperback',
        'field_language' => 'English',
        'field_genre' => 'Non-fiction',
        'field_narrative_type' => 'Non-fiction',
        'field_country_printed' => 'Australia',
        'field_edition' => 'First',
        'field_series' => '',
        'field_features' => [],
        'ebay_title' => "Test Book {$i} Paperback",
        'description' => [
          'value' => $this->fakeDescription($i),
          'format' => 'basic_html',
        ],
        'condition_grade' => 'good',
        'field_condition_issues' => ['surface wear'],
        'condition_note' => 'This item is pre-owned and shows signs of previous use with edge wear. Please see photos for full details.',
      ]);

      $listing->save();
    }

    $this->output()->writeln("Generated {$count} fake listings.");
  }

  private function fakeDescription(int $i): string {
    return "
<p>A guide to growing vegetables, including tips on soil, water, and pests.</p>
<p>---</p>
<p>Australian seller starting a new chapter from the Northern Rivers of NSW</p>
<p>Sent via Australia Post within 2 business days of payment clearing</p>
<p>All items are pre-loved and sold as-is.</p>
<p>Explore my other listings, more books and treasures added regularly!</p>
";
  }

}
