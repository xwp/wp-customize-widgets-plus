<?php
/**
 * Plugin Name: Customize Widgets Plus
 * Plugin URI: https://github.com/xwp/wp-customize-widgets-plus
 * Description: ...
 * Version: 0.1
 * Author:  XWP
 * Author URI: https://xwp.co/
 * License: GPLv2+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: customize-widgets-plus
 * Domain Path: /languages
 *
 * Copyright (c) 2015 XWP (https://xwp.co/)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2 or, at
 * your discretion, any later version, as published by the Free
 * Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301 USA
 */

if ( version_compare( phpversion(), '5.3', '>=' ) ) {
	require_once __DIR__ . '/php/class-plugin-base.php';
	require_once __DIR__ . '/php/class-plugin.php';
	$class_name = '\CustomizeWidgetsPlus\Plugin';
	$GLOBALS['customize_widgets_plus_plugin'] = new $class_name();
} else {
	function customize_widgets_plus_php_version_error() {
		printf( '<div class="error"><p>%s</p></div>', esc_html__( 'Customize Widgets Plus plugin error: Your version of PHP is too old to run this plugin. You must be running PHP 5.3 or higher.', 'customize-widgets-plus' ) );
	}
	if ( defined( 'WP_CLI' ) ) {
		WP_CLI::warning( __( 'Customize Widgets Plus plugin error: Your PHP version is too old. You must have 5.3 or higher.', 'customize-widgets-plus' ) );
	} else {
		add_action( 'admin_notices', 'customize_widgets_plus_php_version_error' );
	}
}
