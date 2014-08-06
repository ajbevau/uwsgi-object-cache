uWSGI WordPress Object Cache
==================

This plugin uses the uWSGI cache for object caching in WordPress.

**WARNINGS**

1. This plugin requires manual install
2. There is a bug in uWSGI so you need to include [this](https://github.com/unbit/uwsgi/pull/660)

**INSTALL**

1. Move the `object-code.php` file to your `WP_CONTENT_DIR`
2. Add `define( 'UWSGI_CACHE', 'YOUR CACHE NAME HERE' );` to `wp-config.php`


