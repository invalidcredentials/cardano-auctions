<?php
/**
 * Plugin Name: Cardano Auctions
 * Plugin URI:  https://cardanowordpressplugins.com
 * Description: Cardano NFT auctions with escrow-based bidding, automatic refunds, and on-chain settlement. Fully self-contained — no plugin dependencies.
 * Version:     1.4.0
 * Author:      Pb
 * Author URI:  https://cardanowordpressplugins.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cardano-auctions
 * Requires PHP: 7.4
 * Requires at least: 5.9
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ─── Constants ─────────────────────────────────────────────────────── */
define( 'CA_VERSION',     '1.4.0' );
define( 'CA_PLUGIN_FILE', __FILE__ );
define( 'CA_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'CA_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'CA_PLUGIN_BASE', plugin_basename( __FILE__ ) );

/* ─── Embedded Cardano primitives ───────────────────────────────────── */
require_once CA_PLUGIN_DIR . 'includes/cardano/class-ed25519-compat.php';
require_once CA_PLUGIN_DIR . 'includes/cardano/class-ed25519-pure.php';
require_once CA_PLUGIN_DIR . 'includes/cardano/class-wallet.php';
require_once CA_PLUGIN_DIR . 'includes/cardano/class-tx-signer.php';

/* ─── Core classes ──────────────────────────────────────────────────── */
require_once CA_PLUGIN_DIR . 'includes/core/class-encryption.php';
require_once CA_PLUGIN_DIR . 'includes/core/class-settings.php';
require_once CA_PLUGIN_DIR . 'includes/core/class-blockfrost.php';
require_once CA_PLUGIN_DIR . 'includes/core/class-anvil.php';
require_once CA_PLUGIN_DIR . 'includes/core/class-escrow.php';
require_once CA_PLUGIN_DIR . 'includes/core/class-master-wallet.php';
require_once CA_PLUGIN_DIR . 'includes/core/class-auction-engine.php';
require_once CA_PLUGIN_DIR . 'includes/core/class-payment-monitor.php';
require_once CA_PLUGIN_DIR . 'includes/core/class-refund-processor.php';
require_once CA_PLUGIN_DIR . 'includes/core/class-settlement.php';
require_once CA_PLUGIN_DIR . 'includes/core/class-pinata.php';
require_once CA_PLUGIN_DIR . 'includes/core/class-mint-policy.php';
require_once CA_PLUGIN_DIR . 'includes/core/class-metadata-builder.php';
require_once CA_PLUGIN_DIR . 'includes/core/class-minter.php';
require_once CA_PLUGIN_DIR . 'includes/core/class-api-keys.php';

/* ─── Models ────────────────────────────────────────────────────────── */
require_once CA_PLUGIN_DIR . 'includes/models/class-auction-model.php';
require_once CA_PLUGIN_DIR . 'includes/models/class-bid-model.php';
require_once CA_PLUGIN_DIR . 'includes/models/class-escrow-model.php';
require_once CA_PLUGIN_DIR . 'includes/models/class-master-wallet-model.php';
require_once CA_PLUGIN_DIR . 'includes/models/class-mint-policy-model.php';
require_once CA_PLUGIN_DIR . 'includes/models/class-event-log-model.php';

/* ─── WordPress integration ─────────────────────────────────────────── */
require_once CA_PLUGIN_DIR . 'includes/wp/class-plugin.php';
require_once CA_PLUGIN_DIR . 'includes/wp/class-admin.php';
require_once CA_PLUGIN_DIR . 'includes/wp/class-admin-auctions.php';
require_once CA_PLUGIN_DIR . 'includes/wp/class-assets.php';
require_once CA_PLUGIN_DIR . 'includes/wp/class-shortcodes.php';
require_once CA_PLUGIN_DIR . 'includes/wp/class-rest-api.php';
require_once CA_PLUGIN_DIR . 'includes/wp/class-admin-widget.php';
require_once CA_PLUGIN_DIR . 'includes/wp/class-cron.php';

/* ─── Boot ──────────────────────────────────────────────────────────── */
register_activation_hook( __FILE__, array( 'CA_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CA_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'CA_Plugin', 'init' ) );
