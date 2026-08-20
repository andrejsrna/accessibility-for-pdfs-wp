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

	if ( $status['level'] === 'red' && ( $meta['status'] ?? '' ) !== 'pending' ) {
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
 * Inline JS injected into the media modal for the "Spracovať PDF" button,
 * plus a lightweight poller so the attachment-detail panel updates itself
 * once background processing finishes — no manual refresh, no need to open
 * the PDF Prístupnosť dashboard.
 */
function sba_pdf_media_modal_js(): string {
	return <<<'JS'
(function($){
	$(document).on('click', '.sba-att-process-btn', function(){
		var $wrap = $(this).closest('.sba-att-wrap');
		var id    = $wrap.data('id');
		var nonce = $wrap.data('nonce');
		var $btn  = $(this).prop('disabled', true).text('Zaraďujem…');
		var $res  = $wrap.find('.sba-att-result').text('');

		$.post(window.ajaxurl || '/wp-admin/admin-ajax.php', {
			action: 'sba_pdf_queue_single',
			nonce:  nonce,
			id:     id
		}).done(function(r){
			if(r.success){
				$wrap.find('> strong').css('color', '#d63638').text('Čaká na spracovanie…');
				$wrap.find('> div').first().text('PDF sa pripravuje na pozadí. Môžete pokračovať v práci.');
				$btn.remove();
				$res.text('');
				startAttachmentPoll($wrap);
			} else {
				$res.text('Nepodarilo sa zaradiť na spracovanie.');
				$btn.prop('disabled', false).text('Spracovať PDF');
			}
		}).fail(function(){
			$res.text('Požiadavka zlyhala.');
			$btn.prop('disabled', false).text('Spracovať PDF');
		});
	});

	// Poll while a PDF is mid-pipeline (pending, or checked but still
	// missing the AI pass) so the panel updates itself without a page
	// reload or a trip to the PDF Prístupnosť dashboard.
	var pollTimers = {};

	function isMidPipeline($wrap) {
		var label = $.trim($wrap.find('> strong').text());
		return label === 'Čaká na spracovanie…' || label === 'Vyžaduje spracovanie';
	}

	// Once basic processing lands (checked_at set) the background AI pass may
	// still be running in batches (large PDFs). Keep polling until either the
	// AI pass finished (ai_alts_ready_at) or the doc needed no AI pass at all
	// (no missing alt texts) — otherwise the panel would flip to "Chýbajú alt
	// texty" mid-batch and the poller would stop before suggestions land.
	function isWaitingOnAiPass(meta) {
		if (!meta || !meta.checked_at) return false;
		if (meta.ai_alts_ready_at || meta.review_required || meta.alts_confirmed_at) return false;
		return parseInt(meta.images_without_alt || 0, 10) > 0;
	}

	function computeAttachmentStatus(meta) {
		if (meta && meta.status === 'pending') return { level: 'red', label: 'Čaká na spracovanie…' };
		if (!meta || !meta.checked_at) return { level: 'red', label: 'Vyžaduje spracovanie' };
		if (!meta.has_text || !meta.fonts_embedded || !meta.meta_title || !meta.meta_lang) return { level: 'red', label: 'Vyžaduje spracovanie' };
		if (meta.review_required) return { level: 'yellow', label: 'Skontrolujte obrázky' };
		if (meta.alts_confirmed_at) return { level: 'green', label: 'Pripravené' };
		var miss = parseInt(meta.images_without_alt || 0, 10);
		if (miss > 0) return { level: 'yellow', label: 'Chýbajú alt texty (' + miss + ')' };
		return { level: 'green', label: 'Pripravené' };
	}

	function renderPollState($wrap, meta) {
		var colors = { red: '#d63638', yellow: '#996800', green: '#008a20' };
		var st = computeAttachmentStatus(meta);
		$wrap.find('> strong').css('color', colors[st.level] || '#646970').text(st.label);

		var hint = 'PDF sa pripravuje na pozadí. Môžete pokračovať v práci.';
		if (meta.review_required) {
			hint = 'Systém pripravil popisy obrázkov. Opravte len to, čo nesedí.';
		} else if (st.level === 'green') {
			hint = 'Dokument bol pripravený pre povinnosti prístupnosti.';
		} else if (st.level !== 'red') {
			hint = 'PDF ešte nebolo spracované.';
		}
		$wrap.find('> div').first().text(hint);

		$wrap.find('.sba-att-review-btn, .sba-att-process-btn').remove();
		if (meta.review_required) {
			var imageCount = (parseInt(meta.images_with_alt || 0, 10) + parseInt(meta.images_without_alt || 0, 10));
			$wrap.find('.sba-att-result').before(
				'<button type="button" class="button button-primary sba-alts-btn sba-att-review-btn" data-id="' + $wrap.data('id') + '" data-images="' + imageCount + '" style="margin-top:8px;">Skontrolovať a potvrdiť</button>'
			);
		}
		return st;
	}

	function shouldKeepPolling($wrap) {
		if (isMidPipeline($wrap)) return true;
		return !!$wrap.data('sba-waiting-ai');
	}

	function pollOnce($wrap) {
		var id    = $wrap.data('id');
		var nonce = $wrap.data('nonce');

		$.post(window.ajaxurl || '/wp-admin/admin-ajax.php', {
			action: 'sba_pdf_check',
			nonce:  nonce,
			id:     id
		}).done(function(r){
			if (!r.success || !r.data) return;
			var st = renderPollState($wrap, r.data);
			var waitingOnAi = isWaitingOnAiPass(r.data);
			$wrap.data('sba-waiting-ai', waitingOnAi);
			if (st.level !== 'red' && !waitingOnAi) {
				stopAttachmentPoll(id);
			}
		});
	}

	// Cap polling so an abandoned tab (or a doc that never finishes, e.g.
	// missing OpenAI key) doesn't hammer admin-ajax.php forever.
	var MAX_POLL_ATTEMPTS = 90; // ~12 minutes at 8s intervals

	function startAttachmentPoll($wrap) {
		var id = $wrap.data('id');
		if (pollTimers[id]) return;
		var attempts = 0;
		pollTimers[id] = setInterval(function () {
			attempts++;
			if (attempts > MAX_POLL_ATTEMPTS || !shouldKeepPolling($wrap)) {
				stopAttachmentPoll(id);
				return;
			}
			pollOnce($wrap);
		}, 8000);
	}

	function stopAttachmentPoll(id) {
		if (pollTimers[id]) {
			clearInterval(pollTimers[id]);
			delete pollTimers[id];
		}
	}

	$(function(){
		$('.sba-att-wrap').each(function () {
			var $wrap = $(this);
			if (isMidPipeline($wrap)) {
				startAttachmentPoll($wrap);
			}
		});
	});
})(jQuery);
JS;
}
