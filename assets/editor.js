/**
 * Block editor sidebar panel: toggle the "Download as PDF" link for this post.
 */
( function ( wp, config ) {
	const { registerPlugin } = wp.plugins;
	const PluginDocumentSettingPanel =
		( wp.editor && wp.editor.PluginDocumentSettingPanel ) ||
		wp.editPost.PluginDocumentSettingPanel;
	const { ToggleControl } = wp.components;
	const { useSelect, useDispatch } = wp.data;
	const { createElement: el } = wp.element;
	const { __ } = wp.i18n;
	const metaKey = config.metaKey;

	function PdfLinkPanel() {
		const hidden = useSelect(
			( select ) =>
				!! ( select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {} )[ metaKey ],
			[]
		);
		const { editPost } = useDispatch( 'core/editor' );

		return el(
			PluginDocumentSettingPanel,
			{ name: 'ptpdf-download-link', title: __( 'PDF download', 'post-to-pdf-for-convertapi' ) },
			el( ToggleControl, {
				__nextHasNoMarginBottom: true,
				label: __( 'Show "Download as PDF" link', 'post-to-pdf-for-convertapi' ),
				checked: ! hidden,
				onChange: ( show ) => editPost( { meta: { [ metaKey ]: ! show } } ),
			} )
		);
	}

	registerPlugin( 'ptpdf-download-link', { render: PdfLinkPanel, icon: 'media-document' } );
} )( window.wp, window.ptpdfEditor );
