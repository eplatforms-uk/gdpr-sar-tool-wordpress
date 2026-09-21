<?php
/**
 * The admin screens: search, log, settings.
 */

defined( 'ABSPATH' ) || exit;

class EP_SAR_Admin {

	const SLUG = 'ep-sar';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_notices', array( __CLASS__, 'dependency_notice' ) );
	}

	public static function menu(): void {
		add_menu_page(
			__( 'Subject Access Requests', 'eplatforms-sar' ),
			__( 'GDPR SAR', 'eplatforms-sar' ),
			EP_SAR::CAP,
			self::SLUG,
			array( __CLASS__, 'page_search' ),
			'dashicons-shield-alt',
			76
		);
		add_submenu_page( self::SLUG, __( 'Search', 'eplatforms-sar' ), __( 'Search', 'eplatforms-sar' ), EP_SAR::CAP, self::SLUG, array( __CLASS__, 'page_search' ) );
		add_submenu_page( self::SLUG, __( 'Request log', 'eplatforms-sar' ), __( 'Request log', 'eplatforms-sar' ), EP_SAR::CAP, self::SLUG . '-log', array( __CLASS__, 'page_log' ) );
		add_submenu_page( self::SLUG, __( 'Settings', 'eplatforms-sar' ), __( 'Settings', 'eplatforms-sar' ), 'manage_options', self::SLUG . '-settings', array( __CLASS__, 'page_settings' ) );
	}

	public static function dependency_notice(): void {
		if ( ! current_user_can( EP_SAR::CAP ) || EP_SAR::ninja_forms_ready() ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, self::SLUG ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Ninja Forms is not active, so there are no submissions to search.', 'eplatforms-sar' )
		);
	}

	/* ---------------------------------------------------------------- search */

	public static function page_search(): void {
		EP_SAR::require_cap();

		$term    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$records = array();
		$capped  = false;
		$ran     = false;

		if ( '' !== $term && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'ep_sar_search' ) ) {
			$ran = true;
			list( $records, $capped ) = EP_SAR_Search::find( $term, (int) EP_SAR::get( 'result_limit' ) );
			// Logged before anything is displayed: the search itself is the access event, whether
			// or not the operator goes on to export.
			EP_SAR_Log::record( 'search', $term, count( $records ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Subject Access Requests', 'eplatforms-sar' ); ?></h1>
			<p class="description" style="max-width:52em;">
				<?php esc_html_e( 'Search every Ninja Forms submission for a name, email address, phone number or any other detail the person has given you. Check the results, tick the ones that are genuinely theirs, and export.', 'eplatforms-sar' ); ?>
			</p>

			<form method="get" style="margin:18px 0 26px;">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<?php wp_nonce_field( 'ep_sar_search', '_wpnonce', false ); ?>
				<input type="search" name="s" value="<?php echo esc_attr( $term ); ?>"
				       class="regular-text" style="min-width:26em;"
				       placeholder="<?php esc_attr_e( 'Email address, name, phone number…', 'eplatforms-sar' ); ?>">
				<?php submit_button( __( 'Search submissions', 'eplatforms-sar' ), 'primary', 'go', false ); ?>
			</form>

			<?php if ( $ran && ! $records ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php echo esc_html( sprintf(
						/* translators: %s: the search term */
						__( 'No submissions contain “%s”. Searches need at least three characters.', 'eplatforms-sar' ),
						$term
					) ); ?>
				</p></div>
			<?php endif; ?>

			<?php if ( $capped ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php echo esc_html( sprintf(
						/* translators: %d: the configured result limit */
						__( 'Showing the first %d matches only. Narrow the search — a full email address matches far more precisely than a first name.', 'eplatforms-sar' ),
						(int) EP_SAR::get( 'result_limit' )
					) ); ?>
				</p></div>
			<?php endif; ?>

			<?php if ( $records ) : ?>
				<form method="post">
					<?php wp_nonce_field( 'ep_sar_export' ); ?>
					<input type="hidden" name="ep_sar_term" value="<?php echo esc_attr( $term ); ?>">

					<p>
						<strong><?php echo esc_html( sprintf(
							/* translators: 1: number of submissions, 2: the search term */
							_n( '%1$d submission mentions “%2$s”.', '%1$d submissions mention “%2$s”.', count( $records ), 'eplatforms-sar' ),
							count( $records ),
							$term
						) ); ?></strong>
						<?php esc_html_e( 'Not all of them are necessarily the same person — check before releasing.', 'eplatforms-sar' ); ?>
					</p>

					<p>
						<button type="submit" name="ep_sar_action" value="csv" class="button button-primary"><?php esc_html_e( 'Export selected as CSV', 'eplatforms-sar' ); ?></button>
						<button type="submit" name="ep_sar_action" value="json" class="button"><?php esc_html_e( 'Export selected as JSON', 'eplatforms-sar' ); ?></button>
					</p>

					<?php foreach ( $records as $r ) : ?>
						<div class="card" style="max-width:none;margin:0 0 14px;padding:14px 18px;">
							<p style="margin:0 0 10px;">
								<label style="font-weight:600;">
									<input type="checkbox" name="ep_sar_ids[]" value="<?php echo esc_attr( $r['id'] ); ?>" checked>
									<?php echo esc_html( $r['form_title'] ); ?>
								</label>
								<span style="color:#666;">
									&mdash; <?php echo esc_html( mysql2date( 'j M Y, H:i', $r['date'] ) ); ?>
									&middot; <?php echo esc_html( sprintf( __( 'submission #%d', 'eplatforms-sar' ), $r['id'] ) ); ?>
								</span>
								<?php if ( ! empty( $r['matched'] ) ) : ?>
									<br><span style="color:#666;font-size:12px;">
										<?php echo esc_html( sprintf(
											/* translators: %s: comma separated field labels */
											__( 'matched in: %s', 'eplatforms-sar' ),
											implode( ', ', array_unique( $r['matched'] ) )
										) ); ?>
									</span>
								<?php endif; ?>
							</p>
							<table class="widefat striped" style="margin:0;">
								<tbody>
								<?php foreach ( $r['answers'] as $a ) : ?>
									<tr>
										<th scope="row" style="width:32%;"><?php echo esc_html( $a['label'] ); ?></th>
										<td><?php echo nl2br( esc_html( $a['value'] ) ); ?></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endforeach; ?>

					<p>
						<button type="submit" name="ep_sar_action" value="csv" class="button button-primary"><?php esc_html_e( 'Export selected as CSV', 'eplatforms-sar' ); ?></button>
						<button type="submit" name="ep_sar_action" value="json" class="button"><?php esc_html_e( 'Export selected as JSON', 'eplatforms-sar' ); ?></button>
					</p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Exports run on admin_init so the file can be streamed before any HTML is sent. */
	public static function handle_export(): void {
		if ( ! isset( $_POST['ep_sar_action'] ) ) {
			return;
		}
		EP_SAR::require_cap();
		check_admin_referer( 'ep_sar_export' );

		$ids  = array_map( 'intval', (array) ( $_POST['ep_sar_ids'] ?? array() ) );
		$ids  = array_values( array_filter( $ids ) );
		$term = sanitize_text_field( wp_unslash( $_POST['ep_sar_term'] ?? '' ) );

		if ( ! $ids ) {
			wp_safe_redirect( add_query_arg(
				array( 'page' => self::SLUG, 's' => rawurlencode( $term ), '_wpnonce' => wp_create_nonce( 'ep_sar_search' ), 'ep_sar_empty' => 1 ),
				admin_url( 'admin.php' )
			) );
			exit;
		}

		$action = sanitize_key( wp_unslash( $_POST['ep_sar_action'] ) );
		if ( 'json' === $action ) {
			EP_SAR_Export::json( $ids, $term );
		}
		EP_SAR_Export::csv( $ids, $term );
	}

	/* ------------------------------------------------------------------- log */

	public static function page_log(): void {
		EP_SAR::require_cap();
		$per   = 50;
		$page  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$total = EP_SAR_Log::count();
		$rows  = EP_SAR_Log::recent( $per, ( $page - 1 ) * $per );
		$keep  = (int) EP_SAR::get( 'log_retention' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Request log', 'eplatforms-sar' ); ?></h1>
			<p class="description" style="max-width:52em;">
				<?php
				echo esc_html(
					$keep > 0
						? sprintf(
							/* translators: %d: months */
							__( 'Every search and export is recorded here. Entries are deleted automatically after %d months, because the search terms are themselves personal data.', 'eplatforms-sar' ),
							$keep
						)
						: __( 'Every search and export is recorded here. Entries are kept indefinitely; the search terms are themselves personal data, so consider setting a retention period.', 'eplatforms-sar' )
				);
				?>
			</p>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'When', 'eplatforms-sar' ); ?></th>
					<th><?php esc_html_e( 'Who', 'eplatforms-sar' ); ?></th>
					<th><?php esc_html_e( 'Action', 'eplatforms-sar' ); ?></th>
					<th><?php esc_html_e( 'Searched for', 'eplatforms-sar' ); ?></th>
					<th><?php esc_html_e( 'Records', 'eplatforms-sar' ); ?></th>
					<th><?php esc_html_e( 'Address', 'eplatforms-sar' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'Nothing recorded yet.', 'eplatforms-sar' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td><?php echo esc_html( get_date_from_gmt( $r->created_at, 'j M Y, H:i' ) ); ?></td>
						<td><?php echo esc_html( $r->user_login ?: '—' ); ?></td>
						<td><?php echo esc_html( $r->action ); ?></td>
						<td><?php echo esc_html( $r->search_term ); ?></td>
						<td><?php echo (int) $r->result_count; ?></td>
						<td><code><?php echo esc_html( $r->ip ?: '—' ); ?></code></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			$pages = (int) ceil( $total / $per );
			if ( $pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post( paginate_links( array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'total'   => $pages,
					'current' => $page,
				) ) );
				echo '</div></div>';
			}
			?>
		</div>
		<?php
	}

	/* -------------------------------------------------------------- settings */

	public static function register_settings(): void {
		register_setting( 'ep_sar', EP_SAR::OPTION, array(
			'type'              => 'array',
			'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			'default'           => EP_SAR::defaults(),
		) );
	}

	public static function sanitize( $in ): array {
		$in  = (array) $in;
		$out = EP_SAR::defaults();

		$roles = array();
		foreach ( (array) ( $in['roles'] ?? array() ) as $r ) {
			$r = sanitize_key( $r );
			if ( 'administrator' !== $r && get_role( $r ) ) {
				$roles[] = $r;
			}
		}
		$out['roles']         = $roles;
		$out['log_retention'] = max( 0, min( 120, (int) ( $in['log_retention'] ?? 24 ) ) );
		$out['wp_exporter']   = empty( $in['wp_exporter'] ) ? 0 : 1;
		$out['result_limit']  = max( 50, min( 5000, (int) ( $in['result_limit'] ?? 500 ) ) );

		// The option is written after this returns, so the capability sync has to read the value
		// being saved rather than the one still in the database.
		add_action( 'update_option_' . EP_SAR::OPTION, array( 'EP_SAR', 'sync_capabilities' ), 10, 0 );
		add_action( 'add_option_' . EP_SAR::OPTION, array( 'EP_SAR', 'sync_capabilities' ), 10, 0 );

		return $out;
	}

	public static function page_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'eplatforms-sar' ) );
		}
		$s = EP_SAR::settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Subject Access Request settings', 'eplatforms-sar' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'ep_sar' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Who can handle requests', 'eplatforms-sar' ); ?></th>
						<td>
							<p style="margin-top:0;"><label><input type="checkbox" checked disabled> <?php esc_html_e( 'Administrator', 'eplatforms-sar' ); ?></label>
								<span class="description"><?php esc_html_e( '(always)', 'eplatforms-sar' ); ?></span></p>
							<?php foreach ( wp_roles()->roles as $slug => $role ) : ?>
								<?php if ( 'administrator' === $slug ) { continue; } ?>
								<p><label>
									<input type="checkbox" name="<?php echo esc_attr( EP_SAR::OPTION ); ?>[roles][]"
									       value="<?php echo esc_attr( $slug ); ?>"
										<?php checked( in_array( $slug, (array) $s['roles'], true ) ); ?>>
									<?php echo esc_html( translate_user_role( $role['name'] ) ); ?>
								</label></p>
							<?php endforeach; ?>
							<p class="description" style="max-width:44em;">
								<?php esc_html_e( 'These screens show other people’s personal data and can export it, so access is limited to roles you choose here rather than being open to every logged-in user. Everything anyone does is recorded in the request log.', 'eplatforms-sar' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ep_sar_retention"><?php esc_html_e( 'Keep the request log for', 'eplatforms-sar' ); ?></label></th>
						<td>
							<input type="number" id="ep_sar_retention" min="0" max="120" class="small-text"
							       name="<?php echo esc_attr( EP_SAR::OPTION ); ?>[log_retention]"
							       value="<?php echo esc_attr( (int) $s['log_retention'] ); ?>">
							<?php esc_html_e( 'months', 'eplatforms-sar' ); ?>
							<p class="description"><?php esc_html_e( '0 keeps it forever. The log holds the terms people searched for, which are usually themselves personal data.', 'eplatforms-sar' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ep_sar_limit"><?php esc_html_e( 'Maximum results per search', 'eplatforms-sar' ); ?></label></th>
						<td>
							<input type="number" id="ep_sar_limit" min="50" max="5000" class="small-text"
							       name="<?php echo esc_attr( EP_SAR::OPTION ); ?>[result_limit]"
							       value="<?php echo esc_attr( (int) $s['result_limit'] ); ?>">
							<p class="description"><?php esc_html_e( 'Stops a two-letter search trying to load every submission on the site.', 'eplatforms-sar' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'WordPress export tool', 'eplatforms-sar' ); ?></th>
						<td>
							<label>
								<input type="checkbox" value="1"
								       name="<?php echo esc_attr( EP_SAR::OPTION ); ?>[wp_exporter]"
									<?php checked( ! empty( $s['wp_exporter'] ) ); ?>>
								<?php esc_html_e( 'Include form submissions in Tools → Export Personal Data', 'eplatforms-sar' ); ?>
							</label>
							<p class="description" style="max-width:44em;">
								<?php esc_html_e( 'WordPress’s own tool confirms the requester’s identity by email before sending anything. It matches on email address only, so it finds less than the search screen does.', 'eplatforms-sar' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
