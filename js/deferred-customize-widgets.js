/* global wp, jQuery */
/* exported deferredCustomizeWidgets */
var deferredCustomizeWidgets = (function( api, $ ) {

	var originalMethods = {
		initialize: api.Widgets.WidgetControl.prototype.initialize,
		embed: api.Widgets.WidgetControl.prototype.embed
	};

	/*
	Options for when to embed the widget contents and call ready():
	1. When the widget panel expands (this could be when the controls and their contents are initially loaded as well).
	2. When the sidebar section expands (need to at least insert placeholders).
	3. When the widget itself expands (requires a placeholder). Control container would need to be swapped with actual content, seamlessly.
	Widget Subareas needs to not break.
	JS-driven widgets would be exempt from all of this since they would not use widget_form types?
	 */

	api.Widgets.WidgetControl.prototype.initialize = function( id, options ) {
		var control = this, retval;

		/*
		 * Short-circuit initialize method to call original if no section is
		 * provided, since we cannot then reliably defer embedding of the widget
		 * control's content.
		 */
		if ( ! options.params.section ) {
			return originalMethods.initialize.call( control, id, options );
		}

		/*
		 * Defer embedding of content until the section is expanded.
		 * And actually only embed a placeholder control when the section is expanded. The widget form
		 * only gets embedded once the widget control itself is expanded. This allows us to keep the
		 * DOM as lightweight as possible. It also allows us to obtain the widget form control content
		 * via Ajax upon widget control expansion.
		 */
		options.params.expandedContent = options.params.content;
		options.params.content = $( '<li></li>', {
			id: 'customize-control-' + id.replace( '[', '-' ).replace( ']', '' ),
			'class': 'customize-control customize-control-' + options.params.type
		} );

		retval = originalMethods.initialize.call( this, id, options );

		/*
		 * Embed a placeholder once the section is expanded. The full widget
		 * form content will be embedded once the control itself is expanded,
		 * and at this point the widget-added event will be triggered.
		 */
		control.placeholderEmbedded = false;
		api.section( options.params.section, function( section ) {
			var onExpanded;
			if ( section.expanded() ) {
				control.embedPlaceholderControl();
			} else {
				onExpanded = function( isExpanded ) {
					if ( isExpanded ) {
						control.embedPlaceholderControl();
						section.expanded.unbind( onExpanded );
					}
				};
				section.expanded.bind( onExpanded );
			}
		} );

		// @todo If expand() happens, embed on the fly.

		return retval;
	};

	api.Widgets.WidgetControl.prototype.embedPlaceholderControl = function() {
		var control = this;

		// @todo This should be part of a template. We need the full widget content around .widget-inside > .form

		// This is currently needed for sortable, because :input[name=widget-id] is queried for the ID.
		control.container.append( $( '<input>', {
			type: 'text', // @todo hidden
			name: 'widget-id',
			value: control.params.widget_id
		} ) );

		control.placeholderEmbedded = true;
	};


	api.Widgets.WidgetControl.prototype.embed = function () {
		var control = this;
		control.container.one( 'click', function () {
			var oldContainer = control.container,
				newContainer = $( control.params.expandedContent );

			// Beware that initialize has already happened, so [data-customize-setting-link] in newContainer will not be processed (widgets lack them anyway).
			oldContainer.replaceWith( newContainer );

			control.container = newContainer;

			control.container.toggleClass( 'widget-rendered', control.active() );

			originalMethods.embed.call( control );
		} );
	};

	//api.Widgets.WidgetControl.prototype.ready = function () {
	//	/* ... */
	//};

})( wp.customize, jQuery );

