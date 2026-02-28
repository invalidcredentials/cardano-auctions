<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap ca-admin">
    <h1>Cardano Auctions &mdash; Widget Deployer</h1>

    <?php settings_errors( 'ca_settings' ); ?>

    <!-- ── Panel 1: API Keys ─────────────────────────────────────── -->
    <div class="ca-panel">
        <h2>API Keys</h2>
        <p class="description">Generate API keys for external widget integrations. Each key can be restricted to specific origins and auctions.</p>

        <div style="background:#f9f9f9;border:1px solid #ddd;padding:16px;border-radius:4px;margin:12px 0">
            <h3 style="margin-top:0">Generate New Key</h3>
            <table class="form-table" style="margin:0">
                <tr>
                    <th><label for="ca-key-label">Label</label></th>
                    <td><input type="text" id="ca-key-label" class="regular-text" placeholder="e.g. My Game Widget"></td>
                </tr>
                <tr>
                    <th><label for="ca-key-origins">Allowed Origins</label></th>
                    <td>
                        <textarea id="ca-key-origins" rows="3" class="large-text" placeholder="https://mygame.com&#10;https://staging.mygame.com"></textarea>
                        <p class="description">One URL per line. Leave empty to allow all origins.</p>
                    </td>
                </tr>
                <tr>
                    <th><label>Restrict to Auctions</label></th>
                    <td>
                        <select id="ca-key-auctions" multiple style="min-width:300px;min-height:80px">
                            <?php foreach ( $auctions as $a ) : ?>
                                <option value="<?php echo (int) $a['id']; ?>">
                                    #<?php echo (int) $a['id']; ?> &mdash; <?php echo esc_html( $a['title'] ); ?> (<?php echo esc_html( $a['status'] ); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Hold Ctrl/Cmd to select multiple. Leave empty to allow all auctions.</p>
                    </td>
                </tr>
            </table>
            <p><button type="button" class="button button-primary" id="ca-generate-key">Generate API Key</button></p>
        </div>

        <!-- Key display modal (hidden until generated) -->
        <div id="ca-key-modal" style="display:none;background:#fff3cd;border:2px solid #f0c040;padding:16px;border-radius:4px;margin:12px 0">
            <h3 style="margin-top:0;color:#856404">Your API Key (shown once!)</h3>
            <code id="ca-key-display" style="display:block;padding:12px;background:#fff;border:1px solid #ccc;font-size:14px;word-break:break-all"></code>
            <p style="margin:8px 0">
                <button type="button" class="button ca-copy-btn" data-copy="" id="ca-copy-key">Copy Key</button>
            </p>
            <p class="description" style="color:#856404">Save this key now. It cannot be retrieved later &mdash; only a prefix is stored.</p>
        </div>

        <?php if ( ! empty( $api_keys ) ) : ?>
            <h3>Active Keys</h3>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:80px">ID</th>
                        <th>Label</th>
                        <th>Key Prefix</th>
                        <th>Origins</th>
                        <th>Auctions</th>
                        <th style="width:140px">Created</th>
                        <th style="width:140px">Last Used</th>
                        <th style="width:80px">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $api_keys as $k ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( $k['id'] ); ?></code></td>
                            <td><?php echo esc_html( $k['label'] ); ?></td>
                            <td><code><?php echo esc_html( $k['key_prefix'] ); ?>...</code></td>
                            <td><?php echo ! empty( $k['allowed_origins'] ) ? esc_html( implode( ', ', $k['allowed_origins'] ) ) : '<em>All</em>'; ?></td>
                            <td><?php echo ! empty( $k['auction_ids'] ) ? esc_html( implode( ', ', array_map( function( $id ) { return '#' . $id; }, $k['auction_ids'] ) ) ) : '<em>All</em>'; ?></td>
                            <td><?php echo esc_html( $k['created_at'] ); ?></td>
                            <td><?php echo $k['last_used'] ? esc_html( $k['last_used'] ) : '<em>Never</em>'; ?></td>
                            <td><button type="button" class="button button-small ca-revoke-key" data-key-id="<?php echo esc_attr( $k['id'] ); ?>">Revoke</button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p style="color:#666">No API keys yet. Generate one above to get started.</p>
        <?php endif; ?>
    </div>

    <!-- ── Panel 2: Widget Appearance ─────────────────────────────── -->
    <div class="ca-panel" style="margin-top:20px">
        <h2>Widget Appearance</h2>
        <form method="post" action="">
            <?php wp_nonce_field( 'ca_save_widget_config', 'ca_widget_config_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th><label for="ca_widget_theme">Theme Preset</label></th>
                    <td>
                        <select name="ca_widget_theme" id="ca_widget_theme">
                            <option value="game-dark" <?php selected( $config['theme'], 'game-dark' ); ?>>Game Dark (Cyberpunk)</option>
                            <option value="dark" <?php selected( $config['theme'], 'dark' ); ?>>Dark</option>
                            <option value="light" <?php selected( $config['theme'], 'light' ); ?>>Light</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="ca_widget_accent">Accent Color</label></th>
                    <td><input type="color" name="ca_widget_accent" id="ca_widget_accent" value="<?php echo esc_attr( $config['accent'] ); ?>"> <code><?php echo esc_html( $config['accent'] ); ?></code></td>
                </tr>
                <tr>
                    <th><label for="ca_widget_confirm">Confirm Button Color</label></th>
                    <td><input type="color" name="ca_widget_confirm" id="ca_widget_confirm" value="<?php echo esc_attr( $config['confirm'] ); ?>"> <code><?php echo esc_html( $config['confirm'] ); ?></code></td>
                </tr>
                <tr>
                    <th><label for="ca_widget_background">Background Color</label></th>
                    <td><input type="color" name="ca_widget_background" id="ca_widget_background" value="<?php echo esc_attr( $config['background'] ); ?>"> <code><?php echo esc_html( $config['background'] ); ?></code></td>
                </tr>
                <tr>
                    <th><label for="ca_widget_text">Text Color</label></th>
                    <td><input type="color" name="ca_widget_text" id="ca_widget_text" value="<?php echo esc_attr( $config['text'] ); ?>"> <code><?php echo esc_html( $config['text'] ); ?></code></td>
                </tr>
            </table>
            <?php submit_button( 'Save Appearance' ); ?>
        </form>
    </div>

    <!-- ── Panel 3: Embed Code Generator ─────────────────────────── -->
    <div class="ca-panel" style="margin-top:20px">
        <h2>Embed Code Generator</h2>
        <table class="form-table">
            <tr>
                <th><label for="ca-embed-auction">Auction</label></th>
                <td>
                    <select id="ca-embed-auction">
                        <option value="">Select an auction...</option>
                        <?php foreach ( $auctions as $a ) : ?>
                            <option value="<?php echo (int) $a['id']; ?>">#<?php echo (int) $a['id']; ?> &mdash; <?php echo esc_html( $a['title'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="ca-embed-key">API Key</label></th>
                <td>
                    <select id="ca-embed-key">
                        <option value="">Select a key...</option>
                        <?php foreach ( $api_keys as $k ) : ?>
                            <option value="<?php echo esc_attr( $k['key_prefix'] ); ?>..."><?php echo esc_html( $k['label'] ); ?> (<?php echo esc_html( $k['key_prefix'] ); ?>...)</option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">The actual key must be pasted manually into the snippet. Only the prefix is stored.</p>
                </td>
            </tr>
        </table>

        <h3>Embed Snippet</h3>
        <pre id="ca-embed-code" style="background:#1e1e1e;color:#d4d4d4;padding:16px;border-radius:6px;overflow-x:auto;font-size:13px;line-height:1.5">&lt;!-- Select an auction and API key above --&gt;</pre>
        <p>
            <button type="button" class="button ca-copy-btn" id="ca-copy-embed" data-copy="">Copy Embed Code</button>
            <a href="<?php echo esc_url( site_url( '/widget-test.html' ) ); ?>" id="ca-sandbox-link" data-base="<?php echo esc_url( site_url( '/widget-test.html' ) ); ?>" target="_blank" class="button" style="margin-left:8px">Customization Sandbox</a>
        </p>

        <input type="hidden" id="ca-embed-rest-url" value="<?php echo esc_attr( $rest_url ); ?>">
        <input type="hidden" id="ca-embed-widget-url" value="<?php echo esc_attr( CA_PLUGIN_URL . 'assets/js/ca-widget.min.js' ); ?>">
    </div>

    <!-- ── Panel 4: Preview ──────────────────────────────────────── -->
    <div class="ca-panel" style="margin-top:20px">
        <h2>Widget Preview</h2>
        <p class="description">Live preview of the widget. Select an auction above to render it. Wallet connect works here too.</p>
        <div id="ca-widget-preview-wrap" style="background:<?php echo esc_attr( $config['background'] ); ?>;border-radius:8px;min-height:200px;overflow:hidden">
            <div id="ca-widget-preview-placeholder" style="display:flex;align-items:center;justify-content:center;min-height:200px;color:<?php echo esc_attr( $config['text'] ); ?>">
                <p style="opacity:0.5">Select an auction in the Embed Code Generator to see a preview.</p>
            </div>
            <iframe id="ca-widget-preview-frame" style="display:none;width:100%;border:none;border-radius:8px" title="Widget Preview"></iframe>
        </div>
    </div>
</div>
