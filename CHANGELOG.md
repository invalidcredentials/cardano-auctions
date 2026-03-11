# Changelog

All notable changes to Cardano Auctions will be documented in this file.

## [1.4.0] - 2025-02

### Added
- **Embeddable Widget System** — deploy auctions on external sites via JavaScript widget with API key authentication, CORS support, and theme customization.
- **Widget Deployer** admin page with API key generation, revocation, and origin restrictions.
- **NFT Minting Pipeline** — create minting policies (time-locked native scripts), upload images to IPFS via Pinata, build CIP-25 metadata, and mint NFTs directly to the master wallet.
- **Gallery images** — auctions support multiple images with a thumbnail strip and click-to-switch.
- **Bidder status endpoint** — `GET /cah/v1/auctions/{id}/bidder-status` for checking a wallet's status on an auction.
- **Transaction proxy endpoints** — `POST /cah/v1/tx/build` and `POST /cah/v1/tx/submit` to proxy Anvil API calls, avoiding CORS and protecting API keys.
- **CSV event export** — download event logs as CSV from the admin monitor.
- **Artist name and listed by fields** on auctions.
- **Custom image override** — optional `image_url` field to use a custom image instead of the on-chain NFT image.
- **Scheduled auction status** — auctions can be scheduled with a future start time.
- **Force activate** — admins can activate auctions without NFT custody verification for testing.

### Changed
- Master wallet is now the primary wallet model; per-auction custody wallets remain as legacy fallback.
- Improved admin dashboard with wallet management, balance refresh, and manual send capabilities.

## [1.3.0] - 2025-02

### Added
- **Master HD Wallet** — single seed generates all escrow addresses via CIP-1852 HD derivation.
- **NFT browser** — browse master wallet NFTs when creating auctions instead of manually pasting asset IDs.
- **Anti-snipe protection** — configurable timer extension when bids arrive near the deadline.
- **NFT metadata display** — collapsible details section on frontend showing policy ID, asset name, and on-chain metadata.

## [1.2.0] - 2025-02

### Added
- **Auto-settlement** — NFT transfer to winner and ADA transfer to seller happen automatically when auction ends.
- **Automatic refunds** — outbid users receive refunds via server-signed transactions.
- **Payment monitoring** — WP-Cron polls Blockfrost every 30 seconds for escrow payments.
- **Live frontend polling** — bid status endpoint does real-time Blockfrost checks inline.

## [1.1.0] - 2025-02

### Added
- **CIP-30 wallet connect** — Nami, Eternl, Lace, Flint, Vespr, Yoroi, and more.
- **Escrow-based bidding** — each bid gets a unique wallet, no funds commingled.
- **REST API** for auction listing, bid placement, and status polling.
- **Shortcodes** — `[cardano_auction]` and `[cardano_auction_list]`.
- **Admin dashboard** — create and manage auctions, monitor bids, view event logs.

## [1.0.0] - 2025-02

### Added
- Initial release.
- Embedded Cardano cryptography stack (Ed25519, BIP39, CIP-1852, CBOR).
- Blockfrost and Anvil API integration.
- AES-256-CBC encryption for wallet keys.
- 6 custom database tables with `dbDelta()` migrations.
- Clean uninstall with full data removal.
