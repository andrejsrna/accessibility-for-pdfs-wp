<?php
/**
 * Admin page renderer for Media → PDF Prístupnosť.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function sba_pdf_render_page(): void {
	$per_page    = 100;
	$paged       = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
	$total       = sba_pdf_count_all();
	$total_pages = (int) ceil( $total / $per_page );
	$attachments = sba_pdf_get_all( $paged, $per_page );
	$base_url    = admin_url( 'upload.php?page=sba-pdf-accessibility' );
	?>
	<div class="wrap">
		<h1>PDF Prístupnosť</h1>

		<div id="sba-notice" class="notice" style="display:none; padding:10px 12px; margin:10px 0;"></div>

		<?php $pending_count = sba_pdf_count_pending(); ?>
		<?php if ( $pending_count > 0 ) : ?>
		<div class="sba-bulk-panel">
			<div>
				<strong><?= $pending_count ?> PDF <?= $pending_count === 1 ? 'vyžaduje' : 'vyžadujú' ?> spracovanie</strong>
			</div>
			<button type="button" id="sba-btn-process-all" class="button button-primary sba-bulk-button">
				Spracovať všetky nedokončené
			</button>
			<span id="sba-progress" class="sba-progress" style="display:none;">
				Spracováva sa <strong id="sba-prog-cur">0</strong> / <strong id="sba-prog-tot">0</strong>&hellip;
			</span>
			<div class="sba-global-lang">
				<label for="sba-lang">OCR jazyk:</label>
				<select id="sba-lang">
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
		<div class="tablenav-pages sba-pagination">
			<?php if ( $paged > 1 ) : ?>
				<a href="<?= esc_url( add_query_arg( 'paged', $paged - 1, $base_url ) ) ?>" class="button button-small">‹ Predchádzajúca</a>
			<?php endif; ?>
			<span>Strana <?= $paged ?> / <?= $total_pages ?></span>
			<?php if ( $paged < $total_pages ) : ?>
				<a href="<?= esc_url( add_query_arg( 'paged', $paged + 1, $base_url ) ) ?>" class="button button-small">Nasledujúca ›</a>
			<?php endif; ?>
		</div>
		<?php endif; ?>

		<?php sba_pdf_render_alt_modal(); ?>
		<?php sba_pdf_render_meta_title_modal(); ?>
	</div>
	<?php
}

function sba_pdf_render_alt_modal(): void {
	?>
	<div id="sba-alt-modal" class="sba-modal">
		<div class="sba-modal-box sba-modal-box-scroll">
			<h2>Alt texty pre obrázky</h2>
			<p>Popíšte obsah každého obrázka pre používateľov čítačiek obrazovky.</p>
			<div id="sba-alt-fields"></div>
			<div class="sba-modal-actions">
				<button type="button" id="sba-alt-save" class="button button-primary">Uložiť</button>
				<button type="button" id="sba-alt-cancel" class="button">Zrušiť</button>
			</div>
		</div>
	</div>
	<?php
}

function sba_pdf_render_meta_title_modal(): void {
	?>
	<div id="sba-mtitle-modal" class="sba-modal">
		<div class="sba-modal-box">
			<h2>Upraviť meta titul PDF</h2>
			<p>Titul sa zapíše priamo do metadát PDF súboru a zobrazuje sa v čítačkách a PDF prehliadačoch.</p>
			<input type="text" id="sba-mtitle-input" class="sba-modal-input" placeholder="Zadajte meta titul…">
			<p id="sba-mtitle-hint">
				Odporúčaná dĺžka: do 70 znakov.
				<span id="sba-mtitle-count"></span>
			</p>
			<div class="sba-modal-actions">
				<button type="button" id="sba-mtitle-save" class="button button-primary">Uložiť</button>
				<button type="button" id="sba-mtitle-cancel" class="button">Zrušiť</button>
				<span id="sba-mtitle-status"></span>
			</div>
		</div>
	</div>
	<?php
}
