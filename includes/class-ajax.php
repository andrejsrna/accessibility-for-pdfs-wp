<?php
/**
 * AJAX handlers: check, process, images, alt-text, meta-title, autotag, localtag.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'wp_ajax_sba_pdf_check',            'sba_pdf_ajax_check' );
add_action( 'wp_ajax_sba_pdf_process',          'sba_pdf_ajax_process' );
add_action( 'wp_ajax_sba_pdf_save_alts',        'sba_pdf_ajax_save_alts' );
add_action( 'wp_ajax_sba_pdf_save_meta_title',  'sba_pdf_ajax_save_meta_title' );
add_action( 'wp_ajax_sba_pdf_images',           'sba_pdf_ajax_images' );
add_action( 'wp_ajax_sba_pdf_autotag',          'sba_pdf_ajax_autotag' );
add_action( 'wp_ajax_sba_pdf_localtag',         'sba_pdf_ajax_localtag' );

// ─── Check ─────────────────────────────────────────────────────────────────

function sba_pdf_ajax_check(): void {
	check_ajax_referer( 'sba_pdf_a11y', 'nonce' );
	if ( ! current_user_can( 'upload_files' ) ) {
		wp_die( '', 403 );
	}

	$id   = intval( $_POST['id'] ?? 0 );
	$path = $id ? get_attached_file( $id ) : '';

	if ( ! $path || ! file_exists( $path ) ) {
		wp_send_json_error( 'Súbor nenájdený.' );
	}

	$result = sba_pdf_run( 'check', $path );
	if ( null === $result ) {
		wp_send_json_error( 'Python skript zlyhal alebo nie je nainštalovaný.' );
	}
	if ( isset( $result['error'] ) || isset( $result['raw'] ) ) {
		$detail = sba_pdf_safe_error_detail( (string) ( $result['error'] ?? $result['raw'] ?? '' ) );
		wp_send_json_error( $detail !== '' ? 'Kontrola zlyhala: ' . $detail : 'Kontrola zlyhala.' );
	}

	$result['checked_at'] = current_time( 'mysql' );
	$result = sba_pdf_save_status_meta( $id, $result );
	wp_send_json_success( $result );
}

// ─── Process (OCR, metadata, fonts, localtag) ─────────────────────────────

function sba_pdf_ajax_process(): void {
	check_ajax_referer( 'sba_pdf_a11y', 'nonce' );
	if ( ! current_user_can( 'upload_files' ) ) {
		wp_die( '', 403 );
	}

	$id         = intval( $_POST['id'] ?? 0 );
	$attachment = $id ? get_post( $id ) : null;
	$path       = $attachment ? get_attached_file( $id ) : '';

	if ( ! $path || ! file_exists( $path ) ) {
		wp_send_json_error( 'Súbor nenájdený.' );
	}

	$existing = sba_pdf_get_meta( $id );
	$title    = trim( (string) ( $existing['meta_title'] ?? '' ) );
	if ( $title === '' ) {
		$title = $attachment->post_title;
	}

	$opts = [
		'title'   => $title,
		'author'  => get_bloginfo( 'name' ),
		'subject' => wp_strip_all_tags( $attachment->post_content ?: $attachment->post_excerpt ),
		'lang'    => sanitize_text_field( $_POST['lang'] ?? 'slk+eng' ),
	];

	$result = sba_pdf_run( 'process', $path, $opts );
	if ( null === $result ) {
		wp_send_json_error( 'Python skript zlyhal alebo nie je nainštalovaný.' );
	}

	// Save fresh status from "final" key produced by process action.
	$status = $result['final'] ?? [];
	if ( empty( $status['tagged_pdf'] ) ) {
		$localtag = sba_pdf_run( 'localtag', $path, [
			'title' => $title,
			'lang'  => $opts['lang'],
		] );
		if ( is_array( $localtag ) && ! empty( $localtag['localtagged'] ) ) {
			$status = $localtag;
			$result['localtag'] = [
				'status'  => 'ok',
				'message' => $localtag['message'] ?? 'PDF bolo lokálne tagované; výsledok treba skontrolovať.',
			];
		} elseif ( is_array( $localtag ) ) {
			$result['localtag'] = [
				'status' => 'skipped_or_failed',
				'reason' => $localtag['reason'] ?? 'unknown',
				'error'  => sba_pdf_safe_error_detail( (string) ( $localtag['error'] ?? $localtag['raw'] ?? '' ) ),
			];
		}
	}
	$status['processed_at'] = current_time( 'mysql' );
	$status['checked_at']   = current_time( 'mysql' );
	$status = sba_pdf_save_status_meta( $id, $status );

	$result['status'] = $status;
	wp_send_json_success( $result );
}

// ─── Save meta title ──────────────────────────────────────────────────────

function sba_pdf_ajax_save_meta_title(): void {
	check_ajax_referer( 'sba_pdf_a11y', 'nonce' );
	if ( ! current_user_can( 'upload_files' ) ) {
		wp_die( '', 403 );
	}

	$id    = intval( $_POST['id'] ?? 0 );
	$title = sanitize_text_field( $_POST['title'] ?? '' );

	if ( ! $id ) {
		wp_send_json_error( 'Neplatné ID.' );
	}

	$path = get_attached_file( $id );
	if ( ! $path || ! file_exists( $path ) ) {
		wp_send_json_error( 'Súbor nenájdený.' );
	}

	// Write new title into PDF metadata via Python script
	sba_pdf_run( 'process', $path, [
		'title'  => $title,
		'author' => get_bloginfo( 'name' ),
		'lang'   => 'slk+eng',
	] );

	// Update stored meta state
	$meta = sba_pdf_get_meta( $id );
	$meta['meta_title']   = $title;
	$meta['processed_at'] = current_time( 'mysql' );
	sba_pdf_save_meta( $id, $meta );

	wp_send_json_success( [ 'title' => $title ] );
}

// ─── Save alt texts ───────────────────────────────────────────────────────

function sba_pdf_ajax_save_alts(): void {
	check_ajax_referer( 'sba_pdf_a11y', 'nonce' );
	if ( ! current_user_can( 'upload_files' ) ) {
		wp_die( '', 403 );
	}

	$id          = intval( $_POST['id'] ?? 0 );
	$alts        = $_POST['alts'] ?? [];
	$struct_xrefs = $_POST['struct_xrefs'] ?? [];

	if ( ! $id || ! is_array( $alts ) ) {
		wp_send_json_error( 'Neplatné dáta.' );
	}

	// Always save to WP postmeta first
	$sanitized = array_map( 'sanitize_text_field', $alts );
	update_post_meta( $id, SBA_PDF_A11Y_ALT_META_KEY, $sanitized );

	// Attempt direct embedding into PDF structure tree
	$embed_status = 'wp_only';
	$embed_count  = 0;
	$path         = get_attached_file( $id );

	if ( $path && file_exists( $path ) && is_array( $struct_xrefs ) ) {
		$alts_for_pdf = [];
		foreach ( $sanitized as $idx => $alt ) {
			$xref = isset( $struct_xrefs[ $idx ] ) ? intval( $struct_xrefs[ $idx ] ) : 0;
			if ( $xref > 0 && $alt !== '' ) {
				$alts_for_pdf[] = [ 'struct_xref' => $xref, 'alt' => $alt ];
			}
		}
		if ( ! empty( $alts_for_pdf ) ) {
			$result = sba_pdf_run( 'write-alts', $path, [ 'alts_json' => wp_json_encode( $alts_for_pdf ) ] );
			if ( $result && ! empty( $result['embedded'] ) ) {
				$embed_status = 'embedded';
				$embed_count  = (int) ( $result['count'] ?? 0 );
			} elseif ( $result && isset( $result['reason'] ) ) {
				$embed_status = $result['reason'] === 'untagged' ? 'untagged' : 'wp_only';
			}
		}
	}

	// Persist embed status in PDF a11y meta
	$meta                    = sba_pdf_get_meta( $id );
	$meta['alt_embed_status'] = $embed_status;
	$meta['alt_embed_count']  = $embed_count;
	sba_pdf_save_meta( $id, $meta );

	wp_send_json_success( [
		'saved'        => count( $sanitized ),
		'embed_status' => $embed_status,
		'embed_count'  => $embed_count,
	] );
}

// ─── Images (extract thumbnails + alt info) ───────────────────────────────

function sba_pdf_ajax_images(): void {
	check_ajax_referer( 'sba_pdf_a11y', 'nonce' );
	if ( ! current_user_can( 'upload_files' ) ) {
		wp_die( '', 403 );
	}

	$id   = intval( $_POST['id'] ?? 0 );
	$path = $id ? get_attached_file( $id ) : '';

	if ( ! $path || ! file_exists( $path ) ) {
		wp_send_json_error( 'Súbor nenájdený.' );
	}

	$result = sba_pdf_run( 'images', $path );
	if ( null === $result ) {
		wp_send_json_error( 'Python skript zlyhal.' );
	}
	if ( isset( $result['error'] ) ) {
		wp_send_json_error( $result['error'] );
	}

	// Defensive UI hint: if a previous successful local tag confirmed tagged_pdf,
	// don't show a misleading "untagged" warning from transient checker state.
	$meta = sba_pdf_get_meta( $id );
	if ( ! empty( $meta['tagged_pdf'] ) || ! empty( $meta['localtag_pdfinfo'] ) || ! empty( $meta['autotagged_at'] ) ) {
		$result['tagged_pdf'] = true;
	}

	wp_send_json_success( $result );
}

// ─── Auto-tag (Adobe) ─────────────────────────────────────────────────────

function sba_pdf_ajax_autotag(): void {
	check_ajax_referer( 'sba_pdf_a11y', 'nonce' );
	if ( ! current_user_can( 'upload_files' ) ) {
		wp_die( '', 403 );
	}

	$id   = intval( $_POST['id'] ?? 0 );
	$path = $id ? get_attached_file( $id ) : '';

	if ( ! $path || ! file_exists( $path ) ) {
		wp_send_json_error( 'Súbor nenájdený.' );
	}
	if ( get_post_mime_type( $id ) !== 'application/pdf' ) {
		wp_send_json_error( 'Súbor nie je PDF.' );
	}

	$result = sba_pdf_run( 'autotag', $path );
	if ( null === $result ) {
		wp_send_json_error( 'Python skript zlyhal alebo nie je nainštalovaný.' );
	}

	if ( ! empty( $result['autotagged'] ) ) {
		$meta                   = sba_pdf_get_meta( $id );
		$meta['tagged_pdf']     = true;
		$meta['autotagged_at']  = current_time( 'mysql' );
		$meta['autotag_status'] = 'ok';
		$meta['checked_at']     = current_time( 'mysql' );
		sba_pdf_save_meta( $id, $meta );
		wp_send_json_success( [
			'autotagged'   => true,
			'tagged_pdf'   => true,
			'autotagged_at' => $meta['autotagged_at'],
			'message'      => 'PDF tagované cez Adobe Auto-Tag; vyžaduje kontrolu/validáciu.',
		] );
	}

	$reason_messages = [
		'missing_credentials' => 'Chýbajú Adobe API prihlasovacie údaje (ADOBE_PDF_SERVICES_CLIENT_ID / SECRET).',
		'invalid_credentials' => 'Neplatné Adobe API prihlasovacie údaje.',
		'sdk_missing'         => 'Adobe pdfservices-sdk nie je nainštalovaný. Spustite: pip3 install pdfservices-sdk',
		'quota_exceeded'      => 'Adobe API: prekročená kvóta alebo limit požiadaviek.',
		'timeout'             => 'Adobe API: vypršal časový limit.',
		'no_struct_tree'      => 'Adobe API vrátilo PDF bez štruktúry tagov (StructTreeRoot).',
		'api_error'           => 'Chyba Adobe Auto-Tag API.',
	];
	$reason  = $result['reason'] ?? 'api_error';
	$message = $reason_messages[ $reason ] ?? $reason_messages['api_error'];
	$detail  = sba_pdf_safe_error_detail( (string) ( $result['error'] ?? $result['raw'] ?? '' ) );
	if ( $detail !== '' ) {
		error_log( sprintf( 'SBA PDF Adobe Auto-Tag failed for attachment %d (%s): %s', $id, $reason, $detail ) );
		$message .= ' Detail: ' . $detail;
	}
	wp_send_json_error( $message );
}

// ─── Local tag (OpenDataLoader) ───────────────────────────────────────────

function sba_pdf_ajax_localtag(): void {
	check_ajax_referer( 'sba_pdf_a11y', 'nonce' );
	if ( ! current_user_can( 'upload_files' ) ) {
		wp_die( '', 403 );
	}

	$id         = intval( $_POST['id'] ?? 0 );
	$attachment = $id ? get_post( $id ) : null;
	$path       = $attachment ? get_attached_file( $id ) : '';
	$lang       = sanitize_text_field( $_POST['lang'] ?? 'slk+eng' );

	if ( ! $path || ! file_exists( $path ) ) {
		wp_send_json_error( 'Súbor nenájdený.' );
	}
	if ( get_post_mime_type( $id ) !== 'application/pdf' ) {
		wp_send_json_error( 'Súbor nie je PDF.' );
	}

	$result = sba_pdf_run( 'localtag', $path, [
		'title' => $attachment ? $attachment->post_title : '',
		'lang'  => $lang,
	] );
	if ( null === $result ) {
		wp_send_json_error( 'Python skript zlyhal alebo nie je nainštalovaný.' );
	}

	if ( ! empty( $result['localtagged'] ) ) {
		$result['checked_at']         = current_time( 'mysql' );
		$result['localtagged_at']     = current_time( 'mysql' );
		$result['localtag_status']    = 'ok';
		$result['localtag_pdfinfo']   = $result['pdfinfo_tagged'] ?? null;
		$result['localtag_validator'] = 'Vyžaduje kontrolu/validáciu; nejde o potvrdenie PDF/UA.';
		$status = sba_pdf_save_status_meta( $id, $result );
		wp_send_json_success( [
			'status'  => $status,
			'message' => $result['message'] ?? 'PDF lokálne tagované cez OpenDataLoader; vyžaduje kontrolu/validáciu.',
		] );
	}

	$reason_messages = [
		'opendataloader_missing' => 'OpenDataLoader nie je nainštalovaný v kontajneri.',
		'opendataloader_failed'  => 'OpenDataLoader tagovanie zlyhalo.',
		'validation_failed'      => 'Lokálne tagovanie prebehlo, ale výstup neprešiel základnou kontrolou.',
		'invalid_pdf'            => 'PDF sa nepodarilo otvoriť.',
		'timeout'                => 'OpenDataLoader prekročil časový limit.',
		'localtag_error'         => 'Chyba lokálneho tagovania.',
	];
	$reason  = $result['reason'] ?? 'localtag_error';
	$message = $reason_messages[ $reason ] ?? $reason_messages['localtag_error'];
	$detail  = sba_pdf_safe_error_detail( (string) ( $result['error'] ?? $result['raw'] ?? '' ) );
	if ( $detail !== '' ) {
		error_log( sprintf( 'SBA PDF local tag failed for attachment %d (%s): %s', $id, $reason, $detail ) );
		$message .= ' Detail: ' . $detail;
	}
	wp_send_json_error( $message );
}
