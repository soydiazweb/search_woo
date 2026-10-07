<?php

defined( 'ABSPATH' ) || exit;

final class Search_Woo {
	private static ?self $instance = null;
	private array $settings = array();
	private bool $search_filtering = false;
	private array $search_cache = array();
	private bool $is_search_request = false;
	private string $search_request_term = '';
	private int $search_request_page = 1;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings = wp_parse_args( (array) get_option( 'swoo_settings', array() ), self::defaults() );
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_swoo_reindex', array( $this, 'admin_reindex' ) );
		add_action( 'admin_post_swoo_export_csv', array( $this, 'export_csv' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'wc_ajax_swoo_search', array( $this, 'ajax_search' ) );
		add_action( 'wc_ajax_nopriv_swoo_search', array( $this, 'ajax_search' ) );
		add_action( 'wp_ajax_swoo_search', array( $this, 'ajax_search' ) );
		add_action( 'wp_ajax_nopriv_swoo_search', array( $this, 'ajax_search' ) );
		add_action( 'wc_ajax_swoo_click', array( $this, 'ajax_click' ) );
		add_action( 'wc_ajax_nopriv_swoo_click', array( $this, 'ajax_click' ) );
		add_action( 'wp_ajax_swoo_click', array( $this, 'ajax_click' ) );
		add_action( 'wp_ajax_nopriv_swoo_click', array( $this, 'ajax_click' ) );
		add_action( 'save_post_product', array( $this, 'queue_product' ), 30, 1 );
		add_action( 'save_post_product_variation', array( $this, 'queue_variation' ), 30, 1 );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'queue_product' ), 30, 1 );
		add_action( 'woocommerce_variation_set_stock_status', array( $this, 'queue_variation' ), 30, 1 );
		add_action( 'before_delete_post', array( $this, 'delete_document' ) );
		add_action( 'swoo_index_product', array( $this, 'index_product' ) );
		add_action( 'swoo_daily_maintenance', array( $this, 'daily_maintenance' ) );
		add_action( 'add_meta_boxes_product', array( $this, 'alias_meta_box' ) );
		add_action( 'save_post_product', array( $this, 'save_aliases' ), 20, 2 );
		// Run after WooCommerce, Elementor and the child theme so none of them can
		// replace the ranked IDs used by the autocomplete/results-page engine.
		add_action( 'pre_get_posts', array( $this, 'unify_results_page' ), 999 );
		add_filter( 'posts_search', array( $this, 'remove_core_search_clause' ), 20, 2 );
		add_filter( 'get_pagenum_link', array( $this, 'fix_search_pagination_url' ), 20, 2 );
		add_action( 'wp', array( $this, 'capture_search_request' ), 1 );
		add_filter( 'gettext', array( $this, 'translate_search_interface' ), 20, 3 );
		add_filter( 'paginate_links_output', array( $this, 'translate_search_pagination_output' ), PHP_INT_MAX, 2 );
		add_filter( 'elementor/widget/render_content', array( $this, 'translate_search_archive_widget' ), 20, 2 );
		add_action( 'template_redirect', array( $this, 'preserve_search_event_redirect' ), 5 );
		add_action( 'plugins_loaded', array( $this, 'declare_woocommerce_compatibility' ) );
		add_action( 'update_option_timezone_string', array( $this, 'reschedule_maintenance' ) );
		add_action( 'update_option_gmt_offset', array( $this, 'reschedule_maintenance' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'search-woo reindex', array( $this, 'cli_reindex' ) );
			WP_CLI::add_command( 'search-woo diagnose', array( $this, 'cli_diagnose' ) );
		}
	}

	public static function defaults(): array {
		return array(
			'min_chars' => 3,
			'limit' => 5,
			'debounce' => 250,
			'placeholder' => 'Buscar productos',
			'no_results' => 'No encontramos productos para esta búsqueda',
			'see_all' => 'Ver todos los productos...',
			'max_width' => 400,
			'style' => 'pirx-compact',
			'show_submit' => 0,
			'submit_text' => 'Buscar',
			'show_price' => 1,
			'show_category' => 1,
			'show_image' => 0,
			'show_description' => 0,
			'show_sku' => 0,
			'show_loader' => 1,
			'background' => '#ffffff',
			'hover' => '#45afad',
			'text' => '#0a0a0a',
			'highlight' => '#0a0a0a',
			'border' => '#f2f2f2',
			'button_color' => '#472476',
			'search_title' => 1,
			'search_excerpt' => 1,
			'search_content' => 1,
			'search_sku' => 1,
			'search_variation_sku' => 1,
			'search_taxonomies' => 0,
			'search_attributes' => 0,
			'search_aliases' => 1,
			'custom_fields' => 'PERSEOCODPROD',
			'synonyms' => '',
			'fuzzy' => 'off',
			'exclude_outofstock' => 0,
			'analytics' => 1,
			'track_admin_searches' => 0,
			'retention_days' => 90,
			'admin_roles' => 'manage_woocommerce',
		);
	}

	public static function activate(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$documents = $wpdb->prefix . 'swoo_documents';
		$events = $wpdb->prefix . 'swoo_events';
		$daily = $wpdb->prefix . 'swoo_daily';
		dbDelta( "CREATE TABLE {$documents} (
			product_id bigint(20) unsigned NOT NULL,
			title text NOT NULL,
			title_norm text NOT NULL,
			excerpt longtext NOT NULL,
			content longtext NOT NULL,
			sku varchar(191) NOT NULL DEFAULT '',
			variation_skus text NOT NULL,
			taxonomy_text longtext NOT NULL,
			attribute_text longtext NOT NULL,
			custom_text longtext NOT NULL,
			aliases text NOT NULL,
			search_text longtext NOT NULL,
			stock_status varchar(24) NOT NULL DEFAULT '',
			visibility varchar(24) NOT NULL DEFAULT 'visible',
			updated_at datetime NOT NULL,
			PRIMARY KEY  (product_id),
			KEY sku (sku),
			KEY stock_status (stock_status),
			KEY updated_at (updated_at)
		) {$charset};" );
		dbDelta( "CREATE TABLE {$events} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_uuid varchar(64) NOT NULL,
			term varchar(255) NOT NULL,
			origin varchar(32) NOT NULL DEFAULT 'autocomplete',
			match_type varchar(32) NOT NULL DEFAULT 'literal',
			results_count int(10) unsigned NOT NULL DEFAULT 0,
			clicked_product_id bigint(20) unsigned NULL,
			session_hash char(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_uuid (event_uuid),
			KEY created_at (created_at),
			KEY term (term(100))
		) {$charset};" );
		dbDelta( "CREATE TABLE {$daily} (
			day date NOT NULL,
			term varchar(255) NOT NULL,
			searches bigint(20) unsigned NOT NULL DEFAULT 0,
			no_results bigint(20) unsigned NOT NULL DEFAULT 0,
			clicks bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (day, term(100))
		) {$charset};" );
		self::import_legacy_data();
		add_option( 'swoo_settings', self::defaults() );
		update_option( 'swoo_db_version', SEARCH_WOO_VERSION );
		self::schedule_maintenance();
		flush_rewrite_rules();
	}

	// Runs shortly after midnight in the site's timezone (Ajustes > Generales),
	// so "yesterday" is already closed when the daily summary is built.
	private static function schedule_maintenance(): void {
		wp_clear_scheduled_hook( 'swoo_daily_maintenance' );
		$next = new DateTimeImmutable( 'tomorrow 00:15', wp_timezone() );
		wp_schedule_event( $next->getTimestamp(), 'daily', 'swoo_daily_maintenance' );
	}

	public function reschedule_maintenance(): void {
		if ( wp_next_scheduled( 'swoo_daily_maintenance' ) ) self::schedule_maintenance();
	}

	// One-time import from the former search_pequeayuda plugin on the same site.
	private static function import_legacy_data(): void {
		global $wpdb;
		if ( get_option( 'swoo_legacy_imported' ) ) return;
		$legacy_settings = get_option( 'spa_settings' );
		if ( is_array( $legacy_settings ) && false === get_option( 'swoo_settings' ) ) add_option( 'swoo_settings', $legacy_settings );
		$wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) SELECT post_id, '_swoo_aliases', meta_value FROM {$wpdb->postmeta} legacy WHERE legacy.meta_key = '_spa_aliases' AND NOT EXISTS (SELECT 1 FROM (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_swoo_aliases') current WHERE current.post_id = legacy.post_id)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( array( 'events', 'daily' ) as $suffix ) {
			$old = $wpdb->prefix . 'spa_' . $suffix;
			$new = $wpdb->prefix . 'swoo_' . $suffix;
			if ( $old === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $old ) ) ) ) {
				$wpdb->query( "INSERT IGNORE INTO {$new} SELECT * FROM {$old}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
		update_option( 'swoo_legacy_imported', 1, false );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'swoo_daily_maintenance' );
		flush_rewrite_rules();
	}

	public function declare_woocommerce_compatibility(): void {
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SEARCH_WOO_FILE, true );
		}
	}

	public function init(): void {
		add_shortcode( 'search_woo', array( $this, 'shortcode' ) );
	}

	public function register_assets(): void {
		wp_register_style( 'search-woo', SEARCH_WOO_URL . 'assets/search-woo.css', array(), SEARCH_WOO_VERSION );
		wp_register_script( 'search-woo', SEARCH_WOO_URL . 'assets/search-woo.js', array(), SEARCH_WOO_VERSION, true );
	}

	public function shortcode( array $atts = array() ): string {
		$atts = shortcode_atts(
			array( 'placeholder' => $this->settings['placeholder'], 'max_width' => $this->settings['max_width'] ),
			$atts,
			'search_woo'
		);
		wp_enqueue_style( 'search-woo' );
		wp_enqueue_script( 'search-woo' );
		wp_localize_script( 'search-woo', 'SearchWoo', array(
			'endpoint' => add_query_arg( 'wc-ajax', 'swoo_search', home_url( '/' ) ),
			'clickEndpoint' => add_query_arg( 'wc-ajax', 'swoo_click', home_url( '/' ) ),
			'minChars' => (int) $this->settings['min_chars'],
			'debounce' => (int) $this->settings['debounce'],
			'noResults' => $this->settings['no_results'],
			'networkError' => 'No pudimos completar la búsqueda. Inténtalo de nuevo.',
			'seeAll' => $this->settings['see_all'],
		) );
		$id = wp_unique_id( 'swoo-search-' );
		$classes = 'swoo-search swoo-style-' . sanitize_html_class( $this->settings['style'] );
		ob_start();
		?>
		<div id="<?php echo esc_attr( $id ); ?>" class="<?php echo esc_attr( $classes ); ?>" style="--swoo-max-width:<?php echo absint( $atts['max_width'] ); ?>px;--swoo-bg:<?php echo esc_attr( $this->settings['background'] ); ?>;--swoo-hover:<?php echo esc_attr( $this->settings['hover'] ); ?>;--swoo-text:<?php echo esc_attr( $this->settings['text'] ); ?>;--swoo-border:<?php echo esc_attr( $this->settings['border'] ); ?>;--swoo-button-color:<?php echo esc_attr( $this->settings['button_color'] ); ?>">
			<form class="swoo-form" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>" autocomplete="off">
				<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>-input">Buscar productos</label>
				<input id="<?php echo esc_attr( $id ); ?>-input" class="swoo-input" type="search" name="s" placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>" aria-autocomplete="list" aria-controls="<?php echo esc_attr( $id ); ?>-results" aria-expanded="false" />
				<input type="hidden" name="post_type" value="product" />
				<input class="swoo-event-id" type="hidden" name="swoo_event" value="" />
				<?php if ( $this->settings['show_loader'] ) : ?><span class="swoo-loader" aria-hidden="true"></span><?php endif; ?>
				<?php if ( $this->settings['show_submit'] ) : ?><button class="swoo-submit" type="submit"><?php echo esc_html( $this->settings['submit_text'] ); ?></button><?php endif; ?>
			</form>
			<div id="<?php echo esc_attr( $id ); ?>-results" class="swoo-results" role="listbox" hidden></div>
			<div class="swoo-status screen-reader-text" role="status" aria-live="polite"></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	public function ajax_search(): void {
		$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
		$event = isset( $_GET['event'] ) ? sanitize_key( wp_unslash( $_GET['event'] ) ) : '';
		if ( mb_strlen( $term ) < (int) $this->settings['min_chars'] ) {
			wp_send_json_success( array( 'items' => array(), 'total' => 0, 'matchType' => 'literal' ) );
		}
		$result = $this->search( $term, (int) $this->settings['limit'] );
		if ( $event ) {
			$this->record_event( $event, $term, 'autocomplete', $result['match_type'], $result['total'] );
		}
		$items = array();
		foreach ( $result['ids'] as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product || ! $product->is_visible() ) {
				continue;
			}
			$category_names = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'names' ) );
			$items[] = array(
				'id' => $product_id,
				'title' => $product->get_name(),
				'url' => $product->get_permalink(),
				'price' => $this->settings['show_price'] ? $product->get_price_html() : '',
				'sku' => $this->settings['show_sku'] ? $product->get_sku() : '',
				'description' => $this->settings['show_description'] ? wp_trim_words( $product->get_short_description(), 18 ) : '',
				'categories' => $this->settings['show_category'] && ! is_wp_error( $category_names ) ? implode( ', ', $category_names ) : '',
				'image' => $this->settings['show_image'] ? wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' ) : '',
			);
		}
		wp_send_json_success( array(
			'items' => $items,
			'total' => $result['total'],
			'matchType' => $result['match_type'],
			'approximate' => 'fuzzy' === $result['match_type'],
		) );
	}

	public function ajax_click(): void {
		global $wpdb;
		$event = isset( $_POST['event'] ) ? sanitize_key( wp_unslash( $_POST['event'] ) ) : '';
		$product_id = isset( $_POST['product'] ) ? absint( $_POST['product'] ) : 0;
		if ( $event && $product_id ) {
			$wpdb->update( $wpdb->prefix . 'swoo_events', array( 'clicked_product_id' => $product_id, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'event_uuid' => $event ), array( '%d', '%s' ), array( '%s' ) );
		}
		wp_send_json_success();
	}

	public function search( string $raw_term, int $limit = 5 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'swoo_documents';
		$term = $this->normalize( $raw_term );
		$limit = min( 500, max( 1, $limit ) );
		$cache_key = $term . '|' . $limit;
		if ( isset( $this->search_cache[ $cache_key ] ) ) {
			return $this->search_cache[ $cache_key ];
		}
		$queries = $this->expand_synonyms( $term );
		$conditions = array();
		$condition_params = array();
		$score_expressions = array();
		$score_params = array();
		$fields = array();
		if ( $this->settings['search_title'] ) $fields[] = 'title_norm';
		if ( $this->settings['search_excerpt'] ) $fields[] = 'excerpt';
		if ( $this->settings['search_content'] ) $fields[] = 'content';
		if ( $this->settings['search_sku'] ) $fields[] = 'sku';
		if ( $this->settings['search_variation_sku'] ) $fields[] = 'variation_skus';
		if ( $this->settings['search_taxonomies'] ) $fields[] = 'taxonomy_text';
		if ( $this->settings['search_attributes'] ) $fields[] = 'attribute_text';
		if ( trim( (string) $this->settings['custom_fields'] ) ) $fields[] = 'custom_text';
		if ( $this->settings['search_aliases'] ) $fields[] = 'aliases';
		$token_search_fields = array_values( array_intersect(
			$fields,
			array( 'title_norm', 'sku', 'variation_skus', 'taxonomy_text', 'attribute_text', 'aliases' )
		) );
		foreach ( $queries as $query_index => $query_term ) {
			$tokens = (array) preg_split( '/\s+/u', $query_term, -1, PREG_SPLIT_NO_EMPTY );
			$like = '%' . $wpdb->esc_like( $query_term ) . '%';
			$prefix = $wpdb->esc_like( $query_term ) . '%';
			$parts = array();
			foreach ( $fields as $field ) {
				$parts[] = "{$field} LIKE %s";
				$condition_params[] = $like;
			}
			$query_conditions = $parts ? array( '(' . implode( ' OR ', $parts ) . ')' ) : array();
			if ( count( $tokens ) > 1 ) {
				$token_parts = array();
				foreach ( $token_search_fields as $field ) {
					$token_parts[] = $this->token_match_sql( $field, $tokens, $condition_params );
				}
				if ( $token_parts ) $query_conditions[] = '(' . implode( ' OR ', $token_parts ) . ')';
			}
			if ( $query_conditions ) $conditions[] = '(' . implode( ' OR ', $query_conditions ) . ')';

			// The original query wins ties, while expanded synonyms still receive
			// meaningful title/alias relevance instead of falling back to product ID.
			$boost = 0 === $query_index ? '1' : '0.82';
			$title_token_params = array();
			$alias_token_params = array();
			$title_token_score = count( $tokens ) > 1 ? $this->token_match_sql( 'title_norm', $tokens, $title_token_params ) : '0 = 1';
			$alias_token_score = count( $tokens ) > 1 ? $this->token_match_sql( 'aliases', $tokens, $alias_token_params ) : '0 = 1';
			$score_expressions[] = "((CASE WHEN sku = %s OR FIND_IN_SET(%s, variation_skus) THEN 1200 ELSE 0 END +
				CASE WHEN aliases = %s THEN 900 WHEN aliases LIKE %s THEN 700 ELSE 0 END +
				CASE WHEN title_norm = %s THEN 850 WHEN title_norm LIKE %s THEN 650 WHEN title_norm LIKE %s THEN 500 ELSE 0 END +
				CASE WHEN {$title_token_score} THEN 475 ELSE 0 END +
				CASE WHEN {$alias_token_score} THEN 350 ELSE 0 END +
				CASE WHEN taxonomy_text LIKE %s OR attribute_text LIKE %s THEN 250 ELSE 0 END +
				CASE WHEN excerpt LIKE %s OR content LIKE %s THEN 100 ELSE 0 END) * {$boost})";
			$base_score_params = array( $query_term, $query_term, $query_term, $like, $query_term, $prefix, $like );
			$trailing_score_params = array( $like, $like, $like, $like );
			$score_params = array_merge( $score_params, $base_score_params, $title_token_params, $alias_token_params, $trailing_score_params );
		}
		if ( ! $conditions ) return array( 'ids' => array(), 'total' => 0, 'match_type' => 'literal' );
		$where = '(' . implode( ' OR ', $conditions ) . ')';
		if ( $this->settings['exclude_outofstock'] ) $where .= " AND stock_status <> 'outofstock'";
		$score_sql = count( $score_expressions ) > 1
			? 'GREATEST(' . implode( ',', $score_expressions ) . ')'
			: $score_expressions[0];
		$sql = "SELECT SQL_CALC_FOUND_ROWS product_id,
			{$score_sql} AS score
			FROM {$table} WHERE {$where} ORDER BY score DESC, product_id DESC LIMIT %d";
		$prepared = $wpdb->prepare( $sql, array_merge( $score_params, $condition_params, array( $limit ) ) );
		$ids = array_map( 'intval', (array) $wpdb->get_col( $prepared ) );
		$total = (int) $wpdb->get_var( 'SELECT FOUND_ROWS()' );
		$match_type = count( $queries ) > 1 ? 'synonym' : 'literal';

		if ( count( $ids ) < min( 3, $limit ) && 'off' !== $this->settings['fuzzy'] && preg_match( '/\p{L}/u', $term ) && mb_strlen( $term ) >= 4 ) {
			$fuzzy_ids = $this->fuzzy_ids( $term, $limit, $ids );
			if ( $fuzzy_ids ) {
				$ids = array_values( array_unique( array_merge( $ids, $fuzzy_ids ) ) );
				$ids = array_slice( $ids, 0, $limit );
				$total = max( $total, count( $ids ) );
				$match_type = 'fuzzy';
			}
		}
		$result = array( 'ids' => $ids, 'total' => $total, 'match_type' => $match_type );
		$this->search_cache[ $cache_key ] = $result;
		return $result;
	}

	private function token_match_sql( string $field, array $tokens, array &$params ): string {
		global $wpdb;
		$parts = array();
		foreach ( $tokens as $token ) {
			if ( preg_match( '/^\p{N}+$/u', $token ) ) {
				$parts[] = "{$field} REGEXP %s";
				$params[] = '(^|[^[:alnum:]])' . preg_quote( $token, '/' ) . '([^0-9]|$)';
			} else {
				$parts[] = "{$field} LIKE %s";
				$params[] = '%' . $wpdb->esc_like( $token ) . '%';
			}
		}
		return '(' . implode( ' AND ', $parts ) . ')';
	}

	private function fuzzy_ids( string $term, int $limit, array $excluded ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'swoo_documents';
		$rows = $wpdb->get_results( "SELECT product_id, title_norm FROM {$table} ORDER BY updated_at DESC LIMIT 5000", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$thresholds = array( 'soft' => 1, 'normal' => 2, 'wide' => 3 );
		$threshold = $thresholds[ $this->settings['fuzzy'] ] ?? 1;
		$tokens = preg_split( '/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY );
		$text_token_count = count( array_filter( $tokens, static fn( string $token ): bool => ! preg_match( '/^\p{N}+$/u', $token ) ) );
		$scores = array();
		foreach ( $rows as $row ) {
			if ( in_array( (int) $row['product_id'], $excluded, true ) ) continue;
			$title_tokens = preg_split( '/\s+/u', $row['title_norm'], -1, PREG_SPLIT_NO_EMPTY );
			$distance = 0;
			foreach ( $tokens as $token ) {
				$best = 99;
				if ( preg_match( '/^\p{N}+$/u', $token ) ) {
					$numeric_pattern = '/^' . preg_quote( $token, '/' ) . '(?:\p{L}{1,4})?$/u';
					foreach ( $title_tokens as $candidate ) {
						if ( preg_match( $numeric_pattern, $candidate ) ) {
							$best = 0;
							break;
						}
					}
				} else {
					foreach ( $title_tokens as $candidate ) $best = min( $best, levenshtein( $token, $candidate ) );
				}
				$distance += $best;
			}
			if ( $distance <= $threshold * max( 1, $text_token_count ) ) $scores[ (int) $row['product_id'] ] = $distance;
		}
		asort( $scores );
		return array_slice( array_keys( $scores ), 0, $limit );
	}

	private function normalize( string $value ): string {
		$value = remove_accents( wp_strip_all_tags( html_entity_decode( $value, ENT_QUOTES, 'UTF-8' ) ) );
		$value = mb_strtolower( $value );
		$value = preg_replace( '/[^\p{L}\p{N}\-\.]+/u', ' ', $value );
		return trim( preg_replace( '/\s+/u', ' ', $value ) );
	}

	private function expand_synonyms( string $term ): array {
		$expanded = array( $term );
		foreach ( preg_split( '/\R/u', (string) $this->settings['synonyms'] ) as $line ) {
			$group = array_values( array_filter( array_map( array( $this, 'normalize' ), explode( ',', $line ) ) ) );
			foreach ( $group as $needle ) {
				if ( preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $needle, '/' ) . '(?![\p{L}\p{N}])/u', $term ) ) {
					foreach ( $group as $replacement ) {
						$expanded[] = preg_replace( '/(?<![\p{L}\p{N}])' . preg_quote( $needle, '/' ) . '(?![\p{L}\p{N}])/u', $replacement, $term );
					}
				}
			}
		}
		return array_values( array_unique( $expanded ) );
	}

	public function index_product( int $product_id ): bool {
		global $wpdb;
		$product = wc_get_product( $product_id );
		if ( ! $product || $product->is_type( 'variation' ) ) return false;
		$post = get_post( $product_id );
		if ( ! $post || 'product' !== $post->post_type ) return false;
		$taxonomies = array();
		foreach ( get_object_taxonomies( 'product' ) as $taxonomy ) {
			$names = wp_get_post_terms( $product_id, $taxonomy, array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $names ) ) $taxonomies = array_merge( $taxonomies, $names );
		}
		$attributes = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( $attribute->is_taxonomy() ) {
				$names = wc_get_product_terms( $product_id, $attribute->get_name(), array( 'fields' => 'names' ) );
				$attributes = array_merge( $attributes, $names );
			} else {
				$attributes = array_merge( $attributes, $attribute->get_options() );
			}
		}
		$variation_skus = array();
		foreach ( $product->get_children() as $variation_id ) {
			$sku = get_post_meta( $variation_id, '_sku', true );
			if ( '' !== $sku ) $variation_skus[] = $this->normalize( (string) $sku );
		}
		$custom = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) $this->settings['custom_fields'] ) ) ) as $key ) {
			$value = get_post_meta( $product_id, $key, true );
			if ( is_scalar( $value ) ) $custom[] = (string) $value;
		}
		$title = $product->get_name();
		$excerpt = $product->get_short_description();
		$content = $product->get_description();
		$aliases = (string) get_post_meta( $product_id, '_swoo_aliases', true );
		$parts = array( $title, $excerpt, $content, $product->get_sku(), implode( ' ', $variation_skus ), implode( ' ', $taxonomies ), implode( ' ', $attributes ), implode( ' ', $custom ), $aliases );
		$visibility = $product->get_catalog_visibility();
		$data = array(
			'product_id' => $product_id,
			'title' => $title,
			'title_norm' => $this->normalize( $title ),
			'excerpt' => $this->normalize( $excerpt ),
			'content' => $this->normalize( $content ),
			'sku' => $this->normalize( $product->get_sku() ),
			'variation_skus' => implode( ',', array_unique( $variation_skus ) ),
			'taxonomy_text' => $this->normalize( implode( ' ', $taxonomies ) ),
			'attribute_text' => $this->normalize( implode( ' ', $attributes ) ),
			'custom_text' => $this->normalize( implode( ' ', $custom ) ),
			'aliases' => $this->normalize( $aliases ),
			'search_text' => $this->normalize( implode( ' ', $parts ) ),
			'stock_status' => $product->get_stock_status(),
			'visibility' => $visibility,
			'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		// WooCommerce: "catalog" means shop-only, while "search" is search-only.
		if ( 'publish' !== $post->post_status || in_array( $visibility, array( 'hidden', 'catalog' ), true ) ) {
			return (bool) $wpdb->delete( $wpdb->prefix . 'swoo_documents', array( 'product_id' => $product_id ), array( '%d' ) );
		}
		return false !== $wpdb->replace( $wpdb->prefix . 'swoo_documents', $data );
	}

	public function queue_product( int $product_id ): void {
		if ( wp_is_post_revision( $product_id ) || wp_is_post_autosave( $product_id ) ) return;
		if ( ! wp_next_scheduled( 'swoo_index_product', array( $product_id ) ) ) wp_schedule_single_event( time() + 5, 'swoo_index_product', array( $product_id ) );
	}

	public function queue_variation( int $variation_id ): void {
		$parent_id = wp_get_post_parent_id( $variation_id );
		if ( $parent_id ) $this->queue_product( $parent_id );
	}

	public function delete_document( int $post_id ): void {
		global $wpdb;
		if ( 'product' === get_post_type( $post_id ) ) $wpdb->delete( $wpdb->prefix . 'swoo_documents', array( 'product_id' => $post_id ), array( '%d' ) );
	}

	public function cli_reindex( array $args, array $assoc_args ): void {
		global $wpdb;
		$ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) );
		$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . 'swoo_documents' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$progress = \WP_CLI\Utils\make_progress_bar( 'Indexando productos', count( $ids ) );
		foreach ( $ids as $id ) { $this->index_product( (int) $id ); $progress->tick(); }
		$progress->finish();
		WP_CLI::success( count( $ids ) . ' productos procesados.' );
	}

	public function cli_diagnose(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'swoo_documents';
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$latest = $wpdb->get_var( "SELECT MAX(updated_at) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		WP_CLI::line( wp_json_encode( array( 'indexed' => $count, 'latest_utc' => $latest, 'latest_local' => $latest ? get_date_from_gmt( $latest, 'Y-m-d H:i:s' ) : null, 'timezone' => wp_timezone_string(), 'version' => SEARCH_WOO_VERSION ), JSON_PRETTY_PRINT ) );
	}

	public function unify_results_page( WP_Query $query ): void {
		if ( is_admin() || $this->search_filtering ) return;
		$term = (string) $query->get( 's' );
		if ( mb_strlen( trim( $term ) ) < (int) $this->settings['min_chars'] ) return;
		$post_type = $query->get( 'post_type' );
		$is_product_query = 'product' === $post_type || ( is_array( $post_type ) && in_array( 'product', $post_type, true ) );
		if ( ! $query->is_main_query() && ! $is_product_query ) return;
		$is_main_query = $query->is_main_query();
		$this->search_filtering = true;
		$result = $this->search( $term, 500 );
		$query->set( 'swoo_original_s', $term );
		$query->set( 'swoo_managed_search', 1 );
		$query->set( 'post_type', 'product' );
		$query->set( 'post__in', $result['ids'] ?: array( 0 ) );
		$query->set( 'orderby', 'post__in' );
		$this->search_filtering = false;
		if ( $is_main_query && isset( $_GET['swoo_event'] ) ) $this->record_event( sanitize_key( wp_unslash( $_GET['swoo_event'] ) ), $term, 'results', $result['match_type'], $result['total'] );
	}

	public function remove_core_search_clause( string $search, WP_Query $query ): string {
		return $query->get( 'swoo_managed_search' ) ? '' : $search;
	}

	public function fix_search_pagination_url( string $url, int $pagenum ): string {
		if ( is_admin() || ! is_search() ) return $url;
		global $wp_rewrite;
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$search_base = isset( $wp_rewrite->search_base ) ? trim( (string) $wp_rewrite->search_base, '/' ) : 'search';
		$marker = '/' . $search_base . '/';
		$marker_position = strpos( $path, $marker );
		if ( false === $marker_position ) return $url;

		$term_start = $marker_position + strlen( $marker );
		$pagination_position = strpos( $path, '/' . $wp_rewrite->pagination_base . '/', $term_start );
		$term_end = false === $pagination_position ? strlen( $path ) : $pagination_position;
		$term_path = substr( $path, $term_start, $term_end - $term_start );
		$decoded_term = urldecode( $term_path );
		$safe_term = remove_accents( $decoded_term );
		$safe_term = trim( (string) preg_replace( '/[^A-Za-z0-9\-\.]+/', ' ', $safe_term ) );
		if ( '' === $safe_term ) return $url;
		$fixed_term_path = str_ireplace( '%20', '+', rawurlencode( $safe_term ) );
		if ( $term_path === $fixed_term_path ) return $url;

		$fixed_path = substr( $path, 0, $term_start ) . $fixed_term_path . substr( $path, $term_end );
		return substr_replace( $url, $fixed_path, strpos( $url, $path ), strlen( $path ) );
	}

	public function translate_search_interface( string $translated, string $text, string $domain ): string {
		$elementor_strings = array(
			'Search Results for: %s' => 'Resultados para: %s',
			'&nbsp;&ndash; Page %s' => '&nbsp;&ndash; Página %s',
		);
		$is_elementor_string = 'elementor-pro' === $domain && isset( $elementor_strings[ $text ] );
		$is_core_page_string = 'default' === $domain && 'Page %s' === $text;
		if ( ! $is_elementor_string && ! $is_core_page_string ) return $translated;

		if ( ! $this->is_search_request ) return $translated;
		if ( $is_elementor_string ) {
			return $elementor_strings[ $text ];
		}
		return 'Página %s';
	}

	public function translate_search_pagination_output( string $output, array $args ): string {
		if ( ! $this->is_search_request ) return $output;
		return (string) preg_replace( '/aria-label=(["\'])Page ([0-9]+)\1/i', 'aria-label=$1Página $2$1', $output );
	}

	public function capture_search_request(): void {
		if ( ! is_search() ) return;
		$this->is_search_request = true;
		// Read the canonical query variable directly. get_search_query() is
		// filterable and, in authenticated/front-end contexts, another plugin can
		// prepend the archive label before Elementor renders the heading.
		$raw_term = get_query_var( 's' );
		$this->search_request_term = is_scalar( $raw_term )
			? sanitize_text_field( wp_unslash( (string) $raw_term ) )
			: '';
		$this->search_request_page = max( 1, absint( get_query_var( 'paged' ) ) );
	}

	public function translate_search_archive_widget( string $content, $widget ): string {
		if ( ! $this->is_search_request || ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) || 'theme-archive-title' !== $widget->get_name() ) {
			return $content;
		}
		if ( '' === $this->search_request_term ) return $content;
		$title = 'Resultados para: ' . esc_html( $this->search_request_term );
		if ( $this->search_request_page > 1 ) {
			$title .= '&nbsp;&ndash; Página ' . $this->search_request_page;
		}
		$translated = preg_replace_callback(
			'/(<h[1-6]\b[^>]*>).*?(<\/h[1-6]>)/is',
			static fn( array $matches ): string => $matches[1] . $title . $matches[2],
			$content,
			1
		);
		return is_string( $translated ) ? $translated : $content;
	}

	public function preserve_search_event_redirect(): void {
		if ( ! is_search() || empty( $_GET['swoo_event'] ) ) return;
		$path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		if ( str_starts_with( $path, '/search/' ) ) return;
		$term = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : (string) get_query_var( 'swoo_original_s' );
		if ( '' === $term ) return;
		$url = trailingslashit( home_url( '/search/' . rawurlencode( $term ) ) );
		$url = add_query_arg( 'swoo_event', sanitize_key( wp_unslash( $_GET['swoo_event'] ) ), $url );
		wp_safe_redirect( $url );
		exit;
	}

	private function record_event( string $event_uuid, string $term, string $origin, string $match_type, int $count ): void {
		global $wpdb;
		if ( ! $this->settings['analytics'] || ( current_user_can( 'manage_woocommerce' ) && empty( $this->settings['track_admin_searches'] ) ) || strlen( $event_uuid ) < 12 ) return;
		$user_agent = (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' );
		if ( preg_match( '/bot|crawler|spider|headless|uptime|monitor/i', $user_agent ) ) return;
		$term = $this->privacy_filter( $term );
		// Only an irreversible, daily-rotating hash is stored; never the raw IP.
		$session = hash( 'sha256', wp_salt( 'nonce' ) . '|' . ( $_SERVER['REMOTE_ADDR'] ?? '' ) . '|' . $user_agent . '|' . wp_date( 'Y-m-d' ) );
		$now = gmdate( 'Y-m-d H:i:s' );
		$table = $wpdb->prefix . 'swoo_events';
		$recent = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE session_hash=%s AND created_at >= %s", $session, gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS ) ) );
		if ( $recent >= 120 ) return;
		$sql = "INSERT INTO {$table} (event_uuid,term,origin,match_type,results_count,session_hash,created_at,updated_at)
			VALUES (%s,%s,%s,%s,%d,%s,%s,%s)
			ON DUPLICATE KEY UPDATE term=VALUES(term),origin=VALUES(origin),match_type=VALUES(match_type),results_count=VALUES(results_count),updated_at=VALUES(updated_at)";
		$wpdb->query( $wpdb->prepare( $sql, $event_uuid, $term, $origin, $match_type, $count, $session, $now, $now ) );
	}

	private function privacy_filter( string $term ): string {
		if ( is_email( $term ) || preg_match( '/(?:\+?\d[\s.-]*){8,}/', $term ) ) return '[consulta filtrada]';
		return mb_substr( sanitize_text_field( $term ), 0, 255 );
	}

	public function alias_meta_box(): void {
		add_meta_box( 'swoo-aliases', 'Search Woo', array( $this, 'render_alias_meta_box' ), 'product', 'side', 'default' );
	}

	public function render_alias_meta_box( WP_Post $post ): void {
		wp_nonce_field( 'swoo_save_aliases', 'swoo_aliases_nonce' );
		$value = get_post_meta( $post->ID, '_swoo_aliases', true );
		echo '<label for="swoo_aliases">Sinónimos / nombres alternativos</label><textarea id="swoo_aliases" name="swoo_aliases" rows="5" style="width:100%">' . esc_textarea( $value ) . '</textarea><p class="description">Un término o frase por línea.</p>';
	}

	public function save_aliases( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST['swoo_aliases_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['swoo_aliases_nonce'] ) ), 'swoo_save_aliases' ) || ! current_user_can( 'edit_post', $post_id ) ) return;
		$value = isset( $_POST['swoo_aliases'] ) ? sanitize_textarea_field( wp_unslash( $_POST['swoo_aliases'] ) ) : '';
		if ( '' === $value ) delete_post_meta( $post_id, '_swoo_aliases' ); else update_post_meta( $post_id, '_swoo_aliases', $value );
	}

	public function register_settings(): void {
		register_setting( 'swoo_settings_group', 'swoo_settings', array( 'sanitize_callback' => array( $this, 'sanitize_settings' ) ) );
	}

	public function sanitize_settings( array $input ): array {
		$d = self::defaults();
		$out = wp_parse_args( (array) get_option( 'swoo_settings', array() ), $d );
		foreach ( array( 'min_chars', 'limit', 'debounce', 'max_width', 'retention_days' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) $out[ $key ] = absint( $input[ $key ] );
		}
		foreach ( array( 'placeholder', 'no_results', 'see_all', 'submit_text', 'custom_fields', 'admin_roles' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) $out[ $key ] = sanitize_text_field( $input[ $key ] );
		}
		if ( array_key_exists( 'synonyms', $input ) ) $out['synonyms'] = sanitize_textarea_field( $input['synonyms'] );
		foreach ( array( 'show_submit', 'show_price', 'show_category', 'show_image', 'show_description', 'show_sku', 'show_loader', 'search_title', 'search_excerpt', 'search_content', 'search_sku', 'search_variation_sku', 'search_taxonomies', 'search_attributes', 'search_aliases', 'exclude_outofstock', 'analytics', 'track_admin_searches' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) $out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}
		foreach ( array( 'background', 'hover', 'text', 'highlight', 'border', 'button_color' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) $out[ $key ] = sanitize_hex_color( $input[ $key ] ) ?: $d[ $key ];
		}
		if ( array_key_exists( 'style', $input ) ) $out['style'] = in_array( $input['style'], array( 'rectangular', 'rounded', 'pirx-compact' ), true ) ? $input['style'] : $d['style'];
		if ( array_key_exists( 'fuzzy', $input ) ) $out['fuzzy'] = in_array( $input['fuzzy'], array( 'off', 'soft', 'normal', 'wide' ), true ) ? $input['fuzzy'] : 'off';
		return $out;
	}

	public function admin_menu(): void {
		add_submenu_page( 'woocommerce', 'Search Woo', 'Search Woo', 'manage_woocommerce', 'search-woo', array( $this, 'admin_page' ) );
	}

	public function admin_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) return;
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'search';
		global $wpdb;
		$indexed = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'swoo_documents' );
		echo '<div class="wrap"><h1>Search Woo</h1><nav class="nav-tab-wrapper">';
		foreach ( array( 'search' => 'Búsqueda', 'appearance' => 'Presentación', 'reports' => 'Reportes' ) as $key => $label ) echo '<a class="nav-tab ' . ( $tab === $key ? 'nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=search-woo&tab=' . $key ) ) . '">' . esc_html( $label ) . '</a>';
		echo '</nav>';
		if ( 'reports' === $tab ) { $this->reports_page(); echo '</div>'; return; }
		echo '<form method="post" action="options.php">'; settings_fields( 'swoo_settings_group' ); echo '<table class="form-table"><tbody>';
		if ( 'search' === $tab ) {
			$this->number_row( 'Mínimo de caracteres', 'min_chars' ); $this->number_row( 'Límite de sugerencias', 'limit' ); $this->number_row( 'Espera al escribir (ms)', 'debounce' );
			foreach ( array( 'search_title' => 'Nombre', 'search_excerpt' => 'Descripción corta', 'search_content' => 'Descripción larga', 'search_sku' => 'SKU del producto', 'search_variation_sku' => 'SKU de variaciones', 'search_taxonomies' => 'Categorías, etiquetas y marca', 'search_attributes' => 'Atributos encontrados', 'search_aliases' => 'Alias por producto', 'exclude_outofstock' => 'Excluir agotados', 'analytics' => 'Registrar analítica' ) as $key => $label ) $this->checkbox_row( 'Buscar: ' . $label, $key );
			$this->checkbox_row( 'Analítica: registrar búsquedas de administradores', 'track_admin_searches' );
			$this->text_row( 'Campos personalizados (separados por coma)', 'custom_fields' );
			echo '<tr><th>Coincidencia aproximada</th><td><select name="swoo_settings[fuzzy]">'; foreach ( array( 'off' => 'Desactivada', 'soft' => 'Suave', 'normal' => 'Normal', 'wide' => 'Amplia' ) as $key => $label ) echo '<option value="' . esc_attr( $key ) . '" ' . selected( $this->settings['fuzzy'], $key, false ) . '>' . esc_html( $label ) . '</option>'; echo '</select><p class="description">Nunca se aplica a consultas que contienen números.</p></td></tr>';
			echo '<tr><th>Sinónimos generales</th><td><textarea class="large-text code" rows="8" name="swoo_settings[synonyms]">' . esc_textarea( $this->settings['synonyms'] ) . '</textarea><p class="description">Un grupo por línea; términos o frases separados por comas.</p></td></tr>';
		} else {
			$this->text_row( 'Placeholder', 'placeholder' ); $this->text_row( 'Sin resultados', 'no_results' ); $this->text_row( 'Ver todos', 'see_all' ); $this->number_row( 'Ancho máximo (px)', 'max_width' );
			echo '<tr><th>Estilo</th><td><select name="swoo_settings[style]">'; foreach ( array( 'rectangular' => 'Rectangular', 'rounded' => 'Redondeado', 'pirx-compact' => 'Redondeado compacto' ) as $key => $label ) echo '<option value="' . esc_attr( $key ) . '" ' . selected( $this->settings['style'], $key, false ) . '>' . esc_html( $label ) . '</option>'; echo '</select></td></tr>';
			foreach ( array( 'show_price' => 'Mostrar precio', 'show_category' => 'Mostrar categorías', 'show_image' => 'Mostrar imagen', 'show_description' => 'Mostrar descripción', 'show_sku' => 'Mostrar SKU', 'show_loader' => 'Mostrar indicador de carga', 'show_submit' => 'Mostrar botón' ) as $key => $label ) $this->checkbox_row( $label, $key );
			$this->text_row( 'Texto del botón', 'submit_text' );
			foreach ( array( 'background' => 'Fondo', 'hover' => 'Selección', 'text' => 'Texto', 'highlight' => 'Resaltado', 'border' => 'Borde', 'button_color' => 'Botón' ) as $key => $label ) echo '<tr><th>' . esc_html( $label ) . '</th><td><input type="color" name="swoo_settings[' . esc_attr( $key ) . ']" value="' . esc_attr( $this->settings[ $key ] ) . '"></td></tr>';
		}
		echo '</tbody></table>'; submit_button(); echo '</form><hr><h2>Diagnóstico</h2><p><strong>Documentos indexados:</strong> ' . number_format_i18n( $indexed ) . '</p><p><a class="button button-secondary" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=swoo_reindex' ), 'swoo_reindex' ) ) . '">Reconstruir índice</a></p></div>';
	}

	private function text_row( string $label, string $key ): void { echo '<tr><th>' . esc_html( $label ) . '</th><td><input class="regular-text" name="swoo_settings[' . esc_attr( $key ) . ']" value="' . esc_attr( $this->settings[ $key ] ) . '"></td></tr>'; }
	private function number_row( string $label, string $key ): void { echo '<tr><th>' . esc_html( $label ) . '</th><td><input type="number" min="1" name="swoo_settings[' . esc_attr( $key ) . ']" value="' . absint( $this->settings[ $key ] ) . '"></td></tr>'; }
	private function checkbox_row( string $label, string $key ): void { echo '<tr><th>' . esc_html( $label ) . '</th><td><label><input type="hidden" name="swoo_settings[' . esc_attr( $key ) . ']" value="0"><input type="checkbox" name="swoo_settings[' . esc_attr( $key ) . ']" value="1" ' . checked( ! empty( $this->settings[ $key ] ), true, false ) . '> Activado</label></td></tr>'; }

	public function admin_reindex(): void {
		check_admin_referer( 'swoo_reindex' ); if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'No autorizado.' );
		$ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => -1 ) );
		foreach ( $ids as $id ) $this->queue_product( (int) $id );
		wp_safe_redirect( admin_url( 'admin.php?page=search-woo&swoo_notice=queued' ) ); exit;
	}

	private function date_range(): array {
		$tz = wp_timezone();
		$to = isset( $_GET['to'] ) ? sanitize_text_field( $_GET['to'] ) : wp_date( 'Y-m-d' );
		$from = isset( $_GET['from'] ) ? sanitize_text_field( $_GET['from'] ) : wp_date( 'Y-m-d', time() - 29 * DAY_IN_SECONDS );
		try { $start = new DateTimeImmutable( $from . ' 00:00:00', $tz ); $end = new DateTimeImmutable( $to . ' 23:59:59', $tz ); } catch ( Exception $e ) { $start = new DateTimeImmutable( '-29 days', $tz ); $end = new DateTimeImmutable( 'now', $tz ); }
		return array( $from, $to, $start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ), $end->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) );
	}

	private function report_filters(): array {
		$origin = isset( $_GET['origin'] ) ? sanitize_key( $_GET['origin'] ) : '';
		$match_type = isset( $_GET['match_type'] ) ? sanitize_key( $_GET['match_type'] ) : '';
		$outcome = isset( $_GET['outcome'] ) ? sanitize_key( $_GET['outcome'] ) : '';
		$order_by = isset( $_GET['order_by'] ) ? sanitize_key( $_GET['order_by'] ) : 'searches';
		$order = isset( $_GET['order'] ) ? strtoupper( sanitize_key( $_GET['order'] ) ) : 'DESC';
		return array(
			'term' => isset( $_GET['term_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['term_filter'] ) ) : '',
			'origin' => in_array( $origin, array( 'autocomplete', 'results' ), true ) ? $origin : '',
			'match_type' => in_array( $match_type, array( 'literal', 'synonym', 'fuzzy' ), true ) ? $match_type : '',
			'outcome' => in_array( $outcome, array( 'no_results', 'with_results', 'clicked', 'not_clicked' ), true ) ? $outcome : '',
			'order_by' => in_array( $order_by, array( 'term', 'searches', 'no_results', 'clicks', 'last_search' ), true ) ? $order_by : 'searches',
			'order' => in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'DESC',
		);
	}

	private function report_where( string $start, string $end, array $filters, bool $include_outcome = true ): array {
		global $wpdb;
		$clauses = array( 'created_at BETWEEN %s AND %s' );
		$args = array( $start, $end );
		if ( '' !== $filters['term'] ) { $clauses[] = 'term LIKE %s'; $args[] = '%' . $wpdb->esc_like( $filters['term'] ) . '%'; }
		if ( '' !== $filters['origin'] ) { $clauses[] = 'origin=%s'; $args[] = $filters['origin']; }
		if ( '' !== $filters['match_type'] ) { $clauses[] = 'match_type=%s'; $args[] = $filters['match_type']; }
		if ( $include_outcome ) {
			if ( 'no_results' === $filters['outcome'] ) $clauses[] = 'results_count=0';
			elseif ( 'with_results' === $filters['outcome'] ) $clauses[] = 'results_count>0';
			elseif ( 'clicked' === $filters['outcome'] ) $clauses[] = 'clicked_product_id IS NOT NULL';
			elseif ( 'not_clicked' === $filters['outcome'] ) $clauses[] = 'clicked_product_id IS NULL';
		}
		return array( implode( ' AND ', $clauses ), $args );
	}

	private function reports_page(): void {
		global $wpdb;
		list( $from, $to, $start, $end ) = $this->date_range();
		$filters = $this->report_filters();
		$table = $wpdb->prefix . 'swoo_events';
		list( $where, $args ) = $this->report_where( $start, $end, $filters );
		list( $base_where, $base_args ) = $this->report_where( $start, $end, $filters, false );
		$sort_columns = array( 'term' => 'term', 'searches' => 'searches', 'no_results' => 'no_results', 'clicks' => 'clicks', 'last_search' => 'last_search' );
		$order_sql = $sort_columns[ $filters['order_by'] ] . ' ' . $filters['order'];
		$summary = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) searches,SUM(results_count=0) no_results,SUM(clicked_product_id IS NOT NULL) clicks,SUM(match_type='synonym') synonyms,SUM(match_type='fuzzy') fuzzy FROM {$table} WHERE {$where}", $args ), ARRAY_A );
		$top = $wpdb->get_results( $wpdb->prepare( "SELECT term,COUNT(*) searches,SUM(results_count=0) no_results,SUM(clicked_product_id IS NOT NULL) clicks,MAX(created_at) last_search FROM {$table} WHERE {$where} GROUP BY term ORDER BY {$order_sql} LIMIT 100", $args ), ARRAY_A );
		$daily = $this->daily_rows( $table, $where, $args );
		$no_results = $wpdb->get_results( $wpdb->prepare( "SELECT term,COUNT(*) searches,MAX(created_at) last_search,GROUP_CONCAT(DISTINCT origin ORDER BY origin SEPARATOR ', ') origins FROM {$table} WHERE {$base_where} AND results_count=0 GROUP BY term ORDER BY searches DESC,last_search DESC LIMIT 200", $base_args ), ARRAY_A );
		$clicked = $wpdb->get_results( $wpdb->prepare( "SELECT term,clicked_product_id,COUNT(*) clicks,MAX(updated_at) last_click FROM {$table} WHERE {$base_where} AND clicked_product_id IS NOT NULL GROUP BY term,clicked_product_id ORDER BY clicks DESC,last_click DESC LIMIT 200", $base_args ), ARRAY_A );

		echo '<form method="get" style="display:flex;flex-wrap:wrap;align-items:end;gap:10px;margin:18px 0">';
		echo '<input type="hidden" name="page" value="search-woo"><input type="hidden" name="tab" value="reports">';
		echo '<label>Desde<br><input type="date" name="from" value="' . esc_attr( $from ) . '"></label><label>Hasta<br><input type="date" name="to" value="' . esc_attr( $to ) . '"></label>';
		echo '<label>Palabra<br><input type="search" name="term_filter" value="' . esc_attr( $filters['term'] ) . '" placeholder="Contiene..."></label>';
		$this->report_select( 'Origen', 'origin', $filters['origin'], array( '' => 'Todos', 'autocomplete' => 'Autocompletado', 'results' => 'Página de resultados' ) );
		$this->report_select( 'Coincidencia', 'match_type', $filters['match_type'], array( '' => 'Todas', 'literal' => 'Literal', 'synonym' => 'Sinónimo', 'fuzzy' => 'Aproximada' ) );
		$this->report_select( 'Resultado', 'outcome', $filters['outcome'], array( '' => 'Todos', 'no_results' => 'Sin resultados', 'with_results' => 'Con resultados', 'clicked' => 'Con clic', 'not_clicked' => 'Sin clic' ) );
		$this->report_select( 'Ordenar por', 'order_by', $filters['order_by'], array( 'searches' => 'Búsquedas', 'no_results' => 'Sin resultados', 'clicks' => 'Clics', 'last_search' => 'Última búsqueda', 'term' => 'Palabra' ) );
		$this->report_select( 'Dirección', 'order', $filters['order'], array( 'DESC' => 'Mayor/reciente primero', 'ASC' => 'Menor/antiguo primero' ) );
		submit_button( 'Filtrar', 'secondary', '', false );
		$export_args = array_merge( array( 'action' => 'swoo_export_csv', 'from' => $from, 'to' => $to ), array_filter( array( 'term_filter' => $filters['term'], 'origin' => $filters['origin'], 'match_type' => $filters['match_type'], 'outcome' => $filters['outcome'] ) ) );
		echo ' <a class="button" href="' . esc_url( wp_nonce_url( add_query_arg( $export_args, admin_url( 'admin-post.php' ) ), 'swoo_export_csv' ) ) . '">Exportar detalle CSV</a></form>';
		$searches = (int) ( $summary['searches'] ?? 0 ); $no = (int) ( $summary['no_results'] ?? 0 ); $clicks = (int) ( $summary['clicks'] ?? 0 );
		echo '<h2>Resumen</h2><p><strong>Búsquedas:</strong> ' . number_format_i18n( $searches ) . ' &nbsp; <strong>Sin resultados:</strong> ' . number_format_i18n( $no ) . ' (' . esc_html( $searches ? round( 100 * $no / $searches, 1 ) : 0 ) . '%) &nbsp; <strong>Con clic:</strong> ' . number_format_i18n( $clicks ) . ' (' . esc_html( $searches ? round( 100 * $clicks / $searches, 1 ) : 0 ) . '%) &nbsp; <strong>Sinónimos:</strong> ' . number_format_i18n( (int) ( $summary['synonyms'] ?? 0 ) ) . ' &nbsp; <strong>Aproximadas:</strong> ' . number_format_i18n( (int) ( $summary['fuzzy'] ?? 0 ) ) . '</p>';
		echo '<h2>Evolución diaria</h2><table class="widefat striped"><thead><tr><th>Día (' . esc_html( wp_timezone_string() ) . ')</th><th>Búsquedas</th><th>Sin resultados</th><th>Clics</th></tr></thead><tbody>'; foreach ( $daily as $row ) echo '<tr><td>' . esc_html( $row['day'] ) . '</td><td>' . absint( $row['searches'] ) . '</td><td>' . absint( $row['no_results'] ) . '</td><td>' . absint( $row['clicks'] ) . '</td></tr>'; echo '</tbody></table>';
		echo '<h2>Términos</h2>';
		echo '<table class="widefat striped"><thead><tr><th>Término</th><th>Búsquedas</th><th>Sin resultados</th><th>Clics</th><th>Última búsqueda</th></tr></thead><tbody>'; foreach ( $top as $row ) echo '<tr><td>' . esc_html( $row['term'] ) . '</td><td>' . absint( $row['searches'] ) . '</td><td>' . absint( $row['no_results'] ) . '</td><td>' . absint( $row['clicks'] ) . '</td><td>' . esc_html( get_date_from_gmt( $row['last_search'], 'Y-m-d H:i' ) ) . '</td></tr>'; if ( ! $top ) echo '<tr><td colspan="5">No hay datos para estos filtros.</td></tr>'; echo '</tbody></table>';
		echo '<h2>Palabras sin resultados</h2><p class="description">Lista específica para detectar términos que necesitan sinónimos, correcciones o productos nuevos.</p>';
		echo '<table class="widefat striped"><thead><tr><th>Palabra o frase</th><th>Veces</th><th>Origen</th><th>Última búsqueda</th></tr></thead><tbody>'; foreach ( $no_results as $row ) echo '<tr><td><strong>' . esc_html( $row['term'] ) . '</strong></td><td>' . absint( $row['searches'] ) . '</td><td>' . esc_html( $row['origins'] ) . '</td><td>' . esc_html( get_date_from_gmt( $row['last_search'], 'Y-m-d H:i' ) ) . '</td></tr>'; if ( ! $no_results ) echo '<tr><td colspan="4">No hay búsquedas sin resultados para este rango.</td></tr>'; echo '</tbody></table>';
		echo '<h2>Clics en productos</h2><p class="description">Muestra qué producto se abrió y desde qué término.</p>';
		echo '<table class="widefat striped"><thead><tr><th>Término</th><th>Producto abierto</th><th>Clics</th><th>Último clic</th></tr></thead><tbody>'; foreach ( $clicked as $row ) { $product_id = absint( $row['clicked_product_id'] ); $title = get_the_title( $product_id ) ?: '#' . $product_id; $url = get_permalink( $product_id ); echo '<tr><td>' . esc_html( $row['term'] ) . '</td><td>' . ( $url ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $title ) . '</a>' : esc_html( $title ) ) . '</td><td>' . absint( $row['clicks'] ) . '</td><td>' . esc_html( get_date_from_gmt( $row['last_click'], 'Y-m-d H:i' ) ) . '</td></tr>'; } if ( ! $clicked ) echo '<tr><td colspan="4">No hay clics para este rango.</td></tr>'; echo '</tbody></table>';
	}

	// Groups by 15-minute UTC buckets and converts each one with the site's
	// timezone in PHP, so DST and :30/:45 offsets land on the right local day.
	private function daily_rows( string $table, string $where, array $args ): array {
		global $wpdb;
		$buckets = $wpdb->get_results( $wpdb->prepare( "SELECT DATE_FORMAT(created_at,'%%Y-%%m-%%d %%H:00:00') + INTERVAL FLOOR(MINUTE(created_at)/15)*15 MINUTE bucket,COUNT(*) searches,SUM(results_count=0) no_results,SUM(clicked_product_id IS NOT NULL) clicks FROM {$table} WHERE {$where} GROUP BY bucket", $args ), ARRAY_A );
		$days = array();
		foreach ( $buckets as $bucket ) {
			$day = get_date_from_gmt( $bucket['bucket'], 'Y-m-d' );
			$days[ $day ] ??= array( 'day' => $day, 'searches' => 0, 'no_results' => 0, 'clicks' => 0 );
			foreach ( array( 'searches', 'no_results', 'clicks' ) as $key ) $days[ $day ][ $key ] += (int) $bucket[ $key ];
		}
		ksort( $days );
		return array_values( $days );
	}

	private function report_select( string $label, string $name, string $current, array $options ): void {
		echo '<label>' . esc_html( $label ) . '<br><select name="' . esc_attr( $name ) . '">';
		foreach ( $options as $value => $option_label ) echo '<option value="' . esc_attr( $value ) . '" ' . selected( $current, (string) $value, false ) . '>' . esc_html( $option_label ) . '</option>';
		echo '</select></label>';
	}

	public function export_csv(): void {
		check_admin_referer( 'swoo_export_csv' ); if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'No autorizado.' );
		global $wpdb; list( $from, $to, $start, $end ) = $this->date_range(); $table = $wpdb->prefix . 'swoo_events'; $filters = $this->report_filters();
		list( $where, $args ) = $this->report_where( $start, $end, $filters );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT term,origin,match_type,results_count,clicked_product_id,created_at FROM {$table} WHERE {$where} ORDER BY created_at DESC", $args ), ARRAY_A );
		nocache_headers(); header( 'Content-Type: text/csv; charset=utf-8' ); header( 'Content-Disposition: attachment; filename="search-woo-' . $from . '-' . $to . '.csv"' );
		$out = fopen( 'php://output', 'w' ); fputcsv( $out, array( 'term', 'origin', 'match_type', 'results_count', 'sin_resultados', 'clicked_product_id', 'clicked_product_title', 'clicked_product_url', 'created_at_utc', 'created_at_local' ) );
		foreach ( $rows as $row ) {
			$product_id = absint( $row['clicked_product_id'] );
			$export_row = array( $row['term'], $row['origin'], $row['match_type'], absint( $row['results_count'] ), 0 === absint( $row['results_count'] ) ? 'sí' : 'no', $product_id ?: '', $product_id ? get_the_title( $product_id ) : '', $product_id ? get_permalink( $product_id ) : '', $row['created_at'], get_date_from_gmt( $row['created_at'], 'Y-m-d H:i:s' ) );
			foreach ( $export_row as &$value ) if ( is_string( $value ) && preg_match( '/^[=+\-@]/', $value ) ) $value = "'" . $value;
			unset( $value ); fputcsv( $out, $export_row );
		}
		fclose( $out ); exit;
	}

	public function daily_maintenance(): void {
		global $wpdb;
		$events = $wpdb->prefix . 'swoo_events';
		$daily = $wpdb->prefix . 'swoo_daily';
		$yesterday = wp_date( 'Y-m-d', time() - DAY_IN_SECONDS, wp_timezone() );
		$start_local = new DateTimeImmutable( $yesterday . ' 00:00:00', wp_timezone() );
		$end_local = $start_local->modify( '+1 day' );
		$start = $start_local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$end = $end_local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$daily} WHERE day=%s", $yesterday ) );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$daily} (day,term,searches,no_results,clicks) SELECT %s,term,COUNT(*),SUM(results_count=0),SUM(clicked_product_id IS NOT NULL) FROM {$events} WHERE created_at >= %s AND created_at < %s GROUP BY term", $yesterday, $start, $end ) );
		$days = max( 7, (int) $this->settings['retention_days'] );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$events} WHERE created_at < %s", $cutoff ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$daily} WHERE day < %s", wp_date( 'Y-m-d', time() - 400 * DAY_IN_SECONDS, wp_timezone() ) ) );
	}
}
