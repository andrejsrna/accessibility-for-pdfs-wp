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
	sba_pdf_save_meta( $attachment_id, $status );
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

	$result['checked_at'] = current_time( 'mysql' );
	sba_pdf_save_meta( $id, $result );
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

	$opts = [
		'title'   => $attachment->post_title,
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
	$status['processed_at'] = current_time( 'mysql' );
	$status['checked_at']   = current_time( 'mysql' );
	sba_pdf_save_meta( $id, $status );

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

	$id   = intval( $_POST['id'] ?? 0 );
	$alts = $_POST['alts'] ?? [];

	if ( ! $id || ! is_array( $alts ) ) {
		wp_send_json_error( 'Neplatné dáta.' );
	}

	$sanitized = array_map( 'sanitize_text_field', $alts );
	update_post_meta( $id, SBA_PDF_A11Y_ALT_META_KEY, $sanitized );
	wp_send_json_success( [ 'saved' => count( $sanitized ) ] );
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

	$python = escapeshellcmd( 'python3' );
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

	$cmd   .= ' 2>&1';
	$output = shell_exec( $cmd );

	if ( $output === null || trim( $output ) === '' ) {
		return null;
	}

	return json_decode( trim( $output ), true ) ?? [ 'raw' => $output ];
}

function sba_pdf_save_meta( int $id, array $data ): void {
	update_post_meta( $id, SBA_PDF_A11Y_META_KEY, $data );
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
		<p style="color:#555;">
			Plugin kontroluje a opravuje: OCR (selektovateľný text), záložky, meta titul/autor/jazyk/predmet, embedding fontov.
			Alt texty pre obrázky treba dopísať manuálne.
		</p>

		<div id="sba-notice" class="notice" style="display:none; padding:10px 12px; margin:10px 0;"></div>

		<form id="sba-form">
			<div class="tablenav top">
				<div class="alignleft actions bulkactions" style="display:flex; gap:8px; align-items:center;">
					<button type="button" id="sba-btn-check-all" class="button">Skontrolovať označené</button>
					<button type="button" id="sba-btn-process-all" class="button button-primary">Opraviť označené</button>
					<label style="margin-left:8px; font-size:13px;">
						Jazyk OCR:
						<select id="sba-lang" style="margin-left:4px;">
							<option value="slk+eng" selected>Slovenčina + Angličtina</option>
							<option value="slk">Slovenčina</option>
							<option value="eng">Angličtina</option>
							<option value="ces+slk">Čeština + Slovenčina</option>
						</select>
					</label>
				</div>
				<div class="alignright tablenav-pages" style="line-height:36px; display:flex; align-items:center; gap:12px;">
					<span id="sba-progress" style="display:none;">
						Spracováva sa <strong id="sba-prog-cur">0</strong> / <strong id="sba-prog-tot">0</strong>&hellip;
					</span>
					<span style="color:#555;"><?= $total ?> PDF súborov</span>
					<?php if ( $total_pages > 1 ) : ?>
					<span class="displaying-num">
						<?php if ( $paged > 1 ) : ?>
							<a href="<?= esc_url( add_query_arg( 'paged', $paged - 1, $base_url ) ) ?>" class="button button-small">‹ Predch.</a>
						<?php endif; ?>
						Strana <?= $paged ?> / <?= $total_pages ?>
						<?php if ( $paged < $total_pages ) : ?>
							<a href="<?= esc_url( add_query_arg( 'paged', $paged + 1, $base_url ) ) ?>" class="button button-small">Nasl. ›</a>
						<?php endif; ?>
					</span>
					<?php endif; ?>
				</div>
				<br class="clear">
			</div>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<td class="manage-column column-cb check-column">
							<input type="checkbox" id="sba-check-all">
						</td>
						<th style="width:220px;">Súbor</th>
						<th>WP Titul</th>
						<th style="width:70px;" title="Selektovateľný text / OCR">Text</th>
						<th style="width:80px;" title="Záložky (Outline)">Záložky</th>
						<th style="width:110px;" title="Meta titul dokumentu">Meta titul</th>
						<th style="width:80px;" title="Jazyk dokumentu">Jazyk</th>
						<th style="width:80px;" title="Embedding fontov">Fonty</th>
						<th style="width:90px;" title="Alt texty pre obrázky">Alt texty</th>
						<th style="width:140px;">Posl. kontrola</th>
						<th style="width:180px;">Akcie</th>
					</tr>
				</thead>
				<tbody id="sba-table-body">
				<?php foreach ( $attachments as $att ) :
					$meta     = sba_pdf_get_meta( $att->ID );
					$filename = basename( get_attached_file( $att->ID ) );
					?>
					<tr id="sba-row-<?= $att->ID ?>">
						<th class="check-column">
							<input type="checkbox" name="ids[]" value="<?= $att->ID ?>">
						</th>
						<td>
							<strong title="<?= esc_attr( $filename ) ?>"><?= esc_html( strlen( $filename ) > 30 ? substr( $filename, 0, 28 ) . '…' : $filename ) ?></strong>
						</td>
						<td><?= esc_html( $att->post_title ) ?></td>
						<td id="sba-col-text-<?= $att->ID ?>">
							<?= sba_pdf_badge( $meta, 'has_text' ) ?>
						</td>
						<td id="sba-col-bm-<?= $att->ID ?>">
							<?= sba_pdf_badge( $meta, 'bookmarks_count' ) ?>
						</td>
						<td id="sba-col-mtitle-<?= $att->ID ?>">
							<div class="sba-mtitle-cell">
								<?= sba_pdf_badge( $meta, 'meta_title' ) ?>
								<button type="button" class="sba-mtitle-btn button button-small"
									data-id="<?= $att->ID ?>"
									data-title="<?= esc_attr( $meta['meta_title'] ?? '' ) ?>"
									title="Upraviť meta titul">✎</button>
							</div>
						</td>
						<td id="sba-col-lang-<?= $att->ID ?>">
							<?= sba_pdf_badge( $meta, 'meta_lang' ) ?>
						</td>
						<td id="sba-col-fonts-<?= $att->ID ?>">
							<?= sba_pdf_badge( $meta, 'fonts_embedded' ) ?>
						</td>
						<td id="sba-col-alts-<?= $att->ID ?>">
							<?= sba_pdf_badge( $meta, 'images_without_alt' ) ?>
							<?php if ( ! empty( $meta['has_images'] ) ) : ?>
								<button type="button" class="sba-alts-btn button button-small"
									data-id="<?= $att->ID ?>"
									data-images="<?= (int) ( ( $meta['images_with_alt'] ?? 0 ) + ( $meta['images_without_alt'] ?? 0 ) ) ?>"
									style="margin-top:2px; font-size:10px; padding:0 4px;"
									title="Editovať alt texty">
									✎
								</button>
							<?php endif; ?>
						</td>
						<td id="sba-col-checked-<?= $att->ID ?>" style="font-size:11px; color:#666;">
							<?= esc_html( $meta['checked_at'] ?? '—' ) ?>
						</td>
						<td>
							<button type="button" class="button button-small sba-check-btn" data-id="<?= $att->ID ?>">Skontrolovať</button>
							<button type="button" class="button button-primary button-small sba-process-btn" data-id="<?= $att->ID ?>">Opraviť</button>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav bottom">
				<div class="tablenav-pages" style="display:flex; justify-content:flex-end; align-items:center; gap:8px; padding:8px 0;">
					<?php if ( $paged > 1 ) : ?>
						<a href="<?= esc_url( add_query_arg( 'paged', 1, $base_url ) ) ?>" class="button button-small">« Prvá</a>
						<a href="<?= esc_url( add_query_arg( 'paged', $paged - 1, $base_url ) ) ?>" class="button button-small">‹ Predch.</a>
					<?php endif; ?>
					<span style="font-size:13px; color:#555;">Strana <?= $paged ?> / <?= $total_pages ?></span>
					<?php if ( $paged < $total_pages ) : ?>
						<a href="<?= esc_url( add_query_arg( 'paged', $paged + 1, $base_url ) ) ?>" class="button button-small">Nasl. ›</a>
						<a href="<?= esc_url( add_query_arg( 'paged', $total_pages, $base_url ) ) ?>" class="button button-small">Posl. »</a>
					<?php endif; ?>
				</div>
			</div>
			<?php endif; ?>
		</form>

		<!-- Alt text modal -->
		<div id="sba-alt-modal" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,.5); z-index:9999; align-items:center; justify-content:center;">
			<div style="background:#fff; border-radius:4px; padding:24px; max-width:520px; width:90%; max-height:80vh; overflow-y:auto;">
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
			<div style="background:#fff; border-radius:4px; padding:24px; max-width:480px; width:90%;">
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
					<button type="button" id="sba-mtitle-save" class="button button-primary">Uložiť a zapísať do PDF</button>
					<button type="button" id="sba-mtitle-cancel" class="button">Zrušiť</button>
					<span id="sba-mtitle-status" style="font-size:12px; margin-left:4px;"></span>
				</div>
			</div>
		</div>
	</div>

	<style>
		.sba-badge         { display:inline-block; padding:2px 6px; border-radius:3px; font-size:11px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; vertical-align:middle; }
		.sba-badge-ok      { background:#d1e7dd; color:#0a3622; }
		.sba-badge-err     { background:#f8d7da; color:#58151c; }
		.sba-badge-warn    { background:#fff3cd; color:#664d03; }
		.sba-badge-na      { background:#e9ecef; color:#495057; }
		.sba-badge-pending { background:#cff4fc; color:#055160; }
		.sba-spin          { display:inline-block; width:14px; height:14px; border:2px solid #ccc; border-top-color:#2271b1; border-radius:50%; animation:sba-rotate .7s linear infinite; vertical-align:middle; }
		@keyframes sba-rotate { to { transform:rotate(360deg); } }
		#sba-alt-modal.open, #sba-mtitle-modal.open { display:flex !important; }
		.sba-mtitle-cell   { display:flex; align-items:center; gap:4px; max-width:210px; min-width:0; }
		.sba-mtitle-cell .sba-badge { flex:1; min-width:0; max-width:none; }
		.sba-mtitle-btn    { flex-shrink:0; font-size:10px !important; padding:0 4px !important; line-height:20px !important; height:20px !important; }
	</style>

	<script>
	jQuery(function ($) {
		const nonce   = '<?= esc_js( $nonce ) ?>';
		const ajaxUrl = '<?= esc_js( $ajax_url ) ?>';

		// Select-all checkbox
		$('#sba-check-all').on('change', function () {
			$('input[name="ids[]"]').prop('checked', this.checked);
		});

		function getLang() {
			return $('#sba-lang').val();
		}

		function spin(id) {
			['text', 'bm', 'mtitle', 'lang', 'fonts', 'alts'].forEach(function (col) {
				$('#sba-col-' + col + '-' + id).html('<span class="sba-spin"></span>');
			});
		}

		function badge(val, key) {
			if (val === undefined || val === null || val === '') {
				return '<span class="sba-badge sba-badge-na">—</span>';
			}
			if (key === 'meta_title') {
				if (!val) return '<span class="sba-badge sba-badge-err">✗</span>';
				var short = val.length > 28 ? val.substring(0, 26) + '…' : val;
				return '<span class="sba-badge sba-badge-ok" title="' + $('<div>').text(val).html() + '">✓ ' + $('<div>').text(short).html() + '</span>';
			}
			if (key === 'meta_lang') {
				return val
					? '<span class="sba-badge sba-badge-ok" title="' + $('<div>').text(val).html() + '">✓ ' + $('<div>').text(val).html() + '</span>'
					: '<span class="sba-badge sba-badge-err">✗</span>';
			}
			if (key === 'images_without_alt') {
				return parseInt(val) === 0
					? '<span class="sba-badge sba-badge-ok">✓</span>'
					: '<span class="sba-badge sba-badge-warn">' + val + ' chýba</span>';
			}
			if (key === 'bookmarks_count') {
				return parseInt(val) > 0
					? '<span class="sba-badge sba-badge-ok">' + val + '</span>'
					: '<span class="sba-badge sba-badge-err">0</span>';
			}
			if (val === true)  return '<span class="sba-badge sba-badge-ok">✓</span>';
			if (val === false) return '<span class="sba-badge sba-badge-err">✗</span>';
			return '<span class="sba-badge sba-badge-na">' + val + '</span>';
		}

		function updateRow(id, data) {
			$('#sba-col-text-'    + id).html(badge(data.has_text,           'has_text'));
			$('#sba-col-bm-'      + id).html(badge(data.bookmarks_count,    'bookmarks_count'));
			(function () {
				var cell = $('#sba-col-mtitle-' + id);
				var btn  = cell.find('.sba-mtitle-btn').detach();
				cell.html('<div class="sba-mtitle-cell">' + badge(data.meta_title, 'meta_title') + '</div>');
				if (btn.length) { btn.attr('data-title', data.meta_title || ''); cell.find('.sba-mtitle-cell').append(btn); }
			})();
			$('#sba-col-lang-'    + id).html(badge(data.meta_lang,          'meta_lang'));
			$('#sba-col-fonts-'   + id).html(badge(data.fonts_embedded,     'fonts_embedded'));
			$('#sba-col-alts-'    + id).html(badge(data.images_without_alt, 'images_without_alt'));
			$('#sba-col-checked-' + id).text(data.checked_at || '—');
		}

		function showNotice(msg, type) {
			$('#sba-notice').removeClass('notice-success notice-error notice-warning')
				.addClass('notice-' + (type || 'success'))
				.html(msg)
				.show();
		}

		// Single check
		$(document).on('click', '.sba-check-btn', function () {
			var id  = $(this).data('id');
			var btn = $(this).prop('disabled', true).text('…');
			spin(id);

			$.post(ajaxUrl, { action: 'sba_pdf_check', nonce: nonce, id: id })
				.done(function (r) {
					if (r.success) updateRow(id, r.data);
					else showNotice('Chyba: ' + (r.data || 'neznáma'), 'error');
				})
				.fail(function () { showNotice('Požiadavka zlyhala.', 'error'); })
				.always(function () { btn.prop('disabled', false).text('Skontrolovať'); });
		});

		// Single process
		$(document).on('click', '.sba-process-btn', function () {
			var id  = $(this).data('id');
			var btn = $(this).prop('disabled', true).text('…');
			spin(id);

			$.post(ajaxUrl, { action: 'sba_pdf_process', nonce: nonce, id: id, lang: getLang() })
				.done(function (r) {
					if (r.success && r.data.status) {
						updateRow(id, r.data.status);
						showNotice('Súbor #' + id + ' bol spracovaný.', 'success');
					} else {
						showNotice('Chyba: ' + (r.data || 'neznáma'), 'error');
					}
				})
				.fail(function () { showNotice('Požiadavka zlyhala.', 'error'); })
				.always(function () { btn.prop('disabled', false).text('Opraviť'); });
		});

		// Bulk helpers
		function getCheckedIds() {
			return $('input[name="ids[]"]:checked').map(function () { return $(this).val(); }).get();
		}

		function runBulk(action, ids) {
			if (!ids.length) { alert('Označte aspoň jeden súbor.'); return; }

			$('#sba-btn-check-all, #sba-btn-process-all').prop('disabled', true);
			$('#sba-progress').show();
			$('#sba-prog-tot').text(ids.length);
			$('#sba-prog-cur').text(0);

			var i = 0;
			function next() {
				if (i >= ids.length) {
					$('#sba-btn-check-all, #sba-btn-process-all').prop('disabled', false);
					$('#sba-progress').hide();
					showNotice('Hotovo: ' + ids.length + ' súborov spracovaných.', 'success');
					return;
				}
				var id = ids[i];
				$('#sba-prog-cur').text(i + 1);
				spin(id);

				var postData = { action: 'sba_pdf_' + action, nonce: nonce, id: id };
				if (action === 'process') postData.lang = getLang();

				$.post(ajaxUrl, postData)
					.done(function (r) {
						if (r.success) {
							var d = action === 'check' ? r.data : (r.data.status || r.data);
							if (d) updateRow(id, d);
						}
					})
					.always(function () { i++; next(); });
			}
			next();
		}

		$('#sba-btn-check-all').on('click',   function () { runBulk('check',   getCheckedIds()); });
		$('#sba-btn-process-all').on('click', function () { runBulk('process', getCheckedIds()); });

		// Alt text modal
		var altCurrentId = null;

		$(document).on('click', '.sba-alts-btn', function () {
			altCurrentId   = $(this).data('id');
			var imageCount = parseInt($(this).data('images')) || 1;
			var fields     = '';
			for (var n = 1; n <= imageCount; n++) {
				fields += '<div style="margin-bottom:12px;">'
					+ '<label style="display:block; font-weight:600; margin-bottom:4px;">Obrázok ' + n + '</label>'
					+ '<input type="text" class="widefat sba-alt-input" data-index="' + (n-1) + '" placeholder="Popis obrázka ' + n + ' …" style="width:100%;">'
					+ '</div>';
			}
			$('#sba-alt-fields').html(fields);
			$('#sba-alt-modal').addClass('open');
		});

		$('#sba-alt-cancel').on('click', function () {
			$('#sba-alt-modal').removeClass('open');
		});

		$('#sba-alt-save').on('click', function () {
			var alts = {};
			$('.sba-alt-input').each(function () {
				alts[$(this).data('index')] = $(this).val();
			});
			$.post(ajaxUrl, { action: 'sba_pdf_save_alts', nonce: nonce, id: altCurrentId, alts: alts })
				.done(function (r) {
					if (r.success) {
						$('#sba-alt-modal').removeClass('open');
						showNotice('Alt texty uložené.', 'success');
						// Update the alts badge to reflect 0 missing
						$('#sba-col-alts-' + altCurrentId).find('.sba-badge').first()
							.attr('class', 'sba-badge sba-badge-ok').text('✓');
					}
				});
		});

		// Meta titul modal
		var mtitleCurrentId = null;

		$(document).on('click', '.sba-mtitle-btn', function () {
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
						var cell   = $('#sba-col-mtitle-' + mtitleCurrentId);
						var editBtn = cell.find('.sba-mtitle-btn').detach().attr('data-title', title);
						cell.html('<div class="sba-mtitle-cell">' + badge(title, 'meta_title') + '</div>');
						cell.find('.sba-mtitle-cell').append(editBtn);
						showNotice('Meta titul uložený.', 'success');
					} else {
						$('#sba-mtitle-status').css('color', '#d63638').text(r.data || 'Chyba pri ukladaní.');
					}
				})
				.fail(function () {
					$('#sba-mtitle-status').css('color', '#d63638').text('Požiadavka zlyhala.');
				})
				.always(function () { saveBtn.prop('disabled', false).text('Uložiť a zapísať do PDF'); });
		});
	});
	</script>
	<?php
}
