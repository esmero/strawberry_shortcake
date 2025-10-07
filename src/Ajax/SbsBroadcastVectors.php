<?php

namespace Drupal\strawberry_shortcake\Ajax;

use Drupal\Core\Ajax\CommandInterface;

/**
 * AJAX command that sets the browser URL using the History State
 *
 * This command is implemented in Drupal.AjaxCommands.prototype.SbsBroadCastVectors
 * in js/cluster_ui.js
 */
class SbsBroadcastVectors implements CommandInterface {

  /**
   * Model and Vector to dispatch for a View Refresh
   *
   * @var array
   */
  protected $vector_per_model;

  /**
   * Constructs a new command instance.
   *
   * @param string $url
   *   The URL to be set in the browser
   */
  public function __construct(array $vector_per_model) {
    $this->vector_per_model = $vector_per_model;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    return [
      'command' => 'SbsBroadCastVectors',
      'vectors' => $this->vector_per_model,
    ];
  }

}
