/* global jQuery, _customizeWidgetsPlusCustomizeSnapshot, JSON, alert */
/* exported customizeSnapshot */
var customizeSnapshot = ( function( exports, $ ) {

	var self = {}, api = wp.customize;

	/**
	 * Inject the functionality.
	 */
	self.init = function() {
		api.bind( 'ready', function() {
			self.addButton();

			$( '#customize-snapshot' ).on( 'click', function( event ) {
				event.preventDefault();
				self.doAjax();
			} );
		} );
	};

	self.addButton = function() {
		var $header = $( '#customize-header-actions' ),
			$button = '<button id="customize-snapshot" class="dashicons dashicons-share"><span class="screen-reader-text">' + _customizeWidgetsPlusCustomizeSnapshot.i18n.buttonText + '</span></button>';

		if ( $header.length ) {
			$header.addClass( 'customize-snapshots' ).append( $button );
		}
	};

	self.doAjax = function() {
		var request, customized;

		customized = {};
		api.each( function( value, key ) {
			customized[ key ] = value();
		} );

		request = wp.ajax.post( 'customize_update_snapshot', {
			nonce: _customizeWidgetsPlusCustomizeSnapshot.nonce,
			wp_customize: 'on',
			customized: JSON.stringify( customized ),
			customize_snapshot_uuid: _customizeWidgetsPlusCustomizeSnapshot.uuid
		} );

		request.done( function( response ) {
			alert( response.snapshot_uuid );
		} );
	};

	self.init();
	return self;
}( wp, jQuery ) );

