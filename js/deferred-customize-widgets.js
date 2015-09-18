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

	/**
	 * Defer embedding of widget control until the section is expanded. Until
	 * it is expanded, only the container LI will be embedded.
	 * When the section is expanded, then the outer widget control will be
	 * embedded, and in its collapsed state it will not show the inner widget
	 * form so we will not embed it either. Once the widget control is expanded,
	 * then we will embed the widget form and fire the widget-added event.
	 * This allows us to keep the DOM as lightweight as possible. It also allows
	 * us to (potentially) obtain the widget form control content via Ajax upon
	 * widget control expansion.
	 */
	api.Widgets.WidgetControl.prototype.initialize = function( id, options ) {
		var control = this;

		/*
		 * Short-circuit initialize method to call original if we determine we
		 * cannot then reliably defer embedding of the widget control's content.
		 */
		if ( ! options.params.section || ! options.params.widget_form || ! options.params.widget_control ) {
			return originalMethods.initialize.call( control, id, options );
		}

		control.expanded = new api.Value( false );
		control.expandedArgumentsQueue = [];
		control.expanded.bind( function( expanded ) {
			var args = control.expandedArgumentsQueue.shift();
			args = $.extend( {}, control.defaultExpandedArguments, args );
			control.onChangeExpanded( expanded, args );
		});

		// @todo Add widgetControlEmbedded and widgetFormEmbedded as deferreds

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
				control.embedWidgetControl();
			} else {
				api.section( control.section(), function( section ) {
					var onExpanded = function( isExpanded ) {
						if ( isExpanded ) {
							control.embedWidgetControl();
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

			control.embedWidgetControl(); // Make sure the outer form is embedded so that the expanded state can be set in the UI.
			if ( expanded ) {
				control.embedWidgetForm();
			}

			return api.Widgets.WidgetControl.prototype.onChangeExpanded.call( control, expanded, args );
		},

		/**
		 * Embed the .widget element inside the li container.
		 */
		embedWidgetControl: function() {
			var control = this,
				widgetControl;

			if ( control.widgetControlEmbedded ) {
				return;
			}

			widgetControl = $( control.params.widget_control );
			control.container.append( widgetControl );

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

			control.widgetControlEmbedded = true;
		},

		/**
		 * Embed the actual widget form inside of .widget-content and finally trigger the widget-added event
		 */
		embedWidgetForm: function() {
			var control = this,
				widgetForm;

			control.embedWidgetControl();
			if ( control.widgetFormEmbedded ) {
				return;
			}

			widgetForm = $( control.params.widget_form );
			control.container.find( '.widget-content:first' ).append( widgetForm );

			$( document ).trigger( 'widget-added', [ control.container.find( '.widget:first' ) ] );

			control.widgetFormEmbedded = true;
		},

		/**
		 * @this {wp.customize.Widgets.WidgetControl}
		 * @returns {*}
		 */
		updateWidget: function() {
			var control = this;

			// The updateWidget logic requires that the form fields to be fully present.
			control.embedWidgetForm();

			return api.Widgets.WidgetControl.prototype.updateWidget.call( control, arguments );
		}

	};

})( wp.customize, jQuery );

