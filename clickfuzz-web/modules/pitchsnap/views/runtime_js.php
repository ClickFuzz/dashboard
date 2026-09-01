<?php
// Served as application/javascript by Pitchsnap_runtime::script()
// $chat_url, $purchase_url, $video_embed_url injected by the controller
?>(function () {
    'use strict';

    var CHAT_URL      = '<?php echo addslashes($chat_url); ?>';
    var PURCHASE_URL  = '<?php echo addslashes($purchase_url); ?>';
    var VIDEO_URL     = '<?php echo addslashes($video_embed_url); ?>';
    var PRICE_DISPLAY = '<?php echo addslashes($price_display); ?>';
    var OPEN_DELAY    = 20000; // ms before auto-open

    // ── Locate this script tag ──────────────────────────────────────────────
    var scriptTag = document.currentScript;
    if (!scriptTag) {
        var allScripts = document.getElementsByTagName('script');
        scriptTag = allScripts[allScripts.length - 1];
    }
    var TOKEN      = (scriptTag && scriptTag.getAttribute('data-redesign-token')) || '';
    var SITE_TOKEN = (scriptTag && scriptTag.getAttribute('data-site-token'))    || '';

    // ── CORE: populate [data-pitchsnap-current-year] ─────────────────────────
    // Runs unconditionally — does not require TOKEN or chat widget interaction.
    (function () {
        var yr  = String(new Date().getFullYear());
        var els = document.querySelectorAll('[data-pitchsnap-current-year]');
        for (var i = 0; i < els.length; i++) { els[i].textContent = yr; }
    }());

    if (!TOKEN) { return; }

    // ── Brand color (data-primary-color) ────────────────────────────────────
    // Accepts #RGB or #RRGGBB only. Anything else falls back to default blue.
    var PRIMARY_COLOR = (function () {
        var raw = (scriptTag && scriptTag.getAttribute('data-primary-color') || '').trim();
        if (!raw) { return ''; }
        var m3 = raw.match(/^#([0-9a-fA-F])([0-9a-fA-F])([0-9a-fA-F])$/);
        if (m3) { raw = '#' + m3[1]+m3[1] + m3[2]+m3[2] + m3[3]+m3[3]; }
        return /^#[0-9a-fA-F]{6}$/.test(raw) ? raw.toUpperCase() : '';
    }());

    function _darken(hex, amt) {
        var r = Math.max(0, Math.round(parseInt(hex.slice(1,3),16) * (1-amt)));
        var g = Math.max(0, Math.round(parseInt(hex.slice(3,5),16) * (1-amt)));
        var b = Math.max(0, Math.round(parseInt(hex.slice(5,7),16) * (1-amt)));
        return '#' + ('0'+r.toString(16)).slice(-2) + ('0'+g.toString(16)).slice(-2) + ('0'+b.toString(16)).slice(-2);
    }
    function _rgb(hex) {
        return parseInt(hex.slice(1,3),16)+','+parseInt(hex.slice(3,5),16)+','+parseInt(hex.slice(5,7),16);
    }

    // Derived values — computed once, used throughout
    var C_BASE  = PRIMARY_COLOR || '#2D3561';
    var C_DARK  = PRIMARY_COLOR ? _darken(PRIMARY_COLOR, 0.18) : '#1A2050';
    var C_HOVER = PRIMARY_COLOR ? _darken(PRIMARY_COLOR, 0.30) : '#141A3E';
    var C_RGB   = _rgb(C_BASE);

    function brandGrad()        { return 'linear-gradient(135deg,' + C_BASE + ' 0%,' + C_DARK + ' 100%)'; }
    function brandGradShort()   { return 'linear-gradient(135deg,' + C_BASE + ',' + C_DARK + ')'; }
    function brandSolid()       { return C_BASE; }
    function brandShadow(alpha) { return 'rgba(' + C_RGB + ',' + alpha + ')'; }
    function brandHover()       { return C_HOVER; }

    // ── State ───────────────────────────────────────────────────────────────
    var submitted = false;

    // ── Tiny helpers ────────────────────────────────────────────────────────
    function el(tag, attrs) {
        var node = document.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach(function (k) {
                if (k === 'className') { node.className = attrs[k]; }
                else { node.setAttribute(k, attrs[k]); }
            });
        }
        return node;
    }

    function css(obj) {
        return Object.keys(obj).map(function (k) {
            return k.replace(/([A-Z])/g, '-$1').toLowerCase() + ':' + obj[k];
        }).join(';');
    }

    function clear(node) {
        while (node.firstChild) { node.removeChild(node.firstChild); }
    }

    // ── Inject animation + hover styles ─────────────────────────────────────
    var styleEl = document.createElement('style');
    styleEl.textContent = [
        '#ps-panel{transition:opacity .22s ease,transform .22s ease}',
        '#ps-fab{transition:transform .15s ease,box-shadow .15s ease}',
        '#ps-fab:hover{transform:scale(1.08)!important;box-shadow:0 8px 28px ' + brandShadow('.5') + '!important}',
        '#ps-close:hover{opacity:.75!important}',
        '.ps-qbtn:hover{background:#e5e8f4!important}',
        '#ps-send:hover{background:' + brandHover() + '!important}',
        '#ps-purchase-btn:hover{background:' + brandHover() + '!important;transform:translateY(-1px)!important}',
        '@keyframes ps-spin{to{transform:rotate(360deg)}}',
        '#ps-modal-overlay{animation:ps-fadein .2s ease}',
        '@keyframes ps-fadein{from{opacity:0}to{opacity:1}}',
    ].join('');
    document.head.appendChild(styleEl);

    // ── Root container ───────────────────────────────────────────────────────
    var wrap = el('div', { id: 'ps-wrap' });
    wrap.setAttribute('style', css({
        position: 'fixed',
        bottom: '24px',
        right: '24px',
        zIndex: '2147483647',
        fontFamily: '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif',
        fontSize: '14px',
        lineHeight: '1.5',
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'flex-end',
    }));
    document.body.appendChild(wrap);

    // ── Chat panel ──────────────────────────────────────────────────────────
    var panel = el('div', { id: 'ps-panel' });
    panel.setAttribute('style', css({
        width: '340px',
        background: '#ffffff',
        borderRadius: '16px',
        boxShadow: '0 8px 40px rgba(0,0,0,.18)',
        marginBottom: '12px',
        overflow: 'hidden',
        opacity: '0',
        transform: 'translateY(14px)',
        pointerEvents: 'none',
    }));
    wrap.appendChild(panel);

    // Panel header
    var panelHeader = el('div');
    panelHeader.setAttribute('style', css({
        background: brandGrad(),
        padding: '15px 18px',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'space-between',
    }));

    var headerLeft = el('div');
    headerLeft.setAttribute('style', css({ display: 'flex', alignItems: 'center', gap: '10px' }));

    var logoCircle = el('div');
    logoCircle.setAttribute('style', css({
        width: '34px', height: '34px', borderRadius: '50%',
        background: 'rgba(255,255,255,.15)', flexShrink: '0',
        display: 'flex', alignItems: 'center', justifyContent: 'center',
    }));
    logoCircle.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z" fill="#fff"/></svg>';

    var headerMeta = el('div');
    var headerTitle = el('div');
    headerTitle.setAttribute('style', css({ color: '#ffffff', fontWeight: '600', fontSize: '14px', lineHeight: '1.2' }));
    headerTitle.textContent = 'ClickFuzz Web';
    var headerSub = el('div');
    headerSub.setAttribute('style', css({ color: 'rgba(255,255,255,.6)', fontSize: '11px' }));
    headerSub.textContent = 'Website Preview';
    headerMeta.appendChild(headerTitle);
    headerMeta.appendChild(headerSub);

    headerLeft.appendChild(logoCircle);
    headerLeft.appendChild(headerMeta);

    var closeBtn = el('div', { id: 'ps-close' });
    closeBtn.setAttribute('style', css({
        cursor: 'pointer', color: 'rgba(255,255,255,.65)',
        fontSize: '24px', lineHeight: '1', padding: '0 4px',
        userSelect: 'none',
    }));
    closeBtn.textContent = '×';
    closeBtn.addEventListener('click', closePanel);

    panelHeader.appendChild(headerLeft);
    panelHeader.appendChild(closeBtn);
    panel.appendChild(panelHeader);

    // Panel body
    var panelBody = el('div', { id: 'ps-body' });
    panelBody.setAttribute('style', css({ padding: '18px 18px 20px' }));
    panel.appendChild(panelBody);

    // ── Floating action button ───────────────────────────────────────────────
    var fab = el('div', { id: 'ps-fab' });
    fab.setAttribute('style', css({
        width: '56px', height: '56px',
        borderRadius: '50%',
        background: brandGrad(),
        display: 'flex', alignItems: 'center', justifyContent: 'center',
        cursor: 'pointer',
        boxShadow: '0 4px 18px ' + brandShadow('.38'),
        flexShrink: '0',
    }));
    fab.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M20 2H4C2.9 2 2 2.9 2 4v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z" fill="#fff"/></svg>';
    fab.addEventListener('click', togglePanel);
    wrap.appendChild(fab);

    // ── Render initial message + quick replies ───────────────────────────────
    renderInitial();

    // ── Auto-open after delay ────────────────────────────────────────────────
    setTimeout(openPanel, OPEN_DELAY);

    // ── Screen helpers ───────────────────────────────────────────────────────

    function messageBubble(text) {
        var row = el('div');
        row.setAttribute('style', css({
            display: 'flex', alignItems: 'flex-start', gap: '10px', marginBottom: '14px',
        }));

        var avatar = el('div');
        avatar.setAttribute('style', css({
            width: '32px', height: '32px', borderRadius: '50%', flexShrink: '0',
            background: brandGradShort(),
            display: 'flex', alignItems: 'center', justifyContent: 'center',
        }));
        avatar.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z" fill="#fff"/></svg>';

        var bubble = el('div');
        bubble.setAttribute('style', css({
            background: '#f4f6fb', borderRadius: '0 12px 12px 12px',
            padding: '11px 14px', maxWidth: '240px',
        }));
        var p = el('p');
        p.setAttribute('style', css({ margin: '0', fontSize: '14px', color: '#1a1f3c', lineHeight: '1.55' }));
        p.textContent = text;
        bubble.appendChild(p);

        row.appendChild(avatar);
        row.appendChild(bubble);
        return row;
    }

    function renderInitial() {
        clear(panelBody);
        panelBody.appendChild(messageBubble('Hey — what do you think of your new website?'));

        var choices = [
            { reply: 'like',       label: '👍  I like it' },
            { reply: 'change',     label: '✏️  I\'d change a few things' },
            { reply: 'not_for_me', label: '🙅  Not for me' },
        ];

        var replyStack = el('div');
        replyStack.setAttribute('style', css({ display: 'flex', flexDirection: 'column', gap: '8px' }));

        choices.forEach(function (c) {
            var btn = el('button', { className: 'ps-qbtn' });
            btn.setAttribute('style', css({
                background: '#f4f6fb',
                border: '1px solid #e0e4f0',
                borderRadius: '8px',
                padding: '10px 14px',
                fontSize: '13px',
                color: '#1a1f3c',
                cursor: 'pointer',
                textAlign: 'left',
                fontFamily: 'inherit',
                width: '100%',
            }));
            btn.textContent = c.label;
            btn.addEventListener('click', function () {
                if (!submitted) { handleReply(c.reply); }
            });
            replyStack.appendChild(btn);
        });

        panelBody.appendChild(replyStack);
    }

    function handleReply(reply) {
        if (reply === 'like') {
            sendChatRequest('like', '');
            closePanel();
            openSalesModal();
        } else if (reply === 'change') {
            renderChangeForm();
        } else {
            sendResponse(reply, '');
        }
    }

    // ── Sales modal ─────────────────────────────────────────────────────────

    function openSalesModal() {
        var overlay = el('div', { id: 'ps-modal-overlay' });
        overlay.setAttribute('style', css({
            position: 'fixed',
            top: '0', left: '0', right: '0', bottom: '0',
            background: 'rgba(10,15,40,.82)',
            zIndex: '2147483646',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            padding: '16px',
            boxSizing: 'border-box',
        }));

        var modal = el('div', { id: 'ps-modal' });
        modal.setAttribute('style', css({
            background: '#ffffff',
            borderRadius: '20px',
            boxShadow: '0 24px 80px rgba(0,0,0,.4)',
            maxWidth: '540px',
            width: '100%',
            overflow: 'hidden',
            maxHeight: '90vh',
            overflowY: 'auto',
        }));

        // Modal header
        var mHeader = el('div');
        mHeader.setAttribute('style', css({
            background: brandGrad(),
            padding: '20px 24px 18px',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
        }));
        var mTitle = el('div');
        mTitle.setAttribute('style', css({ color: '#fff', fontWeight: '700', fontSize: '16px' }));
        mTitle.textContent = 'Get Your Site Live Today';
        var mClose = el('div');
        mClose.setAttribute('style', css({
            cursor: 'pointer', color: 'rgba(255,255,255,.7)',
            fontSize: '26px', lineHeight: '1', padding: '0 2px', userSelect: 'none',
        }));
        mClose.textContent = '×';
        mClose.addEventListener('click', function () { document.body.removeChild(overlay); });
        mHeader.appendChild(mTitle);
        mHeader.appendChild(mClose);
        modal.appendChild(mHeader);

        // Modal body
        var mBody = el('div');
        mBody.setAttribute('style', css({ padding: '24px 28px 28px' }));

        if (VIDEO_URL) {
            var videoWrap = el('div');
            videoWrap.setAttribute('style', css({
                position: 'relative', paddingBottom: '56.25%',
                height: '0', overflow: 'hidden',
                borderRadius: '12px', marginBottom: '22px', background: '#000',
            }));
            var iframe = el('iframe');
            iframe.setAttribute('src', VIDEO_URL + '?autoplay=1&mute=1');
            iframe.setAttribute('frameborder', '0');
            iframe.setAttribute('allow', 'autoplay; encrypted-media; picture-in-picture');
            iframe.setAttribute('allowfullscreen', '');
            iframe.setAttribute('style', css({
                position: 'absolute', top: '0', left: '0',
                width: '100%', height: '100%', border: '0',
            }));
            videoWrap.appendChild(iframe);
            mBody.appendChild(videoWrap);
        }

        var offerHeading = el('h3');
        offerHeading.setAttribute('style', css({
            margin: '0 0 8px', fontSize: '22px', fontWeight: '700', color: '#1a1f3c',
        }));
        offerHeading.textContent = 'Your new website, ready to convert leads.';
        mBody.appendChild(offerHeading);

        var offerSub = el('p');
        offerSub.setAttribute('style', css({ margin: '0 0 20px', fontSize: '14px', color: '#555e7a', lineHeight: '1.6' }));
        offerSub.textContent = 'We\'ll publish your redesigned site and connect it to an automated lead response system — so every inquiry gets a fast, professional follow-up.';
        mBody.appendChild(offerSub);

        var priceBox = el('div');
        priceBox.setAttribute('style', css({
            background: '#f4f6fb', border: '1.5px solid #e0e4f0',
            borderRadius: '14px', padding: '18px 20px', marginBottom: '20px',
            display: 'flex', alignItems: 'center', justifyContent: 'space-between',
        }));
        var priceLeft = el('div');
        var priceAmount = el('div');
        priceAmount.setAttribute('style', css({ fontSize: '32px', fontWeight: '800', color: brandSolid(), lineHeight: '1' }));
        priceAmount.textContent = PRICE_DISPLAY;
        var pricePer = el('div');
        pricePer.setAttribute('style', css({ fontSize: '13px', color: '#888', marginTop: '4px' }));
        pricePer.textContent = 'per month · 12-month agreement';
        priceLeft.appendChild(priceAmount);
        priceLeft.appendChild(pricePer);
        var priceRight = el('div');
        priceRight.setAttribute('style', css({ fontSize: '12px', color: '#555e7a', textAlign: 'right', maxWidth: '140px' }));
        priceRight.innerHTML = '✓ Live website<br>✓ AI lead response<br>✓ Ongoing support';
        priceBox.appendChild(priceLeft);
        priceBox.appendChild(priceRight);
        mBody.appendChild(priceBox);

        var purchaseBtn = el('button', { id: 'ps-purchase-btn' });
        purchaseBtn.setAttribute('style', css({
            display: 'block', width: '100%',
            background: brandGrad(), color: '#fff',
            border: 'none', borderRadius: '12px',
            padding: '16px', fontSize: '16px', fontWeight: '700',
            cursor: 'pointer', fontFamily: 'inherit',
            transition: 'background .15s,transform .1s', marginBottom: '12px',
        }));
        purchaseBtn.textContent = 'Purchase Plan →';
        purchaseBtn.addEventListener('click', function () { startPurchase(purchaseBtn, mBody, overlay); });
        mBody.appendChild(purchaseBtn);

        var dismissLink = el('a');
        dismissLink.setAttribute('href', '#');
        dismissLink.setAttribute('style', css({
            display: 'block', textAlign: 'center',
            fontSize: '12px', color: '#aab', textDecoration: 'none',
        }));
        dismissLink.textContent = 'Maybe later';
        dismissLink.addEventListener('click', function (e) {
            e.preventDefault();
            document.body.removeChild(overlay);
        });
        mBody.appendChild(dismissLink);

        modal.appendChild(mBody);
        overlay.appendChild(modal);
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) { document.body.removeChild(overlay); }
        });
        document.body.appendChild(overlay);
    }

    function startPurchase(btn, mBody, overlay) {
        btn.disabled    = true;
        btn.textContent = 'Processing…';
        var payload = { redesign_token: TOKEN };
        if (SITE_TOKEN) { payload.site_token = SITE_TOKEN; }
        var xhr = new XMLHttpRequest();
        xhr.open('POST', PURCHASE_URL, true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) { return; }
            var data = {};
            try { data = JSON.parse(xhr.responseText); } catch (e) {}
            if (xhr.status === 200 && data.success) {
                btn.textContent = 'Redirecting…';
                window.location.href = data.agreement_url || data.redirect;
            } else {
                btn.disabled    = false;
                btn.textContent = 'Purchase Plan →';
                showModalError(mBody, btn, data.error || 'Something went wrong. Please try again.');
            }
        };
        xhr.send(JSON.stringify(payload));
    }

    function showModalError(mBody, btn, msg) {
        var existing = document.getElementById('ps-modal-err');
        if (existing) { existing.parentNode.removeChild(existing); }
        var errEl = el('p', { id: 'ps-modal-err' });
        errEl.setAttribute('style', css({
            color: '#c0392b', fontSize: '13px', textAlign: 'center',
            margin: '10px 0 0', lineHeight: '1.5',
        }));
        errEl.textContent = msg;
        mBody.insertBefore(errEl, btn.nextSibling);
    }

    function renderChangeForm() {
        clear(panelBody);
        panelBody.appendChild(messageBubble('What would you like changed?'));

        var textarea = el('textarea');
        textarea.setAttribute('id', 'ps-textarea');
        textarea.setAttribute('placeholder', 'Describe the changes you\'d like…');
        textarea.setAttribute('maxlength', '1000');
        textarea.setAttribute('style', css({
            width: '100%', boxSizing: 'border-box',
            border: '1.5px solid #e0e4f0', borderRadius: '8px',
            padding: '10px 12px', fontSize: '13px', fontFamily: 'inherit',
            resize: 'vertical', minHeight: '80px', color: '#1a1f3c',
            outline: 'none', background: '#f9fafc',
        }));
        panelBody.appendChild(textarea);

        var sendBtn = el('button', { id: 'ps-send' });
        sendBtn.setAttribute('style', css({
            marginTop: '10px', width: '100%',
            background: brandSolid(), border: 'none', borderRadius: '8px',
            padding: '11px', color: '#ffffff', fontSize: '13px',
            fontWeight: '600', cursor: 'pointer', fontFamily: 'inherit',
        }));
        sendBtn.textContent = 'Send Feedback';
        sendBtn.addEventListener('click', function () {
            var ta = document.getElementById('ps-textarea');
            sendResponse('change', ta ? ta.value.trim() : '');
        });
        panelBody.appendChild(sendBtn);
    }

    // ── Send helpers ─────────────────────────────────────────────────────────

    function sendChatRequest(reply, changeText) {
        var payload = { token: TOKEN, reply: reply };
        if (reply === 'change') { payload.change = changeText; }
        var xhr = new XMLHttpRequest();
        xhr.open('POST', CHAT_URL, true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.send(JSON.stringify(payload));
    }

    function sendResponse(reply, changeText) {
        if (submitted) { return; }
        submitted = true;
        var payload = { token: TOKEN, reply: reply };
        if (reply === 'change') { payload.change = changeText; }

        clear(panelBody);
        var spinner = el('div');
        spinner.setAttribute('style', css({ textAlign: 'center', padding: '24px 0', color: '#999', fontSize: '13px' }));
        spinner.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" style="animation:ps-spin .8s linear infinite;vertical-align:middle;margin-right:6px;" fill="none">'
            + '<circle cx="12" cy="12" r="10" stroke="#dde1ef" stroke-width="3"/>'
            + '<path d="M12 2a10 10 0 0110 10" stroke="' + C_BASE + '" stroke-width="3" stroke-linecap="round"/>'
            + '</svg>Sending…';
        panelBody.appendChild(spinner);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', CHAT_URL, true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) { return; }
            clear(panelBody);
            if (xhr.status === 200) {
                var confirmations = {
                    like:        'Great — we\'ll help you get it launched.',
                    change:      'Got it — we\'ll review your feedback and be in touch.',
                    not_for_me:  'Thanks for letting us know.',
                };
                panelBody.appendChild(messageBubble(confirmations[reply] || 'Thanks for your response.'));
            } else {
                submitted = false;
                var errMsg = el('p');
                errMsg.setAttribute('style', css({ fontSize: '13px', color: '#c0392b', textAlign: 'center', margin: '16px 0' }));
                errMsg.textContent = 'Something went wrong. Please try again.';
                panelBody.appendChild(errMsg);
            }
        };
        xhr.send(JSON.stringify(payload));
    }

    // ── Panel open/close ─────────────────────────────────────────────────────

    function openPanel() {
        panel.style.opacity       = '1';
        panel.style.transform     = 'translateY(0)';
        panel.style.pointerEvents = 'auto';
    }

    function closePanel() {
        panel.style.opacity       = '0';
        panel.style.transform     = 'translateY(14px)';
        panel.style.pointerEvents = 'none';
    }

    function togglePanel() {
        if (panel.style.opacity === '1') { closePanel(); } else { openPanel(); }
    }

}());

// ── ClickFuzz Forms runtime ────────────────────────────────────────────────
(function () {
    'use strict';

    var BASE_URL = '<?php echo addslashes(rtrim(base_url("pitchsnap"), "/")); ?>/';

    var FORM_CSS = [
        '.cf-form { font-family: inherit; }',
        '.cf-field { margin-bottom: 14px; }',
        '.cf-field label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 4px; }',
        '.cf-field input, .cf-field textarea { width: 100%; box-sizing: border-box;',
        '  padding: 9px 12px; border: 1px solid #ccc; border-radius: 4px; font-size: 15px; font-family: inherit; }',
        '.cf-field textarea { resize: vertical; }',
        '.cf-required { color: #e74c3c; margin-left: 2px; }',
        '.cf-submit { display: inline-block; padding: 11px 28px; background: #2563eb; color: #fff;',
        '  border: none; border-radius: 4px; font-size: 15px; font-weight: 600; cursor: pointer; }',
        '.cf-submit:hover { background: #1d4ed8; }',
        '.cf-submit:disabled { opacity: 0.6; cursor: default; }',
        '.cf-msg { margin-top: 10px; font-size: 14px; padding: 9px 12px; border-radius: 4px; }',
        '.cf-msg.cf-success { background: #d1fae5; color: #065f46; }',
        '.cf-msg.cf-error   { background: #fee2e2; color: #991b1b; }',
        '.cf-popup-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 99998;',
        '  display: flex; align-items: center; justify-content: center; }',
        '.cf-popup-box { background: #fff; border-radius: 8px; padding: 28px; max-width: 480px;',
        '  width: 90%; position: relative; max-height: 90vh; overflow-y: auto; }',
        '.cf-popup-close { position: absolute; top: 10px; right: 14px; background: none; border: none;',
        '  font-size: 22px; cursor: pointer; color: #666; line-height: 1; }',
    ].join('\n');

    function injectStyles() {
        if (document.getElementById('cf-forms-css')) { return; }
        var s = document.createElement('style');
        s.id = 'cf-forms-css';
        s.textContent = FORM_CSS;
        document.head.appendChild(s);
    }

    function getSiteToken() {
        var sc = document.querySelector('script[data-redesign-token]');
        return sc ? (sc.getAttribute('data-redesign-token') || '') : '';
    }

    function fetchForm(formId, callback) {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', BASE_URL + 'form_render/' + formId, true);
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) { return; }
            try {
                var data = JSON.parse(xhr.responseText);
                if (data.success) { callback(data.html); }
            } catch (e) {}
        };
        xhr.send();
    }

    function submitForm(formEl) {
        var formId    = formEl.getAttribute('data-form-id');
        var siteToken = getSiteToken();
        var inputs    = formEl.querySelectorAll('[name^="cf_field"]');
        var fields    = {};

        for (var i = 0; i < inputs.length; i++) {
            var name = inputs[i].name.replace(/^cf_field\[/, '').replace(/\]$/, '');
            fields[name] = inputs[i].value;
        }

        var submitBtn = formEl.querySelector('.cf-submit');
        var msgEl     = formEl.querySelector('.cf-msg');
        if (submitBtn) { submitBtn.disabled = true; }
        if (msgEl)     { msgEl.style.display = 'none'; msgEl.className = 'cf-msg'; }

        var xhr = new XMLHttpRequest();
        xhr.open('POST', BASE_URL + 'form_submit', true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) { return; }
            if (submitBtn) { submitBtn.disabled = false; }
            try {
                var data = JSON.parse(xhr.responseText);
                if (msgEl) {
                    msgEl.style.display = 'block';
                    if (data.success) {
                        msgEl.className   = 'cf-msg cf-success';
                        msgEl.textContent = data.message || 'Thank you!';
                        formEl.reset();
                    } else {
                        msgEl.className   = 'cf-msg cf-error';
                        msgEl.textContent = data.error || 'Something went wrong. Please try again.';
                    }
                }
            } catch (e) {
                if (msgEl) { msgEl.className = 'cf-msg cf-error'; msgEl.textContent = 'Something went wrong.'; msgEl.style.display = 'block'; }
            }
        };
        xhr.send(JSON.stringify({ form_id: parseInt(formId, 10), site_token: siteToken, fields: fields }));
    }

    function openPopup(formId) {
        var overlay = document.createElement('div');
        overlay.className = 'cf-popup-overlay';

        var box = document.createElement('div');
        box.className = 'cf-popup-box';

        var closeBtn = document.createElement('button');
        closeBtn.className   = 'cf-popup-close';
        closeBtn.textContent = '×';
        closeBtn.setAttribute('aria-label', 'Close');
        closeBtn.onclick = function () { document.body.removeChild(overlay); };
        overlay.onclick  = function (e) { if (e.target === overlay) { document.body.removeChild(overlay); } };

        box.appendChild(closeBtn);
        var placeholder = document.createElement('p');
        placeholder.textContent = 'Loading…';
        placeholder.style.padding = '20px 0';
        box.appendChild(placeholder);
        overlay.appendChild(box);
        document.body.appendChild(overlay);

        fetchForm(formId, function (html) {
            box.innerHTML = '';
            box.appendChild(closeBtn);
            box.insertAdjacentHTML('beforeend', html);
        });
    }

    function init() {
        injectStyles();

        // Replace inline form markers
        var markers = document.querySelectorAll('[data-cf-form]');
        for (var i = 0; i < markers.length; i++) {
            (function (el) {
                fetchForm(el.getAttribute('data-cf-form'), function (html) {
                    el.outerHTML = html;
                });
            }(markers[i]));
        }

        // Popup triggers
        document.body.addEventListener('click', function (e) {
            var btn = e.target && e.target.closest && e.target.closest('[data-cf-popup-form]');
            if (!btn) { return; }
            e.preventDefault();
            openPopup(btn.getAttribute('data-cf-popup-form'));
        });

        // Form submission (delegated)
        document.body.addEventListener('submit', function (e) {
            if (!e.target || !e.target.classList.contains('cf-form')) { return; }
            e.preventDefault();
            submitForm(e.target);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
