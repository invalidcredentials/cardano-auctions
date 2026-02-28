<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap ca-admin">
    <h1>Cardano Auctions &mdash; Settings</h1>

    <?php settings_errors( 'ca_settings' ); ?>

    <?php if ( ! empty( $errors ) ) : ?>
        <div class="notice notice-error">
            <p><strong>System Requirements</strong></p>
            <ul><?php foreach ( $errors as $e ) echo '<li>' . esc_html( $e ) . '</li>'; ?></ul>
        </div>
    <?php endif; ?>

    <form method="post" action="">
        <?php wp_nonce_field( 'ca_save_settings', 'ca_settings_nonce' ); ?>

        <h2>Blockchain</h2>
        <table class="form-table">
            <tr>
                <th><label for="ca_network">Network</label></th>
                <td>
                    <select name="ca_network" id="ca_network">
                        <option value="preprod" <?php selected( CA_Settings::get_network(), 'preprod' ); ?>>Preprod (Testnet)</option>
                        <option value="mainnet" <?php selected( CA_Settings::get_network(), 'mainnet' ); ?>>Mainnet</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="ca_blockfrost_key">Blockfrost API Key</label></th>
                <td>
                    <input type="password" name="ca_blockfrost_key" id="ca_blockfrost_key" class="regular-text"
                           placeholder="<?php echo CA_Settings::get_blockfrost_key() ? '********' : 'Enter key'; ?>">
                    <p class="description">Get one at <a href="https://blockfrost.io" target="_blank">blockfrost.io</a>. Leave blank to keep current key.</p>
                </td>
            </tr>
            <tr>
                <th><label for="ca_anvil_key">Anvil API Key</label></th>
                <td>
                    <input type="password" name="ca_anvil_key" id="ca_anvil_key" class="regular-text"
                           placeholder="<?php echo CA_Settings::get_anvil_key() ? '********' : 'Enter key'; ?>">
                    <p class="description">Get one at <a href="https://ada-anvil.io" target="_blank">ada-anvil.io</a>. Leave blank to keep current key.</p>
                </td>
            </tr>
        </table>

        <h2>Auction Defaults</h2>
        <table class="form-table">
            <tr>
                <th><label for="ca_default_seller_address">Seller Address Override</label></th>
                <td>
                    <?php $explicit_seller = get_option( CA_Settings::OPT_DEFAULT_SELLER, '' ); ?>
                    <input type="text" name="ca_default_seller_address" id="ca_default_seller_address" class="large-text"
                           value="<?php echo esc_attr( $explicit_seller ); ?>"
                           placeholder="<?php echo esc_attr( CA_Settings::get_default_seller_address() ); ?>">
                    <p class="description">
                        Leave blank to use your master wallet address (recommended).
                        <?php if ( empty( $explicit_seller ) && CA_Settings::get_default_seller_address() ) : ?>
                            Currently using: <code style="font-size:11px"><?php echo esc_html( substr( CA_Settings::get_default_seller_address(), 0, 20 ) . '...' . substr( CA_Settings::get_default_seller_address(), -8 ) ); ?></code>
                        <?php elseif ( empty( $explicit_seller ) ) : ?>
                            <span style="color:#d32f2f">No master wallet found — create one on the Wallets tab.</span>
                        <?php endif; ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><label for="ca_default_min_increment">Min Bid Increment (lovelace)</label></th>
                <td><input type="number" name="ca_default_min_increment" id="ca_default_min_increment"
                           value="<?php echo esc_attr( CA_Settings::get_default_min_increment() ); ?>" min="1000000" step="1000000">
                    <p class="description">Default: 1000000 (1 ADA)</p></td>
            </tr>
            <tr>
                <th><label for="ca_payment_timeout">Payment Timeout (seconds)</label></th>
                <td><input type="number" name="ca_payment_timeout" id="ca_payment_timeout"
                           value="<?php echo esc_attr( CA_Settings::get_payment_timeout() ); ?>" min="300" step="60">
                    <p class="description">Default: 900 (15 minutes)</p></td>
            </tr>
            <tr>
                <th><label for="ca_anti_snipe_seconds">Anti-Snipe Extension (seconds)</label></th>
                <td><input type="number" name="ca_anti_snipe_seconds" id="ca_anti_snipe_seconds"
                           value="<?php echo esc_attr( CA_Settings::get_anti_snipe_seconds() ); ?>" min="0" step="60">
                    <p class="description">Extend auction by this many seconds when a late bid arrives. 0 = disabled.</p></td>
            </tr>
            <tr>
                <th><label for="ca_anti_snipe_threshold">Anti-Snipe Threshold (seconds)</label></th>
                <td><input type="number" name="ca_anti_snipe_threshold" id="ca_anti_snipe_threshold"
                           value="<?php echo esc_attr( CA_Settings::get_anti_snipe_threshold() ); ?>" min="0" step="60">
                    <p class="description">A bid is "late" if placed within this many seconds of the end.</p></td>
            </tr>
            <tr>
                <th><label for="ca_platform_fee_bps">Platform Fee (basis points)</label></th>
                <td><input type="number" name="ca_platform_fee_bps" id="ca_platform_fee_bps"
                           value="<?php echo esc_attr( CA_Settings::get_platform_fee_bps() ); ?>" min="0" max="5000" step="25">
                    <p class="description">100 = 1%. Applied to winning bid on settlement. 0 = no fee.</p></td>
            </tr>
        </table>

        <h2>IPFS / Pinata</h2>
        <table class="form-table">
            <tr>
                <th><label for="ca_pinata_enabled">Enable Pinata IPFS</label></th>
                <td>
                    <label>
                        <input type="checkbox" name="ca_pinata_enabled" id="ca_pinata_enabled" value="1"
                               <?php checked( CA_Settings::is_pinata_enabled() ); ?>>
                        Pin NFT images to IPFS via Pinata when minting
                    </label>
                    <p class="description">Required for minting NFTs with on-chain images. Get a JWT at <a href="https://app.pinata.cloud" target="_blank">app.pinata.cloud</a>.</p>
                </td>
            </tr>
            <tr>
                <th><label for="ca_pinata_jwt">Pinata JWT</label></th>
                <td>
                    <input type="password" name="ca_pinata_jwt" id="ca_pinata_jwt" class="regular-text"
                           placeholder="<?php echo CA_Settings::get_pinata_jwt() ? '********' : 'Enter JWT token'; ?>">
                    <p class="description">Your Pinata API JWT token. Leave blank to keep current key.</p>
                </td>
            </tr>
        </table>

        <h2>Cron Status</h2>
        <table class="widefat fixed striped" style="max-width:600px">
            <thead><tr><th>Job</th><th>Next Run</th></tr></thead>
            <tbody>
            <?php foreach ( $cron_status as $hook => $next ) : ?>
                <tr>
                    <td><code><?php echo esc_html( $hook ); ?></code></td>
                    <td><?php echo $next ? esc_html( date( 'Y-m-d H:i:s', $next ) ) : '<span style="color:red">Not scheduled</span>'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description">For production, set up a system cron hitting <code>wp-cron.php</code> every minute.</p>

        <?php submit_button( 'Save Settings' ); ?>
    </form>
</div>
