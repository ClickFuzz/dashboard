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

// Phase 3 — page management
$route['pitchsnap/page_add/(:num)']          = 'pitchsnap/page_add/$1';
$route['pitchsnap/page_edit/(:num)']         = 'pitchsnap/page_edit/$1';
$route['pitchsnap/page_save/(:num)']         = 'pitchsnap/page_save/$1';
$route['pitchsnap/page_trash/(:num)']        = 'pitchsnap/page_trash/$1';
$route['pitchsnap/page_restore/(:num)']      = 'pitchsnap/page_restore/$1';

// Phase 3 — media library
$route['pitchsnap/media_upload/(:num)']      = 'pitchsnap/media_upload/$1';
$route['pitchsnap/media_save/(:num)']        = 'pitchsnap/media_save/$1';
$route['pitchsnap/media_delete/(:num)']      = 'pitchsnap/media_delete/$1';
$route['pitchsnap/media_json/(:num)']        = 'pitchsnap/media_json/$1';

// Phase 3 — page ↔ media relationships
$route['pitchsnap/page_media_attach/(:num)'] = 'pitchsnap/page_media_attach/$1';
$route['pitchsnap/page_media_detach/(:num)'] = 'pitchsnap/page_media_detach/$1';

// Phase 4 — page AI generation
$route['pitchsnap/page_generate/(:num)']               = 'pitchsnap/page_generate/$1';
$route['pitchsnap/page_preview/(:num)']                = 'pitchsnap/page_preview/$1';
$route['pitchsnap/page_generation_set_current/(:num)'] = 'pitchsnap/page_generation_set_current/$1';
