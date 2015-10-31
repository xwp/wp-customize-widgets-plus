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
			self.addDialogForm();
			self.dialogEvents();
			if ( is_preview ) {
				api.state( 'saved' ).set( false );
			}
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
	 * Create the snapshot share button.
	 */
	self.addButton = function() {
		var header = $( '#customize-header-actions' ),
			snapshotButton, data;

		if ( header.length ) {
			snapshotButton = wp.template( 'snapshot-button' );
			data = {
				buttonText: _customizeWidgetsPlusCustomizeSnapshot.i18n.shareButton
			};
			header.addClass( 'has-snapshot-button' ).append( snapshotButton( data ) );
		}
	};

	/**
	 * Create the snapshot dialog form.
	 */
	self.addDialogForm = function() {
		var snapshotDialogForm = wp.template( 'snapshot-dialog-form' ),
			data = {
				title: _customizeWidgetsPlusCustomizeSnapshot.i18n.formTitle,
				is_preview: is_preview,
				message: _customizeWidgetsPlusCustomizeSnapshot.i18n.updateMsg,
				scope: _customizeWidgetsPlusCustomizeSnapshot.scope,
				dirtyLabel: _customizeWidgetsPlusCustomizeSnapshot.i18n.dirtyLabel,
				fullLabel: _customizeWidgetsPlusCustomizeSnapshot.i18n.fullLabel
			};
		$( 'body' ).append( snapshotDialogForm( data ) );
	};

	/**
	 * Create the dialog events.
	 */
	self.dialogEvents = function() {
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

		$( '#snapshot-button' ).on( 'click', function( event ) {
			event.preventDefault();
			dialog.dialog( 'open' );
			dialog.find( 'form input[name=scope]' ).blur();
			dialog.next( '.ui-dialog-buttonpane' ).find( 'button' ).blur();
		} );
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
				separator = url.indexOf( '?' ) !== -1 ? '&' : '?',
				snapshotDialogShareLink = wp.template( 'snapshot-dialog-share-link' );

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

			// Insert the snapshot dialog share link template.
			if ( 0 === $( '#snapshot-dialog-share-link' ).length ) {
				$( 'body' ).append( snapshotDialogShareLink( {
					title: _customizeWidgetsPlusCustomizeSnapshot.i18n.formTitle,
					url: url
				} ) );
			}

			spinner.removeClass( 'is-active' );

			// Open the dialog.
			$( '#snapshot-dialog-share-link' ).dialog( {
				autoOpen: true,
				modal: true
			} );
			$( '#snapshot-dialog-share-link' ).find( 'a' ).blur();
		} );

		request.fail( function() {
			var snapshotDialogShareError = wp.template( 'snapshot-dialog-share-error' );

			// Insert the snapshot dialog share error template.
			if ( 0 === $( '#snapshot-dialog-share-error' ).length ) {
				$( 'body' ).append( snapshotDialogShareError( {
					title: _customizeWidgetsPlusCustomizeSnapshot.i18n.formTitle,
					message: _customizeWidgetsPlusCustomizeSnapshot.i18n.errorMsg
				} ) );
			}

			spinner.removeClass( 'is-active' );

			// Open the dialog.
			$( '#snapshot-dialog-share-error' ).dialog( {
				autoOpen: true,
				modal: true
			} );
		} );
	};

	self.init();
}( jQuery ) );
