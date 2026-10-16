<?php
/**
 * wp-admin UI: menu, pages, assets, notices and the admin-post handlers
 * (upload, delete, rename).
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin pages of the plugin ("AI Search" menu).
 */
class HDS_Admin {

	/**
	 * Capability required for everything in the admin UI.
	 */
	const CAP = 'manage_options';

	/**
	 * Slug of the Documents page (also the top-level menu slug).
	 */
	const PAGE_DOCUMENTS = 'hds-documents';

	/**
	 * Slug of the Settings page.
	 */
	const PAGE_SETTINGS = 'hds-settings';

	/**
	 * Settings API group.
	 */
	const SETTINGS_GROUP = 'hds_settings_group';

	/**
	 * Per-user transient prefix for queued notices.
	 */
	const NOTICES_PREFIX = 'hds_notices_';

	/**
	 * How long queued notices wait for the next page view (seconds).
	 */
	const NOTICES_TTL = 600;

	/**
	 * Most queued notices kept per user.
	 */
	const MAX_NOTICES = 50;

	/**
	 * Notice types accepted by add_notice().
	 */
	const NOTICE_TYPES = array( 'success', 'error', 'warning', 'info' );

	/**
	 * Hook suffix (screen id) => page slug of our pages, filled by admin_menu().
	 *
	 * @var array<string, string>
	 */
	private static $hooks = array();

	/**
	 * Register all admin hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_action( 'admin_post_hds_upload', array( __CLASS__, 'handle_upload' ) );
		add_action( 'admin_post_hds_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_hds_rename', array( __CLASS__, 'handle_rename' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( HDS_PLUGIN_FILE ), array( __CLASS__, 'plugin_action_links' ) );
	}

	/**
	 * Add the "AI Search" menu with its Documents and Settings pages.
	 *
	 * @return void
	 */
	public static function admin_menu(): void {
		$documents = add_menu_page(
			__( 'AI Search – Documents', 'smart-document-search' ),
			__( 'AI Search', 'smart-document-search' ),
			self::CAP,
			self::PAGE_DOCUMENTS,
			array( __CLASS__, 'render_documents_page' ),
			'dashicons-search',
			58
		);
		// Same slug as the parent: renames the first submenu entry to "Documents".
		add_submenu_page(
			self::PAGE_DOCUMENTS,
			__( 'AI Search – Documents', 'smart-document-search' ),
			__( 'Documents', 'smart-document-search' ),
			self::CAP,
			self::PAGE_DOCUMENTS,
			array( __CLASS__, 'render_documents_page' )
		);
		$settings = add_submenu_page(
			self::PAGE_DOCUMENTS,
			__( 'AI Search – Settings', 'smart-document-search' ),
			__( 'Settings', 'smart-document-search' ),
			self::CAP,
			self::PAGE_SETTINGS,
			array( __CLASS__, 'render_settings_page' )
		);

		self::$hooks = array();
		if ( is_string( $documents ) && '' !== $documents ) {
			self::$hooks[ $documents ] = self::PAGE_DOCUMENTS;
		}
		if ( is_string( $settings ) && '' !== $settings ) {
			self::$hooks[ $settings ] = self::PAGE_SETTINGS;
		}
	}

	/**
	 * Register the settings option with its sanitize callback.
	 *
	 * @return void
	 */
	public static function register_settings(): void {
		register_setting(
			self::SETTINGS_GROUP,
			HDS_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'HDS_Settings', 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * "Settings" link on the Plugins screen.
	 *
	 * @param array $links Action links.
	 * @return array
	 */
	public static function plugin_action_links( $links ) {
		$links = is_array( $links ) ? $links : array();
		if ( ! current_user_can( self::CAP ) ) {
			return $links;
		}
		array_unshift(
			$links,
			sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( self::page_url( self::PAGE_SETTINGS ) ),
				esc_html__( 'Settings', 'smart-document-search' )
			)
		);
		return $links;
	}

	/**
	 * URL of one of our pages.
	 *
	 * @param string $page Page slug.
	 * @return string
	 */
	public static function page_url( string $page = self::PAGE_DOCUMENTS ): string {
		return admin_url( 'admin.php?page=' . rawurlencode( $page ) );
	}

	/**
	 * Which of our pages is being shown ('' when none).
	 *
	 * @param string $hook_suffix Hook suffix from admin_enqueue_scripts ('' = current screen).
	 * @return string self::PAGE_DOCUMENTS, self::PAGE_SETTINGS or ''.
	 */
	public static function current_page( string $hook_suffix = '' ): string {
		if ( '' === $hook_suffix && function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( is_object( $screen ) && isset( $screen->id ) ) {
				$hook_suffix = (string) $screen->id;
			}
		}
		if ( '' !== $hook_suffix && self::$hooks ) {
			return isset( self::$hooks[ $hook_suffix ] ) ? self::$hooks[ $hook_suffix ] : '';
		}
		// Menu not registered in this request (or no screen yet): route by the page parameter.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing.
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		return in_array( $page, array( self::PAGE_DOCUMENTS, self::PAGE_SETTINGS ), true ) ? $page : '';
	}

	/**
	 * Enqueue the admin script and style on our pages only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix = '' ): void {
		$page = self::current_page( is_string( $hook_suffix ) ? $hook_suffix : '' );
		if ( '' === $page ) {
			return;
		}

		wp_enqueue_style( 'hds-admin', HDS_PLUGIN_URL . 'assets/css/hds-admin.css', array(), HDS_VERSION );
		wp_enqueue_script( 'hds-admin', HDS_PLUGIN_URL . 'assets/js/hds-admin.js', array(), HDS_VERSION, true );
		wp_add_inline_script(
			'hds-admin',
			'window.hdsAdmin = ' . wp_json_encode( self::script_config( $page ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ) . ';',
			'before'
		);
	}

	/**
	 * Configuration passed to assets/js/hds-admin.js as window.hdsAdmin.
	 *
	 * @param string $page Current page slug.
	 * @return array
	 */
	public static function script_config( string $page ): array {
		$max_bytes = HDS_Settings::max_upload_bytes();
		$post_max  = self::post_max_bytes();
		return array(
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( 'hds_admin' ),
			'page'           => self::PAGE_SETTINGS === $page ? 'settings' : 'documents',
			'maxUploadBytes' => $max_bytes,
			'maxUploadLabel' => self::format_size( $max_bytes ),
			'postMaxBytes'   => $post_max,
			'postMaxLabel'   => $post_max > 0 ? self::format_size( $post_max ) : '',
			'maxRetries'     => 3,
			'textDir'        => self::text_dir(),
			'demoLimit'      => (int) HDS_License::DEMO_DOC_LIMIT,
			'i18n'           => array(
				/* translators: Shown when leaving the page while indexing runs. */
				'confirmLeave'   => __( 'Indexing is still running. If you leave now it stops; you can resume it later with “Update index”.', 'smart-document-search' ),
				'starting'       => __( 'Preparing the index…', 'smart-document-search' ),
				/* translators: %d: number of documents. */
				'queued'         => __( 'Documents to index: %d.', 'smart-document-search' ),
				/* translators: %d: number of documents. */
				'skipped'        => __( 'Unchanged documents skipped (already up to date): %d.', 'smart-document-search' ),
				/* translators: %1$d: number of documents, %2$d: demo document limit. */
				'locked'         => __( 'Documents not indexed in the demo version: %1$d (only the first %2$d documents are indexed).', 'smart-document-search' ),
				'nothingToDo'    => __( 'All documents are up to date. Nothing to index.', 'smart-document-search' ),
				/* translators: %s: document title. */
				'current'        => __( 'Processing: %s', 'smart-document-search' ),
				/* translators: %d: percentage. */
				'percent'        => __( '%d%%', 'smart-document-search' ),
				'done'           => __( 'Indexing complete.', 'smart-document-search' ),
				/* translators: %1$d: seconds, %2$d: attempt number, %3$d: maximum attempts. */
				'retrying'       => __( 'The request failed. Retrying in %1$d s (attempt %2$d of %3$d)…', 'smart-document-search' ),
				'resumable'      => __( 'Indexing paused. Click “Update index” to continue where it stopped.', 'smart-document-search' ),
				'fatal'          => __( 'Indexing stopped. Fix the problem above, then click “Update index” to continue.', 'smart-document-search' ),
				'networkError'   => __( 'The server did not respond. Check your connection and click “Update index” to resume.', 'smart-document-search' ),
				'sessionExpired' => __( 'Your session has expired. Reload the page and try again.', 'smart-document-search' ),
				'waiting'        => __( 'Waiting for the previous request to finish on the server…', 'smart-document-search' ),
				'stalled'        => __( 'Indexing is not making progress. Reload the page and click “Update index” to resume.', 'smart-document-search' ),
				'genericError'   => __( 'The request failed. Please try again.', 'smart-document-search' ),
				'running'        => __( 'Indexing…', 'smart-document-search' ),
				'uploading'      => __( 'Uploading…', 'smart-document-search' ),
				'noFiles'        => __( 'Choose at least one file to upload.', 'smart-document-search' ),
				/* translators: 1: file name, 2: size limit. */
				'fileTooLarge'   => __( '%1$s is larger than the %2$s limit.', 'smart-document-search' ),
				/* translators: %s: size limit. */
				'totalTooLarge'  => __( 'The selected files are too large to upload together (server limit per upload: %s). Upload fewer files at once.', 'smart-document-search' ),
				'testing'        => __( 'Testing the connection…', 'smart-document-search' ),
			),
		);
	}

	/**
	 * Text direction of the plugin's own sentences: 'ltr' while the admin
	 * strings are the untranslated English source (keeps punctuation right in
	 * an RTL admin), 'auto' once a translation is loaded.
	 *
	 * @return string 'ltr' or 'auto'.
	 */
	public static function text_dir(): string {
		return 'Update index' === __( 'Update index', 'smart-document-search' ) ? 'ltr' : 'auto';
	}

	/**
	 * Largest request body PHP accepts (post_max_size) in bytes; 0 = unlimited/unknown.
	 *
	 * @return int
	 */
	public static function post_max_bytes(): int {
		$raw = (string) ini_get( 'post_max_size' );
		if ( '' === trim( $raw ) ) {
			return 0;
		}
		$bytes = function_exists( 'wp_convert_hr_to_bytes' ) ? (int) wp_convert_hr_to_bytes( $raw ) : (int) $raw;
		return max( 0, $bytes );
	}

	/* ---------------------------------------------------------------------
	 * Notices
	 * ------------------------------------------------------------------- */

	/**
	 * Queue a notice for the current user's next page view.
	 *
	 * @param string $type    success|error|warning|info.
	 * @param string $message Plain text (escaped on output).
	 * @return void
	 */
	public static function add_notice( string $type, string $message ): void {
		$message = trim( $message );
		if ( '' === $message ) {
			return;
		}
		$notices   = self::get_queued_notices();
		$notices[] = array(
			'type'    => in_array( $type, self::NOTICE_TYPES, true ) ? $type : 'info',
			'message' => $message,
		);
		if ( count( $notices ) > self::MAX_NOTICES ) {
			$notices = array_slice( $notices, -self::MAX_NOTICES );
		}
		set_transient( self::notices_key(), $notices, self::NOTICES_TTL );
	}

	/**
	 * Return and forget the current user's queued notices.
	 *
	 * @return array[] List of {type, message}.
	 */
	public static function pop_notices(): array {
		$notices = self::get_queued_notices();
		if ( $notices ) {
			delete_transient( self::notices_key() );
		}
		return $notices;
	}

	/**
	 * The current user's queued notices (validated).
	 *
	 * @return array[]
	 */
	private static function get_queued_notices(): array {
		$stored = get_transient( self::notices_key() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$out = array();
		foreach ( $stored as $notice ) {
			if ( is_array( $notice ) && isset( $notice['type'], $notice['message'] ) && is_string( $notice['message'] ) ) {
				$out[] = array(
					'type'    => in_array( $notice['type'], self::NOTICE_TYPES, true ) ? $notice['type'] : 'info',
					'message' => $notice['message'],
				);
			}
		}
		return $out;
	}

	/**
	 * Transient name of the current user's notices.
	 *
	 * @return string
	 */
	private static function notices_key(): string {
		return self::NOTICES_PREFIX . (int) get_current_user_id();
	}

	/**
	 * admin_notices callback: demo notice, missing API key warning and the
	 * queued notices, on our pages only.
	 *
	 * @return void
	 */
	public static function admin_notices(): void {
		if ( '' === self::current_page() || ! current_user_can( self::CAP ) ) {
			return;
		}

		if ( HDS_License::is_demo() ) {
			printf(
				'<div class="notice notice-info hds-notice-demo"><p dir="%4$s">%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of documents indexed in the demo version. */
						__( 'Demo version: only the first %d documents are indexed. Enter a license key in Settings to unlock the full version.', 'smart-document-search' ),
						HDS_License::DEMO_DOC_LIMIT
					)
				),
				esc_url( self::page_url( self::PAGE_SETTINGS ) . '#hds-license-key' ),
				esc_html__( 'Open Settings', 'smart-document-search' ),
				esc_attr( self::text_dir() )
			);
		}

		if ( 'none' === HDS_Settings::api_key_source() ) {
			printf(
				'<div class="notice notice-warning hds-notice-api-key"><p dir="%7$s"><strong>%1$s</strong> %2$s <code dir="ltr">%3$s</code> %4$s <a href="%5$s">%6$s</a></p></div>',
				esc_html__( 'OpenAI API key is not configured.', 'smart-document-search' ),
				esc_html__( 'Indexing and search need it. Recommended: add this line to wp-config.php:', 'smart-document-search' ),
				esc_html( "define( 'HDS_OPENAI_KEY', 'sk-...' );" ),
				esc_html__( 'Alternatively, enter the key in Settings.', 'smart-document-search' ),
				esc_url( self::page_url( self::PAGE_SETTINGS ) . '#hds-api-key' ),
				esc_html__( 'Open Settings', 'smart-document-search' ),
				esc_attr( self::text_dir() )
			);
		}

		self::render_notices( self::pop_notices() );
	}

	/**
	 * Print notices; consecutive messages of one type share one box.
	 *
	 * @param array[] $notices List of {type, message}.
	 * @return void
	 */
	public static function render_notices( array $notices ): void {
		$dir    = self::text_dir();
		$groups = array();
		foreach ( $notices as $notice ) {
			$last = count( $groups ) - 1;
			if ( $last >= 0 && $groups[ $last ]['type'] === $notice['type'] ) {
				$groups[ $last ]['messages'][] = $notice['message'];
			} else {
				$groups[] = array(
					'type'     => $notice['type'],
					'messages' => array( $notice['message'] ),
				);
			}
		}

		foreach ( $groups as $group ) {
			echo '<div class="notice notice-' . esc_attr( $group['type'] ) . ' is-dismissible hds-notice">';
			if ( 1 === count( $group['messages'] ) ) {
				echo '<p dir="' . esc_attr( $dir ) . '">' . esc_html( $group['messages'][0] ) . '</p>';
			} else {
				echo '<ul class="hds-notice-list" dir="' . esc_attr( $dir ) . '">';
				foreach ( $group['messages'] as $message ) {
					echo '<li>' . esc_html( $message ) . '</li>';
				}
				echo '</ul>';
			}
			echo '</div>';
		}
	}

	/* ---------------------------------------------------------------------
	 * Pages
	 * ------------------------------------------------------------------- */

	/**
	 * Documents page.
	 *
	 * @return void
	 */
	public static function render_documents_page(): void {
		self::require_capability();
		self::render_view( 'documents', self::documents_view_data() );
	}

	/**
	 * Settings page.
	 *
	 * @return void
	 */
	public static function render_settings_page(): void {
		self::require_capability();
		self::render_view( 'settings', self::settings_view_data() );
	}

	/**
	 * Data for admin/views/documents.php.
	 *
	 * @return array
	 */
	public static function documents_view_data(): array {
		$docs    = HDS_Documents::all();
		$by_stat = HDS_Documents::count_by_status();
		$is_demo = HDS_License::is_demo();
		$total   = count( $docs );
		$stale   = 0;
		$waiting = 0;
		// In demo mode documents beyond the limit are never indexed: not "waiting".
		$allowed = $is_demo ? array_flip( array_map( 'intval', (array) HDS_Indexer::demo_allowed_ids() ) ) : null;

		$rows = array();
		foreach ( $docs as $doc ) {
			$row    = self::document_row( $doc );
			$rows[] = $row;
			if ( null !== $allowed && ! isset( $allowed[ $row['id'] ] ) ) {
				continue;
			}
			if ( in_array( $row['status'], array( 'pending', 'processing' ), true ) ) {
				++$waiting;
				if ( $row['chunk_count'] > 0 || '' !== (string) ( isset( $doc['index_hash'] ) ? $doc['index_hash'] : '' ) ) {
					++$stale;
				}
			}
		}

		return array(
			'rows'          => $rows,
			'total'         => $total,
			'indexed'       => isset( $by_stat['indexed'] ) ? (int) $by_stat['indexed'] : 0,
			'waiting'       => $waiting,
			'stale'         => $stale,
			'is_demo'       => $is_demo,
			'demo_limit'    => (int) HDS_License::DEMO_DOC_LIMIT,
			'over_limit'    => $is_demo ? max( 0, $total - (int) HDS_License::DEMO_DOC_LIMIT ) : 0,
			'max_bytes'     => HDS_Settings::max_upload_bytes(),
			'post_max'      => self::post_max_bytes(),
			'extensions'    => self::supported_extensions(),
			'upload_action' => admin_url( 'admin-post.php?action=hds_upload' ),
			'post_action'   => admin_url( 'admin-post.php' ),
			'has_api_key'   => 'none' !== HDS_Settings::api_key_source(),
		);
	}

	/**
	 * One documents table row, ready for the view.
	 *
	 * @param array $doc Row from HDS_Documents.
	 * @return array
	 */
	public static function document_row( array $doc ): array {
		$status  = isset( $doc['status'] ) ? (string) $doc['status'] : 'pending';
		$message = isset( $doc['status_message'] ) && is_scalar( $doc['status_message'] ) ? trim( (string) $doc['status_message'] ) : '';
		$label   = (string) HDS_Indexer::status_label( $status, $message );
		if ( '' !== $message && ( $message === $label || false !== strpos( $label, $message ) ) ) {
			$message = ''; // Already part of the label.
		}
		$ext  = isset( $doc['ext'] ) ? strtolower( (string) $doc['ext'] ) : '';
		$size = isset( $doc['file_size'] ) ? (int) $doc['file_size'] : 0;

		return array(
			'id'             => isset( $doc['id'] ) ? (int) $doc['id'] : 0,
			'title'          => isset( $doc['title'] ) ? (string) $doc['title'] : '',
			'original_name'  => isset( $doc['original_name'] ) ? (string) $doc['original_name'] : '',
			'ext'            => $ext,
			'size'           => $size,
			'size_label'     => self::format_size( $size ),
			'status'         => $status,
			'status_class'   => self::status_class( $status ),
			'status_label'   => $label,
			'status_message' => $message,
			'chunk_count'    => isset( $doc['chunk_count'] ) ? (int) $doc['chunk_count'] : 0,
			'created_at'     => isset( $doc['created_at'] ) ? (string) $doc['created_at'] : '',
			'created_label'  => self::format_gmt_date( isset( $doc['created_at'] ) ? (string) $doc['created_at'] : '' ),
		);
	}

	/**
	 * CSS modifier class of a status pill.
	 *
	 * @param string $status Document status.
	 * @return string
	 */
	public static function status_class( string $status ): string {
		$slug = preg_replace( '/[^a-z0-9_]/', '', strtolower( $status ) );
		return 'hds-status hds-status--' . ( '' !== $slug ? $slug : 'unknown' );
	}

	/**
	 * Human-readable file size.
	 *
	 * @param int $bytes Size in bytes.
	 * @return string
	 */
	public static function format_size( int $bytes ): string {
		$label = size_format( max( 0, $bytes ), $bytes >= 1048576 ? 1 : 0 );
		return is_string( $label ) && '' !== $label ? $label : '0 B';
	}

	/**
	 * Localized date/time of a GMT MySQL datetime.
	 *
	 * @param string $gmt 'Y-m-d H:i:s' in UTC.
	 * @return string '—' when empty or invalid.
	 */
	public static function format_gmt_date( string $gmt ): string {
		if ( '' === $gmt || 0 === strpos( $gmt, '0000-00-00' ) || false === strtotime( $gmt . ' UTC' ) ) {
			return '—';
		}
		$format = trim( get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' ) );
		$local  = get_date_from_gmt( $gmt, 'Y-m-d H:i:s' );
		$stamp  = strtotime( (string) $local );
		if ( false === $stamp ) {
			return '—';
		}
		return (string) date_i18n( '' !== $format ? $format : 'Y-m-d H:i', $stamp );
	}

	/**
	 * Supported upload extensions (without dots).
	 *
	 * @return string[]
	 */
	public static function supported_extensions(): array {
		$known = array( 'doc', 'docx', 'pdf', 'txt' );
		if ( class_exists( 'HDS_Extractor_Factory' ) ) {
			$exts = array_values( array_intersect( HDS_Extractor_Factory::supported_extensions(), $known ) );
			if ( $exts ) {
				return $exts;
			}
		}
		return $known;
	}

	/**
	 * Data for admin/views/settings.php.
	 *
	 * @return array
	 */
	public static function settings_view_data(): array {
		$server_max = (int) wp_max_upload_size();
		return array(
			'settings'      => HDS_Settings::all(),
			'key_source'    => HDS_Settings::api_key_source(),
			'key_mask'      => HDS_Settings::mask_api_key(),
			'license_ok'    => HDS_License::is_active(),
			'domain'        => HDS_License::current_domain(),
			'demo_limit'    => (int) HDS_License::DEMO_DOC_LIMIT,
			'server_max'    => $server_max,
			'server_max_mb' => max( 1, (int) floor( $server_max / 1048576 ) ),
		);
	}

	/**
	 * Include a view template. The template reads its data from $hds_view.
	 *
	 * @param string $name View name (admin/views/{name}.php).
	 * @param array  $data View data.
	 * @return void
	 */
	public static function render_view( string $name, array $data ): void {
		$file = HDS_PLUGIN_DIR . 'admin/views/' . basename( $name ) . '.php';
		if ( ! is_readable( $file ) ) {
			return;
		}
		$hds_view = $data;
		include $file;
	}

	/* ---------------------------------------------------------------------
	 * admin-post handlers
	 * ------------------------------------------------------------------- */

	/**
	 * Upload one or more documents (admin-post.php?action=hds_upload).
	 *
	 * @return void
	 */
	public static function handle_upload(): void {
		self::require_capability();
		self::require_post();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nothing is changed here: the body was discarded by PHP.
		if ( empty( $_POST ) && empty( $_FILES ) ) {
			self::add_notice( 'error', self::empty_post_message() );
			self::redirect();
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		check_admin_referer( 'hds_upload', '_hds_nonce' );

		$files = self::normalize_files( isset( $_FILES['hds_files'] ) ? $_FILES['hds_files'] : null ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated by HDS_Storage::store_upload().
		if ( ! $files ) {
			self::add_notice( 'error', __( 'No files were selected. Choose one or more .doc, .docx, .pdf or .txt files.', 'smart-document-search' ) );
			self::redirect();
			return;
		}

		$result = self::process_uploads( $files, HDS_Settings::max_upload_bytes() );
		if ( $result['added'] > 0 ) {
			self::add_notice(
				'success',
				sprintf(
					/* translators: %d: number of documents. */
					_n(
						'%d document uploaded. Click “Update index” to make it searchable.',
						'%d documents uploaded. Click “Update index” to make them searchable.',
						$result['added'],
						'smart-document-search'
					),
					$result['added']
				)
			);
		}
		foreach ( $result['notices'] as $notice ) {
			self::add_notice( $notice['type'], $notice['message'] );
		}
		self::redirect();
	}

	/**
	 * Store and register uploaded files.
	 *
	 * @param array[] $files     Normalized $_FILES entries.
	 * @param int     $max_bytes Size limit per file.
	 * @return array {added:int, ids:int[], notices:array[]} (notices = per-file errors/warnings).
	 */
	public static function process_uploads( array $files, int $max_bytes ): array {
		$added   = 0;
		$ids     = array();
		$notices = array();

		foreach ( $files as $file ) {
			$label  = self::file_label( isset( $file['name'] ) ? (string) $file['name'] : '' );
			$stored = null;
			$id     = 0;
			try {
				$stored = HDS_Storage::store_upload( $file, $max_bytes );
				if ( is_wp_error( $stored ) ) {
					$notices[] = array(
						'type'    => 'error',
						'message' => $stored->get_error_message(),
					);
					continue;
				}

				$existing = HDS_Documents::find_by_hash( (string) $stored['file_hash'] );
				if ( is_array( $existing ) && $existing ) {
					HDS_Storage::delete_file( (string) $stored['file_name'] );
					$notices[] = array(
						'type'    => 'warning',
						'message' => sprintf(
							/* translators: 1: uploaded file name, 2: title of the existing document. */
							__( '“%1$s” was skipped: the same file is already uploaded as “%2$s”.', 'smart-document-search' ),
							$label,
							isset( $existing['title'] ) ? (string) $existing['title'] : ''
						),
					);
					continue;
				}

				$id = (int) HDS_Documents::insert( $stored );
				if ( $id <= 0 ) {
					HDS_Storage::delete_file( (string) $stored['file_name'] );
					$notices[] = array(
						'type'    => 'error',
						'message' => sprintf(
							/* translators: %s: file name. */
							__( '“%s” could not be saved to the database. Please try again.', 'smart-document-search' ),
							$label
						),
					);
					continue;
				}
				++$added;
				$ids[] = $id;
			} catch ( \Throwable $e ) {
				if ( is_array( $stored ) && ! empty( $stored['file_name'] ) && $id <= 0 ) {
					HDS_Storage::delete_file( (string) $stored['file_name'] );
				}
				$notices[] = array(
					'type'    => 'error',
					'message' => sprintf(
						/* translators: %s: file name. */
						__( '“%s” could not be uploaded because of an unexpected error. Please try again.', 'smart-document-search' ),
						$label
					),
				);
			}
		}

		return array(
			'added'   => $added,
			'ids'     => $ids,
			'notices' => $notices,
		);
	}

	/**
	 * Turn the multi-file $_FILES['hds_files'] structure into a list of
	 * {name, type, tmp_name, error, size}. Empty file inputs are dropped.
	 *
	 * @param mixed $files $_FILES['hds_files'] (or null).
	 * @return array[]
	 */
	public static function normalize_files( $files ): array {
		if ( ! is_array( $files ) || ! isset( $files['name'] ) ) {
			return array();
		}

		if ( ! is_array( $files['name'] ) ) {
			$list = array( $files );
		} else {
			$list = array();
			foreach ( $files['name'] as $i => $name ) {
				$list[] = array(
					'name'     => $name,
					'type'     => isset( $files['type'][ $i ] ) ? $files['type'][ $i ] : '',
					'tmp_name' => isset( $files['tmp_name'][ $i ] ) ? $files['tmp_name'][ $i ] : '',
					'error'    => isset( $files['error'][ $i ] ) ? $files['error'][ $i ] : UPLOAD_ERR_NO_FILE,
					'size'     => isset( $files['size'][ $i ] ) ? $files['size'][ $i ] : 0,
				);
			}
		}

		$out = array();
		foreach ( $list as $file ) {
			// Nested arrays (hds_files[a][b]) are not produced by our form.
			if ( ! is_scalar( $file['name'] ) || ! is_scalar( $file['tmp_name'] ) || ! is_scalar( $file['error'] ) ) {
				continue;
			}
			$entry = array(
				'name'     => (string) $file['name'],
				'type'     => is_scalar( $file['type'] ) ? (string) $file['type'] : '',
				'tmp_name' => (string) $file['tmp_name'],
				'error'    => (int) $file['error'],
				'size'     => is_scalar( $file['size'] ) ? (int) $file['size'] : 0,
			);
			if ( UPLOAD_ERR_NO_FILE === $entry['error'] && '' === $entry['name'] ) {
				continue;
			}
			$out[] = $entry;
		}
		return $out;
	}

	/**
	 * Message for a POST whose body PHP discarded (larger than post_max_size).
	 *
	 * @return string
	 */
	public static function empty_post_message(): string {
		$length   = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
		$post_max = self::post_max_bytes();
		if ( $post_max > 0 && $length > $post_max ) {
			return sprintf(
				/* translators: 1: size of the upload, 2: server limit. */
				__( 'The upload (%1$s) is larger than the server accepts in one request (%2$s, PHP post_max_size). Upload fewer or smaller files at once, or ask your hosting provider to raise the limit.', 'smart-document-search' ),
				self::format_size( $length ),
				self::format_size( $post_max )
			);
		}
		return __( 'No files were received. The upload may be larger than the server accepts in one request (PHP post_max_size). Upload fewer or smaller files at once.', 'smart-document-search' );
	}

	/**
	 * Delete a document (admin-post.php, action hds_delete).
	 *
	 * @return void
	 */
	public static function handle_delete(): void {
		self::require_capability();
		self::require_post();
		$id = self::posted_doc_id();
		check_admin_referer( 'hds_delete_' . $id, '_hds_nonce' );

		$doc = $id > 0 ? HDS_Documents::get( $id ) : null;
		if ( ! is_array( $doc ) ) {
			self::add_notice( 'error', __( 'The document was not found. It may have been deleted already.', 'smart-document-search' ) );
			self::redirect();
			return;
		}

		$title = isset( $doc['title'] ) ? (string) $doc['title'] : '';
		try {
			$deleted = (bool) HDS_Documents::delete( $id );
		} catch ( \Throwable $e ) {
			$deleted = false;
		}
		if ( $deleted ) {
			/* translators: %s: document title. */
			self::add_notice( 'success', sprintf( __( 'Document “%s” deleted.', 'smart-document-search' ), $title ) );
		} else {
			/* translators: %s: document title. */
			self::add_notice( 'error', sprintf( __( 'Document “%s” could not be deleted. Please try again.', 'smart-document-search' ), $title ) );
		}
		self::redirect();
	}

	/**
	 * Rename a document (admin-post.php, action hds_rename).
	 *
	 * @return void
	 */
	public static function handle_rename(): void {
		self::require_capability();
		self::require_post();
		$id = self::posted_doc_id();
		check_admin_referer( 'hds_rename_' . $id, '_hds_nonce' );

		$raw   = isset( $_POST['title'] ) && is_string( $_POST['title'] ) ? wp_unslash( $_POST['title'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below.
		$title = self::sanitize_title( $raw );
		if ( '' === $title ) {
			self::add_notice( 'error', __( 'The title cannot be empty.', 'smart-document-search' ) );
			self::redirect();
			return;
		}

		$doc = $id > 0 ? HDS_Documents::get( $id ) : null;
		if ( ! is_array( $doc ) ) {
			self::add_notice( 'error', __( 'The document was not found. It may have been deleted.', 'smart-document-search' ) );
			self::redirect();
			return;
		}

		if ( isset( $doc['title'] ) && $title === (string) $doc['title'] ) {
			self::add_notice( 'info', __( 'The title was not changed.', 'smart-document-search' ) );
			self::redirect();
			return;
		}

		try {
			$updated = (bool) HDS_Documents::update( $id, array( 'title' => $title ) );
			if ( $updated ) {
				// Titles appear in suggestions, sources and cached answers.
				HDS_Suggest::rebuild();
				HDS_Vector_Store::bump_version();
			}
		} catch ( \Throwable $e ) {
			$updated = false;
		}

		if ( $updated ) {
			/* translators: %s: new document title. */
			self::add_notice( 'success', sprintf( __( 'Document renamed to “%s”.', 'smart-document-search' ), $title ) );
		} else {
			self::add_notice( 'error', __( 'The document could not be renamed. Please try again.', 'smart-document-search' ) );
		}
		self::redirect();
	}

	/**
	 * Sanitize a document title: single line, no tags, at most 255 characters.
	 *
	 * @param string $raw Unslashed input.
	 * @return string
	 */
	public static function sanitize_title( string $raw ): string {
		$title = sanitize_text_field( $raw );
		if ( mb_strlen( $title, 'UTF-8' ) > 255 ) {
			$title = rtrim( mb_substr( $title, 0, 255, 'UTF-8' ) );
		}
		return $title;
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Stop unless the user may manage the plugin.
	 *
	 * @return void
	 */
	private static function require_capability(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die(
				esc_html__( 'Sorry, you are not allowed to manage AI Search.', 'smart-document-search' ),
				esc_html__( 'Forbidden', 'smart-document-search' ),
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Stop unless the request is a POST (state changes never run on GET).
	 *
	 * @return void
	 */
	private static function require_post(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'POST' !== $method ) {
			wp_die(
				esc_html__( 'This action requires a form submission.', 'smart-document-search' ),
				esc_html__( 'Method not allowed', 'smart-document-search' ),
				array( 'response' => 405 )
			);
		}
	}

	/**
	 * Document id from the submitted form.
	 *
	 * @return int
	 */
	private static function posted_doc_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The id is part of the nonce action checked right after.
		return isset( $_POST['doc_id'] ) && is_scalar( $_POST['doc_id'] ) ? absint( wp_unslash( $_POST['doc_id'] ) ) : 0;
	}

	/**
	 * Short, single-line file name for messages.
	 *
	 * @param string $name Client file name.
	 * @return string
	 */
	private static function file_label( string $name ): string {
		$name = str_replace( '\\', '/', $name );
		$pos  = strrpos( $name, '/' );
		if ( false !== $pos ) {
			$name = substr( $name, $pos + 1 );
		}
		$name = sanitize_text_field( $name );
		if ( mb_strlen( $name, 'UTF-8' ) > 120 ) {
			$name = mb_substr( $name, 0, 119, 'UTF-8' ) . '…';
		}
		return '' !== $name ? $name : __( 'The file', 'smart-document-search' );
	}

	/**
	 * Redirect back to the Documents page and end the request.
	 *
	 * @return void
	 */
	private static function redirect(): void {
		wp_safe_redirect( self::page_url( self::PAGE_DOCUMENTS ), 303 );
		exit;
	}
}
