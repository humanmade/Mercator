<table width="100%">
	<tr>
		<td align="left" width="70">
			<strong>Mercator</strong><br />
			WordPress multisite domain mapping for the modern era.
		</td>
		<td align="right" width="20%">
			<a href="https://travis-ci.org/humanmade/Mercator">
				<img src="https://travis-ci.org/humanmade/Mercator.svg?branch=master" alt="Build status">
			</a>
			<a href="http://codecov.io/github/humanmade/Mercator?branch=master">
				<img src="http://codecov.io/github/humanmade/Mercator/coverage.svg?branch=master" alt="Coverage via codecov.io" />
			</a>
		</td>
	</tr>
	<tr>
		<td>
			A <strong><a href="https://hmn.md/">Human Made</a></strong> project. Maintained by @rmccue.
		</td>
		<td align="center">
			<img src="https://hmn.md/content/themes/hmnmd/assets/images/hm-logo.svg" width="100" />
		</td>
	</tr>
</table>

Mercator is a domain mapping plugin for the New World. Using new features
included with WordPress 3.9 and later, Mercator builds on the new multisite
features and abilities to improve your world.

Stop using outdated practices, and start making sense.

## Requirements
Mercator requires WordPress 3.9 or newer for the new sunrise processes. Mercator
also requires PHP 5.3+ due to the use of namespaced code.

## What is Domain Mapping?
When setting up a Multisite install, the network is configured to create sites either as subdomains of the root site (e.g. `subsite.network.com`) or subfolders (e.g. `network.com/subsite`).

Domain Mapping is the process of mapping any arbitrary domain (called an alias) to load a site. If an alias of `arbitrarydomain.com` is set for the site `network.com/subsite`, the site and wp-admin interface can be accessed over either the alias or the original URL.

Internally, Mercator looks at a request's domain and informs WordPress [what set of tables to use](https://www.youtube.com/watch?t=249&v=3evwb1SiaBY#t=5m42s). User authentication cookies are set for all domains in the network, so a user logs in on one site and is authenticated across all.

## Installation
Mercator must be loaded during sunrise.

We recommend dropping Mercator's directory into your `mu-plugins` directory. You may need to rename the folder from `Mercator-master` to `mercator`.

Then create a `wp-content/sunrise.php` file with the following:

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

Aliases are created in the Network Admin > Sites > Edit Site screen.

DNS for mapped domains must be configured for the domain to point to the WordPress
installation, as well as configuring the web server to route requests for the
domain to the WordPress application.

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
Created by Human Made for high volume and large-scale sites, such as [Happytables](http://happytables.com/). We run Cavalcade on sites with millions of monthly page views, and thousands of sites.

Written and maintained by [Ryan McCue](https://github.com/rmccue). Thanks to all our [contributors](https://github.com/humanmade/Mercator/graphs/contributors).

Mercator builds on concepts from [WPMU Domain Mapping][], written by Donncha O'Caoimh, Ron Rennick, and contributors.

Mercator relies on WordPress core, building on core functionality added in [WP27003][]. Thanks to all involved in the overhaul, including Andrew Nacin and Jeremy Felt.

[WPMU Domain Mapping]: http://wordpress.org/plugins/wordpress-mu-domain-mapping/
[WP27003]: https://core.trac.wordpress.org/ticket/27003

Interested in joining in on the fun? [Join us, and become human!](https://hmn.md/is/hiring/)
