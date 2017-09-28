
#usage

Drush cex places all the excluded files in the invironment specific folders.
on admin/config/development/configuration/exclude/config you can see the
environment specific files and edit them.

Drush cim looks of there is environment specific config and imports them after 
the normal import. The sync folder is always the production environment.


#Config example settings.local.php

```
$config['environment_config'] = array(
  'config' => '../config/default/test',
  'environment' => 'test',
  'ignore' => array(
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