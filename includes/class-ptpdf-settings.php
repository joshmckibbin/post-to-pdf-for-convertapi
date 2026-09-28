<?php
/**
 * Settings page: ConvertAPI secret, rendering method, included elements, custom CSS, download link, header and footer.
 *
 * @package PostToPdfForConvertAPI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders Settings → Post to PDF.
 */
class PTPDF_Settings {

	const OPTION     = 'ptpdf_settings';
	const PAGE_SLUG  = 'post-to-pdf-for-convertapi';
	const CLEAR_HOOK = 'ptpdf_clear_pdfs';

	/** Settings that change the rendered PDF; stored PDFs are regenerated when these change. */
	const RENDER_KEYS = array( 'render_mode', 'included_selectors', 'custom_css', 'header_html', 'header_height', 'footer_html', 'footer_height' );

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_css_editor' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_' . self::CLEAR_HOOK, array( __CLASS__, 'handle_clear' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PTPDF_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Default values.
	 *
	 * @return array
	 */
	private static function defaults() {
		return array(
			'api_secret'         => '',
			'render_mode'        => 'auto',
			'included_selectors' => '',
			'custom_css'         => '',
			'link_text'          => '',
			'link_css'           => '',
			'header_html'        => '',
			'header_height'      => 25,
			'footer_html'        => '',
			'footer_height'      => 20,
		);
	}

	/**
	 * Stored settings merged with defaults.
	 *
	 * @return array{api_secret:string,render_mode:string,included_selectors:string,custom_css:string,link_text:string,link_css:string,header_html:string,header_height:int,footer_html:string,footer_height:int}
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * ConvertAPI secret. A PTPDF_CONVERTAPI_SECRET constant in wp-config.php wins over the saved option.
	 *
	 * @return string
	 */
	public static function api_secret() {
		if ( defined( 'PTPDF_CONVERTAPI_SECRET' ) && PTPDF_CONVERTAPI_SECRET ) {
			return (string) PTPDF_CONVERTAPI_SECRET;
		}
		return (string) self::get()['api_secret'];
	}

	/**
	 * Included selectors as a list. Empty means the whole page.
	 *
	 * @return string[]
	 */
	public static function included_selectors() {
		$lines = preg_split( '/[\r\n]+/', self::get()['included_selectors'] );
		return array_values( array_filter( array_map( 'trim', $lines ) ) );
	}

	/**
	 * Hash of everything that affects the PDF output, stored on each generated PDF.
	 *
	 * @return string
	 */
	public static function render_fingerprint() {
		$settings = self::get();
		$relevant = array( 'version' => PTPDF_VERSION );
		foreach ( self::RENDER_KEYS as $key ) {
			$relevant[ $key ] = $settings[ $key ];
		}
		return md5( wp_json_encode( $relevant ) );
	}

	/**
	 * Add the page under Settings.
	 */
	public static function add_page() {
		add_options_page(
			__( 'Post to PDF for ConvertAPI', 'post-to-pdf-for-convertapi' ),
			__( 'Post to PDF', 'post-to-pdf-for-convertapi' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register the setting and its fields.
	 */
	public static function register() {
		register_setting(
			self::PAGE_SLUG,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => array(),
			)
		);

		add_settings_section( 'ptpdf_main', '', '__return_false', self::PAGE_SLUG );
		add_settings_section(
			'ptpdf_link',
			__( 'Download link', 'post-to-pdf-for-convertapi' ),
			'__return_false',
			self::PAGE_SLUG
		);
		add_settings_section(
			'ptpdf_header_footer',
			__( 'Header and footer', 'post-to-pdf-for-convertapi' ),
			array( __CLASS__, 'section_header_footer' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'api_secret',
			__( 'ConvertAPI secret', 'post-to-pdf-for-convertapi' ),
			array( __CLASS__, 'field_api_secret' ),
			self::PAGE_SLUG,
			'ptpdf_main',
			array( 'label_for' => 'ptpdf-api-secret' )
		);

		add_settings_field(
			'render_mode',
			__( 'Rendering method', 'post-to-pdf-for-convertapi' ),
			array( __CLASS__, 'field_render_mode' ),
			self::PAGE_SLUG,
			'ptpdf_main'
		);

		add_settings_field(
			'included_selectors',
			__( 'Include only these elements', 'post-to-pdf-for-convertapi' ),
			array( __CLASS__, 'field_included_selectors' ),
			self::PAGE_SLUG,
			'ptpdf_main',
			array( 'label_for' => 'ptpdf-included-selectors' )
		);

		add_settings_field(
			'custom_css',
			__( 'Custom CSS', 'post-to-pdf-for-convertapi' ),
			array( __CLASS__, 'field_custom_css' ),
			self::PAGE_SLUG,
			'ptpdf_main',
			array( 'label_for' => 'ptpdf-custom-css' )
		);

		add_settings_field(
			'link_text',
			__( 'Link text', 'post-to-pdf-for-convertapi' ),
			array( __CLASS__, 'field_link_text' ),
			self::PAGE_SLUG,
			'ptpdf_link',
			array( 'label_for' => 'ptpdf-link-text' )
		);

		add_settings_field(
			'link_css',
			__( 'Link CSS', 'post-to-pdf-for-convertapi' ),
			array( __CLASS__, 'field_link_css' ),
			self::PAGE_SLUG,
			'ptpdf_link',
			array( 'label_for' => 'ptpdf-link-css' )
		);

		foreach ( array( 'header', 'footer' ) as $part ) {
			add_settings_field(
				$part . '_html',
				'header' === $part ? __( 'Header', 'post-to-pdf-for-convertapi' ) : __( 'Footer', 'post-to-pdf-for-convertapi' ),
				array( __CLASS__, 'field_header_footer' ),
				self::PAGE_SLUG,
				'ptpdf_header_footer',
				array( 'part' => $part )
			);
		}
	}

	/**
	 * Sanitize submitted settings. options.php has already unslashed the input.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$current  = self::get();
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();

		// A blank secret field means "keep the existing one"; the checkbox clears it.
		$secret = isset( $input['api_secret'] ) ? trim( sanitize_text_field( $input['api_secret'] ) ) : '';
		if ( '' === $secret && empty( $input['clear_secret'] ) ) {
			$secret = $current['api_secret'];
		}

		$selectors = array();
		$rejected  = array();
		$raw       = isset( $input['included_selectors'] ) ? (string) $input['included_selectors'] : '';
		foreach ( preg_split( '/[\r\n]+/', $raw ) as $line ) {
			$line = trim( wp_strip_all_tags( $line ) );
			if ( '' === $line ) {
				continue;
			}
			if ( preg_match( '/^[A-Za-z0-9_\-#.\s>+~:()\[\]=^$*|"\',]+$/', $line ) ) {
				$selectors[] = $line;
			} else {
				$rejected[] = $line;
			}
		}

		// add_settings_error() only exists in wp-admin; the option can also be saved from WP-CLI or code.
		if ( $rejected && function_exists( 'add_settings_error' ) ) {
			add_settings_error(
				self::OPTION,
				'ptpdf_invalid_selectors',
				sprintf(
					/* translators: %s: comma-separated list of rejected selectors. */
					__( 'These selectors were ignored because they contain invalid characters: %s', 'post-to-pdf-for-convertapi' ),
					implode( ', ', $rejected )
				)
			);
		}

		$mode = isset( $input['render_mode'] ) ? sanitize_key( $input['render_mode'] ) : 'auto';

		$clean = array(
			'api_secret'         => $secret,
			'render_mode'        => in_array( $mode, array( 'auto', 'url', 'html' ), true ) ? $mode : 'auto',
			'included_selectors' => implode( "\n", array_unique( $selectors ) ),
			// Only ever sent to ConvertAPI, never printed on the site; strip anything that could close a <style> block.
			'custom_css'         => isset( $input['custom_css'] ) ? trim( preg_replace( '#</?style\b[^>]*>#i', '', str_replace( "\0", '', (string) $input['custom_css'] ) ) ) : '',
			'link_text'          => isset( $input['link_text'] ) ? trim( sanitize_text_field( $input['link_text'] ) ) : '',
			// Printed in a <style> tag on the live site, so no markup of any kind; same treatment as core's Additional CSS.
			'link_css'           => isset( $input['link_css'] ) ? trim( wp_strip_all_tags( (string) $input['link_css'] ) ) : '',
		);

		foreach ( array( 'header', 'footer' ) as $part ) {
			$html = isset( $input[ $part . '_html' ] ) ? (string) $input[ $part . '_html' ] : '';
			// wp_kses_post() removes <script>/<style> tags but keeps their text; drop the blocks entirely.
			$html                     = preg_replace( '#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $html );
			$clean[ $part . '_html' ] = trim( wp_kses_post( $html ) );

			$height                     = isset( $input[ $part . '_height' ] ) ? absint( $input[ $part . '_height' ] ) : $defaults[ $part . '_height' ];
			$clean[ $part . '_height' ] = min( 200, max( 5, $height ) );
		}

		return $clean;
	}

	/**
	 * Secret field. The stored value is never printed back into the page.
	 */
	public static function field_api_secret() {
		if ( defined( 'PTPDF_CONVERTAPI_SECRET' ) && PTPDF_CONVERTAPI_SECRET ) {
			echo '<p>' . esc_html__( 'The secret is set by the PTPDF_CONVERTAPI_SECRET constant in wp-config.php.', 'post-to-pdf-for-convertapi' ) . '</p>';
			return;
		}

		$has_secret = '' !== self::get()['api_secret'];
		printf(
			'<input type="password" id="ptpdf-api-secret" name="%1$s[api_secret]" value="" class="regular-text" autocomplete="new-password" placeholder="%2$s" />',
			esc_attr( self::OPTION ),
			esc_attr( $has_secret ? __( 'Saved. Leave blank to keep it.', 'post-to-pdf-for-convertapi' ) : '' )
		);
		if ( $has_secret ) {
			printf(
				'<p><label><input type="checkbox" name="%s[clear_secret]" value="1" /> %s</label></p>',
				esc_attr( self::OPTION ),
				esc_html__( 'Remove the saved secret', 'post-to-pdf-for-convertapi' )
			);
		}
		echo '<p class="description">' . wp_kses(
			sprintf(
				/* translators: %s: ConvertAPI dashboard URL. */
				__( 'Find your API secret in the <a href="%s" target="_blank" rel="noopener noreferrer">ConvertAPI dashboard</a>.', 'post-to-pdf-for-convertapi' ),
				'https://www.convertapi.com/a/authentication'
			),
			array(
				'a' => array(
					'href'   => array(),
					'target' => array(),
					'rel'    => array(),
				),
			)
		) . '</p>';
	}

	/**
	 * Rendering method radios.
	 */
	public static function field_render_mode() {
		$current = self::get()['render_mode'];
		$options = array(
			'auto' => sprintf(
				/* translators: %s: how the current site will be rendered. */
				__( 'Automatic (this site looks %s)', 'post-to-pdf-for-convertapi' ),
				PTPDF_Converter::site_is_public() ? __( 'public, so the page URL is sent', 'post-to-pdf-for-convertapi' ) : __( 'private, so the page HTML is uploaded', 'post-to-pdf-for-convertapi' )
			),
			'url'  => __( 'Send the page URL. ConvertAPI loads the page itself; the site must be publicly reachable.', 'post-to-pdf-for-convertapi' ),
			'html' => __( 'Upload the page HTML. WordPress loads the page and embeds its local styles, scripts, images and fonts. Works for local, intranet and password-protected staging sites.', 'post-to-pdf-for-convertapi' ),
		);

		echo '<fieldset>';
		foreach ( $options as $value => $label ) {
			printf(
				'<label><input type="radio" name="%1$s[render_mode]" value="%2$s" %3$s /> %4$s</label><br />',
				esc_attr( self::OPTION ),
				esc_attr( $value ),
				checked( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
	}

	/**
	 * Included selectors textarea.
	 */
	public static function field_included_selectors() {
		printf(
			'<textarea id="ptpdf-included-selectors" name="%1$s[included_selectors]" rows="6" class="large-text code" placeholder="%2$s">%3$s</textarea>',
			esc_attr( self::OPTION ),
			esc_attr( ".entry-title\n#main-content" ),
			esc_textarea( self::get()['included_selectors'] )
		);
		echo '<p class="description">' . esc_html__( 'One CSS selector per line, e.g. #main-content or .entry-title. Only matching elements and everything inside them appear in the PDF. Leave empty to include the whole page.', 'post-to-pdf-for-convertapi' ) . '</p>';
	}

	/**
	 * Custom CSS textarea (upgraded to a code editor when available).
	 */
	public static function field_custom_css() {
		printf(
			'<textarea id="ptpdf-custom-css" name="%1$s[custom_css]" rows="10" class="large-text code" placeholder="%2$s">%3$s</textarea>',
			esc_attr( self::OPTION ),
			esc_attr( "body { font-size: 12pt; }\n.wp-block-post-title { color: #9d2235; }" ),
			esc_textarea( self::get()['custom_css'] )
		);
		echo '<p class="description">' . esc_html__( 'Applied to the page only when it is converted to PDF; it does not affect the site or the header and footer.', 'post-to-pdf-for-convertapi' ) . '</p>';
	}

	/**
	 * Link text input.
	 */
	public static function field_link_text() {
		printf(
			'<input type="text" id="ptpdf-link-text" name="%1$s[link_text]" value="%2$s" class="regular-text" placeholder="%3$s" />',
			esc_attr( self::OPTION ),
			esc_attr( self::get()['link_text'] ),
			esc_attr__( 'Download as PDF', 'post-to-pdf-for-convertapi' )
		);
		echo '<p class="description">' . esc_html__( 'Leave empty to use "Download as PDF".', 'post-to-pdf-for-convertapi' ) . '</p>';
	}

	/**
	 * Link CSS textarea (upgraded to a code editor when available).
	 */
	public static function field_link_css() {
		printf(
			'<textarea id="ptpdf-link-css" name="%1$s[link_css]" rows="8" class="large-text code" placeholder="%2$s">%3$s</textarea>',
			esc_attr( self::OPTION ),
			esc_attr( ".ptpdf-download {\n\ttext-align: right;\n}\n.ptpdf-download a {\n\tfont-weight: bold;\n}" ),
			esc_textarea( self::get()['link_css'] )
		);
		echo '<p class="description">' . wp_kses(
			__( 'Styles the link on your site (not the PDF). The link is <code>.ptpdf-download a</code> inside the paragraph <code>.ptpdf-download</code>. Add <code>!important</code> if a theme style wins.', 'post-to-pdf-for-convertapi' ),
			array( 'code' => array() )
		) . '</p>';
	}

	/**
	 * Load WordPress's CodeMirror CSS editor on our settings page.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public static function enqueue_css_editor( $hook_suffix ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}
		// Returns false when the user has turned syntax highlighting off in their profile.
		$settings = wp_enqueue_code_editor( array( 'type' => 'text/css' ) );
		if ( false === $settings ) {
			return;
		}
		wp_add_inline_script(
			'code-editor',
			sprintf(
				'jQuery( function() { [ "ptpdf-custom-css", "ptpdf-link-css" ].forEach( function( id ) { wp.codeEditor.initialize( id, %s ); } ); } );',
				wp_json_encode( $settings )
			)
		);
	}

	/**
	 * Intro for the header/footer section.
	 */
	public static function section_header_footer() {
		echo '<p>' . esc_html__( 'Printed at the top and bottom of every PDF page. Leave empty for none. You can use these placeholders:', 'post-to-pdf-for-convertapi' ) . '</p>';
		echo '<p><code>{page_number}</code> <code>{total_pages}</code> <code>{title}</code> <code>{url}</code> <code>{date}</code> <code>{site_name}</code></p>';
		echo '<p class="description">' . esc_html__( 'Headers and footers do not use the site\'s theme styles. Use the editor\'s formatting, and images from this site\'s Media Library (they are embedded).', 'post-to-pdf-for-convertapi' ) . '</p>';
	}

	/**
	 * Header or footer WYSIWYG editor plus its reserved height.
	 *
	 * @param array $args Field args; 'part' is header or footer.
	 */
	public static function field_header_footer( $args ) {
		$part     = $args['part'];
		$settings = self::get();

		wp_editor(
			$settings[ $part . '_html' ],
			'ptpdf_' . $part . '_html',
			array(
				'textarea_name' => self::OPTION . '[' . $part . '_html]',
				'textarea_rows' => 5,
				'media_buttons' => true,
				'teeny'         => false,
			)
		);

		printf(
			'<p><label>%1$s <input type="number" name="%2$s[%3$s_height]" value="%4$d" min="5" max="200" step="1" class="small-text" /> mm</label></p>',
			'header' === $part ? esc_html__( 'Space reserved at the top of each page:', 'post-to-pdf-for-convertapi' ) : esc_html__( 'Space reserved at the bottom of each page:', 'post-to-pdf-for-convertapi' ),
			esc_attr( self::OPTION ),
			esc_attr( $part ),
			(int) $settings[ $part . '_height' ]
		);
	}

	/**
	 * Render the settings page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect.
		$cleared = isset( $_GET['ptpdf_cleared'] ) ? absint( $_GET['ptpdf_cleared'] ) : null;
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php if ( null !== $cleared ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					/* translators: %d: number of PDFs deleted. */
					echo esc_html( sprintf( _n( 'Deleted %d generated PDF.', 'Deleted %d generated PDFs.', $cleared, 'post-to-pdf-for-convertapi' ), $cleared ) );
					?>
				</p></div>
			<?php endif; ?>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::PAGE_SLUG );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Generated PDFs', 'post-to-pdf-for-convertapi' ); ?></h2>
			<p><?php esc_html_e( 'Each PDF is stored in the Media Library and served again until its post or these settings change; it is then regenerated on the next download. Deleting them frees the space now.', 'post-to-pdf-for-convertapi' ); ?></p>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::CLEAR_HOOK ); ?>" />
				<?php wp_nonce_field( self::CLEAR_HOOK ); ?>
				<?php submit_button( __( 'Delete all generated PDFs', 'post-to-pdf-for-convertapi' ), 'delete', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Delete every PDF this plugin generated.
	 */
	public static function handle_clear() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'post-to-pdf-for-convertapi' ), 403 );
		}
		check_admin_referer( self::CLEAR_HOOK );

		$count = PTPDF_Converter::delete_all_generated();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => self::PAGE_SLUG,
					'ptpdf_cleared' => $count,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * "Settings" link on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ),
				esc_html__( 'Settings', 'post-to-pdf-for-convertapi' )
			)
		);
		return $links;
	}
}
