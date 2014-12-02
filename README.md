# Mercator
*WordPress multisite domain mapping for the modern era.*

Mercator is a domain mapping plugin for the New World. Using new features
included with WordPress 3.9 and later, Mercator builds on the new multisite
features and abilities to improve your world.

Stop using outdated practices, and start making sense.

## Requirements
Mercator requires WordPress 3.9 or newer for the new sunrise processes. Mercator
also requires PHP 5.3+ due to the use of namespaced code.

## Installation
Mercator must be loaded during sunrise.

We recommend dropping Mercator's directory into your `mu-plugins` directory,
then creating a `wp-content/sunrise.php` file with the following:

```php
<?php
// Default mu-plugins directory if you haven't set it
defined( 'WPMU_PLUGIN_DIR' ) or define( 'WPMU_PLUGIN_DIR', WP_CONTENT_DIR . '/mu-plugins' );

require WPMU_PLUGIN_DIR . '/mercator/mercator.php';

```

Additionally, in order for `sunrise.php` to be loaded, you must add the following to your `wp-config.php`:

```
define('SUNRISE', true);
```

## Upgrading from WPMU Domain Mapping
This plugin is a complete replacement for WPMU Domain Mapping. The database
structure is fully compatible.

Note that if you have Domain Mapping code in your sunrise, you should remove
this and replace it with the recommended sunrise code above. Mercator hooks in
to WordPress' internal site mapping code rather than replacing it, unlike Domain
Mapping.

## License
Mercator is licensed under the GPLv2 or later.

## Credits
Mercator is written and maintained by @rmccue.

Mercator builds on concepts from [WPMU Domain Mapping][], written by Donncha
O'Caoimh, Ron Rennick, and contributors.

Mercator relies on WordPress core, building on core functionality added in
[WP27003][]. Thanks to all involved in the overhaul, including Andrew Nacin and
Jeremy Felt.

[WPMU Domain Mapping]: http://wordpress.org/plugins/wordpress-mu-domain-mapping/
[WP27003]: https://core.trac.wordpress.org/ticket/27003
