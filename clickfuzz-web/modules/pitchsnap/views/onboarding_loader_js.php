<?php
// Served as application/javascript by Pitchsnap_runtime::onboarding_loader_js()
// $embed_url injected by the controller
?>(function () {
    'use strict';

    var EMBED_URL = '<?php echo addslashes($embed_url); ?>';

    function _getToken() {
        var m = window.location.search.match(/[?&]token=([a-f0-9]{64})/i);
        return m ? m[1] : '';
    }

    function _mount() {
        var container = document.getElementById('clickfuzz-onboarding');
        if (!container) { return; }

        var token = _getToken();
        if (!token) {
            container.innerHTML = '<p style="text-align:center;padding:48px 16px;color:#666;font-family:sans-serif;">This onboarding link is invalid or has expired.</p>';
            return;
        }

        var iframe = document.createElement('iframe');
        iframe.src             = EMBED_URL + '?token=' + encodeURIComponent(token);
        iframe.style.display   = 'block';
        iframe.style.width     = '100%';
        iframe.style.border    = 'none';
        iframe.style.background = 'transparent';
        iframe.style.height    = '500px';
        iframe.style.overflow  = 'hidden';
        iframe.setAttribute('allowtransparency', 'true');
        iframe.setAttribute('scrolling', 'no');
        iframe.setAttribute('title', 'Customer Onboarding');

        // Resize iframe to content height on load (same-origin fallback)
        iframe.addEventListener('load', function() {
            try {
                var h = iframe.contentWindow.document.body.scrollHeight;
                if (h > 0) { iframe.style.height = h + 'px'; }
            } catch(e) {}
        });

        // Resize on step changes via postMessage from wizard
        window.addEventListener('message', function(e) {
            if (e.data && e.data.cfwObHeight) {
                iframe.style.height = e.data.cfwObHeight + 'px';
            }
        });

        container.style.display = 'block';
        container.appendChild(iframe);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', _mount);
    } else {
        _mount();
    }
}());
