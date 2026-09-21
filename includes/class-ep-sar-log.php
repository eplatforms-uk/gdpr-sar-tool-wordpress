<?php
/**
 * The request log.
 *
 * A SAR tool is a legitimate route to other people's personal data, so the question "who looked
 * up whom, and when" has to have an answer. Every search and every export is recorded here before
 * the results reach the screen.
 *
 * The log stores the search term, which is itself personal data — usually the requester's email
 * address. It therefore has its own retention period and is purged on a daily schedule, rather
 * than accumulating indefinitely as an audit trail nobody prunes.
 */

defined( 'ABSPATH' ) || exit;

class EP_SAR_Log {

	const TABLE = 'ep_sar_log';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function install(): void {
		global $wpdb;
		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				created_at DATETIME NOT NULL,
				user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				user_login VARCHAR(191) NOT NULL DEFAULT '',
				action VARCHAR(32) NOT NULL DEFAULT '',
				search_term VARCHAR(191) NOT NULL DEFAULT '',
				result_count INT NOT NULL DEFAULT 0,
				detail TEXT NULL,
				ip VARCHAR(64) NOT NULL DEFAULT '',
				PRIMARY KEY (id),
				KEY created_at (created_at),
				KEY user_id (user_id)
			) {$collate};"
		);
	}

	/**
	 * @param string $action  search | export | view
	 * @param string $term    what was searched for
	 * @param int    $count   how many records were involved
	 * @param string $detail  free text, e.g. which forms or which submission ids
	 */
	public static function record( string $action, string $term, int $count = 0, string $detail = '' ): void {
		global $wpdb;
		$user = wp_get_current_user();

		$wpdb->insert(
			self::table(),
			array(
				'created_at'   => current_time( 'mysql', true ),
				'user_id'      => (int) $user->ID,
				'user_login'   => (string) $user->user_login,
				'action'       => $action,
				'search_term'  => mb_substr( $term, 0, 191 ),
				'result_count' => $count,
				'detail'       => $detail,
				'ip'           => self::ip(),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * The client address, taken from REMOTE_ADDR only.
	 *
	 * X-Forwarded-For is attacker-controlled unless the proxy in front is known and trusted, and a
	 * forged address in an audit log is worse than no address at all. Sites behind Cloudflare that
	 * want the real client address should set it at the web server, where the trust boundary is.
	 */
	private static function ip(): string {
		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	public static function recent( int $limit = 100, int $offset = 0 ): array {
		global $wpdb;
		$table = self::table();
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $limit, $offset )
		);
	}

	public static function count(): int {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/** Drop entries past the retention period. Runs daily; 0 months means keep everything. */
	public static function purge(): void {
		$months = (int) EP_SAR::get( 'log_retention' );
		if ( $months <= 0 ) {
			return;
		}
		global $wpdb;
		$table = self::table();
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d MONTH)",
				$months
			)
		);
	}
}
