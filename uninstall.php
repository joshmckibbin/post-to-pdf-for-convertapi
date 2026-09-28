<?php
/**
 * Uninstall cleanup. Generated PDFs are left in the Media Library;
 * use "Delete all generated PDFs" on the settings page before uninstalling to remove them.
 *
 * @package PostToPdfForConvertAPI
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'ptpdf_settings' );
delete_post_meta_by_key( '_ptpdf_hide_link' );
delete_post_meta_by_key( '_ptpdf_attachment_id' );
