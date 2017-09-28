
#usage

This module overrides the drush cex and cim, if this is not the production,
if the custom config dir (specified in settings.php), has config it will be
imported after the normal configuration. 

the $config['environment_config']['ignore'] (example below) are copied in 
the other config directories if this site.

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
