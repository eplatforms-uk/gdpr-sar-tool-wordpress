<?php
/**
 * Settings, capability and lifecycle.
 */

defined( 'ABSPATH' ) || exit;

class EP_SAR {

	const OPTION = 'ep_sar_settings';

	/**
	 * The capability that gates everything.
	 *
	 * A dedicated capability rather than checking for 'manage_options' directly, so the site owner
	 * can give a DPO or an office manager access to subject access requests without also handing
	 * them plugin installation and user administration.
	 */
	const CAP = 'ep_sar_manage';

	public static function defaults(): array {
		return array(
			// Roles allowed to run requests. Administrator is not listed because it always has the
			// capability; these are the extra roles granted it.
			'roles'          => array(),
			// Months to keep the request log. 0 keeps it indefinitely.
			'log_retention'  => 24,
			// Feed results into WordPress's own Tools > Export Personal Data.
			'wp_exporter'    => 1,
			// Cap on rows a single search may return, so one broad term cannot try to load the
			// whole submissions table into memory.
			'result_limit'   => 500,
		);
	}

	public static function settings(): array {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	public static function get( string $key ) {
		$s = self::settings();
		return $s[ $key ] ?? null;
	}

	/** Is Ninja Forms present and new enough to have the submission schema we read? */
	public static function ninja_forms_ready(): bool {
		return class_exists( 'Ninja_Forms' ) && post_type_exists( 'nf_sub' );
	}

	public static function init(): void {
		EP_SAR_Admin::init();
		if ( self::get( 'wp_exporter' ) ) {
			add_filter( 'wp_privacy_personal_data_exporters', array( 'EP_SAR_Export', 'register_wp_exporter' ) );
		}
		add_action( 'ep_sar_purge_log', array( 'EP_SAR_Log', 'purge' ) );
	}

	public static function activate(): void {
		add_option( self::OPTION, self::defaults() );
		EP_SAR_Log::install();
		self::sync_capabilities();
		if ( ! wp_next_scheduled( 'ep_sar_purge_log' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ep_sar_purge_log' );
		}
	}

	public static function deactivate(): void {
		$ts = wp_next_scheduled( 'ep_sar_purge_log' );
		if ( $ts ) {
			wp_unschedule_event( $ts, 'ep_sar_purge_log' );
		}
	}

	/**
	 * Put the capability on the roles the settings name, and take it off the rest.
	 *
	 * Run on every settings save rather than only on grant, or a role removed from the list would
	 * quietly keep its access.
	 */
	public static function sync_capabilities(): void {
		$allowed = (array) self::get( 'roles' );
		$allowed[] = 'administrator';

		foreach ( wp_roles()->roles as $slug => $_role ) {
			$role = get_role( $slug );
			if ( ! $role ) {
				continue;
			}
			if ( in_array( $slug, $allowed, true ) ) {
				$role->add_cap( self::CAP );
			} elseif ( $role->has_cap( self::CAP ) ) {
				$role->remove_cap( self::CAP );
			}
		}
	}

	/** Every entry point calls this. */
	public static function require_cap(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die(
				esc_html__( 'You do not have permission to handle subject access requests on this site.', 'eplatforms-sar' ),
				esc_html__( 'Permission denied', 'eplatforms-sar' ),
				array( 'response' => 403 )
			);
		}
	}
}
