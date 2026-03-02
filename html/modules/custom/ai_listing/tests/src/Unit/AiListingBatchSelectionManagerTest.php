<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_listing\Unit;

use Drupal\ai_listing\Service\AiListingBatchSelectionManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests the small selection rules used by the batch form.
 *
 * What this is testing:
 * the batch form keeps selected row keys in a hidden JSON field. This helper
 * owns the plain rules for turning those raw strings into a clean selected set.
 *
 * Why this is a unit test:
 * these rules are pure string and array rules. No Drupal services are needed.
 */
final class AiListingBatchSelectionManagerTest extends TestCase {

  public function testBuildAndParseSelectionKey(): void {
    $manager = new AiListingBatchSelectionManager();

    $key = $manager->buildSelectionKey('book', 42);

    $this->assertSame('book:42', $key);
    $this->assertSame(
      ['listing_type' => 'book', 'id' => 42],
      $manager->parseSelectionKey($key),
    );
  }

  public function testExtractSelectionKeysFromHiddenJsonValue(): void {
    $manager = new AiListingBatchSelectionManager();

    $keys = $manager->extractSelectionKeys('["book:2","book%3A2","book_bundle:9",""]');

    $this->assertSame(['book:2', 'book_bundle:9'], $keys);
  }

  public function testExtractSelectionKeysFallsBackToCurrentPageCheckboxState(): void {
    $manager = new AiListingBatchSelectionManager();

    $keys = $manager->extractSelectionKeys('', [
      'book:2' => 'book:2',
      'book:3' => 0,
      'book_bundle:9' => 'book_bundle:9',
    ]);

    $this->assertSame(['book:2', 'book_bundle:9'], $keys);
  }

  public function testBuildSelectionRefsSkipsBrokenKeys(): void {
    $manager = new AiListingBatchSelectionManager();

    $selection = $manager->buildSelectionRefs([
      'book:2',
      'broken',
      'book_bundle:9',
      'book:0',
    ]);

    $this->assertSame([
      ['listing_type' => 'book', 'id' => 2],
      ['listing_type' => 'book_bundle', 'id' => 9],
    ], $selection);
  }

  public function testCountSelectionKeysIgnoresDuplicatesAndBrokenValues(): void {
    $manager = new AiListingBatchSelectionManager();

    $count = $manager->countSelectionKeys([
      'book:2',
      'book%3A2',
      'broken',
      '',
      'book_bundle:9',
      'book:0',
    ]);

    $this->assertSame(2, $count);
  }

}
