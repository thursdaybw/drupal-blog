<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\compute_orchestrator\Service\VlmClient;

final class BookMetadataController extends ControllerBase {

  public function __construct(
    private readonly VlmClient $vlmClient,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('compute_orchestrator.vlm_client'),
    );
  }

  public function extract(Request $request): JsonResponse {
    $images = $request->files->all('images');

    if (empty($images) || !is_array($images)) {
      return new JsonResponse([
        'error' => 'missing_files',
        'msg' => 'POST multipart form-data with files: images[]',
      ], 400);
    }

    $promptText = "Extract structured metadata from the provided book images. Use visible information from the images. If the book is clearly identifiable by title and author, you may use widely known public knowledge about the book to generate a brief factual summary. Do not invent details. Return only JSON with keys: title, subtitle, author, isbn, publisher, publication_year, format, language, genre, narrative_type, country_printed, edition, series, features, short_description. Rules: title is the main title only, subtitle is the subtitle only (empty string if not visible). isbn is digits only if visible, else empty string. format is one of: paperback, hardcover, else empty string. language is a language name if visible else empty string. features is an array of strings, allowed values: ex-library, dust-jacket, large-print, signed, boxed-set. short_description must be 1-2 concise factual sentences about the book itself. Do not describe the cover image, layout, or visual elements. No promotional tone. Use empty string for any field not visible, and [] for features if none are visible.";

    $imagePaths = [];
    foreach ($images as $image) {
      $imagePaths[] = $image->getPathname();
    }

    $result = $this->vlmClient->infer($promptText, $imagePaths);

    $content = (string) $result['raw'];

    $parsed = $result['parsed'];

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

      return new JsonResponse([
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
        'edition' => (string) ($parsed['edition'] ?? ''),
        'series' => (string) ($parsed['series'] ?? ''),
        'features' => is_array($parsed['features'] ?? null) ? array_values(array_map('strval', $parsed['features'])) : [],
        'ebay_title' => $ebayTitle,
        'description' => $description,
        'raw' => $content,
      ]);
    }

    return new JsonResponse([
      'error' => 'parse_failed',
      'raw' => $content,
    ], 200);

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

}
