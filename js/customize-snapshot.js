/* global jQuery, _customizeWidgetsPlusCustomizeSnapshot, JSON */
/* exported customizeSnapshot */
var customizeSnapshot = ( function( $ ) {

	var self = {},
		api = wp.customize,
		uuid = _customizeWidgetsPlusCustomizeSnapshot.uuid,
		is_preview = _customizeWidgetsPlusCustomizeSnapshot.is_preview,
		dialog, form;

	/**
	 * Inject the functionality.
	 */
	self.init = function() {
		api.bind( 'ready', function() {
			self.previewerQuery();
			self.addButton();
			self.addFormDialog();
			self.addShareDialog();

			dialog = $( '#snapshot-dialog-form' ).dialog( {
				autoOpen: false,
				modal: true,
				buttons: {
					Save: {
						text: _customizeWidgetsPlusCustomizeSnapshot.i18n.saveButton,
						click: self.doAjax
					},
					Cancel: {
						text: _customizeWidgetsPlusCustomizeSnapshot.i18n.cancelButton,
						click: function() {
							dialog.dialog( 'close' );
						}
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
				dialog.find( 'form input[name=scope]' ).blur();
				dialog.next( '.ui-dialog-buttonpane' ).find( 'button' ).blur();
			} );
		} );
	};

	/**
	 * Amend the preview query so we can update the snapshot during `customize_save`.
	 */
	self.previewerQuery = function() {
		var originalQuery = api.previewer.query;

		api.previewer.query = function() {
			var previewer = this,
				allCustomized = {},
				retval;

			retval = originalQuery.apply( previewer, arguments );

			if ( is_preview ) {
				api.each( function( value, key ) {
					allCustomized[ key ] = {
						'value': value(),
						'dirty': false
					};
				} );
				retval.snapshot_customized = JSON.stringify( allCustomized );
				retval.snapshot_uuid = uuid;
			}

			return retval;
		};
	};

	/**
	 * Create the share button.
	 */
	self.addButton = function() {
		var $header = $( '#customize-header-actions' ),
			$button = '<button id="customize-snapshot" class="dashicons dashicons-share"><span class="screen-reader-text">' + _customizeWidgetsPlusCustomizeSnapshot.i18n.shareButton + '</span></button>';

		if ( $header.length ) {
			$header.addClass( 'customize-snapshots' ).append( $button );
		}
	};

	/**
	 * Create the dialog form.
	 */
	self.addFormDialog = function() {
		var html = '<div id="snapshot-dialog-form" title="' + _customizeWidgetsPlusCustomizeSnapshot.i18n.formTitle + '">' +
			'<form>' +
				'<fieldset>';
				if ( is_preview ) {
					html += '<p>' + _customizeWidgetsPlusCustomizeSnapshot.i18n.updateMsg + '</p>' +
					'<input type="hidden" value="' + _customizeWidgetsPlusCustomizeSnapshot.scope + '" name="scope"></label>';
				} else {
					html += '<label for="type-0"><input id="type-0" type="radio" checked="checked" value="dirty" name="scope">' + _customizeWidgetsPlusCustomizeSnapshot.i18n.dirtyLabel + '</label><br>' +
					'<label for="type-1"><input id="type-1" type="radio" value="full" name="scope">' + _customizeWidgetsPlusCustomizeSnapshot.i18n.fullLabel + '</label><br>';
				}
				html += '<input type="submit" tabindex="-1" style="position:absolute; top:-5000px">' +
				'</fieldset>' +
			'</form>' +
		'</div>';
		$( html ).appendTo( 'body' );
	};

	/**
	 * Create the share dialog.
	 */
	self.addShareDialog = function() {
		var html = '<div id="snapshot-dialog-share" title="' + _customizeWidgetsPlusCustomizeSnapshot.i18n.previewTitle + '"></div>';
		$( html ).appendTo( 'body' );
	};

	/**
	 * Make the AJAX request.
	 */
	self.doAjax = function() {
		var spinner = $( '#customize-header-actions .spinner' ),
			scope = dialog.find( 'form input[name="scope"]:checked' ).val(),
			request, customized;

		if ( ! scope ) {
			scope = dialog.find( 'form input[type="hidden"]' ).val();
		}

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
			snapshot_customized: JSON.stringify( customized ),
			customize_snapshot_uuid: uuid,
			scope: scope,
			preview: ( is_preview ? 'on' : 'off' )
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

			if ( 'dirty' !== scope ) {
				scope = 'full';
			}
			url += '&scope=' + scope;

			// Write over the UUID
			if ( ! is_preview ) {
				uuid = response.customize_snapshot_next_uuid;
			}

			spinner.removeClass( 'is-active' );
			$( '#snapshot-dialog-share' ).html( '<a href="' + url + '" target="_blank">' + url + '</a>' ).dialog( {
				autoOpen: true,
				modal: true
			} );
			$( '#snapshot-dialog-share' ).find( 'a' ).blur();
		} );

		request.fail( function() {
			spinner.removeClass( 'is-active' );
			$( '#snapshot-dialog-share' ).html( '<p>' + _customizeWidgetsPlusCustomizeSnapshot.i18n.errorMsg + '</p>' ).dialog( {
				autoOpen: true,
				modal: true
			} );
		} );
	};

	self.init();
}( jQuery ) );
