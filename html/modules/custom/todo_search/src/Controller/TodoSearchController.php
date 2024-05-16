<?php declare(strict_types = 1);

namespace Drupal\todo_search\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\node\Entity\Node;

/**
 * Returns responses for Todo search routes.
 */
final class TodoSearchController extends ControllerBase {

  /**
   * Builds the response.
   */
  public function __invoke(): array {

    $build['content'] = [
      '#type' => 'item',
      '#markup' => $this->t('It works!'),
    ];

    return $build;
  }

  public function search(Request $request) {

    $query = \Drupal::entityQuery('node')
      ->accessCheck(TRUE)
      ->condition('type', 'to_do_list')
      ->condition('status', 0); // Published nodes only


    if ($status = $request->query->get('status')) {
      $query->condition('field_to_do_list_status', $status);
    }

    if ($context = $request->query->get('context')) {
      $query->condition('field_tags.entity.name', $context);
    }

    if ($due_date = $request->query->get('due_date')) {
      $query->condition('field_to_do_list_due_date', $due_date, '<=');
    }

    $nids = $query->execute();

    $nodes = Node::loadMultiple($nids);
    $tasks = [];

    foreach ($nodes as $node) {
      $tasks[] = [
        'id' => $node->id(),
        'title' => $node->getTitle(),
        'field_to_do_list_description' => $node->get('field_to_do_list_description')->value,
        'field_to_do_list_status' => $node->get('field_to_do_list_status')->value,
        'field_to_do_list_priority' => $node->get('field_to_do_list_priority')->value,
        'field_to_do_list_tags' => array_map(function ($tag) {
          return $tag->name->value;
        }, $node->get('field_to_do_list_tags')->referencedEntities()),
        'field_to_do_list_due_date' => $node->get('field_to_do_list_due_date')->value,
      ];
    }

    return new JsonResponse(['tasks' => $tasks]);
  }


}
