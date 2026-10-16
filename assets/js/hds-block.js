/**
 * Smart Document Search – "AI Search" block (hds/search) for the block editor.
 *
 * Plain JavaScript on the wp.* globals (no JSX, no build step). The block is
 * dynamic: the server renders the form (HDS_Frontend::render_block), the
 * editor shows a ServerSideRender preview, save() stores only attributes.
 *
 * @package SmartDocumentSearch
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element || ! wp.components ) {
		return;
	}

	var el = wp.element.createElement;
	var __ = wp.i18n && wp.i18n.__ ? wp.i18n.__ : function ( text ) {
		return text;
	};
	var blockEditor = wp.blockEditor || wp.editor;
	var useBlockProps = blockEditor && blockEditor.useBlockProps;
	var InspectorControls = blockEditor && blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var Disabled = wp.components.Disabled;
	var ServerSideRender = wp.serverSideRender;

	wp.blocks.registerBlockType( 'hds/search', {
		apiVersion: 2,
		title: __( 'AI Search', 'smart-document-search' ),
		description: __( 'AI search form for the uploaded documents.', 'smart-document-search' ),
		icon: 'search',
		category: 'widgets',
		keywords: [ 'search', 'ai', 'documents' ],
		attributes: {
			placeholder: {
				type: 'string',
				default: '',
			},
		},
		supports: {
			html: false,
		},

		edit: function ( props ) {
			var blockProps = useBlockProps ? useBlockProps() : { className: props.className };
			var preview = ServerSideRender
				? el( ServerSideRender, { block: 'hds/search', attributes: props.attributes } )
				: el( 'p', null, __( 'AI Search', 'smart-document-search' ) );

			return el(
				'div',
				blockProps,
				InspectorControls
					? el(
						InspectorControls,
						null,
						el(
							PanelBody,
							{ title: __( 'Search form', 'smart-document-search' ), initialOpen: true },
							el( TextControl, {
								label: __( 'Placeholder', 'smart-document-search' ),
								help: __( 'Text shown in the empty search field. Leave empty for the default Hebrew text.', 'smart-document-search' ),
								value: props.attributes.placeholder || '',
								onChange: function ( value ) {
									props.setAttributes( { placeholder: value } );
								},
							} )
						)
					)
					: null,
				// The preview is not interactive inside the editor.
				Disabled ? el( Disabled, null, preview ) : preview
			);
		},

		save: function () {
			return null;
		},
	} );
}( window.wp ) );
