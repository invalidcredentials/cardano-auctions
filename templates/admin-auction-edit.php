<?php if ( ! defined( 'ABSPATH' ) ) exit;
$is_new = ! $auction;
$s = function( $key, $default = '' ) use ( $auction ) {
    return esc_attr( $auction[ $key ] ?? $default );
};
$ada = function( $key ) use ( $auction ) {
    $v = (int) ( $auction[ $key ] ?? 0 );
    return $v > 0 ? number_format( $v / 1000000, 2, '.', '' ) : '';
};
?>
<div class="wrap ca-admin">
    <h1><?php echo $is_new ? 'Create Auction' : 'Edit Auction #' . (int) $auction['id']; ?>
        <?php if ( ! $is_new ) : ?>
            <span class="ca-status ca-status--<?php echo esc_attr( $auction['status'] ); ?>"><?php echo esc_html( ucfirst( $auction['status'] ) ); ?></span>
        <?php endif; ?>
    </h1>

    <?php if ( isset( $_GET['saved'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p>Auction saved.</p></div>
    <?php endif; ?>

    <form method="post" action="" id="ca-auction-form">
        <?php wp_nonce_field( 'ca_save_auction', 'ca_auction_nonce' ); ?>
        <input type="hidden" name="auction_id" value="<?php echo (int) ( $auction['id'] ?? 0 ); ?>">

        <div class="ca-grid">
            <!-- Left column: form -->
            <div class="ca-col">
                <h2>Details</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="title">Title</label></th>
                        <td><input type="text" name="title" id="title" class="large-text" value="<?php echo $s('title'); ?>" required></td>
                    </tr>
                    <tr>
                        <th><label for="description">Description</label></th>
                        <td><textarea name="description" id="description" class="large-text" rows="4"><?php echo esc_textarea( $auction['description'] ?? '' ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label for="artist_name">Artist Name</label></th>
                        <td><input type="text" name="artist_name" id="artist_name" class="large-text" value="<?php echo $s('artist_name'); ?>" placeholder="e.g. Wren Ellis"></td>
                    </tr>
                    <tr>
                        <th><label for="listed_by">Listed By</label></th>
                        <td><input type="text" name="listed_by" id="listed_by" class="large-text" value="<?php echo $s('listed_by'); ?>" placeholder="e.g. Overexposed Gallery"></td>
                    </tr>
                    <tr>
                        <th><label>Auction Images</label></th>
                        <td>
                            <?php
                            $gallery = array();
                            if ( ! empty( $auction['gallery_images'] ) ) {
                                $gallery = json_decode( $auction['gallery_images'], true );
                                if ( ! is_array( $gallery ) ) $gallery = array();
                            }
                            if ( empty( $gallery ) && ! empty( $auction['image_url'] ) ) {
                                $gallery = array( $auction['image_url'] );
                            }
                            ?>
                            <div id="ca-gallery-preview" style="margin-bottom:12px;<?php echo empty( $gallery ) ? 'display:none' : ''; ?>">
                                <img id="ca-gallery-main" src="<?php echo ! empty( $gallery ) ? esc_url( $gallery[0] ) : ''; ?>" style="max-width:300px;max-height:300px;border-radius:6px;display:block;margin-bottom:8px">
                                <div id="ca-gallery-thumbs" style="display:flex;gap:6px;flex-wrap:wrap">
                                    <?php foreach ( $gallery as $i => $url ) : ?>
                                        <div class="ca-gallery-thumb" data-url="<?php echo esc_url( $url ); ?>" style="position:relative;cursor:move">
                                            <img src="<?php echo esc_url( $url ); ?>" style="width:80px;height:80px;object-fit:cover;border-radius:4px;border:2px solid <?php echo $i === 0 ? '#0073aa' : '#ddd'; ?>">
                                            <button type="button" class="ca-gallery-remove" style="position:absolute;top:-6px;right:-6px;background:#dc3232;color:#fff;border:none;border-radius:50%;width:20px;height:20px;font-size:12px;line-height:20px;text-align:center;cursor:pointer;padding:0">&times;</button>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <input type="hidden" name="gallery_images" id="ca-gallery-data" value="<?php echo esc_attr( wp_json_encode( $gallery ) ); ?>">
                            <input type="hidden" name="image_url" id="ca-image-url" value="<?php echo esc_url( ! empty( $gallery ) ? $gallery[0] : '' ); ?>">
                            <button type="button" class="button" id="ca-add-gallery-images">Add Images</button>
                            <button type="button" class="button" id="ca-clear-gallery" style="<?php echo empty( $gallery ) ? 'display:none' : ''; ?>">Clear All</button>
                            <p class="description">Upload multiple images for a product gallery. First image is the main display image. Drag to reorder. For NFT auctions, the NFT image is used if no custom images are set.</p>
                        </td>
                    </tr>
                </table>

                <h2>NFT</h2>

                <!-- Hidden fields shared by both tabs -->
                <input type="hidden" name="nft_policy_id" id="nft_policy_id" value="<?php echo $s('nft_policy_id'); ?>">
                <input type="hidden" name="nft_asset_name_hex" id="nft_asset_name_hex" value="<?php echo $s('nft_asset_name_hex'); ?>">
                <input type="hidden" name="nft_display_name" id="nft_display_name" value="<?php echo $s('nft_display_name'); ?>">
                <input type="hidden" name="nft_image_url" id="nft_image_url" value="<?php echo $s('nft_image_url'); ?>">
                <input type="hidden" name="nft_metadata" id="nft_metadata" value="<?php echo esc_attr( $auction['nft_metadata'] ?? '' ); ?>">
                <input type="hidden" name="mint_tx_hash" id="mint_tx_hash" value="<?php echo $s('mint_tx_hash'); ?>">
                <input type="hidden" name="mint_policy_table_id" id="mint_policy_table_id" value="<?php echo $s('mint_policy_table_id'); ?>">

                <?php if ( $master_wallet ) : ?>
                <!-- NFT Tab Navigation -->
                <div class="ca-nft-tabs">
                    <a href="#" class="ca-nft-tab active" data-tab="existing">Select Existing NFT</a>
                    <a href="#" class="ca-nft-tab" data-tab="mint">Mint New Auction Item</a>
                </div>
                <?php endif; ?>

                <!-- Tab 1: Select Existing NFT -->
                <div class="ca-nft-tab-content active" id="ca-tab-existing">
                    <?php if ( $master_wallet ) : ?>
                        <div class="ca-nft-browse">
                            <button type="button" class="button" id="ca-browse-nfts">Browse Master Wallet NFTs</button>
                            <span class="ca-nft-browse-or">or enter manually below</span>
                            <div id="ca-nft-browse-grid" class="ca-nft-grid" style="display:none"></div>
                        </div>
                    <?php endif; ?>

                    <table class="form-table">
                        <tr>
                            <th><label for="nft_unit">NFT Unit (Policy ID + Hex Name)</label></th>
                            <td>
                                <input type="text" id="nft_unit" class="large-text" value="<?php echo $s('nft_policy_id') . $s('nft_asset_name_hex'); ?>" placeholder="e.g. abc123...def456">
                                <button type="button" class="button" id="ca-lookup-nft">Lookup NFT</button>
                                <span id="ca-lookup-status"></span>
                            </td>
                        </tr>
                    </table>
                </div>

                <?php if ( $master_wallet ) : ?>
                <!-- Tab 2: Mint New Auction Item -->
                <div class="ca-nft-tab-content" id="ca-tab-mint" style="display:none">

                    <!-- Step 1: Choose / Create Collection -->
                    <div class="ca-mint-step">
                        <h3>1. Collection (Minting Policy)</h3>
                        <div class="ca-mint-policy-row">
                            <select id="ca-mint-policy-select">
                                <option value="">Select a collection...</option>
                                <?php foreach ( $policies as $p ) :
                                    $is_imported = ! empty( $p['source'] ) && $p['source'] !== 'generated';
                                    $exp_label   = ! empty( $p['expiration_date'] ) ? 'expires ' . substr( $p['expiration_date'], 0, 10 ) : 'no expiry';
                                    $badge       = $is_imported ? 'imported, ' : '';
                                ?>
                                    <option value="<?php echo (int) $p['id']; ?>" data-policy-id="<?php echo esc_attr( $p['policy_id'] ); ?>">
                                        <?php echo esc_html( $p['name'] ); ?> (<?php echo $badge; ?><?php echo (int) $p['items_minted']; ?> minted, <?php echo esc_html( $exp_label ); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" class="button" id="ca-new-policy-toggle">+ New Collection</button>
                            <button type="button" class="button" id="ca-import-policy-toggle">Import External Policy</button>
                        </div>
                        <div id="ca-new-policy-form" style="display:none;margin-top:12px">
                            <table class="form-table" style="margin:0">
                                <tr>
                                    <th><label for="ca-new-policy-name">Collection Name</label></th>
                                    <td><input type="text" id="ca-new-policy-name" class="regular-text" placeholder="e.g. Summer 2026 Collection"></td>
                                </tr>
                                <tr>
                                    <th><label for="ca-new-policy-expiry">Expiration Date</label></th>
                                    <td>
                                        <input type="datetime-local" id="ca-new-policy-expiry" value="<?php echo gmdate( 'Y-m-d\TH:i', strtotime( '+1 year' ) ); ?>">
                                        <p class="description">Default: 1 year from now. After this date, no new NFTs can be minted under this policy.</p>
                                    </td>
                                </tr>
                            </table>
                            <button type="button" class="button button-primary" id="ca-create-policy">Create Collection</button>
                            <span id="ca-policy-create-status" style="margin-left:8px"></span>
                        </div>

                        <!-- Import External Policy -->
                        <div id="ca-import-policy-form" style="display:none;margin-top:12px;border:1px solid #c3c4c7;border-radius:4px;padding:16px;background:#f9f9f9">
                            <h4 style="margin-top:0">Import External Policy</h4>
                            <p class="description" style="margin-bottom:12px">Import a minting policy from outside the plugin (e.g. from jpg.store, cardano-cli, etc). The signing key will be encrypted at rest.</p>

                            <!-- Import sub-tabs -->
                            <div class="ca-import-tabs" style="display:flex;gap:0;margin-bottom:16px;border-bottom:2px solid #c3c4c7">
                                <a href="#" class="ca-import-tab active" data-import-tab="skey" style="padding:8px 16px;text-decoration:none;border:1px solid #c3c4c7;border-bottom:2px solid #fff;margin-bottom:-2px;background:#fff;font-weight:600">Skey + Script</a>
                                <a href="#" class="ca-import-tab" data-import-tab="mnemonic" style="padding:8px 16px;text-decoration:none;border:1px solid transparent;margin-bottom:-2px;color:#50575e">Seed Phrase</a>
                                <a href="#" class="ca-import-tab" data-import-tab="manual" style="padding:8px 16px;text-decoration:none;border:1px solid transparent;margin-bottom:-2px;color:#50575e">Full Manual</a>
                            </div>

                            <!-- Sub-tab: Skey + Script -->
                            <div class="ca-import-tab-content" id="ca-import-skey" style="display:block">
                                <table class="form-table" style="margin:0">
                                    <tr>
                                        <th><label for="ca-import-skey-name">Collection Name</label></th>
                                        <td><input type="text" id="ca-import-skey-name" class="regular-text" placeholder="e.g. Mek Tycoon"></td>
                                    </tr>
                                    <tr>
                                        <th><label for="ca-import-skey-key">Signing Key (.skey)</label></th>
                                        <td>
                                            <textarea id="ca-import-skey-key" class="large-text" rows="3" placeholder='Paste .skey file contents (JSON) or raw hex...'></textarea>
                                            <p class="description">Cardano CLI format: {"type":"PaymentSigningKeyShelley_ed25519","cborHex":"5820..."}</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><label for="ca-import-skey-script">Policy Script (.script)</label></th>
                                        <td>
                                            <textarea id="ca-import-skey-script" class="large-text" rows="3" placeholder='Paste .script file contents (JSON)...'></textarea>
                                            <p class="description">Native script JSON: {"type":"all","scripts":[{"type":"sig","keyHash":"..."}]}</p>
                                        </td>
                                    </tr>
                                </table>
                                <button type="button" class="button button-primary" id="ca-import-skey-btn">Import Policy</button>
                                <span id="ca-import-skey-status" style="margin-left:8px"></span>
                            </div>

                            <!-- Sub-tab: Seed Phrase -->
                            <div class="ca-import-tab-content" id="ca-import-mnemonic" style="display:none">
                                <table class="form-table" style="margin:0">
                                    <tr>
                                        <th><label for="ca-import-mnemonic-name">Collection Name</label></th>
                                        <td><input type="text" id="ca-import-mnemonic-name" class="regular-text" placeholder="e.g. My External Collection"></td>
                                    </tr>
                                    <tr>
                                        <th><label for="ca-import-mnemonic-seed">Seed Phrase</label></th>
                                        <td>
                                            <textarea id="ca-import-mnemonic-seed" class="large-text" rows="2" placeholder="Enter 12, 15, or 24 word mnemonic..."></textarea>
                                            <p class="description">The mnemonic will NOT be stored. Only the derived signing key is kept (encrypted).</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><label for="ca-import-mnemonic-expiry">Expiration Date</label></th>
                                        <td>
                                            <input type="datetime-local" id="ca-import-mnemonic-expiry" value="<?php echo gmdate( 'Y-m-d\TH:i', strtotime( '+1 year' ) ); ?>">
                                            <p class="description">A new policy script with time lock will be generated from the derived key.</p>
                                        </td>
                                    </tr>
                                </table>
                                <button type="button" class="button button-primary" id="ca-import-mnemonic-btn">Import Policy</button>
                                <span id="ca-import-mnemonic-status" style="margin-left:8px"></span>
                            </div>

                            <!-- Sub-tab: Full Manual -->
                            <div class="ca-import-tab-content" id="ca-import-manual" style="display:none">
                                <table class="form-table" style="margin:0">
                                    <tr>
                                        <th><label for="ca-import-manual-name">Collection Name</label></th>
                                        <td><input type="text" id="ca-import-manual-name" class="regular-text" placeholder="e.g. Custom Policy"></td>
                                    </tr>
                                    <tr>
                                        <th><label for="ca-import-manual-policy-id">Policy ID</label></th>
                                        <td>
                                            <input type="text" id="ca-import-manual-policy-id" class="large-text" placeholder="56-character hex policy ID">
                                            <p class="description">The script will be verified against this ID via Anvil.</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><label for="ca-import-manual-skey">Signing Key</label></th>
                                        <td><textarea id="ca-import-manual-skey" class="large-text" rows="3" placeholder='Paste .skey file contents (JSON) or raw hex...'></textarea></td>
                                    </tr>
                                    <tr>
                                        <th><label for="ca-import-manual-script">Policy Script</label></th>
                                        <td><textarea id="ca-import-manual-script" class="large-text" rows="3" placeholder='Paste native script JSON...'></textarea></td>
                                    </tr>
                                </table>
                                <button type="button" class="button button-primary" id="ca-import-manual-btn">Import Policy</button>
                                <span id="ca-import-manual-status" style="margin-left:8px"></span>
                            </div>
                        </div>
                    </div>

                    <!-- Step 2: Upload Image -->
                    <div class="ca-mint-step">
                        <h3>2. NFT Image</h3>
                        <div id="ca-mint-image-preview" style="display:none;margin-bottom:10px">
                            <img id="ca-mint-image-thumb" src="" style="max-width:200px;max-height:200px;border-radius:6px">
                        </div>
                        <button type="button" class="button" id="ca-mint-upload-image">Select from Media Library</button>
                        <input type="hidden" id="ca-mint-attachment-id" value="">
                        <input type="hidden" id="ca-mint-image-url" value="">
                        <span id="ca-mint-image-status" style="margin-left:8px"></span>

                        <div style="margin-top:10px">
                            <button type="button" class="button" id="ca-pin-to-ipfs" disabled>Re-pin to IPFS</button>
                            <span id="ca-pin-status" style="margin-left:8px"></span>
                            <?php if ( CA_Settings::is_pinata_enabled() ) : ?>
                                <p class="description" style="margin-top:4px">Image will be automatically pinned to IPFS when selected. Use "Re-pin" to retry if it fails.</p>
                            <?php else : ?>
                                <p class="description" style="margin-top:4px">Enable Pinata in Settings to auto-pin images to IPFS, or enter a CID manually below.</p>
                            <?php endif; ?>
                        </div>

                        <div style="margin-top:10px">
                            <label for="ca-mint-ipfs-cid"><strong>IPFS CID / Image URI</strong></label>
                            <input type="text" id="ca-mint-ipfs-cid" class="large-text" placeholder="Qm... or ipfs://Qm... or https://..." style="margin-top:4px">
                            <p class="description">Auto-filled when pinning succeeds, or enter manually.</p>
                        </div>
                        <input type="hidden" id="ca-mint-media-type" value="image/png">
                    </div>

                    <!-- Step 3: NFT Details -->
                    <div class="ca-mint-step">
                        <h3>3. NFT Details</h3>
                        <table class="form-table" style="margin:0">
                            <tr>
                                <th><label for="ca-mint-nft-name">NFT Name</label></th>
                                <td><input type="text" id="ca-mint-nft-name" class="large-text" placeholder="e.g. Rare Digital Artwork #1"></td>
                            </tr>
                            <tr>
                                <th><label for="ca-mint-nft-desc">Description</label></th>
                                <td><textarea id="ca-mint-nft-desc" class="large-text" rows="3" placeholder="Describe this NFT..."></textarea></td>
                            </tr>
                        </table>
                    </div>

                    <!-- Step 4: Auction Metadata -->
                    <div class="ca-mint-step">
                        <h3>4. Auction Metadata</h3>
                        <table class="form-table" style="margin:0">
                            <tr>
                                <th><label for="ca-mint-category">Category</label></th>
                                <td>
                                    <select id="ca-mint-category">
                                        <option value="">None</option>
                                        <option value="Art">Art</option>
                                        <option value="Collectible">Collectible</option>
                                        <option value="Photography">Photography</option>
                                        <option value="Music">Music</option>
                                        <option value="Gaming">Gaming</option>
                                        <option value="Utility">Utility</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="ca-mint-condition">Condition</label></th>
                                <td>
                                    <select id="ca-mint-condition">
                                        <option value="">None</option>
                                        <option value="New">New</option>
                                        <option value="Like New">Like New</option>
                                        <option value="Good">Good</option>
                                        <option value="Fair">Fair</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="ca-mint-creator">Creator / Artist</label></th>
                                <td><input type="text" id="ca-mint-creator" class="regular-text" placeholder="Artist name"></td>
                            </tr>
                        </table>
                        <p class="description">Starting bid and reserve price will be read from the Bidding section below.</p>
                    </div>

                    <!-- Step 5: Custom Attributes -->
                    <div class="ca-mint-step">
                        <h3>5. Custom Attributes</h3>
                        <div id="ca-custom-attributes">
                            <div class="ca-custom-attr-row">
                                <input type="text" class="ca-attr-key" placeholder="Key (e.g. rarity)">
                                <input type="text" class="ca-attr-value" placeholder="Value (e.g. legendary)">
                                <button type="button" class="button ca-remove-attr" title="Remove">&times;</button>
                            </div>
                        </div>
                        <button type="button" class="button" id="ca-add-attr" style="margin-top:8px">+ Add Attribute</button>
                    </div>

                    <!-- Mint Button -->
                    <div class="ca-mint-step ca-mint-action">
                        <button type="button" class="button button-primary button-hero" id="ca-mint-btn" disabled>Mint to Master Wallet</button>
                        <span id="ca-mint-status" style="display:block;margin-top:8px"></span>
                    </div>

                </div>
                <?php endif; ?>

                <div id="ca-nft-preview" style="<?php echo empty( $auction['nft_image_url'] ) ? 'display:none' : ''; ?>">
                    <div class="ca-nft-card">
                        <img id="ca-nft-img" src="<?php echo esc_url( $auction['nft_image_url'] ?? '' ); ?>" alt="">
                        <div>
                            <strong id="ca-nft-name"><?php echo esc_html( $auction['nft_display_name'] ?? '' ); ?></strong>
                            <div id="ca-nft-meta" class="ca-meta-list"></div>
                        </div>
                    </div>
                </div>

                <h2>Bidding</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="starting_bid">Starting Bid (ADA)</label></th>
                        <td><input type="number" name="starting_bid" id="starting_bid" value="<?php echo $ada('starting_bid_lovelace'); ?>" min="1" step="0.01" required></td>
                    </tr>
                    <tr>
                        <th><label for="reserve_price">Reserve Price (ADA)</label></th>
                        <td><input type="number" name="reserve_price" id="reserve_price" value="<?php echo $ada('reserve_price_lovelace'); ?>" min="0" step="0.01">
                            <p class="description">0 = no reserve</p></td>
                    </tr>
                    <tr>
                        <th><label for="min_increment">Min Increment (ADA)</label></th>
                        <td><input type="number" name="min_increment" id="min_increment" value="<?php echo $ada('min_increment_lovelace') ?: '1'; ?>" min="0.5" step="0.5"></td>
                    </tr>
                    <tr>
                        <th><label for="end_time">End Time (UTC)</label></th>
                        <td><input type="datetime-local" name="end_time" id="end_time" value="<?php echo str_replace( ' ', 'T', $s('end_time') ); ?>" required></td>
                    </tr>
                    <tr>
                        <th><label for="anti_snipe_seconds">Anti-Snipe Extension (s)</label></th>
                        <td><input type="number" name="anti_snipe_seconds" id="anti_snipe_seconds" value="<?php echo $s('anti_snipe_seconds', '300'); ?>" min="0"></td>
                    </tr>
                    <tr>
                        <th><label for="anti_snipe_threshold">Anti-Snipe Threshold (s)</label></th>
                        <td><input type="number" name="anti_snipe_threshold" id="anti_snipe_threshold" value="<?php echo $s('anti_snipe_threshold', '300'); ?>" min="0"></td>
                    </tr>
                    <tr>
                        <th><label for="seller_address">Seller Address</label></th>
                        <td><input type="text" name="seller_address" id="seller_address" class="large-text"
                                   value="<?php echo $s( 'seller_address', CA_Settings::get_default_seller_address() ); ?>" required></td>
                    </tr>
                </table>

                <?php submit_button( $is_new ? 'Create Auction' : 'Update Auction' ); ?>
            </div>

            <!-- Right column: custody + monitoring -->
            <div class="ca-col ca-col--side">
                <?php if ( $master_wallet && ! $is_new ) : ?>
                    <!-- Master wallet custody mode -->
                    <div class="ca-panel">
                        <h3>NFT Custody (Master Wallet)</h3>
                        <p>NFT will be sent from your Master Wallet at settlement. Ensure the NFT is in your master wallet.</p>
                        <p class="ca-mono"><?php echo esc_html( $master_wallet['payment_address'] ); ?></p>
                        <button type="button" class="button" id="ca-check-nft-master" data-auction="<?php echo (int) $auction['id']; ?>" data-address="<?php echo esc_attr( $master_wallet['payment_address'] ); ?>">Check NFT in Master Wallet</button>
                        <span id="ca-check-status"></span>

                        <?php if ( $auction['status'] === 'draft' ) : ?>
                            <p style="margin-top:12px">
                                <a href="<?php echo esc_url( wp_nonce_url(
                                    admin_url( 'admin.php?page=cardano-auctions&ca_action=activate&id=' . (int) $auction['id'] ),
                                    'ca_action_activate_' . (int) $auction['id'], 'ca_nonce'
                                ) ); ?>" class="button button-primary" id="ca-activate-btn">Activate Auction</a>
                            </p>
                            <p style="margin-top:8px">
                                <a href="<?php echo esc_url( wp_nonce_url(
                                    admin_url( 'admin.php?page=cardano-auctions&ca_action=force_activate&id=' . (int) $auction['id'] ),
                                    'ca_action_force_activate_' . (int) $auction['id'], 'ca_nonce'
                                ) ); ?>" class="button" onclick="return confirm('This will activate the auction without verifying NFT custody. Use for testing only. Continue?');">Force Activate (Skip NFT Check)</a>
                                <span class="description" style="display:block;margin-top:4px;font-size:11px;color:#666">For preprod testing — skips NFT custody verification.</span>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php elseif ( $custody ) : ?>
                    <!-- Legacy custody wallet mode -->
                    <div class="ca-panel">
                        <h3>NFT Custody Wallet</h3>
                        <p class="ca-mono"><?php echo esc_html( $custody['payment_address'] ); ?></p>
                        <p>Send the NFT to this address, then click "Check NFT" below.</p>
                        <button type="button" class="button" id="ca-check-nft" data-auction="<?php echo (int) $auction['id']; ?>">Check NFT</button>
                        <span id="ca-check-status"></span>

                        <?php if ( ! $is_new && $auction['status'] === 'draft' ) : ?>
                            <p style="margin-top:12px">
                                <a href="<?php echo esc_url( wp_nonce_url(
                                    admin_url( 'admin.php?page=cardano-auctions&ca_action=activate&id=' . (int) $auction['id'] ),
                                    'ca_action_activate_' . (int) $auction['id'], 'ca_nonce'
                                ) ); ?>" class="button button-primary" id="ca-activate-btn">Activate Auction</a>
                            </p>
                            <p style="margin-top:8px">
                                <a href="<?php echo esc_url( wp_nonce_url(
                                    admin_url( 'admin.php?page=cardano-auctions&ca_action=force_activate&id=' . (int) $auction['id'] ),
                                    'ca_action_force_activate_' . (int) $auction['id'], 'ca_nonce'
                                ) ); ?>" class="button" onclick="return confirm('This will activate the auction without verifying NFT custody. Use for testing only. Continue?');">Force Activate (Skip NFT Check)</a>
                                <span class="description" style="display:block;margin-top:4px;font-size:11px;color:#666">For preprod testing — skips NFT custody verification.</span>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ( ! $is_new && $auction['status'] === 'active' ) : ?>
                    <div class="ca-panel">
                        <h3>Live Status</h3>
                        <table class="ca-info-table">
                            <tr><td>Current Bid</td><td><strong id="ca-live-bid"><?php echo $auction['current_bid_lovelace'] > 0 ? number_format( $auction['current_bid_lovelace'] / 1000000, 2 ) . ' ADA' : 'None'; ?></strong></td></tr>
                            <tr><td>Total Bids</td><td id="ca-live-total"><?php echo (int) $auction['total_bids']; ?></td></tr>
                            <tr><td>Ends</td><td id="ca-live-end"><?php echo esc_html( $auction['end_time'] ); ?></td></tr>
                        </table>
                        <button type="button" class="button" id="ca-refresh" data-auction="<?php echo (int) $auction['id']; ?>">Refresh</button>
                        <button type="button" class="button" id="ca-poll-payments" data-auction="<?php echo (int) $auction['id']; ?>" style="margin-left:4px">Check Payments</button>
                        <button type="button" class="button" id="ca-process-refunds" data-auction="<?php echo (int) $auction['id']; ?>" style="margin-left:4px">Process Refunds</button>
                        <span id="ca-poll-status" style="display:block;margin-top:6px;font-size:12px;color:#666"></span>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $bids ) ) : ?>
                    <div class="ca-panel">
                        <h3>Bid History</h3>
                        <table class="widefat fixed striped" style="font-size:12px">
                            <thead><tr><th>Amount</th><th>Bidder</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php foreach ( $bids as $b ) : ?>
                                <tr>
                                    <td><?php echo number_format( $b['bid_lovelace'] / 1000000, 2 ); ?> ADA</td>
                                    <td title="<?php echo esc_attr( $b['bidder_address'] ); ?>"><?php echo esc_html( substr( $b['bidder_address'], 0, 10 ) . '...' . substr( $b['bidder_address'], -6 ) ); ?></td>
                                    <td><span class="ca-status ca-status--<?php echo esc_attr( $b['payment_status'] ); ?>"><?php echo esc_html( $b['payment_status'] ); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $events ) ) : ?>
                    <div class="ca-panel">
                        <h3>
                            Event Log <span class="ca-badge"><?php echo count( $events ); ?></span>
                            <?php if ( ! $is_new ) : ?>
                                <a href="<?php echo esc_url( wp_nonce_url(
                                    admin_url( 'admin.php?page=cardano-auctions&ca_action=export_events&id=' . (int) $auction['id'] ),
                                    'ca_action_export_events_' . (int) $auction['id'], 'ca_nonce'
                                ) ); ?>" class="ca-export-link">Export CSV</a>
                            <?php endif; ?>
                        </h3>
                        <div class="ca-event-log">
                            <?php foreach ( $events as $ev ) : ?>
                                <div class="ca-event">
                                    <span class="ca-event-time"><?php echo esc_html( $ev['created_at'] ); ?></span>
                                    <span class="ca-event-type ca-etype--<?php echo esc_attr( sanitize_html_class( $ev['event_type'] ) ); ?>"><?php echo esc_html( $ev['event_type'] ); ?></span>
                                    <span class="ca-event-msg"><?php echo esc_html( $ev['message'] ); ?></span>
                                    <?php if ( ! empty( $ev['meta'] ) && '{}' !== $ev['meta'] ) : ?>
                                        <button type="button" class="ca-meta-toggle dashicons dashicons-arrow-down-alt2" title="Toggle meta"></button>
                                        <pre class="ca-event-meta" style="display:none"><?php echo esc_html( wp_json_encode( json_decode( $ev['meta'], true ), JSON_PRETTY_PRINT ) ); ?></pre>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>
