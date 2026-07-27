/* global jQuery, sbaPdfA11y */
(function ($) {
	'use strict';

	$(function () {
		const nonce = (window.sbaPdfA11y && window.sbaPdfA11y.nonce) || '';
		const ajaxUrl = (window.sbaPdfA11y && window.sbaPdfA11y.ajaxUrl) || window.ajaxurl || '/wp-admin/admin-ajax.php';

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

		function computeStatus(meta) {
			if (!meta || !meta.checked_at) return { level: 'red', label: 'Vyžaduje spracovanie' };
			if (meta.status === 'pending') return { level: 'red', label: 'Čaká na spracovanie…' };
			if (!meta.has_text || !meta.fonts_embedded || !meta.meta_title || !meta.meta_lang) return { level: 'red', label: 'Vyžaduje spracovanie' };
			if (meta.review_required) return { level: 'yellow', label: 'Skontrolujte obrázky' };
			if (meta.alts_confirmed_at) return { level: 'green', label: 'Pripravené' };
			var miss = parseInt(meta.images_without_alt || 0, 10);
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

			var actionDiv = card.find('.sba-card-action');
			if (st.level === 'red') {
				actionDiv.html('<button type="button" class="button button-primary sba-action-btn sba-process-btn" data-id="' + id + '">Spracovať</button>');
			} else if (st.level === 'yellow') {
				var yellowImgCount = card.data('images') || 0;
				actionDiv.html('<button type="button" class="button button-primary sba-action-btn sba-alts-btn" data-id="' + id + '" data-images="' + yellowImgCount + '">Skontrolovať obrázky</button>');
			} else {
				var greenImgCount = card.data('images') || 0;
				actionDiv.html(greenImgCount ? '<button type="button" class="button button-small sba-action-btn sba-alts-btn" data-id="' + id + '" data-images="' + greenImgCount + '">Alt texty</button>' : '');
			}

			if (meta.meta_title !== undefined) {
				card.find('.sba-mtitle-btn').attr('data-title', meta.meta_title || '');
				card.data('title', meta.meta_title || '');
			}

			var totalImgs = (parseInt(meta.images_with_alt || 0, 10) + parseInt(meta.images_without_alt || 0, 10));
			if (totalImgs > 0) {
				card.data('images', totalImgs);
				card.find('.sba-alts-btn').attr('data-images', totalImgs);
			}
		}

		// Single process (Spracovať button)
		$(document).on('click', '.sba-process-btn', function () {
			var id = $(this).data('id');
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
				.fail(function () {
					showNotice('Požiadavka zlyhala.', 'error');
					btn.prop('disabled', false).text('Spracovať');
				});
		});

		// OCR language toggle — clicking 🌐 shows/hides the dropdown
		$(document).on('click', '.sba-lang-btn', function (e) {
			e.stopPropagation();
			var card = $(this).closest('.sba-card');
			var sel = card.find('.sba-row-lang');
			sel.toggle();
			if (sel.is(':visible')) { sel.focus(); }
		});

		$(document).on('click', function (e) {
			if (!$(e.target).closest('.sba-lang-btn, .sba-row-lang').length) {
				$('.sba-row-lang:visible').hide();
			}
		});

		// Queue all incomplete PDFs server-side. Current-page cards are only a view.
		$('#sba-btn-process-all').on('click', function () {
			var btn = $(this);
			btn.prop('disabled', true).text('Zaraďujem…');
			$.post(ajaxUrl, { action: 'sba_pdf_queue_all', nonce: nonce })
				.done(function (r) {
					if (r.success) {
						showNotice('Zaradených ' + (r.data.queued || 0) + ' PDF. Spracovanie pokračuje automaticky na pozadí.', 'success');
					} else {
						showNotice('Nepodarilo sa zaradiť PDF na spracovanie.', 'error');
					}
				})
				.fail(function () { showNotice('Požiadavka zlyhala.', 'error'); })
				.always(function () { btn.prop('disabled', false).text('Spracovať všetky nedokončené'); });
		});

		// ─── Alt text modal ───────────────────────────────────────────────
		var altCurrentId = null;

		function renderAltFields(images, suggestedAlts) {
			var fields = '';
			images.forEach(function (img, n) {
				var thumb = img.thumb
					? '<img src="data:image/jpeg;base64,' + img.thumb + '" alt="" style="max-width:240px;max-height:160px;display:block;margin-bottom:6px;border:1px solid #ddd;border-radius:4px;">'
					: '<div style="width:240px;max-width:100%;height:80px;display:flex;align-items:center;justify-content:center;margin-bottom:6px;border:1px dashed #c3c4c7;border-radius:4px;background:#f6f7f7;color:#646970;font-size:12px;text-align:center;padding:8px;box-sizing:border-box;">Náhľad nedostupný</div>';
				var label = 'Strana ' + img.page + ', obrázok ' + img.index;
				if (img.has_alt) { label += ' <span style="color:#0a3622;font-size:11px;">✓</span>'; }
				var xrefAttr = img.struct_fig_xref ? ' data-struct-xref="' + parseInt(img.struct_fig_xref, 10) + '"' : '';
				var suggested = suggestedAlts && suggestedAlts[n] !== undefined ? suggestedAlts[n] : '';
				fields += '<div style="margin-bottom:16px;padding-bottom:14px;border-bottom:1px solid #f0f0f0;">'
					+ thumb
					+ '<label style="display:block;font-weight:600;margin-bottom:4px;">' + label + '</label>'
					+ '<input type="text" class="widefat sba-alt-input" data-index="' + n + '"' + xrefAttr + ' placeholder="Popíšte obrázok…" value="' + $('<div>').text(suggested).html() + '" style="width:100%;">'
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
			altCurrentId = $(this).data('id');
			var imageCount = parseInt($(this).data('images'), 10) || 1;
			$('#sba-alt-fields').html('<p style="color:#666;font-size:13px;">Načítavam náhľady…</p>');
			$('#sba-alt-modal').addClass('open');
			$.post(ajaxUrl, { action: 'sba_pdf_images', nonce: nonce, id: altCurrentId })
				.done(function (r) {
					var imgs = r.success && r.data && Array.isArray(r.data.images) ? r.data.images : null;
					var altTaggedPdf = !!(r.success && r.data && r.data.tagged_pdf);
					var tagNotice = altTaggedPdf
						? '<p style="font-size:12px;color:#0a3622;background:#d1e7dd;padding:8px 12px;border-radius:4px;margin:0 0 12px;">Alt texty sa zapíšu priamo do PDF (dokument má tagovanú štruktúru).</p>'
						: '<p style="font-size:12px;color:#664d03;background:#fff3cd;padding:8px 12px;border-radius:4px;margin:0 0 12px;">Alt texty sa uložia do databázy (dokument nie je tagovaný).</p>';
					if (imgs && imgs.length) {
						renderAltFields(imgs, r.data.suggested_alts || {});
					} else {
						renderAltFieldsFallback(imageCount);
					}
					$('#sba-alt-fields').prepend(tagNotice);
				})
				.fail(function () { renderAltFieldsFallback(imageCount); });
		});

		$(document).on('click', '#sba-alt-cancel', function () {
			$('#sba-alt-modal').removeClass('open');
		});

		function setAltFieldNote(input, text, isError) {
			var wrap = input.closest('div');
			wrap.find('.sba-alt-note').remove();
			if (text) {
				input.after('<div class="sba-alt-note" style="font-size:11px;margin-top:3px;color:' + (isError ? '#d63638' : '#646970') + ';">' + text + '</div>');
			}
		}

		$(document).on('click', '#sba-alt-suggest', function () {
			var btn = $(this);
			var status = $('#sba-alt-suggest-status');
			var emptyInputs = $('.sba-alt-input').filter(function () { return $(this).val().trim() === ''; });
			var indices = emptyInputs.map(function () { return parseInt($(this).data('index'), 10); }).get();

			if (!indices.length) {
				status.css('color', '#646970').text('Všetky polia už majú text.');
				return;
			}

			btn.prop('disabled', true).text('Generujem návrhy…');
			status.css('color', '#646970').text('');

			$.post(ajaxUrl, { action: 'sba_pdf_suggest_alts', nonce: nonce, id: altCurrentId, indices: indices })
				.done(function (r) {
					if (!r.success) {
						status.css('color', '#d63638').text(r.data || 'Návrh sa nepodaril.');
						return;
					}
					var suggestions = r.data.suggestions || {};
					var filled = 0;
					var failed = 0;
					Object.keys(suggestions).forEach(function (idx) {
						var input = $('.sba-alt-input[data-index="' + idx + '"]');
						if (!input.length) return;
						var s = suggestions[idx];
						if (s.error) {
							failed++;
							setAltFieldNote(input, 'AI návrh zlyhal: ' + s.error, true);
							return;
						}
						filled++;
						input.val(s.alt);
						setAltFieldNote(input, s.decorative ? 'AI návrh: dekoratívny obrázok, bez popisu je to v poriadku.' : 'Návrh AI — skontrolujte pred uložením.', false);
					});

					var remaining = r.data.remaining || 0;
					var msg = 'Navrhnutých ' + filled + (failed ? ', zlyhalo ' + failed : '') + '.';
					if (remaining > 0) {
						msg += ' Zostáva ' + remaining + ' — kliknite znova.';
					}
					status.css('color', failed && !filled ? '#d63638' : '#0a3622').text(msg);
				})
				.fail(function () {
					status.css('color', '#d63638').text('Požiadavka na AI návrh zlyhala.');
				})
				.always(function () {
					btn.prop('disabled', false).text('🤖 Navrhnúť AI alt texty');
				});
		});

		function refreshAttachmentPanel(id, meta) {
			var wrap = $('.sba-att-wrap[data-id="' + id + '"]');
			if (!wrap.length) return;
			var st = computeStatus(meta);
			var colors = { red: '#d63638', yellow: '#996800', green: '#008a20' };
			wrap.find('> strong').css('color', colors[st.level] || '#646970').text(st.label);
			var hint = meta.review_required
				? 'Systém pripravil popisy obrázkov. Opravte len to, čo nesedí.'
				: (st.level === 'green' ? 'Dokument bol pripravený pre povinnosti prístupnosti.' : 'PDF ešte nebolo spracované.');
			wrap.find('> div').first().text(hint);
			wrap.find('.sba-att-review-btn, .sba-att-process-btn').remove();
		}

		$(document).on('click', '#sba-alt-save', function () {
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
					var es = (r.data && r.data.embed_status) || 'wp_only';
					var msg = es === 'embedded'
						? 'Alt texty uložené a zapísané do PDF (' + (r.data.embed_count || 0) + ' položiek).'
						: 'Alt texty uložené v databáze WordPress.';
					showNotice(msg, es === 'embedded' ? 'success' : 'warning');
					// Never mark the card green optimistically. Re-check the PDF so the
					// UI reflects what was actually written into its structure tree.
					$.post(ajaxUrl, { action: 'sba_pdf_check', nonce: nonce, id: altCurrentId })
						.done(function (check) {
							if (check.success) {
								refreshCard(altCurrentId, check.data);
								refreshAttachmentPanel(altCurrentId, check.data);
							}
						});
				} else {
					showNotice('Alt texty sa nepodarilo uložiť.', 'error');
				}
			}).fail(function () { showNotice('Požiadavka zlyhala.', 'error'); });
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

		$(document).on('input', '#sba-mtitle-input', function () {
			updateMtitleCount(this.value.length);
		});

		$(document).on('click', '#sba-mtitle-cancel', function () {
			$('#sba-mtitle-modal').removeClass('open');
		});

		$(document).on('click', '#sba-mtitle-save', function () {
			var title = $('#sba-mtitle-input').val().trim();
			if (!title) { $('#sba-mtitle-status').css('color', '#d63638').text('Zadajte titul.'); return; }
			var saveBtn = $(this).prop('disabled', true).text('Ukladám…');
			$('#sba-mtitle-status').text('');

			$.post(ajaxUrl, { action: 'sba_pdf_save_meta_title', nonce: nonce, id: mtitleCurrentId, title: title })
				.done(function (r) {
					if (r.success) {
						$('#sba-mtitle-modal').removeClass('open');
						var card = $('#sba-row-' + mtitleCurrentId);
						card.find('.sba-mtitle-btn').attr('data-title', title);
						card.data('title', title);
						showNotice('Meta titul uložený.', 'success');
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
})(jQuery);
