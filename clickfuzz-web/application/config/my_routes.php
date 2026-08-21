<?php
defined('BASEPATH') or exit('No direct script access allowed');

// PitchSnap public routes (standard CI3/MX format: module/controller/method).
// Module routes file (modules/pitchsnap/config/routes.php) is the canonical source;
// these serve as a guaranteed fallback via the application/config/routes.php include mechanism.
$route['pitchsnap/intake']     = 'pitchsnap/pitchsnap_intake/request';
$route['pitchsnap/runtime.js'] = 'pitchsnap/pitchsnap_runtime/script';
$route['pitchsnap/runtime']    = 'pitchsnap/pitchsnap_runtime/script';
$route['pitchsnap/chat']       = 'pitchsnap/pitchsnap_runtime/chat';
