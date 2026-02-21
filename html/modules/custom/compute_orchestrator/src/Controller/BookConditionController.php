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

    $promptText = "Assess the physical condition of the book based only on visible damage in the provided images. Do not speculate about pages not shown. Return only JSON with keys condition_grade and visible_issues (array of short strings).";

    $imagePaths = [];
    foreach ($images as $image) {
      $imagePaths[] = $image->getPathname();
    }

    $result = $this->vlmClient->infer($promptText, $imagePaths);

    $content = (string) $result['raw'];
    $parsed = $result['parsed'];

    if (is_array($parsed) && isset($parsed['condition_grade']) && isset($parsed['visible_issues'])) {
      return new JsonResponse([
        'condition_grade' => (string) $parsed['condition_grade'],
        'visible_issues' => array_values(array_map('strval', $parsed['visible_issues'])),
        'raw' => $content,
      ]);
    }

    return new JsonResponse([
      'error' => 'parse_failed',
      'raw' => $content,
    ], 200);
  }

}
