<?php
/**
 * Front end: appends the download link, prepares the PDF over REST, and serves it.
 *
 * @package PostToPdfForConvertAPI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Link output, the ptpdf/v1/prepare REST route, and the ?ptpdf_download=ID endpoint.
 */
class PTPDF_Frontend {

	const QUERY_VAR = 'ptpdf_download';
	const REST_NS   = 'ptpdf/v1';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_filter( 'the_content', array( __CLASS__, 'append_link' ), 20 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_route' ) );
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
		if ( ! $post || $post->ID !== get_queried_object_id() || ! self::is_enabled_for( $post ) ) {
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
			'restUrl' => esc_url_raw( rest_url( self::REST_NS . '/prepare/' ) ),
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
		// Logged-in users (e.g. admins who should see error details) need a nonce for cookie auth over REST.
		// Logged-in pages are not normally page-cached, so the nonce will not leak into a shared cache.
		if ( is_user_logged_in() ) {
			$config['nonce'] = wp_create_nonce( 'wp_rest' );
		}
		wp_add_inline_script( 'ptpdf-frontend', 'window.ptpdfFrontend = ' . wp_json_encode( $config ) . ';', 'before' );

		$css = PTPDF_Settings::get()['link_css'];
		if ( '' !== $css ) {
			wp_add_inline_style( 'ptpdf-frontend', $css );
		}
	}

	/**
	 * REST route the link script calls to generate the PDF before downloading it.
	 */
	public static function register_rest_route() {
		register_rest_route(
			self::REST_NS,
			'/prepare/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_prepare' ),
				// Public on purpose, like the link itself; is_enabled_for() limits it to published, linked posts.
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Generate (or find) the PDF and return the URL that downloads it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_prepare( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! self::is_enabled_for( $post ) ) {
			return new WP_Error( 'ptpdf_unavailable', __( 'A PDF is not available for this content.', 'post-to-pdf-for-convertapi' ), array( 'status' => 404 ) );
		}

		$attachment_id = PTPDF_Converter::get_or_create( $post );
		if ( is_wp_error( $attachment_id ) ) {
			$data  = (array) $attachment_id->get_error_data();
			$error = new WP_Error(
				$attachment_id->get_error_code(),
				$attachment_id->get_error_message(),
				array( 'status' => isset( $data['status'] ) ? (int) $data['status'] : 500 )
			);
			if ( ! empty( $data['detail'] ) && current_user_can( 'manage_options' ) ) {
				$error->add_data( array_merge( $error->get_error_data(), array( 'detail' => $data['detail'] ) ) );
			}
			return $error;
		}

		$response = rest_ensure_response( array( 'url' => self::download_url( $post->ID ) ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
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
					'link_url'  => get_permalink( $post ),
					'link_text' => __( 'Back to the page', 'post-to-pdf-for-convertapi' ),
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
