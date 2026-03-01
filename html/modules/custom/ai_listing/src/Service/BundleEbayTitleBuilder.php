<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Service;

/**
 * Derives eBay listing titles for book bundles.
 */
final class BundleEbayTitleBuilder {

  /**
   * @param array<int,array<string,mixed>> $items
   */
  public function deriveTitle(array $items): string {
    $normalizedItems = $this->normalizeItems($items);
    if ($normalizedItems === []) {
      return 'Book Bundle';
    }

    $bookCount = count($normalizedItems);
    $titleList = $this->buildTitleList($normalizedItems);
    $sharedAuthor = $this->deriveSharedAuthor($normalizedItems);

    if ($sharedAuthor !== '') {
      $prefix = $this->buildAuthorPrefix($sharedAuthor, $bookCount);
      return $this->truncateTitle($prefix . $titleList);
    }

    $genre = $this->deriveTopGenre($normalizedItems);
    $prefix = $this->buildGenrePrefix($genre, $bookCount);

    return $this->truncateTitle($prefix . $titleList);
  }

  /**
   * @param array<int,array<string,mixed>> $items
   * @return array<int,array{title:string,author:string,genre:string}>
   */
  private function normalizeItems(array $items): array {
    $normalizedItems = [];

    foreach ($items as $item) {
      $normalizedItems[] = [
        'title' => $this->normalizeText((string) ($item['title'] ?? '')),
        'author' => $this->normalizeText((string) ($item['author'] ?? '')),
        'genre' => $this->normalizeText((string) ($item['genre'] ?? '')),
      ];
    }

    return $normalizedItems;
  }

  /**
   * @param array<int,array{title:string,author:string,genre:string}> $items
   */
  private function buildTitleList(array $items): string {
    $titles = [];

    foreach ($items as $index => $item) {
      if ($item['title'] !== '') {
        $titles[] = $item['title'];
        continue;
      }

      $titles[] = sprintf('Book %d', $index + 1);
    }

    return implode(', ', $titles);
  }

  /**
   * @param array<int,array{title:string,author:string,genre:string}> $items
   */
  private function deriveSharedAuthor(array $items): string {
    $firstAuthor = $items[0]['author'];
    if ($firstAuthor === '') {
      return '';
    }

    foreach ($items as $item) {
      if ($item['author'] !== $firstAuthor) {
        return '';
      }
    }

    return $firstAuthor;
  }

  /**
   * @param array<int,array{title:string,author:string,genre:string}> $items
   */
  private function deriveTopGenre(array $items): string {
    $genreCounts = [];

    foreach ($items as $item) {
      if ($item['genre'] === '') {
        continue;
      }

      if (!isset($genreCounts[$item['genre']])) {
        $genreCounts[$item['genre']] = 0;
      }

      $genreCounts[$item['genre']]++;
    }

    if ($genreCounts === []) {
      return 'Book';
    }

    arsort($genreCounts);

    return (string) array_key_first($genreCounts);
  }

  private function buildAuthorPrefix(string $author, int $bookCount): string {
    return sprintf('%s %d Book Bundle: ', $author, $bookCount);
  }

  private function buildGenrePrefix(string $genre, int $bookCount): string {
    return sprintf('%s %d Book Bundle: ', $genre, $bookCount);
  }

  private function truncateTitle(string $value): string {
    $value = trim($value);
    if (mb_strlen($value) <= 80) {
      return $value;
    }

    return rtrim(mb_substr($value, 0, 80));
  }

  private function normalizeText(string $value): string {
    return trim(preg_replace('/\s+/', ' ', $value) ?? '');
  }

}
