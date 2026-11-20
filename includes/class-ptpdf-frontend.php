<?php
/**
 * Front end: appends the download link, prepares the PDF over admin-ajax, and serves it.
 *
 * @package PostToPdfForConvertAPI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Link output, the ptpdf_prepare AJAX action, and the ?ptpdf_download=ID endpoint.
 */
class PTPDF_Frontend {

	const QUERY_VAR   = 'ptpdf_download';
	const AJAX_ACTION = 'ptpdf_prepare';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_filter( 'the_content', array( __CLASS__, 'append_link' ), 20 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		// Public on purpose, like the link itself; is_enabled_for() limits it to published, linked posts.
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'ajax_prepare' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( __CLASS__, 'ajax_prepare' ) );
	}

	/**
	 * Whether the link should be offered for this post. PDFs are rendered from the public URL,
	 * so only published, non-password-protected posts qualify.
	 *
	 * @param WP_Post|null $post Post.
	 * @return bool
	 */
	public static function is_enabled_for( $post ) {
		$enabled = $post instanceof WP_Post
			&& in_array( $post->post_type, ptpdf_post_types(), true )
			&& 'publish' === $post->post_status
			&& '' === $post->post_password
			&& ! get_post_meta( $post->ID, PTPDF_META_HIDE_LINK, true )
			&& '' !== PTPDF_Settings::api_secret();

		/**
		 * Filters whether the PDF download is available for a post.
		 *
		 * @param bool         $enabled Whether the link is shown and the download allowed.
		 * @param WP_Post|null $post    Post.
		 */
		return (bool) apply_filters( 'ptpdf_enabled_for_post', $enabled, $post );
	}

	/**
	 * Download URL for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function download_url( $post_id ) {
		return add_query_arg( self::QUERY_VAR, $post_id, home_url( '/' ) );
	}

	/**
	 * Append the link to the main post's content on its single view.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function append_link( $content ) {
		if ( is_feed() || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post = get_post();
		if ( ! $post || get_queried_object_id() !== $post->ID || ! self::is_enabled_for( $post ) ) {
			return $content;
		}

		wp_enqueue_style( 'ptpdf-frontend' );
		wp_enqueue_script( 'ptpdf-frontend' );

		/**
		 * Filters the text of the download link.
		 *
		 * @param string  $text Link text.
		 * @param WP_Post $post Post.
		 */
		$text = PTPDF_Settings::get()['link_text'];
		$text = apply_filters( 'ptpdf_link_text', '' !== $text ? $text : __( 'Download as PDF', 'post-to-pdf-for-convertapi' ), $post );

		$link = sprintf(
			'<p class="ptpdf-download"><a href="%1$s" rel="nofollow" data-ptpdf-post="%2$d">%3$s</a>'
				. '<span class="ptpdf-status" role="status" aria-live="polite"></span></p>',
			esc_url( self::download_url( $post->ID ) ),
			(int) $post->ID,
			esc_html( $text )
		);

		return $content . $link;
	}

	/**
	 * Register our query var.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Register the link stylesheet (plus the Link CSS setting) and script; they print only when the link does.
	 */
	public static function register_assets() {
		// Versioned by file time so asset edits bust browser caches without bumping PTPDF_VERSION,
		// which would make every stored PDF regenerate.
		wp_register_style( 'ptpdf-frontend', PTPDF_URL . 'assets/frontend.css', array(), (string) filemtime( PTPDF_DIR . 'assets/frontend.css' ) );

		wp_register_script(
			'ptpdf-frontend',
			PTPDF_URL . 'assets/frontend.js',
			array(),
			(string) filemtime( PTPDF_DIR . 'assets/frontend.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		$config = array(
			'ajaxUrl' => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
			'action'  => self::AJAX_ACTION,
			'i18n'    => array(
				'preparing' => __( 'Preparing your PDF…', 'post-to-pdf-for-convertapi' ),
				'slow'      => __( 'Still working, this can take up to a minute…', 'post-to-pdf-for-convertapi' ),
				'starting'  => __( 'Your download is starting.', 'post-to-pdf-for-convertapi' ),
				'error'     => __( 'Sorry, the PDF could not be generated. Please try again later.', 'post-to-pdf-for-convertapi' ),
			),
		);
		/**
		 * Filters the messages shown while a PDF is being prepared.
		 *
		 * @param array $messages Keys: preparing, slow, starting, error.
		 */
		$config['i18n'] = apply_filters( 'ptpdf_frontend_messages', $config['i18n'] );
		wp_add_inline_script( 'ptpdf-frontend', 'window.ptpdfFrontend = ' . wp_json_encode( $config ) . ';', 'before' );

		$css = PTPDF_Settings::get()['link_css'];
		if ( '' !== $css ) {
			wp_add_inline_style( 'ptpdf-frontend', $css );
		}
	}

	/**
	 * AJAX handler the link script calls to generate the PDF before downloading it.
	 *
	 * No nonce: the action is public (anyone can trigger generation via the plain download link),
	 * so a nonce would only add stale-nonce failures on cached pages. Logged-in users are
	 * recognised by their auth cookie, which is how administrators get error details.
	 */
	public static function ajax_prepare() {
		nocache_headers();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- public, read-only action; see above.
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! self::is_enabled_for( $post ) ) {
			wp_send_json_error(
				array(
					'code'    => 'ptpdf_unavailable',
					'message' => __( 'A PDF is not available for this content.', 'post-to-pdf-for-convertapi' ),
				),
				404
			);
		}

		$attachment_id = PTPDF_Converter::get_or_create( $post );
		if ( is_wp_error( $attachment_id ) ) {
			$data  = (array) $attachment_id->get_error_data();
			$error = array(
				'code'    => $attachment_id->get_error_code(),
				'message' => $attachment_id->get_error_message(),
			);
			if ( ! empty( $data['detail'] ) && current_user_can( 'manage_options' ) ) {
				$error['detail'] = $data['detail'];
			}
			wp_send_json_error( $error, isset( $data['status'] ) ? (int) $data['status'] : 500 );
		}

		wp_send_json_success( array( 'url' => self::download_url( $post->ID ) ) );
	}

	/**
	 * Handle a download request: serve the stored PDF, generating it first if needed.
	 */
	public static function maybe_serve() {
		$post_id = absint( get_query_var( self::QUERY_VAR ) );
		if ( ! $post_id ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! self::is_enabled_for( $post ) ) {
			wp_die( esc_html__( 'A PDF is not available for this content.', 'post-to-pdf-for-convertapi' ), '', array( 'response' => 404 ) );
		}

		$attachment_id = PTPDF_Converter::get_or_create( $post );
		if ( is_wp_error( $attachment_id ) ) {
			$data    = $attachment_id->get_error_data();
			$message = esc_html( $attachment_id->get_error_message() );
			if ( ! empty( $data['detail'] ) && current_user_can( 'manage_options' ) ) {
				$message .= '<p><strong>' . esc_html__( 'Details (shown to administrators only):', 'post-to-pdf-for-convertapi' ) . '</strong> ' . esc_html( $data['detail'] ) . '</p>';
			}
			wp_die(
				$message, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				esc_html__( 'PDF unavailable', 'post-to-pdf-for-convertapi' ),
				array(
					'response'  => isset( $data['status'] ) ? (int) $data['status'] : 500,
					'link_url'  => esc_url( get_permalink( $post ) ),
					'link_text' => esc_html__( 'Back to the page', 'post-to-pdf-for-convertapi' ),
				)
			);
		}

		self::send_file( $attachment_id, PTPDF_Converter::file_basename( $post ) . '.pdf' );
	}

	/**
	 * Stream the attachment as a download, or redirect to it if the file is not stored locally.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $filename      Download file name.
	 */
	private static function send_file( $attachment_id, $filename ) {
		$path = get_attached_file( $attachment_id );

		if ( ! $path || ! is_readable( $path ) ) {
			// Offloaded media (e.g. object storage); let the browser fetch it from there.
			wp_redirect( wp_get_attachment_url( $attachment_id ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- CDN host may differ.
			exit;
		}

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $filename ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a download.
		exit;
	}
}
