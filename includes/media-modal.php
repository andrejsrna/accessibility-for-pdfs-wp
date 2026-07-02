<?php
/**
 * Media Library modal integration: attachment fields, badges, inline JS.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Inject PDF accessibility panel into the media modal / attachment detail screen
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

/**
 * Render the media-modal attachment field HTML with badges + process button.
 */
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

/**
 * Render the status badge table for the media modal.
 */
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

/**
 * Inline JS injected into the media modal for the "Opraviť teraz" button.
 */
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
