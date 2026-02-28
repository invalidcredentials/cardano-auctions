/**
 * Cardano Auction Widget — Embeddable, self-contained, zero dependencies.
 *
 * Usage:
 *   <div id="cardano-auction-widget"></div>
 *   <script
 *     src="https://yoursite.com/wp-content/plugins/cardano-auctions/assets/js/ca-widget.min.js"
 *     data-api="https://yoursite.com/wp-json/cah/v1"
 *     data-key="cak_..."
 *     data-auction="42"
 *     data-container="cardano-auction-widget"
 *   ></script>
 */
(function () {
    'use strict';

    /* ── Config from script tag ──────────────────────────────────────── */

    var scriptTag = document.currentScript || (function () {
        var scripts = document.getElementsByTagName('script');
        return scripts[scripts.length - 1];
    })();

    var CFG = {
        api:       scriptTag.getAttribute('data-api') || '',
        key:       scriptTag.getAttribute('data-key') || '',
        auction:   scriptTag.getAttribute('data-auction') || '',
        theme:     scriptTag.getAttribute('data-theme') || 'game-dark',
        container: scriptTag.getAttribute('data-container') || 'cardano-auction-widget',
    };

    if (!CFG.api || !CFG.auction) {
        console.error('[CAW] data-api and data-auction attributes are required.');
        return;
    }

    /* ── Bech32 Encoding (from CIP-30 hex addresses) ─────────────────── */

    function hexToBytes(hex) {
        var bytes = [];
        for (var i = 0; i < hex.length; i += 2) bytes.push(parseInt(hex.substr(i, 2), 16));
        return bytes;
    }

    function to5Bits(bytes) {
        var bits = 0, value = 0, out = [];
        for (var i = 0; i < bytes.length; i++) {
            value = (value << 8) | bytes[i];
            bits += 8;
            while (bits >= 5) { out.push((value >> (bits - 5)) & 31); bits -= 5; }
        }
        if (bits > 0) out.push((value << (5 - bits)) & 31);
        return out;
    }

    function bech32Encode(hrp, data5) {
        var CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
        var GEN = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
        function polymod(values) {
            var chk = 1;
            for (var j = 0; j < values.length; j++) {
                var v = values[j];
                var b = chk >> 25;
                chk = ((chk & 0x1ffffff) << 5) ^ v;
                for (var i = 0; i < 5; i++) if ((b >> i) & 1) chk ^= GEN[i];
            }
            return chk;
        }
        function hrpExpand(h) {
            var out = [];
            for (var i = 0; i < h.length; i++) out.push(h.charCodeAt(i) >> 5);
            out.push(0);
            for (var i = 0; i < h.length; i++) out.push(h.charCodeAt(i) & 31);
            return out;
        }
        var all = hrpExpand(hrp).concat(data5).concat([0, 0, 0, 0, 0, 0]);
        var chk = polymod(all) ^ 1;
        var checksum = [];
        for (var p = 0; p < 6; p++) checksum.push((chk >> (5 * (5 - p))) & 31);
        var encoded = hrp + '1';
        var full = data5.concat(checksum);
        for (var i = 0; i < full.length; i++) encoded += CHARSET[full[i]];
        return encoded;
    }

    function hexToBech32(hex) {
        var bytes = hexToBytes(hex);
        if (!bytes.length) return hex;
        var networkId = bytes[0] & 0x0F;
        var hrp = networkId === 1 ? 'addr' : 'addr_test';
        return bech32Encode(hrp, to5Bits(bytes));
    }

    /* ── REST Client ─────────────────────────────────────────────────── */

    function apiGet(endpoint) {
        return fetch(CFG.api + endpoint, {
            headers: { 'X-CA-Api-Key': CFG.key },
        }).then(function (r) { return r.json(); });
    }

    function apiPost(endpoint, data) {
        return fetch(CFG.api + endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CA-Api-Key': CFG.key,
            },
            body: JSON.stringify(data),
        }).then(function (r) { return r.json(); });
    }

    /* ── Known Wallets ───────────────────────────────────────────────── */

    var KNOWN_WALLETS = ['nami', 'eternl', 'lace', 'flint', 'typhoncip30', 'gerowallet', 'nufi', 'begin', 'vespr', 'yoroi'];

    function getInstalledWallets() {
        if (!window.cardano) return [];
        return KNOWN_WALLETS.filter(function (k) { return window.cardano[k]; });
    }

    /* ── CSS ──────────────────────────────────────────────────────────── */

    var WIDGET_CSS = (function () { return [

/* Custom property defaults — set on the host element, pierce Shadow DOM */
':host {',
'  --caw-accent:     #00e5ff;',
'  --caw-bg:         #0a0e1a;',
'  --caw-text:       #e6edf3;',
'  --caw-success:    #00c853;',
'  --caw-error:      #ff3860;',
'  --caw-font:       -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;',
'  --caw-font-title: "Trebuchet MS", "Gill Sans", "Century Gothic", Calibri, sans-serif;',
'  --caw-font-mono:  Consolas, "Courier New", monospace;',
'  --caw-radius:     12px;',
'  --caw-padding:    28px 32px;',
'}',

/* Reset + Root */
':host { all: initial; display: block; font-family: var(--caw-font); }',
'.caw-root { background: var(--caw-bg); color: var(--caw-text); border-radius: var(--caw-radius); padding: var(--caw-padding); box-sizing: border-box; }',
'.caw-root *, .caw-root *::before, .caw-root *::after { box-sizing: border-box; margin: 0; padding: 0; }',

/* ── Two-column layout ─────────────────────────────────── */
'.caw-layout { display: flex; gap: 36px; }',
'.caw-col-image { flex: 0 0 45%; max-width: 45%; }',
'.caw-col-details { flex: 1; min-width: 0; }',
'.caw-details-top { position: relative; padding-left: 18px; margin-bottom: 18px; }',
'.caw-details-top::before { content: ""; position: absolute; left: 0; top: 0; bottom: 0; width: 1px; background: linear-gradient(to bottom, color-mix(in srgb, var(--caw-accent) 50%, transparent), color-mix(in srgb, var(--caw-accent) 15%, transparent) 60%, transparent); }',
'@media (max-width: 680px) {',
'  .caw-layout { flex-direction: column; }',
'  .caw-col-image { flex: none; max-width: 100%; }',
'  .caw-col-details { border-top: none; padding-top: 0; }',
'}',

/* ── NFT Image ─────────────────────────────────────────── */
'.caw-image-main { width: 100%; height: auto; border-radius: 8px; display: block; }',
'.caw-gallery-thumbs { display: flex; gap: 8px; margin-top: 12px; overflow-x: auto; padding-bottom: 4px; position: relative; }',
'.caw-gallery-thumb { width: 64px; height: 64px; object-fit: cover; border-radius: 6px; cursor: pointer; border: 2px solid rgba(255,255,255,0.1); opacity: 0.5; transition: opacity 0.2s, border-color 0.2s; flex-shrink: 0; }',
'.caw-gallery-thumb:hover, .caw-gallery-thumb.active { opacity: 1; border-color: var(--caw-accent); box-shadow: 0 0 8px color-mix(in srgb, var(--caw-accent) 30%, transparent); }',
'.caw-gallery-thumb.active { position: relative; }',
'.caw-gallery-label { position: absolute; top: 2px; left: 2px; background: var(--caw-accent); color: var(--caw-bg); font-size: 8px; font-weight: 800; padding: 1px 4px; border-radius: 2px; text-transform: uppercase; letter-spacing: 0.5px; pointer-events: none; }',
'.caw-gallery-nav { position: absolute; top: 50%; transform: translateY(-50%); background: color-mix(in srgb, var(--caw-bg) 85%, transparent); color: var(--caw-accent); border: 1px solid color-mix(in srgb, var(--caw-accent) 30%, transparent); border-radius: 50%; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 16px; z-index: 2; transition: all 0.2s; }',
'.caw-gallery-nav:hover { background: color-mix(in srgb, var(--caw-accent) 15%, transparent); box-shadow: 0 0 10px color-mix(in srgb, var(--caw-accent) 30%, transparent); }',
'.caw-gallery-nav--left { left: -6px; }',
'.caw-gallery-nav--right { right: -6px; }',
'.caw-gallery-wrap { position: relative; }',

/* ── Header row: Title + badges ─────────────────────────── */
'.caw-header { display: flex; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 4px; }',
'.caw-title { font-size: 32px; font-weight: 700; color: var(--caw-title-color, #fff); line-height: 1.15; font-family: var(--caw-font-title); letter-spacing: 0.5px; }',

'.caw-badge { display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; padding: 5px 14px; border-radius: 20px; white-space: nowrap; }',
'.caw-badge--live { border: 1px solid var(--caw-error); color: var(--caw-error); background: linear-gradient(135deg, color-mix(in srgb, var(--caw-error) 12%, transparent), color-mix(in srgb, var(--caw-error) 4%, transparent)); }',
'.caw-badge--live::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: var(--caw-error); flex-shrink: 0; animation: caw-pulse 1.5s infinite; }',
'.caw-badge--type { border: 1px solid var(--caw-accent); color: var(--caw-accent); margin-left: auto; font-size: 10px; letter-spacing: 1.5px; padding: 5px 16px; background: linear-gradient(135deg, color-mix(in srgb, var(--caw-accent) 10%, transparent), color-mix(in srgb, var(--caw-accent) 3%, transparent)); }',
'.caw-badge--ended { border: 1px solid rgba(255,255,255,0.25); color: rgba(255,255,255,0.5); }',
'@keyframes caw-pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.3; } }',

/* ── Seller / creator line ─────────────────────────────── */
'.caw-seller { font-size: 13px; color: rgba(255,255,255,0.45); margin-bottom: 14px; display: flex; align-items: center; gap: 0; }',
'.caw-seller-sep { margin: 0 10px; font-size: 8px; opacity: 0.6; }',
'.caw-seller-name { color: var(--caw-accent); font-weight: 600; text-shadow: 0 0 6px color-mix(in srgb, var(--caw-accent) 30%, transparent); }',

/* ── Description ────────────────────────────────────────── */
'.caw-desc { font-size: 13.5px; line-height: 1.6; color: rgba(255,255,255,0.65); margin-bottom: 20px; }',

/* ── Card panels (Time Remaining, Current Bid, Recent Bids) */
'.caw-card { position: relative; border: 1px solid color-mix(in srgb, var(--caw-accent) 15%, transparent); border-radius: 6px; padding: 16px 18px; margin-bottom: 14px; background: color-mix(in srgb, var(--caw-accent) 2%, transparent); }',
/* corner dots */
'.caw-card::before, .caw-card::after { content: ""; position: absolute; width: 4px; height: 4px; border-radius: 50%; background: color-mix(in srgb, var(--caw-accent) 50%, transparent); }',
'.caw-card::before { top: -2px; right: -2px; }',
'.caw-card::after { bottom: -2px; left: -2px; }',
'.caw-card-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; color: rgba(255,255,255,0.4); margin-bottom: 8px; }',

/* ── Current Bid card — gradient variant ───────────────── */
'.caw-card--bid { background: linear-gradient(180deg, color-mix(in srgb, var(--caw-accent) 7%, transparent) 0%, color-mix(in srgb, var(--caw-accent) 1.5%, transparent) 100%); border-color: color-mix(in srgb, var(--caw-accent) 20%, transparent); }',

/* ── Time Remaining ─────────────────────────────────────── */
'.caw-time { display: flex; align-items: baseline; gap: 2px; }',
'.caw-time-num { font-size: 28px; font-weight: 700; color: var(--caw-title-color, #fff); font-family: var(--caw-font-mono); }',
'.caw-time-unit { font-size: 12px; font-weight: 400; color: rgba(255,255,255,0.3); margin-right: 4px; }',
'.caw-time-sep { font-size: 20px; color: rgba(255,255,255,0.15); margin: 0 4px; font-family: var(--caw-font-mono); }',

/* ── Current Bid card ───────────────────────────────────── */
'.caw-bid-row { display: flex; align-items: flex-start; justify-content: space-between; }',
'.caw-bid-count { font-size: 12px; color: rgba(255,255,255,0.4); }',
'.caw-bid-amount { font-size: 52px; font-weight: 800; color: var(--caw-accent); font-family: var(--caw-font-mono); line-height: 1.1; letter-spacing: -1px; text-shadow: 0 0 20px color-mix(in srgb, var(--caw-accent) 40%, transparent), 0 0 40px color-mix(in srgb, var(--caw-accent) 15%, transparent); }',
'.caw-bid-ada { font-size: 20px; font-weight: 400; color: rgba(255,255,255,0.4); margin-left: 8px; letter-spacing: 3px; vertical-align: baseline; font-family: var(--caw-font-mono); }',
'.caw-high-bidder { display: flex; align-items: center; gap: 6px; font-size: 13px; color: rgba(255,255,255,0.5); margin-top: 8px; }',
'.caw-high-bidder-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--caw-success); flex-shrink: 0; box-shadow: 0 0 6px color-mix(in srgb, var(--caw-success) 50%, transparent); }',
'.caw-high-bidder-name { color: var(--caw-accent); font-weight: 600; text-decoration: underline; text-underline-offset: 2px; text-shadow: 0 0 6px color-mix(in srgb, var(--caw-accent) 30%, transparent); }',

/* ── Place Bid button ───────────────────────────────────── */
'.caw-btn-place { display: block; width: 100%; padding: 16px; border: none; border-radius: 6px; font-size: 16px; font-weight: 800; letter-spacing: 3px; text-transform: uppercase; cursor: pointer; transition: all 0.2s; text-align: center; margin-bottom: 14px; font-family: var(--caw-font-mono); }',
'.caw-btn-place--bid { background: var(--caw-accent); color: var(--caw-bg); box-shadow: 0 0 20px color-mix(in srgb, var(--caw-accent) 25%, transparent); }',
'.caw-btn-place--bid:hover { background: color-mix(in srgb, var(--caw-accent) 80%, #fff); box-shadow: 0 0 30px color-mix(in srgb, var(--caw-accent) 45%, transparent), 0 0 60px color-mix(in srgb, var(--caw-accent) 15%, transparent); }',
'.caw-btn-place--connect { background: transparent; border: 2px solid var(--caw-accent); color: var(--caw-accent); }',
'.caw-btn-place--connect:hover { background: color-mix(in srgb, var(--caw-accent) 8%, transparent); box-shadow: 0 0 15px color-mix(in srgb, var(--caw-accent) 20%, transparent); }',
'.caw-btn-place--connected { background: color-mix(in srgb, var(--caw-accent) 6%, transparent); border: 1px solid color-mix(in srgb, var(--caw-accent) 25%, transparent); color: var(--caw-accent); font-size: 12px; font-weight: 600; letter-spacing: 0; text-transform: none; padding: 10px 16px; text-align: left; margin-bottom: 10px; font-family: var(--caw-font); }',
'.caw-btn-place:disabled { opacity: 0.4; cursor: not-allowed; }',

/* ── Bid input (shown after clicking Place Bid) ─────────── */
'.caw-bid-input-wrap { margin-bottom: 14px; }',
'.caw-bid-input-row { display: flex; gap: 8px; align-items: center; }',
'.caw-bid-field { flex: 1; background: rgba(255,255,255,0.03); border: 1px solid color-mix(in srgb, var(--caw-accent) 20%, transparent); border-radius: 6px; padding: 12px 14px; font-size: 18px; font-family: var(--caw-font-mono); font-weight: 700; color: var(--caw-accent); outline: none; }',
'.caw-bid-field:focus { border-color: var(--caw-accent); box-shadow: 0 0 0 2px color-mix(in srgb, var(--caw-accent) 15%, transparent), 0 0 15px color-mix(in srgb, var(--caw-accent) 10%, transparent); }',
'.caw-bid-field::placeholder { color: rgba(255,255,255,0.2); }',
'.caw-bid-suffix { font-size: 14px; color: rgba(255,255,255,0.4); font-weight: 600; }',
'.caw-bid-min { font-size: 11px; color: rgba(255,255,255,0.3); margin-top: 6px; }',
'.caw-btn-confirm { display: block; width: 100%; padding: 14px; border: none; border-radius: 6px; background: var(--caw-accent); color: var(--caw-bg); font-size: 15px; font-weight: 800; letter-spacing: 1.5px; text-transform: uppercase; cursor: pointer; transition: all 0.2s; margin-top: 8px; font-family: var(--caw-font-mono); box-shadow: 0 0 15px color-mix(in srgb, var(--caw-accent) 20%, transparent); }',
'.caw-btn-confirm:hover { background: color-mix(in srgb, var(--caw-accent) 80%, #fff); box-shadow: 0 0 25px color-mix(in srgb, var(--caw-accent) 35%, transparent); }',
'.caw-btn-confirm:disabled { opacity: 0.4; cursor: not-allowed; }',

/* ── Recent Bids ────────────────────────────────────────── */
'.caw-bids-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }',
'.caw-bids-title { font-size: 15px; font-weight: 700; color: var(--caw-title-color, #fff); }',
'.caw-bids-view-all { font-size: 12px; color: var(--caw-accent); cursor: pointer; font-weight: 600; text-shadow: 0 0 6px color-mix(in srgb, var(--caw-accent) 30%, transparent); }',
'.caw-bids-view-all:hover { text-decoration: underline; }',
'.caw-bid-entry { display: flex; align-items: center; padding: 12px 0; border-bottom: 1px solid color-mix(in srgb, var(--caw-accent) 8%, transparent); }',
'.caw-bid-entry:last-child { border-bottom: none; }',
'.caw-bid-avatar { width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, color-mix(in srgb, var(--caw-accent) 15%, transparent) 0%, color-mix(in srgb, var(--caw-accent) 5%, transparent) 100%); flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; color: var(--caw-accent); margin-right: 12px; border: 1px solid color-mix(in srgb, var(--caw-accent) 12%, transparent); }',
'.caw-bid-meta { flex: 1; min-width: 0; }',
'.caw-bid-bidder { font-size: 13px; font-weight: 700; color: var(--caw-accent); text-decoration: underline; text-underline-offset: 2px; }',
'.caw-bid-time { font-size: 11px; color: rgba(255,255,255,0.3); margin-top: 2px; }',
'.caw-bid-numbers { text-align: right; flex-shrink: 0; display: flex; align-items: center; gap: 10px; }',
'.caw-bid-incr { font-size: 12px; font-weight: 700; color: var(--caw-success); }',
'.caw-bid-total { font-size: 14px; font-weight: 700; color: var(--caw-title-color, #fff); font-family: var(--caw-font-mono); }',
'.caw-bids-empty { text-align: center; padding: 20px 0; color: rgba(255,255,255,0.3); font-size: 13px; }',

/* ── Status messages ────────────────────────────────────── */
'.caw-status { text-align: center; padding: 12px 16px; margin-bottom: 12px; border-radius: 6px; font-size: 13px; font-weight: 600; }',
'.caw-status--info { background: color-mix(in srgb, var(--caw-accent) 8%, transparent); color: var(--caw-accent); border: 1px solid color-mix(in srgb, var(--caw-accent) 15%, transparent); }',
'.caw-status--success { background: color-mix(in srgb, var(--caw-success) 8%, transparent); color: var(--caw-success); border: 1px solid color-mix(in srgb, var(--caw-success) 15%, transparent); }',
'.caw-status--error { background: color-mix(in srgb, var(--caw-error) 8%, transparent); color: var(--caw-error); border: 1px solid color-mix(in srgb, var(--caw-error) 15%, transparent); }',
'.caw-status--winning { background: color-mix(in srgb, var(--caw-accent) 6%, transparent); border: 1px solid color-mix(in srgb, var(--caw-accent) 20%, transparent); color: var(--caw-accent); }',

/* ── Wallet Modal ───────────────────────────────────────── */
'.caw-modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.75); display: flex; align-items: center; justify-content: center; z-index: 999999; }',
'.caw-modal { background: var(--caw-modal-bg, #0d1117); border: 1px solid color-mix(in srgb, var(--caw-accent) 15%, transparent); border-radius: var(--caw-radius); padding: 24px; min-width: 300px; max-width: 380px; }',
'.caw-modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }',
'.caw-modal-header h3 { font-size: 16px; color: var(--caw-title-color, #fff); font-weight: 700; }',
'.caw-modal-close { background: none; border: none; color: rgba(255,255,255,0.5); font-size: 24px; cursor: pointer; padding: 0; line-height: 1; }',
'.caw-modal-close:hover { color: #fff; }',
'.caw-wallet-opt { display: flex; align-items: center; gap: 12px; width: 100%; padding: 12px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.06); border-radius: 8px; color: var(--caw-text); font-size: 14px; cursor: pointer; margin-bottom: 8px; transition: all 0.2s; }',
'.caw-wallet-opt:hover { background: color-mix(in srgb, var(--caw-accent) 6%, transparent); border-color: color-mix(in srgb, var(--caw-accent) 25%, transparent); }',
'.caw-wallet-opt img { width: 28px; height: 28px; border-radius: 6px; }',

/* ── Loading ────────────────────────────────────────────── */
'.caw-loading { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 60px 0; color: rgba(255,255,255,0.4); gap: 12px; }',
'.caw-spinner { width: 28px; height: 28px; border: 3px solid rgba(255,255,255,0.08); border-top-color: var(--caw-accent); border-radius: 50%; animation: caw-spin 0.8s linear infinite; }',
'@keyframes caw-spin { to { transform: rotate(360deg); } }',

/* ── Footer ─────────────────────────────────────────────── */
'.caw-footer { text-align: center; margin-top: 20px; padding-top: 14px; border-top: 1px solid rgba(255,255,255,0.05); font-size: 10px; color: rgba(255,255,255,0.18); }',
'.caw-footer a { color: rgba(255,255,255,0.25); text-decoration: none; }',
'.caw-footer a:hover { color: rgba(255,255,255,0.45); }',

    ].join('\n'); })();

    /* ── Widget Class ────────────────────────────────────────────────── */

    function CardanoAuctionWidget(containerEl) {
        this.container = containerEl;
        this.shadow = containerEl.attachShadow({ mode: 'open' });
        this.root = null;
        this.auction = null;
        this.bids = [];
        this.wallet = { connected: false, address: '', addressHex: '', handler: null, key: '', name: '' };
        this.countdownInterval = null;
        this.refreshInterval = null;
        this.pollInterval = null;
        this.bidInputVisible = false;
        this.destroyed = false;
    }

    /* ── Events ──────────────────────────────────────────────────── */

    CardanoAuctionWidget._listeners = {};

    CardanoAuctionWidget.on = function (event, callback) {
        if (!this._listeners[event]) this._listeners[event] = [];
        this._listeners[event].push(callback);
    };

    CardanoAuctionWidget.off = function (event, callback) {
        if (!this._listeners[event]) return;
        this._listeners[event] = this._listeners[event].filter(function (fn) { return fn !== callback; });
    };

    CardanoAuctionWidget._emit = function (event, data) {
        var fns = this._listeners[event] || [];
        for (var i = 0; i < fns.length; i++) {
            try { fns[i](data); } catch (e) { console.error('[CAW] Event handler error:', e); }
        }
    };

    /* ── Init ────────────────────────────────────────────────────── */

    CardanoAuctionWidget.prototype.init = function () {
        var self = this;

        // Inject styles.
        var style = document.createElement('style');
        style.textContent = WIDGET_CSS;
        this.shadow.appendChild(style);

        // Create root.
        this.root = document.createElement('div');
        this.root.className = 'caw-root';
        this.shadow.appendChild(this.root);

        // Show loading.
        this.root.innerHTML = '<div class="caw-loading"><div class="caw-spinner"></div><div>Loading auction...</div></div>';

        // Fetch auction + bids.
        Promise.all([
            apiGet('/auctions/' + CFG.auction),
            apiGet('/auctions/' + CFG.auction + '/bids'),
        ]).then(function (results) {
            if (self.destroyed) return;
            self.auction = results[0];
            self.bids = (results[1] && results[1].bids) ? results[1].bids : [];

            if (self.auction.error) {
                self.root.innerHTML = '<div class="caw-status caw-status--error">Auction not found.</div>';
                return;
            }

            self.render();
            self.startCountdown();
            self.startAutoRefresh();
        }).catch(function (err) {
            console.error('[CAW] Failed to load auction:', err);
            if (!self.destroyed) {
                self.root.innerHTML = '<div class="caw-status caw-status--error">Failed to load auction data.</div>';
            }
        });
    };

    /* ── Render ──────────────────────────────────────────────────── */

    CardanoAuctionWidget.prototype.render = function () {
        var a = this.auction;
        if (!a) return;

        var isActive = a.status === 'active';
        var currentBid = a.current_bid_ada || a.starting_bid_ada;
        var bidLabel = a.current_bid_ada ? 'CURRENT BID' : 'STARTING BID';
        var formattedBid = formatNumber(currentBid);

        var html = '<div class="caw-layout">';

        /* ── Left column: Image Gallery ────────────────────── */
        var gallery = a.gallery_images && a.gallery_images.length ? a.gallery_images : [];
        if (!gallery.length) {
            var fallback = a.image_url || a.nft_image_url || '';
            if (fallback) gallery = [fallback];
        }
        html += '<div class="caw-col-image">';
        if (gallery.length) {
            html += '<img class="caw-image-main" src="' + escAttr(gallery[0]) + '" alt="' + escAttr(a.nft_display_name || '') + '">';
            if (gallery.length > 1) {
                html += '<div class="caw-gallery-wrap">';
                if (gallery.length > 4) {
                    html += '<div class="caw-gallery-nav caw-gallery-nav--left" data-dir="-1">&#8249;</div>';
                }
                html += '<div class="caw-gallery-thumbs">';
                for (var gi = 0; gi < gallery.length; gi++) {
                    html += '<span class="caw-gallery-thumb' + (gi === 0 ? ' active' : '') + '" style="position:relative;display:inline-block;flex-shrink:0">';
                    html += '<img src="' + escAttr(gallery[gi]) + '" data-idx="' + gi + '" style="width:64px;height:64px;object-fit:cover;border-radius:6px;display:block">';
                    if (gi === 0) html += '<span class="caw-gallery-label">MAIN</span>';
                    html += '</span>';
                }
                html += '</div>';
                if (gallery.length > 4) {
                    html += '<div class="caw-gallery-nav caw-gallery-nav--right" data-dir="1">&#8250;</div>';
                }
                html += '</div>';
            }
        }
        html += '</div>';

        /* ── Right column: Details ──────────────────────────── */
        html += '<div class="caw-col-details">';
        html += '<div class="caw-details-top">';

        /* Title + badges */
        html += '<div class="caw-header">';
        html += '<span class="caw-title">' + escHtml(a.title || a.nft_display_name || 'Auction #' + a.id) + '</span>';
        if (isActive) {
            html += '<span class="caw-badge caw-badge--live">LIVE</span>';
        } else {
            html += '<span class="caw-badge caw-badge--ended">' + escHtml(a.status.toUpperCase()) + '</span>';
        }
        html += '<span class="caw-badge caw-badge--type">PUBLIC AUCTION</span>';
        html += '</div>';

        /* Seller / artist line */
        html += '<div class="caw-seller">';
        if (a.artist_name) {
            html += '<span class="caw-seller-name">' + escHtml(a.artist_name) + '</span>';
            if (a.listed_by) {
                html += '<span class="caw-seller-sep">&#9679;</span>Listed by ' + escHtml(a.listed_by);
            }
        } else if (a.listed_by) {
            html += 'Listed by <span class="caw-seller-name">' + escHtml(a.listed_by) + '</span>';
        } else {
            html += '<span class="caw-seller-name">' + escHtml(a.network || 'Cardano') + '</span>';
            if (a.nft_policy_id) {
                html += '<span class="caw-seller-sep">&#9679;</span>Policy ' + escHtml(a.nft_policy_id.substring(0, 12)) + '...';
            }
        }
        html += '</div>';

        /* Description */
        if (a.description) {
            html += '<div class="caw-desc">' + escHtml(a.description) + '</div>';
        }
        html += '</div>'; /* end caw-details-top */

        /* Time Remaining card */
        html += '<div class="caw-card">';
        html += '<div class="caw-card-label">TIME REMAINING</div>';
        html += '<div class="caw-time" id="caw-countdown">' + this.renderCountdownHTML() + '</div>';
        html += '</div>';

        /* Current Bid card */
        html += '<div class="caw-card caw-card--bid">';
        html += '<div class="caw-bid-row">';
        html += '<div class="caw-card-label">' + bidLabel + '</div>';
        html += '<div class="caw-bid-count">' + a.total_bids + ' bid' + (a.total_bids !== 1 ? 's' : '') + '</div>';
        html += '</div>';
        html += '<div><span class="caw-bid-amount" id="caw-bid-value">' + formattedBid + '</span><span class="caw-bid-ada">ADA</span></div>';
        if (a.current_bidder) {
            html += '<div class="caw-high-bidder"><span class="caw-high-bidder-dot"></span>High bidder: <span class="caw-high-bidder-name">' + escHtml(a.current_bidder) + '</span></div>';
        }
        html += '</div>';

        /* Status area (hidden initially) */
        html += '<div class="caw-status caw-status--info" id="caw-status" style="display:none"></div>';

        /* Bidder banner */
        html += '<div id="caw-bidder-banner"></div>';

        /* Wallet + Bid section */
        if (isActive) {
            if (this.wallet.connected) {
                html += '<button class="caw-btn-place caw-btn-place--connected" id="caw-wallet-btn">';
                html += escHtml(this.wallet.name) + ': ' + this.wallet.address.substring(0, 14) + '...' + this.wallet.address.slice(-8);
                html += '</button>';

                /* Bid input area */
                html += '<div id="caw-bid-form">';
                if (this.bidInputVisible) {
                    html += this.renderBidInputHTML();
                } else {
                    html += '<button class="caw-btn-place caw-btn-place--bid" id="caw-place-bid-btn">PLACE BID</button>';
                }
                html += '</div>';
            } else {
                html += '<button class="caw-btn-place caw-btn-place--connect" id="caw-wallet-btn">CONNECT WALLET</button>';
                html += '<button class="caw-btn-place caw-btn-place--bid" id="caw-place-bid-btn">PLACE BID</button>';
            }
        }

        /* Recent Bids card */
        html += '<div class="caw-card">';
        html += '<div class="caw-bids-header">';
        html += '<span class="caw-bids-title">Recent Bids</span>';
        html += '<span class="caw-bids-view-all">View All</span>';
        html += '</div>';
        html += '<div id="caw-history-list">' + this.renderHistoryHTML() + '</div>';
        html += '</div>';

        html += '</div>'; /* end col-details */
        html += '</div>'; /* end layout */

        /* Footer */
        html += '<div class="caw-footer">Powered by <a href="https://cardanowordpressplugins.com" target="_blank" rel="noopener">Cardano Auctions</a></div>';

        this.root.innerHTML = html;
        this.bindWidgetEvents();
    };

    /* ── Bid Input HTML ──────────────────────────────────────────── */

    CardanoAuctionWidget.prototype.renderBidInputHTML = function () {
        var a = this.auction;
        var minBid = a.current_bid_lovelace > 0
            ? (a.current_bid_lovelace / 1000000) + parseFloat(a.min_increment_ada)
            : parseFloat(a.starting_bid_ada);

        var html = '<div class="caw-bid-input-wrap">';
        html += '<div class="caw-bid-input-row">';
        html += '<input type="number" class="caw-bid-field" id="caw-bid-amount" value="' + minBid.toFixed(2) + '" min="' + minBid.toFixed(2) + '" step="0.01" placeholder="' + minBid.toFixed(2) + '">';
        html += '<span class="caw-bid-suffix">ADA</span>';
        html += '</div>';
        html += '<div class="caw-bid-min">Minimum: ' + minBid.toFixed(2) + ' ADA (increment: ' + escHtml(a.min_increment_ada) + ' ADA)</div>';
        html += '<button class="caw-btn-confirm" id="caw-confirm-bid">CONFIRM BID</button>';
        html += '</div>';
        return html;
    };

    /* ── Countdown ───────────────────────────────────────────────── */

    CardanoAuctionWidget.prototype.renderCountdownHTML = function () {
        var a = this.auction;
        if (!a || !a.end_time) return '';

        var end = new Date(a.end_time + 'Z').getTime();
        var diff = end - Date.now();
        if (diff <= 0) {
            return '<span class="caw-time-num">0</span><span class="caw-time-unit">d</span>' +
                   '<span class="caw-time-sep">:</span>' +
                   '<span class="caw-time-num">00</span><span class="caw-time-unit">h</span>' +
                   '<span class="caw-time-sep">:</span>' +
                   '<span class="caw-time-num">00</span><span class="caw-time-unit">m</span>';
        }

        var d = Math.floor(diff / 86400000);
        var h = Math.floor((diff % 86400000) / 3600000);
        var m = Math.floor((diff % 3600000) / 60000);
        var s = Math.floor((diff % 60000) / 1000);

        var out = '';
        if (d > 0) {
            out += '<span class="caw-time-num">' + d + '</span><span class="caw-time-unit">d</span>';
            out += '<span class="caw-time-sep">:</span>';
        }
        out += '<span class="caw-time-num">' + pad2(h) + '</span><span class="caw-time-unit">h</span>';
        out += '<span class="caw-time-sep">:</span>';
        out += '<span class="caw-time-num">' + pad2(m) + '</span><span class="caw-time-unit">m</span>';
        if (d === 0) {
            out += '<span class="caw-time-sep">:</span>';
            out += '<span class="caw-time-num">' + pad2(s) + '</span><span class="caw-time-unit">s</span>';
        }
        return out;
    };

    CardanoAuctionWidget.prototype.startCountdown = function () {
        var self = this;
        if (this.countdownInterval) clearInterval(this.countdownInterval);
        this.countdownInterval = setInterval(function () {
            if (self.destroyed) return;
            var el = self.shadow.getElementById('caw-countdown');
            if (el) el.innerHTML = self.renderCountdownHTML();

            if (self.auction && self.auction.end_time) {
                var end = new Date(self.auction.end_time + 'Z').getTime();
                if (Date.now() >= end) {
                    clearInterval(self.countdownInterval);
                    CardanoAuctionWidget._emit('onAuctionEnded', { auction_id: self.auction.id });
                }
            }
        }, 1000);
    };

    /* ── Bid History ─────────────────────────────────────────────── */

    CardanoAuctionWidget.prototype.renderHistoryHTML = function () {
        if (!this.bids || this.bids.length === 0) {
            return '<div class="caw-bids-empty">No bids yet. Be the first!</div>';
        }
        var html = '';
        var shown = this.bids.slice(0, 6);
        for (var i = 0; i < shown.length; i++) {
            var b = shown[i];
            var initials = b.bidder ? b.bidder.substring(0, 2).toUpperCase() : '??';
            var increment = '';
            if (i < shown.length - 1) {
                var diff = parseFloat(b.bid_ada) - parseFloat(shown[i + 1].bid_ada);
                if (diff > 0) increment = '+' + formatNumber(diff.toFixed(0));
            }
            var timeAgo = b.created_at ? getTimeAgo(b.created_at) : '';

            html += '<div class="caw-bid-entry">';
            html += '<div class="caw-bid-avatar">' + escHtml(initials) + '</div>';
            html += '<div class="caw-bid-meta">';
            html += '<div class="caw-bid-bidder">' + escHtml(b.bidder) + '</div>';
            if (timeAgo) html += '<div class="caw-bid-time">' + escHtml(timeAgo) + '</div>';
            html += '</div>';
            html += '<div class="caw-bid-numbers">';
            if (increment) html += '<span class="caw-bid-incr">' + escHtml(increment) + '</span>';
            html += '<span class="caw-bid-total">' + formatNumber(b.bid_ada) + ' ADA</span>';
            html += '</div>';
            html += '</div>';
        }
        return html;
    };

    /* ── Event Binding ───────────────────────────────────────────── */

    CardanoAuctionWidget.prototype.bindWidgetEvents = function () {
        var self = this;

        // Gallery thumbnail clicks.
        var thumbs = this.shadow.querySelectorAll('.caw-gallery-thumb');
        for (var ti = 0; ti < thumbs.length; ti++) {
            thumbs[ti].addEventListener('click', function () {
                var img = this.querySelector('img');
                if (!img) return;
                var mainImg = self.shadow.querySelector('.caw-image-main');
                if (mainImg) mainImg.src = img.src;
                var allThumbs = self.shadow.querySelectorAll('.caw-gallery-thumb');
                for (var tj = 0; tj < allThumbs.length; tj++) allThumbs[tj].classList.remove('active');
                this.classList.add('active');
            });
        }

        // Gallery navigation arrows.
        var navBtns = this.shadow.querySelectorAll('.caw-gallery-nav');
        for (var ni = 0; ni < navBtns.length; ni++) {
            navBtns[ni].addEventListener('click', function () {
                var strip = self.shadow.querySelector('.caw-gallery-thumbs');
                if (strip) {
                    var dir = parseInt(this.getAttribute('data-dir'), 10);
                    strip.scrollBy({ left: dir * 120, behavior: 'smooth' });
                }
            });
        }

        // Wallet button.
        var walletBtn = this.shadow.getElementById('caw-wallet-btn');
        if (walletBtn) {
            walletBtn.addEventListener('click', function () {
                if (self.wallet.connected) {
                    self.disconnectWallet();
                } else {
                    self.showWalletModal();
                }
            });
        }

        // Place Bid button — reveals input or opens wallet modal.
        var placeBtn = this.shadow.getElementById('caw-place-bid-btn');
        if (placeBtn) {
            placeBtn.addEventListener('click', function () {
                if (!self.wallet.connected) {
                    self.showWalletModal();
                    return;
                }
                self.bidInputVisible = true;
                var bidForm = self.shadow.getElementById('caw-bid-form');
                if (bidForm) {
                    bidForm.innerHTML = self.renderBidInputHTML();
                    self.bindConfirmBtn();
                    var input = self.shadow.getElementById('caw-bid-amount');
                    if (input) input.focus();
                }
            });
        }

        // Confirm bid button (if input already visible after render).
        this.bindConfirmBtn();
    };

    CardanoAuctionWidget.prototype.bindConfirmBtn = function () {
        var self = this;
        var confirmBtn = this.shadow.getElementById('caw-confirm-bid');
        if (confirmBtn) {
            confirmBtn.addEventListener('click', function () {
                var input = self.shadow.getElementById('caw-bid-amount');
                var amount = input ? parseFloat(input.value) : 0;
                if (amount < 1) {
                    self.showStatus('Minimum bid is 1 ADA.', 'error');
                    return;
                }
                self.placeBid(amount);
            });
        }
    };

    /* ── Wallet Connect ──────────────────────────────────────────── */

    CardanoAuctionWidget.prototype.showWalletModal = function () {
        var self = this;
        var installed = getInstalledWallets();

        if (installed.length === 0) {
            this.showStatus('No Cardano wallets detected. Install Nami, Eternl, or Lace.', 'error');
            return;
        }

        var overlay = document.createElement('div');
        overlay.className = 'caw-modal-overlay';

        var modal = document.createElement('div');
        modal.className = 'caw-modal';

        var header = '<div class="caw-modal-header"><h3>Connect Wallet</h3><button class="caw-modal-close">&times;</button></div>';
        var options = '';
        for (var i = 0; i < installed.length; i++) {
            var key = installed[i];
            var walletInfo = window.cardano[key];
            var icon = walletInfo && walletInfo.icon ? walletInfo.icon : '';
            var name = (walletInfo && walletInfo.name) || key;
            options += '<button class="caw-wallet-opt" data-wallet="' + escAttr(key) + '">';
            if (icon) options += '<img src="' + escAttr(icon) + '" alt="">';
            options += '<span>' + escHtml(name) + '</span>';
            options += '</button>';
        }

        modal.innerHTML = header + options;
        overlay.appendChild(modal);
        this.shadow.appendChild(overlay);

        overlay.querySelector('.caw-modal-close').addEventListener('click', function () { overlay.remove(); });
        overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });

        var walletBtns = overlay.querySelectorAll('.caw-wallet-opt');
        for (var j = 0; j < walletBtns.length; j++) {
            walletBtns[j].addEventListener('click', function () {
                var walletKey = this.getAttribute('data-wallet');
                this.innerHTML = '<span>Connecting...</span>';
                self.connectWallet(walletKey).then(function () {
                    overlay.remove();
                }).catch(function () {
                    overlay.remove();
                });
            });
        }
    };

    CardanoAuctionWidget.prototype.connectWallet = function (key) {
        var self = this;
        return window.cardano[key].enable().then(function (api) {
            self.wallet.handler = api;
            self.wallet.key = key;
            self.wallet.name = window.cardano[key].name || key;

            return api.getUsedAddresses().then(function (addresses) {
                var addr = addresses[0];
                if (!addr) {
                    return api.getUnusedAddresses().then(function (unused) {
                        return unused[0] || '';
                    });
                }
                return addr;
            });
        }).then(function (addrHex) {
            if (!addrHex) throw new Error('No address found');
            self.wallet.addressHex = addrHex;
            self.wallet.address = hexToBech32(addrHex);
            self.wallet.connected = true;

            CardanoAuctionWidget._emit('onWalletConnected', {
                address: self.wallet.address,
                wallet: self.wallet.name,
            });

            self.render();
            self.checkBidderStatus();
        }).catch(function (err) {
            console.error('[CAW] Wallet connect failed:', err);
            self.showStatus('Wallet connection failed.', 'error');
        });
    };

    CardanoAuctionWidget.prototype.disconnectWallet = function () {
        this.wallet = { connected: false, address: '', addressHex: '', handler: null, key: '', name: '' };
        this.bidInputVisible = false;
        this.render();
    };

    /* ── Bidder Status ───────────────────────────────────────────── */

    CardanoAuctionWidget.prototype.checkBidderStatus = function () {
        var self = this;
        if (!this.wallet.connected || !this.auction) return;

        apiGet('/auctions/' + this.auction.id + '/bidder-status?address=' + encodeURIComponent(this.wallet.address))
            .then(function (data) {
                if (self.destroyed) return;
                var banner = self.shadow.getElementById('caw-bidder-banner');
                var bidForm = self.shadow.getElementById('caw-bid-form');
                if (!banner) return;

                if (data.is_highest_bidder) {
                    banner.innerHTML = '<div class="caw-status caw-status--winning"><strong>You are the highest bidder!</strong><br>Winning bid: ' + escHtml(data.highest_bid_ada) + ' ADA</div>';
                    if (bidForm) bidForm.style.display = 'none';
                } else if (data.has_pending_bid && data.pending_bid) {
                    var pb = data.pending_bid;
                    banner.innerHTML = '<div class="caw-status caw-status--info"><strong>Pending bid: ' + escHtml(pb.bid_ada) + ' ADA</strong><br>Status: ' + escHtml(pb.payment_status) + '</div>';
                    if (bidForm) bidForm.style.display = 'none';
                    if (pb.payment_status === 'awaiting' || pb.payment_status === 'detected') {
                        self.pollBidStatus(pb.bid_id);
                    }
                } else {
                    banner.innerHTML = '';
                    if (bidForm) bidForm.style.display = '';
                }
            }).catch(function () { /* silent */ });
    };

    /* ── Place Bid ───────────────────────────────────────────────── */

    CardanoAuctionWidget.prototype.placeBid = function (bidAda) {
        var self = this;
        var bidForm = this.shadow.getElementById('caw-bid-form');

        this.showStatus('Placing bid...', 'info');
        if (bidForm) bidForm.style.display = 'none';

        CardanoAuctionWidget._emit('onBidPlaced', { auction_id: this.auction.id, bid_ada: bidAda });

        apiPost('/auctions/' + this.auction.id + '/bid', {
            bidder_address: this.wallet.address,
            bid_ada: bidAda,
        }).then(function (result) {
            if (result.error) {
                self.showStatus(result.error, 'error');
                setTimeout(function () { self.hideStatus(); if (bidForm) bidForm.style.display = ''; }, 4000);
                return;
            }

            var bidId = result.bid_id;
            var escrowAddress = result.escrow_address;
            var amountLovelace = result.amount_lovelace;

            self.showStatus('Building payment transaction...', 'info');

            return apiPost('/tx/build', {
                change_address: self.wallet.address,
                outputs: [{ address: escrowAddress, lovelace: amountLovelace }],
            }).then(function (buildResult) {
                if (!buildResult || buildResult.error) {
                    self.showStatus('Transaction build failed. ' + (buildResult ? buildResult.error || buildResult.message || '' : ''), 'error');
                    return;
                }

                var unsignedTx = buildResult.complete || buildResult.stripped || buildResult.tx_cbor || '';
                if (!unsignedTx) {
                    self.showStatus('Build returned no CBOR.', 'error');
                    return;
                }

                self.showStatus('Please sign in your wallet...', 'info');

                return self.wallet.handler.signTx(unsignedTx, true).then(function (witness) {
                    self.showStatus('Submitting transaction...', 'info');

                    return apiPost('/tx/submit', {
                        transaction: unsignedTx,
                        witnesses: [witness],
                    }).then(function (submitResult) {
                        if (!submitResult || submitResult.error) {
                            self.showStatus('Submit failed. ' + (submitResult ? submitResult.error || submitResult.message || '' : ''), 'error');
                            return;
                        }

                        self.showStatus('Transaction submitted! Waiting for confirmation...', 'info');
                        self.pollBidStatus(bidId);
                    });
                });
            });
        }).catch(function (err) {
            console.error('[CAW] Bid flow error:', err);
            self.showStatus('Error: ' + (err.message || 'Wallet rejected or network error.'), 'error');
            setTimeout(function () { self.hideStatus(); if (bidForm) bidForm.style.display = ''; }, 5000);
        });
    };

    /* ── Poll Bid Status ─────────────────────────────────────────── */

    CardanoAuctionWidget.prototype.pollBidStatus = function (bidId) {
        var self = this;
        var attempts = 0;
        var maxAttempts = 120;

        if (this.pollInterval) clearInterval(this.pollInterval);

        this.pollInterval = setInterval(function () {
            if (self.destroyed) { clearInterval(self.pollInterval); return; }
            attempts++;
            if (attempts > maxAttempts) {
                clearInterval(self.pollInterval);
                self.showStatus('Payment monitoring timed out. Your bid is still being tracked.', 'info');
                return;
            }

            apiGet('/bids/' + bidId + '/status').then(function (data) {
                if (data.payment_status === 'confirmed' || data.payment_status === 'won') {
                    clearInterval(self.pollInterval);
                    self.showStatus('Bid confirmed! You are the highest bidder!', 'success');
                    CardanoAuctionWidget._emit('onBidConfirmed', { bid_id: bidId, auction_id: self.auction.id });
                    self.refreshAuction();
                } else if (data.payment_status === 'detected') {
                    self.showStatus('Payment detected, confirming...', 'info');
                } else if (data.payment_status === 'expired') {
                    clearInterval(self.pollInterval);
                    self.showStatus('Bid expired (payment timeout).', 'error');
                    setTimeout(function () { self.refreshAuction(); }, 2000);
                } else if (data.payment_status === 'refunding' || data.payment_status === 'refunded') {
                    clearInterval(self.pollInterval);
                    self.showStatus('Outbid! Refund is being processed.', 'info');
                    CardanoAuctionWidget._emit('onOutbid', { bid_id: bidId, auction_id: self.auction.id });
                    setTimeout(function () { self.refreshAuction(); }, 2000);
                }
            }).catch(function () { /* retry */ });
        }, 5000);
    };

    /* ── Auto Refresh ────────────────────────────────────────────── */

    CardanoAuctionWidget.prototype.startAutoRefresh = function () {
        var self = this;
        if (this.refreshInterval) clearInterval(this.refreshInterval);
        this.refreshInterval = setInterval(function () {
            if (self.destroyed) return;
            self.refreshAuction();
        }, 30000);
    };

    CardanoAuctionWidget.prototype.refreshAuction = function () {
        var self = this;
        Promise.all([
            apiGet('/auctions/' + CFG.auction),
            apiGet('/auctions/' + CFG.auction + '/bids'),
        ]).then(function (results) {
            if (self.destroyed) return;
            var prevBidder = self.auction ? self.auction.current_bidder_address : null;
            self.auction = results[0];
            self.bids = (results[1] && results[1].bids) ? results[1].bids : [];

            // Update bid value.
            var bidVal = self.shadow.getElementById('caw-bid-value');
            if (bidVal) {
                var display = self.auction.current_bid_ada || self.auction.starting_bid_ada;
                bidVal.textContent = formatNumber(display);
            }

            // Update history.
            var historyList = self.shadow.getElementById('caw-history-list');
            if (historyList) {
                historyList.innerHTML = self.renderHistoryHTML();
            }

            // Check outbid.
            if (self.wallet.connected && prevBidder === self.wallet.address && self.auction.current_bidder_address !== self.wallet.address) {
                CardanoAuctionWidget._emit('onOutbid', { auction_id: self.auction.id });
            }

            if (self.wallet.connected) {
                self.checkBidderStatus();
            }
        }).catch(function () { /* silent */ });
    };

    /* ── Helpers ──────────────────────────────────────────────────── */

    CardanoAuctionWidget.prototype.showStatus = function (msg, type) {
        var el = this.shadow.getElementById('caw-status');
        if (!el) return;
        el.style.display = 'block';
        el.className = 'caw-status caw-status--' + (type || 'info');
        el.textContent = msg;
    };

    CardanoAuctionWidget.prototype.hideStatus = function () {
        var el = this.shadow.getElementById('caw-status');
        if (el) el.style.display = 'none';
    };

    CardanoAuctionWidget.prototype.destroy = function () {
        this.destroyed = true;
        if (this.countdownInterval) clearInterval(this.countdownInterval);
        if (this.refreshInterval) clearInterval(this.refreshInterval);
        if (this.pollInterval) clearInterval(this.pollInterval);
    };

    /* ── Utility ─────────────────────────────────────────────────── */

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    function escAttr(str) {
        return String(str || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function pad2(n) {
        return n < 10 ? '0' + n : '' + n;
    }

    function formatNumber(val) {
        var num = parseFloat(val);
        if (isNaN(num)) return val;
        // Format with commas: 4805.00 → "4,805"
        var parts = num.toFixed(2).split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        // Drop decimal if .00
        if (parts[1] === '00') return parts[0];
        return parts.join('.');
    }

    function getTimeAgo(dateStr) {
        try {
            var d = new Date(dateStr + (dateStr.indexOf('Z') === -1 && dateStr.indexOf('+') === -1 ? 'Z' : ''));
            var diff = Date.now() - d.getTime();
            if (diff < 0) return '';
            var mins = Math.floor(diff / 60000);
            if (mins < 1) return 'just now';
            if (mins < 60) return mins + 'm ago';
            var hours = Math.floor(mins / 60);
            if (hours < 24) return hours + 'h ago';
            var days = Math.floor(hours / 24);
            return days + 'd ago';
        } catch (e) { return ''; }
    }

    /* ── Boot ────────────────────────────────────────────────────── */

    function boot() {
        var container = document.getElementById(CFG.container);
        if (!container) {
            console.error('[CAW] Container #' + CFG.container + ' not found.');
            return;
        }
        var widget = new CardanoAuctionWidget(container);
        widget.init();

        // Expose for game integration.
        window.CardanoAuctionWidget = CardanoAuctionWidget;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
