<?php

namespace Drupal\entity_sync\Commands;

use Drupal\entity_sync\Service\EntitySyncManager;
use Drush\Commands\DrushCommands;

/**
 * Drush commandfile for Entity Sync.
 *
 * This class is intentionally thin: all the logic lives in the service
 * manager so it can be reused and tested independently.
 */
class EntitySyncDrushCommands extends DrushCommands {

  /**
   * @var \Drupal\entity_sync\Service\EntitySyncManager
   */
  protected EntitySyncManager $manager;

  /**
   * Constructs the command wrapper.
   */
  public function __construct(EntitySyncManager $manager) {
    parent::__construct();
    $this->manager = $manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(\Symfony\Component\DependencyInjection\ContainerInterface $container): self {
    return new static(
      $container->get('entity_sync.manager')
    );
  }

  /**
   * Import content from entity files.
   *
   * @command entity:sync:import
   * @aliases esync:im
   * @option delete Remove previous entities.
   * @option new Always create new entities.
   * @option refresh-files Recreate file/media from __SOURCE__ (enabled by default).
   * @usage drush esync:im --file 0.menu_link_content.footer.yml
   * @usage drush esync:im --new --file 0.menu_link_content.footer.yml
   * @usage drush esync:im --file 1.node.puzz_landing.yml
   * @usage drush esync:im --no-refresh-files --file 1.node.puzz_landing.yml
   */
  public function import(array $options = ['delete' => 0, 'new' => 0, 'file' => null, 'refresh-files' => 1]): void {
    $this->manager->import($options);
  }

  /**
   * Get entity info and templates from entities.
   *
   * @command entity:sync:get:template
   * @aliases esync:gt
   * @option entity-type Filter entity_type (optional, defaults to all supported).
   * @option output Write templates to files instead of printing YAML.
   * @option bundle Select bundle name.
   * @usage drush entity:sync:get:template --output
   * @usage drush entity:sync:get:template --entity-type node --bundle puzz_landing --output
   */
  public function getTemplate(array $options = ['entity-type' => null, 'output' => 0, 'bundle' => null]): void {
    $this->manager->getTemplate($options);
  }

}

