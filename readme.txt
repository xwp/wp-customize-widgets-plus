=== Customize Widgets Plus ===
Contributors: westonruter, xwp, newscorpau
Requires at least: trunk
Tested up to: trunk
Stable tag: trunk
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Tags: customizer, customize, widgets

Lab features and a testbed for improvements to Widgets and the Customizer.

== Description ==

This plugin consists of lab features and a testbed for improvements to Widgets and the Customizer.

Requires PHP 5.3+.

**Development of this plugin is done [on GitHub](https://github.com/xwp/wp-customize-widgets-plus). Pull requests welcome. Please see [issues](https://github.com/xwp/wp-customize-widgets-plus/issues) reported there before going to the [plugin forum](https://wordpress.org/support/plugin/customize-widgets-plus).**

Current features:

= Non-Autoloaded Widget Options  =

Widgets are stored in options (normally). Multi-widgets store all of their instances in one big serialized array specific to that type. When there are many widgets of a given type, the size of the serialized array can grow very large. What's more is that `WP_Widget` does not explicitly `add_option(... 'no' )` before calling `update_option()`, and so all of the settings get added with autoloading. This is very bad when using Memcached Object Cache specifically because it can result in the total `alloptions` cache key to become larger than 1MB and result in Memcached failing to store the value in the cache. On WordPress.com the result is a ["Matt's fault" error](https://github.com/Automattic/vip-quickstart/blob/master/www/wp-content/mu-plugins/alloptions-limit.php) which has to be fixed by the VIP team. Widget settings should not be stored in serialized arrays to begin with; each widget instance should be stored in a custom post type. But until this is done we should stop autoloading options. See also [#26876](https://core.trac.wordpress.org/ticket/26876) and [#23909](https://core.trac.wordpress.org/ticket/23909).

= Widget Number Incrementing =

Implements fixes for Core issue [#32183](https://core.trac.wordpress.org/ticket/32183) (Widget ID auto-increments conflict for concurrent users). The stored widget_number option provides a centralized auto-increment number for whenever a widget is instantiated, even widgets in the Customizer that are not yet saved.

= Efficient Multidimensional Setting Sanitizing =

Settings for multidimensional options and theme_mods are extremely inefficient to sanitize in Core because all of the Customizer settings registered for the subsets of the `option` or `theme_mod` need filters that are added to the entire value, meaning sanitizing one single setting will result in all filters for all other settings in that `option`/`theme_mod` will also get applied. This functionality seeks to improve this as much as possible, especially for widgets which are the worst offenders. Implements partial fix for [#32103](https://core.trac.wordpress.org/ticket/32103).

= HTTPS Resource Proxy =

When `FORCE_SSL_ADMIN` is enabled (such as on WordPress.com), the Customizer will load the site into the preview iframe using HTTPS as well. If, however, external resources are being referenced which are not HTTPS, they will fail to load due to the browser's security model raise mixed content warnings. This functionality will attempt to rewrite any HTTP URLs to be HTTPS ones via a WordPress-based proxy.

= Widget Posts =

Store widget instances in posts instead of options. Requires trunk due to patch committed in [#32474](https://core.trac.wordpress.org/ticket/32474). More details forthcoming...

== Changelog ==

= 0.2 =
Add Widget Posts module. See [changelog](https://github.com/xwp/wp-customize-widgets-plus/compare/0.1...0.2).

= 0.1 =
Initial release.
