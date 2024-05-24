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

    $token = $request->headers->get('Authorization');
    \Drupal::logger('todo_search')->info('Received token: ' . $token);

    $query = \Drupal::entityQuery('node')
      ->accessCheck(TRUE)
      ->condition('type', 'to_do_list')
      ->condition('status', 1); // Published nodes only

    if ($status = $request->query->get('status')) {
      $query->condition('field_to_do_list_status', $status);
    }

    if ($context = $request->query->get('context')) {
      $query->condition('field_tags.entity.name', $context);
    }

    if ($due_date = $request->query->get('due_date')) {
      $query->condition('field_to_do_list_due_date', $due_date, '<=');
    }

    if ($due_date_upcoming = $request->query->get('due_date_upcoming')) {
      $query->condition('field_to_do_list_due_date', $due_date_upcoming, '>=');
    }

    if ($due_date_overdue = $request->query->get('due_date_overdue')) {
      $query->condition('field_to_do_list_due_date', $due_date_overdue, '<');
    }

    if ($request->query->get('due_date_empty')) {
      $query->notExists('field_to_do_list_due_date');
    }

    if ($created = $request->query->get('created_after')) {
      $query->condition('created', strtotime($created), '>=');
    }

    if ($priority = $request->query->get('priority')) {
      $query->condition('field_to_do_list_priority', $priority);
    }

    if ($keyword = $request->query->get('keyword')) {
      $group = $query->orConditionGroup()
        ->condition('title', '%' . $query->escapeLike($keyword) . '%', 'LIKE')
        ->condition('field_to_do_list_description', '%' . $query->escapeLike($keyword) . '%', 'LIKE');
      $query->condition($group);
    }

    if ($author = $request->query->get('author')) {
      $query->condition('uid', $author);
    }

    if ($assignee = $request->query->get('assignee')) {
      $query->condition('field_to_do_list_assignee', $assignee);
    }

    if ($sort = $request->query->get('sort')) {
      $query->sort('created', $sort);
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

