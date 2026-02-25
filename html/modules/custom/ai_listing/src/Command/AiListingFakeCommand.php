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

    $storage = $this->entityTypeManager->getStorage('ai_book_listing');

    for ($i = 1; $i <= $count; $i++) {

      $listing = $storage->create([
        'status' => 'ready_for_review',
        'title' => "Test Book {$i}",
        'subtitle' => '',
        'full_title' => "Test Book {$i}",
        'author' => 'Dev Author',
        'isbn' => '0000000000',
        'publisher' => 'Dev Press',
        'publication_year' => '2025',
        'format' => 'paperback',
        'language' => 'English',
        'genre' => 'Non-fiction',
        'narrative_type' => 'Non-fiction',
        'country_printed' => 'Australia',
        'edition' => 'First',
        'series' => '',
        'features' => [],
        'ebay_title' => "Test Book {$i} Paperback",
        'description' => [
          'value' => $this->fakeDescription($i),
          'format' => 'basic_html',
        ],
        'condition_grade' => 'good',
        'condition_issues' => ['edge wear'],
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
