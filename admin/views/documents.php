<?php
/**
 * Documents page: upload form, "Update index" with progress, documents table.
 *
 * Rendered by HDS_Admin::render_documents_page(); data in $hds_view
 * (see HDS_Admin::documents_view_data()).
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var array $hds_view */
$hds_rows       = isset( $hds_view['rows'] ) ? (array) $hds_view['rows'] : array();
$hds_total      = isset( $hds_view['total'] ) ? (int) $hds_view['total'] : count( $hds_rows );
$hds_indexed    = isset( $hds_view['indexed'] ) ? (int) $hds_view['indexed'] : 0;
$hds_waiting    = isset( $hds_view['waiting'] ) ? (int) $hds_view['waiting'] : 0;
$hds_stale      = isset( $hds_view['stale'] ) ? (int) $hds_view['stale'] : 0;
$hds_is_demo    = ! empty( $hds_view['is_demo'] );
$hds_demo_limit = isset( $hds_view['demo_limit'] ) ? (int) $hds_view['demo_limit'] : 0;
$hds_over_limit = isset( $hds_view['over_limit'] ) ? (int) $hds_view['over_limit'] : 0;
$hds_max_bytes  = isset( $hds_view['max_bytes'] ) ? (int) $hds_view['max_bytes'] : 0;
$hds_post_max   = isset( $hds_view['post_max'] ) ? (int) $hds_view['post_max'] : 0;
$hds_exts       = isset( $hds_view['extensions'] ) ? (array) $hds_view['extensions'] : array( 'doc', 'docx', 'pdf', 'txt' );
$hds_dotted     = array();
foreach ( $hds_exts as $hds_ext ) {
	$hds_dotted[] = '.' . sanitize_key( (string) $hds_ext );
}
$hds_post_url   = isset( $hds_view['post_action'] ) ? (string) $hds_view['post_action'] : admin_url( 'admin-post.php' );
$hds_dir        = HDS_Admin::text_dir();
$hds_upload_url = isset( $hds_view['upload_action'] ) ? (string) $hds_view['upload_action'] : admin_url( 'admin-post.php?action=hds_upload' );
?>
<div class="wrap hds-wrap hds-page-documents">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'AI Search: Documents', 'smart-document-search' ); ?></h1>
	<?php if ( $hds_is_demo ) : ?>
		<span class="hds-badge hds-badge--demo" title="<?php esc_attr_e( 'Demo version', 'smart-document-search' ); ?>"><?php esc_html_e( 'Demo', 'smart-document-search' ); ?></span>
	<?php endif; ?>
	<hr class="wp-header-end">

	<p class="hds-summary" id="hds-summary" dir="<?php echo esc_attr( $hds_dir ); ?>">
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: number of documents, 2: number of indexed documents. */
				__( 'Documents: %1$s · Indexed: %2$s', 'smart-document-search' ),
				number_format_i18n( $hds_total ),
				number_format_i18n( $hds_indexed )
			)
		);
		if ( $hds_is_demo ) {
			echo ' · ';
			if ( $hds_over_limit > 0 ) {
				echo esc_html(
					sprintf(
						/* translators: 1: demo document limit, 2: number of documents above the limit. */
						__( 'Demo version: only the first %1$d documents are indexed; %2$d more are available in the full version.', 'smart-document-search' ),
						$hds_demo_limit,
						$hds_over_limit
					)
				);
			} else {
				echo esc_html(
					sprintf(
						/* translators: %d: demo document limit. */
						__( 'Demo version: up to %d documents are indexed.', 'smart-document-search' ),
						$hds_demo_limit
					)
				);
			}
		}
		?>
	</p>

	<div class="hds-panels">
		<section class="hds-card hds-card--upload" aria-labelledby="hds-upload-heading">
			<h2 id="hds-upload-heading"><?php esc_html_e( 'Upload documents', 'smart-document-search' ); ?></h2>
			<form id="hds-upload-form" class="hds-upload-form" method="post" action="<?php echo esc_url( $hds_upload_url ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="hds_upload">
				<?php wp_nonce_field( 'hds_upload', '_hds_nonce' ); ?>
				<p>
					<label for="hds-files"><?php esc_html_e( 'Files', 'smart-document-search' ); ?></label><br>
					<input type="file" id="hds-files" name="hds_files[]" multiple required accept="<?php echo esc_attr( implode( ',', $hds_dotted ) ); ?>" aria-describedby="hds-upload-help">
				</p>
				<p class="description" id="hds-upload-help" dir="<?php echo esc_attr( $hds_dir ); ?>">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: list of file types, 2: maximum file size. */
							__( 'Allowed types: %1$s. Maximum file size: %2$s. You can select several files at once.', 'smart-document-search' ),
							implode( ', ', $hds_dotted ),
							size_format( $hds_max_bytes )
						)
					);
					if ( $hds_post_max > 0 ) {
						echo ' ';
						echo esc_html(
							sprintf(
								/* translators: %s: maximum size of one upload request. */
								__( 'Total per upload: up to %s.', 'smart-document-search' ),
								size_format( $hds_post_max )
							)
						);
					}
					?>
				</p>
				<p class="hds-upload-error" id="hds-upload-error" role="alert" dir="<?php echo esc_attr( $hds_dir ); ?>" hidden></p>
				<p class="submit hds-submit">
					<button type="submit" class="button button-primary" id="hds-upload-submit"><?php esc_html_e( 'Upload', 'smart-document-search' ); ?></button>
				</p>
			</form>
		</section>

		<section class="hds-card hds-card--index" aria-labelledby="hds-index-heading">
			<h2 id="hds-index-heading"><?php esc_html_e( 'Search index', 'smart-document-search' ); ?></h2>
			<p class="description" dir="<?php echo esc_attr( $hds_dir ); ?>">
				<?php esc_html_e( 'Indexing extracts the text of new and changed documents, splits it into fragments and creates embeddings with OpenAI. Unchanged documents are skipped at no cost. Keep this page open while indexing runs.', 'smart-document-search' ); ?>
			</p>
			<?php if ( $hds_waiting > 0 ) : ?>
				<div class="notice notice-warning inline hds-notice-reindex">
					<p dir="<?php echo esc_attr( $hds_dir ); ?>">
						<?php
						if ( $hds_stale > 0 ) {
							echo esc_html(
								sprintf(
									/* translators: %d: number of documents. */
									_n(
										'The search index is out of date (for example, after the embedding model was changed): %d document needs to be indexed again. Click “Update index”.',
										'The search index is out of date (for example, after the embedding model was changed): %d documents need to be indexed again. Click “Update index”.',
										$hds_waiting,
										'smart-document-search'
									),
									$hds_waiting
								)
							);
						} else {
							echo esc_html(
								sprintf(
									/* translators: %d: number of documents. */
									_n(
										'%d document is not indexed yet. Click “Update index” to make it searchable.',
										'%d documents are not indexed yet. Click “Update index” to make them searchable.',
										$hds_waiting,
										'smart-document-search'
									),
									$hds_waiting
								)
							);
						}
						?>
					</p>
				</div>
			<?php endif; ?>
			<p>
				<button type="button" class="button button-primary" id="hds-index-button" <?php disabled( 0 === $hds_total ); ?>><?php esc_html_e( 'Update index', 'smart-document-search' ); ?></button>
			</p>
			<div id="hds-progress" class="hds-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-labelledby="hds-index-heading" hidden>
				<div class="hds-progress__bar"></div>
				<span class="hds-progress__text" aria-hidden="true">0%</span>
			</div>
			<p class="hds-progress__current" id="hds-progress-current" dir="<?php echo esc_attr( $hds_dir ); ?>"></p>
			<ul id="hds-index-log" class="hds-log" aria-live="polite" aria-relevant="additions"></ul>
		</section>
	</div>

	<h2 class="hds-table-heading" id="hds-docs-heading"><?php esc_html_e( 'Documents', 'smart-document-search' ); ?></h2>
	<div class="hds-table-wrap" role="region" aria-labelledby="hds-docs-heading" tabindex="0">
		<table id="hds-docs-table" class="wp-list-table widefat striped hds-docs-table">
			<thead>
				<tr>
					<th scope="col" class="hds-col-title"><?php esc_html_e( 'Title', 'smart-document-search' ); ?></th>
					<th scope="col" class="hds-col-file"><?php esc_html_e( 'File', 'smart-document-search' ); ?></th>
					<th scope="col" class="hds-col-size"><?php esc_html_e( 'Size', 'smart-document-search' ); ?></th>
					<th scope="col" class="hds-col-status"><?php esc_html_e( 'Status', 'smart-document-search' ); ?></th>
					<th scope="col" class="hds-col-chunks"><?php esc_html_e( 'Fragments', 'smart-document-search' ); ?></th>
					<th scope="col" class="hds-col-date"><?php esc_html_e( 'Uploaded', 'smart-document-search' ); ?></th>
					<th scope="col" class="hds-col-actions"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'smart-document-search' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $hds_rows ) : ?>
					<tr class="hds-empty">
						<td colspan="7" dir="<?php echo esc_attr( $hds_dir ); ?>"><?php esc_html_e( 'No documents yet. Upload .doc, .docx, .pdf or .txt files above, then click “Update index”.', 'smart-document-search' ); ?></td>
					</tr>
				<?php endif; ?>
				<?php foreach ( $hds_rows as $hds_row ) : ?>
					<?php
					$hds_id       = (int) $hds_row['id'];
					$hds_title_id = 'hds-title-' . $hds_id;
					$hds_form_id  = 'hds-rename-' . $hds_id;
					?>
					<tr data-doc-id="<?php echo esc_attr( (string) $hds_id ); ?>" data-status="<?php echo esc_attr( $hds_row['status'] ); ?>">
						<td class="hds-col-title">
							<strong class="hds-doc-title" dir="auto"><?php echo esc_html( $hds_row['title'] ); ?></strong>
							<div class="hds-row-actions">
								<button type="button" class="button-link hds-rename-toggle" aria-expanded="false" aria-controls="<?php echo esc_attr( $hds_form_id ); ?>"><?php esc_html_e( 'Rename', 'smart-document-search' ); ?></button>
							</div>
							<form id="<?php echo esc_attr( $hds_form_id ); ?>" class="hds-rename-form" method="post" action="<?php echo esc_url( $hds_post_url ); ?>">
								<input type="hidden" name="action" value="hds_rename">
								<input type="hidden" name="doc_id" value="<?php echo esc_attr( (string) $hds_id ); ?>">
								<input type="hidden" name="_hds_nonce" value="<?php echo esc_attr( wp_create_nonce( 'hds_rename_' . $hds_id ) ); ?>">
								<label class="screen-reader-text" for="<?php echo esc_attr( $hds_title_id ); ?>"><?php esc_html_e( 'New title', 'smart-document-search' ); ?></label>
								<input type="text" id="<?php echo esc_attr( $hds_title_id ); ?>" class="hds-rename-input" name="title" value="<?php echo esc_attr( $hds_row['title'] ); ?>" maxlength="255" required dir="auto">
								<button type="submit" class="button button-small"><?php esc_html_e( 'Save', 'smart-document-search' ); ?></button>
								<button type="button" class="button-link hds-rename-cancel"><?php esc_html_e( 'Cancel', 'smart-document-search' ); ?></button>
							</form>
						</td>
						<td class="hds-col-file">
							<span class="hds-file-name" dir="auto"><?php echo esc_html( $hds_row['original_name'] ); ?></span>
							<?php if ( '' !== $hds_row['ext'] ) : ?>
								<span class="hds-ext"><?php echo esc_html( strtoupper( $hds_row['ext'] ) ); ?></span>
							<?php endif; ?>
						</td>
						<td class="hds-col-size"><?php echo esc_html( $hds_row['size_label'] ); ?></td>
						<td class="hds-col-status">
							<span class="<?php echo esc_attr( $hds_row['status_class'] ); ?>" data-hds-status dir="<?php echo esc_attr( $hds_dir ); ?>"><?php echo esc_html( $hds_row['status_label'] ); ?></span>
							<span class="hds-status-message" data-hds-status-message dir="<?php echo esc_attr( $hds_dir ); ?>"<?php echo '' === $hds_row['status_message'] ? ' hidden' : ''; ?>><?php echo esc_html( $hds_row['status_message'] ); ?></span>
						</td>
						<td class="hds-col-chunks" data-hds-chunks><?php echo esc_html( number_format_i18n( (int) $hds_row['chunk_count'] ) ); ?></td>
						<td class="hds-col-date">
							<?php if ( '' !== $hds_row['created_at'] ) : ?>
								<time dir="auto" datetime="<?php echo esc_attr( str_replace( ' ', 'T', $hds_row['created_at'] ) . 'Z' ); ?>"><?php echo esc_html( $hds_row['created_label'] ); ?></time>
							<?php else : ?>
								<?php echo esc_html( $hds_row['created_label'] ); ?>
							<?php endif; ?>
						</td>
						<td class="hds-col-actions">
							<form class="hds-delete-form" method="post" action="<?php echo esc_url( $hds_post_url ); ?>" data-hds-confirm="<?php echo esc_attr( sprintf( /* translators: %s: document title. */ __( 'Delete “%s” and its search index? This cannot be undone.', 'smart-document-search' ), $hds_row['title'] ) ); ?>">
								<input type="hidden" name="action" value="hds_delete">
								<input type="hidden" name="doc_id" value="<?php echo esc_attr( (string) $hds_id ); ?>">
								<input type="hidden" name="_hds_nonce" value="<?php echo esc_attr( wp_create_nonce( 'hds_delete_' . $hds_id ) ); ?>">
								<button type="submit" class="button button-link-delete hds-delete-button" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: document title. */ __( 'Delete “%s”', 'smart-document-search' ), $hds_row['title'] ) ); ?>"><?php esc_html_e( 'Delete', 'smart-document-search' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>
