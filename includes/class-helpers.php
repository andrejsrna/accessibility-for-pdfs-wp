<?php
/**
 * Helper functions: DB queries, meta management, badges, status computation.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ─── DB queries ───────────────────────────────────────────────────────────

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

// ─── Python process runner ────────────────────────────────────────────────

function sba_pdf_run( string $action, string $path, array $opts = [] ): ?array {
	if ( ! function_exists( 'shell_exec' ) ) {
		return [ 'error' => 'shell_exec je zakázaný v php.ini' ];
	}

	$python = escapeshellcmd( '/usr/bin/python3' );
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

	// Some SDKs/libraries emit warnings before JSON. Try to recover the last JSON object.
	if ( preg_match( '/(\{.*\})\s*$/s', $trimmed, $m ) ) {
		$decoded = json_decode( $m[1], true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}
	}

	return [ 'raw' => $output ];
}

// ─── Meta management ──────────────────────────────────────────────────────

function sba_pdf_save_meta( int $id, array $data ): void {
	update_post_meta( $id, SBA_PDF_A11Y_META_KEY, $data );
}

function sba_pdf_get_meta( int $id ): array {
	return (array) ( get_post_meta( $id, SBA_PDF_A11Y_META_KEY, true ) ?: [] );
}

function sba_pdf_save_status_meta( int $id, array $status ): array {
	$existing = sba_pdf_get_meta( $id );

	// Preserve values entered manually through the AJAX UI.
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

// ─── Error sanitisation ───────────────────────────────────────────────────

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

// ─── Badges (used by media modal + legacy table views) ────────────────────

function sba_pdf_badge( array $status, string $key ): string {
	if ( empty( $status ) ) {
		return '<span class="sba-badge sba-badge-na">—</span>';
	}
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

// ─── Status computation (traffic-light) ───────────────────────────────────

/**
 * Compute a simplified traffic-light status from the stored metadata.
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
