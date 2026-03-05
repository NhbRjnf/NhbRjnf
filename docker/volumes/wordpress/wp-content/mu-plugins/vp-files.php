<?php
/**
 * Plugin Name: VP Files Gateway
 * Description: Private file gateway for uploads, link management and secure downloads.
 */

if (!defined('ABSPATH')) {
  exit;
}

if (!function_exists('vp_files_request_id')) {
  function vp_files_request_id() {
    try {
      return bin2hex(random_bytes(8));
    } catch (Exception $e) {
      return uniqid('vpfiles_', true);
    }
  }
}

if (!function_exists('vp_files_runtime_log')) {
  function vp_files_runtime_log($level, $action, $message, $ctx = []) {
    $runtimeDir = '/opt/vseponyatno/runtime';
    if (!is_dir($runtimeDir)) {
      @mkdir($runtimeDir, 0775, true);
    }

    $safeCtx = is_array($ctx) ? $ctx : [];
    foreach (['token', 'authorization', 'password', 'password_hash'] as $secretKey) {
      if (isset($safeCtx[$secretKey])) {
        unset($safeCtx[$secretKey]);
      }
    }

    $line = sprintf(
      "%s [vp-files] level=%s action=%s rid=%s ip=%s msg=\"%s\" ctx=%s\n",
      date(DATE_ATOM),
      sanitize_text_field((string)$level),
      sanitize_text_field((string)$action),
      sanitize_text_field((string)($safeCtx['request_id'] ?? 'unknown')),
      isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field((string)$_SERVER['REMOTE_ADDR']) : 'unknown',
      str_replace('"', '\\"', (string)$message),
      wp_json_encode($safeCtx, JSON_UNESCAPED_UNICODE)
    );

    $target = $runtimeDir . '/vp-files.log';
    $written = @file_put_contents($target, $line, FILE_APPEND | LOCK_EX);
    if ($written === false) {
      error_log('[vp-files] ' . $line);
    }
  }
}

if (!function_exists('vp_files_json')) {
  function vp_files_json($payload, $status = 200) {
    return new WP_REST_Response($payload, $status);
  }
}

if (!function_exists('vp_files_service_token')) {
  function vp_files_service_token() {
    $token = getenv('VP_WP_SERVICE_TOKEN');
    return $token ? trim((string)$token) : '';
  }
}

if (!function_exists('vp_files_get_bearer_token')) {
  function vp_files_get_bearer_token() {
    $header = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
      $header = (string)$_SERVER['HTTP_AUTHORIZATION'];
    } elseif (function_exists('getallheaders')) {
      $headers = getallheaders();
      if (!empty($headers['Authorization'])) {
        $header = (string)$headers['Authorization'];
      }
    }

    if ($header === '') {
      return '';
    }

    if (preg_match('/Bearer\s+(.+)$/i', $header, $matches)) {
      return trim((string)$matches[1]);
    }

    return '';
  }
}

if (!function_exists('vp_files_is_service_auth')) {
  function vp_files_is_service_auth() {
    $expected = vp_files_service_token();
    if ($expected === '') {
      return false;
    }
    $provided = vp_files_get_bearer_token();
    return $provided !== '' && hash_equals($expected, $provided);
  }
}

if (!function_exists('vp_files_rest_permission')) {
  function vp_files_rest_permission() {
    return vp_files_is_service_auth() || is_user_logged_in();
  }
}

if (!function_exists('vp_files_links_table')) {
  function vp_files_links_table() {
    global $wpdb;
    return $wpdb->prefix . 'vp_file_links';
  }
}

if (!function_exists('vp_files_access_log_table')) {
  function vp_files_access_log_table() {
    global $wpdb;
    return $wpdb->prefix . 'vp_file_access_log';
  }
}

if (!function_exists('vp_files_install_tables')) {
  function vp_files_install_tables() {
    global $wpdb;

    $installedVersion = (string)get_option('vp_files_db_version', '');
    $targetVersion = '1.0.0';
    if ($installedVersion === $targetVersion) {
      return;
    }

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();

    $linksTable = vp_files_links_table();
    $accessTable = vp_files_access_log_table();

    $sqlLinks = "CREATE TABLE {$linksTable} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      token VARCHAR(64) NOT NULL,
      attachment_id BIGINT UNSIGNED NOT NULL,
      mode CHAR(1) NOT NULL,
      tenant_id VARCHAR(64) NULL,
      require_auth TINYINT(1) NOT NULL DEFAULT 0,
      password_hash VARCHAR(255) NULL,
      max_uses INT NOT NULL DEFAULT 0,
      used_count INT NOT NULL DEFAULT 0,
      expires_at DATETIME NULL,
      revoked_at DATETIME NULL,
      created_at DATETIME NOT NULL,
      created_by BIGINT UNSIGNED NULL,
      meta LONGTEXT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY token (token),
      KEY attachment_id (attachment_id),
      KEY expires_at (expires_at),
      KEY revoked_at (revoked_at)
    ) {$charset};";

    $sqlAccess = "CREATE TABLE {$accessTable} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      link_id BIGINT UNSIGNED NOT NULL,
      ip VARCHAR(64) NULL,
      user_agent TEXT NULL,
      status VARCHAR(32) NOT NULL,
      created_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      KEY link_id (link_id),
      KEY created_at (created_at)
    ) {$charset};";

    dbDelta($sqlLinks);
    dbDelta($sqlAccess);
    update_option('vp_files_db_version', $targetVersion, false);
  }
}


if (!function_exists('vp_files_tempnam')) {
  function vp_files_tempnam($prefix) {
    if (!function_exists('wp_tempnam')) {
      require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    if (function_exists('wp_tempnam')) {
      return wp_tempnam($prefix);
    }
    // Fallback (very rare): system temp
    $safe = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$prefix);
    if ($safe === '') $safe = 'vp_';
    return tempnam(sys_get_temp_dir(), $safe);
  }
}

if (!function_exists('vp_files_upload_base')) {
  function vp_files_upload_base() {
    $uploads = wp_upload_dir();
    $baseDir = trailingslashit((string)$uploads['basedir']) . 'vp-private';
    $baseUrl = trailingslashit((string)$uploads['baseurl']) . 'vp-private';

    if (!is_dir($baseDir)) {
      wp_mkdir_p($baseDir);
    }

    return [
      'dir' => $baseDir,
      'url' => $baseUrl,
    ];
  }
}

if (!function_exists('vp_files_store_file')) {
  function vp_files_store_file($sourcePath, $originalName, $mime = '') {
    $base = vp_files_upload_base();
    $ym = gmdate('Y/m');
    $targetDir = trailingslashit($base['dir']) . $ym;
    if (!is_dir($targetDir)) {
      wp_mkdir_p($targetDir);
    }

    $safeName = sanitize_file_name((string)$originalName);
    if ($safeName === '') {
      $safeName = 'file.bin';
    }

    $targetPath = wp_unique_filename($targetDir, $safeName);
    $finalPath = trailingslashit($targetDir) . $targetPath;

    if (!@rename($sourcePath, $finalPath)) {
      if (!@copy($sourcePath, $finalPath)) {
        return new WP_Error('vp_files_store_failed', 'Failed to store uploaded file', ['status' => 500]);
      }
      @unlink($sourcePath);
    }

    $size = filesize($finalPath);
    if ($size === false) {
      $size = 0;
    }

    if ($mime === '') {
      $detected = wp_check_filetype($finalPath);
      $mime = !empty($detected['type']) ? (string)$detected['type'] : 'application/octet-stream';
    }

    $relPath = 'vp-private/' . $ym . '/' . basename($finalPath);

    $attachment = [
      'post_mime_type' => $mime,
      'post_title' => sanitize_text_field(pathinfo($originalName, PATHINFO_FILENAME)),
      'post_content' => '',
      'post_status' => 'inherit',
      'guid' => trailingslashit($base['url']) . $ym . '/' . basename($finalPath),
    ];

    $attachmentId = wp_insert_attachment($attachment, $finalPath);
    if (is_wp_error($attachmentId) || !$attachmentId) {
      return new WP_Error('vp_files_attachment_failed', 'Failed to create attachment', ['status' => 500]);
    }

    if (!function_exists('wp_generate_attachment_metadata')) {
      require_once ABSPATH . 'wp-admin/includes/image.php';
    }
    $meta = wp_generate_attachment_metadata($attachmentId, $finalPath);
    if (!is_wp_error($meta) && is_array($meta)) {
      wp_update_attachment_metadata($attachmentId, $meta);
    }

    update_post_meta($attachmentId, '_vp_private_file', 1);
    update_post_meta($attachmentId, '_vp_original_name', $originalName);
    update_post_meta($attachmentId, '_vp_stored_rel_path', $relPath);

    $sha256 = hash_file('sha256', $finalPath);
    update_post_meta($attachmentId, '_vp_sha256', $sha256);

    return [
      'attachment_id' => (int)$attachmentId,
      'sha256' => (string)$sha256,
      'size' => (int)$size,
      'mime' => (string)$mime,
      'original_name' => (string)$originalName,
      'stored_rel_path' => $relPath,
      'stored_url' => null,
    ];
  }
}

if (!function_exists('vp_files_handle_upload')) {
  function vp_files_handle_upload(WP_REST_Request $request) {
    $rid = vp_files_request_id();
    $files = $request->get_file_params();
    if (empty($files['file']) || empty($files['file']['tmp_name'])) {
      return vp_files_json(['ok' => false, 'error' => 'file_required', 'request_id' => $rid], 400);
    }

    $file = $files['file'];
    if (!empty($file['error']) && (int)$file['error'] !== UPLOAD_ERR_OK) {
      return vp_files_json(['ok' => false, 'error' => 'upload_error', 'request_id' => $rid], 400);
    }

    $tmpName = (string)$file['tmp_name'];
    $tmpCopy = vp_files_tempnam('vp-upload-');
    if (!$tmpCopy || !@move_uploaded_file($tmpName, $tmpCopy)) {
      if (!@copy($tmpName, $tmpCopy)) {
        return vp_files_json(['ok' => false, 'error' => 'upload_move_failed', 'request_id' => $rid], 500);
      }
    }

    $name = !empty($file['name']) ? sanitize_file_name((string)$file['name']) : 'upload.bin';
    $mime = !empty($file['type']) ? sanitize_text_field((string)$file['type']) : '';
    $stored = vp_files_store_file($tmpCopy, $name, $mime);
    if (is_wp_error($stored)) {
      vp_files_runtime_log('error', 'upload', $stored->get_error_message(), ['request_id' => $rid]);
      return vp_files_json(['ok' => false, 'error' => 'store_failed', 'request_id' => $rid], (int)($stored->get_error_data()['status'] ?? 500));
    }

    vp_files_runtime_log('info', 'upload', 'file uploaded', [
      'request_id' => $rid,
      'attachment_id' => $stored['attachment_id'],
      'size' => $stored['size'],
      'mime' => $stored['mime'],
    ]);

    return vp_files_json(array_merge(['ok' => true], $stored), 200);
  }
}

if (!function_exists('vp_files_random_token')) {
  function vp_files_random_token() {
    return bin2hex(random_bytes(32));
  }
}

if (!function_exists('vp_files_normalize_expires_at')) {
  function vp_files_normalize_expires_at($ttl, $expiresAt) {
    if (!empty($expiresAt)) {
      $ts = strtotime((string)$expiresAt);
      if ($ts) {
        return gmdate('Y-m-d H:i:s', $ts);
      }
    }

    if ($ttl === null) {
      return null;
    }

    $ttlInt = (int)$ttl;
    if ($ttlInt <= 0) {
      return null;
    }

    return gmdate('Y-m-d H:i:s', time() + $ttlInt);
  }
}

if (!function_exists('vp_files_handle_create_link')) {
  function vp_files_handle_create_link(WP_REST_Request $request) {
    global $wpdb;

    $rid = vp_files_request_id();
    $body = $request->get_json_params();
    if (!is_array($body)) {
      $body = [];
    }

    $attachmentId = isset($body['attachment_id']) ? (int)$body['attachment_id'] : 0;
    $mode = isset($body['mode']) ? strtoupper(trim((string)$body['mode'])) : 'D';
    $validModes = ['A', 'B', 'C', 'D'];
    if ($attachmentId <= 0 || !in_array($mode, $validModes, true)) {
      return vp_files_json(['ok' => false, 'error' => 'invalid_input', 'request_id' => $rid], 400);
    }

    $path = get_attached_file($attachmentId);
    if (!$path || !file_exists($path)) {
      return vp_files_json(['ok' => false, 'error' => 'attachment_not_found', 'request_id' => $rid], 404);
    }

    $requireAuth = !empty($body['require_auth']) ? 1 : 0;
    if ($mode === 'C') {
      $requireAuth = 1;
    }

    $password = isset($body['password']) ? (string)$body['password'] : '';
    $passwordHash = null;
    if ($password !== '') {
      $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    }

    $expiresAt = vp_files_normalize_expires_at($body['ttl'] ?? null, $body['expires_at'] ?? null);
    $maxUses = isset($body['max_uses']) ? max(0, (int)$body['max_uses']) : 0;
    $tenantId = isset($body['tenant_id']) && $body['tenant_id'] !== null ? sanitize_text_field((string)$body['tenant_id']) : null;
    $meta = isset($body['meta']) && is_array($body['meta']) ? $body['meta'] : null;

    $token = vp_files_random_token();
    $inserted = $wpdb->insert(vp_files_links_table(), [
      'token' => $token,
      'attachment_id' => $attachmentId,
      'mode' => $mode,
      'tenant_id' => $tenantId,
      'require_auth' => $requireAuth,
      'password_hash' => $passwordHash,
      'max_uses' => $maxUses,
      'used_count' => 0,
      'expires_at' => $expiresAt,
      'revoked_at' => null,
      'created_at' => current_time('mysql', true),
      'created_by' => get_current_user_id() ?: null,
      'meta' => $meta ? wp_json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
    ], [
      '%s', '%d', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s',
    ]);

    if (!$inserted) {
      vp_files_runtime_log('error', 'create_link', 'db_insert_failed', ['request_id' => $rid]);
      return vp_files_json(['ok' => false, 'error' => 'db_insert_failed', 'request_id' => $rid], 500);
    }

    $publicUrl = home_url('/dl/' . $token);
    return vp_files_json([
      'ok' => true,
      'token' => $token,
      'public_url' => $publicUrl,
      'mode' => $mode,
      'expires_at' => $expiresAt,
      'max_uses' => $maxUses,
    ], 200);
  }
}

if (!function_exists('vp_files_is_blocked_private_ip')) {
  function vp_files_is_blocked_private_ip($ip) {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
      return true;
    }

    if ($ip === '::1') {
      return true;
    }

    $long = ip2long($ip);
    if ($long === false) {
      return false;
    }

    $ranges = [
      ['127.0.0.0', '255.0.0.0'],
      ['10.0.0.0', '255.0.0.0'],
      ['172.16.0.0', '255.240.0.0'],
      ['192.168.0.0', '255.255.0.0'],
    ];

    foreach ($ranges as $range) {
      $subnet = ip2long($range[0]);
      $mask = ip2long($range[1]);
      if (($long & $mask) === ($subnet & $mask)) {
        return true;
      }
    }

    return false;
  }
}

if (!function_exists('vp_files_validate_source_url')) {
  function vp_files_validate_source_url($url) {
    $parts = wp_parse_url((string)$url);
    if (!is_array($parts)) {
      return new WP_Error('invalid_url', 'Invalid source_url', ['status' => 400]);
    }

    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
      return new WP_Error('invalid_scheme', 'Only http/https are allowed', ['status' => 400]);
    }

    $host = strtolower((string)($parts['host'] ?? ''));
    if ($host === '' || $host === 'localhost') {
      return new WP_Error('blocked_host', 'Host is blocked', ['status' => 400]);
    }

    $ips = gethostbynamel($host);
    if (!is_array($ips) || count($ips) === 0) {
      $single = gethostbyname($host);
      $ips = [$single];
    }

    foreach ($ips as $ip) {
      if (vp_files_is_blocked_private_ip((string)$ip)) {
        return new WP_Error('blocked_ip', 'Resolved IP is blocked', ['status' => 400]);
      }
    }

    return true;
  }
}

if (!function_exists('vp_files_allowed_mimes')) {
  function vp_files_allowed_mimes() {
    $raw = (string)getenv('VP_FILES_ALLOWED_MIME');
    if ($raw === '') {
      return [
        'application/octet-stream',
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'text/plain',
        'application/zip',
      ];
    }

    $out = [];
    foreach (explode(',', $raw) as $item) {
      $mime = trim((string)$item);
      if ($mime !== '') {
        $out[] = $mime;
      }
    }

    return count($out) > 0 ? $out : ['application/octet-stream'];
  }
}

if (!function_exists('vp_files_handle_register_remote')) {
  function vp_files_handle_register_remote(WP_REST_Request $request) {
    $rid = vp_files_request_id();
    $body = $request->get_json_params();
    if (!is_array($body)) {
      $body = [];
    }

    $sourceUrl = isset($body['source_url']) ? trim((string)$body['source_url']) : '';
    if ($sourceUrl === '') {
      return vp_files_json(['ok' => false, 'error' => 'source_url_required', 'request_id' => $rid], 400);
    }

    $urlCheck = vp_files_validate_source_url($sourceUrl);
    if (is_wp_error($urlCheck)) {
      return vp_files_json(['ok' => false, 'error' => $urlCheck->get_error_code(), 'request_id' => $rid], (int)($urlCheck->get_error_data()['status'] ?? 400));
    }

    $maxMb = (int)getenv('VP_FILES_MAX_MB');
    if ($maxMb <= 0) {
      $maxMb = 100;
    }
    $maxBytes = $maxMb * 1024 * 1024;

    $tmp = vp_files_tempnam('vp-register-');
    if (!$tmp) {
      return vp_files_json(['ok' => false, 'error' => 'tempfile_failed', 'request_id' => $rid], 500);
    }

    $headers = [];
    if (vp_files_is_service_auth()) {
      $headers['Authorization'] = 'Bearer ' . vp_files_get_bearer_token();
    }

    $response = wp_remote_get($sourceUrl, [
      'timeout' => 60,
      'redirection' => 3,
      'stream' => true,
      'filename' => $tmp,
      'headers' => $headers,
      'reject_unsafe_urls' => true,
    ]);

    if (is_wp_error($response)) {
      @unlink($tmp);
      return vp_files_json(['ok' => false, 'error' => 'download_failed', 'request_id' => $rid], 502);
    }

    $statusCode = (int)wp_remote_retrieve_response_code($response);
    if ($statusCode < 200 || $statusCode >= 300) {
      @unlink($tmp);
      return vp_files_json(['ok' => false, 'error' => 'download_http_error', 'request_id' => $rid, 'status_code' => $statusCode], 502);
    }

    $size = filesize($tmp);
    if ($size === false || $size > $maxBytes) {
      @unlink($tmp);
      return vp_files_json(['ok' => false, 'error' => 'file_too_large', 'request_id' => $rid], 413);
    }

    $originalName = isset($body['original_name']) && $body['original_name'] !== null
      ? sanitize_file_name((string)$body['original_name'])
      : basename((string)wp_parse_url($sourceUrl, PHP_URL_PATH));
    if ($originalName === '' || $originalName === '/') {
      $originalName = 'remote.bin';
    }

    $mime = isset($body['mime']) && $body['mime'] !== null ? sanitize_text_field((string)$body['mime']) : '';
    if ($mime === '') {
      $remoteMime = (string)wp_remote_retrieve_header($response, 'content-type');
      if ($remoteMime !== '') {
        $mime = trim(explode(';', $remoteMime)[0]);
      }
    }

    $allowed = vp_files_allowed_mimes();
    if ($mime === '' || !in_array($mime, $allowed, true)) {
      $mime = 'application/octet-stream';
    }

    $stored = vp_files_store_file($tmp, $originalName, $mime);
    if (is_wp_error($stored)) {
      @unlink($tmp);
      return vp_files_json(['ok' => false, 'error' => 'store_failed', 'request_id' => $rid], 500);
    }

    return vp_files_json(array_merge(['ok' => true], $stored), 200);
  }
}

if (!function_exists('vp_files_log_access')) {
  function vp_files_log_access($linkId, $status) {
    global $wpdb;
    $wpdb->insert(vp_files_access_log_table(), [
      'link_id' => (int)$linkId,
      'ip' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field((string)$_SERVER['REMOTE_ADDR']) : null,
      'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field((string)$_SERVER['HTTP_USER_AGENT']) : null,
      'status' => sanitize_text_field((string)$status),
      'created_at' => current_time('mysql', true),
    ], ['%d', '%s', '%s', '%s', '%s']);
  }
}

if (!function_exists('vp_files_deny')) {
  function vp_files_deny($httpCode, $message = 'Forbidden') {
    status_header((int)$httpCode);
    nocache_headers();
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
  }
}

if (!function_exists('vp_files_stream_file')) {
  function vp_files_stream_file($path, $mime, $downloadName = '') {
    if (!file_exists($path) || !is_readable($path)) {
      vp_files_deny(404, 'Not found');
    }

    $size = filesize($path);
    if ($size === false) {
      $size = 0;
    }

    if ($downloadName === '') {
      $downloadName = basename($path);
    }

    nocache_headers();
    status_header(200);
    header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
    header('Content-Length: ' . (string)$size);
    header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    header('X-Content-Type-Options: nosniff');

    $fh = fopen($path, 'rb');
    if ($fh === false) {
      vp_files_deny(404, 'Not found');
    }

    while (!feof($fh)) {
      $chunk = fread($fh, 1024 * 1024);
      if ($chunk === false) {
        break;
      }
      echo $chunk;
      if (function_exists('fastcgi_finish_request')) {
        @ob_flush();
        flush();
      }
    }
    fclose($fh);
    exit;
  }
}

if (!function_exists('vp_files_handle_download')) {
  function vp_files_handle_download($token) {
    global $wpdb;

    $token = trim((string)$token);
    if ($token === '') {
      vp_files_deny(404, 'Not found');
    }

    $linksTable = vp_files_links_table();
    $link = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$linksTable} WHERE token = %s LIMIT 1", $token), ARRAY_A);

    if (!is_array($link)) {
      vp_files_deny(404, 'Not found');
    }

    $meta = null;
    if (!empty($link['meta'])) {
      $metaDecoded = json_decode((string)$link['meta'], true);
      if (is_array($metaDecoded)) {
        $meta = $metaDecoded;
      }
    }

    $mode = strtoupper((string)($link['mode'] ?? 'D'));
    $requireAuth = !empty($link['require_auth']) || $mode === 'C';
    $serviceAuth = vp_files_is_service_auth();
    $serviceCanBypassAuth = $serviceAuth && (!$requireAuth || !empty($meta['service_ok']));

    if (!empty($link['revoked_at'])) {
      vp_files_log_access((int)$link['id'], 'revoked');
      vp_files_deny(403, 'Forbidden');
    }

    if (!empty($link['expires_at']) && strtotime((string)$link['expires_at']) < time()) {
      vp_files_log_access((int)$link['id'], 'expired');
      vp_files_deny(403, 'Forbidden');
    }

    if ((int)$link['max_uses'] > 0 && (int)$link['used_count'] >= (int)$link['max_uses']) {
      vp_files_log_access((int)$link['id'], 'limit');
      vp_files_deny(403, 'Forbidden');
    }

    if ($requireAuth && !$serviceCanBypassAuth) {
      if (!is_user_logged_in()) {
        vp_files_log_access((int)$link['id'], 'auth_required');
        vp_files_deny(403, 'Forbidden');
      }
      if (!empty($link['tenant_id'])) {
        $tenantCookie = isset($_COOKIE['vp_tenant']) ? sanitize_text_field((string)$_COOKIE['vp_tenant']) : '';
        if ($tenantCookie === '' || $tenantCookie !== (string)$link['tenant_id']) {
          vp_files_log_access((int)$link['id'], 'auth_required');
          vp_files_deny(403, 'Forbidden');
        }
      }
    }

    $passwordHash = (string)($link['password_hash'] ?? '');
    $passwordRequired = ($passwordHash !== '') || $mode === 'B';
    if ($passwordRequired) {
      $provided = isset($_GET['p']) ? (string)wp_unslash($_GET['p']) : '';
      if ($passwordHash === '' || $provided === '' || !password_verify($provided, $passwordHash)) {
        vp_files_log_access((int)$link['id'], 'bad_password');
        vp_files_deny(403, 'Forbidden');
      }
    }

    $updated = $wpdb->query($wpdb->prepare(
      "UPDATE {$linksTable}
       SET used_count = used_count + 1,
           revoked_at = CASE WHEN mode = 'A' THEN %s ELSE revoked_at END
       WHERE id = %d
         AND revoked_at IS NULL
         AND (expires_at IS NULL OR expires_at > %s)
         AND (max_uses = 0 OR used_count < max_uses)",
      current_time('mysql', true),
      (int)$link['id'],
      current_time('mysql', true)
    ));

    if ((int)$updated !== 1) {
      vp_files_log_access((int)$link['id'], 'denied');
      vp_files_deny(403, 'Forbidden');
    }

    $attachmentId = (int)$link['attachment_id'];
    $path = get_attached_file($attachmentId);
    if (!$path || !file_exists($path)) {
      vp_files_log_access((int)$link['id'], 'not_found');
      vp_files_deny(404, 'Not found');
    }

    $mime = get_post_mime_type($attachmentId);
    $name = (string)get_post_meta($attachmentId, '_vp_original_name', true);
    if ($name === '') {
      $name = basename($path);
    }

    vp_files_log_access((int)$link['id'], 'ok');
    vp_files_stream_file($path, (string)$mime, $name);
  }
}

add_action('init', function () {
  vp_files_install_tables();

  add_rewrite_tag('%vp_dl_token%', '([^&]+)');
  add_rewrite_rule('^dl/([A-Za-z0-9_-]{16,128})/?$', 'index.php?vp_dl_token=$matches[1]', 'top');

  $stored = (string)get_option('vp_files_rewrite_version', '');
  if ($stored !== '1.0.0') {
    flush_rewrite_rules(false);
    update_option('vp_files_rewrite_version', '1.0.0', false);
  }
});

add_filter('query_vars', function ($vars) {
  $vars[] = 'vp_dl_token';
  return $vars;
});

add_action('template_redirect', function () {
  $token = get_query_var('vp_dl_token');
  if ($token) {
    vp_files_handle_download($token);
  }
}, 0);

add_action('rest_api_init', function () {
  register_rest_route('vp/v1', '/upload', [
    'methods' => 'POST',
    'callback' => 'vp_files_handle_upload',
    'permission_callback' => 'vp_files_rest_permission',
  ]);

  register_rest_route('vp/v1', '/links', [
    'methods' => 'POST',
    'callback' => 'vp_files_handle_create_link',
    'permission_callback' => 'vp_files_rest_permission',
  ]);

  register_rest_route('vp/v1', '/file/register', [
    'methods' => 'POST',
    'callback' => 'vp_files_handle_register_remote',
    'permission_callback' => 'vp_files_rest_permission',
  ]);
});
