<?php
defined('ABSPATH') || exit;

/**
 * Content sanitization — PHP detection and rejection.
 *
 * Generated HTML/CSS/JS is treated as inert markup only.
 * Any PHP found causes the operation to be rejected; content is never
 * silently mutated or executed.
 */
class CF_Sanitize
{
    /**
     * Return true if $content contains PHP open/close tags.
     *
     * Matches: <?php  <?=  <?[whitespace]  close-tag
     * Does NOT match: <?xml declarations (safe XML markup).
     */
    public static function has_php(string $content): bool
    {
        // Strip XML declarations before scanning so their closing delimiter
        // is not caught by the orphaned-close-tag check below.
        $check = preg_replace('/<\?xml(?:[^?]|\?(?!>))*\?>/i', '', $content);

        // PHP open tag variants (case-insensitive): <?php, <?=, <? followed by whitespace
        if (preg_match('/<\?(?:php\b|=|[ \t\r\n])/i', $check) === 1) {
            return true;
        }
        // Orphaned PHP close tag
        if (strpos($check, '?>') !== false) {
            return true;
        }
        return false;
    }

    /**
     * Return true (success) or WP_Error (422) if $content contains PHP.
     *
     * @return true|WP_Error
     */
    public static function validate_no_php(string $content)
    {
        if (self::has_php($content)) {
            return new WP_Error(
                'cf_php_in_content',
                'Generated content must not contain PHP.',
                ['status' => 422]
            );
        }
        return true;
    }
}
