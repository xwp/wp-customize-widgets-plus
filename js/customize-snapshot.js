/* global jQuery, _customizeWidgetsPlusCustomizeSnapshot, JSON */
/* exported customizeSnapshot */
var customizeSnapshot = ( function( $ ) {

	var self = {},
		api = wp.customize,
		dialog, form;

	/**
	 * Inject the functionality.
	 */
	self.init = function() {
		api.bind( 'ready', function() {
			self.addButton();
			self.addFormDialog();
			self.addShareDialog();

			dialog = $( '#snapshot-dialog-form' ).dialog( {
				autoOpen: false,
				modal: true,
				buttons: {
					'Save': self.doAjax,
					Cancel: function() {
						dialog.dialog( 'close' );
					}
				},
				close: function() {
					form[ 0 ].reset();
				}
			} );

			form = dialog.find( 'form' ).on( 'submit', function( event ) {
				event.preventDefault();
				self.doAjax();
			} );

			$( '#customize-snapshot' ).on( 'click', function( event ) {
				event.preventDefault();
				dialog.dialog( 'open' );
			} );
		} );
	};

	/**
	 * Create the share button.
	 */
	self.addButton = function() {
		var $header = $( '#customize-header-actions' ),
			$button = '<button id="customize-snapshot" class="dashicons dashicons-share"><span class="screen-reader-text">' + _customizeWidgetsPlusCustomizeSnapshot.i18n.buttonText + '</span></button>';

		if ( $header.length ) {
			$header.addClass( 'customize-snapshots' ).append( $button );
		}
	};

	/**
	 * Create the dialog form.
	 */
	self.addFormDialog = function() {
		var html = '<div id="snapshot-dialog-form" title="Snapshot">' +
			'<form>' +
				'<fieldset>' +
					'<input id="type-0" type="radio" checked="checked" value="dirty" name="scope"><label for="type-0">Diff</label><br>' +
					'<input id="type-1" type="radio" value="full" name="scope"><label for="type-1">Full</label><br>' +
					'<input type="submit" tabindex="-1" style="position:absolute; top:-5000px">' +
				'</fieldset>' +
			'</form>' +
		'</div>';
		$( html ).appendTo( 'body' );
	};

	/**
	 * Create the share dialog.
	 */
	self.addShareDialog = function() {
		var html = '<div id="snapshot-dialog-share"></div>';
		$( html ).appendTo( 'body' );
	};

	/**
	 * Make the AJAX request.
	 */
	self.doAjax = function() {
		var spinner = $( '#customize-header-actions .spinner' ),
			request, customized;

		dialog.dialog( 'close' );
		spinner.addClass( 'is-active' );

		customized = {};
		api.each( function( value, key ) {
			customized[ key ] = {
				'value': value(),
				'dirty': value._dirty
			};
		} );

		request = wp.ajax.post( 'customize_update_snapshot', {
			nonce: _customizeWidgetsPlusCustomizeSnapshot.nonce,
			wp_customize: 'on',
			customized_json: JSON.stringify( customized ),
			customize_snapshot_uuid: _customizeWidgetsPlusCustomizeSnapshot.uuid,
			scope: dialog.find( 'form input[name="scope"]:checked' ).val()
		} );

		request.done( function( response ) {
			var url = wp.customize.previewer.previewUrl(),
				regex = new RegExp( '([?&])customize_snapshot_uuid=.*?(&|$)', 'i' ),
				separator = url.indexOf( '?' ) !== -1 ? '&' : '?';

			if ( url.match( regex ) ) {
				url = url.replace( regex, '$1' + 'customize_snapshot_uuid=' + response.customize_snapshot_uuid + '$2' );
			} else {
				url = url + separator + 'customize_snapshot_uuid=' + response.customize_snapshot_uuid;
			}

			spinner.removeClass( 'is-active' );
			$( '#snapshot-dialog-share' ).html( '<p>' + url + '</p>' ).dialog( {
				autoOpen: true,
				modal: true
			} );
		} );

		request.fail( function() {
			spinner.removeClass( 'is-active' );
			$( '#snapshot-dialog-share' ).html( '<p>' + _customizeWidgetsPlusCustomizeSnapshot.i18n.errorText + '</p>' ).dialog( {
				autoOpen: true,
				modal: true
			} );
		} );
	};

	self.init();
}( jQuery ) );

