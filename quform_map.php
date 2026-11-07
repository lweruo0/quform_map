<?php
/**
 * Plugin Name: Quform MAP
 * Plugin URI: https://github.com/lweruo0/quform_map
 * Description: Pflegt eine zentrale PHP-Datei mit sprechenden Namen für Quform-Formular- und Feld-IDs und stellt sie PHP und JavaScript zur Verfügung.
 * Version: 2026.11.07
 * Requires PHP: 8.0
 * Author: Oliver Ruoß
 * License: GPL2
 * Text Domain: quform_map
 *
 * @package quform_map
 * @author Oliver Ruoß
 */
if (! defined ( 'ABSPATH' )) {
	exit ();
}

define ( 'QUFORM_MAP_VERSION', '2026.11.07' );
define ( 'QUFORM_MAP_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-quform-map.php';

if (is_admin ()) {
	require_once __DIR__ . '/includes/class-quform-map-admin.php';
	quform_map_admin::instance ();
}

/**
 * Dieses Plugin als erstes laden, damit andere Plugins die Klasse quform_map schon beim
 * Einbinden ihrer Dateien nutzen können (WordPress lädt Plugins in der Reihenfolge von active_plugins).
 *
 * @return void
 */
function quform_map_load_first() {
	$self = plugin_basename ( QUFORM_MAP_PLUGIN_FILE );
	$active = get_option ( 'active_plugins' );
	if (! is_array ( $active )) {
		return;
	}
	$pos = array_search ( $self, $active, true );
	if (false === $pos || 0 === $pos) {
		return;
	}
	array_splice ( $active, $pos, 1 );
	array_unshift ( $active, $self );
	update_option ( 'active_plugins', $active );
}
add_action ( 'activated_plugin', 'quform_map_load_first' );
