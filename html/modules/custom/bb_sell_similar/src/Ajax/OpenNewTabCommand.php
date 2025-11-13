<?php

namespace Drupal\bb_sell_similar\Ajax;

use Drupal\Core\Ajax\CommandInterface;

class OpenNewTabCommand implements CommandInterface {

  protected string $url;

  public function __construct(string $url) {
    $this->url = $url;
  }

  public function render(): array {
    return [
      'command' => 'openNewTab',
      'url' => $this->url,
    ];
  }
}

