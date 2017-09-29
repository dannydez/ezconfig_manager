
#usage

This module provides config per environment. You can use the standard drush 
commands to export and import the config.

Drush cex automatically adds all the files defined in the 
`$config['environment_config']['copy']` in the config directories (except sync).

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
    'webform.webform.*',
  )
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
