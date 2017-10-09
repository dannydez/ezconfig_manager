<?php

namespace Drupal\ezconfig_manager;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Replace core's config service with our own.
 */
class EzconfigManagerServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    $definitions = $container->getDefinitions();

    if (isset($definitions['config.export.commands'])) {
      $definition = $container->getDefinition('config.export.commands');
      $definition->setClass('Drupal\ezconfig_manager\Commands\config\EzConfigExportCommands');
      $definition->setArguments(
        [
          new Reference('config.manager'),
          new Reference('config.storage'),
          new Reference('config.storage.sync'),
        ]
      );
    }

    if (isset($definitions['config.import.commands'])) {
      $definition = $container->getDefinition('config.import.commands');
      $definition->setClass('Drupal\ezconfig_manager\Commands\config\EzConfigImportCommands');
      $definition->setArguments(
        [
          new Reference('config.manager'),
          new Reference('config.storage'),
          new Reference('config.storage.sync'),
          new Reference('module_handler'),
          new Reference('event_dispatcher'),
          new Reference('lock'),
          new Reference('config.typed'),
          new Reference('module_installer'),
          new Reference('theme_handler'),
          new Reference('string_translation'),
        ]
      );
    }
  }

}
