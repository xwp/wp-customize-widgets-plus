/*global wpCustomizeWidgetsPlus, wp, console */

wpCustomizeWidgetsPlus.widgetNumberIncrementing.customizer = ( function( $ ) {
	var self = {};

	/**
	 * Inject the functionality.
	 */
	self.init = function() {

		// Keep the nonces fresh
		wp.customize.bind( 'nonce-refresh', function( nonces ) {
			if ( nonces.incrWidgetNumber ) {
				wpCustomizeWidgetsPlus.widgetNumberIncrementing.nonce = nonces.incrWidgetNumber;
			}
		} );

		wp.customize.Widgets.SidebarControl.prototype.originalAddWidget = wp.customize.Widgets.SidebarControl.prototype.addWidget;
		wp.customize.Widgets.SidebarControl.prototype.addWidget = self.addWidget;
	};

	/**
	 * Override/wrapper for wp.customize.Widgets.SidebarControl.prototype.addWidget()
	 *
	 * Note that when adding a new multi-widget, a mock widget form control is returned that has a deferred focus method.
	 *
	 * @param {string} widgetId or an id_base for adding a previously non-existing widget
	 * @returns {object|boolean}
	 */
	self.addWidget = function( widgetId ) {
		var sidebarControl = this, parsedWidgetId, widget, incrWidgetNumber, remainingRetryCount, widgetAdded, deferredWidgetFormControl, originalArguments, spinner, wasSpinnerActive;
		originalArguments = arguments;

		parsedWidgetId = wpCustomizeWidgetsPlus.parseWidgetId( widgetId );
		widget = wp.customize.Widgets.availableWidgets.findWhere( { 'id_base': parsedWidgetId.idBase } );
		if ( ! widget ) {
			return false;
		}

		// If we're not creating a new multi-widget, continue with Core's logic
		if ( ! widget.get( 'is_multi' ) || parsedWidgetId.number ) {
			return sidebarControl.originalAddWidget.apply( sidebarControl, originalArguments );
		}

		// Set up new multi widget
		spinner = $( '#customize-header-actions .spinner' );
		wasSpinnerActive = spinner.hasClass( 'is-active' );
		if ( ! wasSpinnerActive ) {
			spinner.addClass( 'is-active' );
		}
		widgetAdded = $.Deferred();

		remainingRetryCount = wpCustomizeWidgetsPlus.widgetNumberIncrementing.retryCount;
		incrWidgetNumber = function() {
			var incrWidgetNumberRequest;
			incrWidgetNumberRequest = wp.ajax.post( wpCustomizeWidgetsPlus.widgetNumberIncrementing.action, {
				nonce: wpCustomizeWidgetsPlus.widgetNumberIncrementing.nonce,
				idBase: parsedWidgetId.idBase
			} );

			incrWidgetNumberRequest.done( function( res ) {
				var result;
				widget.set( 'multi_number', res.number - 1 ); /* decrement by 1 because core's addWidget will add 1 */
				result = sidebarControl.originalAddWidget.apply( sidebarControl, originalArguments );
				widgetAdded.resolve( result );
			} );

			// When failing, try to recover if user is not logged-in or if the nonce was just stale
			incrWidgetNumberRequest.fail( function( res ) {
				var errorCode, deferred, errorMessage, previewer = wp.customize.previewer;
				remainingRetryCount -= 1;

				if ( '0' === res ) {
					errorCode = 'not_logged_in';
				} else if ( '-1' === res ) {
					errorCode = 'invalid_nonce';
				} else if ( res && res.message ) {
					errorCode = res.message;
				} else {
					errorCode = 'unknown';
				}

				if ( 0 >= remainingRetryCount ) {
					errorMessage = 'Failed request count: ' + wpCustomizeWidgetsPlus.widgetNumberIncrementing.retryCount + '.';
					errorMessage += ' Last error code: ' + errorCode;
					widgetAdded.reject( errorMessage );
				} else if ( 'invalid_nonce' === errorCode || 'not_logged_in' === errorCode || 'unauthorized' === errorCode ) {
					if ( 'invalid_nonce' === errorCode ) {
						deferred = previewer.refreshNonces();
						deferred.done( function() {
							incrWidgetNumber();
						} );
					} else {
						previewer.preview.iframe.hide();
						deferred = previewer.login();
						deferred.done( function() {
							previewer.preview.iframe.show();
							incrWidgetNumber();
						} );
					}
					deferred.fail( function() {
						previewer.cheatin();
						widgetAdded.reject();
					});
				} else {
					incrWidgetNumber(); /* Network error or temporary server problem */
				}
			} );
		};
		incrWidgetNumber();

		widgetAdded.always( function() {
			if ( ! wasSpinnerActive ) {
				spinner.removeClass( 'is-active' );
			}
		} );

		widgetAdded.fail( function( result ) {
			if ( 'undefined' !== typeof console ) {
				console.error( 'addWidget failure: ' + result );
			}
		} );

		deferredWidgetFormControl = {
			focus: function() {
				widgetAdded.done( function( result ) {
					if ( result ) {
						result.focus();
					}
				} );
			}
		};
		return deferredWidgetFormControl;
	};

	self.init();
	return self;
}( jQuery ) );
