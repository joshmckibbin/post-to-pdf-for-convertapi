<?php
/**
 * Self-contained HTML snapshot of a page, for sites ConvertAPI cannot reach (local dev, intranets, staging behind auth).
 *
 * @package PostToPdfForConvertAPI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fetches a page over loopback and embeds its local stylesheets, scripts, images and fonts.
 */
class PTPDF_Snapshot {

	/** Local files larger than this are not embedded. */
	const MAX_EMBED_BYTES = 5 * MB_IN_BYTES;

	/** File types that may be read from disk and embedded. Never includes PHP. */
	const MIME_TYPES = array(
		'css'   => 'text/css',
		'js'    => 'text/javascript',
		'png'   => 'image/png',
		'jpg'   => 'image/jpeg',
		'jpeg'  => 'image/jpeg',
		'gif'   => 'image/gif',
		'webp'  => 'image/webp',
		'avif'  => 'image/avif',
		'svg'   => 'image/svg+xml',
		'ico'   => 'image/x-icon',
		'woff'  => 'font/woff',
		'woff2' => 'font/woff2',
		'ttf'   => 'font/ttf',
		'otf'   => 'font/otf',
		'eot'   => 'application/vnd.ms-fontobject',
	);

	/**
	 * Local hosts whose URLs should be embedded.
	 *
	 * @var string[]
	 */
	private $local_hosts = array();

	/**
	 * Capture a page as a single HTML document.
	 *
	 * @param string $url Page URL.
	 * @return string|WP_Error HTML.
	 */
	public static function capture( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 30,
				// Same default core uses for its own loopback requests; local sites often use self-signed certificates.
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'headers'   => array( 'Accept' => 'text/html' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ptpdf_loopback', 'Could not load the page from the server itself: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( 'ptpdf_loopback', sprintf( 'Loading the page from the server itself returned HTTP %d.', $code ) );
		}

		return ( new self() )->inline_assets( wp_remote_retrieve_body( $response ), $url );
	}

	/**
	 * Replace local <img> sources in an HTML fragment with data URIs.
	 * Used for PDF headers and footers, which cannot load images over the network.
	 *
	 * @param string $html HTML fragment.
	 * @return string
	 */
	public static function embed_images( $html ) {
		$self = new self();
		return preg_replace_callback(
			'/(<img\b[^>]*?\ssrc=)(["\'])(.*?)\2/i',
			function ( $m ) use ( $self ) {
				$abs  = WP_Http::make_absolute_url( html_entity_decode( $m[3] ), home_url( '/' ) );
				$data = $self->is_local( $abs ) ? $self->data_uri( $abs ) : null;
				return $m[1] . $m[2] . ( $data ? $data : esc_url( $abs ) ) . $m[2];
			},
			$html
		);
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		foreach ( array( home_url(), site_url(), content_url(), includes_url() ) as $base ) {
			$host = wp_parse_url( $base, PHP_URL_HOST );
			if ( $host ) {
				$this->local_hosts[ strtolower( $host ) ] = true;
			}
		}
	}

	/**
	 * Rewrite the document so it needs nothing from this server.
	 *
	 * @param string $html     Page HTML.
	 * @param string $page_url Page URL, for resolving relative references.
	 * @return string
	 */
	private function inline_assets( $html, $page_url ) {
		$doc   = new DOMDocument();
		$prior = libxml_use_internal_errors( true );
		// The XML prolog makes libxml treat the input as UTF-8; it is removed again on output.
		$doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $prior );

		$xpath = new DOMXPath( $doc );

		// Stylesheets → <style>.
		foreach ( iterator_to_array( $xpath->query( '//link[@href]' ) ) as $link ) {
			$rel = strtolower( $link->getAttribute( 'rel' ) );
			$abs = WP_Http::make_absolute_url( $link->getAttribute( 'href' ), $page_url );

			if ( preg_match( '/\bstylesheet\b/', $rel ) && $this->is_local( $abs ) ) {
				$css = $this->read_local( $abs, 'css' );
				if ( null !== $css ) {
					$style = $doc->createElement( 'style' );
					$style->appendChild( $doc->createTextNode( $this->rewrite_css( $css, $abs ) ) );
					if ( $link->getAttribute( 'media' ) ) {
						$style->setAttribute( 'media', $link->getAttribute( 'media' ) );
					}
					$link->parentNode->replaceChild( $style, $link );
					continue;
				}
			}
			$link->setAttribute( 'href', $abs );
		}

		// Inline <style> blocks and style="" attributes.
		foreach ( $xpath->query( '//style' ) as $style ) {
			$this->set_text( $style, $this->rewrite_css( $style->textContent, $page_url ) );
		}
		foreach ( $xpath->query( '//*[@style]' ) as $el ) {
			$el->setAttribute( 'style', $this->rewrite_css( $el->getAttribute( 'style' ), $page_url ) );
		}

		// Scripts → inline.
		foreach ( $xpath->query( '//script[@src]' ) as $script ) {
			$abs = WP_Http::make_absolute_url( $script->getAttribute( 'src' ), $page_url );
			$js  = $this->is_local( $abs ) && 'module' !== $script->getAttribute( 'type' ) ? $this->read_local( $abs, 'js' ) : null;
			if ( null !== $js ) {
				$script->removeAttribute( 'src' );
				// Literal "</script" inside the code would end the element early.
				$this->set_text( $script, str_ireplace( '</script', '<\/script', $js ) );
			} else {
				$script->setAttribute( 'src', $abs );
			}
		}

		// Images → data URIs. srcset/sizes are dropped for local images so the embedded src is used.
		foreach ( $xpath->query( '//img[@src] | //image[@href] | //video[@poster] | //input[@type="image"][@src]' ) as $el ) {
			$attr = $el->hasAttribute( 'src' ) ? 'src' : ( $el->hasAttribute( 'href' ) ? 'href' : 'poster' );
			$abs  = WP_Http::make_absolute_url( $el->getAttribute( $attr ), $page_url );
			$data = $this->is_local( $abs ) ? $this->data_uri( $abs ) : null;
			$el->setAttribute( $attr, $data ? $data : $abs );
			if ( $data ) {
				$el->removeAttribute( 'srcset' );
				$el->removeAttribute( 'sizes' );
				$el->removeAttribute( 'loading' );
			}
		}
		foreach ( $xpath->query( '//source[@srcset]' ) as $source ) {
			$first = trim( explode( ' ', trim( explode( ',', $source->getAttribute( 'srcset' ) )[0] ) )[0] );
			$abs   = WP_Http::make_absolute_url( $first, $page_url );
			if ( $this->is_local( $abs ) ) {
				// Let the <img> fallback (already embedded) render instead.
				$source->parentNode->removeChild( $source );
			}
		}

		$out = $doc->saveHTML();
		return str_replace( '<?xml encoding="UTF-8">', '', $out );
	}

	/**
	 * Replace an element's children with a single text node.
	 *
	 * @param DOMElement $el   Element.
	 * @param string     $text Text.
	 */
	private function set_text( DOMElement $el, $text ) {
		while ( $el->firstChild ) {
			$el->removeChild( $el->firstChild );
		}
		$el->appendChild( $el->ownerDocument->createTextNode( $text ) );
	}

	/**
	 * Embed local url() references in CSS; make the rest absolute.
	 *
	 * @param string $css      CSS text.
	 * @param string $base_url URL the CSS was loaded from.
	 * @return string
	 */
	private function rewrite_css( $css, $base_url ) {
		return preg_replace_callback(
			'/url\(\s*([\'"]?)(.*?)\1\s*\)/i',
			function ( $m ) use ( $base_url ) {
				$ref = trim( $m[2] );
				if ( '' === $ref || 0 === strpos( $ref, 'data:' ) || 0 === strpos( $ref, '#' ) ) {
					return $m[0];
				}
				$abs  = WP_Http::make_absolute_url( $ref, $base_url );
				$data = $this->is_local( $abs ) ? $this->data_uri( $abs ) : null;
				return 'url("' . ( $data ? $data : $abs ) . '")';
			},
			$css
		);
	}

	/**
	 * Whether a URL points at this site.
	 *
	 * @param string $url Absolute URL.
	 * @return bool
	 */
	private function is_local( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return $host && isset( $this->local_hosts[ strtolower( $host ) ] );
	}

	/**
	 * A local file as a data: URI.
	 *
	 * @param string $url Absolute local URL.
	 * @return string|null
	 */
	private function data_uri( $url ) {
		$ext = $this->extension( $url );
		if ( ! $ext || 'css' === $ext || 'js' === $ext ) {
			return null;
		}
		$bytes = $this->read_local( $url, $ext );
		return null === $bytes ? null : 'data:' . self::MIME_TYPES[ $ext ] . ';base64,' . base64_encode( $bytes );
	}

	/**
	 * Read a local asset, from disk when it maps to a file in the WordPress install, otherwise over loopback.
	 *
	 * @param string $url          Absolute local URL.
	 * @param string $expected_ext Required extension (css/js) or the detected one.
	 * @return string|null Contents, or null if unavailable.
	 */
	private function read_local( $url, $expected_ext ) {
		$path = $this->url_to_path( $url );
		if ( $path ) {
			if ( $this->extension( $path ) !== $expected_ext || filesize( $path ) > self::MAX_EMBED_BYTES ) {
				return null;
			}
			$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local asset.
			return false === $contents ? null : $contents;
		}

		// Generated assets (e.g. CSS output by PHP) have no file; fetch them.
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 15,
				'sslverify'           => apply_filters( 'https_local_ssl_verify', false ),
				'limit_response_size' => self::MAX_EMBED_BYTES,
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Map a local URL to a readable file inside the WordPress install with an allowed extension.
	 *
	 * @param string $url Absolute local URL.
	 * @return string|null
	 */
	private function url_to_path( $url ) {
		$url_path = rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) );

		$roots = array(
			array( content_url( '/' ), WP_CONTENT_DIR . '/' ),
			array( includes_url( '/' ), ABSPATH . WPINC . '/' ),
			array( site_url( '/' ), ABSPATH ),
		);
		foreach ( $roots as $root ) {
			$prefix = (string) wp_parse_url( $root[0], PHP_URL_PATH );
			if ( '' === $prefix || 0 !== strpos( $url_path, $prefix ) ) {
				continue;
			}
			$path = realpath( $root[1] . substr( $url_path, strlen( $prefix ) ) );
			$base = realpath( $root[1] );
			if ( $path && $base && 0 === strpos( $path, $base ) && is_file( $path ) && is_readable( $path )
				&& isset( self::MIME_TYPES[ $this->extension( $path ) ] ) ) {
				return $path;
			}
		}
		return null;
	}

	/**
	 * Lower-case file extension of a URL or path, ignoring the query string.
	 *
	 * @param string $url_or_path URL or path.
	 * @return string
	 */
	private function extension( $url_or_path ) {
		$path = (string) ( wp_parse_url( $url_or_path, PHP_URL_PATH ) ?? $url_or_path );
		return strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	}
}
