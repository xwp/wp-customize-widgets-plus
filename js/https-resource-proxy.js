/*global jQuery, _httpsResourceProxyExports */
/*exported httpsResourceProxy */
var httpsResourceProxy = ( function( $ ) {

	var self = {
		baseUrl: '',
		trailingslashSrcs: false
	};
	if ( 'undefined' !== typeof _httpsResourceProxyExports ) {
		$.extend( self, _httpsResourceProxyExports );
	}

	/**
	 * Given the URL to an asset, return the HTTPS version given the proxy.
	 *
	 * @param {string} src URL to resource.
	 * @return {string}
	 */
	self.getProxiedSrc = function( src ) {
		var urlParser = document.createElement( 'a' ),
			proxiedSrc,
			regexTrailingslashed = /\/$/;

		urlParser.href = src;
		if ( 'https:' === urlParser.protocol ) {
			return src;
		}

		proxiedSrc = this.baseUrl; /* trailingslashed */

		proxiedSrc += urlParser.host;
		proxiedSrc += urlParser.pathname;

		if ( this.trailingslashSrcs ) {
			if ( ! regexTrailingslashed.test( proxiedSrc ) ) {
				proxiedSrc += '/';
			}
		} else {
			proxiedSrc = proxiedSrc.replace( regexTrailingslashed, '' );
		}

		if ( urlParser.search ) {
			proxiedSrc += urlParser.search;
		}
		return proxiedSrc;
	};

	// @todo add a jQuery.ajaxPrefilter which rewrites any HTTP URLs to be proxied ones. See approach at https://github.com/xwp/wordpress-develop/pull/61/files#diff-5ddb7ff7b83ab560762efaf889fdff15R80

	return self;
} ( jQuery ) );
