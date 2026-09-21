<?php
/**
 * Uninstall.
 *
 * Removes the settings, the capability and the request log. The log is deleted rather than kept:
 * it holds the terms people searched for, which are personal data, and leaving it behind after
 * someone has deliberately removed the plugin would be keeping data with no controller.
 *
 * Ninja Forms submissions are never touched — they are not this plugin's data.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'ep_sar_settings' );

$table = $wpdb->prefix . 'ep_sar_log';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

foreach ( wp_roles()->roles as $slug => $_role ) {
	$role = get_role( $slug );
	if ( $role && $role->has_cap( 'ep_sar_manage' ) ) {
		$role->remove_cap( 'ep_sar_manage' );
	}
}

$ts = wp_next_scheduled( 'ep_sar_purge_log' );
if ( $ts ) {
	wp_unschedule_event( $ts, 'ep_sar_purge_log' );
}
