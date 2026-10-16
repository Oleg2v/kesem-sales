<?php
/**
 * Search form: shortcode [hds_search] and block hds/search.
 *
 * @package SmartDocumentSearch
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Front end.
 *
 * The form markup is rendered on the server (also for the block, which is
 * dynamic); assets/js/hds-search.js enhances every [data-hds-search]
 * element on the page. The configuration (REST URL, nonce, Hebrew strings)
 * is printed once per page as an inline script before hds-search.js.
 */
class HDS_Frontend {

	/**
	 * Shortcode tag.
	 */
	const SHORTCODE = 'hds_search';

	/**
	 * Block name.
	 */
	const BLOCK = 'hds/search';

	/**
	 * Script/style handles.
	 */
	const HANDLE        = 'hds-search';
	const EDITOR_HANDLE = 'hds-block-editor';

	/**
	 * Longest placeholder kept (characters).
	 */
	const PLACEHOLDER_MAX = 150;

	/**
	 * Whether the inline configuration was added in this request.
	 *
	 * @var bool
	 */
	private static $config_added = false;

	/**
	 * Forms rendered in this request (for unique element ids).
	 *
	 * @var int
	 */
	private static $instances = 0;

	/**
	 * Register the shortcode, the assets and the block (init).
	 *
	 * @return void
	 */
	public static function register(): void {
		self::register_assets();
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_style' ) );

		if ( function_exists( 'register_block_type' ) ) {
			register_block_type(
				self::BLOCK,
				array(
					'api_version'     => 2,
					'title'           => __( 'AI Search', 'smart-document-search' ),
					'description'     => __( 'AI search form for the uploaded documents.', 'smart-document-search' ),
					'category'        => 'widgets',
					'icon'            => 'search',
					'editor_script'   => self::EDITOR_HANDLE,
					'style'           => self::HANDLE,
					'attributes'      => array(
						'placeholder' => array(
							'type'    => 'string',
							'default' => '',
						),
					),
					'supports'        => array( 'html' => false ),
					'render_callback' => array( __CLASS__, 'render_block' ),
				)
			);
		}
	}

	/**
	 * Register (not enqueue) the scripts and the style.
	 *
	 * @return void
	 */
	public static function register_assets(): void {
		wp_register_script( self::HANDLE, HDS_PLUGIN_URL . 'assets/js/hds-search.js', array(), HDS_VERSION, true );
		wp_register_style( self::HANDLE, HDS_PLUGIN_URL . 'assets/css/hds-search.css', array(), HDS_VERSION );
		wp_register_script(
			self::EDITOR_HANDLE,
			HDS_PLUGIN_URL . 'assets/js/hds-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n' ),
			HDS_VERSION,
			false
		);
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( self::EDITOR_HANDLE, 'smart-document-search' );
		}
	}

	/**
	 * Enqueue the style in <head> when the current post contains the
	 * shortcode (otherwise it is printed in the footer when the form renders,
	 * which shows the form unstyled for a moment). Blocks need nothing: their
	 * style is handled by WordPress.
	 *
	 * @return void
	 */
	public static function maybe_enqueue_style(): void {
		if ( ! function_exists( 'is_singular' ) || ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( is_object( $post ) && isset( $post->post_content ) && has_shortcode( (string) $post->post_content, self::SHORTCODE ) ) {
			wp_enqueue_style( self::HANDLE );
		}
	}

	/**
	 * Shortcode [hds_search placeholder="..."].
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string HTML.
	 */
	public static function render( $atts = array() ): string {
		$atts = shortcode_atts(
			array( 'placeholder' => '' ),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);
		return self::form( is_scalar( $atts['placeholder'] ) ? (string) $atts['placeholder'] : '' );
	}

	/**
	 * Render callback of the hds/search block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string HTML.
	 */
	public static function render_block( array $attributes ): string {
		$placeholder = isset( $attributes['placeholder'] ) && is_scalar( $attributes['placeholder'] ) ? (string) $attributes['placeholder'] : '';
		return self::form( $placeholder );
	}

	/**
	 * Hebrew interface strings for the script.
	 *
	 * @return array
	 */
	public static function i18n(): array {
		return array(
			'placeholder'  => __( 'הקלידו שאלה או מילות חיפוש…', 'smart-document-search' ),
			'submit'       => __( 'חיפוש', 'smart-document-search' ),
			'label'        => __( 'חיפוש במסמכים', 'smart-document-search' ),
			'loading'      => __( 'מחפש תשובה…', 'smart-document-search' ),
			'answer'       => __( 'תשובה', 'smart-document-search' ),
			'sources'      => __( 'מקורות', 'smart-document-search' ),
			'disclaimer'   => __( 'התשובה מבוססת על המסמכים בלבד ואינה מהווה ייעוץ משפטי.', 'smart-document-search' ),
			'document'     => __( 'מסמך', 'smart-document-search' ),
			'suggestions'  => __( 'הצעות', 'smart-document-search' ),
			/* translators: %d: number of suggestions. */
			'suggestCount' => __( '%d הצעות זמינות. השתמשו בחיצים כדי לבחור.', 'smart-document-search' ),
			'suggestOne'   => __( 'הצעה אחת זמינה. השתמשו בחיצים כדי לבחור.', 'smart-document-search' ),
			'errors'       => array(
				'short'          => __( 'נא להקליד לפחות 2 תווים.', 'smart-document-search' ),
				'long'           => __( 'השאלה ארוכה מדי.', 'smart-document-search' ),
				'rate'           => __( 'נשלחו יותר מדי בקשות. נסו שוב בעוד דקה.', 'smart-document-search' ),
				'generic'        => __( 'אירעה שגיאה. נסו שוב מאוחר יותר.', 'smart-document-search' ),
				'not_configured' => __( 'החיפוש אינו זמין כרגע.', 'smart-document-search' ),
				'no_index'       => __( 'עדיין אין מסמכים זמינים לחיפוש.', 'smart-document-search' ),
				'network'        => __( 'אין חיבור לשרת. בדקו את החיבור ונסו שוב.', 'smart-document-search' ),
				'nonce'          => __( 'פג תוקף הדף. רעננו את הדף ונסו שוב.', 'smart-document-search' ),
			),
		);
	}

	/**
	 * Configuration printed for the script.
	 *
	 * @return array
	 */
	public static function config(): array {
		return array(
			'restUrl'   => esc_url_raw( rest_url( HDS_REST::NS . '/' ) ),
			'nonce'     => HDS_REST::create_nonce(),
			'demo'      => HDS_License::is_demo(),
			'minChars'  => HDS_Answerer::MIN_LEN,
			'maxLength' => HDS_Answerer::MAX_LEN,
			'debounce'  => 200,
			'i18n'      => self::i18n(),
		);
	}

	/**
	 * Enqueue the assets and add the configuration (once per request).
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		if ( function_exists( 'wp_script_is' ) && ! wp_script_is( self::HANDLE, 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );
		if ( self::$config_added ) {
			return;
		}
		$json = wp_json_encode( self::config(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP );
		if ( is_string( $json ) ) {
			wp_add_inline_script( self::HANDLE, 'window.hdsSearch = ' . $json . ';', 'before' );
			self::$config_added = true;
		}
	}

	/**
	 * The form markup.
	 *
	 * @param string $placeholder Custom placeholder ('' = default).
	 * @return string HTML.
	 */
	private static function form( string $placeholder ): string {
		self::enqueue();

		$placeholder = trim( sanitize_text_field( $placeholder ) );
		if ( mb_strlen( $placeholder, 'UTF-8' ) > self::PLACEHOLDER_MAX ) {
			$placeholder = trim( mb_substr( $placeholder, 0, self::PLACEHOLDER_MAX, 'UTF-8' ) );
		}
		if ( '' === $placeholder ) {
			$placeholder = __( 'הקלידו שאלה או מילות חיפוש…', 'smart-document-search' );
		}

		++self::$instances;
		$id      = 'hds-search-' . self::$instances;
		$input   = $id . '-input';
		$listbox = $id . '-listbox';
		$status  = $id . '-status';
		$result  = $id . '-result';
		$demo    = HDS_License::is_demo();

		$html  = '<div class="hds-search' . ( $demo ? ' hds-search--demo' : '' ) . '" id="' . esc_attr( $id ) . '" dir="rtl" lang="he" data-hds-search>';
		$html .= '<form class="hds-form" role="search" action="#" novalidate>';
		$html .= '<label class="hds-label hds-sr-only" for="' . esc_attr( $input ) . '">' . esc_html__( 'חיפוש במסמכים', 'smart-document-search' ) . '</label>';
		$html .= '<div class="hds-field">';
		$html .= '<input class="hds-input" id="' . esc_attr( $input ) . '" type="search" role="combobox"'
			. ' aria-autocomplete="list" aria-expanded="false" aria-controls="' . esc_attr( $listbox ) . '"'
			. ' autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="search"'
			. ' maxlength="' . esc_attr( (string) HDS_Answerer::MAX_LEN ) . '"'
			. ' placeholder="' . esc_attr( $placeholder ) . '">';
		$html .= '<ul class="hds-suggestions" id="' . esc_attr( $listbox ) . '" role="listbox" aria-label="' . esc_attr__( 'הצעות', 'smart-document-search' ) . '" hidden></ul>';
		$html .= '</div>';
		$html .= '<button class="hds-submit" type="submit">' . esc_html__( 'חיפוש', 'smart-document-search' ) . '</button>';
		if ( $demo ) {
			$html .= '<span class="hds-demo-badge">Demo</span>';
		}
		$html .= '</form>';
		$html .= '<div class="hds-status" id="' . esc_attr( $status ) . '" role="status" aria-live="polite"></div>';
		$html .= '<div class="hds-result" id="' . esc_attr( $result ) . '" aria-live="polite" aria-atomic="false"></div>';
		$html .= '<noscript><p class="hds-noscript">' . esc_html__( 'כדי להשתמש בחיפוש יש להפעיל JavaScript בדפדפן.', 'smart-document-search' ) . '</p></noscript>';
		$html .= '</div>';
		return $html;
	}
}
