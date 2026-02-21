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

    $promptText = "Extract structured metadata from the provided book images. Only use information visible in the images. Do not guess. Return only JSON with keys: title, author, isbn, publisher, publication_year, format, language. Use empty string for any field that is not visible.";

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
        'raw' => $content,
      ]);
    }

    return new JsonResponse([
      'error' => 'parse_failed',
      'raw' => $content,
    ], 200);

  }
}
