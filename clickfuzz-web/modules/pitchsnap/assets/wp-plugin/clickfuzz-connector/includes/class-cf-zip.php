<?php
defined('ABSPATH') || exit;

/**
 * ZIP validation for ClickFuzz-generated theme archives.
 *
 * Pure PHP — no WordPress dependencies. Validates structure,
 * path safety, and ClickFuzz theme identity before any
 * installation step runs.
 */
class CF_Zip
{
    const MAX_BYTES   = 50 * 1024 * 1024; // 50 MB
    const SLUG_PREFIX = 'clickfuzz-generated-';
    const REQUIRED    = ['style.css', 'index.php'];

    /**
     * Validate a ClickFuzz theme ZIP from a local file path.
     *
     * @return array{ok:true,theme_slug:string,theme_name:string}
     *       | array{ok:false,error:string,status:int}
     */
    public static function validate(string $path): array
    {
        if (!file_exists($path) || !is_file($path)) {
            return self::err('Uploaded file could not be found.', 400);
        }

        if (filesize($path) > self::MAX_BYTES) {
            return self::err('ZIP exceeds the 50 MB size limit.', 422);
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return self::err('ZIP archive could not be opened — file may be corrupt or is not a valid ZIP.', 422);
        }

        $top_dirs = [];

        for ($i = 0, $n = $zip->numFiles; $i < $n; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }

            // ── Path traversal and injection checks ───────────────────────────
            if (str_contains($name, '../') || str_contains($name, "..\\" )) {
                $zip->close();
                return self::err('ZIP contains a path traversal sequence ("../"). Rejected for security.', 422);
            }
            if ($name !== '' && ($name[0] === '/' || $name[0] === '\\')) {
                $zip->close();
                return self::err('ZIP contains an absolute path. Rejected for security.', 422);
            }
            if (str_contains($name, "\0")) {
                $zip->close();
                return self::err('ZIP entry name contains a null byte. Rejected for security.', 422);
            }
            // Windows drive letter absolute path (C:\...)
            if (strlen($name) >= 3 && $name[1] === ':' && ($name[2] === '/' || $name[2] === '\\')) {
                $zip->close();
                return self::err('ZIP contains a Windows absolute path. Rejected for security.', 422);
            }

            // ── Collect top-level segment ─────────────────────────────────────
            $slash = strpos($name, '/');
            $seg   = ($slash !== false) ? substr($name, 0, $slash) : rtrim($name, '/\\');
            if ($seg !== '') {
                $top_dirs[$seg] = true;
            }
        }

        if (empty($top_dirs)) {
            $zip->close();
            return self::err('ZIP archive is empty.', 422);
        }

        if (count($top_dirs) !== 1) {
            $zip->close();
            $found = implode(', ', array_keys($top_dirs));
            return self::err("ZIP must contain exactly one top-level directory. Found: {$found}.", 422);
        }

        $theme_slug = array_key_first($top_dirs);

        // ── ClickFuzz slug identity ───────────────────────────────────────────

        if (!str_starts_with($theme_slug, self::SLUG_PREFIX)) {
            $zip->close();
            return self::err(
                'Theme directory must start with "clickfuzz-generated-". ' .
                'This does not appear to be a ClickFuzz-generated theme.',
                422
            );
        }

        // Slug: only lowercase alphanumeric + hyphens after the prefix
        if (!preg_match('/^clickfuzz-generated-[a-z0-9][a-z0-9\-]*$/', $theme_slug)) {
            $zip->close();
            return self::err('Theme slug contains invalid characters.', 422);
        }

        // ── Required files ────────────────────────────────────────────────────

        foreach (self::REQUIRED as $file) {
            if ($zip->locateName($theme_slug . '/' . $file) === false) {
                $zip->close();
                return self::err("Required theme file missing from ZIP: {$file}.", 422);
            }
        }

        // ── style.css header validation ───────────────────────────────────────

        $style = $zip->getFromName($theme_slug . '/style.css');
        if ($style === false) {
            $zip->close();
            return self::err('Could not read style.css from ZIP.', 422);
        }

        if (!preg_match('/^Theme Name:\s*(.+)$/mi', $style, $m)) {
            $zip->close();
            return self::err('style.css is missing the WordPress Theme Name header.', 422);
        }
        $theme_name = trim($m[1]);

        // ClickFuzz author marker — exporter always writes "Author: ClickFuzz"
        if (stripos($style, 'Author: ClickFuzz') === false) {
            $zip->close();
            return self::err(
                'This does not appear to be a ClickFuzz-generated theme ' .
                '(Author: ClickFuzz marker missing from style.css).',
                422
            );
        }

        $zip->close();
        return ['ok' => true, 'theme_slug' => $theme_slug, 'theme_name' => $theme_name];
    }

    /** @return array{ok:false,error:string,status:int} */
    private static function err(string $msg, int $status): array
    {
        return ['ok' => false, 'error' => $msg, 'status' => $status];
    }
}
