/**
 * Cardano Auctions — Admin JS
 */
(function ($) {
    'use strict';

    const admin = window.CA_Admin || {};

    /** Escape HTML to prevent XSS when inserting dynamic content. */
    function escHtml(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    // NFT Lookup.
    $('#ca-lookup-nft').on('click', function () {
        const unit = $('#nft_unit').val().trim();
        if (!unit) return;
        const $status = $('#ca-lookup-status');
        $status.text('Looking up...');

        $.post(admin.ajaxUrl, {
            action: 'ca_lookup_nft',
            nonce: admin.nonce,
            unit: unit,
        }, function (res) {
            if (res.success) {
                const d = res.data;
                $('#nft_policy_id').val(d.policy_id);
                $('#nft_asset_name_hex').val(d.asset_name_hex);
                $('#nft_display_name').val(d.asset_name || d.asset_name_hex);
                $('#nft_image_url').val(d.image);
                $('#nft_metadata').val(JSON.stringify(d.metadata || {}));

                // Update preview.
                $('#ca-nft-img').attr('src', d.image);
                $('#ca-nft-name').text(d.asset_name || d.asset_name_hex);
                let metaHtml = '';
                if (d.metadata) {
                    for (const [k, v] of Object.entries(d.metadata)) {
                        metaHtml += `<div><strong>${escHtml(k)}:</strong> ${escHtml(v)}</div>`;
                    }
                }
                $('#ca-nft-meta').html(metaHtml);
                $('#ca-nft-preview').show();
                $status.text('Found!').css('color', 'green');
            } else {
                $status.text('Error: ' + (res.data || 'Not found')).css('color', 'red');
            }
        }).fail(function () {
            $status.text('Request failed.').css('color', 'red');
        });
    });

    // Check NFT in custody wallet.
    $('#ca-check-nft').on('click', function () {
        const auctionId = $(this).data('auction');
        const $status = $('#ca-check-status');
        $status.text('Checking...');

        $.post(admin.ajaxUrl, {
            action: 'ca_check_nft',
            nonce: admin.nonce,
            auction_id: auctionId,
        }, function (res) {
            if (res.success && res.data.has_nft) {
                $status.html('<span style="color:green;font-weight:bold">NFT confirmed in custody!</span>');
                $('#ca-activate-btn').css('opacity', '1').prop('disabled', false);
            } else if (res.success) {
                $status.html('<span style="color:orange">NFT not yet received. Send it to the custody address above.</span>');
            } else {
                $status.html('<span style="color:red">Error: ' + escHtml(res.data || 'Check failed') + '</span>');
            }
        });
    });

    // Refresh live auction data.
    $('#ca-refresh').on('click', function () {
        const auctionId = $(this).data('auction');
        $.post(admin.ajaxUrl, {
            action: 'ca_auction_data',
            nonce: admin.nonce,
            auction_id: auctionId,
        }, function (res) {
            if (!res.success) return;
            const a = res.data.auction;
            $('#ca-live-bid').text(a.current_bid_lovelace > 0
                ? (a.current_bid_lovelace / 1000000).toFixed(2) + ' ADA'
                : 'None');
            $('#ca-live-total').text(a.total_bids);
            $('#ca-live-end').text(a.end_time);
        });
    });

    // Process stuck refunds.
    $('#ca-process-refunds').on('click', function () {
        const auctionId = $(this).data('auction');
        const $status = $('#ca-poll-status');
        $(this).prop('disabled', true);
        $status.text('Processing refunds...').css('color', '#666');

        $.post(admin.ajaxUrl, {
            action: 'ca_process_refunds',
            nonce: admin.nonce,
            auction_id: auctionId,
        }, function (res) {
            $('#ca-process-refunds').prop('disabled', false);
            if (res.success) {
                const color = res.data.succeeded > 0 ? 'green' : (res.data.errors.length > 0 ? 'red' : '#666');
                $status.text(res.data.message).css('color', color);
                if (res.data.succeeded > 0) {
                    $('#ca-refresh').trigger('click');
                }
            } else {
                $status.text('Error: ' + (res.data || 'Failed')).css('color', 'red');
            }
        }).fail(function () {
            $('#ca-process-refunds').prop('disabled', false);
            $status.text('Request failed.').css('color', 'red');
        });
    });

    // ── Gallery Image Manager ─────────────────────────────────────
    function getGalleryImages() {
        try { return JSON.parse($('#ca-gallery-data').val()) || []; }
        catch(e) { return []; }
    }

    function setGalleryImages(urls) {
        $('#ca-gallery-data').val(JSON.stringify(urls));
        $('#ca-image-url').val(urls.length ? urls[0] : '');
    }

    function renderGalleryThumbs() {
        const urls = getGalleryImages();
        const $thumbs = $('#ca-gallery-thumbs').empty();
        urls.forEach(function(url, i) {
            $thumbs.append(
                '<div class="ca-gallery-thumb" data-url="' + url + '" style="position:relative;cursor:move">' +
                    '<img src="' + url + '" style="width:80px;height:80px;object-fit:cover;border-radius:4px;border:2px solid ' + (i === 0 ? '#0073aa' : '#ddd') + '">' +
                    '<button type="button" class="ca-gallery-remove" style="position:absolute;top:-6px;right:-6px;background:#dc3232;color:#fff;border:none;border-radius:50%;width:20px;height:20px;font-size:12px;line-height:20px;text-align:center;cursor:pointer;padding:0">&times;</button>' +
                '</div>'
            );
        });
        if (urls.length) {
            $('#ca-gallery-main').attr('src', urls[0]);
            $('#ca-gallery-preview').show();
            $('#ca-clear-gallery').show();
        } else {
            $('#ca-gallery-preview').hide();
            $('#ca-clear-gallery').hide();
        }
        // Refresh Sortable so new DOM elements are recognized.
        if ($.fn.sortable && $thumbs.data('ui-sortable')) {
            $thumbs.sortable('refresh');
        }
    }

    // Make thumbnails sortable (jQuery UI Sortable ships with WP admin).
    if ($.fn.sortable) {
        $('#ca-gallery-thumbs').sortable({
            tolerance: 'pointer',
            update: function() {
                const urls = [];
                $('#ca-gallery-thumbs .ca-gallery-thumb').each(function() {
                    urls.push($(this).data('url'));
                });
                setGalleryImages(urls);
                // Update border highlights — first = primary.
                $('#ca-gallery-thumbs .ca-gallery-thumb img').css('border-color', '#ddd');
                $('#ca-gallery-thumbs .ca-gallery-thumb:first img').css('border-color', '#0073aa');
                $('#ca-gallery-main').attr('src', urls[0] || '');
            }
        });
    }

    // Add images via WP media library (multi-select).
    $('#ca-add-gallery-images').on('click', function(e) {
        e.preventDefault();
        const frame = wp.media({
            title: 'Select Auction Images',
            button: { text: 'Add to gallery' },
            multiple: true,
        });
        frame.on('select', function() {
            const urls = getGalleryImages();
            frame.state().get('selection').each(function(attachment) {
                const data = attachment.toJSON();
                const url = data.sizes && data.sizes.large
                    ? data.sizes.large.url
                    : data.url;
                urls.push(url);
            });
            setGalleryImages(urls);
            renderGalleryThumbs();
        });
        frame.open();
    });

    // Remove single thumbnail.
    $(document).on('click', '.ca-gallery-remove', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const urlToRemove = $(this).closest('.ca-gallery-thumb').data('url');
        const urls = getGalleryImages().filter(function(u) { return u !== urlToRemove; });
        setGalleryImages(urls);
        renderGalleryThumbs();
    });

    // Click thumbnail to preview as main.
    $(document).on('click', '.ca-gallery-thumb img', function() {
        $('#ca-gallery-main').attr('src', $(this).closest('.ca-gallery-thumb').data('url'));
        $('#ca-gallery-thumbs .ca-gallery-thumb img').css('border-color', '#ddd');
        $(this).css('border-color', '#0073aa');
    });

    // Clear all gallery images.
    $('#ca-clear-gallery').on('click', function() {
        setGalleryImages([]);
        renderGalleryThumbs();
    });

    // Safety net: sync hidden input from DOM thumbs right before form submit.
    $('#ca-auction-form').on('submit', function() {
        const $thumbs = $('#ca-gallery-thumbs .ca-gallery-thumb');
        if ($thumbs.length) {
            const urls = [];
            $thumbs.each(function() { urls.push($(this).data('url')); });
            setGalleryImages(urls);
        }
    });

    // ── Event meta toggle ──────────────────────────────────────────
    $(document).on('click', '.ca-meta-toggle', function () {
        const $pre = $(this).next('.ca-event-meta');
        $pre.toggle();
        $(this).toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2');
    });

    // Manual payment check via Blockfrost.
    $('#ca-poll-payments').on('click', function () {
        const auctionId = $(this).data('auction');
        const $status = $('#ca-poll-status');
        $(this).prop('disabled', true);
        $status.text('Checking Blockfrost...').css('color', '#666');

        $.post(admin.ajaxUrl, {
            action: 'ca_poll_payments',
            nonce: admin.nonce,
            auction_id: auctionId,
        }, function (res) {
            $('#ca-poll-payments').prop('disabled', false);
            if (res.success) {
                const color = res.data.confirmed > 0 ? 'green' : '#666';
                $status.text(res.data.message).css('color', color);
                if (res.data.confirmed > 0) {
                    // Refresh the page data.
                    $('#ca-refresh').trigger('click');
                }
            } else {
                $status.text('Error: ' + (res.data || 'Check failed')).css('color', 'red');
            }
        }).fail(function () {
            $('#ca-poll-payments').prop('disabled', false);
            $status.text('Request failed.').css('color', 'red');
        });
    });

    // ── Wallet Dashboard ─────────────────────────────────────────────

    // Per-card balance refresh.
    function refreshWalletBalance(walletId) {
        const $card = $(`.ca-wallet-card[data-wallet-id="${walletId}"]`);
        $card.find('.ca-wallet-refresh').text('...').prop('disabled', true);

        $.post(admin.ajaxUrl, {
            action: 'ca_wallet_balance',
            nonce: admin.nonce,
            wallet_id: walletId,
        }, function (res) {
            $card.find('.ca-wallet-refresh').html('&#x21bb;').prop('disabled', false);
            if (!res.success) return;

            const d = res.data;
            $card.find('.ca-wallet-ada').text(parseFloat(d.ada).toFixed(2));

            // Render asset grid.
            const $assets = $(`#ca-wallet-assets-${walletId}`);
            if (!d.assets || d.assets.length === 0) {
                $assets.html('');
                return;
            }
            let html = '<div class="ca-asset-tiles">';
            d.assets.forEach(function (a) {
                html += '<div class="ca-asset-tile">';
                if (a.image) {
                    html += `<img src="${a.image}" alt="${a.name}" class="ca-asset-thumb">`;
                }
                html += `<span class="ca-asset-name">${a.name}</span>`;
                html += `<span class="ca-asset-qty">x${a.qty}</span>`;
                if (parseInt(a.qty) === 1) {
                    html += `<button type="button" class="button button-small ca-list-nft" data-wallet-id="${walletId}" data-unit="${a.unit}">List</button>`;
                }
                html += '</div>';
            });
            html += '</div>';
            $assets.html(html);
        }).fail(function () {
            $card.find('.ca-wallet-refresh').html('&#x21bb;').prop('disabled', false);
        });
    }

    $(document).on('click', '.ca-wallet-refresh', function () {
        refreshWalletBalance($(this).data('wallet-id'));
    });

    // Auto-fetch balance for active wallets showing 0.
    $('.ca-wallet-card').each(function () {
        const $card = $(this);
        const walletId = $card.data('wallet-id');
        const status = $card.data('status');
        const currentAda = parseFloat($card.find('.ca-wallet-ada').text());
        if (status === 'active' && currentAda === 0) {
            refreshWalletBalance(walletId);
        }
    });

    // Refresh All Balances.
    $('#ca-refresh-all').on('click', function () {
        const $btn = $(this);
        const $status = $('#ca-refresh-all-status');
        $btn.prop('disabled', true);
        $status.text('Refreshing...');

        $.post(admin.ajaxUrl, {
            action: 'ca_refresh_balances',
            nonce: admin.nonce,
        }, function (res) {
            $btn.prop('disabled', false);
            if (!res.success) {
                $status.text('Error: ' + (res.data || 'Failed'));
                return;
            }
            $status.text(`Updated ${res.data.updated} wallet(s).`);
            // Update displayed balances.
            res.data.wallets.forEach(function (w) {
                $(`#ca-wallet-ada-${w.id}`).text(parseFloat(w.ada).toFixed(2));
            });
            setTimeout(function () { $status.text(''); }, 3000);
        }).fail(function () {
            $btn.prop('disabled', false);
            $status.text('Request failed.');
        });
    });

    // Send form toggle.
    $(document).on('click', '.ca-send-toggle', function () {
        $(this).closest('.ca-wallet-card').find('.ca-send-form').slideToggle(200);
    });

    // Send confirm.
    $(document).on('click', '.ca-send-confirm', function () {
        const $card = $(this).closest('.ca-wallet-card');
        const walletId = $(this).data('wallet-id');
        const recipient = $card.find('.ca-send-recipient').val().trim();
        const amount = parseFloat($card.find('.ca-send-amount').val());
        const $status = $card.find('.ca-send-status');

        if (!recipient || !amount || amount <= 0) {
            $status.text('Enter recipient and amount.').css('color', 'red');
            return;
        }
        if (!confirm(`Send ${amount} ADA from wallet #${walletId} to ${recipient.substring(0, 20)}...?`)) return;

        $(this).prop('disabled', true);
        $status.text('Sending...').css('color', '#666');

        $.post(admin.ajaxUrl, {
            action: 'ca_wallet_send',
            nonce: admin.nonce,
            wallet_id: walletId,
            recipient: recipient,
            amount: amount,
        }, function (res) {
            $card.find('.ca-send-confirm').prop('disabled', false);
            if (res.success) {
                $status.html(`<span style="color:green">Sent! TX: ${res.data.txHash.substring(0, 16)}...</span>`);
                $card.find('.ca-send-recipient, .ca-send-amount').val('');
                // Refresh balance after a short delay.
                setTimeout(function () { refreshWalletBalance(walletId); }, 3000);
            } else {
                $status.text('Error: ' + (res.data || 'Failed')).css('color', 'red');
            }
        }).fail(function () {
            $card.find('.ca-send-confirm').prop('disabled', false);
            $status.text('Request failed.').css('color', 'red');
        });
    });

    // List NFT as auction.
    $(document).on('click', '.ca-list-nft', function () {
        const walletId = $(this).data('wallet-id');
        const unit = $(this).data('unit');
        if (!confirm('Create a draft auction from this NFT?')) return;

        $(this).prop('disabled', true).text('Creating...');

        $.post(admin.ajaxUrl, {
            action: 'ca_create_auction_from_nft',
            nonce: admin.nonce,
            wallet_id: walletId,
            unit: unit,
        }, function (res) {
            if (res.success && res.data.redirect_url) {
                window.location.href = res.data.redirect_url;
            } else {
                alert('Error: ' + (res.data || 'Failed'));
                $(this).prop('disabled', false).text('List');
            }
        }).fail(function () {
            alert('Request failed.');
        });
    });

    // Copy to clipboard (with fallback for non-HTTPS contexts).
    $(document).on('click', '.ca-copy-btn', function () {
        const text = $(this).data('copy');
        const $btn = $(this);
        const orig = $btn.text();

        function onSuccess() {
            $btn.text('Copied!');
            setTimeout(function () { $btn.text(orig); }, 1500);
        }

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(onSuccess);
        } else {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            onSuccess();
        }
    });

    // ── Master Wallet ────────────────────────────────────────────────

    // Generate master wallet.
    $('#ca-generate-master').on('click', function () {
        const $btn = $(this);
        const $status = $('#ca-generate-status');
        const name = $('#ca-master-wallet-name').val().trim() || 'Master Wallet';

        if (!confirm('Generate a new master wallet? This will create a new HD seed for ' + name + '.')) return;

        $btn.prop('disabled', true);
        $status.text('Generating...').css('color', '#666');

        $.post(admin.ajaxUrl, {
            action: 'ca_generate_master_wallet',
            nonce: admin.nonce,
            wallet_name: name,
        }, function (res) {
            $btn.prop('disabled', false);
            if (res.success) {
                $status.text('Success! Reloading...').css('color', 'green');
                setTimeout(function () { location.reload(); }, 500);
            } else {
                $status.text('Error: ' + (res.data || 'Failed')).css('color', 'red');
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $status.text('Request failed.').css('color', 'red');
        });
    });

    // Dismiss mnemonic banner.
    $('#ca-dismiss-mnemonic').on('click', function () {
        const walletId = $(this).data('wallet-id');
        if (!confirm('Are you sure you have saved your recovery phrase? It cannot be shown again.')) return;

        $.post(admin.ajaxUrl, {
            action: 'ca_dismiss_mnemonic',
            nonce: admin.nonce,
            wallet_id: walletId,
        });

        $('#ca-mnemonic-banner').slideUp(300);
    });

    // Archive master wallet.
    $('#ca-archive-master').on('click', function () {
        const walletId = $(this).data('wallet-id');
        if (!confirm('Archive this master wallet? New escrow wallets will fall back to independent generation until a new master wallet is created.')) return;

        $(this).prop('disabled', true);

        $.post(admin.ajaxUrl, {
            action: 'ca_archive_master_wallet',
            nonce: admin.nonce,
            wallet_id: walletId,
        }, function (res) {
            if (res.success) {
                location.reload();
            } else {
                alert('Error: ' + (res.data || 'Failed'));
                $('#ca-archive-master').prop('disabled', false);
            }
        });
    });

    // Unarchive master wallet.
    $(document).on('click', '.ca-unarchive-master', function () {
        const walletId = $(this).data('wallet-id');
        if (!confirm('Restore this master wallet as the active wallet?')) return;

        $(this).prop('disabled', true);

        $.post(admin.ajaxUrl, {
            action: 'ca_unarchive_master_wallet',
            nonce: admin.nonce,
            wallet_id: walletId,
        }, function (res) {
            if (res.success) {
                location.reload();
            } else {
                alert('Error: ' + (res.data || 'Failed'));
                $('.ca-unarchive-master').prop('disabled', false);
            }
        });
    });

    // Master wallet balance refresh.
    $('#ca-master-refresh').on('click', function () {
        $(this).text('...').prop('disabled', true);

        $.post(admin.ajaxUrl, {
            action: 'ca_master_wallet_balance',
            nonce: admin.nonce,
        }, function (res) {
            $('#ca-master-refresh').html('&#x21bb;').prop('disabled', false);
            if (res.success) {
                $('#ca-master-ada').text(parseFloat(res.data.ada).toFixed(2));
            }
        }).fail(function () {
            $('#ca-master-refresh').html('&#x21bb;').prop('disabled', false);
        });
    });

    // Auto-refresh master wallet balance on load.
    if ($('#ca-master-refresh').length) {
        $('#ca-master-refresh').trigger('click');
    }

    // Reveal extended signing key.
    $('#ca-reveal-skey').on('click', function () {
        const $display = $('#ca-master-skey-display');
        const $btn = $(this);

        if ($display.hasClass('ca-skey-revealed')) {
            $display.removeClass('ca-skey-revealed').html('&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;');
            $btn.text('Reveal');
            return;
        }

        // Fetch the key from the stored encrypted value (server-side only).
        // The key is already on the page in encrypted form; we use the existing skey display pattern.
        $display.addClass('ca-skey-revealed');
        $btn.text('Hide');
        $display.text('(encrypted on server — use decrypt tools)');
    });

    // Load master wallet NFTs.
    $('#ca-load-master-nfts').on('click', function () {
        const walletId = $(this).data('wallet-id');
        const $grid = $('#ca-master-nft-grid');
        $(this).prop('disabled', true).text('Loading...');

        $.post(admin.ajaxUrl, {
            action: 'ca_master_wallet_assets',
            nonce: admin.nonce,
            wallet_id: walletId,
        }, function (res) {
            $('#ca-load-master-nfts').prop('disabled', false).text('Load NFTs');
            if (!res.success) {
                $grid.html('<p style="color:red">' + (res.data || 'Failed') + '</p>');
                return;
            }

            const assets = res.data.assets;
            if (!assets || assets.length === 0) {
                $grid.html('<p style="color:#666">No NFTs found in master wallet.</p>');
                return;
            }

            let html = '<div class="ca-asset-tiles">';
            assets.forEach(function (a) {
                html += '<div class="ca-asset-tile">';
                if (a.image) {
                    html += `<img src="${a.image}" alt="${a.name}" class="ca-asset-thumb">`;
                }
                html += `<span class="ca-asset-name">${a.name}</span>`;
                html += `<span class="ca-asset-qty">x${a.quantity}</span>`;
                html += '</div>';
            });
            html += '</div>';
            $grid.html(html);
        }).fail(function () {
            $('#ca-load-master-nfts').prop('disabled', false).text('Load NFTs');
            $grid.html('<p style="color:red">Request failed.</p>');
        });
    });

    // ── NFT Browse on Auction Edit Page ──────────────────────────────

    $('#ca-browse-nfts').on('click', function () {
        const $grid = $('#ca-nft-browse-grid');

        if ($grid.is(':visible')) {
            $grid.slideUp(200);
            return;
        }

        $grid.html('<div class="ca-nft-grid-loading">Loading NFTs from master wallet...</div>').slideDown(200);

        $.post(admin.ajaxUrl, {
            action: 'ca_master_wallet_assets',
            nonce: admin.nonce,
        }, function (res) {
            if (!res.success) {
                $grid.html('<p style="color:red;padding:12px">' + (res.data || 'Failed') + '</p>');
                return;
            }

            const assets = res.data.assets;
            if (!assets || assets.length === 0) {
                $grid.html('<p style="color:#666;padding:12px">No NFTs found in master wallet.</p>');
                return;
            }

            let html = '';
            assets.forEach(function (a) {
                if (parseInt(a.quantity) !== 1) return; // Only show NFTs (qty=1).
                html += `<div class="ca-nft-grid-card" data-policy="${a.policy_id}" data-hex="${a.asset_name_hex}" data-name="${a.name}" data-image="${a.image}" data-meta='${JSON.stringify(a.metadata || {})}'>`;
                if (a.image) {
                    html += `<img src="${a.image}" alt="${a.name}">`;
                } else {
                    html += '<div style="height:80px;background:#eee;border-radius:4px;display:flex;align-items:center;justify-content:center;color:#999;font-size:10px">No Image</div>';
                }
                html += `<div class="ca-nft-grid-name" title="${a.name}">${a.name}</div>`;
                html += '</div>';
            });

            if (!html) {
                $grid.html('<p style="color:#666;padding:12px">No NFTs (qty=1) found in master wallet.</p>');
                return;
            }

            $grid.html(html);
        }).fail(function () {
            $grid.html('<p style="color:red;padding:12px">Request failed.</p>');
        });
    });

    // Select NFT from browse grid.
    $(document).on('click', '.ca-nft-grid-card', function () {
        $('.ca-nft-grid-card').removeClass('selected');
        $(this).addClass('selected');

        const policyId = $(this).data('policy');
        const hexName = $(this).data('hex');
        const name = $(this).data('name');
        const image = $(this).data('image');
        const metadata = $(this).data('meta') || {};

        // Populate hidden fields.
        $('#nft_policy_id').val(policyId);
        $('#nft_asset_name_hex').val(hexName);
        $('#nft_display_name').val(name);
        $('#nft_image_url').val(image);
        $('#nft_metadata').val(JSON.stringify(metadata));
        $('#nft_unit').val(policyId + hexName);

        // Update preview.
        $('#ca-nft-img').attr('src', image);
        $('#ca-nft-name').text(name);
        let metaHtml = '';
        if (metadata && typeof metadata === 'object') {
            for (const [k, v] of Object.entries(metadata)) {
                metaHtml += `<div><strong>${k}:</strong> ${v}</div>`;
            }
        }
        $('#ca-nft-meta').html(metaHtml);
        $('#ca-nft-preview').show();
        $('#ca-lookup-status').text('Selected from master wallet').css('color', 'green');
    });

    // ── Minting Tab System ─────────────────────────────────────────────

    // Tab switching.
    $(document).on('click', '.ca-nft-tab', function (e) {
        e.preventDefault();
        const tab = $(this).data('tab');
        $('.ca-nft-tab').removeClass('active');
        $(this).addClass('active');
        $('.ca-nft-tab-content').hide().removeClass('active');
        $('#ca-tab-' + tab).show().addClass('active');
    });

    // Toggle new policy form.
    $('#ca-new-policy-toggle').on('click', function () {
        $('#ca-new-policy-form').slideToggle(200);
    });

    // Create new collection/policy.
    $('#ca-create-policy').on('click', function () {
        const name = $('#ca-new-policy-name').val().trim();
        const expiry = $('#ca-new-policy-expiry').val();
        const $status = $('#ca-policy-create-status');

        if (!name) {
            $status.text('Name is required.').css('color', 'red');
            return;
        }

        $(this).prop('disabled', true);
        $status.text('Creating policy on-chain...').css('color', '#666');

        const expirationDate = expiry ? expiry.replace('T', ' ') + ':00' : '';

        $.post(admin.ajaxUrl, {
            action: 'ca_create_policy',
            nonce: admin.nonce,
            name: name,
            expiration_date: expirationDate,
        }, function (res) {
            $('#ca-create-policy').prop('disabled', false);
            if (res.success) {
                const d = res.data;
                $status.text('Created!').css('color', 'green');
                // Add to dropdown and select it.
                const label = d.name + ' (0 minted, expires ' + d.expiration_date.substring(0, 10) + ')';
                const $opt = $('<option>').val(d.id).text(label).attr('data-policy-id', d.policy_id);
                $('#ca-mint-policy-select').append($opt).val(d.id);
                $('#ca-new-policy-form').slideUp(200);
                $('#ca-new-policy-name').val('');
                checkMintReady();
            } else {
                $status.text('Error: ' + (res.data || 'Failed')).css('color', 'red');
            }
        }).fail(function () {
            $('#ca-create-policy').prop('disabled', false);
            $status.text('Request failed.').css('color', 'red');
        });
    });

    // Policy dropdown change.
    $('#ca-mint-policy-select').on('change', function () {
        checkMintReady();
    });

    // Pin attachment to IPFS via Pinata.
    function pinToIpfs(attachmentId) {
        const $status = $('#ca-pin-status');
        const $btn = $('#ca-pin-to-ipfs');

        $btn.prop('disabled', true);
        $status.text('Pinning to IPFS via Pinata...').css('color', '#666');

        $.post(admin.ajaxUrl, {
            action: 'ca_pin_to_ipfs',
            nonce: admin.nonce,
            attachment_id: attachmentId,
        }, function (res) {
            $btn.prop('disabled', false);
            if (res.success) {
                const cid = res.data.cid;
                const mediaType = res.data.mediaType;
                $('#ca-mint-ipfs-cid').val(cid);
                $('#ca-mint-media-type').val(mediaType);
                $status.html('<span style="color:green">Pinned! CID: ' + cid + '</span>');
                checkMintReady();
            } else {
                $status.text('IPFS pin failed: ' + (res.data || 'Failed') + '. Enter CID manually below.').css('color', 'red');
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $status.text('IPFS pin request failed. Enter CID manually below.').css('color', 'red');
        });
    }

    // Mint image upload via WP media library.
    $('#ca-mint-upload-image').on('click', function (e) {
        e.preventDefault();
        const frame = wp.media({
            title: 'Select NFT Image',
            button: { text: 'Use this image' },
            multiple: false,
        });
        frame.on('select', function () {
            const attachment = frame.state().get('selection').first().toJSON();
            const url = attachment.sizes && attachment.sizes.medium
                ? attachment.sizes.medium.url
                : attachment.url;
            $('#ca-mint-attachment-id').val(attachment.id);
            $('#ca-mint-image-url').val(url);
            $('#ca-mint-image-thumb').attr('src', url);
            $('#ca-mint-image-preview').show();
            $('#ca-mint-image-status').text('Selected: ' + attachment.filename).css('color', 'green');
            $('#ca-pin-to-ipfs').prop('disabled', false);

            // Detect media type.
            const ext = (attachment.filename || '').split('.').pop().toLowerCase();
            const mimeMap = { png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', gif: 'image/gif', svg: 'image/svg+xml', webp: 'image/webp' };
            $('#ca-mint-media-type').val(mimeMap[ext] || attachment.mime || 'image/png');

            // Auto-pin to IPFS when Pinata is enabled.
            if (admin.pinataEnabled) {
                pinToIpfs(attachment.id);
            }

            checkMintReady();
        });
        frame.open();
    });

    // Manual re-pin button (fallback or retry).
    $('#ca-pin-to-ipfs').on('click', function () {
        const attachmentId = $('#ca-mint-attachment-id').val();
        if (!attachmentId) {
            $('#ca-pin-status').text('Select an image first.').css('color', 'red');
            return;
        }
        pinToIpfs(attachmentId);
    });

    // IPFS CID manual input.
    $('#ca-mint-ipfs-cid').on('input', function () {
        checkMintReady();
    });

    // NFT name sync for readiness check.
    $('#ca-mint-nft-name').on('input', function () {
        checkMintReady();
    });

    // Custom attributes: add row.
    $('#ca-add-attr').on('click', function () {
        const row = '<div class="ca-custom-attr-row">' +
            '<input type="text" class="ca-attr-key" placeholder="Key">' +
            '<input type="text" class="ca-attr-value" placeholder="Value">' +
            '<button type="button" class="button ca-remove-attr" title="Remove">&times;</button>' +
            '</div>';
        $('#ca-custom-attributes').append(row);
    });

    // Custom attributes: remove row.
    $(document).on('click', '.ca-remove-attr', function () {
        const $container = $('#ca-custom-attributes');
        if ($container.find('.ca-custom-attr-row').length > 1) {
            $(this).closest('.ca-custom-attr-row').remove();
        } else {
            // Keep last row but clear it.
            $(this).closest('.ca-custom-attr-row').find('input').val('');
        }
    });

    // Mint readiness check.
    function checkMintReady() {
        const hasPolicy = !!$('#ca-mint-policy-select').val();
        const hasImage = !!$('#ca-mint-ipfs-cid').val().trim();
        const hasName = !!$('#ca-mint-nft-name').val().trim();
        $('#ca-mint-btn').prop('disabled', !(hasPolicy && hasImage && hasName));
    }

    // Mint button click.
    $('#ca-mint-btn').on('click', function () {
        const $btn = $(this);
        const $status = $('#ca-mint-status');

        const policyDbId = $('#ca-mint-policy-select').val();
        const title = $('#ca-mint-nft-name').val().trim();
        const imageIpfs = $('#ca-mint-ipfs-cid').val().trim();
        const mediaType = $('#ca-mint-media-type').val();
        const description = $('#ca-mint-nft-desc').val().trim();
        const category = $('#ca-mint-category').val();
        const condition = $('#ca-mint-condition').val();
        const creator = $('#ca-mint-creator').val().trim();
        const startingPrice = $('#starting_bid').val();
        const reservePrice = $('#reserve_price').val();

        // Collect custom attributes.
        const custom = [];
        $('.ca-custom-attr-row').each(function () {
            const key = $(this).find('.ca-attr-key').val().trim();
            const value = $(this).find('.ca-attr-value').val().trim();
            if (key && value) {
                custom.push({ key: key, value: value });
            }
        });

        if (!policyDbId || !title || !imageIpfs) {
            $status.text('Please fill in all required fields (collection, name, image).').css('color', 'red');
            return;
        }

        if (!confirm('Mint "' + title + '" to the master wallet? This will create an on-chain transaction.')) return;

        $btn.prop('disabled', true);
        $status.html('<span style="color:#666">Building transaction &amp; minting NFT... this may take 15-30 seconds.</span>');

        $.post(admin.ajaxUrl, {
            action: 'ca_mint_auction_nft',
            nonce: admin.nonce,
            policy_db_id: policyDbId,
            title: title,
            image_ipfs: imageIpfs,
            media_type: mediaType,
            description: description,
            category: category,
            condition: condition,
            creator: creator,
            starting_price: startingPrice,
            reserve_price: reservePrice,
            custom: custom,
        }, function (res) {
            $btn.prop('disabled', false);
            if (res.success) {
                const d = res.data;
                $status.html('<span style="color:green;font-weight:bold">NFT minted! TX: ' + d.txHash + '</span>');

                // Auto-populate the auction form NFT fields.
                $('#nft_policy_id').val(d.policy_id);
                $('#nft_asset_name_hex').val(d.asset_name_hex);
                $('#nft_display_name').val(d.nft_display_name);
                $('#nft_image_url').val(d.nft_image_url);
                $('#nft_metadata').val(d.nft_metadata);
                $('#nft_unit').val(d.unit);
                $('#mint_tx_hash').val(d.txHash);
                $('#mint_policy_table_id').val(d.policy_db_id);

                // Auto-fill the auction title if empty.
                if (!$('#title').val()) {
                    $('#title').val(title);
                }

                // Update NFT preview.
                $('#ca-nft-img').attr('src', d.nft_image_url);
                $('#ca-nft-name').text(d.nft_display_name);
                $('#ca-nft-preview').show();

                // Disable mint button (already minted).
                $btn.text('Minted!').prop('disabled', true);
            } else {
                $status.text('Error: ' + (res.data || 'Mint failed')).css('color', 'red');
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $status.text('Request failed.').css('color', 'red');
        });
    });

    // Check NFT in master wallet (auction edit page).
    $('#ca-check-nft-master').on('click', function () {
        const auctionId = $(this).data('auction');
        const masterAddress = $(this).data('address');
        const $status = $('#ca-check-status');
        $status.text('Checking master wallet...');

        const policyId = $('#nft_policy_id').val();
        const hexName = $('#nft_asset_name_hex').val();
        if (!policyId || !hexName) {
            $status.html('<span style="color:orange">No NFT configured for this auction.</span>');
            return;
        }

        // Use the master wallet balance endpoint to check.
        $.post(admin.ajaxUrl, {
            action: 'ca_master_wallet_assets',
            nonce: admin.nonce,
        }, function (res) {
            if (!res.success) {
                $status.html('<span style="color:red">Error: ' + (res.data || 'Failed') + '</span>');
                return;
            }

            const unit = policyId + hexName;
            const found = (res.data.assets || []).some(function (a) { return a.unit === unit; });

            if (found) {
                $status.html('<span style="color:green;font-weight:bold">NFT confirmed in master wallet!</span>');
                $('#ca-activate-btn').css('opacity', '1').prop('disabled', false);
            } else {
                $status.html('<span style="color:orange">NFT not found in master wallet. Send it to: ' + masterAddress.substring(0, 20) + '...</span>');
            }
        }).fail(function () {
            $status.html('<span style="color:red">Request failed.</span>');
        });
    });

    // ── Widget Deployer ────────────────────────────────────────────────

    // Generate API key.
    $('#ca-generate-key').on('click', function () {
        const label = $('#ca-key-label').val().trim();
        if (!label) {
            alert('Please enter a label for the API key.');
            return;
        }

        const origins = $('#ca-key-origins').val().trim();
        const auctionIds = $('#ca-key-auctions').val() || [];

        $(this).prop('disabled', true).text('Generating...');

        $.post(admin.ajaxUrl, {
            action: 'ca_generate_api_key',
            nonce: admin.nonce,
            label: label,
            allowed_origins: origins,
            auction_ids: auctionIds,
        }, function (res) {
            $('#ca-generate-key').prop('disabled', false).text('Generate API Key');
            if (res.success) {
                const d = res.data;
                $('#ca-key-display').text(d.key);
                $('#ca-copy-key').data('copy', d.key);
                $('#ca-key-modal').slideDown(200);
                $('#ca-key-label').val('');
                $('#ca-key-origins').val('');
                // Reload page after a moment to show the key in the table.
                setTimeout(function () { location.reload(); }, 5000);
            } else {
                alert('Error: ' + (res.data || 'Failed'));
            }
        }).fail(function () {
            $('#ca-generate-key').prop('disabled', false).text('Generate API Key');
            alert('Request failed.');
        });
    });

    // Revoke API key.
    $(document).on('click', '.ca-revoke-key', function () {
        const keyId = $(this).data('key-id');
        if (!confirm('Revoke this API key? Any widgets using it will stop working.')) return;

        $(this).prop('disabled', true).text('...');

        $.post(admin.ajaxUrl, {
            action: 'ca_revoke_api_key',
            nonce: admin.nonce,
            key_id: keyId,
        }, function (res) {
            if (res.success) {
                location.reload();
            } else {
                alert('Error: ' + (res.data || 'Failed'));
                $(this).prop('disabled', false).text('Revoke');
            }
        }).fail(function () {
            alert('Request failed.');
        });
    });

    // Live embed code builder + preview.
    function updateEmbedCode() {
        const auctionId = $('#ca-embed-auction').val();
        const keyDisplay = $('#ca-embed-key').val();
        const restUrl = $('#ca-embed-rest-url').val();
        const widgetUrl = $('#ca-embed-widget-url').val();

        if (!auctionId) {
            $('#ca-embed-code').text('<!-- Select an auction and API key above -->');
            $('#ca-copy-embed').data('copy', '');
            $('#ca-widget-preview-placeholder').show();
            $('#ca-widget-preview-frame').hide();
            return;
        }

        const keyPlaceholder = keyDisplay || 'YOUR_API_KEY_HERE';
        const snippet = `<!--
  ╔══════════════════════════════════════════════════════════════════╗
  ║  CARDANO AUCTION WIDGET — Embeddable NFT Auction Component     ║
  ╚══════════════════════════════════════════════════════════════════╝

  This widget renders a live Cardano NFT auction inside a Shadow DOM
  web component. It is fully self-contained: no frameworks, no
  dependencies, zero global CSS side-effects.

  ── HOW IT WORKS ──────────────────────────────────────────────────
  The <script> tag reads its data-* attributes, fetches auction data
  from the REST API, and renders the widget into a Shadow DOM root
  inside the container <div>. All styles are encapsulated.

  ── CUSTOMIZATION (CSS Custom Properties) ─────────────────────────
  Because the widget uses Shadow DOM, normal CSS selectors cannot
  reach its internals. However, CSS custom properties (variables)
  DO pierce the shadow boundary. Set them on the container element:

  | Property           | Default                                    | What it controls                        |
  |--------------------|--------------------------------------------|-----------------------------------------|
  | --caw-accent       | #00e5ff (cyan)                             | Buttons, links, bid amount, glows       |
  | --caw-bg           | #0a0e1a (dark navy)                        | Widget background, button text          |
  | --caw-text         | #e6edf3 (light gray)                       | Primary text color                      |
  | --caw-success      | #00c853 (green)                            | Confirmed state, bid increments         |
  | --caw-error        | #ff3860 (red)                              | LIVE badge, error messages              |
  | --caw-font         | -apple-system, BlinkMacSystemFont, ...     | Body text font stack                    |
  | --caw-font-title   | Trebuchet MS, Gill Sans, ...               | Title / heading font stack              |
  | --caw-font-mono    | Consolas, Courier New, monospace            | Numbers, bid amounts, buttons           |
  | --caw-radius       | 12px                                       | Widget and modal border radius          |
  | --caw-padding      | 28px 32px                                  | Widget inner padding                    |
  | --caw-title-color  | #fff                                       | Title and heading text color            |
  | --caw-modal-bg     | #0d1117                                    | Wallet-connect modal background         |

  Alpha variants (glows, borders, hover states) are derived
  automatically via color-mix(), so changing --caw-accent updates
  every related tint and shadow.

  ── WIDGET DOM STRUCTURE ──────────────────────────────────────────
  #cardano-auction-widget (host)
    shadow root
       <style>           — all widget CSS
       .caw-root         — widget wrapper
          .caw-layout    — two-column flex
             .caw-col-image    — NFT image + gallery thumbnails
             .caw-col-details  — auction info
                .caw-header    — title + LIVE / type badges
                .caw-seller    — seller / network line
                .caw-desc      — description text
                .caw-card      — time remaining panel
                .caw-card.caw-card--bid — current bid panel
                .caw-btn-place — place bid / connect wallet button
                .caw-card      — recent bids list

  ── JAVASCRIPT EVENT API ─────────────────────────────────────────
  CardanoAuctionWidget.on(event, callback)
  CardanoAuctionWidget.off(event, callback)

  Events: bid:placed, bid:confirmed, wallet:connected,
          wallet:disconnected, auction:ended, auction:refreshed

  ── DATA ATTRIBUTES ──────────────────────────────────────────────
  data-api         REST API base URL (required)
  data-key         API key for authentication (required for write ops)
  data-auction     Auction ID to display (required)
  data-container   ID of the container element (default: cardano-auction-widget)
  data-theme       Theme preset (default: game-dark)

  ── EXAMPLES ─────────────────────────────────────────────────────
  Red gaming theme:
    --caw-accent: #ff4444; --caw-bg: #1a0a0a; --caw-error: #ff6b6b;

  Light/clean theme:
    --caw-accent: #0066cc; --caw-bg: #ffffff; --caw-text: #1a1a1a;
    --caw-modal-bg: #f5f5f5; --caw-title-color: #000;

  Gold luxury theme:
    --caw-accent: #d4af37; --caw-bg: #0f0f0f; --caw-success: #50c878;
-->

<style>
  #cardano-auction-widget {
    --caw-accent:     #00e5ff;  /* Buttons, links, glows */
    --caw-bg:         #0a0e1a;  /* Widget background     */
    --caw-text:       #e6edf3;  /* Primary text color    */
    --caw-success:    #00c853;  /* Confirmed state       */
    --caw-error:      #ff3860;  /* Error / live badge    */
    /* --caw-font:       your-font, sans-serif;           */
    /* --caw-font-title: your-font, sans-serif;           */
    /* --caw-font-mono:  your-font, monospace;            */
    /* --caw-radius:     12px;                            */
    /* --caw-padding:    28px 32px;                       */
    /* --caw-title-color: #fff;                           */
    /* --caw-modal-bg:    #0d1117;                        */
  }
</style>

<div id="cardano-auction-widget"></div>
<script
  src="${widgetUrl}"
  data-api="${restUrl}"
  data-key="${keyPlaceholder}"
  data-auction="${auctionId}"
  data-container="cardano-auction-widget"
></${"script"}>`;

        $('#ca-embed-code').text(snippet);
        $('#ca-copy-embed').data('copy', snippet);

        // Update sandbox link with selected auction.
        const sandboxLink = document.getElementById('ca-sandbox-link');
        if (sandboxLink) {
            sandboxLink.href = sandboxLink.dataset.base + '?auction=' + auctionId;
        }

        // Load widget preview in iframe (same-origin, no API key needed for GET).
        loadWidgetPreview(auctionId, restUrl, widgetUrl);
    }

    function loadWidgetPreview(auctionId, restUrl, widgetUrl) {
        const $wrap = $('#ca-widget-preview-wrap');
        const $placeholder = $('#ca-widget-preview-placeholder');

        // Remove any existing iframe and create a fresh one each time.
        $wrap.find('iframe').remove();
        const frame = document.createElement('iframe');
        frame.id = 'ca-widget-preview-frame';
        frame.style.cssText = 'width:100%;border:none;border-radius:8px;min-height:400px;';
        frame.title = 'Widget Preview';
        $wrap.append(frame);

        $placeholder.hide();

        // Build a minimal HTML page that loads the widget.
        const html = '<!DOCTYPE html>' +
            '<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
            '<style>html,body{margin:0;padding:0;background:#0d1117;}</style></head>' +
            '<body>' +
            '<div id="caw-preview"></div>' +
            '<script src="' + widgetUrl + '"' +
            ' data-api="' + restUrl + '"' +
            ' data-key="preview"' +
            ' data-auction="' + auctionId + '"' +
            ' data-container="caw-preview"' +
            '></' + 'script>' +
            '</body></html>';

        // Use document.write for reliable same-origin script loading.
        const doc = frame.contentDocument || frame.contentWindow.document;
        doc.open();
        doc.write(html);
        doc.close();

        // Auto-resize iframe to fit widget content (including after images load).
        function resizeFrame() {
            try {
                const body = frame.contentDocument && frame.contentDocument.body;
                if (body) {
                    const h = body.scrollHeight;
                    if (h > 100) frame.style.height = h + 'px';
                }
            } catch (e) { /* safety */ }
        }

        // Resize on multiple triggers: timeouts + image load events.
        setTimeout(resizeFrame, 800);
        setTimeout(resizeFrame, 2000);
        setTimeout(resizeFrame, 4000);
        setTimeout(resizeFrame, 7000);

        // Also resize whenever an image loads inside the iframe.
        frame.addEventListener('load', function () {
            resizeFrame();
            try {
                const images = frame.contentDocument.querySelectorAll('img');
                for (let i = 0; i < images.length; i++) {
                    images[i].addEventListener('load', resizeFrame);
                }
                // MutationObserver to catch images added after initial load (widget renders async).
                const observer = new MutationObserver(function () {
                    resizeFrame();
                    const imgs = frame.contentDocument.querySelectorAll('img');
                    for (let j = 0; j < imgs.length; j++) {
                        imgs[j].addEventListener('load', resizeFrame);
                    }
                });
                observer.observe(frame.contentDocument.body, { childList: true, subtree: true });
            } catch (e) { /* safety */ }
        });
    }

    $('#ca-embed-auction, #ca-embed-key').on('change', updateEmbedCode);

})(jQuery);
