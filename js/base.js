/* exported wpCustomizeWidgetsPlus */

var wpCustomizeWidgetsPlus = (function() {

	var self = {};

	/**
	 * @param {String} widgetId
	 * @returns {Object}
	 */
	self.parseWidgetId = function( widgetId ) {
		var matches, parsed = {
			number: '',
			idBase: 0
		};

		matches = widgetId.match( /^(.+)-(\d+)$/ );
		if ( matches ) {
			parsed.idBase = matches[1];
			parsed.number = parseInt( matches[2], 10 );
		} else {
			parsed.idBase = widgetId; /* likely an old single widget */
		}
		return parsed;
	};

	return self;
}() );
