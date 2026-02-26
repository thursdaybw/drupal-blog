<?php

declare(strict_types=1);

namespace Drupal\ai_listing_inference\Service;

use Drupal\compute_orchestrator\Service\VlmClient;

final class BookExtractionService {

  public function __construct(
    private readonly VlmClient $vlmClient,
  ) {}

  /**
   * @param string[] $imagePaths Real filesystem paths.
   * @return array{
   *   metadata: array<string,mixed>|null,
   *   metadata_raw: string,
   *   condition: array{issues: string[]}|null,
   *   condition_raw: string
   * }
   */
  public function extract(array $imagePaths, ?array $metadataImagePaths = NULL): array {

    if (empty($imagePaths)) {
      throw new \RuntimeException('No images provided.');
    }

    $metadataImagePaths = $metadataImagePaths ?? $imagePaths;

    if (empty($metadataImagePaths)) {
      throw new \RuntimeException('No metadata images provided.');
    }

    $metadataPrompt = $this->buildMetadataPrompt();

    $metadataResult = $this->vlmClient->infer($metadataPrompt, $metadataImagePaths);
    $conditionResult = $this->inferConditionFromImages($imagePaths);

    $metadataParsed = is_array($metadataResult['parsed'] ?? null) ? $metadataResult['parsed'] : null;
    $conditionParsed = $conditionResult['parsed'] ?? null;

    $metadataStructured = $metadataParsed ? $this->normalizeMetadata($metadataParsed) : null;
    $conditionStructured = $conditionParsed ? $this->normalizeCondition($conditionParsed) : null;

    return [
      'metadata' => $metadataStructured,
      'metadata_raw' => (string) ($metadataResult['raw'] ?? ''),
      'condition' => $conditionStructured,
      'condition_raw' => (string) ($conditionResult['raw'] ?? ''),
    ];
  }

  /**
   * Runs condition inference one image at a time and aggregates visible issues.
   *
   * @param string[] $imagePaths
   * @return array{parsed: array{issues: string[]}, raw: string}
   */
  private function inferConditionFromImages(array $imagePaths): array {
    $conditionPrompt = $this->buildConditionPrompt();
    $allIssues = [];
    $rawParts = [];

    foreach ($imagePaths as $index => $imagePath) {
      $imageNumber = $index + 1;
      $conditionResult = $this->vlmClient->infer($conditionPrompt, [$imagePath]);
      $conditionParsed = is_array($conditionResult['parsed'] ?? null) ? $conditionResult['parsed'] : [];
      $normalizedCondition = $this->normalizeCondition($conditionParsed);

      foreach ($normalizedCondition['issues'] as $issue) {
        if ($issue === '') {
          continue;
        }
        $allIssues[$issue] = TRUE;
      }

      $rawParts[] = $this->formatConditionRawPart(
        $imageNumber,
        (string) ($conditionResult['raw'] ?? '')
      );
    }

    $aggregatedIssues = array_keys($allIssues);
    sort($aggregatedIssues);

    return [
      'parsed' => ['issues' => $aggregatedIssues],
      'raw' => implode("\n\n", array_filter($rawParts)),
    ];
  }

  private function formatConditionRawPart(int $imageNumber, string $raw): string {
    $trimmed = trim($raw);
    if ($trimmed === '') {
      return 'Image ' . $imageNumber . ': (empty)';
    }

    return 'Image ' . $imageNumber . ': ' . $trimmed;
  }

  private function buildMetadataPrompt(): string {
    return "Extract structured metadata from the provided book images. Use visible information from the images. If the book is clearly identifiable by title and author, you may use widely known public knowledge about the book to generate a brief factual summary. Do not invent details. Return only JSON with keys: title, subtitle, author, isbn, publisher, publication_year, format, language, genre, narrative_type, country_printed, edition, series, features, short_description. Rules: title is the main title only, subtitle is the subtitle only (empty string if not visible). isbn is digits only if visible, else empty string. format is one of: paperback, hardcover, else empty string. language is a language name if visible else empty string. features is an array of strings, allowed values: ex-library, dust-jacket, large-print, signed, boxed-set. short_description must be 1-2 concise factual sentences about the book itself. Do not describe the cover image, layout, or visual elements. No promotional tone. Use empty string for any field not visible, and [] for features if none are visible.";
  }

  private function buildConditionPrompt(): string {
    return " Act as a very cautious resller and assess the physical condition of the book.

List visible physical defects of any pages, cover board or dust jacket if present.
As a cautious reseller look out for anything noticible such as faint staining and include it.

Use any of the following issue categories:

- ex_library
- gift inscription/pen marks
- foxing
- tearing
- tanning/toning
- removable dust jacket damage
- cover wear
- surface wear
- paper ageing
- staining
- loose pages
- tape/adhesive residue

Rules:
- If no issues are visible, return: { \"issues\": [] }
- Use \"gift inscription/pen marks\" only for visible handwritten or pen/pencil marks on pages, endpapers, or inside covers.
- Do not use \"gift inscription/pen marks\" for printed labels, stickers, dymo labels, or tape.
- Use \"tape/adhesive residue\" for visible tape, tape marks, adhesive staining, or tape residue.

Return only a single JSON object in this format:

{ \"issues\": [\"issue_name\"] }

Do not add commentary.
Do not wrap in markdown fences.
      ";
  }

  /**
   * @param array<string,mixed> $parsed
   * @return array<string,mixed>
   */
  private function normalizeMetadata(array $parsed): array {
    // --- Normalize parsed metadata ---
    if (is_array($parsed)) {
      foreach ($parsed as $k => $v) {
        if (is_string($v)) {
          $parsed[$k] = trim($v);
        }
      }

      // Normalize ISBN: remove spaces and hyphens.
      if (!empty($parsed['isbn'])) {
        $parsed['isbn'] = preg_replace('/[^0-9Xx]/', '', $parsed['isbn']);
      }

      // Normalize features array
      if (isset($parsed['features']) && is_array($parsed['features'])) {
        $parsed['features'] = array_values(array_map(
          fn($v) => is_string($v) ? trim($v) : '',
          $parsed['features']
        ));
      } else {
        $parsed['features'] = [];
      }

    }

    if (is_array($parsed)) {
      $title = (string) ($parsed['title'] ?? '');
      $subtitle = (string) ($parsed['subtitle'] ?? '');
      $author = (string) ($parsed['author'] ?? '');
      $format = (string) ($parsed['format'] ?? '');

      $fullTitle = $this->buildFullTitle($title, $subtitle);
      $ebayTitle = $this->buildEbayTitle($title, $subtitle, $author, $format);

      $shortDescription = trim((string) ($parsed['short_description'] ?? ''));

      $footer = trim("
---

Australian seller starting a new chapter from the Northern Rivers of NSW

Sent via Australia Post within 2 business days of payment clearing

All items are pre-loved and sold as-is.

Explore my other listings, more books and treasures added regularly!
");

      if ($shortDescription === '') {
        $description = $footer;
      }
      else {
        $description = $shortDescription . "\n\n" . $footer;
      }

      return [
        'title' => $title,
        'subtitle' => $subtitle,
        'full_title' => $fullTitle,
        'author' => $author,
        'isbn' => (string) ($parsed['isbn'] ?? ''),
        'publisher' => (string) ($parsed['publisher'] ?? ''),
        'publication_year' => (string) ($parsed['publication_year'] ?? ''),
        'format' => $format,
        'language' => (string) ($parsed['language'] ?? ''),
        'genre' => (string) ($parsed['genre'] ?? ''),
        'narrative_type' => (string) ($parsed['narrative_type'] ?? ''),
        'country_printed' => (string) ($parsed['country_printed'] ?? ''),
        'edition' => $this->truncateEdition((string) ($parsed['edition'] ?? '')),
        'series' => is_scalar($parsed['series'] ?? null) ? (string) $parsed['series'] : '',
        'features' => is_array($parsed['features'] ?? null) ? array_values(array_map('strval', $parsed['features'])) : [],
        'ebay_title' => $ebayTitle,
        'description' => $description,
      ];
    }

    return [];
  }

  /**
   * @param array<string,mixed> $parsed
   * @return array{issues: string[]}
   */
  private function normalizeCondition(array $parsed): array {

    if (isset($parsed['issues']) && is_array($parsed['issues'])) {

      $issues = array_values(array_map(
        fn($v) => strtolower(trim((string) $v)),
        $parsed['issues']
      ));

      $issues = $this->canonicalizeConditionIssues($issues);

      return ['issues' => $issues];
    }

    return ['issues' => []];
  }

  private function buildFullTitle(string $title, string $subtitle): string {
    $title = trim($title);
    $subtitle = trim($subtitle);

    if ($title === '') {
      return '';
    }

    if ($subtitle === '') {
      return $title;
    }

    return $title . ': ' . $subtitle;
  }

  private function buildEbayTitle(string $title, string $subtitle, string $author, string $format): string {
    $title = trim($title);
    $subtitle = trim($subtitle);
    $author = trim($author);
    $format = strtolower(trim($format));

    $formatLabel = '';
    if ($format === 'paperback') {
      $formatLabel = 'Paperback';
    }
    elseif ($format === 'hardcover') {
      $formatLabel = 'Hardcover';
    }

    $suffixParts = [];
    if ($author !== '') {
      $suffixParts[] = 'by ' . $author;
    }
    if ($formatLabel !== '') {
      $suffixParts[] = $formatLabel;
    }
    $suffixParts[] = 'Book';

    $suffix = implode(' ', $suffixParts);
    $maxLen = 80;

    $candidateWithSubtitle = $this->buildFullTitle($title, $subtitle);
    $candidate = trim($candidateWithSubtitle . ' ' . $suffix);
    $candidate = preg_replace('/\s+/', ' ', $candidate) ?? $candidate;

    if (strlen($candidate) <= $maxLen) {
      return $candidate;
    }

    // Drop subtitle first.
    $candidateNoSubtitle = trim($title . ' ' . $suffix);
    $candidateNoSubtitle = preg_replace('/\s+/', ' ', $candidateNoSubtitle) ?? $candidateNoSubtitle;

    if (strlen($candidateNoSubtitle) <= $maxLen) {
      return $candidateNoSubtitle;
    }

    // Truncate title to fit, keep suffix intact.
    $suffixWithLeadingSpace = ' ' . $suffix;
    $budget = $maxLen - strlen($suffixWithLeadingSpace);
    if ($budget <= 0) {
      // Extreme case, truncate suffix too.
      return substr($candidateNoSubtitle, 0, $maxLen);
    }

    $truncatedTitle = $title;
    if (strlen($truncatedTitle) > $budget) {
      if ($budget >= 3) {
        $truncatedTitle = substr($truncatedTitle, 0, $budget - 3) . '...';
      }
      else {
        $truncatedTitle = substr($truncatedTitle, 0, $budget);
      }
    }

    $final = trim($truncatedTitle . $suffixWithLeadingSpace);
    $final = preg_replace('/\s+/', ' ', $final) ?? $final;

    return substr($final, 0, $maxLen);
  }

  /**
   * @param string[] $issues
   * @return string[]
   */
  private function canonicalizeConditionIssues(array $issues): array {
    $aliases = [
      'edge wear' => 'surface wear',
      'edgewear' => 'surface wear',
      'dust jacket damage' => 'removable dust jacket damage',
    ];

    $canonicalIssues = [];
    foreach ($issues as $issue) {
      if ($issue === '') {
        continue;
      }

      $canonical = $aliases[$issue] ?? $issue;
      $canonicalIssues[$canonical] = TRUE;
    }

    $result = array_keys($canonicalIssues);
    sort($result);

    return $result;
  }

  private function truncateEdition(string $value): string {
    $max = 64;
    $normalized = trim($value);
    if ($normalized === '') {
      return '';
    }

    if (mb_strlen($normalized) <= $max) {
      return $normalized;
    }

    $truncated = mb_substr($normalized, 0, max(0, $max - 1));
    return $truncated . '…';
  }

}
