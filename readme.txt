=== uWSGI Object Cache ===
Contributors: andrewbevitt
Tags: cache
Requires at least: 3.5
Tested up to: 4.2.2
Stable tag: 1.1
License: GPL2

uWSGI Object Cache for WordPress.

**WARNING:** This requires a manual install.

GitHub repository: https://github.com/andrewbevitt/uwsgi-object-cache

== Installation ==
**Move** the `object-cache.php` file to your WP_CONTENT_DIR.

Add the following to your wp-config.php

    define( 'UWSGI_CACHE', 'YOUR CACHE NAME HERE' );

NOTE: This only provides object caching, for page caching install batcache and add the following to wp-config.php:

    $batcache = array('group'=>UWSGI_CACHE, 'remote'=>1, 'debug'=>false);


