<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\compute_orchestrator\Service\VlmClient;

final class BookConditionController extends ControllerBase {

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

    $promptText = " Act as a very cautious resller and assess the physical condition of the book.

List visible physical defects of any pages, cover board or dust jacket if present.
As a cautious reseller look out for anything noticible such as faint staining and include it.

Use any of the following issue categories:

- ex_library
- gift inscription/pen marks
- foxing
- tearing
- tanning/toning
- edge wear
- dust jacket damage
- surface wear
- paper ageing
- staining

Rules:
- If no issues are visible, return: { \"issues\": [] }

Return only a single JSON object in this format:

{ \"issues\": [\"issue_name\"] }

Do not add commentary.
Do not wrap in markdown fences.
      ";

    $imagePaths = [];
    foreach ($images as $image) {
      $imagePaths[] = $image->getPathname();
    }

    $result = $this->vlmClient->infer($promptText, $imagePaths);

    $content = (string) $result['raw'];
    $parsed = $result['parsed'];

    if (is_array($parsed) && isset($parsed['issues'])) {

      $issues = array_values(array_map(
        fn($v) => strtolower(trim((string) $v)),
        is_array($parsed['issues']) ? $parsed['issues'] : []
      ));

      return new JsonResponse([
        'issues' => $issues,
        'raw' => $content,
      ]);
    }

    return new JsonResponse([
      'error' => 'parse_failed',
      'raw' => $content,
    ], 200);
  }

}
