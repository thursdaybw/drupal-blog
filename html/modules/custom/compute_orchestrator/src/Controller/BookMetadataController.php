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

    $promptText = "Extract structured metadata from the provided book images. Only use information visible in the images. Do not guess. Return only JSON with keys: title, author, isbn, publisher, publication_year, format, language, genre, narrative_type, country_printed, edition, series, features. Features must be an array of short strings such as 'dust jacket', 'ex-library', 'illustrated', 'large print'. Use empty string for any scalar field not visible. Use empty array for features if none visible.";

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
      return new JsonResponse([
        'title' => (string) ($parsed['title'] ?? ''),
        'author' => (string) ($parsed['author'] ?? ''),
        'isbn' => (string) ($parsed['isbn'] ?? ''),
        'publisher' => (string) ($parsed['publisher'] ?? ''),
        'publication_year' => (string) ($parsed['publication_year'] ?? ''),
        'format' => (string) ($parsed['format'] ?? ''),
        'language' => (string) ($parsed['language'] ?? ''),
        'genre' => (string) ($parsed['genre'] ?? ''),
        'narrative_type' => (string) ($parsed['narrative_type'] ?? ''),
        'country_printed' => (string) ($parsed['country_printed'] ?? ''),
        'edition' => (string) ($parsed['edition'] ?? ''),
        'series' => (string) ($parsed['series'] ?? ''),
        'features' => $parsed['features'] ?? [],
        'ebay_title' => $this->buildEbayTitle($parsed),
        'raw' => $content,
      ]);
    }

    return new JsonResponse([
      'error' => 'parse_failed',
      'raw' => $content,
    ], 200);

  }

  private function buildEbayTitle(array $meta): string {

    $title = trim((string) ($meta['title'] ?? ''));
    $author = trim((string) ($meta['author'] ?? ''));
    $format = trim((string) ($meta['format'] ?? ''));

    // Normalize format casing
    if ($format !== '') {
      $format = ucfirst(strtolower($format));
    }

    // Remove subtitle first if too long
    $baseTitle = $title;

    $candidate = trim("$baseTitle by $author $format Book");

    if (strlen($candidate) > 80 && str_contains($title, ':')) {
      $parts = explode(':', $title, 2);
      $baseTitle = trim($parts[0]);
      $candidate = trim("$baseTitle by $author $format Book");
    }

    // Final hard truncate if still too long
    if (strlen($candidate) > 80) {
      $candidate = substr($candidate, 0, 80);
    }

    // Clean double spaces
    $candidate = preg_replace('/\s+/', ' ', $candidate);

    return trim($candidate);
  }

}
