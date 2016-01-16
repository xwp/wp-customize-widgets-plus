/*global wpCustomizeWidgetsPlus, _customizeWidgetsPlusWidgetNumberIncrementingExports */

wpCustomizeWidgetsPlus.widgetNumberIncrementing = ( function( $ ) {
	var self = {
		nonce: '',
		action: '',
		retryCount: 0
	};
	$.extend( self, _customizeWidgetsPlusWidgetNumberIncrementingExports );

	return self;
}( jQuery ) );
