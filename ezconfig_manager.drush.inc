<?php

/**
 * @file
 * Contains turbo-charged config commands for a better partial workflow.
 */

use Drupal\Component\Serialization\Exception\InvalidDataTypeException;
use Drupal\Component\Serialization\Yaml;
use Drupal\config\StorageReplaceDataWrapper;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\StorageComparer;
use Drush\Config\StorageWrapper;
use Drush\Log\LogLevel;

/**
 * Implements hook_drush_command().
 */
function ezconfig_manager_drush_command() {
  $deps = ['drupal dependencies' => ['config']];
  $items['ezconfig-export'] = [
    'description' => 'Export configuration to a directory and apply an copy list.',
    'core' => ['8+'],
    'aliases' => ['ezcex']
  ];

  $items['ezconfig-import'] = $deps + [
    'description' => 'Import config from a config directory resepecting live content and a delete list.',
    'core' => ['8+'],
    'aliases' =>
    ['ezcim']
  ];

  return $items;
}

/**
 * Perform export.
 */
function drush_ezconfig_manager_ezconfig_export() {
  global $config_directories;
  $destination = $config_directories[CONFIG_SYNC_DIRECTORY];
  $environment = \Drupal::configFactory()->get('environment_config');

  drush_log(dt('Starting Exporting.'), LogLevel::DEBUG);
  if (($target = $config_directories[CONFIG_SYNC_DIRECTORY]) && $target !== TRUE) {
    $destination_dir = $target;
    drush_mkdir($destination_dir);
  }

  $patterns = [];
  $copy_list = $environment->get('copy');
  if (isset($copy_list) && !empty($copy_list)) {
    foreach ($copy_list as $copy) {
      // Allow for accidental .yml extension.
      if (substr($copy, -4) === 'export.yml') {
        $copy = substr($copy, 0, -4);
      }
      $patterns[] = '/^' . str_replace('\*', '(.*)', preg_quote($copy)) . '\.yml/';
    }
  }

  $temp_dir = \Drupal::service('file_system')->realpath(file_default_scheme() . "://ezconfig_temp");
  if (!realpath($temp_dir)) {
    \Drupal::service('file_system')->mkdir($temp_dir);
  }

  foreach ($patterns as $pattern) {
    foreach (file_scan_directory($destination_dir, $pattern) as $file_url => $file) {
      copy($file_url, $temp_dir . '/' . $file->filename);
    }
  }

  $result = _drush_config_export($destination, $destination_dir, FALSE);

  $dirs = ezconfig_manager_get_dirs();
  foreach ($patterns as $pattern) {
    foreach (file_scan_directory($destination_dir, $pattern) as $file_url => $file) {
      foreach ($dirs as $path => $dir) {
        if (!file_exists($path . '/' . $file->filename)) {
          copy($file_url, $path . '/' . $file->filename);
        }
      }
    }

    foreach (file_scan_directory($temp_dir, $pattern) as $file_url => $file) {
      copy($file_url, $destination_dir . '/' . $file->filename);
    }
  }

  return $result;
}

/**
 * Perform import.
 */
function drush_ezconfig_manager_ezconfig_import() {
  global $config_directories;
  $environment = \Drupal::configFactory()->get('environment_config');

  drush_log(dt('Starting import'), LogLevel::DEBUG);
  $source_dir = $config_directories[CONFIG_SYNC_DIRECTORY];

  /** @var \Drupal\Core\Config\StorageInterface $active_storage */
  $active_storage = \Drupal::service('config.storage');

  $source_storage = new StorageReplaceDataWrapper($active_storage);
  $file_storage = new FileStorage($source_dir);
  foreach ($file_storage->listAll() as $name) {
    $data = $file_storage->read($name);
    $source_storage->replaceData($name, $data);
  }

  $config = $environment->get('config');
  if (isset($config) && !empty($config)) {
    $file_storage = new FileStorage($config);
    foreach ($file_storage->listAll() as $name) {
      $data = $file_storage->read($name);
      $source_storage->replaceData($name, $data);
    }
  }

  /** @var \Drupal\Core\Config\ConfigManagerInterface $config_manager */
  $config_manager = \Drupal::service('config.manager');
  $storage_comparer = new StorageComparer($source_storage, $active_storage, $config_manager);

  if (!$storage_comparer->createChangelist()->hasChanges()) {
    return drush_log(dt('There are no changes to import.'), LogLevel::OK);
  }

  if (drush_get_option('preview', 'list') == 'list') {
    $change_list = [];
    foreach ($storage_comparer->getAllCollectionNames() as $collection) {
      $change_list[$collection] = $storage_comparer->getChangelist(NULL, $collection);
    }
    _drush_print_config_changes_table($change_list);
  }
  else {
    // Copy active storage to the temporary directory.
    $temp_dir = drush_tempdir();
    $temp_storage = new FileStorage($temp_dir);
    $source_dir_storage = new FileStorage($source_dir);
    foreach ($source_dir_storage->listAll() as $name) {
      if ($data = $active_storage->read($name)) {
        $temp_storage->write($name, $data);
      }
    }
    drush_shell_exec('diff -x %s -u %s %s', '*.git', $temp_dir, $source_dir);
    $output = drush_shell_exec_output();
    drush_print(implode("\n", $output));
  }

  if (drush_confirm(dt('Import the listed configuration changes?'))) {
    return drush_op('_drush_config_import', $storage_comparer);
  }
}
