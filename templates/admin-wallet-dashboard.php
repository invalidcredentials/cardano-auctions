<?php if ( ! defined( 'ABSPATH' ) ) exit;
$current_filter = sanitize_text_field( $_GET['filter'] ?? '' );
$current_status = sanitize_text_field( $_GET['status'] ?? '' );
$base_url = admin_url( 'admin.php?page=ca-wallets' );
?>
<div class="wrap ca-admin ca-wallets-page">
    <h1>Wallets <span class="ca-network-badge"><?php echo esc_html( strtoupper( $network ) ); ?></span></h1>

    <!-- ── Master Wallet Section ────────────────────────────────── -->
    <div class="ca-master-wallet-section">
        <h2>Master Wallet</h2>

        <?php if ( $master_wallet ) : ?>
            <?php $mw_addr = $master_wallet['payment_address']; ?>
            <div class="ca-master-wallet-card">
                <div class="ca-wallet-header">
                    <span class="ca-purpose-badge ca-purpose--master">MASTER</span>
                    <span class="ca-status ca-status--active">Active</span>
                </div>

                <div class="ca-master-name"><?php echo esc_html( $master_wallet['wallet_name'] ); ?></div>

                <!-- One-time mnemonic display -->
                <?php if ( $master_mnemonic ) : ?>
                    <div class="ca-mnemonic-banner" id="ca-mnemonic-banner">
                        <div class="ca-mnemonic-warning">Write down your recovery phrase now. It will not be shown again.</div>
                        <div class="ca-mnemonic-grid">
                            <?php
                            $words = explode( ' ', $master_mnemonic );
                            foreach ( $words as $i => $word ) :
                            ?>
                                <span class="ca-mnemonic-word"><em><?php echo $i + 1; ?>.</em> <?php echo esc_html( $word ); ?></span>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="button ca-copy-btn" data-copy="<?php echo esc_attr( $master_mnemonic ); ?>">Copy Mnemonic</button>
                        <button type="button" class="button" id="ca-dismiss-mnemonic" data-wallet-id="<?php echo (int) $master_wallet['id']; ?>" style="margin-left:8px">I've saved it — dismiss</button>
                    </div>
                <?php endif; ?>

                <!-- Balance -->
                <div class="ca-wallet-balance">
                    <span class="ca-wallet-ada" id="ca-master-ada">--</span> ADA
                    <button type="button" class="ca-wallet-refresh button-link" id="ca-master-refresh" title="Refresh balance">&#x21bb;</button>
                </div>

                <!-- Address -->
                <div class="ca-wallet-address">
                    <code title="<?php echo esc_attr( $mw_addr ); ?>"><?php echo esc_html( substr( $mw_addr, 0, 16 ) . '...' . substr( $mw_addr, -8 ) ); ?></code>
                    <button type="button" class="ca-copy-btn button-link" data-copy="<?php echo esc_attr( $mw_addr ); ?>" title="Copy address">Copy</button>
                </div>

                <!-- Extended key (blurred) -->
                <div class="ca-master-skey">
                    <label>Extended Key:</label>
                    <code class="ca-skey-blur" id="ca-master-skey-display">&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;</code>
                    <button type="button" class="button-link" id="ca-reveal-skey" data-wallet-id="<?php echo (int) $master_wallet['id']; ?>">Reveal</button>
                </div>

                <!-- NFT grid (loaded via AJAX) -->
                <div class="ca-master-nfts">
                    <h4>NFTs <button type="button" class="button button-small" id="ca-load-master-nfts" data-wallet-id="<?php echo (int) $master_wallet['id']; ?>">Load NFTs</button></h4>
                    <div id="ca-master-nft-grid"></div>
                </div>

                <!-- Footer info -->
                <div class="ca-wallet-footer">
                    <span>Created: <?php echo esc_html( $master_wallet['created_at'] ); ?></span>
                    <span>Index: <?php echo (int) $master_wallet['next_derivation_index']; ?> children derived</span>
                </div>

                <!-- Archive -->
                <div class="ca-master-actions">
                    <button type="button" class="button" id="ca-archive-master" data-wallet-id="<?php echo (int) $master_wallet['id']; ?>">Archive Wallet</button>
                </div>
            </div>

        <?php else : ?>
            <div class="ca-master-wallet-empty">
                <p>No master wallet configured for <?php echo esc_html( strtoupper( $network ) ); ?>.</p>
                <p class="description">A master wallet generates all escrow addresses via HD derivation from a single seed.</p>
                <div class="ca-master-generate-form">
                    <input type="text" id="ca-master-wallet-name" placeholder="Wallet name (optional)" value="Master Wallet" />
                    <button type="button" class="button button-primary" id="ca-generate-master">Generate Master Wallet</button>
                    <span id="ca-generate-status"></span>
                </div>
            </div>
        <?php endif; ?>

        <!-- Archived master wallets -->
        <?php if ( $archived_count > 0 ) : ?>
            <details class="ca-archived-section">
                <summary>Archived Master Wallets (<?php echo (int) $archived_count; ?>)</summary>
                <div class="ca-archived-list">
                    <?php foreach ( $archived_wallets as $aw ) : ?>
                        <div class="ca-archived-wallet" data-wallet-id="<?php echo (int) $aw['id']; ?>">
                            <span class="ca-archived-name"><?php echo esc_html( $aw['wallet_name'] ); ?></span>
                            <code><?php echo esc_html( substr( $aw['payment_address'], 0, 16 ) . '...' . substr( $aw['payment_address'], -8 ) ); ?></code>
                            <span class="ca-archived-date">Archived: <?php echo esc_html( $aw['archived_at'] ); ?></span>
                            <button type="button" class="button button-small ca-unarchive-master" data-wallet-id="<?php echo (int) $aw['id']; ?>">Unarchive</button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endif; ?>
    </div>

    <hr style="margin:24px 0">

    <!-- Summary cards -->
    <div class="ca-summary-cards">
        <div class="ca-summary-card">
            <span class="ca-summary-num"><?php echo (int) $counts['total']; ?></span>
            <span class="ca-summary-label">Total</span>
        </div>
        <div class="ca-summary-card">
            <span class="ca-summary-num"><?php echo (int) $counts['custody']; ?></span>
            <span class="ca-summary-label">Custody</span>
        </div>
        <div class="ca-summary-card">
            <span class="ca-summary-num"><?php echo (int) $counts['escrow']; ?></span>
            <span class="ca-summary-label">Escrow</span>
        </div>
        <div class="ca-summary-card">
            <span class="ca-summary-num"><?php echo (int) $counts['active']; ?></span>
            <span class="ca-summary-label">Active</span>
        </div>
    </div>

    <!-- Filter tabs -->
    <div class="ca-filter-tabs">
        <a href="<?php echo esc_url( $base_url ); ?>" class="<?php echo '' === $current_filter ? 'active' : ''; ?>">All (<?php echo (int) $counts['total']; ?>)</a>
        <a href="<?php echo esc_url( add_query_arg( 'filter', 'custody', $base_url ) ); ?>" class="<?php echo 'custody' === $current_filter ? 'active' : ''; ?>">Custody (<?php echo (int) $counts['custody']; ?>)</a>
        <a href="<?php echo esc_url( add_query_arg( 'filter', 'escrow', $base_url ) ); ?>" class="<?php echo 'escrow' === $current_filter ? 'active' : ''; ?>">Escrow (<?php echo (int) $counts['escrow']; ?>)</a>
    </div>

    <!-- Refresh All -->
    <p><button type="button" class="button" id="ca-refresh-all">Refresh All Balances</button> <span id="ca-refresh-all-status"></span></p>

    <?php if ( empty( $wallets ) ) : ?>
        <p>No wallets found.</p>
    <?php else : ?>
        <!-- Wallet card grid -->
        <div class="ca-wallet-grid">
            <?php foreach ( $wallets as $w ) :
                $is_custody = ( 'nft_custody' === $w['purpose'] );
                $purpose_label = $is_custody ? 'CUSTODY' : 'ESCROW';
                $purpose_class = $is_custody ? 'custody' : 'escrow';
                $ada = number_format( $w['balance_lovelace'] / 1000000, 2 );
                $addr = $w['payment_address'];
                $addr_short = substr( $addr, 0, 12 ) . '...' . substr( $addr, -8 );
            ?>
                <div class="ca-wallet-card" data-wallet-id="<?php echo (int) $w['id']; ?>" data-status="<?php echo esc_attr( $w['status'] ); ?>">
                    <!-- Header -->
                    <div class="ca-wallet-header">
                        <span class="ca-purpose-badge ca-purpose--<?php echo $purpose_class; ?>"><?php echo $purpose_label; ?></span>
                        <span class="ca-status ca-status--<?php echo esc_attr( $w['status'] ); ?>"><?php echo esc_html( ucfirst( $w['status'] ) ); ?></span>
                    </div>

                    <!-- Balance -->
                    <div class="ca-wallet-balance">
                        <span class="ca-wallet-ada" id="ca-wallet-ada-<?php echo (int) $w['id']; ?>"><?php echo esc_html( $ada ); ?></span> ADA
                        <button type="button" class="ca-wallet-refresh button-link" data-wallet-id="<?php echo (int) $w['id']; ?>" title="Refresh balance">&#x21bb;</button>
                    </div>

                    <!-- Address -->
                    <div class="ca-wallet-address">
                        <code title="<?php echo esc_attr( $addr ); ?>"><?php echo esc_html( $addr_short ); ?></code>
                        <button type="button" class="ca-copy-btn button-link" data-copy="<?php echo esc_attr( $addr ); ?>" title="Copy address">Copy</button>
                    </div>

                    <!-- Parent link -->
                    <div class="ca-wallet-parent">
                        <?php if ( $w['auction_id'] ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ca-auction-edit&id=' . (int) $w['auction_id'] ) ); ?>">Auction #<?php echo (int) $w['auction_id']; ?></a>
                        <?php endif; ?>
                        <?php if ( $w['bid_id'] ) : ?>
                            <span>Bid #<?php echo (int) $w['bid_id']; ?></span>
                        <?php endif; ?>
                    </div>

                    <!-- Asset grid (loaded via AJAX) -->
                    <div class="ca-wallet-assets" id="ca-wallet-assets-<?php echo (int) $w['id']; ?>"></div>

                    <!-- Send form (active wallets only) -->
                    <?php if ( 'active' === $w['status'] ) : ?>
                        <div class="ca-wallet-actions">
                            <button type="button" class="button ca-send-toggle">Send</button>
                        </div>
                        <div class="ca-send-form" style="display:none">
                            <input type="text" class="ca-send-recipient" placeholder="Recipient address (addr...)" />
                            <input type="number" class="ca-send-amount" placeholder="Amount (ADA)" min="1" step="0.1" />
                            <button type="button" class="button button-primary ca-send-confirm" data-wallet-id="<?php echo (int) $w['id']; ?>">Confirm Send</button>
                            <span class="ca-send-status"></span>
                        </div>
                    <?php endif; ?>

                    <!-- Footer -->
                    <div class="ca-wallet-footer">
                        <span>Created: <?php echo esc_html( $w['created_at'] ); ?></span>
                        <span>ID: <?php echo (int) $w['id']; ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
