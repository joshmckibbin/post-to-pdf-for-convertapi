<?php
/**
 * Per-post toggle for the download link in the block and classic editors.
 *
 * @package PostToPdfForConvertAPI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the hide-link meta and its editor controls.
 */
class PTPDF_Editor {

	const NONCE = 'ptpdf_meta_box';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_block_editor' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save_meta_box' ), 10, 2 );
	}

	/**
	 * Register the meta so the block editor can read and write it over REST.
	 */
	public static function register_meta() {
		foreach ( ptpdf_post_types() as $post_type ) {
			register_post_meta(
				$post_type,
				PTPDF_META_HIDE_LINK,
				array(
					'type'          => 'boolean',
					'single'        => true,
					'default'       => false,
					'show_in_rest'  => true,
					'auth_callback' => static function ( $allowed, $meta_key, $post_id ) {
						return current_user_can( 'edit_post', $post_id );
					},
				)
			);
		}
	}

	/**
	 * Load the document sidebar panel in the block editor.
	 */
	public static function enqueue_block_editor() {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, ptpdf_post_types(), true ) ) {
			return;
		}

		wp_enqueue_script(
			'ptpdf-editor',
			PTPDF_URL . 'assets/editor.js',
			array( 'wp-plugins', 'wp-editor', 'wp-edit-post', 'wp-components', 'wp-data', 'wp-element', 'wp-i18n' ),
			PTPDF_VERSION,
			true
		);
		wp_localize_script( 'ptpdf-editor', 'ptpdfEditor', array( 'metaKey' => PTPDF_META_HIDE_LINK ) );
		wp_set_script_translations( 'ptpdf-editor', 'post-to-pdf-for-convertapi' );
	}

	/**
	 * Classic editor fallback. Hidden in the block editor, which uses the sidebar panel instead.
	 */
	public static function add_meta_box() {
		add_meta_box(
			'ptpdf-download-link',
			__( 'PDF download', 'post-to-pdf-for-convertapi' ),
			array( __CLASS__, 'render_meta_box' ),
			ptpdf_post_types(),
			'side',
			'default',
			array( '__back_compat_meta_box' => true )
		);
	}

	/**
	 * Render the classic editor checkbox.
	 *
	 * @param WP_Post $post Current post.
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE );
		$hidden = (bool) get_post_meta( $post->ID, PTPDF_META_HIDE_LINK, true );
		?>
		<label>
			<input type="checkbox" name="ptpdf_show_link" value="1" <?php checked( ! $hidden ); ?> />
			<?php esc_html_e( 'Show "Download as PDF" link', 'post-to-pdf-for-convertapi' ); ?>
		</label>
		<?php
	}

	/**
	 * Save the classic editor checkbox. Block editor saves go through REST and never include our nonce.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_meta_box( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( $_POST[ self::NONCE ] ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, ptpdf_post_types(), true ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( empty( $_POST['ptpdf_show_link'] ) ) {
			update_post_meta( $post_id, PTPDF_META_HIDE_LINK, true );
		} else {
			delete_post_meta( $post_id, PTPDF_META_HIDE_LINK );
		}
	}
}
