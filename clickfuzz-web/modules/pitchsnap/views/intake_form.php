<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Free Website Preview</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f6fa; color: #333; }
        .wrap { max-width: 540px; margin: 48px auto; padding: 0 16px; }
        h1 { font-size: 1.6rem; font-weight: 700; margin-bottom: 6px; }
        .sub { color: #666; margin-bottom: 32px; font-size: .95rem; }
        .card { background: #fff; border-radius: 10px; box-shadow: 0 2px 12px rgba(0,0,0,.08); padding: 32px; }
        .field { margin-bottom: 18px; }
        label { display: block; font-size: .85rem; font-weight: 600; margin-bottom: 5px; color: #444; }
        input, select, textarea {
            width: 100%; padding: 10px 12px; font-size: .95rem;
            border: 1px solid #d0d5dd; border-radius: 6px;
            background: #fff; color: #333; outline: none; transition: border .2s;
        }
        input:focus, select:focus, textarea:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.1); }
        textarea { resize: vertical; min-height: 80px; }
        .optional { font-weight: 400; color: #999; font-size: .8rem; }
        .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .btn {
            display: block; width: 100%; padding: 13px;
            background: #2563eb; color: #fff; border: none; border-radius: 6px;
            font-size: 1rem; font-weight: 600; cursor: pointer; margin-top: 8px;
            transition: background .2s;
        }
        .btn:hover { background: #1d4ed8; }
        .note { font-size: .78rem; color: #999; text-align: center; margin-top: 14px; }
        @media (max-width: 480px) { .row2 { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="wrap">
    <h1>Free Website Preview</h1>
    <p class="sub">See what your new site could look like — no commitment required.</p>
    <div class="card">
        <?php
        $CI =& get_instance();
        $csrf_name  = $CI->security->get_csrf_token_name();
        $csrf_hash  = $CI->security->get_csrf_hash();
        ?>
        <form method="post" action="<?php echo site_url('pitchsnap/intake'); ?>" novalidate>
            <input type="hidden" name="<?php echo $csrf_name; ?>" value="<?php echo $csrf_hash; ?>">

            <div class="row2">
                <div class="field">
                    <label for="ps_name">Full Name</label>
                    <input type="text" id="ps_name" name="name" maxlength="250" required autocomplete="name">
                </div>
                <div class="field">
                    <label for="ps_company">Company <span class="optional">(optional)</span></label>
                    <input type="text" id="ps_company" name="company" maxlength="250" autocomplete="organization">
                </div>
            </div>

            <div class="row2">
                <div class="field">
                    <label for="ps_email">Email Address</label>
                    <input type="email" id="ps_email" name="email" required autocomplete="email">
                </div>
                <div class="field">
                    <label for="ps_phone">Phone Number</label>
                    <input type="tel" id="ps_phone" name="phone" required autocomplete="tel">
                </div>
            </div>

            <div class="field">
                <label for="ps_website">Current Website URL</label>
                <input type="url" id="ps_website" name="website" placeholder="https://yourbusiness.com" required>
            </div>

            <div class="field">
                <label for="ps_vertical">Industry</label>
                <select id="ps_vertical" name="vertical" required>
                    <option value="">Select your industry...</option>
                    <option value="hvac">HVAC</option>
                    <option value="plumbing">Plumbing</option>
                    <option value="roofing">Roofing</option>
                    <option value="electrical">Electrical</option>
                    <option value="general_contractor">General Contractor</option>
                    <option value="remodeling">Remodeling</option>
                    <option value="landscaping">Landscaping</option>
                    <option value="tree_service">Tree Service</option>
                    <option value="pest_control">Pest Control</option>
                    <option value="garage_door">Garage Door</option>
                    <option value="painting">Painting</option>
                    <option value="flooring">Flooring</option>
                    <option value="concrete">Concrete</option>
                    <option value="fencing">Fencing</option>
                    <option value="pool_spa">Pool &amp; Spa</option>
                    <option value="restoration">Restoration</option>
                    <option value="cleaning">Cleaning</option>
                    <option value="auto_repair">Auto Repair</option>
                    <option value="towing">Towing</option>
                    <option value="moving">Moving</option>
                    <option value="locksmith">Locksmith</option>
                    <option value="solar">Solar</option>
                    <option value="real_estate">Real Estate</option>
                    <option value="mortgage">Mortgage</option>
                    <option value="insurance">Insurance</option>
                    <option value="law_firm">Law Firm</option>
                    <option value="accounting">Accounting</option>
                    <option value="dental">Dental</option>
                    <option value="chiropractic">Chiropractic</option>
                    <option value="med_spa">Med Spa</option>
                    <option value="veterinary">Veterinary</option>
                    <option value="other">Other</option>
                </select>
            </div>

            <div class="field" id="ps_other_field" style="display:none;">
                <label for="ps_vertical_other">What type of business?</label>
                <input type="text" id="ps_vertical_other" name="vertical_other" maxlength="100" placeholder="e.g. Dog Groomer">
            </div>

            <div class="row2">
                <div class="field">
                    <label for="ps_role">Your Role <span class="optional">(optional)</span></label>
                    <select id="ps_role" name="role">
                        <option value="">— Select —</option>
                        <option>Owner</option>
                        <option>General Manager</option>
                        <option>Marketing Manager</option>
                        <option>Office Manager</option>
                        <option>Other</option>
                    </select>
                </div>
                <div class="field">
                    <label for="ps_size">Company Size <span class="optional">(optional)</span></label>
                    <select id="ps_size" name="company_size">
                        <option value="">— Select —</option>
                        <option>1–5 employees</option>
                        <option>6–20 employees</option>
                        <option>21–50 employees</option>
                        <option>51+ employees</option>
                    </select>
                </div>
            </div>

            <div class="field">
                <label for="ps_improvement">What would you most like to improve? <span class="optional">(optional)</span></label>
                <textarea id="ps_improvement" name="desired_improvement" maxlength="1000" placeholder="More leads, better design, mobile-friendly…"></textarea>
            </div>

            <input type="hidden" name="source" value="Meta / Free HVAC Redesign">

            <button type="submit" class="btn">Get My Free Website Preview</button>
        </form>
        <p class="note">No credit card. No spam. Just your new website.</p>
    </div>
</div>
<script>
(function () {
    var validIndustries = [
        'hvac','plumbing','roofing','electrical','general_contractor',
        'remodeling','landscaping','tree_service','pest_control','garage_door',
        'painting','flooring','concrete','fencing','pool_spa','restoration',
        'cleaning','auto_repair','towing','moving','locksmith','solar',
        'real_estate','mortgage','insurance','law_firm','accounting','dental',
        'chiropractic','med_spa','veterinary','other'
    ];

    var sel        = document.getElementById('ps_vertical');
    var otherDiv   = document.getElementById('ps_other_field');
    var otherInput = document.getElementById('ps_vertical_other');

    function toggleOther(show) {
        otherDiv.style.display = show ? '' : 'none';
        otherInput.required    = show;
        if (!show) { otherInput.value = ''; }
    }

    // Preselect from ?industry= URL parameter
    try {
        var params   = new URLSearchParams(window.location.search);
        var industry = (params.get('industry') || '').toLowerCase().replace(/-/g, '_');
        if (industry && validIndustries.indexOf(industry) !== -1) {
            sel.value = industry;
            toggleOther(industry === 'other');
        }
    } catch (e) {}

    sel.addEventListener('change', function () {
        toggleOther(this.value === 'other');
    });
}());
</script>
</body>
</html>
