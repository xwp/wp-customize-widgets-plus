/* global wp, jQuery */
/* exported deferredCustomizeWidgets */
var deferredCustomizeWidgets = (function( api, $ ) {
	var originalMethods, overrideMethods;

	originalMethods = {
		initialize: api.Widgets.WidgetControl.prototype.initialize
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
		var control = this;

		/*
		 * Short-circuit initialize method to call original if no section is
		 * provided, since we cannot then reliably defer embedding of the widget
		 * control's content.
		 */
		if ( ! options.params.section || ! options.params.widget_content ) {
			return originalMethods.initialize.call( control, id, options );
		}

		options.params.outerFormContent = options.params.content;

		/*
		 * Replace the content embedded first with just the wrapper LI.
		 * This will defer embedding of content until the section is expanded.
		 * When the section is expanded, we will embed the widget but without the
		 * content. Once the widget control is expanded, then we will embed the
		 * form contents and fire the widget-added event. This allows us to keep the
		 * DOM as lightweight as possible. It also allows us to obtain the widget
		 * form control content via Ajax upon widget control expansion.
		 */
		options.params.content = $( '<li></li>', {
			id: 'customize-control-' + id.replace( '[', '-' ).replace( ']', '' ),
			'class': 'customize-control customize-control-' + options.params.type
		} );

		control.expanded = new api.Value( false );
		control.expandedArgumentsQueue = [];
		control.expanded.bind( function( expanded ) {
			var args = control.expandedArgumentsQueue.shift();
			args = $.extend( {}, control.defaultExpandedArguments, args );
			control.onChangeExpanded( expanded, args );
		});

		$.extend( control, overrideMethods );

		return api.Control.prototype.initialize.call( control, id, options );
	};

	overrideMethods = {

		/**
		 * Watch for the expansion of the containing section once the control container is embedded.
		 */
		ready: function() {
			var control = this;

			/*
			 * Embed a placeholder once the section is expanded. The full widget
			 * form content will be embedded once the control itself is expanded,
			 * and at this point the widget-added event will be triggered.
			 */
			if ( ! control.section() ) {
				control.embedOuterForm();
			} else {
				api.section( control.section(), function( section ) {
					var onExpanded = function( isExpanded ) {
						if ( isExpanded ) {
							control.embedOuterForm();
							section.expanded.unbind( onExpanded );
						}
					};
					if ( section.expanded() ) {
						onExpanded( true );
					} else {
						section.expanded.bind( onExpanded );
					}
				} );
			}
		},

		/**
		 * Ensure that the widget is fully embedded when it is expanded
		 */
		onChangeExpanded: function( expanded, args ) {
			var control = this;

			control.embedOuterForm(); // Make sure the outer form is embedded so that the expanded state can be set in the UI.
			if ( expanded ) {
				control.embedInnerForm();
			}

			return api.Widgets.WidgetControl.prototype.onChangeExpanded.call( control, expanded, args );
		},

		/**
		 * Embed the .widget element inside the li container.
		 */
		embedOuterForm: function() {
			var control = this,
				outerFormContent;

			if ( control.outerFormEmbedded ) {
				return;
			}

			outerFormContent = $( control.params.outerFormContent ).find( '> .widget' );
			control.container.append( outerFormContent );

			control._setupModel();
			control._setupWideWidget();
			control._setupControlToggle();

			control._setupWidgetTitle();
			control._setupReorderUI();
			control._setupHighlightEffects();
			control._setupUpdateUI();
			control._setupRemoveUI();

			/*
			 * Important: Note that widget-added is not triggered here. It will be
			 * triggered along with the embedding of control.params.widgetContent once
			 * the widget control is expanded.
			 */

			control.outerFormEmbedded = true;
		},

		/**
		 * Embed the actual widget form inside of .widget-content and finally trigger the widget-added event
		 */
		embedInnerForm: function() {
			var control = this,
				innerFormContent;

			control.embedOuterForm();
			if ( control.innerFormEmbedded ) {
				return;
			}

			innerFormContent = $( control.params.widget_content );
			control.container.find( '.widget-content:first' ).append( innerFormContent );

			$( document ).trigger( 'widget-added', [ control.container.find( '.widget:first' ) ] );

			control.innerFormEmbedded = true;
		},

		/**
		 * @this {wp.customize.Widgets.WidgetControl}
		 * @returns {*}
		 */
		updateWidget: function() {
			var control = this;

			// The updateWidget logic requires that the form fields to be fully present.
			control.embedInnerForm();

			return api.Widgets.WidgetControl.prototype.updateWidget.call( control, arguments );
		}

	};

})( wp.customize, jQuery );

