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
 * Render a one-state attachment field. Editors get one next action only.
 */
function sba_pdf_attachment_field_html( int $id, array $meta, string $nonce ): string {
	$status = sba_pdf_compute_status( $meta );
	$colors = [ 'red' => '#d63638', 'yellow' => '#996800', 'green' => '#008a20' ];
	$color  = $colors[ $status['level'] ] ?? '#646970';
	$action = '';

	if ( $status['level'] === 'red' ) {
		$action = '<button type="button" class="button button-primary sba-att-process-btn" style="margin-top:8px;">Spracovať PDF</button>';
	} elseif ( ! empty( $meta['review_required'] ) ) {
		$image_count = (int) ( ( $meta['images_with_alt'] ?? 0 ) + ( $meta['images_without_alt'] ?? 0 ) );
		$action = '<button type="button" class="button button-primary sba-alts-btn sba-att-review-btn" data-id="' . $id . '" data-images="' . $image_count . '" style="margin-top:8px;">Skontrolovať a potvrdiť</button>';
	}

	ob_start();
	sba_pdf_render_alt_modal();
	$modal = (string) ob_get_clean();

	return sprintf(
		'<div class="sba-att-wrap" data-id="%d" data-nonce="%s" style="font-size:12px;">
			<strong style="color:%s;">%s</strong>
			<div style="color:#646970;margin-top:4px;">%s</div>
			%s
			<span class="sba-att-result" style="margin-left:8px;font-size:11px;"></span>
		</div>',
		$id,
		esc_attr( $nonce ),
		esc_attr( $color ),
		esc_html( $status['label'] ),
		esc_html( sba_pdf_attachment_status_hint( $meta, $status['level'] ) ),
		$action
	) . $modal;
}

function sba_pdf_attachment_status_hint( array $meta, string $level ): string {
	if ( ( $meta['status'] ?? '' ) === 'pending' ) {
		return 'PDF sa pripravuje na pozadí. Môžete pokračovať v práci.';
	}
	if ( ! empty( $meta['review_required'] ) ) {
		return 'Systém pripravil popisy obrázkov. Opravte len to, čo nesedí.';
	}
	if ( $level === 'green' ) {
		return 'Dokument bol pripravený pre povinnosti prístupnosti.';
	}
	return 'PDF ešte nebolo spracované.';
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
