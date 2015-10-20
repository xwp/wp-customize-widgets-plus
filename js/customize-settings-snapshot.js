/* global jQuery, _customizeWidgetsPlusCustomizeSettingsSnapshot, JSON, alert */
/* exported customizeSettingsSnapshot */
var customizeSettingsSnapshot = ( function( exports, $ ) {

	var self = {}, api = wp.customize;

	/**
	 * Inject the functionality.
	 */
	self.init = function() {
		api.bind( 'ready', function() {
			self.addButton();

			$( '#customizer-settings-snapshot' ).on( 'click', function( event ) {
				event.preventDefault();
				self.doAjax();
			} );
		} );
	};

	self.addButton = function() {
		var $header = $( '#customize-header-actions' ),
			$button = '<button id="customizer-settings-snapshot" class="dashicons dashicons-share"><span class="screen-reader-text">' + _customizeWidgetsPlusCustomizeSettingsSnapshot.i18n.buttonText + '</span></button>';

		if ( $header.length ) {
			$header.addClass( 'snapshots' ).append( $button );
		}
	};

	self.doAjax = function() {
		var request, customized;

		customized = {};
		api.each( function( value, key ) {
			customized[ key ] = value();
		} );

		request = wp.ajax.post( 'customize_update_snapshot', {
			nonce: _customizeWidgetsPlusCustomizeSettingsSnapshot.nonce,
			wp_customize: 'on',
			customized: JSON.stringify( customized ),
			customize_settings_snapshot: _customizeWidgetsPlusCustomizeSettingsSnapshot.uuid
		} );

		request.done( function( response ) {
			alert( response.snapshot_uuid );
		} );
	};

	self.init();
	return self;
}( wp, jQuery ) );

