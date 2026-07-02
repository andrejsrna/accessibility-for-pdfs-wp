<?php
/**
 * Plugin Name: SBA PDF Accessibility
 * Description: Kontrola a oprava prístupnosti PDF súborov (OCR, záložky, metadata, fonty, jazyk, alt texty).
 * Version: 1.0.0
 * Author: SBA Agency
 */

defined( 'ABSPATH' ) || exit;

define( 'SBA_PDF_A11Y_VERSION', '1.0.0' );
define( 'SBA_PDF_A11Y_DIR', plugin_dir_path( __FILE__ ) );
define( 'SBA_PDF_A11Y_URL', plugin_dir_url( __FILE__ ) );
define( 'SBA_PDF_A11Y_PYTHON_SCRIPT', SBA_PDF_A11Y_DIR . 'bin/process_pdf.py' );
define( 'SBA_PDF_A11Y_META_KEY', '_sba_pdf_a11y' );
define( 'SBA_PDF_A11Y_ALT_META_KEY', '_sba_pdf_image_alts' );

// Core includes. Keep helpers first: other modules depend on them.
require_once SBA_PDF_A11Y_DIR . 'includes/class-helpers.php';
require_once SBA_PDF_A11Y_DIR . 'includes/class-ajax.php';
require_once SBA_PDF_A11Y_DIR . 'includes/media-modal.php';
require_once SBA_PDF_A11Y_DIR . 'includes/admin-page.php';

// ─── Auto-processing on upload (WP-Cron) ──────────────────────────────────

add_action( 'add_attachment', function ( int $attachment_id ) {
	if ( get_post_mime_type( $attachment_id ) !== 'application/pdf' ) {
		return;
	}

	// Mark as pending and process asynchronously so the upload itself remains fast.
	sba_pdf_save_meta( $attachment_id, [ 'status' => 'pending', 'queued_at' => current_time( 'mysql' ) ] );
	wp_schedule_single_event( time() + 5, 'sba_pdf_process_async', [ $attachment_id ] );
} );

add_action( 'sba_pdf_process_async', function ( int $attachment_id ) {
	$attachment = get_post( $attachment_id );
	if ( ! $attachment ) {
		return;
	}

	$path = get_attached_file( $attachment_id );
	if ( ! $path || ! file_exists( $path ) ) {
		return;
	}

	$result = sba_pdf_run( 'process', $path, [
		'title'   => $attachment->post_title,
		'author'  => get_bloginfo( 'name' ),
		'subject' => wp_strip_all_tags( $attachment->post_content ?: $attachment->post_excerpt ),
		'lang'    => 'slk+eng',
	] );

	$status = ( $result && isset( $result['final'] ) ) ? $result['final'] : [];
	$status['processed_at'] = current_time( 'mysql' );
	$status['checked_at']   = current_time( 'mysql' );
	$status['auto']         = true;
	sba_pdf_save_status_meta( $attachment_id, $status );
} );

// ─── Admin menu + assets ──────────────────────────────────────────────────

add_action( 'admin_menu', function () {
	add_media_page(
		'PDF Prístupnosť',
		'PDF Prístupnosť',
		'upload_files',
		'sba-pdf-accessibility',
		'sba_pdf_render_page'
	);
} );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	wp_enqueue_script( 'jquery' );

	// Media modal + media edit screen — inline script for the "Opraviť teraz" button.
	wp_add_inline_script( 'jquery-core', sba_pdf_media_modal_js() );

	// Dedicated plugin admin page.
	if ( $hook === 'media_page_sba-pdf-accessibility' ) {
		wp_enqueue_style(
			'sba-pdf-a11y-admin',
			SBA_PDF_A11Y_URL . 'assets/admin.css',
			[],
			SBA_PDF_A11Y_VERSION
		);
		wp_enqueue_script(
			'sba-pdf-a11y-admin',
			SBA_PDF_A11Y_URL . 'assets/admin.js',
			[ 'jquery' ],
			SBA_PDF_A11Y_VERSION,
			true
		);
		wp_localize_script( 'sba-pdf-a11y-admin', 'sbaPdfA11y', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'sba_pdf_a11y' ),
		] );
	}
} );
