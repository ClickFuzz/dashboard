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
$route['pitchsnap/subscription_complete/(:any)']        = 'pitchsnap_runtime/subscription_complete/$1';
$route['pitchsnap/payment_complete/(:num)/(:any)']      = 'pitchsnap_runtime/payment_complete/$1/$2';
$route['pitchsnap/track_view/(:any)']                   = 'pitchsnap_runtime/track_view/$1';

$route['pitchsnap/redesigns']  = 'pitchsnap/websites';

// Forms — public runtime endpoints
$route['pitchsnap/form_render/(:num)'] = 'pitchsnap_runtime/form_render/$1';
$route['pitchsnap/form_submit']        = 'pitchsnap_runtime/form_submit';

// Forms — admin actions
$route['pitchsnap/form_save/(:num)']               = 'pitchsnap/form_save/$1';
$route['pitchsnap/form_delete/(:num)']             = 'pitchsnap/form_delete/$1';
$route['pitchsnap/form_placements_json/(:num)']    = 'pitchsnap/form_placements_json/$1';
$route['pitchsnap/form_placement_add/(:num)']      = 'pitchsnap/form_placement_add/$1';
$route['pitchsnap/form_placement_remove/(:num)']   = 'pitchsnap/form_placement_remove/$1';

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

// Phase 5 — page publishing
$route['pitchsnap/page_publish/(:num)'] = 'pitchsnap/page_publish/$1';

// WordPress Connector — public callback (WP plugin POSTs token + site_url here)
$route['pitchsnap/wp_pair_callback']             = 'pitchsnap_runtime/wp_pair_callback';
// WordPress Connector — signed one-time theme download (WP pulls ZIP from here)
$route['pitchsnap/wp_theme_download/(:any)']     = 'pitchsnap_runtime/wp_theme_download/$1';

// WordPress Connector — admin actions
$route['pitchsnap/generate_wp_token/(:num)']   = 'pitchsnap/generate_wp_token/$1';
$route['pitchsnap/download_wp_plugin/(:num)']  = 'pitchsnap/download_wp_plugin/$1';
$route['pitchsnap/reset_wp_connector/(:num)']  = 'pitchsnap/reset_wp_connector/$1';
$route['pitchsnap/test_wp_connection/(:num)']  = 'pitchsnap/test_wp_connection/$1';
$route['pitchsnap/deploy_to_wordpress/(:num)'] = 'pitchsnap/deploy_to_wordpress/$1';
$route['pitchsnap/redeploy_wp_theme/(:num)']   = 'pitchsnap/redeploy_wp_theme/$1';
$route['pitchsnap/reimport_wp_content/(:num)'] = 'pitchsnap/reimport_wp_content/$1';
$route['pitchsnap/export_wordpress/(:num)']    = 'pitchsnap/export_wordpress/$1';
$route['pitchsnap/download_wordpress/(:num)']  = 'pitchsnap/download_wordpress/$1';
$route['pitchsnap/update_wp_plugin/(:num)']    = 'pitchsnap/update_wp_plugin/$1';
