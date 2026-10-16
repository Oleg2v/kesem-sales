<?php
/**
 * Settings page (Settings API, option hds_settings).
 *
 * Rendered by HDS_Admin::render_settings_page(); data in $hds_view
 * (see HDS_Admin::settings_view_data()). The API key is never printed:
 * only its mask, as a placeholder.
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var array $hds_view */
$hds_settings   = isset( $hds_view['settings'] ) ? (array) $hds_view['settings'] : array();
$hds_key_source = isset( $hds_view['key_source'] ) ? (string) $hds_view['key_source'] : 'none';
$hds_key_mask   = isset( $hds_view['key_mask'] ) ? (string) $hds_view['key_mask'] : '';
$hds_license_ok = ! empty( $hds_view['license_ok'] );
$hds_domain     = isset( $hds_view['domain'] ) ? (string) $hds_view['domain'] : '';
$hds_demo_limit = isset( $hds_view['demo_limit'] ) ? (int) $hds_view['demo_limit'] : 0;
$hds_server_max = isset( $hds_view['server_max'] ) ? (int) $hds_view['server_max'] : 0;
$hds_max_mb     = isset( $hds_view['server_max_mb'] ) ? (int) $hds_view['server_max_mb'] : 1;
$hds_name       = HDS_Settings::OPTION;
$hds_dir        = HDS_Admin::text_dir();
$hds_value      = static function ( $key ) use ( $hds_settings ) {
	return isset( $hds_settings[ $key ] ) && is_scalar( $hds_settings[ $key ] ) ? (string) $hds_settings[ $key ] : '';
};
$hds_embed_list = array( 'text-embedding-3-small', 'text-embedding-3-large', 'text-embedding-ada-002' );
$hds_chat_list  = array( 'gpt-4o-mini', 'gpt-4.1-mini', 'gpt-4.1-nano', 'gpt-4.1', 'gpt-4o' );
?>
<div class="wrap hds-wrap hds-page-settings">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'AI Search: Settings', 'smart-document-search' ); ?></h1>
	<?php if ( ! $hds_license_ok ) : ?>
		<span class="hds-badge hds-badge--demo" title="<?php esc_attr_e( 'Demo version', 'smart-document-search' ); ?>"><?php esc_html_e( 'Demo', 'smart-document-search' ); ?></span>
	<?php endif; ?>
	<hr class="wp-header-end">

	<?php settings_errors(); ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" id="hds-settings-form">
		<?php settings_fields( HDS_Admin::SETTINGS_GROUP ); ?>

		<h2 class="title"><?php esc_html_e( 'OpenAI', 'smart-document-search' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<?php if ( 'constant' === $hds_key_source ) : ?>
						<?php esc_html_e( 'OpenAI API key', 'smart-document-search' ); ?>
					<?php else : ?>
						<label for="hds-api-key"><?php esc_html_e( 'OpenAI API key', 'smart-document-search' ); ?></label>
					<?php endif; ?>
				</th>
				<td>
					<?php if ( 'constant' === $hds_key_source ) : ?>
						<p class="hds-key-constant" dir="<?php echo esc_attr( $hds_dir ); ?>"><strong><?php esc_html_e( 'Defined in wp-config.php (HDS_OPENAI_KEY)', 'smart-document-search' ); ?></strong></p>
						<p dir="<?php echo esc_attr( $hds_dir ); ?>" class="description"><?php esc_html_e( 'To change the key, edit the HDS_OPENAI_KEY line in wp-config.php.', 'smart-document-search' ); ?></p>
					<?php else : ?>
						<input type="password" id="hds-api-key" class="regular-text code" dir="ltr" name="<?php echo esc_attr( $hds_name ); ?>[api_key_new]" value="" autocomplete="new-password" spellcheck="false" autocapitalize="off" placeholder="<?php echo esc_attr( $hds_key_mask ); ?>" aria-describedby="hds-api-key-help">
						<p dir="<?php echo esc_attr( $hds_dir ); ?>" class="description" id="hds-api-key-help">
							<?php
							if ( 'option' === $hds_key_source ) {
								esc_html_e( 'A key is stored (encrypted). Leave the field empty to keep it, or enter a new key to replace it.', 'smart-document-search' );
							} else {
								esc_html_e( 'No key is configured. The key is stored encrypted and is never shown again.', 'smart-document-search' );
							}
							echo ' ';
							esc_html_e( 'Recommended instead: add this line to wp-config.php:', 'smart-document-search' );
							?>
							<code dir="ltr"><?php echo esc_html( "define( 'HDS_OPENAI_KEY', 'sk-...' );" ); ?></code>
						</p>
						<?php if ( 'option' === $hds_key_source ) : ?>
							<p>
								<label for="hds-api-key-remove">
									<input type="checkbox" id="hds-api-key-remove" name="<?php echo esc_attr( $hds_name ); ?>[api_key_remove]" value="1">
									<?php esc_html_e( 'Remove stored key', 'smart-document-search' ); ?>
								</label>
							</p>
						<?php endif; ?>
					<?php endif; ?>
					<p class="hds-test-api">
						<button type="button" class="button" id="hds-test-api" aria-describedby="hds-test-api-help"><?php esc_html_e( 'Test connection', 'smart-document-search' ); ?></button>
						<span id="hds-test-api-result" class="hds-test-result" role="status" aria-live="polite" dir="<?php echo esc_attr( $hds_dir ); ?>"></span>
					</p>
					<p dir="<?php echo esc_attr( $hds_dir ); ?>" class="description" id="hds-test-api-help"><?php esc_html_e( 'Tests the saved key with the saved embedding model. Save your changes first.', 'smart-document-search' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="hds-embedding-model"><?php esc_html_e( 'Embedding model', 'smart-document-search' ); ?></label></th>
				<td>
					<input type="text" id="hds-embedding-model" class="regular-text code" dir="ltr" name="<?php echo esc_attr( $hds_name ); ?>[embedding_model]" value="<?php echo esc_attr( $hds_value( 'embedding_model' ) ); ?>" list="hds-embedding-models" required maxlength="64" spellcheck="false" autocapitalize="off" aria-describedby="hds-embedding-model-help">
					<datalist id="hds-embedding-models">
						<?php foreach ( $hds_embed_list as $hds_model ) : ?>
							<option value="<?php echo esc_attr( $hds_model ); ?>"></option>
						<?php endforeach; ?>
					</datalist>
					<p dir="<?php echo esc_attr( $hds_dir ); ?>" class="description" id="hds-embedding-model-help"><?php esc_html_e( 'Model that turns document fragments and questions into vectors. Changing it requires indexing all documents again (Documents → Update index).', 'smart-document-search' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="hds-chat-model"><?php esc_html_e( 'Answer model', 'smart-document-search' ); ?></label></th>
				<td>
					<input type="text" id="hds-chat-model" class="regular-text code" dir="ltr" name="<?php echo esc_attr( $hds_name ); ?>[chat_model]" value="<?php echo esc_attr( $hds_value( 'chat_model' ) ); ?>" list="hds-chat-models" required maxlength="64" spellcheck="false" autocapitalize="off" aria-describedby="hds-chat-model-help">
					<datalist id="hds-chat-models">
						<?php foreach ( $hds_chat_list as $hds_model ) : ?>
							<option value="<?php echo esc_attr( $hds_model ); ?>"></option>
						<?php endforeach; ?>
					</datalist>
					<p dir="<?php echo esc_attr( $hds_dir ); ?>" class="description" id="hds-chat-model-help"><?php esc_html_e( 'Chat model that writes the short answer from the found fragments.', 'smart-document-search' ); ?></p>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Search', 'smart-document-search' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="hds-top-n"><?php esc_html_e( 'Fragments in context', 'smart-document-search' ); ?></label></th>
				<td>
					<input type="number" id="hds-top-n" class="small-text" name="<?php echo esc_attr( $hds_name ); ?>[top_n]" value="<?php echo esc_attr( $hds_value( 'top_n' ) ); ?>" min="1" max="20" step="1" required aria-describedby="hds-top-n-help">
					<p dir="<?php echo esc_attr( $hds_dir ); ?>" class="description" id="hds-top-n-help"><?php esc_html_e( 'How many of the most relevant fragments are sent to the answer model for each question (1–20). More fragments give fuller answers but cost more.', 'smart-document-search' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="hds-rate-limit"><?php esc_html_e( 'Requests per minute per IP', 'smart-document-search' ); ?></label></th>
				<td>
					<input type="number" id="hds-rate-limit" class="small-text" name="<?php echo esc_attr( $hds_name ); ?>[rate_limit]" value="<?php echo esc_attr( $hds_value( 'rate_limit' ) ); ?>" min="1" max="1000" step="1" required aria-describedby="hds-rate-limit-help">
					<p dir="<?php echo esc_attr( $hds_dir ); ?>" class="description" id="hds-rate-limit-help"><?php esc_html_e( 'Maximum number of questions one visitor IP address can ask per minute (1–1000). Protects your OpenAI budget.', 'smart-document-search' ); ?></p>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'Documents', 'smart-document-search' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="hds-max-file-mb"><?php esc_html_e( 'Maximum file size (MB)', 'smart-document-search' ); ?></label></th>
				<td>
					<input type="number" id="hds-max-file-mb" class="small-text" name="<?php echo esc_attr( $hds_name ); ?>[max_file_mb]" value="<?php echo esc_attr( $hds_value( 'max_file_mb' ) ); ?>" min="1" max="<?php echo esc_attr( (string) max( 1, $hds_max_mb ) ); ?>" step="1" required aria-describedby="hds-max-file-mb-help">
					<p dir="<?php echo esc_attr( $hds_dir ); ?>" class="description" id="hds-max-file-mb-help">
						<?php
						if ( $hds_server_max > 0 ) {
							echo esc_html(
								sprintf(
									/* translators: %s: server upload limit. */
									__( 'Largest document that can be uploaded. The server allows up to %s per file.', 'smart-document-search' ),
									size_format( $hds_server_max )
								)
							);
						} else {
							esc_html_e( 'Largest document that can be uploaded.', 'smart-document-search' );
						}
						?>
					</p>
				</td>
			</tr>
		</table>

		<h2 class="title"><?php esc_html_e( 'License', 'smart-document-search' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="hds-license-key"><?php esc_html_e( 'License key', 'smart-document-search' ); ?></label></th>
				<td>
					<textarea id="hds-license-key" class="large-text code" dir="ltr" name="<?php echo esc_attr( $hds_name ); ?>[license_key]" rows="3" spellcheck="false" autocapitalize="off" aria-describedby="hds-license-status hds-license-domain"><?php echo esc_textarea( $hds_value( 'license_key' ) ); ?></textarea>
					<p class="hds-license-status" id="hds-license-status" dir="<?php echo esc_attr( $hds_dir ); ?>">
						<?php esc_html_e( 'Status:', 'smart-document-search' ); ?>
						<?php if ( $hds_license_ok ) : ?>
							<strong class="hds-license-active">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: site domain. */
										__( 'Active for %s', 'smart-document-search' ),
										$hds_domain
									)
								);
								?>
							</strong>
						<?php else : ?>
							<strong class="hds-license-demo"><?php esc_html_e( 'Demo mode', 'smart-document-search' ); ?></strong>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of documents. */
									__( '(only the first %d documents are indexed)', 'smart-document-search' ),
									$hds_demo_limit
								)
							);
							?>
						<?php endif; ?>
					</p>
					<p dir="<?php echo esc_attr( $hds_dir ); ?>" class="description" id="hds-license-domain">
						<?php esc_html_e( 'Site domain for licensing:', 'smart-document-search' ); ?>
						<code dir="ltr"><?php echo esc_html( '' !== $hds_domain ? $hds_domain : '—' ); ?></code>
						<?php esc_html_e( 'A license key is valid for exactly this domain.', 'smart-document-search' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>
</div>
