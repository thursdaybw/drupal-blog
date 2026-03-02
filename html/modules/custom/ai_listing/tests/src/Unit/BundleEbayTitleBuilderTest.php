<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_listing\Unit;

use Drupal\ai_listing\Service\BundleEbayTitleBuilder;
use Drupal\Tests\UnitTestCase;

final class BundleEbayTitleBuilderTest extends UnitTestCase {

  private ?BundleEbayTitleBuilder $builder = NULL;

  protected function setUp(): void {
    parent::setUp();
    $this->builder = new BundleEbayTitleBuilder();
  }

  public function testDerivesSharedAuthorBundleTitle(): void {
    $title = $this->builder->deriveTitle([
      [
        'title' => 'Book One',
        'author' => 'Tony Ferguson',
        'genre' => 'Cookbooks',
      ],
      [
        'title' => 'Book Two',
        'author' => 'Tony Ferguson',
        'genre' => 'Cookbooks',
      ],
    ]);

    $this->assertSame('Tony Ferguson 2 Book Bundle: Book One, Book Two', $title);
  }

  public function testFallsBackToTopGenreWhenAuthorsDiffer(): void {
    $title = $this->builder->deriveTitle([
      [
        'title' => 'Birdy',
        'author' => 'William Wharton',
        'genre' => 'Fiction',
      ],
      [
        'title' => 'The Blind Assassin',
        'author' => 'Margaret Atwood',
        'genre' => 'Fiction',
      ],
      [
        'title' => 'Hold Your Fire',
        'author' => 'Chloe Wilson',
        'genre' => 'Literary Fiction',
      ],
    ]);

    $this->assertSame('Fiction 3 Book Bundle: Birdy, The Blind Assassin, Hold Your Fire', $title);
  }

  public function testTruncatesLongTitlesToEbayLimit(): void {
    $title = $this->builder->deriveTitle([
      [
        'title' => 'The Tony Ferguson Cook Book: Low GI Good-Carb Recipes for Your Wellbeing',
        'author' => 'Tony Ferguson',
        'genre' => 'Cookbooks',
      ],
      [
        'title' => 'The Tony Ferguson Cook Book II: More Low GI Good-Carb Recipes For Your Wellbeing',
        'author' => 'Tony Ferguson',
        'genre' => 'Cookbooks',
      ],
    ]);

    $this->assertSame(80, mb_strlen($title));
    $this->assertSame(
      'Tony Ferguson 2 Book Bundle: The Tony Ferguson Cook Book: Low GI Good-Carb Recip',
      $title
    );
  }

}
