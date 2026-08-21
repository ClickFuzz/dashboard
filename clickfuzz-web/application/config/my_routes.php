<?php
defined('BASEPATH') or exit('No direct script access allowed');

// PitchSnap public routes (standard CI3/MX format: module/controller/method).
// Module routes file (modules/pitchsnap/config/routes.php) is the canonical source;
// these serve as a guaranteed fallback via the application/config/routes.php include mechanism.
$route['pitchsnap/intake']     = 'pitchsnap/pitchsnap_intake/request';
$route['pitchsnap/runtime.js'] = 'pitchsnap/pitchsnap_runtime/script';
$route['pitchsnap/runtime']    = 'pitchsnap/pitchsnap_runtime/script';
$route['pitchsnap/chat']       = 'pitchsnap/pitchsnap_runtime/chat';
$route['pitchsnap/purchase']                     = 'pitchsnap/pitchsnap_runtime/purchase';
$route['pitchsnap/agreement/(:any)']             = 'pitchsnap/pitchsnap_runtime/agreement/$1';
$route['pitchsnap/accept_agreement']             = 'pitchsnap/pitchsnap_runtime/accept_agreement';
$route['pitchsnap/subscription_complete/(:any)'] = 'pitchsnap/pitchsnap_runtime/subscription_complete/$1';
$route['pitchsnap/track_view/(:any)']            = 'pitchsnap/pitchsnap_runtime/track_view/$1';
