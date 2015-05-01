<?php

namespace CustomizeWidgetsPlus;

global $customize_widgets_plus_plugin;

require_once __DIR__ . '/php/class-plugin-base.php';
require_once __DIR__ . '/php/class-plugin.php';

$customize_widgets_plus_plugin = new Plugin();

/**
 * @return Plugin
 */
function get_plugin_instance() {
	global $customize_widgets_plus_plugin;
	return $customize_widgets_plus_plugin;
}
