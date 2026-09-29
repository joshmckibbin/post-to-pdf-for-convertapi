<?php
/**
 * Plugin Name:       Post to PDF for ConvertAPI
 * Description:       Adds a "Download as PDF" link to posts and pages. PDFs are rendered with ConvertAPI and cached in the Media Library.
 * Version:           1.0.3
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Josh McKibbin
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       post-to-pdf-for-convertapi
 *
 * @package PostToPdfForConvertAPI
 */

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

defined( 'ABSPATH' ) || exit;

define( 'PTPDF_VERSION', '1.0.3' );
define( 'PTPDF_FILE', __FILE__ );
define( 'PTPDF_DIR', plugin_dir_path( __FILE__ ) );
define( 'PTPDF_URL', plugin_dir_url( __FILE__ ) );

/** Post meta flag: when truthy, the download link is hidden for that post. */
define( 'PTPDF_META_HIDE_LINK', '_ptpdf_hide_link' );
/** Post meta on the source post: attachment ID of its generated PDF. */
define( 'PTPDF_META_ATTACHMENT', '_ptpdf_attachment_id' );
/** Attachment meta: ID of the post the PDF was generated from. */
define( 'PTPDF_META_SOURCE_POST', '_ptpdf_source_post' );
/** Attachment meta: post_modified_gmt of the source post at generation time. */
define( 'PTPDF_META_SOURCE_MODIFIED', '_ptpdf_source_modified' );
/** Attachment meta: fingerprint of the render settings at generation time. */
define( 'PTPDF_META_SETTINGS_HASH', '_ptpdf_settings_hash' );

require_once PTPDF_DIR . 'vendor/autoload.php';
require_once PTPDF_DIR . 'includes/class-ptpdf-settings.php';
require_once PTPDF_DIR . 'includes/class-ptpdf-snapshot.php';
require_once PTPDF_DIR . 'includes/class-ptpdf-converter.php';
require_once PTPDF_DIR . 'includes/class-ptpdf-editor.php';
require_once PTPDF_DIR . 'includes/class-ptpdf-frontend.php';

/**
 * Update checker for the plugin.
 */
$ptpdf_update_checker = PucFactory::buildUpdateChecker(
	'https://github.com/joshmckibbin/post-to-pdf-for-convertapi/',
	__FILE__,
	'post-to-pdf-for-convertapi'
);

// Set the branch for the update checker.
// $ptpdf_update_checker->setBranch( 'main' );

/**
 * Post types that get the download link.
 *
 * @return string[]
 */
function ptpdf_post_types() {
	/**
	 * Filters the post types that display the "Download as PDF" link.
	 *
	 * @param string[] $post_types Default: post and page.
	 */
	return (array) apply_filters( 'ptpdf_post_types', array( 'post', 'page' ) );
}

add_action(
	'plugins_loaded',
	static function () {
		PTPDF_Settings::init();
		PTPDF_Editor::init();
		PTPDF_Frontend::init();
	}
);
