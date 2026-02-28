<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class CA_Shortcodes {

    public static function init(): void {
        add_shortcode( 'cardano_auction',      array( __CLASS__, 'single_auction' ) );
        add_shortcode( 'cardano_auction_list',  array( __CLASS__, 'auction_list' ) );
    }

    /**
     * [cardano_auction id="123"]
     */
    public static function single_auction( $atts ): string {
        $atts = shortcode_atts( array( 'id' => 0 ), $atts );
        $id = (int) $atts['id'];
        if ( ! $id ) return '<p class="ca-error">No auction ID specified.</p>';

        $auction = CA_Auction_Model::get( $id );
        if ( ! $auction ) return '<p class="ca-error">Auction not found.</p>';

        ob_start();
        ?>
        <?php
        $display_image = ! empty( $auction['image_url'] ) ? $auction['image_url'] : $auction['nft_image_url'];
        $gallery = array();
        if ( ! empty( $auction['gallery_images'] ) ) {
            $gallery = json_decode( $auction['gallery_images'], true );
            if ( ! is_array( $gallery ) ) $gallery = array();
        }
        if ( empty( $gallery ) && $display_image ) {
            $gallery = array( $display_image );
        }
        ?>
        <div class="ca-auction" data-auction-id="<?php echo $id; ?>" data-status="<?php echo esc_attr( $auction['status'] ); ?>">
            <div class="ca-auction__header">
                <?php if ( ! empty( $gallery ) ) : ?>
                    <div class="ca-auction__image">
                        <img class="ca-auction__main-img" src="<?php echo esc_url( $gallery[0] ); ?>" alt="<?php echo esc_attr( $auction['title'] ); ?>">
                        <?php if ( count( $gallery ) > 1 ) : ?>
                            <div class="ca-auction__gallery-thumbs">
                                <?php foreach ( $gallery as $i => $gimg ) : ?>
                                    <img src="<?php echo esc_url( $gimg ); ?>" alt="" class="ca-auction__gallery-thumb<?php echo $i === 0 ? ' active' : ''; ?>" data-full="<?php echo esc_url( $gimg ); ?>">
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <div class="ca-auction__info">
                    <h3 class="ca-auction__title"><?php echo esc_html( $auction['title'] ); ?></h3>
                    <p class="ca-auction__nft-name"><?php echo esc_html( $auction['nft_display_name'] ); ?></p>
                    <?php if ( $auction['description'] ) : ?>
                        <p class="ca-auction__desc"><?php echo esc_html( $auction['description'] ); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="ca-auction__body">
                <div class="ca-auction__stats">
                    <div class="ca-stat">
                        <span class="ca-stat__label">Current Bid</span>
                        <span class="ca-stat__value ca-current-bid">
                            <?php echo $auction['current_bid_lovelace'] > 0
                                ? number_format( $auction['current_bid_lovelace'] / 1000000, 2 ) . ' ADA'
                                : number_format( $auction['starting_bid_lovelace'] / 1000000, 2 ) . ' ADA (starting)'; ?>
                        </span>
                    </div>
                    <div class="ca-stat">
                        <span class="ca-stat__label">Time Remaining</span>
                        <span class="ca-stat__value ca-countdown" data-end="<?php echo esc_attr( $auction['end_time'] ); ?>">
                            &mdash;
                        </span>
                    </div>
                    <div class="ca-stat">
                        <span class="ca-stat__label">Total Bids</span>
                        <span class="ca-stat__value ca-total-bids"><?php echo (int) $auction['total_bids']; ?></span>
                    </div>
                    <?php if ( $auction['current_bidder_address'] ) : ?>
                        <div class="ca-stat">
                            <span class="ca-stat__label">Highest Bidder</span>
                            <span class="ca-stat__value ca-highest-bidder"><?php
                                echo esc_html( substr( $auction['current_bidder_address'], 0, 10 ) . '...' . substr( $auction['current_bidder_address'], -6 ) );
                            ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ( $auction['status'] === 'active' ) : ?>
                    <div class="ca-auction__bid-form">
                        <div class="ca-wallet-status">
                            <button type="button" class="ca-btn ca-btn--connect" id="ca-connect-wallet">
                                Connect Wallet
                            </button>
                            <span class="ca-wallet-address" style="display:none"></span>
                        </div>

                        <div class="ca-bid-input" style="display:none">
                            <label for="ca-bid-amount-<?php echo $id; ?>">Your Bid (ADA)</label>
                            <div class="ca-bid-row">
                                <input type="number" id="ca-bid-amount-<?php echo $id; ?>" class="ca-bid-amount"
                                       min="<?php echo esc_attr( self::min_next_bid( $auction ) ); ?>"
                                       step="0.5"
                                       value="<?php echo esc_attr( self::min_next_bid( $auction ) ); ?>"
                                       placeholder="<?php echo esc_attr( self::min_next_bid( $auction ) ); ?>">
                                <button type="button" class="ca-btn ca-btn--bid ca-place-bid" data-auction="<?php echo $id; ?>">
                                    Place Bid
                                </button>
                            </div>
                            <p class="ca-bid-min">Minimum: <?php echo esc_html( self::min_next_bid( $auction ) ); ?> ADA</p>
                        </div>

                        <div class="ca-bid-status" style="display:none">
                            <div class="ca-spinner"></div>
                            <p class="ca-status-text"></p>
                        </div>
                    </div>
                <?php elseif ( $auction['status'] === 'draft' ) : ?>
                    <div class="ca-auction__draft">
                        <p>This auction is being prepared and is not yet open for bidding.</p>
                        <p class="ca-draft-sub">The seller is setting up NFT custody. Check back soon!</p>
                    </div>
                <?php elseif ( $auction['status'] === 'scheduled' ) : ?>
                    <div class="ca-auction__scheduled">
                        <p>This auction is scheduled to begin on <strong><?php echo esc_html( date( 'M j, Y \a\t g:i A', strtotime( $auction['start_time'] ) ) ); ?></strong>.</p>
                        <p class="ca-scheduled-sub">Bidding will open automatically at the start time.</p>
                    </div>
                <?php elseif ( $auction['status'] === 'settled' ) : ?>
                    <div class="ca-auction__settled">
                        <p>Auction settled! Winner: <?php echo esc_html( substr( $auction['current_bidder_address'], 0, 10 ) . '...' . substr( $auction['current_bidder_address'], -6 ) ); ?></p>
                        <p>Winning bid: <?php echo number_format( $auction['current_bid_lovelace'] / 1000000, 2 ); ?> ADA</p>
                    </div>
                <?php elseif ( $auction['status'] === 'ended' || $auction['status'] === 'settling' ) : ?>
                    <div class="ca-auction__ending"><p>Auction ended. Settlement in progress...</p></div>
                <?php elseif ( $auction['status'] === 'cancelled' ) : ?>
                    <div class="ca-auction__cancelled"><p>This auction has been cancelled.</p></div>
                <?php elseif ( $auction['status'] === 'failed' ) : ?>
                    <div class="ca-auction__cancelled"><p>This auction could not be completed.</p></div>
                <?php endif; ?>

                <div class="ca-auction__bids">
                    <h4>Recent Bids</h4>
                    <div class="ca-bid-history" data-auction="<?php echo $id; ?>">
                        <p class="ca-loading">Loading bid history...</p>
                    </div>
                </div>

                <?php
                $nft_meta = ! empty( $auction['nft_metadata'] ) ? json_decode( $auction['nft_metadata'], true ) : null;
                if ( $nft_meta && is_array( $nft_meta ) ) :
                ?>
                    <details class="ca-auction__metadata">
                        <summary>NFT Details</summary>
                        <dl class="ca-metadata-list">
                            <?php if ( ! empty( $auction['nft_policy_id'] ) ) : ?>
                                <dt>Policy ID</dt>
                                <dd><?php echo esc_html( $auction['nft_policy_id'] ); ?></dd>
                            <?php endif; ?>
                            <?php if ( ! empty( $auction['nft_display_name'] ) ) : ?>
                                <dt>Asset Name</dt>
                                <dd><?php echo esc_html( $auction['nft_display_name'] ); ?></dd>
                            <?php endif; ?>
                            <?php foreach ( $nft_meta as $meta_key => $meta_val ) : ?>
                                <?php if ( 'image' === $meta_key ) continue; ?>
                                <dt><?php echo esc_html( $meta_key ); ?></dt>
                                <dd><?php echo esc_html( is_array( $meta_val ) ? implode( ', ', $meta_val ) : $meta_val ); ?></dd>
                            <?php endforeach; ?>
                        </dl>
                    </details>
                <?php endif; ?>
            </div>

            <div class="ca-auction__footer">
                <span class="ca-network-badge"><?php echo esc_html( strtoupper( $auction['network'] ) ); ?></span>
                <?php if ( $auction['reserve_price_lovelace'] > 0 ) : ?>
                    <span class="ca-reserve-badge">Reserve price set</span>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * [cardano_auction_list status="active" limit="12"]
     */
    public static function auction_list( $atts ): string {
        $atts = shortcode_atts( array(
            'status' => 'active',
            'limit'  => 12,
        ), $atts );

        $auctions = CA_Auction_Model::get_all( array(
            'status'  => sanitize_text_field( $atts['status'] ),
            'network' => CA_Settings::get_network(),
            'limit'   => (int) $atts['limit'],
        ) );

        if ( empty( $auctions ) ) {
            return '<p class="ca-empty">No auctions found.</p>';
        }

        ob_start();
        echo '<div class="ca-auction-grid">';
        foreach ( $auctions as $a ) {
            $url = '#'; // Link to single auction page if applicable.
            ?>
            <?php $card_image = ! empty( $a['image_url'] ) ? $a['image_url'] : $a['nft_image_url']; ?>
            <div class="ca-auction-card" data-auction-id="<?php echo (int) $a['id']; ?>">
                <?php if ( $card_image ) : ?>
                    <div class="ca-auction-card__image">
                        <img src="<?php echo esc_url( $card_image ); ?>" alt="<?php echo esc_attr( $a['title'] ); ?>">
                    </div>
                <?php endif; ?>
                <div class="ca-auction-card__body">
                    <h4><?php echo esc_html( $a['title'] ); ?></h4>
                    <p class="ca-auction-card__nft"><?php echo esc_html( $a['nft_display_name'] ); ?></p>
                    <div class="ca-auction-card__meta">
                        <span class="ca-auction-card__bid">
                            <?php echo $a['current_bid_lovelace'] > 0
                                ? number_format( $a['current_bid_lovelace'] / 1000000, 2 ) . ' ADA'
                                : number_format( $a['starting_bid_lovelace'] / 1000000, 2 ) . ' ADA'; ?>
                        </span>
                        <span class="ca-countdown" data-end="<?php echo esc_attr( $a['end_time'] ); ?>">&mdash;</span>
                    </div>
                    <span class="ca-status ca-status--<?php echo esc_attr( $a['status'] ); ?>"><?php echo esc_html( ucfirst( $a['status'] ) ); ?></span>
                </div>
            </div>
            <?php
        }
        echo '</div>';
        return ob_get_clean();
    }

    private static function min_next_bid( array $auction ): string {
        $min = (int) $auction['current_bid_lovelace'] > 0
            ? (int) $auction['current_bid_lovelace'] + (int) $auction['min_increment_lovelace']
            : (int) $auction['starting_bid_lovelace'];
        return number_format( $min / 1000000, 2, '.', '' );
    }
}
