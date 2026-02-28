/**
 * Cardano Auctions — Frontend JS
 *
 * Self-contained CIP-30 wallet connect + auction bid flow.
 * No external JS dependencies.
 */
(function () {
    'use strict';

    const config = window.CA_Config || {};
    const REST = config.restUrl || '/wp-json/cah/v1';
    const NONCE = config.nonce || '';
    const POLL_INTERVAL = config.pollInterval || 5000;
    const STORAGE_KEY = 'ca_wallet_key';

    /* ── Wallet State ─────────────────────────────────────────────── */

    const wallet = {
        connected: false,
        address: '',
        addressHex: '',
        handler: null,
        key: '',
        name: '',
    };

    const KNOWN_WALLETS = [
        'nami', 'eternl', 'lace', 'flint', 'typhoncip30',
        'gerowallet', 'nufi', 'begin', 'vespr', 'yoroi'
    ];

    function getInstalledWallets() {
        if (!window.cardano) return [];
        return KNOWN_WALLETS.filter(k => window.cardano[k]);
    }

    async function connectWallet(key) {
        try {
            const api = await window.cardano[key].enable();
            wallet.handler = api;
            wallet.key = key;
            wallet.name = window.cardano[key].name || key;

            const addresses = await api.getUsedAddresses();
            const addr = addresses[0] || (await api.getUnusedAddresses())[0];
            wallet.addressHex = addr;
            wallet.address = hexToBech32(addr);
            wallet.connected = true;

            // Persist wallet key for reconnect on page refresh.
            try { sessionStorage.setItem(STORAGE_KEY, key); } catch (e) { /* private browsing */ }

            updateWalletUI();

            // Check bidder status for all visible auctions.
            checkBidderStatusAll();

            return wallet;
        } catch (e) {
            console.error('Wallet connect failed:', e);
            return null;
        }
    }

    function disconnectWallet() {
        wallet.connected = false;
        wallet.address = '';
        wallet.addressHex = '';
        wallet.handler = null;
        wallet.key = '';
        wallet.name = '';

        try { sessionStorage.removeItem(STORAGE_KEY); } catch (e) { /* private browsing */ }

        updateWalletUI();
        clearBidderStatusAll();
    }

    /**
     * Try to restore wallet connection from sessionStorage.
     */
    async function tryAutoReconnect() {
        let savedKey;
        try { savedKey = sessionStorage.getItem(STORAGE_KEY); } catch (e) { return; }
        if (!savedKey) return;
        if (!window.cardano || !window.cardano[savedKey]) return;

        // Check if the wallet extension is still available and auto-connects.
        try {
            const isEnabled = await window.cardano[savedKey].isEnabled();
            if (isEnabled) {
                await connectWallet(savedKey);
            } else {
                // Wallet available but not enabled — try to enable (may show popup).
                await connectWallet(savedKey);
            }
        } catch (e) {
            console.log('Auto-reconnect failed:', e.message);
            try { sessionStorage.removeItem(STORAGE_KEY); } catch (ex) { /* */ }
        }
    }

    /* ── Bidder Status Check ──────────────────────────────────────── */

    /**
     * Check bidder status for all visible auction elements.
     */
    function checkBidderStatusAll() {
        if (!wallet.connected) return;
        document.querySelectorAll('.ca-auction[data-status="active"]').forEach(auctionEl => {
            checkBidderStatus(auctionEl);
        });
    }

    /**
     * Clear bidder status banners from all auctions (on disconnect).
     */
    function clearBidderStatusAll() {
        document.querySelectorAll('.ca-bidder-banner').forEach(el => el.remove());
    }

    /**
     * Check the connected wallet's status for a specific auction.
     * Shows appropriate banner: highest bidder, pending bid, etc.
     */
    async function checkBidderStatus(auctionEl) {
        if (!wallet.connected) return;
        const auctionId = auctionEl.dataset.auctionId;

        try {
            const data = await apiGet(`/auctions/${auctionId}/bidder-status?address=${encodeURIComponent(wallet.address)}`);

            // Remove any existing banner.
            const existing = auctionEl.querySelector('.ca-bidder-banner');
            if (existing) existing.remove();

            const bidFormEl = auctionEl.querySelector('.ca-bid-input');
            const statusEl = auctionEl.querySelector('.ca-bid-status');
            const walletStatusEl = auctionEl.querySelector('.ca-wallet-status');

            if (data.is_highest_bidder) {
                // Show "you are the highest bidder" banner, hide bid form.
                const banner = document.createElement('div');
                banner.className = 'ca-bidder-banner ca-bidder-banner--winning';
                banner.innerHTML = `<strong>You are the highest bidder!</strong><br>Your winning bid: ${data.highest_bid_ada} ADA`;
                if (walletStatusEl) walletStatusEl.after(banner);
                if (bidFormEl) bidFormEl.style.display = 'none';
                if (statusEl) statusEl.style.display = 'none';
            } else if (data.has_pending_bid && data.pending_bid) {
                // Show pending bid status, hide bid form.
                const pb = data.pending_bid;
                const banner = document.createElement('div');
                banner.className = 'ca-bidder-banner ca-bidder-banner--pending';
                banner.innerHTML = `<strong>You have a pending bid of ${pb.bid_ada} ADA</strong><br>Status: ${pb.payment_status}. Send payment to the escrow address shown after placing your bid.`;
                if (walletStatusEl) walletStatusEl.after(banner);
                if (bidFormEl) bidFormEl.style.display = 'none';

                // If still awaiting/detected, show the status and resume polling.
                if (statusEl && (pb.payment_status === 'awaiting' || pb.payment_status === 'detected')) {
                    statusEl.style.display = 'flex';
                    const statusText = auctionEl.querySelector('.ca-status-text');
                    if (statusText) {
                        statusText.textContent = pb.payment_status === 'detected'
                            ? 'Payment detected, confirming...'
                            : 'Waiting for payment confirmation...';
                    }
                    pollBidStatus(pb.bid_id, auctionEl);
                }
            } else {
                // Not the highest bidder and no pending bid — show bid form normally.
                if (bidFormEl) bidFormEl.style.display = '';
            }
        } catch (e) {
            console.error('Bidder status check failed:', e);
        }
    }

    /* ── Bech32 Encoding ──────────────────────────────────────────── */

    function hexToBech32(hex) {
        const bytes = hexToBytes(hex);
        if (!bytes.length) return hex;
        const firstByte = bytes[0];
        const networkId = firstByte & 0x0F;
        const hrp = networkId === 1 ? 'addr' : 'addr_test';
        const data5 = to5Bits(bytes);
        return bech32Encode(hrp, data5);
    }

    function hexToBytes(hex) {
        const bytes = [];
        for (let i = 0; i < hex.length; i += 2) bytes.push(parseInt(hex.substr(i, 2), 16));
        return bytes;
    }

    function to5Bits(bytes) {
        let bits = 0, value = 0, out = [];
        for (const b of bytes) {
            value = (value << 8) | b;
            bits += 8;
            while (bits >= 5) { out.push((value >> (bits - 5)) & 31); bits -= 5; }
        }
        if (bits > 0) out.push((value << (5 - bits)) & 31);
        return out;
    }

    function bech32Encode(hrp, data5) {
        const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
        const GEN = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
        function polymod(values) {
            let chk = 1;
            for (const v of values) {
                const b = chk >> 25;
                chk = ((chk & 0x1ffffff) << 5) ^ v;
                for (let i = 0; i < 5; i++) if ((b >> i) & 1) chk ^= GEN[i];
            }
            return chk;
        }
        function hrpExpand(h) {
            const out = [];
            for (let i = 0; i < h.length; i++) out.push(h.charCodeAt(i) >> 5);
            out.push(0);
            for (let i = 0; i < h.length; i++) out.push(h.charCodeAt(i) & 31);
            return out;
        }
        const chk = polymod([...hrpExpand(hrp), ...data5, 0, 0, 0, 0, 0, 0]) ^ 1;
        const checksum = [];
        for (let p = 0; p < 6; p++) checksum.push((chk >> (5 * (5 - p))) & 31);
        return hrp + '1' + [...data5, ...checksum].map(v => CHARSET[v]).join('');
    }

    /* ── REST Helpers ─────────────────────────────────────────────── */

    async function apiGet(endpoint) {
        const res = await fetch(REST + endpoint, { headers: { 'X-WP-Nonce': NONCE } });
        return res.json();
    }

    async function apiPost(endpoint, data) {
        const res = await fetch(REST + endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
            body: JSON.stringify(data),
        });
        return res.json();
    }

    /* ── Transaction Build/Submit (via WP REST proxy to Anvil) ────── */

    async function txBuild(changeAddress, outputs) {
        return apiPost('/tx/build', {
            change_address: changeAddress,
            outputs: outputs,
        });
    }

    async function txSubmit(transaction, witnesses) {
        return apiPost('/tx/submit', {
            transaction: transaction,
            witnesses: witnesses,
        });
    }

    /* ── Bid Flow ─────────────────────────────────────────────────── */

    async function placeBid(auctionId, bidAda) {
        const auctionEl = document.querySelector(`.ca-auction[data-auction-id="${auctionId}"]`);
        if (!auctionEl) return;

        const statusEl = auctionEl.querySelector('.ca-bid-status');
        const statusText = auctionEl.querySelector('.ca-status-text');
        const formEl = auctionEl.querySelector('.ca-bid-input');

        function showStatus(msg) {
            statusEl.style.display = 'flex';
            formEl.style.display = 'none';
            statusText.textContent = msg;
        }

        try {
            showStatus('Placing bid...');

            // 1. Call REST API to create bid + get escrow address.
            const bidResult = await apiPost(`/auctions/${auctionId}/bid`, {
                bidder_address: wallet.address,
                bid_ada: bidAda,
            });

            if (bidResult.error) {
                showStatus('Error: ' + bidResult.error);
                setTimeout(() => { statusEl.style.display = 'none'; formEl.style.display = ''; }, 4000);
                return;
            }

            const { bid_id, escrow_address, amount_lovelace } = bidResult;
            showStatus('Building payment transaction...');

            // 2. Build tx via WP REST → Anvil: bidder → escrow.
            const buildResult = await txBuild(wallet.address, [
                { address: escrow_address, lovelace: amount_lovelace },
            ]);

            if (!buildResult || buildResult.error) {
                showStatus('Transaction build failed. ' + (buildResult?.error || buildResult?.message || ''));
                setTimeout(() => { statusEl.style.display = 'none'; formEl.style.display = ''; }, 5000);
                return;
            }

            const unsignedTx = buildResult.complete || buildResult.stripped || buildResult.tx_cbor || '';
            if (!unsignedTx) {
                console.error('Build result:', buildResult);
                showStatus('Transaction build returned no CBOR. Check API keys in settings.');
                return;
            }

            showStatus('Please sign in your wallet...');

            // 3. Sign with CIP-30 wallet.
            const witness = await wallet.handler.signTx(unsignedTx, true);
            showStatus('Submitting transaction...');

            // 4. Submit via WP REST → Anvil.
            const submitResult = await txSubmit(unsignedTx, [witness]);
            if (!submitResult || submitResult.error) {
                showStatus('Submit failed. ' + (submitResult?.error || submitResult?.message || ''));
                return;
            }

            showStatus('Transaction submitted! Waiting for confirmation...');

            // 5. Poll bid status.
            pollBidStatus(bid_id, auctionEl);

        } catch (e) {
            console.error('Bid flow error:', e);
            showStatus('Error: ' + (e.message || 'Wallet rejected or network error.'));
            setTimeout(() => { statusEl.style.display = 'none'; formEl.style.display = ''; }, 5000);
        }
    }

    function pollBidStatus(bidId, auctionEl) {
        const statusText = auctionEl.querySelector('.ca-status-text');
        let attempts = 0;
        const maxAttempts = 120; // 10 minutes at 5s intervals

        const interval = setInterval(async () => {
            attempts++;
            if (attempts > maxAttempts) {
                clearInterval(interval);
                statusText.textContent = 'Payment monitoring timed out. Your bid is still being tracked.';
                return;
            }

            try {
                const data = await apiGet(`/bids/${bidId}/status`);
                if (data.payment_status === 'confirmed' || data.payment_status === 'won') {
                    clearInterval(interval);
                    statusText.textContent = 'Bid confirmed! You are the highest bidder!';
                    auctionEl.querySelector('.ca-bid-status').classList.add('ca-confirmed');
                    refreshAuction(auctionEl);
                    // Re-check bidder status to show winning banner.
                    checkBidderStatus(auctionEl);
                } else if (data.payment_status === 'detected') {
                    statusText.textContent = 'Payment detected, confirming...';
                } else if (data.payment_status === 'expired') {
                    clearInterval(interval);
                    statusText.textContent = 'Bid expired (payment timeout).';
                    // Re-check to restore bid form.
                    setTimeout(() => checkBidderStatus(auctionEl), 2000);
                } else if (data.payment_status === 'refunding' || data.payment_status === 'refunded') {
                    clearInterval(interval);
                    statusText.textContent = 'You have been outbid. Refund is being processed.';
                    // Re-check to restore bid form.
                    setTimeout(() => checkBidderStatus(auctionEl), 2000);
                }
            } catch (e) { /* retry */ }
        }, POLL_INTERVAL);
    }

    /* ── Auction Refresh ──────────────────────────────────────────── */

    async function refreshAuction(auctionEl) {
        const id = auctionEl.dataset.auctionId;
        try {
            const data = await apiGet(`/auctions/${id}`);
            const bidEl = auctionEl.querySelector('.ca-current-bid');
            if (bidEl) bidEl.textContent = data.current_bid_ada ? data.current_bid_ada + ' ADA' : data.starting_bid_ada + ' ADA (starting)';
            const totalEl = auctionEl.querySelector('.ca-total-bids');
            if (totalEl) totalEl.textContent = data.total_bids;
            const bidderEl = auctionEl.querySelector('.ca-highest-bidder');
            if (bidderEl && data.current_bidder) bidderEl.textContent = data.current_bidder;

            // Update min bid input.
            const minNext = data.current_bid_lovelace > 0
                ? (data.current_bid_lovelace + parseFloat(data.min_increment_ada) * 1000000) / 1000000
                : parseFloat(data.starting_bid_ada);
            const bidInput = auctionEl.querySelector('.ca-bid-amount');
            if (bidInput) {
                bidInput.min = minNext.toFixed(2);
                bidInput.value = minNext.toFixed(2);
            }
            const minLabel = auctionEl.querySelector('.ca-bid-min');
            if (minLabel) minLabel.textContent = `Minimum: ${minNext.toFixed(2)} ADA`;
        } catch (e) { /* silent */ }

        // Refresh bid history.
        loadBidHistory(auctionEl);

        // Re-check bidder status if connected.
        if (wallet.connected) {
            checkBidderStatus(auctionEl);
        }
    }

    async function loadBidHistory(auctionEl) {
        const id = auctionEl.dataset.auctionId;
        const historyEl = auctionEl.querySelector('.ca-bid-history');
        if (!historyEl) return;

        try {
            const data = await apiGet(`/auctions/${id}/bids`);
            if (!data.bids || data.bids.length === 0) {
                historyEl.innerHTML = '<p class="ca-empty">No bids yet.</p>';
                return;
            }
            historyEl.innerHTML = data.bids.slice(0, 10).map(b =>
                `<div class="ca-bid-entry">
                    <span class="ca-bid-entry__amount">${b.bid_ada} ADA</span>
                    <span class="ca-bid-entry__bidder">${b.bidder}</span>
                    <span class="ca-bid-entry__status ca-status--${b.payment_status}">${b.payment_status}</span>
                </div>`
            ).join('');
        } catch (e) {
            historyEl.innerHTML = '<p class="ca-error">Failed to load bids.</p>';
        }
    }

    /* ── Countdown Timer ──────────────────────────────────────────── */

    function startCountdowns() {
        document.querySelectorAll('.ca-countdown').forEach(el => {
            const end = new Date(el.dataset.end + 'Z').getTime();
            function tick() {
                const now = Date.now();
                const diff = end - now;
                if (diff <= 0) { el.textContent = 'Ended'; return; }
                const d = Math.floor(diff / 86400000);
                const h = Math.floor((diff % 86400000) / 3600000);
                const m = Math.floor((diff % 3600000) / 60000);
                const s = Math.floor((diff % 60000) / 1000);
                el.textContent = d > 0
                    ? `${d}d ${h}h ${m}m ${s}s`
                    : h > 0 ? `${h}h ${m}m ${s}s` : `${m}m ${s}s`;
            }
            tick();
            setInterval(tick, 1000);
        });
    }

    /* ── UI Updates ───────────────────────────────────────────────── */

    function updateWalletUI() {
        document.querySelectorAll('.ca-auction').forEach(auctionEl => {
            const connectBtn = auctionEl.querySelector('.ca-btn--connect');
            const addrSpan = auctionEl.querySelector('.ca-wallet-address');
            const bidInput = auctionEl.querySelector('.ca-bid-input');
            const statusEl = auctionEl.querySelector('.ca-bid-status');

            if (wallet.connected) {
                if (connectBtn) { connectBtn.textContent = 'Disconnect'; connectBtn.classList.add('ca-connected'); }
                if (addrSpan) {
                    addrSpan.style.display = '';
                    addrSpan.textContent = wallet.name + ': ' + wallet.address.substring(0, 12) + '...' + wallet.address.slice(-6);
                }
                // Don't show bid form yet — checkBidderStatus will decide visibility.
                // Only show if no bidder banner exists (initial state before status check).
                if (bidInput && !auctionEl.querySelector('.ca-bidder-banner')) {
                    bidInput.style.display = 'none'; // Hidden until status check completes.
                }
            } else {
                if (connectBtn) { connectBtn.textContent = 'Connect Wallet'; connectBtn.classList.remove('ca-connected'); }
                if (addrSpan) addrSpan.style.display = 'none';
                if (bidInput) bidInput.style.display = 'none';
                // Don't clear the bid status area if a bid is actively being monitored.
            }
        });
    }

    /* ── Wallet Modal ─────────────────────────────────────────────── */

    function showWalletModal() {
        const installed = getInstalledWallets();
        if (installed.length === 0) {
            alert('No Cardano wallets detected. Please install a CIP-30 compatible wallet (Nami, Eternl, Lace, etc.).');
            return;
        }

        // Create modal.
        let modal = document.getElementById('ca-wallet-modal');
        if (modal) modal.remove();

        modal = document.createElement('div');
        modal.id = 'ca-wallet-modal';
        modal.className = 'ca-modal-overlay';
        modal.innerHTML = `
            <div class="ca-modal">
                <div class="ca-modal__header">
                    <h3>Connect Wallet</h3>
                    <button class="ca-modal__close">&times;</button>
                </div>
                <div class="ca-modal__body">
                    ${installed.map(key => `
                        <button class="ca-wallet-option" data-wallet="${key}">
                            <img src="${window.cardano[key]?.icon || ''}" alt="" class="ca-wallet-icon">
                            <span>${window.cardano[key]?.name || key}</span>
                        </button>
                    `).join('')}
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        modal.querySelector('.ca-modal__close').addEventListener('click', () => modal.remove());
        modal.addEventListener('click', (e) => { if (e.target === modal) modal.remove(); });
        modal.querySelectorAll('.ca-wallet-option').forEach(btn => {
            btn.addEventListener('click', async () => {
                const key = btn.dataset.wallet;
                btn.textContent = 'Connecting...';
                await connectWallet(key);
                modal.remove();
            });
        });
    }

    /* ── Event Binding ────────────────────────────────────────────── */

    function bindEvents() {
        // Gallery thumbnail clicks.
        document.querySelectorAll('.ca-auction__gallery-thumb').forEach(thumb => {
            thumb.addEventListener('click', () => {
                const container = thumb.closest('.ca-auction__image');
                if (!container) return;
                const mainImg = container.querySelector('.ca-auction__main-img');
                if (mainImg) mainImg.src = thumb.dataset.full;
                container.querySelectorAll('.ca-auction__gallery-thumb').forEach(t => t.classList.remove('active'));
                thumb.classList.add('active');
            });
        });

        // Connect wallet buttons.
        document.querySelectorAll('.ca-btn--connect').forEach(btn => {
            btn.addEventListener('click', () => {
                if (wallet.connected) {
                    disconnectWallet();
                } else {
                    showWalletModal();
                }
            });
        });

        // Place bid buttons.
        document.querySelectorAll('.ca-place-bid').forEach(btn => {
            btn.addEventListener('click', () => {
                if (!wallet.connected) { showWalletModal(); return; }
                const auctionId = btn.dataset.auction;
                const input = document.getElementById(`ca-bid-amount-${auctionId}`);
                const amount = parseFloat(input?.value || 0);
                if (amount < 1) { alert('Minimum bid is 1 ADA.'); return; }
                placeBid(auctionId, amount);
            });
        });
    }

    /* ── Init ─────────────────────────────────────────────────────── */

    async function init() {
        startCountdowns();
        bindEvents();

        // Load bid history for all visible auctions.
        document.querySelectorAll('.ca-auction').forEach(el => loadBidHistory(el));

        // Try to restore wallet connection from previous session.
        await tryAutoReconnect();

        // Auto-refresh active auctions every 30s.
        setInterval(() => {
            document.querySelectorAll('.ca-auction[data-status="active"]').forEach(el => refreshAuction(el));
        }, 30000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
