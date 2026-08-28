<?php

defined( 'ABSPATH' ) || exit;

final class Adv_Search_Admin {
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_adv_search_clear_cache', array( __CLASS__, 'clear_cache' ) );
	}

	public static function menu() {
		add_theme_page(
			__( 'Paieška', 'alps' ),
			__( 'Paieška', 'alps' ),
			'manage_options',
			'adv-search',
			array( __CLASS__, 'page' )
		);
	}

	public static function settings() {
		register_setting(
			'adv_search_settings',
			'adv_search_options',
			array( 'sanitize_callback' => array( 'Adv_Search', 'sanitize_options' ) )
		);
	}

	public static function assets( $hook ) {
		if ( 'appearance_page_adv-search' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		$file = get_template_directory() . '/assets/js/adv-search-admin.js';
		wp_enqueue_script( 'adv-search-admin', get_template_directory_uri() . '/assets/js/adv-search-admin.js', array( 'wp-color-picker' ), is_readable( $file ) ? filemtime( $file ) : '1', true );
		wp_localize_script(
			'adv-search-admin',
			'AdvSearchAdmin',
			array(
				'endpoint' => esc_url_raw( rest_url( 'adventistai/v1/search/reindex' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'working'  => __( 'Kuriamas indeksas…', 'alps' ),
				'done'     => __( 'Indeksas sukurtas.', 'alps' ),
				'error'    => __( 'Indekso sukurti nepavyko.', 'alps' ),
			)
		);
	}

	public static function clear_cache() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Neturite teisės atlikti šio veiksmo.', 'alps' ) );
		}
		check_admin_referer( 'adv_search_clear_cache' );
		Adv_Search_Indexer::flush_cache();
		wp_safe_redirect( add_query_arg( array( 'page' => 'adv-search', 'tab' => 'index', 'cache-cleared' => 1 ), admin_url( 'themes.php' ) ) );
		exit;
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tabs    = array(
			'general'  => __( 'Bendra', 'alps' ),
			'matching' => __( 'Atitikimas', 'alps' ),
			'ranking'  => __( 'Rikiavimas', 'alps' ),
			'display'  => __( 'Rodymas', 'alps' ),
			'index'    => __( 'Indeksas', 'alps' ),
		);
		$current = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $tabs[ $current ] ) ? $current : 'general';
		$options = Adv_Search::options();
		?>
		<div class="wrap adv-search-admin">
			<h1><?php esc_html_e( 'Adventistai paieška', 'alps' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a class="nav-tab <?php echo $current === $key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'adv-search', 'tab' => $key ), admin_url( 'themes.php' ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php settings_errors(); ?>
			<?php if ( 'index' === $current ) : ?>
				<?php self::index_tab(); ?>
			<?php else : ?>
				<form method="post" action="options.php">
					<?php settings_fields( 'adv_search_settings' ); ?>
					<?php self::hidden_current_options( $options ); ?>
					<table class="form-table" role="presentation">
						<?php call_user_func( array( __CLASS__, $current . '_tab' ), $options ); ?>
					</table>
					<?php submit_button(); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function general_tab( $o ) {
		self::checkbox_row( 'enabled', __( 'Įjungti išplėstinę paiešką', 'alps' ), $o['enabled'], __( 'Pagrindinis jungiklis. Išjungus grąžinama įprasta WordPress paieška.', 'alps' ) );
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		?>
		<tr><th scope="row"><?php esc_html_e( 'Ieškomi turinio tipai', 'alps' ); ?></th><td>
			<input type="hidden" name="adv_search_options[post_types][]" value="">
			<?php foreach ( $post_types as $type ) : ?>
				<label style="display:block"><input type="checkbox" name="adv_search_options[post_types][]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, $o['post_types'], true ) ); ?>> <?php echo esc_html( $type->labels->singular_name . ' (' . $type->name . ')' ); ?></label>
			<?php endforeach; ?>
			<p class="description"><?php esc_html_e( 'Tai aiškus leidžiamų tipų sąrašas. Pagal numatymą įtraukti tik įrašai ir puslapiai; naujų įskiepių tipai nebus pridėti automatiškai.', 'alps' ); ?></p>
		</td></tr>
		<?php
		self::text_row( 'exclude_ids', __( 'Neįtraukti įrašų ID', 'alps' ), implode( ', ', $o['exclude_ids'] ), __( 'ID atskirkite kableliais.', 'alps' ) );
		self::text_row( 'exclude_categories', __( 'Neįtraukti kategorijų ID', 'alps' ), implode( ', ', $o['exclude_categories'] ) );
		self::text_row( 'exclude_tags', __( 'Neįtraukti žymų ID', 'alps' ), implode( ', ', $o['exclude_tags'] ) );
		self::checkbox_row( 'include_excerpt', __( 'Ieškoti ištraukoje', 'alps' ), $o['include_excerpt'] );
		self::checkbox_row( 'include_custom_fields', __( 'Ieškoti pasirinktiniuose laukuose', 'alps' ), $o['include_custom_fields'], __( 'Išjungta pagal numatymą. Įjungiami tik žemiau įrašyti raktai.', 'alps' ) );
		self::text_row( 'custom_field_keys', __( 'Leidžiami laukų raktai', 'alps' ), implode( ', ', $o['custom_field_keys'] ) );
		?>
		<tr><th scope="row"><?php esc_html_e( 'Apsauga', 'alps' ); ?></th><td><p><?php esc_html_e( 'Juodraščiai, privatūs, ištrinti ir slaptažodžiu apsaugoti įrašai visada neįtraukiami.', 'alps' ); ?></p></td></tr>
		<?php
	}

	private static function matching_tab( $o ) {
		self::number_row( 'min_chars', __( 'Mažiausiai simbolių tiesioginei paieškai', 'alps' ), $o['min_chars'], 1, 20 );
		self::number_row( 'min_term_length', __( 'Mažiausias termino ilgis', 'alps' ), $o['min_term_length'], 1, 20 );
		self::number_row( 'max_terms', __( 'Daugiausia terminų', 'alps' ), $o['max_terms'], 1, 20 );
		?>
		<tr><th scope="row"><label for="adv-operator"><?php esc_html_e( 'Kelių terminų operatorius', 'alps' ); ?></label></th><td><select id="adv-operator" name="adv_search_options[operator]"><option value="AND" <?php selected( $o['operator'], 'AND' ); ?>>AND</option><option value="OR" <?php selected( $o['operator'], 'OR' ); ?>>OR</option></select></td></tr>
		<?php
		self::checkbox_row( 'allow_phrases', __( 'Leisti kabutėse įrašytas frazes', 'alps' ), $o['allow_phrases'] );
		?>
		<tr><th scope="row"><label for="adv-stopwords"><?php esc_html_e( 'Ignoruojami žodžiai', 'alps' ); ?></label></th><td><textarea id="adv-stopwords" class="large-text" rows="5" name="adv_search_options[stopwords]"><?php echo esc_textarea( implode( ', ', $o['stopwords'] ) ); ?></textarea><p class="description"><?php esc_html_e( 'Ignoruojami tik tada, kai užklausoje yra kitų terminų.', 'alps' ); ?></p></td></tr>
		<?php
	}

	private static function ranking_tab( $o ) {
		$labels = array(
			'exact_title'     => __( 'Visas pavadinimas sutampa', 'alps' ),
			'title_starts'    => __( 'Pavadinimas prasideda užklausa', 'alps' ),
			'title_first'     => __( 'Pirmasis pavadinimo žodis', 'alps' ),
			'title_token'     => __( 'Kitas pavadinimo žodis', 'alps' ),
			'excerpt'         => __( 'Ištrauka', 'alps' ),
			'content'         => __( 'Turinys', 'alps' ),
			'all_title_bonus' => __( 'Visi terminai pavadinime', 'alps' ),
			'page_multiplier' => __( 'Puslapio daugiklis', 'alps' ),
		);
		foreach ( $labels as $key => $label ) {
			printf( '<tr><th scope="row"><label for="adv-w-%1$s">%2$s</label></th><td><input id="adv-w-%1$s" type="number" step="0.1" min="0" name="adv_search_options[weights][%1$s]" value="%3$s" data-adv-weight="%1$s"></td></tr>', esc_attr( $key ), esc_html( $label ), esc_attr( $o['weights'][ $key ] ) );
		}
		?>
		<tr><th></th><td><button type="button" class="button" data-adv-reset-weights data-defaults="<?php echo esc_attr( wp_json_encode( Adv_Search::defaults()['weights'] ) ); ?>"><?php esc_html_e( 'Atkurti numatytus svorius', 'alps' ); ?></button><p class="description"><?php esc_html_e( 'Rezultatai su atitikmeniu pavadinime visada rodomi aukščiau už rezultatus, rastus tik turinyje, nepaisant svorių.', 'alps' ); ?></p></td></tr>
		<?php
	}

	private static function display_tab( $o ) {
		self::number_row( 'per_page_default', __( 'Numatytas rezultatų skaičius', 'alps' ), $o['per_page_default'], 1, 100 );
		self::text_row( 'per_page_options', __( 'Leidžiami rezultatų skaičiai', 'alps' ), implode( ', ', $o['per_page_options'] ) );
		self::checkbox_row( 'live_enabled', __( 'Tiesioginė paieška', 'alps' ), $o['live_enabled'] );
		self::number_row( 'live_delay', __( 'Uždelsimas (ms)', 'alps' ), $o['live_delay'], 100, 2000 );
		self::number_row( 'live_limit', __( 'Tiesioginių rezultatų limitas', 'alps' ), $o['live_limit'], 1, 20 );
		self::number_row( 'snippet_length', __( 'Ištraukos ilgis', 'alps' ), $o['snippet_length'], 60, 1000 );
		self::number_row( 'max_snippets', __( 'Daugiausia ištraukų', 'alps' ), $o['max_snippets'], 1, 3 );
		self::text_row( 'highlight_tag', __( 'Paryškinimo HTML žyma', 'alps' ), $o['highlight_tag'], __( 'Leidžiama: mark, span arba em.', 'alps' ) );
		self::text_row( 'highlight_class', __( 'Paryškinimo CSS klasė', 'alps' ), $o['highlight_class'] );
		self::text_row( 'highlight_color', __( 'Paryškinimo spalva', 'alps' ), $o['highlight_color'], '', 'adv-color-field' );
		self::checkbox_row( 'show_type', __( 'Rodyti turinio tipą', 'alps' ), $o['show_type'] );
		self::checkbox_row( 'show_date', __( 'Rodyti datą', 'alps' ), $o['show_date'] );
		self::checkbox_row( 'show_matched_in', __( 'Rodyti, kur rasta', 'alps' ), $o['show_matched_in'] );
		self::checkbox_row( 'show_filters', __( 'Rodyti tipų filtrus', 'alps' ), $o['show_filters'] );
		self::text_row( 'zero_message', __( 'Pranešimas, kai nieko nerasta', 'alps' ), $o['zero_message'] );
		self::number_row( 'cache_ttl', __( 'Talpyklos trukmė (sek.)', 'alps' ), $o['cache_ttl'], 0, DAY_IN_SECONDS );
		self::number_row( 'rate_limit_count', __( 'Tiesioginių užklausų limitas', 'alps' ), $o['rate_limit_count'], 1, 1000 );
		self::number_row( 'rate_limit_window', __( 'Limito langas (sek.)', 'alps' ), $o['rate_limit_window'], 10, HOUR_IN_SECONDS );
		?>
		<tr><th scope="row"><label for="adv-suggestions"><?php esc_html_e( 'Nuorodos, kai nieko nerasta', 'alps' ); ?></label></th><td><textarea id="adv-suggestions" class="large-text" rows="5" name="adv_search_options[suggested_links]"><?php echo esc_textarea( self::links_text( $o['suggested_links'] ) ); ?></textarea><p class="description"><?php esc_html_e( 'Viena eilutė: Pavadinimas | URL', 'alps' ); ?></p></td></tr>
		<?php
	}

	private static function index_tab() {
		$status = Adv_Search_Schema::status();
		?>
		<table class="widefat striped" style="max-width:850px;margin-top:20px"><tbody>
			<tr><th><?php esc_html_e( 'Būsena', 'alps' ); ?></th><td><?php echo $status['ready'] ? esc_html__( 'Lentelės paruoštos', 'alps' ) : esc_html__( 'Lentelių nėra', 'alps' ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Indeksuoti įrašai', 'alps' ); ?></th><td><?php echo esc_html( number_format_i18n( $status['rows'] ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Žodžių eilutės', 'alps' ); ?></th><td><?php echo esc_html( number_format_i18n( $status['tokens'] ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Lentelių dydis', 'alps' ); ?></th><td><?php echo esc_html( size_format( $status['size_bytes'] ) ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Paskutinis kūrimas', 'alps' ); ?></th><td><?php echo esc_html( $status['last_build'] ?: '—' ); ?></td></tr>
			<tr><th>MySQL / MariaDB</th><td><?php echo esc_html( $status['db_version'] ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Duomenų bazės palyginimas', 'alps' ); ?></th><td><?php echo esc_html( $status['collation'] ?: '—' ); ?></td></tr>
			<tr><th>mbstring / intl</th><td><?php echo esc_html( ( $status['mbstring'] ? 'mbstring ✓' : 'mbstring ✗' ) . ' · ' . ( $status['intl'] ? 'intl ✓' : 'intl —' ) ); ?></td></tr>
		</tbody></table>
		<p><button type="button" class="button button-primary" data-adv-reindex><?php esc_html_e( 'Perkurti indeksą', 'alps' ); ?></button> <progress data-adv-progress value="0" max="100" hidden></progress> <span data-adv-progress-text></span></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="adv_search_clear_cache">
			<?php wp_nonce_field( 'adv_search_clear_cache' ); ?>
			<?php submit_button( __( 'Išvalyti paieškos talpyklą', 'alps' ), 'secondary', 'submit', false ); ?>
		</form>
		<p class="description"><?php esc_html_e( 'Pilnas indeksas kuriamas paketais po 50 įrašų ir gali būti tęsiamas neužlaikant vienos ilgos užklausos.', 'alps' ); ?></p>
		<?php
	}

	private static function hidden_current_options( $options ) {
		unset( $options['enabled'] );
		foreach ( $options as $key => $value ) {
			if ( is_array( $value ) ) {
				continue;
			}
			printf( '<input type="hidden" name="adv_search_options[%s]" value="%s">', esc_attr( $key ), esc_attr( $value ) );
		}
	}

	private static function checkbox_row( $key, $label, $checked, $description = '' ) {
		printf( '<tr><th scope="row">%1$s</th><td><input type="hidden" name="adv_search_options[%2$s]" value="0"><label><input type="checkbox" name="adv_search_options[%2$s]" value="1" %3$s> %4$s</label></td></tr>', esc_html( $label ), esc_attr( $key ), checked( $checked, true, false ), esc_html( $description ) );
	}

	private static function text_row( $key, $label, $value, $description = '', $class = 'regular-text' ) {
		printf( '<tr><th scope="row"><label for="adv-%1$s">%2$s</label></th><td><input id="adv-%1$s" class="%5$s" type="text" name="adv_search_options[%1$s]" value="%3$s"><p class="description">%4$s</p></td></tr>', esc_attr( $key ), esc_html( $label ), esc_attr( $value ), esc_html( $description ), esc_attr( $class ) );
	}

	private static function number_row( $key, $label, $value, $min, $max ) {
		printf( '<tr><th scope="row"><label for="adv-%1$s">%2$s</label></th><td><input id="adv-%1$s" type="number" min="%4$d" max="%5$d" name="adv_search_options[%1$s]" value="%3$d"></td></tr>', esc_attr( $key ), esc_html( $label ), (int) $value, (int) $min, (int) $max );
	}

	private static function links_text( $links ) {
		$lines = array();
		foreach ( $links as $link ) {
			$lines[] = $link['label'] . ' | ' . $link['url'];
		}
		return implode( "\n", $lines );
	}
}
