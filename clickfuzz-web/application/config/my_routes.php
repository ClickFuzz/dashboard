<?php
defined('BASEPATH') or exit('No direct script access allowed');

// PitchSnap public routes (standard CI3/MX format: module/controller/method).
// Module routes file (modules/pitchsnap/config/routes.php) is the canonical source;
// these serve as a guaranteed fallback via the application/config/routes.php include mechanism.
$route['pitchsnap/intake']     = 'pitchsnap/pitchsnap_intake/request';
$route['pitchsnap/generation_brief/(:any)'] = 'pitchsnap/pitchsnap_runtime/generation_brief/$1';
$route['pitchsnap/runtime.js'] = 'pitchsnap/pitchsnap_runtime/script';
$route['pitchsnap/runtime']    = 'pitchsnap/pitchsnap_runtime/script';
$route['pitchsnap/chat']       = 'pitchsnap/pitchsnap_runtime/chat';
$route['pitchsnap/purchase']                     = 'pitchsnap/pitchsnap_runtime/purchase';
$route['pitchsnap/agreement/(:any)']             = 'pitchsnap/pitchsnap_runtime/agreement/$1';
$route['pitchsnap/accept_agreement']             = 'pitchsnap/pitchsnap_runtime/accept_agreement';
$route['pitchsnap/subscription_complete/(:any)'] = 'pitchsnap/pitchsnap_runtime/subscription_complete/$1';
$route['pitchsnap/track_view/(:any)']            = 'pitchsnap/pitchsnap_runtime/track_view/$1';

// Phase 3 — page management fallbacks
$route['pitchsnap/page_add/(:num)']          = 'pitchsnap/pitchsnap/page_add/$1';
$route['pitchsnap/page_edit/(:num)']         = 'pitchsnap/pitchsnap/page_edit/$1';
$route['pitchsnap/page_save/(:num)']         = 'pitchsnap/pitchsnap/page_save/$1';
$route['pitchsnap/page_trash/(:num)']        = 'pitchsnap/pitchsnap/page_trash/$1';
$route['pitchsnap/page_restore/(:num)']      = 'pitchsnap/pitchsnap/page_restore/$1';
$route['pitchsnap/media_upload/(:num)']      = 'pitchsnap/pitchsnap/media_upload/$1';
$route['pitchsnap/media_save/(:num)']        = 'pitchsnap/pitchsnap/media_save/$1';
$route['pitchsnap/media_delete/(:num)']      = 'pitchsnap/pitchsnap/media_delete/$1';
$route['pitchsnap/media_json/(:num)']        = 'pitchsnap/pitchsnap/media_json/$1';
$route['pitchsnap/page_media_attach/(:num)'] = 'pitchsnap/pitchsnap/page_media_attach/$1';
$route['pitchsnap/page_media_detach/(:num)'] = 'pitchsnap/pitchsnap/page_media_detach/$1';
$route['pitchsnap/page_generate/(:num)']               = 'pitchsnap/pitchsnap/page_generate/$1';
$route['pitchsnap/page_preview/(:num)']                = 'pitchsnap/pitchsnap/page_preview/$1';
$route['pitchsnap/page_generation_set_current/(:num)'] = 'pitchsnap/pitchsnap/page_generation_set_current/$1';
$route['pitchsnap/page_publish/(:num)']                = 'pitchsnap/pitchsnap/page_publish/$1';

// Forms — public runtime fallbacks
$route['pitchsnap/form_render/(:num)'] = 'pitchsnap/pitchsnap_runtime/form_render/$1';
$route['pitchsnap/form_submit']        = 'pitchsnap/pitchsnap_runtime/form_submit';

// Forms — admin action fallbacks
$route['pitchsnap/form_save/(:num)']              = 'pitchsnap/pitchsnap/form_save/$1';
$route['pitchsnap/form_delete/(:num)']            = 'pitchsnap/pitchsnap/form_delete/$1';
$route['pitchsnap/form_placements_json/(:num)']   = 'pitchsnap/pitchsnap/form_placements_json/$1';
$route['pitchsnap/form_placement_add/(:num)']     = 'pitchsnap/pitchsnap/form_placement_add/$1';
$route['pitchsnap/form_placement_remove/(:num)']  = 'pitchsnap/pitchsnap/form_placement_remove/$1';
