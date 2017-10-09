<?php
namespace Drupal\ezconfig_manager\Commands\config;

use Consolidation\AnnotatedCommand\CommandData;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\StorageInterface;
use Drush\Commands\DrushCommands;
use Drush\Exceptions\UserAbortException;
use Drush\Drupal\Commands\config\ConfigCommands;

class EzConfigExportCommands extends DrushCommands
{

    /**
     * @var ConfigManagerInterface
     */
    protected $configManager;

    /**
     * @var StorageInterface
     */
    protected $configStorage;

    /**
     * @var StorageInterface
     */
    protected $configStorageSync;

    /**
     * @return ConfigManagerInterface
     */
    public function getConfigManager()
    {
        return $this->configManager;
    }

    /**
     * @return StorageInterface
     */
    public function getConfigStorage()
    {
        return $this->configStorage;
    }

    /**
     * @return StorageInterface
     */
    public function getConfigStorageSync()
    {
        return $this->configStorageSync;
    }


    /**
     * @param ConfigManagerInterface $configManager
     * @param StorageInterface $configStorage
     * @param StorageInterface $configStorageSync
     */
    public function __construct(ConfigManagerInterface $configManager, StorageInterface $configStorage, StorageInterface $configStorageSync)
    {
        parent::__construct();
        $this->configManager = $configManager;
        $this->configStorage = $configStorage;
        $this->configStorageSync = $configStorageSync;
    }

    /**
     * Export Drupal configuration to a directory.
     *
     * @command config:export
     * @interact-config-label
     * @param string $label A config directory label (i.e. a key in $config_directories array in settings.php).
     * @option add Run `git add -p` after exporting. This lets you choose which config changes to sync for commit.
     * @option commit Run `git add -A` and `git commit` after exporting.  This commits everything that was exported without prompting.
     * @option message Commit comment for the exported configuration.  Optional; may only be used with --commit.
     * @option destination An arbitrary directory that should receive the exported files. An alternative to label argument.
     * @usage drush config:export --destination
     *   Export configuration; Save files in a backup directory named config-export.
     * @aliases cex,config-export
     */
    public function export($label = null, $options = ['add' => false, 'commit' => false, 'message' => null, 'destination' => ''])
    {

        // Get destination directory.
        $destination_dir = ConfigCommands::getDirectory($label, $options['destination']);

        // Do the actual config export operation.
        $preview = $this->doExport($options, $destination_dir);

        // Do the VCS operations.
        $this->doAddCommit($options, $destination_dir, $preview);
    }

    public function doExport($options, $destination_dir)
    {
      // Prepare the configuration storage for the export.
      if ($destination_dir == \config_get_config_directory(CONFIG_SYNC_DIRECTORY)) {
        $target_storage = $this->getConfigStorageSync();
      } else {
        $target_storage = new FileStorage($destination_dir);
      }

      // Get Environment config
      $environment = \Drupal::configFactory()->get('environment_config');

      // Get Files to copy.
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

      // Get all files to exclude.
      $exclude_patterns = [];
      $exclude_list = $environment->get('exclude_config');
      $exclude_modules = $environment->get('exclude_modules');

      foreach ($exclude_modules as $module) {
        $exclude_list[] = $module . '.*';
      }

      if (isset($exclude_list) && !empty($exclude_list)) {
        foreach ($exclude_list as $exclude) {
          // Allow for accidental .yml extension.
          if (substr($exclude, -4) === 'export.yml') {
            $exclude = substr($exclude, 0, -4);
          }
          $exclude_patterns[] = '/^' . str_replace('\*', '(.*)', preg_quote($exclude)) . '\.yml/';
        }
      }

      $temp_dir = \Drupal::service('file_system')->realpath(file_default_scheme() . "://ezconfig_temp");
      if (!realpath($temp_dir)) {
        \Drupal::service('file_system')->mkdir($temp_dir);
      }

      foreach ($patterns as $pattern) {
        foreach (file_scan_directory($destination_dir, $pattern) as $file_url => $file) {
          $dirs = explode('/', str_replace([$destination_dir, $file->filename],'' ,$file_url));

          $dir_path = '';
          foreach ($dirs as $dir) {
            if (!empty($dir)) {
              if (!realpath($temp_dir . $dir_path . '/' . $dir)) {
                \Drupal::service('file_system')->mkdir($temp_dir . $dir_path . '/' . $dir);
              }

              $dir_path .= '/' . $dir;
            }
          }

          copy($file_url, str_replace($destination_dir, $temp_dir, $file_url));
          $env_file = str_replace($destination_dir, $environment->get('config'), $file_url);
          if (file_exists($env_file)) {
            copy($env_file, $file_url);
          }
        }
      }

        if (count(glob($destination_dir . '/*')) > 0) {
            // Retrieve a list of differences between the active and target configuration (if any).
            $config_comparer = new StorageComparer($this->getConfigStorage(), $target_storage, $this->getConfigManager());
            if (!$config_comparer->createChangelist()->hasChanges()) {
                $this->logger()->notice(dt('The active configuration is identical to the configuration in the export directory (!target).', array('!target' => $destination_dir)));
//                return;
            }

            if ($config_comparer->createChangelist()->hasChanges()) {
              $this->output()
                ->writeln("Differences of the active config to the export directory:\n");
              $change_list = array();
              foreach ($config_comparer->getAllCollectionNames() as $collection) {
                $change_list[$collection] = $config_comparer->getChangelist(NULL, $collection);
              }
              // Print a table with changes in color, then re-generate again without
              // color to place in the commit comment.
              ConfigCommands::configChangesTablePrint($change_list);
              $tbl = ConfigCommands::configChangesTableFormat($change_list);
              $preview = $tbl->getTable();
              if (!stristr(PHP_OS, 'WIN')) {
                $preview = str_replace("\r\n", PHP_EOL, $preview);
              }

              if (!$this->io()
                ->confirm(dt('The .yml files in your export directory (!target) will be deleted and replaced with the active config.', array('!target' => $destination_dir)))) {
                throw new UserAbortException();
              }
              // Only delete .yml files, and not .htaccess or .git.
              $target_storage->deleteAll();
            }
        }

        // Write all .yml files.
        ConfigCommands::copyConfig($this->getConfigStorage(), $target_storage);

        $yaml = Yaml::decode(file_get_contents($destination_dir . '/core.extension.yml'));

        foreach ($yaml['module'] as $module => $enabled) {
          if (in_array($module, $exclude_modules)) {
            unset($yaml['module'][$module]);
          }
        }

        file_put_contents($destination_dir . '/core.extension.yml', Yaml::encode($yaml));

        $dirs = ezconfig_manager_get_dirs();
      foreach ($patterns as $pattern) {
        foreach (file_scan_directory($destination_dir, $pattern) as $file_url => $file) {
          foreach ($dirs as $path => $dir) {
            $conf_dirs = explode('/', str_replace([$destination_dir, $file->filename], '', $file_url));

            $conf_dir_path = '';
            foreach ($conf_dirs as $conf_dir) {
              if (!empty($conf_dir)) {
                if (!realpath($path . $conf_dir_path . '/' . $conf_dir)) {
                  mkdir($path . $conf_dir_path . '/' . $conf_dir);
                }

                $conf_dir_path .= '/' . $conf_dir;
              }
            }

            if (!file_exists(str_replace($destination_dir, $path, $file_url))) {
              copy($file_url, str_replace($destination_dir, $path, $file_url));
            }
          }
        }

        foreach (file_scan_directory($temp_dir, $pattern) as $file_url => $file) {
          copy($file_url, $destination_dir . '/' . $file->filename);
        }
      }

      $file_service = \Drupal::service('file_system');
      foreach ($exclude_patterns as $pattern) {
        foreach (file_scan_directory($destination_dir, $pattern) as $file_url => $file) {
          $file_service->unlink($file_url);
        }
      }

        $this->logger()->success(dt('Configuration successfully exported to !target.', ['!target' => $destination_dir]));
        drush_backend_set_result($destination_dir);
        return isset($preview) ? $preview : 'No existing configuration to diff against.';
    }

  public function doAddCommit($options, $destination_dir, $preview)
  {
    // Commit or add exported configuration if requested.
    if ($options['commit']) {
      // There must be changed files at the destination dir; if there are not, then
      // we will skip the commit step.
      $result = drush_shell_cd_and_exec($destination_dir, 'git status --porcelain .');
      if (!$result) {
        throw new \Exception(dt("`git status` failed."));
      }
      $uncommitted_changes = drush_shell_exec_output();
      if (!empty($uncommitted_changes)) {
        $result = drush_shell_cd_and_exec($destination_dir, 'git add -A .');
        if (!$result) {
          throw new \Exception(dt("`git add -A` failed."));
        }
        $comment_file = drush_save_data_to_temp_file($options['message'] ?: 'Exported configuration.'. $preview);
        $result = drush_shell_cd_and_exec($destination_dir, 'git commit --file=%s', $comment_file);
        if (!$result) {
          throw new \Exception(dt("`git commit` failed.  Output:\n\n!output", ['!output' => implode("\n", drush_shell_exec_output())]));
        }
      }
    }
    elseif ($options['add']) {
      drush_shell_exec_interactive('git add -p %s', $destination_dir);
    }
  }

  /**
   * Implements hook_valdate().
   */
  public function validate(CommandData $commandData) {
    $destination = $commandData->input()->getOption('destination');

    if ($destination === TRUE) {
      // We create a dir in command callback. No need to validate.
      return;
    }

    if (!empty($destination)) {
      if (!file_exists($destination)) {
        $parent = dirname($destination);
        if (!is_dir($parent)) {
          throw new \Exception('The destination parent directory does not exist.');
        }
        if (!is_writable($parent)) {
          throw new \Exception('The destination parent directory is not writable.');
        }
      }
      else {
        if (!is_dir($destination)) {
          throw new \Exception('The destination is not a directory.');
        }
        if (!is_writable($destination)) {
          throw new \Exception('The destination directory is not writable.');
        }
      }
    }
  }

}
