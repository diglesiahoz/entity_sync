<?php

namespace Drupal\entity_sync\Service;

use Drupal\entity_sync\Service\EntitySyncEngine;

/**
 * Manager for Entity Sync operations.
 *
 * Drush commands should remain thin wrappers and delegate to this service.
 */
class EntitySyncManager {
  public function __construct(
    protected EntitySyncEngine $engine,
  ) {}

  /**
   * Import content from entity .yml files.
   *
   * @param array $options
   *   Drush options passed through from the command.
   */
  public function import(array $options = []): void {
    $this->engine->import($options);
  }

  /**
   * Generate import templates from entity definitions.
   *
   * @param array $options
   *   Drush options passed through from the command.
   */
  public function getTemplate(array $options = []): void {
    $this->engine->getTemplate($options);
  }

}

