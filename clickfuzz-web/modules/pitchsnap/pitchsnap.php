<?php
defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: ClickFuzz Web
Description: HVAC website intake and pipeline management
Version: 3.0.0
Requires at least: 3.0.*
Author: ClickFuzz
*/

require_once __DIR__ . '/helpers/pitchsnap_scraper_helper.php';
require_once __DIR__ . '/helpers/pitchsnap_generation_helper.php';
require_once __DIR__ . '/helpers/pitchsnap_cron_helper.php';

register_activation_hook('pitchsnap', 'clickfuzz_web_activation');

function clickfuzz_web_activation()
{
    $CI =& get_instance();
    $CI->load->database();
    $p = db_prefix();

    $CI->db->query("
        CREATE TABLE IF NOT EXISTS `{$p}pitchsnap_conversations` (
            `id`             INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `redesign_id`    INT(11) NOT NULL,
            `quick_reply`    VARCHAR(50) NOT NULL,
            `change_request` TEXT DEFAULT NULL,
            `ip_address`     VARCHAR(45) DEFAULT NULL,
            `created_at`     DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_ps_conv_redesign` (`redesign_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $CI->db->query("
        CREATE TABLE IF NOT EXISTS `{$p}pitchsnap_redesigns` (
            `id`                   INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `lead_id`              INT(11) NOT NULL,
            `parent_redesign_id`   INT(11) DEFAULT NULL,
            `original_url`         VARCHAR(500) DEFAULT NULL,
            `vertical`             VARCHAR(50) NOT NULL DEFAULT 'hvac',
            `status`               VARCHAR(50) NOT NULL DEFAULT 'new',
            `provider`             VARCHAR(100) DEFAULT NULL,
            `model_used`           VARCHAR(100) DEFAULT NULL,
            `provider_project_id`  VARCHAR(255) DEFAULT NULL,
            `provider_website_id`  VARCHAR(255) DEFAULT NULL,
            `generation_prompt`    TEXT DEFAULT NULL,
            `generation_result`    LONGTEXT DEFAULT NULL,
            `preview_url`          VARCHAR(500) DEFAULT NULL,
            `preview_token`        VARCHAR(64) DEFAULT NULL,
            `generated_at`         DATETIME DEFAULT NULL,
            `approved_at`          DATETIME DEFAULT NULL,
            `approved_by`          INT(11) DEFAULT NULL,
            `sent_at`              DATETIME DEFAULT NULL,
            `first_viewed_at`      DATETIME DEFAULT NULL,
            `last_viewed_at`       DATETIME DEFAULT NULL,
            `first_device_type`    VARCHAR(20) DEFAULT NULL,
            `last_device_type`     VARCHAR(20) DEFAULT NULL,
            `prospect_notified_at` DATETIME DEFAULT NULL,
            `admin_notified_at`    DATETIME DEFAULT NULL,
            `view_count`           INT(11) NOT NULL DEFAULT 0,
            `generation_error`     TEXT DEFAULT NULL,
            `intake_role`          VARCHAR(100) DEFAULT NULL,
            `intake_company_size`  VARCHAR(100) DEFAULT NULL,
            `intake_improvement`   TEXT DEFAULT NULL,
            `addedfrom`            INT(11) NOT NULL DEFAULT 0,
            `dateadded`            DATETIME NOT NULL,
            `dateupdated`          DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_preview_token` (`preview_token`),
            KEY `idx_lead_id` (`lead_id`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $CI->db->query("
        CREATE TABLE IF NOT EXISTS `{$p}pitchsnap_sites` (
            `id`                    INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `client_id`             INT(11) DEFAULT NULL,
            `source_website_id`     INT(11) NOT NULL,
            `source_lead_id`        INT(11) DEFAULT NULL,
            `site_token`            VARCHAR(64) NOT NULL,
            `domain`                VARCHAR(500) DEFAULT NULL,
            `status`                VARCHAR(50) NOT NULL DEFAULT 'draft',
            `invoice_id`            INT(11) DEFAULT NULL,
            `subscription_id`       INT(11) DEFAULT NULL,
            `sub_email_last_status` VARCHAR(50) DEFAULT NULL,
            `dateadded`             DATETIME NOT NULL,
            `dateupdated`           DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_site_token` (`site_token`),
            UNIQUE KEY `uq_source_website` (`source_website_id`),
            KEY `idx_client_id` (`client_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    add_option('pitchsnap_default_source',    'Meta / Free HVAC Redesign');
    add_option('pitchsnap_primary_provider',  'manus');
    add_option('pitchsnap_fallback_provider', 'anthropic');
    add_option('pitchsnap_manus_api_key',     '');
    add_option('pitchsnap_manus_prompt',      clickfuzz_web_manus_default_prompt());
    add_option('pitchsnap_ai_provider',       'anthropic');
    add_option('pitchsnap_video_demo_url',    '');
    add_option('pitchsnap_web_design_admin',  '');
    add_option('pitchsnap_model',             'claude-sonnet-4-6');
    add_option('pitchsnap_generation_prompt', clickfuzz_web_default_prompt());

    $guardrails = ['logo_usage','image_selection','team_placement','team_association','anonymous_team','gallery_usage','credential_usage','owner_story','visual_readability'];
    foreach ($guardrails as $gk) {
        add_option('pitchsnap_guardrail_anthropic_' . $gk, '1');
        add_option('pitchsnap_guardrail_manus_'     . $gk, '0');
    }
}

hooks()->add_action('admin_init',            'clickfuzz_web_add_menu_items');
hooks()->add_action('admin_init',            'clickfuzz_web_db_upgrade');
hooks()->add_action('before_cron_run',       'clickfuzz_web_cron_run');
hooks()->add_action('after_email_templates', 'clickfuzz_web_email_templates_section');
hooks()->add_action('after_lead_lead_tabs',    'clickfuzz_web_lead_tab');
hooks()->add_action('after_lead_tabs_content', 'clickfuzz_web_lead_tab_content');
hooks()->add_action('admin_navbar_start',      'clickfuzz_web_db_version_badge');

// ---------------------------------------------------------------------------
// Menu
// ---------------------------------------------------------------------------

function clickfuzz_web_add_menu_items()
{
    $CI =& get_instance();

    $CI->app_menu->add_sidebar_menu_item('pitchsnap', [
        'name'     => 'ClickFuzz Web',
        'icon'     => 'fa fa-magic',
        'href'     => admin_url('pitchsnap/websites'),
        'position' => 7,
    ]);

    $CI->app_menu->add_sidebar_children_item('pitchsnap', [
        'slug'     => 'pitchsnap_redesigns',
        'name'     => 'Websites',
        'href'     => admin_url('pitchsnap/websites'),
        'icon'     => 'fa fa-list',
        'position' => 1,
    ]);

    $CI->app_menu->add_sidebar_children_item('pitchsnap', [
        'slug'     => 'pitchsnap_onboarding_flows',
        'name'     => 'Onboarding Flows',
        'href'     => admin_url('pitchsnap/flows'),
        'icon'     => 'fa fa-list-ol',
        'position' => 2,
    ]);

    $CI->app_menu->add_sidebar_children_item('pitchsnap', [
        'slug'     => 'pitchsnap_usage_tags',
        'name'     => 'Usage Tags',
        'href'     => admin_url('pitchsnap/usage_tags'),
        'icon'     => 'fa fa-tags',
        'position' => 3,
    ]);

    $CI->app_menu->add_sidebar_children_item('pitchsnap', [
        'slug'     => 'pitchsnap_settings',
        'name'     => 'Settings',
        'href'     => admin_url('pitchsnap/settings'),
        'icon'     => 'fa fa-cog',
        'position' => 4,
    ]);
}

// ---------------------------------------------------------------------------
// DB migration (runs on every admin_init, idempotent)
// ---------------------------------------------------------------------------

function clickfuzz_web_db_upgrade()
{
    // Version gate: skip all schema/settings checks once already up to date.
    if ((int) get_option('pitchsnap_db_version') >= 38) {
        return;
    }

    $CI =& get_instance();
    $t  = db_prefix() . 'pitchsnap_redesigns';
    $tc = db_prefix() . 'pitchsnap_conversations';

    if (!$CI->db->table_exists($t)) {
        return;
    }

    if (!$CI->db->table_exists($tc)) {
        $CI->db->query("
            CREATE TABLE IF NOT EXISTS `{$tc}` (
                `id`             INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `redesign_id`    INT(11) NOT NULL,
                `quick_reply`    VARCHAR(50) NOT NULL,
                `change_request` TEXT DEFAULT NULL,
                `ip_address`     VARCHAR(45) DEFAULT NULL,
                `created_at`     DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_ps_conv_redesign` (`redesign_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    $cols = [
        'parent_redesign_id'  => "ALTER TABLE `{$t}` ADD COLUMN `parent_redesign_id` INT(11) DEFAULT NULL AFTER `lead_id`",
        'model_used'          => "ALTER TABLE `{$t}` ADD COLUMN `model_used` VARCHAR(100) DEFAULT NULL AFTER `provider`",
        'provider_website_id' => "ALTER TABLE `{$t}` ADD COLUMN `provider_website_id` VARCHAR(255) DEFAULT NULL AFTER `provider_project_id`",
        'generation_result'   => "ALTER TABLE `{$t}` ADD COLUMN `generation_result` LONGTEXT DEFAULT NULL AFTER `generation_error`",
    ];

    foreach ($cols as $col => $sql) {
        if (!$CI->db->field_exists($col, $t)) {
            $CI->db->query($sql);
        }
    }

    // Seed settings that may be missing on upgrades from earlier phases
    $defaults = [
        'pitchsnap_primary_provider'  => 'manus',
        'pitchsnap_fallback_provider' => 'anthropic',
        'pitchsnap_manus_api_key'     => '',
        'pitchsnap_ai_provider'       => 'anthropic',
        'pitchsnap_model'             => 'claude-sonnet-4-6',
    ];
    foreach ($defaults as $key => $val) {
        if (!get_option($key)) {
            add_option($key, $val);
        }
    }
    if (!get_option('pitchsnap_manus_prompt')) {
        add_option('pitchsnap_manus_prompt', clickfuzz_web_manus_default_prompt());
    }
    if (!get_option('pitchsnap_generation_prompt')) {
        add_option('pitchsnap_generation_prompt', clickfuzz_web_default_prompt());
    }

    $CI->db->where('status', 'pending')->update($t, ['status' => 'new']);

    $guardrails = ['logo_usage','image_selection','team_placement','team_association','anonymous_team','gallery_usage','credential_usage','owner_story','visual_readability'];
    foreach ($guardrails as $gk) {
        if (get_option('pitchsnap_guardrail_anthropic_' . $gk) === false) {
            add_option('pitchsnap_guardrail_anthropic_' . $gk, '1');
        }
        if (get_option('pitchsnap_guardrail_manus_' . $gk) === false) {
            add_option('pitchsnap_guardrail_manus_' . $gk, '0');
        }
    }

    // v6: sites table + video demo url setting
    $ts = db_prefix() . 'pitchsnap_sites';
    if (!$CI->db->table_exists($ts)) {
        $CI->db->query("
            CREATE TABLE IF NOT EXISTS `{$ts}` (
                `id`                    INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `client_id`             INT(11) DEFAULT NULL,
                `source_website_id`     INT(11) NOT NULL,
                `source_lead_id`        INT(11) DEFAULT NULL,
                `site_token`            VARCHAR(64) NOT NULL,
                `domain`                VARCHAR(500) DEFAULT NULL,
                `status`                VARCHAR(50) NOT NULL DEFAULT 'draft',
                `invoice_id`            INT(11) DEFAULT NULL,
                `subscription_id`       INT(11) DEFAULT NULL,
                `sub_email_last_status` VARCHAR(50) DEFAULT NULL,
                `dateadded`             DATETIME NOT NULL,
                `dateupdated`           DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_site_token` (`site_token`),
                UNIQUE KEY `uq_source_website` (`source_website_id`),
                KEY `idx_client_id` (`client_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    if (!get_option('pitchsnap_video_demo_url')) {
        add_option('pitchsnap_video_demo_url', '');
    }

    // v7: agreements table + agreement settings
    $ta = db_prefix() . 'pitchsnap_agreements';
    if (!$CI->db->table_exists($ta)) {
        $CI->db->query("
            CREATE TABLE IF NOT EXISTS `{$ta}` (
                `id`                 INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_id`            INT(11) NOT NULL,
                `client_id`          INT(11) NOT NULL,
                `agreement_version`  VARCHAR(20) NOT NULL,
                `agreement_hash`     VARCHAR(64) NOT NULL,
                `accepted_at`        DATETIME NOT NULL,
                `ip_address`         VARCHAR(45) DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_site_agreement` (`site_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    if (!get_option('pitchsnap_agreement_version')) {
        add_option('pitchsnap_agreement_version', '1.0');
    }
    if (!get_option('pitchsnap_agreement_text')) {
        add_option('pitchsnap_agreement_text', clickfuzz_web_default_agreement_text());
    }

    // v8: activity log table + logging toggle
    if (!get_option('pitchsnap_logging_enabled')) {
        add_option('pitchsnap_logging_enabled', '1');
    }
    $tl = db_prefix() . 'pitchsnap_logs';
    if (!$CI->db->table_exists($tl)) {
        $CI->db->query("
            CREATE TABLE IF NOT EXISTS `{$tl}` (
                `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `context`    VARCHAR(50)  NOT NULL DEFAULT '',
                `message`    TEXT         NOT NULL,
                `data_json`  TEXT         DEFAULT NULL,
                `created_at` DATETIME     NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_ps_log_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    // v9: pricing settings + subscription flow
    $pricing_defaults = [
        'pitchsnap_payment_type'     => 'onetime',
        'pitchsnap_price'            => '295.00',
        'pitchsnap_stripe_plan_id'   => '',
        'pitchsnap_sub_quantity'     => '1',
        'pitchsnap_sub_name'         => 'ClickFuzz Web Monthly Service',
        'pitchsnap_sub_description'  => '',
        'pitchsnap_sub_include_desc' => '0',
        'pitchsnap_sub_currency'     => '',
        'pitchsnap_sub_tax1'         => '',
        'pitchsnap_sub_tax2'         => '',
    ];
    foreach ($pricing_defaults as $pkey => $pval) {
        if (get_option($pkey) === false) {
            add_option($pkey, $pval);
        }
    }

    if ($CI->db->table_exists($ts) && !$CI->db->field_exists('subscription_id', $ts)) {
        $CI->db->query("ALTER TABLE `{$ts}` ADD COLUMN `subscription_id` INT(11) DEFAULT NULL AFTER `invoice_id`");
    }

    // v10: approval tracking, view tracking, notification flags
    $tr = db_prefix() . 'pitchsnap_redesigns';
    if ($CI->db->table_exists($tr)) {
        $v10_redesign_cols = [
            'approved_by'          => "ALTER TABLE `{$tr}` ADD COLUMN `approved_by` INT(11) DEFAULT NULL AFTER `approved_at`",
            'last_viewed_at'       => "ALTER TABLE `{$tr}` ADD COLUMN `last_viewed_at` DATETIME DEFAULT NULL AFTER `first_viewed_at`",
            'first_device_type'    => "ALTER TABLE `{$tr}` ADD COLUMN `first_device_type` VARCHAR(20) DEFAULT NULL AFTER `last_viewed_at`",
            'last_device_type'     => "ALTER TABLE `{$tr}` ADD COLUMN `last_device_type` VARCHAR(20) DEFAULT NULL AFTER `first_device_type`",
            'prospect_notified_at' => "ALTER TABLE `{$tr}` ADD COLUMN `prospect_notified_at` DATETIME DEFAULT NULL AFTER `last_device_type`",
            'admin_notified_at'    => "ALTER TABLE `{$tr}` ADD COLUMN `admin_notified_at` DATETIME DEFAULT NULL AFTER `prospect_notified_at`",
        ];
        foreach ($v10_redesign_cols as $col => $sql) {
            if (!$CI->db->field_exists($col, $tr)) {
                $CI->db->query($sql);
            }
        }
    }

    $tsites = db_prefix() . 'pitchsnap_sites';
    if ($CI->db->table_exists($tsites) && !$CI->db->field_exists('sub_email_last_status', $tsites)) {
        $CI->db->query("ALTER TABLE `{$tsites}` ADD COLUMN `sub_email_last_status` VARCHAR(50) DEFAULT NULL AFTER `subscription_id`");
    }

    if (get_option('pitchsnap_web_design_admin') === false) {
        add_option('pitchsnap_web_design_admin', '');
    }

    clickfuzz_web_register_email_templates();

    // v11: primary version designation
    if ($CI->db->table_exists($tr) && !$CI->db->field_exists('is_primary', $tr)) {
        $CI->db->query("ALTER TABLE `{$tr}` ADD COLUMN `is_primary` TINYINT(1) NOT NULL DEFAULT 0 AFTER `lead_id`");
        // Seed: mark the highest-ID version per lead as primary
        $CI->db->query("
            UPDATE `{$tr}` r
            INNER JOIN (SELECT lead_id, MAX(id) AS max_id FROM `{$tr}` GROUP BY lead_id) m
                ON r.id = m.max_id
            SET r.is_primary = 1
        ");
    }

    // v12: site-domain mapping table
    $td = db_prefix() . 'pitchsnap_site_domains';
    if (!$CI->db->table_exists($td)) {
        $CI->db->query("
            CREATE TABLE IF NOT EXISTS `{$td}` (
                `id`          INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_id`     INT(11) NOT NULL,
                `hostname`    VARCHAR(255) NOT NULL,
                `domain_type` VARCHAR(50)  NOT NULL DEFAULT 'platform',
                `is_primary`  TINYINT(1)   NOT NULL DEFAULT 1,
                `status`      VARCHAR(50)  NOT NULL DEFAULT 'active',
                `dateadded`   DATETIME     NOT NULL,
                `dateupdated` DATETIME     DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_hostname` (`hostname`),
                KEY `idx_site_domains_site` (`site_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    // v13: verification + SSL status columns on site-domain table
    if ($CI->db->table_exists($td)) {
        if (!$CI->db->field_exists('verification_status', $td)) {
            $CI->db->query("ALTER TABLE `{$td}` ADD COLUMN `verification_status` VARCHAR(50) NOT NULL DEFAULT 'pending' AFTER `status`");
            // Existing platform rows are already live on *.clickfuzz.com — mark them verified.
            $CI->db->query("UPDATE `{$td}` SET `verification_status` = 'verified' WHERE `domain_type` = 'platform'");
        }
        if (!$CI->db->field_exists('verified_at', $td)) {
            $CI->db->query("ALTER TABLE `{$td}` ADD COLUMN `verified_at` DATETIME DEFAULT NULL AFTER `verification_status`");
            $CI->db->query("UPDATE `{$td}` SET `verified_at` = `dateadded` WHERE `domain_type` = 'platform' AND `verified_at` IS NULL");
        }
        if (!$CI->db->field_exists('ssl_status', $td)) {
            $CI->db->query("ALTER TABLE `{$td}` ADD COLUMN `ssl_status` VARCHAR(50) NOT NULL DEFAULT 'pending' AFTER `verified_at`");
            // Platform rows are covered by the *.clickfuzz.com wildcard cert.
            $CI->db->query("UPDATE `{$td}` SET `ssl_status` = 'active' WHERE `domain_type` = 'platform'");
        }
    }

    // v14: GHL location mapping table + API token option
    $tg = db_prefix() . 'pitchsnap_ghl_locations';
    if (!$CI->db->table_exists($tg)) {
        $CI->db->query("
            CREATE TABLE IF NOT EXISTS `{$tg}` (
                `id`                INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_id`           INT(11) NOT NULL,
                `ghl_location_id`   VARCHAR(50)  NOT NULL DEFAULT '',
                `ghl_location_name` VARCHAR(255) DEFAULT NULL,
                `status`            VARCHAR(20)  NOT NULL DEFAULT 'pending',
                `last_error`        VARCHAR(500) DEFAULT NULL,
                `last_verified_at`  DATETIME DEFAULT NULL,
                `created_at`        DATETIME NOT NULL,
                `updated_at`        DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_ghl_site` (`site_id`),
                KEY `idx_ghl_location` (`ghl_location_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }
    if (get_option('pitchsnap_ghl_api_key') === false) {
        add_option('pitchsnap_ghl_api_key', '');
    }

    // v15: publishing type + WordPress connection fields on sites table
    $tsites = db_prefix() . 'pitchsnap_sites';
    if ($CI->db->table_exists($tsites)) {
        if (!$CI->db->field_exists('publish_type', $tsites)) {
            $CI->db->query("ALTER TABLE `{$tsites}` ADD COLUMN `publish_type` VARCHAR(20) NOT NULL DEFAULT 'html' AFTER `status`");
        }
        if (!$CI->db->field_exists('wp_site_url', $tsites)) {
            $CI->db->query("ALTER TABLE `{$tsites}` ADD COLUMN `wp_site_url` VARCHAR(500) DEFAULT NULL AFTER `publish_type`");
        }
        if (!$CI->db->field_exists('wp_username', $tsites)) {
            $CI->db->query("ALTER TABLE `{$tsites}` ADD COLUMN `wp_username` VARCHAR(255) DEFAULT NULL AFTER `wp_site_url`");
        }
        if (!$CI->db->field_exists('wp_app_password', $tsites)) {
            $CI->db->query("ALTER TABLE `{$tsites}` ADD COLUMN `wp_app_password` TEXT DEFAULT NULL AFTER `wp_username`");
        }
        if (!$CI->db->field_exists('wp_page_id', $tsites)) {
            $CI->db->query("ALTER TABLE `{$tsites}` ADD COLUMN `wp_page_id` INT(11) DEFAULT NULL AFTER `wp_app_password`");
        }
    }

    // v16: internal pages, site media library, page-media mapping, page generation history
    $tp   = db_prefix() . 'pitchsnap_pages';
    $tm   = db_prefix() . 'pitchsnap_site_media';
    $tpm  = db_prefix() . 'pitchsnap_page_media';
    $tpg  = db_prefix() . 'pitchsnap_page_generations';

    if (!$CI->db->table_exists($tp)) {
        $CI->db->query("
            CREATE TABLE IF NOT EXISTS `{$tp}` (
                `id`                    INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_id`               INT(11) NOT NULL,
                `title`                 VARCHAR(255) NOT NULL DEFAULT '',
                `slug`                  VARCHAR(255) NOT NULL DEFAULT '',
                `page_type`             VARCHAR(30)  NOT NULL DEFAULT 'custom',
                `parent_page_id`        INT(11) DEFAULT NULL,
                `status`                VARCHAR(20)  NOT NULL DEFAULT 'draft',
                `generation_status`     VARCHAR(30)  NOT NULL DEFAULT 'not_generated',
                `meta_title`            VARCHAR(255) DEFAULT NULL,
                `meta_description`      TEXT DEFAULT NULL,
                `primary_keyword`       VARCHAR(255) DEFAULT NULL,
                `supporting_keywords`   TEXT DEFAULT NULL,
                `instructions`          TEXT DEFAULT NULL,
                `noindex_page`          TINYINT(1) NOT NULL DEFAULT 0,
                `is_home_page`          TINYINT(1) NOT NULL DEFAULT 0,
                `menu_primary`          TINYINT(1) NOT NULL DEFAULT 0,
                `menu_footer`           TINYINT(1) NOT NULL DEFAULT 0,
                `menu_label`            VARCHAR(255) DEFAULT NULL,
                `menu_order`            INT(11) NOT NULL DEFAULT 0,
                `current_generation_id` INT(11) DEFAULT NULL,
                `published_path`        VARCHAR(500) DEFAULT NULL,
                `wp_page_id`            INT(11) DEFAULT NULL,
                `published_at`          DATETIME DEFAULT NULL,
                `dateadded`             DATETIME NOT NULL,
                `dateupdated`           DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_pages_site` (`site_id`),
                KEY `idx_pages_parent` (`parent_page_id`),
                KEY `idx_pages_status` (`status`),
                KEY `idx_pages_slug_site` (`site_id`, `slug`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    if (!$CI->db->table_exists($tm)) {
        $CI->db->query("
            CREATE TABLE IF NOT EXISTS `{$tm}` (
                `id`                INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_id`           INT(11) NOT NULL,
                `filename`          VARCHAR(255) NOT NULL DEFAULT '',
                `original_filename` VARCHAR(255) NOT NULL DEFAULT '',
                `title`             VARCHAR(255) DEFAULT NULL,
                `description`       TEXT DEFAULT NULL,
                `alt_text`          VARCHAR(500) DEFAULT NULL,
                `category`          VARCHAR(50)  NOT NULL DEFAULT 'general',
                `mime_type`         VARCHAR(100) DEFAULT NULL,
                `file_size`         INT(11) DEFAULT NULL,
                `dateadded`         DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_media_site` (`site_id`),
                KEY `idx_media_category` (`site_id`, `category`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    if (!$CI->db->table_exists($tpm)) {
        $CI->db->query("
            CREATE TABLE IF NOT EXISTS `{$tpm}` (
                `id`        INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `page_id`   INT(11) NOT NULL,
                `media_id`  INT(11) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_page_media` (`page_id`, `media_id`),
                KEY `idx_pm_media` (`media_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    if (!$CI->db->table_exists($tpg)) {
        $CI->db->query("
            CREATE TABLE IF NOT EXISTS `{$tpg}` (
                `id`                       INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `page_id`                  INT(11) NOT NULL,
                `site_id`                  INT(11) NOT NULL,
                `html_content`             LONGTEXT DEFAULT NULL,
                `css_content`              TEXT DEFAULT NULL,
                `js_content`               TEXT DEFAULT NULL,
                `meta_title_generated`     VARCHAR(255) DEFAULT NULL,
                `meta_description_generated` TEXT DEFAULT NULL,
                `prompt_snapshot`          TEXT DEFAULT NULL,
                `is_current`               TINYINT(1) NOT NULL DEFAULT 0,
                `status`                   VARCHAR(20) NOT NULL DEFAULT 'draft',
                `dateadded`                DATETIME NOT NULL,
                `dateupdated`              DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_pg_page` (`page_id`),
                KEY `idx_pg_site` (`site_id`),
                KEY `idx_pg_current` (`page_id`, `is_current`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    // v17: WP menu-item ID tracking on internal pages
    $tp = db_prefix() . 'pitchsnap_pages';
    if ($CI->db->table_exists($tp)) {
        if (!$CI->db->field_exists('wp_primary_menu_item_id', $tp)) {
            $CI->db->query("ALTER TABLE `{$tp}` ADD COLUMN `wp_primary_menu_item_id` INT(11) DEFAULT NULL AFTER `wp_page_id`");
        }
        if (!$CI->db->field_exists('wp_footer_menu_item_id', $tp)) {
            $CI->db->query("ALTER TABLE `{$tp}` ADD COLUMN `wp_footer_menu_item_id` INT(11) DEFAULT NULL AFTER `wp_primary_menu_item_id`");
        }
    }

    // v18: Cloudflare custom hostname tracking on site_domains
    $td = db_prefix() . 'pitchsnap_site_domains';
    if ($CI->db->table_exists($td)) {
        if (!$CI->db->field_exists('cf_hostname_id', $td)) {
            $CI->db->query("ALTER TABLE `{$td}` ADD COLUMN `cf_hostname_id` VARCHAR(64) DEFAULT NULL AFTER `ssl_status`");
        }
        if (!$CI->db->field_exists('cf_status', $td)) {
            $CI->db->query("ALTER TABLE `{$td}` ADD COLUMN `cf_status` VARCHAR(20) DEFAULT NULL AFTER `cf_hostname_id`");
        }
    }
    if (get_option('pitchsnap_cf_api_token') === false) {
        add_option('pitchsnap_cf_api_token', '');
    }
    if (get_option('pitchsnap_cf_zone_id') === false) {
        add_option('pitchsnap_cf_zone_id', '');
    }

    // v19: apex_status on site_domains + apex API token option
    $td = db_prefix() . 'pitchsnap_site_domains';
    if ($CI->db->table_exists($td) && !$CI->db->field_exists('apex_status', $td)) {
        $CI->db->query("ALTER TABLE `{$td}` ADD COLUMN `apex_status` VARCHAR(20) DEFAULT NULL AFTER `cf_status`");
    }
    if (get_option('pitchsnap_apex_api_token') === false) {
        add_option('pitchsnap_apex_api_token', '');
    }

    // v20: connector API key + connection status columns on pitchsnap_sites
    $ts20 = db_prefix() . 'pitchsnap_sites';
    if ($CI->db->table_exists($ts20)) {
        if (!$CI->db->field_exists('wp_api_key', $ts20)) {
            $CI->db->query("ALTER TABLE `{$ts20}` ADD COLUMN `wp_api_key` TEXT DEFAULT NULL AFTER `wp_site_url`");
        }
        if (!$CI->db->field_exists('wp_connected_at', $ts20)) {
            $CI->db->query("ALTER TABLE `{$ts20}` ADD COLUMN `wp_connected_at` DATETIME DEFAULT NULL");
        }
        if (!$CI->db->field_exists('wp_connector_version', $ts20)) {
            $CI->db->query("ALTER TABLE `{$ts20}` ADD COLUMN `wp_connector_version` VARCHAR(20) DEFAULT NULL");
        }
        if (!$CI->db->field_exists('wp_wp_version', $ts20)) {
            $CI->db->query("ALTER TABLE `{$ts20}` ADD COLUMN `wp_wp_version` VARCHAR(20) DEFAULT NULL");
        }
        if (!$CI->db->field_exists('wp_active_theme_slug', $ts20)) {
            $CI->db->query("ALTER TABLE `{$ts20}` ADD COLUMN `wp_active_theme_slug` VARCHAR(255) DEFAULT NULL");
        }
    }

    // v21: pairing token column — ClickFuzz generates & shows token, plugin sends it back
    $ts21 = db_prefix() . 'pitchsnap_sites';
    if ($CI->db->table_exists($ts21) && !$CI->db->field_exists('wp_pairing_token', $ts21)) {
        $CI->db->query("ALTER TABLE `{$ts21}` ADD COLUMN `wp_pairing_token` VARCHAR(64) DEFAULT NULL AFTER `wp_api_key`");
    }

    // v22: video_url on pages + sort_order on page_media
    $tp22  = db_prefix() . 'pitchsnap_pages';
    $tpm22 = db_prefix() . 'pitchsnap_page_media';
    if ($CI->db->table_exists($tp22) && !$CI->db->field_exists('video_url', $tp22)) {
        $CI->db->query("ALTER TABLE `{$tp22}` ADD COLUMN `video_url` VARCHAR(500) DEFAULT NULL AFTER `instructions`");
    }
    if ($CI->db->table_exists($tpm22) && !$CI->db->field_exists('sort_order', $tpm22)) {
        $CI->db->query("ALTER TABLE `{$tpm22}` ADD COLUMN `sort_order` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `media_id`");
    }

    // v23: rename index_page → noindex_page (value-flipped: indexed=0, noindex=1), add is_home_page
    $tp23 = db_prefix() . 'pitchsnap_pages';
    if ($CI->db->table_exists($tp23) && $CI->db->field_exists('index_page', $tp23)) {
        if (!$CI->db->field_exists('noindex_page', $tp23)) {
            $CI->db->query("ALTER TABLE `{$tp23}` ADD COLUMN `noindex_page` TINYINT(1) NOT NULL DEFAULT 0 AFTER `index_page`");
            $CI->db->query("UPDATE `{$tp23}` SET `noindex_page` = 1 - `index_page`");
        }
        $CI->db->query("ALTER TABLE `{$tp23}` DROP COLUMN `index_page`");
    }
    if ($CI->db->table_exists($tp23) && !$CI->db->field_exists('noindex_page', $tp23)) {
        $CI->db->query("ALTER TABLE `{$tp23}` ADD COLUMN `noindex_page` TINYINT(1) NOT NULL DEFAULT 0");
    }
    if ($CI->db->table_exists($tp23) && !$CI->db->field_exists('is_home_page', $tp23)) {
        $CI->db->query("ALTER TABLE `{$tp23}` ADD COLUMN `is_home_page` TINYINT(1) NOT NULL DEFAULT 0");
    }

    // v24: source column on page generations, published_generation_id on pages
    $tpg24 = db_prefix() . 'pitchsnap_page_generations';
    if ($CI->db->table_exists($tpg24) && !$CI->db->field_exists('source', $tpg24)) {
        $CI->db->query("ALTER TABLE `{$tpg24}` ADD COLUMN `source` VARCHAR(30) NOT NULL DEFAULT 'ai_generated' AFTER `is_current`");
    }
    $tp24 = db_prefix() . 'pitchsnap_pages';
    if ($CI->db->table_exists($tp24) && !$CI->db->field_exists('published_generation_id', $tp24)) {
        $CI->db->query("ALTER TABLE `{$tp24}` ADD COLUMN `published_generation_id` INT(11) NULL DEFAULT NULL");
    }

    // v25: forms, form placements, form submissions
    $tf  = db_prefix() . 'pitchsnap_forms';
    $tfp = db_prefix() . 'pitchsnap_form_placements';
    $tfs = db_prefix() . 'pitchsnap_form_submissions';

    if (!$CI->db->table_exists($tf)) {
        $CI->db->query("
            CREATE TABLE IF NOT EXISTS `{$tf}` (
                `id`          INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_id`     INT(11) NOT NULL,
                `name`        VARCHAR(255) NOT NULL DEFAULT '',
                `form_type`   VARCHAR(30)  NOT NULL DEFAULT 'custom',
                `fields`      MEDIUMTEXT DEFAULT NULL,
                `settings`    TEXT DEFAULT NULL,
                `dateadded`   DATETIME NOT NULL,
                `dateupdated` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_forms_site` (`site_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    if (!$CI->db->table_exists($tfp)) {
        $CI->db->query("
            CREATE TABLE IF NOT EXISTS `{$tfp}` (
                `id`          INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `form_id`     INT(11) NOT NULL,
                `page_id`     INT(11) NOT NULL,
                `placement`   VARCHAR(20) NOT NULL DEFAULT 'inline',
                `dateadded`   DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_placements_form` (`form_id`),
                KEY `idx_placements_page` (`page_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    if (!$CI->db->table_exists($tfs)) {
        $CI->db->query("
            CREATE TABLE IF NOT EXISTS `{$tfs}` (
                `id`              INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `form_id`         INT(11) NOT NULL,
                `site_id`         INT(11) NOT NULL,
                `ghl_contact_id`  VARCHAR(100) DEFAULT NULL,
                `submitted_at`    DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_submissions_form` (`form_id`),
                KEY `idx_submissions_site` (`site_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    if (get_option('pitchsnap_ghl_form_webhook_url') === false) {
        add_option('pitchsnap_ghl_form_webhook_url', '');
    }

    // v26: GHL destination registry
    $td = db_prefix() . 'pitchsnap_ghl_destinations';
    if (!$CI->db->table_exists($td)) {
        $CI->db->query("
            CREATE TABLE IF NOT EXISTS `{$td}` (
                `id`          INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_id`     INT(11) DEFAULT NULL,
                `label`       VARCHAR(100) NOT NULL DEFAULT '',
                `ghl_key`     VARCHAR(200) NOT NULL DEFAULT '',
                `mode`        ENUM('single','multiple') NOT NULL DEFAULT 'single',
                `sort_order`  INT(11) NOT NULL DEFAULT 0,
                `active`      TINYINT(1) NOT NULL DEFAULT 1,
                `dateadded`   DATETIME NOT NULL,
                `dateupdated` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_ghl_dest_site` (`site_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        $now = date('Y-m-d H:i:s');
        $CI->db->query("
            INSERT INTO `{$td}` (site_id, label, ghl_key, mode, sort_order, active, dateadded, dateupdated) VALUES
            (NULL, 'First Name',  'firstName', 'single',   1, 1, '{$now}', '{$now}'),
            (NULL, 'Last Name',   'lastName',  'single',   2, 1, '{$now}', '{$now}'),
            (NULL, 'Email',       'email',     'single',   3, 1, '{$now}', '{$now}'),
            (NULL, 'Phone',       'phone',     'single',   4, 1, '{$now}', '{$now}'),
            (NULL, 'Quote Content', '',        'multiple', 5, 1, '{$now}', '{$now}')
        ");
    }

    // v27: onboarding flows table
    $tf = db_prefix() . 'pitchsnap_onboarding_flows';
    if (!$CI->db->table_exists($tf)) {
        $CI->db->query("
            CREATE TABLE IF NOT EXISTS `{$tf}` (
                `id`          INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `name`        VARCHAR(255) NOT NULL,
                `description` TEXT DEFAULT NULL,
                `status`      VARCHAR(20) NOT NULL DEFAULT 'active',
                `created_at`  DATETIME NOT NULL,
                `updated_at`  DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    // v28: onboarding sections table
    $ts = db_prefix() . 'pitchsnap_onboarding_sections';
    if (!$CI->db->table_exists($ts)) {
        $CI->db->query("
            CREATE TABLE IF NOT EXISTS `{$ts}` (
                `id`          INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `flow_id`     INT(11) NOT NULL,
                `name`        VARCHAR(255) NOT NULL,
                `description` TEXT DEFAULT NULL,
                `sort_order`  INT(11) NOT NULL DEFAULT 0,
                `created_at`  DATETIME NOT NULL,
                `updated_at`  DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_sections_flow` (`flow_id`),
                KEY `idx_sections_order` (`flow_id`, `sort_order`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    // v29: onboarding questions table
    $tq = db_prefix() . 'pitchsnap_onboarding_questions';
    if (!$CI->db->table_exists($tq)) {
        $CI->db->query("
            CREATE TABLE IF NOT EXISTS `{$tq}` (
                `id`           INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `section_id`   INT(11) NOT NULL,
                `label`        VARCHAR(255) NOT NULL,
                `help_text`    TEXT DEFAULT NULL,
                `field_type`   VARCHAR(30) NOT NULL DEFAULT 'text',
                `required`     TINYINT(1) NOT NULL DEFAULT 0,
                `options_json` TEXT DEFAULT NULL,
                `sort_order`   INT(11) NOT NULL DEFAULT 0,
                `created_at`   DATETIME NOT NULL,
                `updated_at`   DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_questions_section` (`section_id`),
                KEY `idx_questions_order` (`section_id`, `sort_order`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    // v30: data_key + purpose columns on onboarding questions
    $tq30 = db_prefix() . 'pitchsnap_onboarding_questions';
    if ($CI->db->table_exists($tq30)) {
        if (!$CI->db->field_exists('data_key', $tq30)) {
            $CI->db->query("ALTER TABLE `{$tq30}` ADD COLUMN `data_key` VARCHAR(100) NOT NULL DEFAULT '' AFTER `label`");
        }
        if (!$CI->db->field_exists('purpose', $tq30)) {
            $CI->db->query("ALTER TABLE `{$tq30}` ADD COLUMN `purpose` VARCHAR(30) NOT NULL DEFAULT 'data' AFTER `data_key`");
        }
    }

    // v31: condition columns on onboarding questions
    $tq31 = db_prefix() . 'pitchsnap_onboarding_questions';
    if ($CI->db->table_exists($tq31)) {
        if (!$CI->db->field_exists('condition_question_id', $tq31)) {
            $CI->db->query("ALTER TABLE `{$tq31}` ADD COLUMN `condition_question_id` INT(11) DEFAULT NULL AFTER `options_json`");
        }
        if (!$CI->db->field_exists('condition_operator', $tq31)) {
            $CI->db->query("ALTER TABLE `{$tq31}` ADD COLUMN `condition_operator` VARCHAR(20) DEFAULT NULL AFTER `condition_question_id`");
        }
        if (!$CI->db->field_exists('condition_value', $tq31)) {
            $CI->db->query("ALTER TABLE `{$tq31}` ADD COLUMN `condition_value` VARCHAR(500) DEFAULT NULL AFTER `condition_operator`");
        }
    }

    // v33: usage tags table + question-tag join table + seed default tags
    $ttags = db_prefix() . 'pitchsnap_onboarding_usage_tags';
    $tqt   = db_prefix() . 'pitchsnap_onboarding_question_tags';

    if (!$CI->db->table_exists($ttags)) {
        $CI->db->query("
            CREATE TABLE IF NOT EXISTS `{$ttags}` (
                `id`          INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `name`        VARCHAR(100) NOT NULL,
                `slug`        VARCHAR(100) NOT NULL,
                `description` TEXT DEFAULT NULL,
                `created_at`  DATETIME NOT NULL,
                `updated_at`  DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_tag_slug` (`slug`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    if (!$CI->db->table_exists($tqt)) {
        $CI->db->query("
            CREATE TABLE IF NOT EXISTS `{$tqt}` (
                `question_id` INT(11) UNSIGNED NOT NULL,
                `tag_id`      INT(11) UNSIGNED NOT NULL,
                PRIMARY KEY (`question_id`, `tag_id`),
                KEY `idx_qtags_tag` (`tag_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    if ($CI->db->table_exists($ttags)) {
        $default_tags = [
            ['name' => 'Customer Profile', 'slug' => 'customer_profile'],
            ['name' => 'Perfex',           'slug' => 'perfex'],
            ['name' => 'Website',          'slug' => 'website'],
            ['name' => 'GHL',              'slug' => 'ghl'],
            ['name' => 'A2P',              'slug' => 'a2p'],
            ['name' => 'Schema',           'slug' => 'schema'],
            ['name' => 'Quote Form',       'slug' => 'quote_form'],
            ['name' => 'Runtime',          'slug' => 'runtime'],
        ];
        $now = date('Y-m-d H:i:s');
        foreach ($default_tags as $tag) {
            if (!$CI->db->where('slug', $tag['slug'])->count_all_results($ttags)) {
                $CI->db->insert($ttags, ['name' => $tag['name'], 'slug' => $tag['slug'], 'description' => null, 'created_at' => $now]);
            }
        }
    }

    // v34: canonical site data table
    $tsd = db_prefix() . 'pitchsnap_site_data';
    if (!$CI->db->table_exists($tsd)) {
        $CI->db->query("
            CREATE TABLE IF NOT EXISTS `{$tsd}` (
                `id`         INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_id`    INT(11) NOT NULL,
                `data_key`   VARCHAR(100) NOT NULL,
                `value`      MEDIUMTEXT DEFAULT NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_site_data` (`site_id`, `data_key`),
                KEY `idx_site_data_site` (`site_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    // v35: onboarding link records
    $tol = db_prefix() . 'pitchsnap_onboarding_links';
    if (!$CI->db->table_exists($tol)) {
        $CI->db->query("
            CREATE TABLE IF NOT EXISTS `{$tol}` (
                `id`         INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `site_id`    INT(11) NOT NULL,
                `flow_id`    INT(11) NOT NULL,
                `token`      VARCHAR(64) NOT NULL,
                `status`     VARCHAR(20) NOT NULL DEFAULT 'active',
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_ob_token` (`token`),
                KEY `idx_ob_site` (`site_id`),
                KEY `idx_ob_flow` (`flow_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    // v36: page_url column on onboarding flows
    $tf36 = db_prefix() . 'pitchsnap_onboarding_flows';
    if ($CI->db->table_exists($tf36) && !$CI->db->field_exists('page_url', $tf36)) {
        $CI->db->query("ALTER TABLE `{$tf36}` ADD COLUMN `page_url` VARCHAR(500) DEFAULT NULL AFTER `description`");
    }

    // v37: completed_at timestamp on onboarding links
    $t37 = db_prefix() . 'pitchsnap_onboarding_links';
    if ($CI->db->table_exists($t37) && !$CI->db->field_exists('completed_at', $t37)) {
        $CI->db->query("ALTER TABLE `{$t37}` ADD COLUMN `completed_at` DATETIME DEFAULT NULL AFTER `status`");
    }

    // v38: onboarding_link_id on sites — idempotency marker for auto-created onboarding links
    $t38 = db_prefix() . 'pitchsnap_sites';
    if ($CI->db->table_exists($t38) && !$CI->db->field_exists('onboarding_link_id', $t38)) {
        $CI->db->query("ALTER TABLE `{$t38}` ADD COLUMN `onboarding_link_id` INT(11) DEFAULT NULL AFTER `status`");
    }

    // Mark schema as current so this function is a no-op on future requests
    if (!get_option('pitchsnap_db_version')) {
        add_option('pitchsnap_db_version', '38');
    } else {
        update_option('pitchsnap_db_version', '38');
    }
}

// ---------------------------------------------------------------------------
// Agreement defaults
// ---------------------------------------------------------------------------

function clickfuzz_web_default_agreement_text()
{
    return <<<'AGREEMENT'
PITCHSNAP SERVICE AGREEMENT
Version 1.0

[LEGAL NOTICE: This is placeholder agreement text. This document requires final legal review and approval before use in production. Replace this text with your attorney-approved Service Agreement before going live.]

This Service Agreement ("Agreement") is between the business identified in the account ("Customer") and the service provider ("Provider").

SERVICES INCLUDED
Provider will deliver:
- Professionally redesigned business website
- AI-powered lead response system integration
- Technical hosting and maintenance of the redesigned website
- Ongoing support for the duration of this Agreement

PRICING AND PAYMENT
- Monthly fee: $295.00 USD
- Agreement term: 12 months (minimum commitment)
- First payment is due upon acceptance of this Agreement
- Subsequent monthly payments are due on the same date each month
- Payments are non-refundable unless required by applicable law

WEBSITE AND HOSTING
- Provider will host the redesigned website on Provider's infrastructure
- Customer retains ownership of original business content, logos, and brand materials
- Provider retains rights to AI-generated design elements, layout, and code
- Customer grants Provider a license to display their content as part of the website
- Provider will make commercially reasonable efforts to maintain service availability

CANCELLATION AND TERMINATION
- Either party may cancel this Agreement with 30 days written notice after the initial 12-month term
- Cancellation during the initial 12-month term may result in remaining balance becoming due
- Provider may suspend services for non-payment after 14 days past the due date
- Provider may terminate for material breach with 10 days written notice

LIMITATION OF LIABILITY
- Provider does not guarantee specific business outcomes, conversions, or revenue
- Provider's total liability shall not exceed fees paid in the prior 3-month period
- Provider is not responsible for outages caused by third-party services

GENERAL
- This Agreement is the entire agreement between the parties regarding these Services
- Amendments require written consent from both parties
- If any provision is unenforceable, the remaining provisions remain in effect

By checking the acceptance box and clicking "Agree & Continue to Payment," Customer confirms they have read, understood, and agreed to be bound by this Agreement.
AGREEMENT;
}

// ---------------------------------------------------------------------------
// Lead profile tab — ClickFuzz Web website/site info on the Perfex lead page
// ---------------------------------------------------------------------------

function clickfuzz_web_lead_tab($lead)
{
    if (!$lead) {
        return;
    }
    echo '<li role="presentation"><a href="#tab_pitchsnap" aria-controls="tab_pitchsnap" role="tab" data-toggle="tab">ClickFuzz Web</a></li>';
}

function clickfuzz_web_lead_tab_content($lead)
{
    if (!$lead) {
        return;
    }
    $CI =& get_instance();
    // Load model via require_once — module models cannot be resolved through
    // $CI->load->model() from a global hook context outside the module.
    if (!class_exists('Pitchsnap_model')) {
        require_once FCPATH . 'modules/pitchsnap/models/Pitchsnap_model.php';
        $CI->pitchsnap_model = new Pitchsnap_model();
    }
    $websites  = $CI->pitchsnap_model->get_by_lead($lead->id);
    $view_path = FCPATH . 'modules/pitchsnap/views/lead_section.php';
    if (file_exists($view_path)) {
        // $websites and $lead are in scope for the included view.
        include $view_path;
    }
}

function clickfuzz_web_db_version_badge()
{
    $CI = &get_instance();
    if (strpos($CI->uri->uri_string(), 'pitchsnap') === false) {
        return;
    }
    $v = get_option('pitchsnap_db_version') ?: '?';
    echo '<li style="display:flex;align-items:center;padding:0 10px;">'
       . '<span style="font-size:11px;font-weight:600;color:#aaa;letter-spacing:.5px;white-space:nowrap;">'
       . 'DB v' . (int) $v
       . '</span></li>';
}
