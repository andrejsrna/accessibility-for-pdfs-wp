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
define( 'SBA_PDF_A11Y_PYTHON_SCRIPT', SBA_PDF_A11Y_DIR . 'bin/process_pdf.py' );
define( 'SBA_PDF_A11Y_META_KEY', '_sba_pdf_a11y' );
define( 'SBA_PDF_A11Y_ALT_META_KEY', '_sba_pdf_image_alts' );

// --- Auto-spracovanie pri uploade (WP Cron) --------------------------------

add_action( 'add_attachment', function ( int $attachment_id ) {
	if ( get_post_mime_type( $attachment_id ) !== 'application/pdf' ) {
		return;
	}
	// Označ ako "čaká na spracovanie"
	sba_pdf_save_meta( $attachment_id, [ 'status' => 'pending', 'queued_at' => current_time( 'mysql' ) ] );
	// Naplánuj asynchrónne spracovanie
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

// --- Admin menu -----------------------------------------------------------

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
	// Plugin admin page
	if ( $hook === 'media_page_sba-pdf-accessibility' ) {
		wp_enqueue_script( 'jquery' );
	}
	// Media modal + media edit screen – inline script pre tlačidlo "Opraviť"
	wp_enqueue_script( 'jquery' );
	wp_add_inline_script( 'jquery-core', sba_pdf_media_modal_js() );
} );

// --- Media modal / attachment detail --------------------------------------

add_filter( 'attachment_fields_to_edit', function ( array $fields, WP_Post $post ): array {
	if ( $post->post_mime_type !== 'application/pdf' ) {
		return $fields;
	}
	$meta  = sba_pdf_get_meta( $post->ID );
	$nonce = wp_create_nonce( 'sba_pdf_a11y' );

	$fields['sba_pdf_a11y'] = [
		'label' => 'PDF Prístupnosť',
		'input' => 'html',
		'html'  => sba_pdf_attachment_field_html( $post->ID, $meta, $nonce ),
	];
	return $fields;
}, 10, 2 );

function sba_pdf_attachment_field_html( int $id, array $meta, string $nonce ): string {
	$badges = sba_pdf_attachment_badges_html( $meta );
	$last   = ! empty( $meta['checked_at'] )
		? '<div style="font-size:10px;color:#888;margin-top:4px;">Posledná kontrola: ' . esc_html( $meta['checked_at'] ) . '</div>'
		: '';

	return sprintf(
		'<div class="sba-att-wrap" data-id="%d" data-nonce="%s" style="font-size:12px;">
			<div class="sba-att-badges">%s</div>
			%s
			<button type="button" class="button button-small sba-att-process-btn" style="margin-top:8px;">
				Opraviť teraz
			</button>
			<span class="sba-att-result" style="margin-left:8px;font-size:11px;"></span>
		</div>',
		$id,
		esc_attr( $nonce ),
		$badges,
		$last
	);
}

function sba_pdf_attachment_badges_html( array $meta ): string {
	if ( empty( $meta ) ) {
		return '<span style="color:#888;">Ešte nebolo skontrolované</span>';
	}
	if ( ( $meta['status'] ?? '' ) === 'pending' ) {
		return '<span style="color:#055160;">⏳ Čaká na spracovanie na pozadí…</span>';
	}
	$rows = [
		'Text/OCR'   => sba_pdf_badge( $meta, 'has_text' ),
		'Záložky'    => sba_pdf_badge( $meta, 'bookmarks_count' ),
		'Meta titul' => sba_pdf_badge( $meta, 'meta_title' ),
		'Jazyk'      => sba_pdf_badge( $meta, 'meta_lang' ),
		'Fonty'      => sba_pdf_badge( $meta, 'fonts_embedded' ),
	];
	$html = '<table style="border-collapse:collapse;width:100%;">';
	foreach ( $rows as $label => $badge ) {
		$html .= "<tr>
			<td style='padding:2px 6px 2px 0;color:#555;white-space:nowrap;'>{$label}</td>
			<td style='padding:2px 0;'>{$badge}</td>
		</tr>";
	}
	return $html . '</table>';
}

function sba_pdf_media_modal_js(): string {
	return <<<'JS'
(function($){
	$(document).on('click', '.sba-att-process-btn', function(){
		var $wrap = $(this).closest('.sba-att-wrap');
		var id    = $wrap.data('id');
		var nonce = $wrap.data('nonce');
		var $btn  = $(this).prop('disabled', true).text('…');
		var $res  = $wrap.find('.sba-att-result').text('Spracováva sa…');

		$.post(window.ajaxurl || '/wp-admin/admin-ajax.php', {
			action: 'sba_pdf_process',
			nonce:  nonce,
			id:     id
		}).done(function(r){
			if(r.success && r.data && r.data.status){
				var s = r.data.status;
				function b(v){ return v ? '✓' : '✗'; }
				var rows =
					'<table style="border-collapse:collapse;width:100%;font-size:12px;">' +
					'<tr><td style="padding:2px 6px 2px 0;color:#555;">Text/OCR</td><td>' + b(s.has_text) + '</td></tr>' +
					'<tr><td style="padding:2px 6px 2px 0;color:#555;">Záložky</td><td>' + (s.bookmarks_count||0) + '</td></tr>' +
					'<tr><td style="padding:2px 6px 2px 0;color:#555;">Meta titul</td><td>' + (s.meta_title||'—') + '</td></tr>' +
					'<tr><td style="padding:2px 6px 2px 0;color:#555;">Jazyk</td><td>' + (s.meta_lang||'—') + '</td></tr>' +
					'<tr><td style="padding:2px 6px 2px 0;color:#555;">Fonty</td><td>' + b(s.fonts_embedded) + '</td></tr>' +
					'</table>';
				$wrap.find('.sba-att-badges').html(rows);
				$res.text('✓ Hotovo');
			} else {
				$res.text('✗ Chyba');
			}
		}).fail(function(){
			$res.text('✗ Požiadavka zlyhala');
		}).always(function(){
			$btn.prop('disabled', false).text('Opraviť teraz');
		});
	});
})(jQuery);
JS;
}

// --- AJAX handlers --------------------------------------------------------

add_action( 'wp_ajax_sba_pdf_check',            'sba_pdf_ajax_check' );
add_action( 'wp_ajax_sba_pdf_process',          'sba_pdf_ajax_process' );
add_action( 'wp_ajax_sba_pdf_save_alts',        'sba_pdf_ajax_save_alts' );
add_action( 'wp_ajax_sba_pdf_save_meta_title',  'sba_pdf_ajax_save_meta_title' );
add_action( 'wp_ajax_sba_pdf_images',           'sba_pdf_ajax_images' );
add_action( 'wp_ajax_sba_pdf_autotag',          'sba_pdf_ajax_autotag' );
add_action( 'wp_ajax_sba_pdf_localtag',         'sba_pdf_ajax_localtag' );

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

	// Zapis nový titul do PDF metadát cez Python skript
	$result = sba_pdf_run( 'process', $path, [
		'title'  => $title,
		'author' => get_bloginfo( 'name' ),
		'lang'   => 'slk+eng',
	] );

	// Aktualizuj uložený meta stav
	$meta = sba_pdf_get_meta( $id );
	$meta['meta_title']   = $title;
	$meta['processed_at'] = current_time( 'mysql' );
	sba_pdf_save_meta( $id, $meta );

	wp_send_json_success( [ 'title' => $title ] );
}

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

	// Defensive UI hint: the image endpoint detects StructTreeRoot from the file,
	// but if a previous successful local tag already confirmed Tagged: yes, don't
	// show the editor a misleading "untagged" warning because of transient checker
	// state or stale metadata from an earlier failed check.
	$meta = sba_pdf_get_meta( $id );
	if ( ! empty( $meta['tagged_pdf'] ) || ! empty( $meta['localtag_pdfinfo'] ) || ! empty( $meta['autotagged_at'] ) ) {
		$result['tagged_pdf'] = true;
	}

	wp_send_json_success( $result );
}

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

	// Map reason codes to Slovak messages. Include a short sanitized detail for admins
	// so real Adobe/file-specific failures can be diagnosed without exposing secrets.
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

// --- Helpers --------------------------------------------------------------

function sba_pdf_get_all( int $paged = 1, int $per_page = 100 ): array {
	return get_posts( [
		'post_type'      => 'attachment',
		'post_mime_type' => 'application/pdf',
		'post_status'    => 'inherit',
		'posts_per_page' => $per_page,
		'paged'          => $paged,
		'orderby'        => 'date',
		'order'          => 'DESC',
	] );
}

function sba_pdf_count_all(): int {
	global $wpdb;
	return (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts}
		 WHERE post_type = 'attachment'
		   AND post_mime_type = 'application/pdf'
		   AND post_status = 'inherit'"
	);
}

function sba_pdf_run( string $action, string $path, array $opts = [] ): ?array {
	if ( ! function_exists( 'shell_exec' ) ) {
		return [ 'error' => 'shell_exec je zakázaný v php.ini' ];
	}

	$python_bin = '/usr/bin/python3';
	$python = escapeshellcmd( $python_bin );
	$script = escapeshellarg( SBA_PDF_A11Y_PYTHON_SCRIPT );
	$file   = escapeshellarg( $path );
	$cmd    = "$python $script " . escapeshellarg( $action ) . " --input $file";

	if ( ! empty( $opts['title'] ) ) {
		$cmd .= ' --title ' . escapeshellarg( $opts['title'] );
	}
	if ( ! empty( $opts['author'] ) ) {
		$cmd .= ' --author ' . escapeshellarg( $opts['author'] );
	}
	if ( ! empty( $opts['subject'] ) ) {
		$cmd .= ' --subject ' . escapeshellarg( $opts['subject'] );
	}
	if ( ! empty( $opts['lang'] ) ) {
		$cmd .= ' --lang ' . escapeshellarg( $opts['lang'] );
	}
	if ( ! empty( $opts['shift_headings'] ) ) {
		$cmd .= ' --shift-headings';
	}

	// alts_json: write to temp file to avoid shell argument length limits
	$tmp_json = null;
	if ( ! empty( $opts['alts_json'] ) ) {
		$tmp_json = tempnam( sys_get_temp_dir(), 'sba_alts_' ) . '.json';
		file_put_contents( $tmp_json, $opts['alts_json'] );
		$cmd .= ' --alts-file ' . escapeshellarg( $tmp_json );
	}

	$cmd   .= ' 2>&1';
	$output = shell_exec( $cmd );

	if ( $tmp_json && file_exists( $tmp_json ) ) {
		@unlink( $tmp_json );
	}

	if ( $output === null || trim( $output ) === '' ) {
		return null;
	}

	$trimmed = trim( $output );
	$decoded = json_decode( $trimmed, true );
	if ( is_array( $decoded ) ) {
		return $decoded;
	}

	// Some SDKs/libraries can emit warnings/log lines before JSON. Try to recover
	// the last JSON object so the UI still receives the real reason code.
	if ( preg_match( '/(\{.*\})\s*$/s', $trimmed, $m ) ) {
		$decoded = json_decode( $m[1], true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}
	}

	return [ 'raw' => $output ];
}

function sba_pdf_save_meta( int $id, array $data ): void {
	update_post_meta( $id, SBA_PDF_A11Y_META_KEY, $data );
}

function sba_pdf_safe_error_detail( string $error ): string {
	$error = trim( wp_strip_all_tags( $error ) );
	if ( $error === '' ) {
		return '';
	}

	// Remove obvious long tokens/secret-like fragments before returning to the admin UI.
	$error = preg_replace( '/\b[A-Za-z0-9_\-]{24,}\b/', '[redacted]', $error );
	$error = preg_replace( '/(client[_-]?secret|access[_-]?token|authorization)\s*[:=]\s*\S+/i', '$1=[redacted]', $error );
	$error = preg_replace( '/\s+/', ' ', $error );

	return mb_substr( $error, 0, 220 );
}

function sba_pdf_save_status_meta( int $id, array $status ): array {
	$existing = sba_pdf_get_meta( $id );

	// Preserve values entered manually through the AJAX UI. The Python "check" and
	// "process" actions return a fresh technical status only; without this merge,
	// clicking "Opraviť" would overwrite meta-title/alt/embed/autotag state in WP.
	$preserve_keys = [
		'alt_embed_status',
		'alt_embed_count',
		'autotagged_at',
		'autotag_status',
		'localtagged_at',
		'localtag_status',
		'localtag_pdfinfo',
		'localtag_validator',
	];
	foreach ( $preserve_keys as $key ) {
		if ( array_key_exists( $key, $existing ) && ! array_key_exists( $key, $status ) ) {
			$status[ $key ] = $existing[ $key ];
		}
	}

	if ( ! empty( $existing['meta_title'] ) && empty( $status['meta_title'] ) ) {
		$status['meta_title'] = $existing['meta_title'];
	}

	sba_pdf_save_meta( $id, $status );
	return $status;
}

function sba_pdf_get_meta( int $id ): array {
	return (array) ( get_post_meta( $id, SBA_PDF_A11Y_META_KEY, true ) ?: [] );
}

function sba_pdf_badge( array $status, string $key ): string {
	if ( empty( $status ) ) {
		return '<span class="sba-badge sba-badge-na">—</span>';
	}
	// Súbor čaká na asynchrónne spracovanie
	if ( ( $status['status'] ?? '' ) === 'pending' ) {
		return '<span class="sba-badge sba-badge-pending" title="Čaká na spracovanie na pozadí">⏳</span>';
	}
	$val = $status[ $key ] ?? null;

	if ( $key === 'meta_lang' ) {
		return $val
			? '<span class="sba-badge sba-badge-ok" title="' . esc_attr( $val ) . '">✓ ' . esc_html( $val ) . '</span>'
			: '<span class="sba-badge sba-badge-err">✗</span>';
	}

	if ( $key === 'meta_title' ) {
		if ( ! $val ) {
			return '<span class="sba-badge sba-badge-err">✗</span>';
		}
		$short = mb_strlen( $val ) > 28 ? mb_substr( $val, 0, 26 ) . '…' : $val;
		return '<span class="sba-badge sba-badge-ok sba-meta-title-badge" title="' . esc_attr( $val ) . '">✓ ' . esc_html( $short ) . '</span>';
	}

	if ( $key === 'images_without_alt' ) {
		return (int) $val === 0
			? '<span class="sba-badge sba-badge-ok">✓</span>'
			: '<span class="sba-badge sba-badge-warn">' . (int) $val . ' chýba</span>';
	}

	if ( $key === 'bookmarks_count' ) {
		return (int) $val > 0
			? '<span class="sba-badge sba-badge-ok">' . (int) $val . '</span>'
			: '<span class="sba-badge sba-badge-err">0</span>';
	}

	if ( $val === true ) {
		return '<span class="sba-badge sba-badge-ok">✓</span>';
	}
	if ( $val === false ) {
		return '<span class="sba-badge sba-badge-err">✗</span>';
	}

	return '<span class="sba-badge sba-badge-na">—</span>';
}

function sba_pdf_alts_embed_badge( array $meta ): string {
	$status = $meta['alt_embed_status'] ?? null;
	$map = [
		'embedded' => [ 'sba-badge-ok',   'v PDF',  'Alt texty zapísané priamo do štruktúry PDF' ],
		'untagged' => [ 'sba-badge-warn',  'len WP', 'PDF nemá StructTreeRoot – alt texty len v databáze' ],
		'wp_only'  => [ 'sba-badge-warn',  'len WP', 'Alt texty uložené len v databáze WordPress' ],
	];
	if ( ! $status || ! isset( $map[ $status ] ) ) {
		return '';
	}
	[ $cls, $label, $title ] = $map[ $status ];
	return '<span class="sba-badge ' . $cls . '" style="font-size:9px;padding:1px 4px;margin-top:2px;display:inline-block;" title="' . esc_attr( $title ) . '">' . esc_html( $label ) . '</span>';
}

function sba_pdf_tagged_badge( array $meta ): string {
	if ( empty( $meta ) ) {
		return '<span class="sba-badge sba-badge-na">—</span>';
	}
	if ( ( $meta['status'] ?? '' ) === 'pending' ) {
		return '<span class="sba-badge sba-badge-pending">⏳</span>';
	}
	$tagged     = ! empty( $meta['tagged_pdf'] );
	$autotagged = ! empty( $meta['autotagged_at'] );
	$localtagged = ! empty( $meta['localtagged_at'] );
	if ( $tagged && $autotagged ) {
		return '<span class="sba-badge sba-badge-ok" title="Tagované cez Adobe Auto-Tag API ' . esc_attr( $meta['autotagged_at'] ) . '">Adobe</span>';
	}
	if ( $tagged && $localtagged ) {
		return '<span class="sba-badge sba-badge-warn" title="Lokálne tagované cez OpenDataLoader ' . esc_attr( $meta['localtagged_at'] ) . '; vyžaduje kontrolu/validáciu">Local</span>';
	}
	if ( $tagged ) {
		return '<span class="sba-badge sba-badge-ok" title="PDF má StructTreeRoot">✓</span>';
	}
	return '<span class="sba-badge sba-badge-err" title="PDF nemá StructTreeRoot">✗</span>';
}

// --- Status computation ---------------------------------------------------

/**
 * Compute a simplified traffic-light status from the stored metadata.
 * Returns level (red/yellow/green), a label, and the recommended action.
 */
function sba_pdf_compute_status( array $meta ): array {
	if ( empty( $meta ) || empty( $meta['checked_at'] ?? '' ) ) {
		return [
			'level'  => 'red',
			'label'  => 'Vyžaduje spracovanie',
		];
	}

	if ( ( $meta['status'] ?? '' ) === 'pending' ) {
		return [
			'level'  => 'red',
			'label'  => 'Čaká na spracovanie…',
		];
	}

	$has_text   = ! empty( $meta['has_text'] );
	$has_fonts  = ! empty( $meta['fonts_embedded'] );
	$meta_title = trim( (string) ( $meta['meta_title'] ?? '' ) );

	if ( ! $has_text || ! $has_fonts || ! $meta_title ) {
		return [
			'level'  => 'red',
			'label'  => 'Vyžaduje spracovanie',
		];
	}

	$images_without_alt = (int) ( $meta['images_without_alt'] ?? 0 );

	if ( $images_without_alt > 0 ) {
		return [
			'level'  => 'yellow',
			'label'  => 'Chýbajú alt texty (' . $images_without_alt . ')',
		];
	}

	return [
		'level'  => 'green',
		'label'  => 'Pripravené',
	];
}

/**
 * Count how many PDFs need processing (red status).
 */
function sba_pdf_count_pending(): int {
	$all = sba_pdf_get_all( 1, 9999 );
	$count = 0;
	foreach ( $all as $att ) {
		$meta = sba_pdf_get_meta( $att->ID );
		$status = sba_pdf_compute_status( $meta );
		if ( $status['level'] === 'red' ) {
			$count++;
		}
	}
	return $count;
}

// --- Admin page -----------------------------------------------------------

function sba_pdf_render_page(): void {
	$per_page    = 100;
	$paged       = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
	$total       = sba_pdf_count_all();
	$total_pages = (int) ceil( $total / $per_page );
	$attachments = sba_pdf_get_all( $paged, $per_page );
	$nonce       = wp_create_nonce( 'sba_pdf_a11y' );
	$ajax_url    = admin_url( 'admin-ajax.php' );
	$base_url    = admin_url( 'upload.php?page=sba-pdf-accessibility' );
	?>
	<div class="wrap">
		<h1>PDF Prístupnosť</h1>

		<div id="sba-notice" class="notice" style="display:none; padding:10px 12px; margin:10px 0;"></div>

		<?php $pending_count = sba_pdf_count_pending(); ?>
		<?php if ( $pending_count > 0 ) : ?>
		<div style="margin: 15px 0 20px; padding: 16px 20px; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
			<div>
				<strong style="font-size:14px;"><?= $pending_count ?> PDF <?= $pending_count === 1 ? 'vyžaduje' : 'vyžadujú' ?> spracovanie</strong>
			</div>
			<button type="button" id="sba-btn-process-all" class="button button-primary" style="font-size:14px; height:auto; padding: 6px 20px;">
				Spracovať všetky nedokončené
			</button>
			<span id="sba-progress" style="display:none; font-size:13px; color:#666;">
				Spracováva sa <strong id="sba-prog-cur">0</strong> / <strong id="sba-prog-tot">0</strong>&hellip;
			</span>
			<div style="margin-left:auto; display:flex; align-items:center; gap:6px;">
				<label for="sba-lang" style="font-size:13px; color:#666;">OCR jazyk:</label>
				<select id="sba-lang" style="font-size:13px;">
					<option value="slk+eng" selected>SK + EN</option>
					<option value="slk">Slovenčina</option>
					<option value="eng">Angličtina</option>
					<option value="ces+slk">Čeština + Slovenčina</option>
				</select>
			</div>
		</div>
		<?php endif; ?>

		<div id="sba-list">
		<?php foreach ( $attachments as $att ) :
			$meta       = sba_pdf_get_meta( $att->ID );
			$filename   = basename( get_attached_file( $att->ID ) );
			$status     = sba_pdf_compute_status( $meta );
			$has_images = ! empty( $meta['has_images'] );
			$img_total  = (int) ( ( $meta['images_with_alt'] ?? 0 ) + ( $meta['images_without_alt'] ?? 0 ) );
			?>
			<div class="sba-card" id="sba-row-<?= $att->ID ?>" data-id="<?= $att->ID ?>"
				data-level="<?= esc_attr( $status['level'] ) ?>"
				data-images="<?= $img_total ?>"
				data-title="<?= esc_attr( $meta['meta_title'] ?? '' ) ?>">
				<div class="sba-card-dot-wrap">
					<span class="sba-dot sba-dot-<?= esc_attr( $status['level'] ) ?>"></span>
					<span class="sba-card-label sba-card-label-<?= esc_attr( $status['level'] ) ?>"><?= esc_html( $status['label'] ) ?></span>
				</div>
				<div class="sba-card-body">
					<strong class="sba-card-name" title="<?= esc_attr( $filename ) ?>"><?= esc_html( mb_strlen( $filename ) > 40 ? mb_substr( $filename, 0, 38 ) . '…' : $filename ) ?></strong>
					<div class="sba-card-tools">
						<?php if ( $has_images ) : ?>
							<button type="button" class="sba-icon-btn sba-alts-btn"
								data-id="<?= $att->ID ?>"
								data-images="<?= $img_total ?>"
								title="Alt texty (<?= $img_total ?> obrázkov)">📝</button>
						<?php endif; ?>
						<button type="button" class="sba-icon-btn sba-mtitle-btn"
							data-id="<?= $att->ID ?>"
							data-title="<?= esc_attr( $meta['meta_title'] ?? '' ) ?>"
							title="Upraviť meta titul">🔤</button>
						<button type="button" class="sba-icon-btn sba-lang-btn"
							data-id="<?= $att->ID ?>"
							title="OCR jazyk">🌐</button>
						<select class="sba-row-lang" data-id="<?= $att->ID ?>" style="display:none;">
							<option value="slk+eng">SK + EN</option>
							<option value="slk">Slovenčina</option>
							<option value="eng">Angličtina</option>
							<option value="ces+slk">Čeština + Slovenčina</option>
						</select>
					</div>
				</div>
				<div class="sba-card-action">
					<?php if ( $status['level'] === 'red' ) : ?>
						<button type="button" class="button button-primary sba-action-btn sba-process-btn" data-id="<?= $att->ID ?>">Spracovať</button>
					<?php elseif ( $status['level'] === 'yellow' ) : ?>
						<button type="button" class="button button-primary sba-action-btn sba-alts-btn"
							data-id="<?= $att->ID ?>" data-images="<?= $img_total ?>">Dopísať alt texty</button>
					<?php else : ?>
						<?php if ( $has_images ) : ?>
							<button type="button" class="button button-small sba-action-btn sba-alts-btn"
								data-id="<?= $att->ID ?>" data-images="<?= $img_total ?>">Alt texty</button>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			</div>
		<?php endforeach; ?>
		</div>

		<?php if ( $total_pages > 1 ) : ?>
		<div class="tablenav-pages" style="display:flex; justify-content:center; align-items:center; gap:8px; padding:16px 0;">
			<?php if ( $paged > 1 ) : ?>
				<a href="<?= esc_url( add_query_arg( 'paged', $paged - 1, $base_url ) ) ?>" class="button button-small">‹ Predchádzajúca</a>
			<?php endif; ?>
			<span style="font-size:13px; color:#555;">Strana <?= $paged ?> / <?= $total_pages ?></span>
			<?php if ( $paged < $total_pages ) : ?>
				<a href="<?= esc_url( add_query_arg( 'paged', $paged + 1, $base_url ) ) ?>" class="button button-small">Nasledujúca ›</a>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<!-- Alt text modal -->
		<div id="sba-alt-modal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,.5); z-index:9999; align-items:center; justify-content:center;">
			<div style="background:#fff; border-radius:8px; padding:24px; max-width:520px; width:90%; max-height:80vh; overflow-y:auto;">
				<h2 style="margin-top:0;">Alt texty pre obrázky</h2>
				<p style="color:#555; font-size:13px;">
					Popíšte obsah každého obrázka pre používateľov čítačiek obrazovky.
				</p>
				<div id="sba-alt-fields"></div>
				<div style="margin-top:16px; display:flex; gap:8px;">
					<button type="button" id="sba-alt-save" class="button button-primary">Uložiť</button>
					<button type="button" id="sba-alt-cancel" class="button">Zrušiť</button>
				</div>
			</div>
		</div>

		<!-- Meta titul modal -->
		<div id="sba-mtitle-modal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,.5); z-index:9999; align-items:center; justify-content:center;">
			<div style="background:#fff; border-radius:8px; padding:24px; max-width:480px; width:90%;">
				<h2 style="margin-top:0; font-size:16px;">Upraviť meta titul PDF</h2>
				<p style="color:#555; font-size:13px; margin-top:0;">
					Titul sa zapíše priamo do metadát PDF súboru a zobrazuje sa v čítačkách a PDF prehliadačoch.
				</p>
				<input type="text" id="sba-mtitle-input"
					style="width:100%; padding:8px 10px; font-size:14px; border:1px solid #8c8f94; border-radius:3px; box-sizing:border-box;"
					placeholder="Zadajte meta titul…">
				<p id="sba-mtitle-hint" style="font-size:11px; color:#888; margin:6px 0 0;">
					Odporúčaná dĺžka: do 70 znakov.
					<span id="sba-mtitle-count" style="font-weight:600;"></span>
				</p>
				<div style="margin-top:16px; display:flex; gap:8px; align-items:center;">
					<button type="button" id="sba-mtitle-save" class="button button-primary">Uložiť</button>
					<button type="button" id="sba-mtitle-cancel" class="button">Zrušiť</button>
					<span id="sba-mtitle-status" style="font-size:12px; margin-left:4px;"></span>
				</div>
			</div>
		</div>
	</div>

	<style>
		/* Card list */
		.sba-card {
			display: flex;
			align-items: center;
			gap: 16px;
			padding: 14px 16px;
			background: #fff;
			border: 1px solid #e0e0e0;
			border-radius: 6px;
			margin-bottom: 8px;
			transition: border-color .15s;
		}
		.sba-card:hover { border-color: #2271b1; }
		.sba-card[data-level="green"] { border-left: 4px solid #00a32a; }
		.sba-card[data-level="yellow"] { border-left: 4px solid #dba617; }
		.sba-card[data-level="red"] { border-left: 4px solid #d63638; }

		.sba-card-dot-wrap {
			display: flex;
			flex-direction: column;
			align-items: center;
			gap: 4px;
			min-width: 130px;
			flex-shrink: 0;
		}
		.sba-dot {
			width: 14px;
			height: 14px;
			border-radius: 50%;
			flex-shrink: 0;
		}
		.sba-dot-red    { background: #d63638; box-shadow: 0 0 0 3px #f8d7da; }
		.sba-dot-yellow { background: #dba617; box-shadow: 0 0 0 3px #fff3cd; }
		.sba-dot-green  { background: #00a32a; box-shadow: 0 0 0 3px #d1e7dd; }
		.sba-card-label { font-size: 11px; font-weight: 600; text-align: center; }
		.sba-card-label-red    { color: #d63638; }
		.sba-card-label-yellow { color: #8a6d0b; }
		.sba-card-label-green  { color: #0a3622; }

		.sba-card-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 6px; }
		.sba-card-name {
			font-size: 14px;
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
		}
		.sba-card-tools { display: flex; gap: 6px; align-items: center; }
		.sba-icon-btn {
			background: none;
			border: none;
			cursor: pointer;
			font-size: 16px;
			padding: 2px 4px;
			border-radius: 3px;
			line-height: 1;
		}
		.sba-icon-btn:hover { background: #f0f0f1; }
		.sba-row-lang {
			font-size: 12px !important;
			padding: 2px 4px !important;
			height: auto !important;
			width: auto !important;
			line-height: 1.4 !important;
		}

		.sba-card-action { flex-shrink: 0; }
		.sba-card-action .button { white-space: nowrap; }

		/* Spin */
		.sba-spin { display:inline-block; width:14px; height:14px; border:2px solid #ccc; border-top-color:#2271b1; border-radius:50%; animation:sba-rotate .7s linear infinite; vertical-align:middle; }
		@keyframes sba-rotate { to { transform:rotate(360deg); } }

		/* Modals */
		#sba-alt-modal.open, #sba-mtitle-modal.open { display:flex !important; }

		/* Responsive */
		@media (max-width: 782px) {
			.sba-card { flex-wrap: wrap; }
			.sba-card-dot-wrap { flex-direction: row; width: 100%; min-width: 0; }
			.sba-card-action { width: 100%; }
			.sba-card-action .button { width: 100%; text-align:center; }
		}
	</style>

	<script>
	jQuery(function ($) {
		const nonce   = '<?= esc_js( $nonce ) ?>';
		const ajaxUrl = '<?= esc_js( $ajax_url ) ?>';

		function getLang(id) {
			if (id) {
				var rowLang = $('.sba-row-lang[data-id="' + id + '"]').val();
				if (rowLang) return rowLang;
			}
			return $('#sba-lang').val() || 'slk+eng';
		}

		function showNotice(msg, type) {
			$('#sba-notice').removeClass('notice-success notice-error notice-warning')
				.addClass('notice-' + (type || 'success'))
				.html(msg)
				.show();
		}

		// Compute status from data, same logic as PHP
		function computeStatus(meta) {
			if (!meta || !meta.checked_at) return { level: 'red', label: 'Vyžaduje spracovanie' };
			if (meta.status === 'pending') return { level: 'red', label: 'Čaká na spracovanie…' };
			if (!meta.has_text || !meta.fonts_embedded || !meta.meta_title) return { level: 'red', label: 'Vyžaduje spracovanie' };
			var miss = parseInt(meta.images_without_alt || 0);
			if (miss > 0) return { level: 'yellow', label: 'Chýbajú alt texty (' + miss + ')' };
			return { level: 'green', label: 'Pripravené' };
		}

		function refreshCard(id, meta) {
			var card = $('#sba-row-' + id);
			if (!card.length) return;
			var st = computeStatus(meta);
			card.attr('data-level', st.level);
			card.find('.sba-dot').attr('class', 'sba-dot sba-dot-' + st.level);
			card.find('.sba-card-label')
				.attr('class', 'sba-card-label sba-card-label-' + st.level)
				.text(st.label);
			// Update action button
			var actionDiv = card.find('.sba-card-action');
			if (st.level === 'red') {
				actionDiv.html('<button type="button" class="button button-primary sba-action-btn sba-process-btn" data-id="' + id + '">Spracovať</button>');
			} else if (st.level === 'yellow') {
				var imgCount = card.data('images') || 0;
				actionDiv.html('<button type="button" class="button button-primary sba-action-btn sba-alts-btn" data-id="' + id + '" data-images="' + imgCount + '">Dopísať alt texty</button>');
			} else {
				var imgCount = card.data('images') || 0;
				actionDiv.html(imgCount ? '<button type="button" class="button button-small sba-action-btn sba-alts-btn" data-id="' + id + '" data-images="' + imgCount + '">Alt texty</button>' : '');
			}
			// Update meta title data for the edit button
			if (meta.meta_title !== undefined) {
				card.find('.sba-mtitle-btn').attr('data-title', meta.meta_title || '');
				card.data('title', meta.meta_title || '');
			}
			// Update image count if changed
			var totalImgs = (parseInt(meta.images_with_alt || 0) + parseInt(meta.images_without_alt || 0));
			if (totalImgs > 0) {
				card.data('images', totalImgs);
				card.find('.sba-alts-btn').attr('data-images', totalImgs);
			}
		}

		// Single process (Spracovať button)
		$(document).on('click', '.sba-process-btn', function () {
			var id  = $(this).data('id');
			var btn = $(this);
			var lang = getLang(id);

			btn.prop('disabled', true).text('Spracúva sa…');
			$('#sba-row-' + id).find('.sba-card-label').html('<span class="sba-spin"></span>');

			$.post(ajaxUrl, { action: 'sba_pdf_process', nonce: nonce, id: id, lang: lang })
				.done(function (r) {
					if (r.success && r.data.status) {
						refreshCard(id, r.data.status);
						var msg = 'Súbor bol spracovaný.';
						var type = 'success';
						if (r.data.localtag && r.data.localtag.status === 'ok') {
							msg += ' PDF bolo zároveň pripravené pre čítačky obrazovky.';
							type = 'warning';
						} else if (r.data.localtag && r.data.localtag.error) {
							msg += ' Tagovanie sa nepodarilo: ' + r.data.localtag.error;
							type = 'warning';
						}
						showNotice(msg, type);
					} else {
						showNotice('Chyba: ' + (r.data || 'neznáma'), 'error');
						btn.prop('disabled', false).text('Spracovať');
						refreshCard(id, {});
					}
				})
				.fail(function () { showNotice('Požiadavka zlyhala.', 'error'); btn.prop('disabled', false).text('Spracovať'); });
		});

		// OCR language toggle — clicking 🌐 shows/hides the dropdown
		$(document).on('click', '.sba-lang-btn', function (e) {
			e.stopPropagation();
			var card = $(this).closest('.sba-card');
			var sel = card.find('.sba-row-lang');
			sel.toggle();
			if (sel.is(':visible')) { sel.focus(); }
		});
		// Hide OCR dropdown when clicking elsewhere
		$(document).on('click', function (e) {
			// Only hide if the click was NOT on a lang button or lang dropdown
			if (!$(e.target).closest('.sba-lang-btn, .sba-row-lang').length) {
				$('.sba-row-lang:visible').hide();
			}
		});

		// Process all pending (red) files
		$('#sba-btn-process-all').on('click', function () {
			var btn = $(this);
			var ids = $('.sba-card[data-level="red"]').map(function () { return $(this).data('id'); }).get();
			if (!ids.length) { showNotice('Nie sú žiadne súbory na spracovanie.', 'success'); return; }

			btn.prop('disabled', true).text('Spracúva sa…');
			$('#sba-progress').show();
			$('#sba-prog-tot').text(ids.length);
			$('#sba-prog-cur').text(0);

			var i = 0;
			var errors = 0;
			function next() {
				if (i >= ids.length) {
					btn.prop('disabled', false).text('Spracovať všetky nedokončené');
					$('#sba-progress').hide();
					var doneMsg = 'Hotovo: ' + ids.length + ' súborov spracovaných.';
					if (errors > 0) { doneMsg += ' (' + errors + ' s chybou)'; showNotice(doneMsg, 'warning'); }
					else { showNotice(doneMsg, 'success'); }
					return;
				}
				var id = ids[i];
				$('#sba-prog-cur').text(i + 1);
				var card = $('#sba-row-' + id);
				card.find('.sba-card-label').html('<span class="sba-spin"></span>');

				$.post(ajaxUrl, { action: 'sba_pdf_process', nonce: nonce, id: id, lang: getLang(id) })
					.done(function (r) {
						if (r.success && r.data.status) {
							refreshCard(id, r.data.status);
						} else {
							errors++;
							refreshCard(id, {});
						}
					})
					.fail(function () { errors++; })
					.always(function () { i++; next(); });
			}
			next();
		});

		// ─── Alt text modal ───────────────────────────────────────────────
		var altCurrentId = null;
		var altTaggedPdf = false;

		function renderAltFields(images) {
			var fields = '';
			images.forEach(function (img, n) {
				var thumb = img.thumb
					? '<img src="data:image/jpeg;base64,' + img.thumb + '" alt="" style="max-width:240px;max-height:160px;display:block;margin-bottom:6px;border:1px solid #ddd;border-radius:4px;">'
					: '<div style="width:240px;max-width:100%;height:80px;display:flex;align-items:center;justify-content:center;margin-bottom:6px;border:1px dashed #c3c4c7;border-radius:4px;background:#f6f7f7;color:#646970;font-size:12px;text-align:center;padding:8px;box-sizing:border-box;">Náhľad nedostupný</div>';
				var label = 'Strana ' + img.page + ', obrázok ' + img.index;
				if (img.has_alt) { label += ' <span style="color:#0a3622;font-size:11px;">✓</span>'; }
				var xrefAttr = img.struct_fig_xref ? ' data-struct-xref="' + parseInt(img.struct_fig_xref) + '"' : '';
				fields += '<div style="margin-bottom:16px;padding-bottom:14px;border-bottom:1px solid #f0f0f0;">'
					+ thumb
					+ '<label style="display:block;font-weight:600;margin-bottom:4px;">' + label + '</label>'
					+ '<input type="text" class="widefat sba-alt-input" data-index="' + n + '"' + xrefAttr + ' placeholder="Popíšte obrázok…" style="width:100%;">'
					+ '</div>';
			});
			$('#sba-alt-fields').html(fields);
		}

		function renderAltFieldsFallback(imageCount) {
			var fields = '';
			for (var n = 1; n <= imageCount; n++) {
				fields += '<div style="margin-bottom:12px;">'
					+ '<label style="display:block;font-weight:600;margin-bottom:4px;">Obrázok ' + n + '</label>'
					+ '<input type="text" class="widefat sba-alt-input" data-index="' + (n - 1) + '" placeholder="Popíšte obrázok ' + n + '…" style="width:100%;">'
					+ '</div>';
			}
			$('#sba-alt-fields').html(fields);
		}

		$(document).on('click', '.sba-alts-btn', function () {
			altCurrentId   = $(this).data('id');
			altTaggedPdf   = false;
			var imageCount = parseInt($(this).data('images')) || 1;
			$('#sba-alt-fields').html('<p style="color:#666;font-size:13px;">Načítavam náhľady…</p>');
			$('#sba-alt-modal').addClass('open');
			$.post(ajaxUrl, { action: 'sba_pdf_images', nonce: nonce, id: altCurrentId })
				.done(function (r) {
					var imgs = r.success && r.data && Array.isArray(r.data.images) ? r.data.images : null;
					altTaggedPdf = !!(r.success && r.data && r.data.tagged_pdf);
					var tagNotice = altTaggedPdf
						? '<p style="font-size:12px;color:#0a3622;background:#d1e7dd;padding:8px 12px;border-radius:4px;margin:0 0 12px;">Alt texty sa zapíšu priamo do PDF (dokument má tagovanú štruktúru).</p>'
						: '<p style="font-size:12px;color:#664d03;background:#fff3cd;padding:8px 12px;border-radius:4px;margin:0 0 12px;">Alt texty sa uložia do databázy (dokument nie je tagovaný).</p>';
					if (imgs && imgs.length) {
						renderAltFields(imgs);
					} else {
						renderAltFieldsFallback(imageCount);
					}
					$('#sba-alt-fields').prepend(tagNotice);
				})
				.fail(function () { altTaggedPdf = false; renderAltFieldsFallback(imageCount); });
		});

		$('#sba-alt-cancel').on('click', function () {
			$('#sba-alt-modal').removeClass('open');
		});

		$('#sba-alt-save').on('click', function () {
			var alts = {};
			var structXrefs = {};
			$('.sba-alt-input').each(function () {
				var idx = $(this).data('index');
				alts[idx] = $(this).val();
				var xref = $(this).data('struct-xref');
				if (xref) { structXrefs[idx] = xref; }
			});
			$.post(ajaxUrl, {
				action: 'sba_pdf_save_alts',
				nonce: nonce,
				id: altCurrentId,
				alts: alts,
				struct_xrefs: structXrefs,
			}).done(function (r) {
				if (r.success) {
					$('#sba-alt-modal').removeClass('open');
					// Refresh card to reflect updated alt status
					var card = $('#sba-row-' + altCurrentId);
					var existingImages = parseInt(card.data('images') || 0);
					var updatedMeta = {
						checked_at: true,
						has_text: true,
						fonts_embedded: true,
						meta_title: card.find('.sba-mtitle-btn').data('title') || card.data('title'),
						images_without_alt: 0,
						images_with_alt: existingImages,
					};
					refreshCard(altCurrentId, updatedMeta);

					var es = (r.data && r.data.embed_status) || 'wp_only';
					var msg = es === 'embedded'
						? 'Alt texty uložené a zapísané do PDF (' + (r.data.embed_count || 0) + ' položiek).'
						: 'Alt texty uložené v databáze WordPress.';
					showNotice(msg, es === 'embedded' ? 'success' : 'warning');
				}
			});
		});

		// ─── Meta title modal ─────────────────────────────────────────────
		var mtitleCurrentId = null;

		$(document).on('click', '.sba-mtitle-btn', function (e) {
			e.stopPropagation();
			mtitleCurrentId = $(this).data('id');
			var cur = $(this).data('title') || '';
			$('#sba-mtitle-input').val(cur);
			updateMtitleCount(cur.length);
			$('#sba-mtitle-status').text('');
			$('#sba-mtitle-modal').addClass('open');
			setTimeout(function () { $('#sba-mtitle-input').focus().select(); }, 50);
		});

		function updateMtitleCount(len) {
			$('#sba-mtitle-count').text(len + ' znakov').css('color', len > 70 ? '#d63638' : '#888');
		}

		$('#sba-mtitle-input').on('input', function () {
			updateMtitleCount(this.value.length);
		});

		$('#sba-mtitle-cancel').on('click', function () {
			$('#sba-mtitle-modal').removeClass('open');
		});

		$('#sba-mtitle-save').on('click', function () {
			var title = $('#sba-mtitle-input').val().trim();
			if (!title) { $('#sba-mtitle-status').css('color', '#d63638').text('Zadajte titul.'); return; }
			var saveBtn = $(this).prop('disabled', true).text('Ukladám…');
			$('#sba-mtitle-status').text('');

			$.post(ajaxUrl, { action: 'sba_pdf_save_meta_title', nonce: nonce, id: mtitleCurrentId, title: title })
				.done(function (r) {
					if (r.success) {
						$('#sba-mtitle-modal').removeClass('open');
						// Update the card's meta title data + refresh status
						var card = $('#sba-row-' + mtitleCurrentId);
						card.find('.sba-mtitle-btn').attr('data-title', title);
						card.data('title', title);
						showNotice('Meta titul uložený.', 'success');
						// Trigger a background check to refresh the card status
						$.post(ajaxUrl, { action: 'sba_pdf_check', nonce: nonce, id: mtitleCurrentId })
							.done(function (cr) {
								if (cr.success) refreshCard(mtitleCurrentId, cr.data);
							});
					} else {
						$('#sba-mtitle-status').css('color', '#d63638').text(r.data || 'Chyba pri ukladaní.');
					}
				})
				.fail(function () {
					$('#sba-mtitle-status').css('color', '#d63638').text('Požiadavka zlyhala.');
				})
				.always(function () { saveBtn.prop('disabled', false).text('Uložiť'); });
		});
	});
	</script>
	<?php
}
