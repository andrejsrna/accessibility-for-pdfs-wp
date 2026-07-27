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

const SBA_PDF_MUTATING_ACTIONS = [ 'process', 'metadata', 'write-alts', 'autotag', 'localtag' ];

/**
 * Serialize writes to a given PDF so the WP-Cron auto-processor and a manual
 * admin click can never run the Python pipeline on the same file at once.
 * Uses a non-blocking flock so a busy request fails fast instead of queueing
 * behind a long-running OCR job.
 */
function sba_pdf_acquire_lock( string $path ) {
	$lock_path = sys_get_temp_dir() . '/sba_pdf_lock_' . md5( $path ) . '.lock';
	$handle    = fopen( $lock_path, 'c' );
	if ( ! $handle ) {
		return null;
	}
	if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
		fclose( $handle );
		return false;
	}
	return $handle;
}

function sba_pdf_release_lock( $handle ): void {
	if ( is_resource( $handle ) ) {
		flock( $handle, LOCK_UN );
		fclose( $handle );
	}
}

function sba_pdf_run( string $action, string $path, array $opts = [] ): ?array {
	if ( ! function_exists( 'shell_exec' ) ) {
		return [ 'error' => 'shell_exec je zakázaný v php.ini' ];
	}

	$lock = null;
	if ( in_array( $action, SBA_PDF_MUTATING_ACTIONS, true ) ) {
		$lock = sba_pdf_acquire_lock( $path );
		if ( $lock === false ) {
			return [ 'error' => 'Súbor sa práve spracováva (cron alebo iná požiadavka), skúste to o chvíľu.', 'busy' => true ];
		}
	}

	try {
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
			$tmp_json = tempnam( sys_get_temp_dir(), 'sba_alts_' );
			if ( false === $tmp_json || false === file_put_contents( $tmp_json, $opts['alts_json'], LOCK_EX ) ) {
				return [ 'error' => 'Nepodarilo sa pripraviť dočasné dáta alt textov.' ];
			}
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
	} finally {
		sba_pdf_release_lock( $lock );
	}
}

/**
 * Enforce both attachment ownership/capabilities and the expected file type.
 * `upload_files` alone lets Authors upload files but does not allow changing
 * another author's attachment.
 */
function sba_pdf_require_editable_attachment( int $id ): void {
	if ( ! $id || get_post_mime_type( $id ) !== 'application/pdf' || ! current_user_can( 'edit_post', $id ) ) {
		wp_die( '', 403 );
	}
}

// ─── AI alt-text suggestions (OpenAI vision) ───────────────────────────────
//
// Reuses the same API key/model resolution and prompt style as the WP-CLI
// image ALT generator in wp-content/mu-plugins/sba-alt-generator.php so
// suggestions read consistently whether they came from a regular image or
// a PDF page image. Suggestions are never written automatically — the admin
// UI only pre-fills the alt-text modal; the editor still reviews and saves.

define( 'SBA_PDF_A11Y_OPENAI_API_URL', 'https://api.openai.com/v1/chat/completions' );
define( 'SBA_PDF_A11Y_OPENAI_MAX_PER_REQUEST', 8 );

function sba_pdf_openai_api_key(): string {
	if ( defined( 'SBA_ALT_OPENAI_API_KEY' ) && SBA_ALT_OPENAI_API_KEY ) {
		return trim( (string) SBA_ALT_OPENAI_API_KEY );
	}
	$env = getenv( 'OPENAI_API_KEY' );
	return is_string( $env ) ? trim( $env ) : '';
}

function sba_pdf_openai_model(): string {
	if ( defined( 'SBA_ALT_OPENAI_MODEL' ) && SBA_ALT_OPENAI_MODEL ) {
		return trim( (string) SBA_ALT_OPENAI_MODEL );
	}
	$env = getenv( 'SBA_ALT_OPENAI_MODEL' );
	return ( is_string( $env ) && trim( $env ) !== '' ) ? trim( $env ) : 'gpt-4.1-mini';
}

/**
 * Ask OpenAI vision for one PDF image's alt text. An empty 'alt' means the
 * model judged the image purely decorative — that is a valid suggestion,
 * not a failure, and the UI must not treat it as an error.
 */
function sba_pdf_suggest_alt_text( string $thumb_b64, string $doc_title, int $page ): array {
	$api_key = sba_pdf_openai_api_key();
	if ( ! $api_key ) {
		return [ 'error' => 'Chýba OPENAI_API_KEY na serveri.' ];
	}
	if ( $thumb_b64 === '' ) {
		return [ 'error' => 'Náhľad obrázka nie je dostupný.' ];
	}

	$instructions = implode( "\n", array_filter( [
		'Vygeneruj ALT text v slovenčine pre obrázok zo strany PDF dokumentu.',
		'Vráť iba samotný ALT text bez úvodzoviek, vysvetlení a markdownu.',
		'Buď konkrétny, stručný a vecný. Maximálne 125 znakov.',
		'Nepoužívaj frázy ako "obrázok", "fotografia", "na obrázku je", pokiaľ nie sú nevyhnutné.',
		'Ak je dôležitý text priamo v obrázku (napr. graf, schéma), zahrň jeho zmysel stručne.',
		'Ak je obrázok čisto dekoratívny (pozadie, oddeľovač, logo, ozdobný prvok bez informačnej hodnoty), vráť prázdny reťazec.',
		$doc_title !== '' ? 'Názov dokumentu: ' . $doc_title : '',
		'Strana v dokumente: ' . $page,
	] ) );

	$payload = [
		'model'       => sba_pdf_openai_model(),
		'messages'    => [
			[
				'role'    => 'user',
				'content' => [
					[ 'type' => 'text', 'text' => $instructions ],
					[ 'type' => 'image_url', 'image_url' => [ 'url' => 'data:image/jpeg;base64,' . $thumb_b64 ] ],
				],
			],
		],
		'max_tokens'  => 120,
		'temperature' => 0.2,
	];

	$response = wp_remote_post( SBA_PDF_A11Y_OPENAI_API_URL, [
		'headers' => [
			'Authorization' => 'Bearer ' . $api_key,
			'Content-Type'  => 'application/json',
		],
		'body'    => wp_json_encode( $payload ),
		'timeout' => 40,
	] );

	if ( is_wp_error( $response ) ) {
		return [ 'error' => sba_pdf_safe_error_detail( $response->get_error_message() ) ];
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $code < 200 || $code >= 300 ) {
		$msg = is_array( $body ) ? ( $body['error']['message'] ?? ( 'HTTP ' . $code ) ) : ( 'HTTP ' . $code );
		return [ 'error' => sba_pdf_safe_error_detail( (string) $msg ) ];
	}

	$content = $body['choices'][0]['message']['content'] ?? '';
	if ( ! is_string( $content ) ) {
		return [ 'error' => 'OpenAI odpoveď nemá očakávaný obsah.' ];
	}

	$alt = wp_strip_all_tags( $content );
	$alt = preg_replace( '/\s+/u', ' ', $alt );
	$alt = trim( (string) $alt, " \t\n\r\0\x0B\"'" );
	$alt = function_exists( 'mb_substr' ) ? mb_substr( $alt, 0, 125 ) : substr( $alt, 0, 125 );

	return [ 'alt' => $alt, 'decorative' => $alt === '' ];
}

/**
 * Background AI pass after upload. Stores suggestions only; no PDF or public
 * attachment data changes until an editor confirms them in the review modal.
 */
function sba_pdf_auto_suggest_alts( int $id ): void {
	if ( ! sba_pdf_openai_api_key() ) {
		return;
	}

	$path = get_attached_file( $id );
	if ( ! $path || ! file_exists( $path ) ) {
		return;
	}
	$images_result = sba_pdf_run( 'images', $path );
	$images        = is_array( $images_result ) ? ( $images_result['images'] ?? [] ) : [];
	if ( ! $images ) {
		return;
	}

	$stored    = (array) get_post_meta( $id, SBA_PDF_A11Y_ALT_META_KEY, true );
	$next      = (int) get_post_meta( $id, '_sba_pdf_ai_alt_next', true );
	$pending   = array_values( array_filter( array_keys( $images ), static function ( int $index ) use ( $stored ): bool {
		return ! array_key_exists( $index, $stored );
	} ) );
	$batch     = array_slice( $pending, 0, SBA_PDF_A11Y_OPENAI_MAX_PER_REQUEST );
	$title     = (string) get_the_title( $id );
	$review    = false;

	foreach ( $batch as $index ) {
		if ( array_key_exists( $index, $stored ) ) {
			continue;
		}
		$image = $images[ $index ];
		$ai    = sba_pdf_suggest_alt_text( (string) ( $image['thumb'] ?? '' ), $title, (int) ( $image['page'] ?? 0 ) );
		if ( isset( $ai['alt'] ) ) {
			$stored[ $index ] = $ai['alt'];
			$review = $review || $ai['alt'] !== '';
		}
	}

	update_post_meta( $id, SBA_PDF_A11Y_ALT_META_KEY, $stored );
	if ( count( $pending ) > count( $batch ) ) {
		update_post_meta( $id, '_sba_pdf_ai_alt_next', $next + count( $batch ) );
		wp_schedule_single_event( time() + 5, 'sba_pdf_auto_suggest_async', [ $id ] );
		return;
	}
	delete_post_meta( $id, '_sba_pdf_ai_alt_next' );

	foreach ( $stored as $alt ) {
		if ( trim( (string) $alt ) !== '' ) {
			$review = true;
			break;
		}
	}
	$meta = sba_pdf_get_meta( $id );
	$meta['review_required'] = $review;
	$meta['ai_alts_ready_at'] = current_time( 'mysql' );
	sba_pdf_save_meta( $id, $meta );
}

add_action( 'sba_pdf_auto_suggest_async', 'sba_pdf_auto_suggest_alts' );

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
		'review_required',
		'ai_alts_ready_at',
		'alts_confirmed_at',
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
	$meta_lang  = trim( (string) ( $meta['meta_lang'] ?? '' ) );

	if ( ! $has_text || ! $has_fonts || ! $meta_title || ! $meta_lang ) {
		return [
			'level'  => 'red',
			'label'  => 'Vyžaduje spracovanie',
		];
	}

	if ( ! empty( $meta['review_required'] ) ) {
		return [
			'level'  => 'yellow',
			'label'  => 'Skontrolujte obrázky',
		];
	}

	if ( empty( $meta['tagged_pdf'] ) ) {
		return [
			'level'  => 'yellow',
			'label'  => 'Pripravujeme PDF',
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
