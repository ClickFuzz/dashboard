<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>PitchSnap Service Agreement</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            font-size: 15px;
            line-height: 1.6;
            background: #f0f2f8;
            color: #1a1f3c;
            min-height: 100vh;
            padding: 32px 16px 64px;
        }
        .ps-page {
            max-width: 720px;
            margin: 0 auto;
        }
        .ps-header {
            background: linear-gradient(135deg, #2d3561 0%, #1a2050 100%);
            border-radius: 16px 16px 0 0;
            padding: 24px 32px 20px;
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .ps-logo {
            width: 40px; height: 40px; border-radius: 50%;
            background: rgba(255,255,255,.15);
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .ps-header-text h1 {
            color: #fff;
            font-size: 18px;
            font-weight: 700;
            line-height: 1.2;
        }
        .ps-header-text p {
            color: rgba(255,255,255,.6);
            font-size: 12px;
            margin-top: 2px;
        }
        .ps-card {
            background: #fff;
            border-radius: 0 0 16px 16px;
            box-shadow: 0 8px 40px rgba(0,0,0,.12);
            padding: 32px;
        }
        .ps-version-tag {
            display: inline-block;
            background: #eef0fa;
            color: #2d3561;
            font-size: 11px;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 20px;
            margin-bottom: 20px;
            letter-spacing: .3px;
            text-transform: uppercase;
        }
        .ps-agreement-box {
            border: 1.5px solid #e0e4f0;
            border-radius: 10px;
            background: #f9fafc;
            padding: 20px 24px;
            max-height: 420px;
            overflow-y: auto;
            font-size: 13px;
            line-height: 1.75;
            color: #2a3050;
            white-space: pre-wrap;
            margin-bottom: 24px;
        }
        .ps-agreement-box::-webkit-scrollbar { width: 6px; }
        .ps-agreement-box::-webkit-scrollbar-track { background: #eee; border-radius: 3px; }
        .ps-agreement-box::-webkit-scrollbar-thumb { background: #bbb; border-radius: 3px; }
        .ps-divider { border: none; border-top: 1.5px solid #e8ecf5; margin: 24px 0; }
        .ps-checkbox-row {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 24px;
        }
        .ps-checkbox-row input[type="checkbox"] {
            width: 18px; height: 18px;
            margin-top: 2px;
            flex-shrink: 0;
            cursor: pointer;
            accent-color: #2d3561;
        }
        .ps-checkbox-row label {
            font-size: 14px;
            color: #1a1f3c;
            cursor: pointer;
            line-height: 1.5;
        }
        .ps-btn {
            display: block;
            width: 100%;
            background: linear-gradient(135deg, #2d3561 0%, #1a2050 100%);
            color: #fff;
            border: none;
            border-radius: 12px;
            padding: 16px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
            transition: opacity .15s, transform .1s;
            text-align: center;
        }
        .ps-btn:hover:not(:disabled) { opacity: .9; transform: translateY(-1px); }
        .ps-btn:disabled { opacity: .55; cursor: not-allowed; transform: none; }
        .ps-error {
            margin-top: 14px;
            padding: 12px 16px;
            background: #fdf0f0;
            border: 1px solid #f5c6c6;
            border-radius: 8px;
            color: #c0392b;
            font-size: 13px;
            line-height: 1.5;
        }
        .ps-footer {
            text-align: center;
            color: #aab;
            font-size: 12px;
            margin-top: 20px;
        }
        @media (max-width: 500px) {
            .ps-card { padding: 20px 16px; }
            .ps-header { padding: 18px 20px 16px; }
        }
    </style>
</head>
<body>
<div class="ps-page">
    <div class="ps-header">
        <div class="ps-logo">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z" fill="#fff"/>
            </svg>
        </div>
        <div class="ps-header-text">
            <h1>PitchSnap Service Agreement</h1>
            <p>Review and accept the terms before completing your purchase</p>
        </div>
    </div>

    <div class="ps-card">
        <span class="ps-version-tag">Version <?php echo htmlspecialchars($version, ENT_QUOTES, 'UTF-8'); ?></span>

        <div class="ps-agreement-box" id="ps-agreement-text"><?php echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); ?></div>

        <hr class="ps-divider">

        <div class="ps-checkbox-row">
            <input type="checkbox" id="ps-agree-check">
            <label for="ps-agree-check">
                I have read and agree to the PitchSnap Service Agreement, including the 12-month commitment at $295/month.
            </label>
        </div>

        <button class="ps-btn" id="ps-agree-btn" type="button">
            Agree &amp; Continue to Payment
        </button>

        <div class="ps-error" id="ps-error" style="display:none;"></div>
    </div>

    <p class="ps-footer">PitchSnap &mdash; Secure checkout powered by your existing account</p>
</div>

<script>
(function () {
    var btn    = document.getElementById('ps-agree-btn');
    var check  = document.getElementById('ps-agree-check');
    var errEl  = document.getElementById('ps-error');
    var token  = <?php echo json_encode($token); ?>;
    var postUrl = <?php echo json_encode($accept_url); ?>;

    function showError(msg) {
        errEl.textContent = msg;
        errEl.style.display = 'block';
    }
    function hideError() {
        errEl.style.display = 'none';
    }

    btn.addEventListener('click', function () {
        hideError();

        if (!check.checked) {
            showError('Please check the box to confirm you have read and agree to the Service Agreement.');
            return;
        }

        btn.disabled    = true;
        btn.textContent = 'Processing…';

        var xhr = new XMLHttpRequest();
        xhr.open('POST', postUrl, true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) { return; }
            var data = {};
            try { data = JSON.parse(xhr.responseText); } catch (e) {}
            if (xhr.status === 200 && data.success && data.redirect) {
                btn.textContent = 'Redirecting to payment…';
                window.location.href = data.redirect;
            } else {
                btn.disabled    = false;
                btn.textContent = 'Agree & Continue to Payment';
                showError(data.error || 'Something went wrong. Please try again.');
            }
        };
        xhr.send(JSON.stringify({ site_token: token, accepted: true }));
    });
}());
</script>
</body>
</html>
