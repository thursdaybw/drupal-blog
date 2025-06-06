<?php

namespace Drupal\book_forge\Drush\Commands;

use Drupal\taxonomy\Entity\Term;
use Drupal\book_forge\Entity\ListableBook;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Book Forge Drush commands.
 */
final class BookForgeCommands extends DrushCommands {

  #[CLI\Command(name: 'book-forge:import-json', aliases: ['bf:import'])]
  #[CLI\Argument(name: 'json_path', description: 'Path to the JSON file.')]
  public function importJson(string $json_path): void {
    if (!file_exists($json_path)) {
      $this->output()->writeln("❌ File not found: $json_path");
      return;
    }

    $json = file_get_contents($json_path);
    $books = json_decode($json, TRUE);

    if (!is_array($books)) {
      $this->output()->writeln("❌ Invalid JSON structure.");
      return;
    }

    foreach ($books as $book) {
      if (empty($book['title']) || empty($book['author'])) {
        $this->output()->writeln("⚠️ Skipping book with missing title/author.");
        continue;
      }

      $node = ListableBook::create([
        'label' => $book['title'],
        'field_genre_main' => $this->getOrCreateTerm($book['genre_main'], 'genre_main'),
        'field_genre_sub' => $this->getOrCreateTerm($book['genre_sub'], 'genre_sub'),
        'field_tags' => array_map(function ($tag) {
          return $this->getOrCreateTerm($tag, 'book_tags');
        }, $book['tags'] ?? []),
      ]);
      $node->save();

      $this->output()->writeln("✅ Imported: {$book['title']} by {$book['author']}");
    }
  }

  private function getOrCreateTerm(string $name, string $vocab): int {
    $name = trim($name);
    if (!$name) {
      return 0;
    }
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties([
      'name' => $name,
      'vid' => $vocab,
    ]);
    if ($term = reset($terms)) {
      return $term->id();
    }
    $term = Term::create([
      'name' => $name,
      'vid' => $vocab,
    ]);
    $term->save();
    return $term->id();
  }
}

