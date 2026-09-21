<?php
/**
 * Plugin Name:       eplatforms GDPR Subject Access Request
 * Plugin URI:        https://www.eplatforms.com/
 * Description:       Find, review and export everything Ninja Forms holds about one person, and keep an auditable log of who asked and what was released.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            eplatforms
 * Author URI:        https://www.eplatforms.com/
 * License:           GPL-2.0-or-later
 * Text Domain:       eplatforms-sar
 *
 * Answering a subject access request by hand means someone opening every form in turn and reading
 * submissions looking for a name. On a site with eight thousand submissions across twenty forms
 * that is slow enough that requests get answered late, and thorough enough only if nobody is busy.
 * This does the searching, shows what was found for a human to check, and exports it.
 *
 * Two deliberate constraints:
 *
 *  - Nothing is released without someone choosing to release it. The search shows what matched;
 *    the export includes only what the operator ticked. There is no one-click "send everything".
 *  - Every search and every export is logged, with who ran it and what they searched for. A SAR
 *    tool is a legitimate route to other people's personal data, so the audit trail is not
 *    optional — it is the thing that makes giving anyone access to it defensible.
 */

defined( 'ABSPATH' ) || exit;

define( 'EP_SAR_VERSION', '1.0.0' );
define( 'EP_SAR_FILE', __FILE__ );
define( 'EP_SAR_PATH', plugin_dir_path( __FILE__ ) );
define( 'EP_SAR_URL', plugin_dir_url( __FILE__ ) );

require_once EP_SAR_PATH . 'includes/class-ep-sar.php';
require_once EP_SAR_PATH . 'includes/class-ep-sar-log.php';
require_once EP_SAR_PATH . 'includes/class-ep-sar-search.php';
require_once EP_SAR_PATH . 'includes/class-ep-sar-export.php';
require_once EP_SAR_PATH . 'includes/class-ep-sar-admin.php';

register_activation_hook( __FILE__, array( 'EP_SAR', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'EP_SAR', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'EP_SAR', 'init' ) );
