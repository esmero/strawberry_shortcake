<?php

namespace Drupal\strawberry_shortcake\Ajax;

use Drupal\Core\Ajax\CommandInterface;

/**
 * AJAX command that sets the browser URL using the History State
 *
 * This command is implemented in Drupal.AjaxCommands.prototype.SbsBroadCastVectors
 * in js/cluster_ui.js
 */
class SbsUpdateClusterMemberInfo implements CommandInterface {

  /**
   * Model and Vector to dispatch for a View Refresh
   *
   * @var string
   */
  protected $active_cluster;

  /**
   * Constructs a new command instance.
   *
   * @param string $url
   *   The URL to be set in the browser
   */
  public function __construct(string $active_cluster) {
    $this->active_cluster = $active_cluster;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    return [
      'command' => 'SbsUpdateClusterMemberInfo',
      'active_cluster' => $this->active_cluster,
    ];
  }

}
