<?php
/**
 * ConvertAPI integration and Media Library storage.
 *
 * @package PostToPdfForConvertAPI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Finds or generates the PDF attachment for a post.
 */
class PTPDF_Converter {

	const ENDPOINT = 'https://v2.convertapi.com/convert/html/to/pdf';

	/** Seconds a generation lock is honoured before being treated as abandoned. */
	const LOCK_TTL = 180;

	/** Seconds a request waits for another request's in-progress conversion. */
	const WAIT_TIMEOUT = 90;

	/** Selector for our own link, always hidden in the PDF. */
	const LINK_SELECTOR = '.ptpdf-download';

	/** Video embeds, always hidden in the PDF: block and classic embeds, plus bare iframes from common video hosts. */
	const VIDEO_SELECTORS = array(
		'.wp-block-embed.is-type-video',
		'.wp-block-video',
		'.wp-video',
		'.wp-block-embed-youtube',
		'.wp-block-embed-vimeo',
		'video',
		'iframe[src*="youtube.com"]',
		'iframe[src*="youtube-nocookie.com"]',
		'iframe[src*="youtu.be"]',
		'iframe[src*="vimeo.com"]',
		'iframe[src*="wistia"]',
		'iframe[src*="kaltura"]',
		'iframe[src*="panopto"]',
		'iframe[src*="brightcove"]',
		'iframe[src*="dailymotion"]',
		'iframe[src*="videopress"]',
		'iframe[src*="loom.com"]',
		'iframe[src*="vidyard"]',
		'iframe[src*="streamable"]',
		'iframe[src*="tiktok"]',
	);

	/**
	 * Return a current PDF attachment ID for the post, generating one if needed.
	 *
	 * @param WP_Post $post Source post.
	 * @return int|WP_Error Attachment ID.
	 */
	public static function get_or_create( WP_Post $post ) {
		$existing = self::find_current( $post );
		if ( $existing ) {
			return $existing;
		}

		$locked   = self::acquire_lock( $post->ID );
		$deadline = time() + self::WAIT_TIMEOUT;
		// Another request is converting this post; wait for its result rather than paying for a second conversion.
		while ( ! $locked && time() < $deadline ) {
			sleep( 2 );
			wp_cache_delete( $post->ID, 'post_meta' );
			$existing = self::find_current( $post );
			if ( $existing ) {
				return $existing;
			}
			$locked = self::acquire_lock( $post->ID );
		}
		if ( ! $locked ) {
			return new WP_Error( 'ptpdf_busy', __( 'The PDF is still being generated. Please try again in a moment.', 'post-to-pdf-for-convertapi' ), array( 'status' => 503 ) );
		}

		try {
			// Re-check: the previous lock holder may have finished between our checks.
			wp_cache_delete( $post->ID, 'post_meta' );
			$existing = self::find_current( $post );
			if ( $existing ) {
				return $existing;
			}

			$previous = (int) get_post_meta( $post->ID, PTPDF_META_ATTACHMENT, true );

			$pdf = self::convert( $post );
			if ( is_wp_error( $pdf ) ) {
				return $pdf;
			}

			$attachment_id = self::store( $post, $pdf );
			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}

			// Replace the outdated PDF rather than accumulating copies.
			if ( $previous && $previous !== $attachment_id && self::is_generated_attachment( $previous ) ) {
				wp_delete_attachment( $previous, true );
			}

			return $attachment_id;
		} finally {
			self::release_lock( $post->ID );
		}
	}

	/**
	 * The stored PDF for this post, if it exists and was generated from the current revision and settings.
	 *
	 * @param WP_Post $post Source post.
	 * @return int Attachment ID, or 0.
	 */
	public static function find_current( WP_Post $post ) {
		$attachment_id = (int) get_post_meta( $post->ID, PTPDF_META_ATTACHMENT, true );
		if ( ! $attachment_id || ! self::is_generated_attachment( $attachment_id ) ) {
			return 0;
		}

		if ( get_post_meta( $attachment_id, PTPDF_META_SOURCE_MODIFIED, true ) !== $post->post_modified_gmt
			|| get_post_meta( $attachment_id, PTPDF_META_SETTINGS_HASH, true ) !== PTPDF_Settings::render_fingerprint() ) {
			return 0;
		}

		return $attachment_id;
	}

	/**
	 * Whether the attachment exists and was created by this plugin.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private static function is_generated_attachment( $attachment_id ) {
		return 'attachment' === get_post_type( $attachment_id )
			&& '' !== get_post_meta( $attachment_id, PTPDF_META_SOURCE_POST, true );
	}

	/**
	 * Call ConvertAPI to render the post to PDF.
	 *
	 * @param WP_Post $post Source post.
	 * @return string|WP_Error Raw PDF bytes.
	 */
	private static function convert( WP_Post $post ) {
		$secret = PTPDF_Settings::api_secret();
		if ( '' === $secret ) {
			return new WP_Error( 'ptpdf_no_secret', __( 'PDF generation is not configured.', 'post-to-pdf-for-convertapi' ), array( 'status' => 500 ) );
		}

		$url    = get_permalink( $post );
		$params = self::build_params( $post );

		$html = null;
		if ( self::should_upload_html() ) {
			$html = PTPDF_Snapshot::capture( $url );
			if ( is_wp_error( $html ) ) {
				return self::fail( $html->get_error_message() );
			}
		} else {
			$params['Url'] = $url;
		}

		/**
		 * Filters the parameters sent to ConvertAPI's HTML to PDF converter.
		 *
		 * @see https://www.convertapi.com/html-to-pdf
		 *
		 * @param array   $params Request parameters. Contains Url unless the page HTML is being uploaded.
		 * @param WP_Post $post   Source post.
		 */
		$params = apply_filters( 'ptpdf_convertapi_params', $params, $post );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may be disabled on the host.
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $secret,
			'Accept'        => 'application/json',
		);

		if ( null === $html ) {
			$body = $params;
		} else {
			$boundary                = 'ptpdf-' . wp_generate_password( 24, false );
			$headers['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;
			$body                    = self::multipart_body( $boundary, $params, self::file_basename( $post ) . '.html', $html );
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 180,
				'headers' => $headers,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::fail( 'ConvertAPI request failed: ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$message = is_array( $body ) && isset( $body['Message'] ) ? $body['Message'] : wp_remote_retrieve_response_message( $response );
			return self::fail( sprintf( 'ConvertAPI returned HTTP %d: %s', $code, $message ) );
		}

		$file = is_array( $body ) && ! empty( $body['Files'][0] ) ? $body['Files'][0] : null;
		if ( ! $file ) {
			return self::fail( 'ConvertAPI response contained no file.' );
		}

		if ( ! empty( $file['FileData'] ) ) {
			$pdf = base64_decode( $file['FileData'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- API payload encoding.
		} elseif ( ! empty( $file['Url'] ) ) {
			// Returned when StoreFile is enabled through the params filter.
			$download = wp_remote_get( $file['Url'], array( 'timeout' => 120 ) );
			$pdf      = is_wp_error( $download ) ? false : wp_remote_retrieve_body( $download );
		} else {
			$pdf = false;
		}

		if ( ! $pdf || 0 !== strpos( $pdf, '%PDF' ) ) {
			return self::fail( 'ConvertAPI response did not contain a valid PDF.' );
		}

		return $pdf;
	}

	/**
	 * ConvertAPI parameters for a post, without the page source (Url or File).
	 *
	 * @param WP_Post $post Source post.
	 * @return array
	 */
	private static function build_params( WP_Post $post ) {
		$settings = PTPDF_Settings::get();

		/**
		 * Filters the selectors always hidden in the PDF (video embeds, the download link, the admin bar).
		 *
		 * @param string[] $selectors CSS selectors.
		 * @param WP_Post  $post      Source post.
		 */
		$hidden = (array) apply_filters(
			'ptpdf_hidden_selectors',
			array_merge( array( self::LINK_SELECTOR, '#wpadminbar' ), self::VIDEO_SELECTORS ),
			$post
		);

		$params = array(
			'HideElements'       => implode( ', ', $hidden ),
			'CookieConsentBlock' => 'true',
			'LoadLazyContent'    => 'true',
			'FileName'           => self::file_basename( $post ),
			'StoreFile'          => 'false',
		);

		// CSS is injected by script rather than sent as UserCss, which ConvertAPI did not apply to uploaded HTML.
		// HideElements is kept as well, but the script is what reliably hides these.
		$css = $hidden ? implode( ",\n", $hidden ) . " {\n\tdisplay: none !important;\n}" : '';
		if ( '' !== $settings['custom_css'] ) {
			$css .= "\n" . $settings['custom_css'];
		}
		$scripts = array();
		if ( '' !== trim( $css ) ) {
			$scripts[] = self::css_script( $css );
		}
		$included = PTPDF_Settings::included_selectors();
		if ( $included ) {
			$scripts[] = self::inclusion_script( $included );
		}
		if ( $scripts ) {
			$params['UserJs'] = implode( "\n", $scripts );
		}

		foreach ( array( 'header' => 'Top', 'footer' => 'Bottom' ) as $part => $side ) {
			if ( '' !== $settings[ $part . '_html' ] ) {
				$params[ ucfirst( $part ) ]  = self::header_footer_template( $settings[ $part . '_html' ], $post );
				$params[ 'Margin' . $side ] = (int) $settings[ $part . '_height' ];
			}
		}

		return $params;
	}

	/**
	 * JavaScript that appends the custom CSS as the last <style> in <body>, so it wins the cascade
	 * over theme styles printed earlier, including block styles output inside <body>.
	 *
	 * @param string $css Custom CSS.
	 * @return string
	 */
	private static function css_script( $css ) {
		return '(function(){'
			. 'var s=document.createElement("style");s.id="ptpdf-custom-css";'
			. 's.textContent=' . wp_json_encode( $css, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ';'
			. '(document.body||document.documentElement).appendChild(s);'
			. '})();';
	}

	/**
	 * JavaScript that hides everything except the included elements, their descendants and
	 * the ancestors needed to reach them. If no selector matches, the whole page is kept.
	 *
	 * @param string[] $selectors CSS selectors.
	 * @return string
	 */
	private static function inclusion_script( array $selectors ) {
		return '(function(){'
			. 'var sel=' . wp_json_encode( array_values( $selectors ) ) . ',keep=[];'
			. 'sel.forEach(function(q){try{keep=keep.concat([].slice.call(document.querySelectorAll(q)));}catch(e){}});'
			. 'if(!keep.length){return;}'
			. 'var skip=/^(SCRIPT|STYLE|LINK|META|NOSCRIPT|TEMPLATE)$/;'
			. 'function prune(el){[].slice.call(el.children).forEach(function(c){'
			. 'if(keep.indexOf(c)>-1||skip.test(c.tagName)){return;}'
			. 'if(keep.some(function(k){return c.contains(k);})){prune(c);return;}'
			. 'c.style.setProperty("display","none","important");'
			. '});}'
			. 'prune(document.body);'
			. '})();';
	}

	/**
	 * Wrap header/footer HTML for Chromium's print template: fill placeholders, embed local images,
	 * and supply base styles because templates do not inherit the page's CSS.
	 *
	 * @param string  $html Header or footer HTML from the settings.
	 * @param WP_Post $post Source post.
	 * @return string
	 */
	private static function header_footer_template( $html, WP_Post $post ) {
		$html = strtr(
			$html,
			array(
				'{page_number}' => '<span class="pageNumber"></span>',
				'{total_pages}' => '<span class="totalPages"></span>',
				'{title}'       => esc_html( wp_strip_all_tags( get_the_title( $post ) ) ),
				'{url}'         => esc_html( get_permalink( $post ) ),
				'{date}'        => esc_html( wp_date( get_option( 'date_format' ) ) ),
				'{site_name}'   => esc_html( get_bloginfo( 'name' ) ),
			)
		);
		$html = PTPDF_Snapshot::embed_images( wpautop( $html ) );

		$css = '.ptpdf-hf{box-sizing:border-box;width:100%;padding:0 10mm;font-family:Helvetica,Arial,sans-serif;'
			. 'font-size:9pt;line-height:1.3;color:#333;-webkit-print-color-adjust:exact;print-color-adjust:exact}'
			. '.ptpdf-hf p{margin:0 0 .25em}.ptpdf-hf p:last-child{margin-bottom:0}'
			. '.ptpdf-hf img{max-width:100%;height:auto;vertical-align:middle}'
			. '.ptpdf-hf .aligncenter,.ptpdf-hf .has-text-align-center{text-align:center;display:block;margin-left:auto;margin-right:auto}'
			. '.ptpdf-hf .alignleft{float:left;margin-right:1em}.ptpdf-hf .alignright{float:right;margin-left:1em}'
			. '.ptpdf-hf::after{content:"";display:table;clear:both}';

		return '<style>' . $css . '</style><div class="ptpdf-hf">' . $html . '</div>';
	}

	/**
	 * Whether to upload the page HTML instead of letting ConvertAPI fetch the URL.
	 *
	 * @return bool
	 */
	public static function should_upload_html() {
		$mode = PTPDF_Settings::get()['render_mode'];
		if ( 'auto' === $mode ) {
			$mode = self::site_is_public() ? 'url' : 'html';
		}
		return 'html' === $mode;
	}

	/**
	 * Best guess at whether ConvertAPI's servers can reach this site.
	 *
	 * @return bool
	 */
	public static function site_is_public() {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		if ( '' === $host || preg_match( '/(^localhost$|\.(local|localhost|test|internal|lan|home\.arpa|invalid|example)$)/', $host ) ) {
			$public = false;
		} else {
			$ip     = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : gethostbyname( $host );
			$public = (bool) filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}

		/**
		 * Filters whether the site is considered reachable by ConvertAPI in "auto" mode.
		 *
		 * @param bool   $public Whether the site is public.
		 * @param string $host   Site host name.
		 */
		return (bool) apply_filters( 'ptpdf_site_is_public', $public, $host );
	}

	/**
	 * Build a multipart/form-data body with the HTML as the File parameter.
	 *
	 * @param string $boundary Multipart boundary.
	 * @param array  $params   Other parameters.
	 * @param string $filename Uploaded file name.
	 * @param string $html     File contents.
	 * @return string
	 */
	private static function multipart_body( $boundary, array $params, $filename, $html ) {
		$body = '';
		foreach ( $params as $name => $value ) {
			$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
		}
		$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"File\"; filename=\"{$filename}\"\r\n";
		$body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n{$html}\r\n";
		$body .= "--{$boundary}--\r\n";
		return $body;
	}

	/**
	 * Save PDF bytes to the uploads folder and register them as an attachment of the post.
	 *
	 * @param WP_Post $post Source post.
	 * @param string  $pdf  PDF bytes.
	 * @return int|WP_Error Attachment ID.
	 */
	private static function store( WP_Post $post, $pdf ) {
		$upload = wp_upload_bits( self::file_basename( $post ) . '.pdf', null, $pdf );
		if ( ! empty( $upload['error'] ) ) {
			return self::fail( 'Could not save PDF: ' . $upload['error'] );
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_title'     => sprintf(
					/* translators: %s: post title. */
					__( '%s (PDF)', 'post-to-pdf-for-convertapi' ),
					wp_strip_all_tags( get_the_title( $post ) )
				),
				'post_mime_type' => 'application/pdf',
				'post_status'    => 'inherit',
				'post_author'    => $post->post_author,
			),
			$upload['file'],
			$post->ID,
			true
		);

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $upload['file'] );
			return self::fail( 'Could not create attachment: ' . $attachment_id->get_error_message() );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );

		update_post_meta( $attachment_id, PTPDF_META_SOURCE_POST, $post->ID );
		update_post_meta( $attachment_id, PTPDF_META_SOURCE_MODIFIED, $post->post_modified_gmt );
		update_post_meta( $attachment_id, PTPDF_META_SETTINGS_HASH, PTPDF_Settings::render_fingerprint() );
		update_post_meta( $post->ID, PTPDF_META_ATTACHMENT, $attachment_id );

		return $attachment_id;
	}

	/**
	 * Delete every attachment generated by this plugin.
	 *
	 * @return int Number deleted.
	 */
	public static function delete_all_generated() {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => PTPDF_META_SOURCE_POST, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- admin-only bulk action.
			)
		);

		$count = 0;
		foreach ( $ids as $id ) {
			if ( wp_delete_attachment( $id, true ) ) {
				++$count;
			}
		}
		delete_post_meta_by_key( PTPDF_META_ATTACHMENT );

		return $count;
	}

	/**
	 * File name (without extension) for a post's PDF.
	 *
	 * @param WP_Post $post Source post.
	 * @return string
	 */
	public static function file_basename( WP_Post $post ) {
		$name = sanitize_file_name( $post->post_name ? $post->post_name : sanitize_title( $post->post_title ) );
		return '' !== $name ? $name : 'post-' . $post->ID;
	}

	/**
	 * Take an atomic per-post lock. add_option() only succeeds if the row does not exist yet.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function acquire_lock( $post_id ) {
		$key = 'ptpdf_lock_' . $post_id;
		if ( add_option( $key, time(), '', false ) ) {
			return true;
		}

		wp_cache_delete( $key, 'options' );
		$started = (int) get_option( $key );
		if ( $started && time() - $started > self::LOCK_TTL ) {
			delete_option( $key );
			return add_option( $key, time(), '', false );
		}
		return false;
	}

	/**
	 * Release the per-post lock.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function release_lock( $post_id ) {
		delete_option( 'ptpdf_lock_' . $post_id );
	}

	/**
	 * Log the technical detail and return a generic error for visitors.
	 *
	 * @param string $detail Message for the error log.
	 * @return WP_Error
	 */
	private static function fail( $detail ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operational logging.
		error_log( '[Post to PDF for ConvertAPI] ' . $detail );
		return new WP_Error(
			'ptpdf_failed',
			__( 'Sorry, the PDF could not be generated. Please try again later.', 'post-to-pdf-for-convertapi' ),
			array(
				'status' => 500,
				'detail' => $detail,
			)
		);
	}
}
