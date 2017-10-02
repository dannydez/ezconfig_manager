
#usage

This module provides config per environment. You can use the standard drush 
commands to export and import the config.

Drush cex automatically adds all the files defined in the 
`$config['environment_config']['copy']` in the config directories (except sync).

There is a possibility to exclude single config files using the 
`$config['environment_config']['exclude_config']` all files in this array will
removed from the sync directory.

There is a possibility to exclude modules using the 
`$config['environment_config']['exclude_modules']`  all files of these modules
will be removed from the sync directory and the module will be removed from the
core.extension.yml.

You can change the specific config using 
`admin/config/development/configuration/environment/config`

Drush cim imports the whole sync directory and after that he will import the 
config from the `$config['environment_config']['config']` directory.

The sync folder is basically the prod config.


#Config example settings.local.php

```
$config['environment_config'] = array(
  'config' => '../config/default/test',
  'environment' => 'test',
  'copy' => array(
    'system.mail',
    'system.site',
  ),
  'exclude_config' => array(
    '',
  ),,
  'exclude_modules' => array(
    'devel',
  ),
);
```

#composer.json


```
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/dannydez/ezconfig_manager"
    }
]

"require": {
    "ezmod/ezconfig_manager": "dev-master"
}

"extra": {
    "installer-paths": {
        "htdocs/modules/custom/{$name}": ["type:drupal-custom-module"]
    }
}
```
