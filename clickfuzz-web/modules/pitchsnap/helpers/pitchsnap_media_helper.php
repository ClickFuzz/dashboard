<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Allowed MIME types for site media uploads
define('PS_MEDIA_ALLOWED_MIMES', [
    'image/jpeg'    => 'jpg',
    'image/png'     => 'png',
    'image/gif'     => 'gif',
    'image/webp'    => 'webp',
]);

define('PS_MEDIA_MAX_BYTES', 10 * 1024 * 1024); // 10 MB

/**
 * Return the filesystem directory for a site's media.
 * Directory is NOT created here — caller must mkdir if needed.
 */
function clickfuzz_web_media_dir($site_id)
{
    return dirname(FCPATH) . '/media/' . (int) $site_id;
}

/**
 * Return the public URL for a stored media file.
 * Follows the same convention as /previews and /sites — all stored inside
 * the dashboard directory, accessible via base_url():
 *   FCPATH    = .../public_html/dashboard/
 *   Media dir = .../public_html/dashboard/media/{site_id}/{filename}
 *   URL       = https://domain.com/dashboard/media/{site_id}/{filename}
 */
function clickfuzz_web_media_url($site_id, $filename)
{
    return rtrim(base_url(), '/') . '/media/' . (int) $site_id . '/' . rawurlencode($filename);
}

/**
 * Validate and store an uploaded file for a site's media library.
 *
 * Expects $_FILES['media_file'] to be set.
 * Returns ['success'=>true, 'filename'=>string, 'mime_type'=>string, 'file_size'=>int]
 *      or ['success'=>false, 'error'=>string]
 */
function clickfuzz_web_upload_media($site_id)
{
    $site_id = (int) $site_id;
    if (!$site_id) {
        return ['success' => false, 'error' => 'Invalid site ID.'];
    }

    if (empty($_FILES['media_file']) || $_FILES['media_file']['error'] !== UPLOAD_ERR_OK) {
        $err_code = $_FILES['media_file']['error'] ?? -1;
        $msg = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temporary directory missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not write file.',
        ];
        return ['success' => false, 'error' => $msg[$err_code] ?? 'Upload error (code ' . $err_code . ').'];
    }

    $tmp  = $_FILES['media_file']['tmp_name'];
    $size = $_FILES['media_file']['size'];
    $orig = basename($_FILES['media_file']['name']);

    if ($size > PS_MEDIA_MAX_BYTES) {
        return ['success' => false, 'error' => 'File exceeds the 10 MB size limit.'];
    }

    if (!is_uploaded_file($tmp)) {
        return ['success' => false, 'error' => 'Invalid upload.'];
    }

    // Server-side MIME detection
    if (!function_exists('finfo_open')) {
        return ['success' => false, 'error' => 'finfo extension unavailable on this server.'];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($tmp);

    $allowed = PS_MEDIA_ALLOWED_MIMES;
    if (!array_key_exists($mime, $allowed)) {
        return ['success' => false, 'error' => 'File type not allowed (' . htmlspecialchars($mime) . '). Accepted: JPEG, PNG, GIF, WebP, SVG.'];
    }

    $ext      = $allowed[$mime];
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;

    $dir = clickfuzz_web_media_dir($site_id);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return ['success' => false, 'error' => 'Could not create media directory.'];
    }

    $dest = $dir . '/' . $filename;
    if (!move_uploaded_file($tmp, $dest)) {
        return ['success' => false, 'error' => 'Could not move uploaded file.'];
    }

    return [
        'success'           => true,
        'filename'          => $filename,
        'original_filename' => $orig,
        'mime_type'         => $mime,
        'file_size'         => $size,
    ];
}

/**
 * Safely delete a media file from disk.
 * Validates the file is inside the expected site directory before removing.
 */
function clickfuzz_web_delete_media_file($site_id, $filename)
{
    $site_id  = (int) $site_id;
    $dir      = clickfuzz_web_media_dir($site_id);
    $real_dir = realpath($dir);
    if (!$real_dir) {
        return;
    }

    $path      = $dir . '/' . $filename;
    $real_path = realpath($path);
    if ($real_path && strpos($real_path . DIRECTORY_SEPARATOR, $real_dir . DIRECTORY_SEPARATOR) === 0) {
        @unlink($real_path);
    }
}
