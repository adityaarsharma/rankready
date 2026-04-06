/**
 * RankReady — FAQ Generator Gutenberg Block
 * Dynamic block with generate button and style controls. No build step required.
 */
( function () {
	'use strict';

	var el                = wp.element.createElement;
	var Fragment          = wp.element.Fragment;
	var useState          = wp.element.useState;
	var useEffect         = wp.element.useEffect;
	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps     = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody         = wp.components.PanelBody;
	var ToggleControl     = wp.components.ToggleControl;
	var TextControl       = wp.components.TextControl;
	var SelectControl     = wp.components.SelectControl;
	var RangeControl      = wp.components.RangeControl;
	var ColorPalette      = wp.components.ColorPalette;
	var BaseControl       = wp.components.BaseControl;
	var Button            = wp.components.Button;
	var Notice            = wp.components.Notice;
	var Spinner           = wp.components.Spinner;
	var useSelect         = wp.data.useSelect;
	var apiFetch          = wp.apiFetch;

	function colorControl( label, value, onChange ) {
		return el( BaseControl, { label: label, __nextHasNoMarginBottom: true },
			el( ColorPalette, {
				value: value || undefined,
				onChange: onChange,
				clearable: true,
			} )
		);
	}

	registerBlockType( 'rankready/faq', {
		title:       'FAQ (RankReady)',
		icon:        'editor-help',
		category:    'text',
		description: 'Display AI-generated FAQ with brand entity injection. Generate from DataForSEO + OpenAI.',
		keywords:    [ 'faq', 'questions', 'ai', 'seo', 'rankready', 'schema' ],

		attributes: {
			// Content
			showTitle:         { type: 'boolean', default: true },
			titleText:         { type: 'string',  default: 'Frequently Asked Questions' },
			headingTag:        { type: 'string',  default: 'h3' },
			showReviewed:      { type: 'boolean', default: true },
			keyword:           { type: 'string',  default: '' },
			// Box style
			boxBgColor:        { type: 'string',  default: '' },
			boxBorderColor:    { type: 'string',  default: '' },
			boxBorderWidth:    { type: 'number',  default: 0 },
			boxBorderRadius:   { type: 'number',  default: 0 },
			boxPadding:        { type: 'number',  default: 0 },
			// Question style
			questionColor:     { type: 'string',  default: '' },
			questionFontSize:  { type: 'number',  default: 0 },
			// Answer style
			answerColor:       { type: 'string',  default: '' },
			answerFontSize:    { type: 'number',  default: 0 },
			// Divider
			dividerColor:      { type: 'string',  default: '' },
		},

		edit: function ( props ) {
			var attrs    = props.attributes;
			var setAttrs = props.setAttributes;

			var _faq       = useState( [] );
			var faq        = _faq[0]; var setFaq = _faq[1];
			var _loading   = useState( false );
			var loading    = _loading[0]; var setLoading = _loading[1];
			var _error     = useState( '' );
			var error      = _error[0]; var setError = _error[1];
			var _generated = useState( '' );
			var generated  = _generated[0]; var setGenerated = _generated[1];

			var postId = useSelect( function ( select ) {
				return select( 'core/editor' ).getCurrentPostId();
			}, [] );

			// Load existing FAQ on mount.
			useEffect( function () {
				if ( ! postId ) return;
				apiFetch( { path: '/rankready/v1/faq/get/' + postId } )
					.then( function ( data ) {
						if ( data && data.faq ) {
							setFaq( data.faq );
						}
						if ( data && data.generated ) {
							setGenerated( data.generated );
						}
						if ( data && data.keyword && ! attrs.keyword ) {
							setAttrs( { keyword: data.keyword } );
						}
					} )
					.catch( function () {} );
			}, [ postId ] );

			function handleGenerate() {
				if ( ! postId || loading ) return;
				setLoading( true );
				setError( '' );

				var body = {};
				if ( attrs.keyword ) {
					body.keyword = attrs.keyword;
				}

				apiFetch( {
					path: '/rankready/v1/faq/generate/' + postId,
					method: 'POST',
					data: body,
				} )
					.then( function ( data ) {
						if ( data && data.faq ) {
							setFaq( data.faq );
							setGenerated( 'Just now' );
						}
						setLoading( false );
					} )
					.catch( function ( err ) {
						var msg = ( err && err.message ) ? err.message : 'FAQ generation failed.';
						setError( msg );
						setLoading( false );
					} );
			}

			// Build preview styles.
			var boxStyle = {};
			if ( attrs.boxBgColor ) boxStyle.backgroundColor = attrs.boxBgColor;
			if ( attrs.boxBorderColor && attrs.boxBorderWidth ) {
				boxStyle.border = attrs.boxBorderWidth + 'px solid ' + attrs.boxBorderColor;
			}
			if ( attrs.boxBorderRadius ) boxStyle.borderRadius = attrs.boxBorderRadius + 'px';
			if ( attrs.boxPadding ) boxStyle.padding = attrs.boxPadding + 'px';

			var questionStyle = {};
			if ( attrs.questionColor ) questionStyle.color = attrs.questionColor;
			if ( attrs.questionFontSize ) questionStyle.fontSize = attrs.questionFontSize + 'px';

			var answerStyle = {};
			if ( attrs.answerColor ) answerStyle.color = attrs.answerColor;
			if ( attrs.answerFontSize ) answerStyle.fontSize = attrs.answerFontSize + 'px';

			var dividerStyle = {};
			if ( attrs.dividerColor ) dividerStyle.borderBottomColor = attrs.dividerColor;

			var HeadingTag = attrs.headingTag || 'h3';
			var blockProps = useBlockProps( { className: 'rr-faq-wrapper rr-editor-preview', style: boxStyle } );

			return el( Fragment, null,

				// Inspector Controls
				el( InspectorControls, null,

					// Panel: FAQ Settings
					el( PanelBody, { title: 'FAQ Settings', initialOpen: true },

						el( TextControl, {
							label: 'Focus Keyword',
							value: attrs.keyword || '',
							placeholder: 'Auto-detected from Rank Math/Yoast',
							onChange: function ( v ) { setAttrs( { keyword: v } ); },
							help: 'Leave empty to use SEO plugin focus keyword.',
							__nextHasNoMarginBottom: true,
						} ),

						el( ToggleControl, {
							label: 'Show title',
							checked: attrs.showTitle,
							onChange: function ( v ) { setAttrs( { showTitle: v } ); },
							__nextHasNoMarginBottom: true,
						} ),

						attrs.showTitle && el( TextControl, {
							label: 'Title text',
							value: attrs.titleText || 'Frequently Asked Questions',
							onChange: function ( v ) { setAttrs( { titleText: v } ); },
							__nextHasNoMarginBottom: true,
						} ),

						attrs.showTitle && el( SelectControl, {
							label: 'Title tag',
							value: attrs.headingTag || 'h3',
							options: [
								{ label: 'H2', value: 'h2' }, { label: 'H3', value: 'h3' },
								{ label: 'H4', value: 'h4' }, { label: 'H5', value: 'h5' },
								{ label: 'H6', value: 'h6' },
							],
							onChange: function ( v ) { setAttrs( { headingTag: v } ); },
							__nextHasNoMarginBottom: true,
						} ),

						el( ToggleControl, {
							label: 'Show "Last reviewed" date',
							checked: attrs.showReviewed,
							onChange: function ( v ) { setAttrs( { showReviewed: v } ); },
							__nextHasNoMarginBottom: true,
						} ),

						el( 'div', { style: { marginTop: '16px' } },
							el( Button, {
								variant: 'primary',
								isBusy: loading,
								disabled: loading || ! postId,
								onClick: handleGenerate,
								style: { width: '100%', justifyContent: 'center' },
							}, loading ? 'Generating FAQ...' : faq.length ? 'Regenerate FAQ' : 'Generate FAQ' )
						),

						generated && el( 'p', { style: { color: '#757575', fontSize: '11px', margin: '8px 0 0', fontStyle: 'italic' } },
							'Last generated: ' + generated
						)
					),

					// Panel: Box Style
					el( PanelBody, { title: 'Box Style', initialOpen: false },
						colorControl( 'Background', attrs.boxBgColor, function ( v ) { setAttrs( { boxBgColor: v } ); } ),
						colorControl( 'Border Color', attrs.boxBorderColor, function ( v ) { setAttrs( { boxBorderColor: v } ); } ),

						el( RangeControl, {
							label: 'Border Width',
							value: attrs.boxBorderWidth || 0,
							onChange: function ( v ) { setAttrs( { boxBorderWidth: v } ); },
							min: 0, max: 5, step: 1,
							__nextHasNoMarginBottom: true,
						} ),

						el( RangeControl, {
							label: 'Border Radius',
							value: attrs.boxBorderRadius || 0,
							onChange: function ( v ) { setAttrs( { boxBorderRadius: v } ); },
							min: 0, max: 20, step: 1,
							__nextHasNoMarginBottom: true,
						} ),

						el( RangeControl, {
							label: 'Padding',
							value: attrs.boxPadding || 0,
							onChange: function ( v ) { setAttrs( { boxPadding: v } ); },
							min: 0, max: 60, step: 2,
							help: '0 = no extra padding',
							__nextHasNoMarginBottom: true,
						} )
					),

					// Panel: Question Style
					el( PanelBody, { title: 'Question Style', initialOpen: false },
						colorControl( 'Color', attrs.questionColor, function ( v ) { setAttrs( { questionColor: v } ); } ),

						el( RangeControl, {
							label: 'Font Size (px)',
							value: attrs.questionFontSize || 0,
							onChange: function ( v ) { setAttrs( { questionFontSize: v } ); },
							min: 0, max: 32, step: 1,
							help: '0 = inherit',
							__nextHasNoMarginBottom: true,
						} )
					),

					// Panel: Answer Style
					el( PanelBody, { title: 'Answer Style', initialOpen: false },
						colorControl( 'Color', attrs.answerColor, function ( v ) { setAttrs( { answerColor: v } ); } ),

						el( RangeControl, {
							label: 'Font Size (px)',
							value: attrs.answerFontSize || 0,
							onChange: function ( v ) { setAttrs( { answerFontSize: v } ); },
							min: 0, max: 24, step: 1,
							help: '0 = inherit',
							__nextHasNoMarginBottom: true,
						} ),

						colorControl( 'Divider Color', attrs.dividerColor, function ( v ) { setAttrs( { dividerColor: v } ); } )
					)
				),

				// Editor Preview
				el( 'div', blockProps,

					attrs.showTitle && el( HeadingTag, {
						className: 'rr-faq-title',
						style: attrs.questionColor ? { color: attrs.questionColor } : {},
					}, attrs.titleText || 'Frequently Asked Questions' ),

					error && el( Notice, {
						status: 'error', isDismissible: true,
						onRemove: function () { setError( '' ); },
					}, error ),

					loading
						? el( 'div', { style: { display: 'flex', alignItems: 'center', gap: '8px', padding: '12px 0' } },
							el( Spinner ), el( 'span', null, 'Generating FAQ from DataForSEO + OpenAI...' )
						)
						: faq.length > 0
							? el( 'div', { className: 'rr-faq-list' },
								faq.map( function ( item, i ) {
									return el( 'div', {
										className: 'rr-faq-item',
										key: i,
										style: dividerStyle,
									},
										el( 'h4', {
											className: 'rr-faq-question',
											style: questionStyle,
										}, item.question ),
										el( 'p', {
											className: 'rr-faq-answer',
											style: answerStyle,
										}, item.answer )
									);
								} ),
								attrs.showReviewed && generated && el( 'p', {
									className: 'rr-faq-reviewed',
								}, 'Last reviewed: ' + generated )
							)
							: el( 'p', { style: { opacity: 0.5, fontStyle: 'italic', margin: 0, padding: '12px 0' } },
								'Click "Generate FAQ" in the sidebar to create FAQ items for this post.'
							)
				)
			);
		},

		save: function () { return null; },
	} );
} )();
