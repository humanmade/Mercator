# Mercator
*WordPress multisite domain mapping for the modern era.*

Mercator is a domain mapping plugin for the New World. Using new features
included with WordPress 3.9 and later, Mercator builds on the new multisite
features and abilities to improve your world.

Stop using outdated practices, and start making sense.

**Important: Mercator is not production ready. Do not use.**

## Requirements
Mercator requires WordPress 3.9 or newer for the new sunrise processes.

## Installation
Mercator must be loaded during sunrise.

We recommend dropping Mercator's directory into your `mu-plugins` directory,
then creating a `wp-content/sunrise.php` file with the following:

```php
<?php

require WPMU_PLUGIN_DIR . '/mercator/mercator.php';

```

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
