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
        'slug'     => 'pitchsnap_settings',
        'name'     => 'Settings',
        'href'     => admin_url('pitchsnap/settings'),
        'icon'     => 'fa fa-cog',
        'position' => 2,
    ]);
}

// ---------------------------------------------------------------------------
// DB migration (runs on every admin_init, idempotent)
// ---------------------------------------------------------------------------

function clickfuzz_web_db_upgrade()
{
    // Version gate: skip all schema/settings checks once already up to date.
    if ((int) get_option('pitchsnap_db_version') >= 16) {
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
                `index_page`            TINYINT(1) NOT NULL DEFAULT 1,
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

    // Mark schema as current so this function is a no-op on future requests
    if (!get_option('pitchsnap_db_version')) {
        add_option('pitchsnap_db_version', '16');
    } else {
        update_option('pitchsnap_db_version', '16');
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
