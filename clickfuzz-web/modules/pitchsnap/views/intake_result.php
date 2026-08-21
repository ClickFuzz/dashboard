<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $success ? 'Request Received' : 'Submission Error'; ?> — PitchSnap</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f6fa; color: #333; }
        .wrap { max-width: 480px; margin: 80px auto; padding: 0 16px; text-align: center; }
        .icon { font-size: 3rem; margin-bottom: 16px; }
        h1 { font-size: 1.5rem; font-weight: 700; margin-bottom: 10px; }
        p { color: #555; line-height: 1.6; margin-bottom: 0; }
        .card { background: #fff; border-radius: 10px; box-shadow: 0 2px 12px rgba(0,0,0,.08); padding: 40px 32px; }
        .back { display: inline-block; margin-top: 24px; font-size: .9rem; color: #2563eb; text-decoration: none; }
        .back:hover { text-decoration: underline; }
        .ref { margin-top: 16px; font-size: .8rem; color: #aaa; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <?php if ($success) { ?>
        <div class="icon">&#10003;</div>
        <h1>You're on the list!</h1>
        <p>We received your request and will have a preview of your new website ready shortly. Keep an eye on your inbox.</p>
        <?php if ($website_id) { ?>
        <p class="ref">Reference: #<?php echo (int) $website_id; ?></p>
        <?php } ?>
        <?php } else { ?>
        <div class="icon">&#9888;</div>
        <h1>Something went wrong</h1>
        <p><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
        <a href="javascript:history.back()" class="back">&larr; Go back and try again</a>
        <?php } ?>
    </div>
</div>
</body>
</html>
