<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Module routes — values must NOT include the module prefix.
// Modules::parse_routes() prepends '$module/' automatically before locating controllers.

$route['pitchsnap/intake']     = 'pitchsnap_intake/request';
$route['pitchsnap/runtime.js'] = 'pitchsnap_runtime/script';
$route['pitchsnap/runtime']    = 'pitchsnap_runtime/script';
$route['pitchsnap/chat']       = 'pitchsnap_runtime/chat';
$route['pitchsnap/purchase']          = 'pitchsnap_runtime/purchase';
$route['pitchsnap/agreement/(:any)'] = 'pitchsnap_runtime/agreement/$1';
$route['pitchsnap/accept_agreement']             = 'pitchsnap_runtime/accept_agreement';
$route['pitchsnap/subscription_complete/(:any)'] = 'pitchsnap_runtime/subscription_complete/$1';
$route['pitchsnap/track_view/(:any)']            = 'pitchsnap_runtime/track_view/$1';

$route['pitchsnap/redesigns']  = 'pitchsnap/websites';
