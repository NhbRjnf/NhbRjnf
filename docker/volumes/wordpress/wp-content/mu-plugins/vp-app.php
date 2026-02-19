<?php
/**
 * Plugin Name: VP App Shell API
 * Description: /app endpoints and runtime logging for VP app shell.
 */

if (!defined('ABSPATH')) {
  exit;
}

if (!defined('VP_APP_ASSET_VERSION')) {
  define('VP_APP_ASSET_VERSION', (string)(function_exists('vp_app_asset_version') ? vp_app_asset_version() : '1.0.0'));
}

if (!function_exists('vp_app_request_id')) {
  function vp_app_request_id() {
    try {
      return bin2hex(random_bytes(8));
    } catch (Exception $e) {
      return uniqid('vpapp_', true);
    }
  }
}

if (!function_exists('vp_app_module_user_type')) {
  function vp_app_module_user_type($userType) {
    $type = trim((string)$userType);
    $map = [
      'dentist_doctor' => 'dentist',
      'clinic_admin' => 'dentist',
      'auto_business' => 'auto',
      'location_admin' => 'location',
      'content_partner' => 'partner',
      'car_owner' => 'car_owner',
    ];

    return $map[$type] ?? $type;
  }
}

if (!function_exists('vp_app_is_dentist_family')) {
  function vp_app_is_dentist_family($userType) {
    $type = trim((string)$userType);
    return in_array($type, ['dentist', 'dentist_doctor', 'clinic_admin'], true);
  }
}


if (!function_exists('vp_app_runtime_diag')) {
  function vp_app_runtime_diag($force = false) {
    static $cache = null;
    if ($cache !== null && !$force) {
      return $cache;
    }

    $runtimePath = '/opt/vseponyatno/runtime';
    $lastError = null;
    $isDir = is_dir($runtimePath);

    if (!$isDir) {
      $mk = @mkdir($runtimePath, 0775, true);
      clearstatcache();
      $isDir = is_dir($runtimePath);
      if (!$mk && !$isDir) {
        $e = error_get_last();
        $lastError = $e['message'] ?? 'mkdir failed';
      }
    }

    $isWritable = $isDir && is_writable($runtimePath);
    if ($isDir) {
      $probe = @file_put_contents($runtimePath . '/.vp-runtime-check.log', date(DATE_ATOM) . " runtime-check\n", FILE_APPEND | LOCK_EX);
      if ($probe === false) {
        $e = error_get_last();
        $lastError = $e['message'] ?? $lastError;
        $isWritable = false;
      } else {
        $isWritable = true;
      }
    }

    $uid = function_exists('posix_geteuid') ? @posix_geteuid() : null;
    $gid = function_exists('posix_getegid') ? @posix_getegid() : null;
    $user = function_exists('get_current_user') ? (string)get_current_user() : 'unknown';
    $openBaseDir = (string)ini_get('open_basedir');

    $cache = [
      'runtime_path' => $runtimePath,
      'is_dir' => (bool)$isDir,
      'is_writable' => (bool)$isWritable,
      'php_user' => $user,
      'php_uid' => $uid,
      'php_gid' => $gid,
      'php_sapi' => (string)php_sapi_name(),
      'open_basedir' => $openBaseDir,
      'last_error' => $lastError,
    ];

    if (!$cache['is_writable']) {
      error_log('[vp-app] runtime diag failed path=' . $runtimePath . ' is_dir=' . ($cache['is_dir'] ? '1' : '0') . ' writable=' . ($cache['is_writable'] ? '1' : '0') . ' uid=' . (string)$uid . ' gid=' . (string)$gid . ' user=' . $user . ' sapi=' . $cache['php_sapi'] . ' open_basedir=' . $openBaseDir . ' last_error=' . (string)$lastError);
    }

    return $cache;
  }
}

if (!function_exists('vp_app_sanitize_log_context')) {
  function vp_app_sanitize_log_context($ctx = []) {
    $safeCtx = is_array($ctx) ? $ctx : [];
    foreach (['access_token', 'refresh_token', 'token', 'password', 'vp_dx_at', 'vp_dx_rt'] as $secretKey) {
      if (isset($safeCtx[$secretKey])) {
        unset($safeCtx[$secretKey]);
      }
    }
    return $safeCtx;
  }
}

if (!function_exists('vp_app_runtime_log_write')) {
  function vp_app_runtime_log_write($fileName, $line, $fallbackTag) {
    $diag = vp_app_runtime_diag();
    $logFile = rtrim((string)$diag['runtime_path'], '/') . '/' . ltrim((string)$fileName, '/');
    $written = @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    if ($written === false) {
      error_log('[' . $fallbackTag . '] runtime log write failed: ' . $line);
    }
  }
}

if (!function_exists('vp_app_runtime_log')) {
  function vp_app_runtime_log($level, $action, $message, $ctx = []) {
    $userId = 'unknown';
    if (!empty($ctx['user_id'])) {
      $userId = (string)$ctx['user_id'];
    }

    $safeCtx = vp_app_sanitize_log_context($ctx);
    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field((string)$_SERVER['REMOTE_ADDR']) : 'unknown';
    $line = sprintf(
      '%s [vp-app] level=%s action=%s user=%s ip=%s msg="%s" ctx=%s' . "
",
      date(DATE_ATOM),
      sanitize_text_field((string)$level),
      sanitize_text_field((string)$action),
      sanitize_text_field((string)$userId),
      $ip,
      str_replace('"', '\"', (string)$message),
      wp_json_encode($safeCtx, JSON_UNESCAPED_UNICODE)
    );

    vp_app_runtime_log_write('vp-app.log', $line, 'vp-app');
  }
}

if (!function_exists('vp_app_json')) {
  function vp_app_json($payload, $status = 200) {
    return new WP_REST_Response($payload, $status);
  }
}

if (!function_exists('vp_app_directus_base_url')) {
  function vp_app_directus_base_url() {
    $base = getenv('DIRECTUS_URL');
    if (!$base) {
      $base = getenv('DIRECTUS_BASE_URL');
    }
    return $base ? rtrim((string)$base, '/') : '';
  }
}

if (!function_exists('vp_app_access_token')) {
  function vp_app_access_token() {
    return isset($_COOKIE['vp_dx_at']) ? trim((string)$_COOKIE['vp_dx_at']) : '';
  }
}

if (!function_exists('vp_app_cookie_options')) {
  function vp_app_cookie_options($expires) {
    return [
      'expires' => (int)$expires,
      'path' => '/',
      'secure' => is_ssl(),
      'httponly' => true,
      'samesite' => 'Lax',
    ];
  }
}

if (!function_exists('vp_app_directus_request')) {
  function vp_app_directus_request($method, $path, $token = '', $body = null) {
    $base = vp_app_directus_base_url();
    if ($base === '') {
      return new WP_Error('vp_app_directus_env_missing', 'DIRECTUS URL is missing', ['status' => 500]);
    }

    $headers = ['Accept' => 'application/json'];
    if ($token !== '') {
      $headers['Authorization'] = 'Bearer ' . $token;
    }

    $args = [
      'method' => strtoupper((string)$method),
      'timeout' => 30,
      'headers' => $headers,
    ];

    if ($body !== null) {
      $args['headers']['Content-Type'] = 'application/json';
      $args['body'] = wp_json_encode($body, JSON_UNESCAPED_UNICODE);
    }

    $response = wp_remote_request($base . $path, $args);

    if (is_wp_error($response)) {
      return $response;
    }

    $status = (int)wp_remote_retrieve_response_code($response);
    $raw = (string)wp_remote_retrieve_body($response);
    $json = json_decode($raw, true);

    if ($status < 200 || $status >= 300) {
      $message = 'Directus request failed';
      if (is_array($json) && !empty($json['errors'][0]['message'])) {
        $message = (string)$json['errors'][0]['message'];
      }
      return new WP_Error('vp_app_directus_http_error', $message, ['status' => $status, 'body' => $json]);
    }

    return is_array($json) ? $json : ['data' => null];
  }
}

if (!function_exists('vp_app_runtime_log_dentist')) {
  function vp_app_runtime_log_dentist($level, $action, $message, $ctx = []) {
    $safeCtx = vp_app_sanitize_log_context($ctx);

    $line = sprintf(
      '%s [vp-dentist] level=%s action=%s user=%s tenant=%s msg="%s" ctx=%s' . "
",
      date(DATE_ATOM),
      sanitize_text_field((string)$level),
      sanitize_text_field((string)$action),
      sanitize_text_field((string)($safeCtx['user_id'] ?? 'unknown')),
      sanitize_text_field((string)($safeCtx['tenant_id'] ?? 'unknown')),
      str_replace('"', '\"', (string)$message),
      wp_json_encode($safeCtx, JSON_UNESCAPED_UNICODE)
    );

    vp_app_runtime_log_write('vp-dentist.log', $line, 'vp-dentist');
  }
}

if (!function_exists('vp_app_is_directus_id')) {
  function vp_app_is_directus_id($value) {
    $stringValue = trim((string)$value);
    if ($stringValue === '') {
      return false;
    }

    if (preg_match('/^[0-9]+$/', $stringValue) === 1) {
      return true;
    }

    return preg_match('/^[0-9a-fA-F]{8}\-[0-9a-fA-F]{4}\-[1-5][0-9a-fA-F]{3}\-[89abAB][0-9a-fA-F]{3}\-[0-9a-fA-F]{12}$/', $stringValue) === 1;
  }
}

if (!function_exists('vp_app_dentist_context')) {
  function vp_app_dentist_context($token, $requestId, $action) {
    $me = vp_app_fetch_me($token);
    if (is_wp_error($me)) {
      $status = (int)($me->get_error_data()['status'] ?? 500);
      vp_app_runtime_log_dentist('error', $action, $me->get_error_message(), ['request_id' => $requestId, 'status' => $status]);
      return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => $me->get_error_message()], $status);
    }

    $userId = (string)($me['id'] ?? '');
    if ($userId === '') {
      vp_app_runtime_log_dentist('error', $action, 'Directus user id missing', ['request_id' => $requestId]);
      return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => 'unauthorized'], 401);
    }

    $profile = vp_app_fetch_profile($serviceToken, $userId);
    if (is_wp_error($profile)) {
      $status = (int)($profile->get_error_data()['status'] ?? 500);
      vp_app_runtime_log_dentist('error', $action, $profile->get_error_message(), ['request_id' => $requestId, 'status' => $status, 'user_id' => $userId]);
      return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => $profile->get_error_message()], $status);
    }

    if (!vp_app_is_dentist_family((string)($profile['user_type'] ?? ''))) {
      vp_app_runtime_log_dentist('error', $action, 'User is not dentist', ['request_id' => $requestId, 'user_id' => $userId]);
      return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => 'forbidden'], 403);
    }

    $tenantId = trim((string)($_COOKIE['vp_tenant'] ?? ''));
    if ($tenantId === '') {
      vp_app_runtime_log_dentist('error', $action, 'Active tenant is missing', ['request_id' => $requestId, 'user_id' => $userId]);
      return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => 'tenant_required'], 400);
    }

    $memberships = vp_app_fetch_memberships($token, $userId);
    if (is_wp_error($memberships)) {
      $status = (int)($memberships->get_error_data()['status'] ?? 500);
      vp_app_runtime_log_dentist('error', $action, $memberships->get_error_message(), ['request_id' => $requestId, 'status' => $status, 'user_id' => $userId, 'tenant_id' => $tenantId]);
      return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => $memberships->get_error_message()], $status);
    }

    $activeTenant = vp_app_active_tenant(vp_app_normalize_tenants($memberships), $tenantId);
    if ($activeTenant === null) {
      vp_app_runtime_log_dentist('error', $action, 'Tenant is not available for user', ['request_id' => $requestId, 'user_id' => $userId, 'tenant_id' => $tenantId]);
      return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => 'forbidden'], 403);
    }

    return [
      'user_id' => $userId,
      'tenant_id' => $tenantId,
    ];
  }
}

if (!function_exists('vp_app_dentist_case_code')) {
  function vp_app_dentist_case_code() {
    return sprintf('DENT-%s-%04d', wp_date('Ymd'), random_int(0, 9999));
  }
}

if (!function_exists('vp_app_fetch_me')) {
  function vp_app_fetch_me($token) {
    $me = vp_app_directus_request('GET', '/users/me', $token);
    if (is_wp_error($me)) {
      return $me;
    }
    return $me['data'] ?? null;
  }
}

if (!function_exists('vp_app_fetch_profile')) {
  function vp_app_fetch_profile($token, $userId) {
    $filter = rawurlencode(wp_json_encode(['user_id' => ['_eq' => (string)$userId]]));
    $res = vp_app_directus_request('GET', '/items/vp_user_profiles?limit=1&filter=' . $filter, $token);
    if (is_wp_error($res)) {
      return $res;
    }
    return $res['data'][0] ?? null;
  }
}


if (!function_exists('vp_app_service_token')) {
  function vp_app_service_token() {
    $token = getenv('VP_DIRECTUS_TOKEN');
    if (!$token) {
      $token = getenv('DIRECTUS_TOKEN');
    }
    return $token ? trim((string)$token) : '';
  }
}


if (!function_exists('vp_app_session_expired_response')) {
  function vp_app_session_expired_response($requestId) {
    vp_app_runtime_log('warn', 'session_expired', 'Session expired, re-login required', ['request_id' => $requestId]);
    return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => 'session expired, please login'], 401);
  }
}

if (!function_exists('vp_app_require_service_token')) {
  function vp_app_require_service_token($requestId, $action) {
    $serviceToken = vp_app_service_token();
    if ($serviceToken === '') {
      vp_app_runtime_log('error', $action, 'Service token is missing', ['request_id' => $requestId]);
      return new WP_Error('vp_app_service_token_missing', 'service_token_missing', ['status' => 500]);
    }
    return $serviceToken;
  }
}

if (!function_exists('vp_app_directus_request_with_service_fallback')) {
  function vp_app_directus_request_with_service_fallback($method, $path, $userToken = '', $body = null) {
    $serviceToken = vp_app_service_token();
    if ($serviceToken !== '') {
      return vp_app_directus_request($method, $path, $serviceToken, $body);
    }
    return vp_app_directus_request($method, $path, $userToken, $body);
  }
}

if (!function_exists('vp_app_detect_profile_avatar_field')) {
  function vp_app_detect_profile_avatar_field($token) {
    static $cached = null;
    if ($cached !== null) {
      return $cached;
    }

    $res = vp_app_directus_request_with_service_fallback('GET', '/fields/vp_user_profiles', $token);
    if (is_wp_error($res)) {
      $cached = '';
      return $cached;
    }

    $candidates = ['avatar_file', 'avatar', 'avatar_id', 'avatar_image'];
    $fields = is_array($res['data'] ?? null) ? $res['data'] : [];
    foreach ($fields as $fieldMeta) {
      $field = (string)($fieldMeta['field'] ?? '');
      if (in_array($field, $candidates, true)) {
        $cached = $field;
        return $cached;
      }
    }

    $cached = '';
    return $cached;
  }
}

if (!function_exists('vp_app_profile_avatar_info')) {
  function vp_app_profile_avatar_info($profile) {
    $profile = is_array($profile) ? $profile : [];
    $avatarId = '';
    foreach (['avatar_file', 'avatar', 'avatar_id', 'avatar_image'] as $key) {
      if (!isset($profile[$key])) {
        continue;
      }
      $value = $profile[$key];
      if (is_array($value)) {
        $avatarId = trim((string)($value['id'] ?? ''));
      } else {
        $avatarId = trim((string)$value);
      }
      if ($avatarId !== '') {
        break;
      }
    }

    $avatarUrl = '';
    if ($avatarId !== '') {
      $avatarUrl = home_url('/wp-json/vp/v1/app/profile/avatar') . '?ver=' . rawurlencode((string)VP_APP_ASSET_VERSION);
    }

    return [
      'avatar_file' => $avatarId,
      'avatar_url' => $avatarUrl,
    ];
  }
}

if (!function_exists('vp_app_build_profile_payload')) {
  function vp_app_build_profile_payload($profile) {
    $profile = is_array($profile) ? $profile : [];
    $avatar = vp_app_profile_avatar_info($profile);

    return [
      'id' => (int)($profile['id'] ?? 0),
      'user_id' => (string)($profile['user_id'] ?? ''),
      'user_type' => (string)($profile['user_type'] ?? ''),
      'module_user_type' => vp_app_module_user_type((string)($profile['user_type'] ?? '')),
      'status' => (string)($profile['status'] ?? ''),
      'phone' => (string)($profile['phone'] ?? ''),
      'avatar_file' => (string)$avatar['avatar_file'],
      'avatar_url' => (string)$avatar['avatar_url'],
    ];
  }
}

if (!function_exists('vp_app_directus_upload_file')) {
  function vp_app_directus_upload_file($token, $tmpPath, $fileName, $mimeType) {
    $base = vp_app_directus_base_url();
    if ($base === '') {
      return new WP_Error('vp_app_directus_env_missing', 'DIRECTUS URL is missing', ['status' => 500]);
    }

    if (!function_exists('curl_file_create')) {
      return new WP_Error('vp_app_curl_missing', 'curl_file_create is not available', ['status' => 500]);
    }

    $ch = curl_init($base . '/files');
    if ($ch === false) {
      return new WP_Error('vp_app_curl_init_failed', 'Unable to initialize cURL', ['status' => 500]);
    }

    $headers = ['Accept: application/json'];
    if ($token !== '') {
      $headers[] = 'Authorization: Bearer ' . $token;
    }

    $postFields = [
      'file' => curl_file_create($tmpPath, $mimeType, $fileName),
    ];

    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => $postFields,
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_TIMEOUT => 60,
    ]);

    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
      return new WP_Error('vp_app_directus_curl_error', $error !== '' ? $error : 'Directus upload failed', ['status' => 500]);
    }

    $json = json_decode((string)$raw, true);
    if ($status < 200 || $status >= 300) {
      $message = 'Directus upload failed';
      if (is_array($json) && !empty($json['errors'][0]['message'])) {
        $message = (string)$json['errors'][0]['message'];
      }
      return new WP_Error('vp_app_directus_upload_error', $message, ['status' => $status, 'body' => $json]);
    }

    return is_array($json) ? $json : ['data' => null];
  }
}

if (!function_exists('vp_app_directus_fetch_asset')) {
  function vp_app_directus_fetch_asset($token, $fileId) {
    $base = vp_app_directus_base_url();
    if ($base === '') {
      return new WP_Error('vp_app_directus_env_missing', 'DIRECTUS URL is missing', ['status' => 500]);
    }

    $headers = [];
    if ($token !== '') {
      $headers['Authorization'] = 'Bearer ' . $token;
    }

    $response = wp_remote_get($base . '/assets/' . rawurlencode((string)$fileId), [
      'timeout' => 30,
      'headers' => $headers,
    ]);

    if (is_wp_error($response)) {
      return $response;
    }

    $status = (int)wp_remote_retrieve_response_code($response);
    if ($status < 200 || $status >= 300) {
      return new WP_Error('vp_app_directus_http_error', 'Directus asset request failed', ['status' => $status]);
    }

    return [
      'content_type' => (string)wp_remote_retrieve_header($response, 'content-type'),
      'body' => (string)wp_remote_retrieve_body($response),
    ];
  }
}

if (!function_exists('vp_app_fetch_memberships')) {
  function vp_app_fetch_memberships($token, $userId) {
    $fields = rawurlencode('id,tenant_id,user_id,role,status,created_at');
    $filter = rawurlencode(wp_json_encode(['user_id' => ['_eq' => (string)$userId], 'status' => ['_eq' => 'active']]));
    $res = vp_app_directus_request('GET', '/items/vp_memberships?limit=-1&fields=' . $fields . '&filter=' . $filter, $token);
    if (is_wp_error($res)) {
      return $res;
    }
    return is_array($res['data'] ?? null) ? $res['data'] : [];
  }
}

if (!function_exists('vp_app_fetch_tenants_by_ids')) {
  function vp_app_fetch_tenants_by_ids($token, $tenantIds) {
    $ids = [];
    foreach ((array)$tenantIds as $tenantId) {
      $tenantId = trim((string)$tenantId);
      if ($tenantId !== '') {
        $ids[] = $tenantId;
      }
    }

    if (empty($ids)) {
      return [];
    }

    $filter = rawurlencode(wp_json_encode(['id' => ['_in' => array_values(array_unique($ids))]]));
    $fields = rawurlencode('id,title,slug,status');
    $res = vp_app_directus_request('GET', '/items/vp_tenants?limit=-1&fields=' . $fields . '&filter=' . $filter, $token);
    if (is_wp_error($res)) {
      return $res;
    }

    $map = [];
    foreach (($res['data'] ?? []) as $row) {
      $id = trim((string)($row['id'] ?? ''));
      if ($id !== '') {
        $map[$id] = $row;
      }
    }

    return $map;
  }
}

if (!function_exists('vp_app_membership_tenant_id')) {
  function vp_app_membership_tenant_id($membership) {
    if (isset($membership['tenant_id']) && is_array($membership['tenant_id'])) {
      return trim((string)($membership['tenant_id']['id'] ?? ''));
    }

    return trim((string)($membership['tenant_id'] ?? ''));
  }
}

if (!function_exists('vp_app_membership_role')) {
  function vp_app_membership_role($membership) {
    $role = trim((string)($membership['member_role'] ?? ''));
    if ($role !== '') {
      return $role;
    }

    $role = trim((string)($membership['role'] ?? ''));
    return $role !== '' ? $role : 'viewer';
  }
}

if (!function_exists('vp_app_normalize_tenants')) {
  function vp_app_normalize_tenants($memberships, $tenantMap = [], $profileUserType = '') {
    $tenants = [];
    foreach ($memberships as $membership) {
      $tenantId = vp_app_membership_tenant_id($membership);
      if ($tenantId === '') {
        continue;
      }

      $tenant = is_array($tenantMap[$tenantId] ?? null) ? $tenantMap[$tenantId] : [];
      $tenantTitle = trim((string)($tenant['title'] ?? ''));
      if ($tenantTitle === '' && isset($membership['tenant_id']) && is_array($membership['tenant_id'])) {
        $tenantTitle = trim((string)($membership['tenant_id']['title'] ?? $membership['tenant_id']['name'] ?? ''));
      }

      $tenants[] = [
        'tenant_id' => $tenantId,
        'tenant_title' => $tenantTitle !== '' ? $tenantTitle : $tenantId,
        'member_role' => vp_app_membership_role($membership),
        'status' => (string)($membership['status'] ?? ''),
        'user_type' => (string)$profileUserType,
      ];
    }
    return $tenants;
  }
}

if (!function_exists('vp_app_active_tenant')) {
  function vp_app_active_tenant($tenants, $requestedTenant) {
    $requested = trim((string)$requestedTenant);
    if ($requested === '') {
      return null;
    }

    foreach ($tenants as $tenant) {
      if ((string)$tenant['tenant_id'] === $requested) {
        return [
          'tenant_id' => (string)$tenant['tenant_id'],
          'tenant_title' => (string)$tenant['tenant_title'],
        ];
      }
    }

    return null;
  }
}

if (!function_exists('vp_app_build_payload')) {
  function vp_app_build_payload($token, $requestId) {
    $me = vp_app_fetch_me($token);
    if (is_wp_error($me)) {
      return $me;
    }

    $userId = (string)($me['id'] ?? '');
    $profile = $userId !== '' ? vp_app_fetch_profile($token, $userId) : null;
    if (is_wp_error($profile)) {
      return $profile;
    }

    $memberships = $userId !== '' ? vp_app_fetch_memberships($token, $userId) : [];
    if (is_wp_error($memberships)) {
      return $memberships;
    }

    $tenantIds = [];
    foreach ($memberships as $membership) {
      $tenantId = vp_app_membership_tenant_id($membership);
      if ($tenantId !== '') {
        $tenantIds[] = $tenantId;
      }
    }

    $tenantMap = vp_app_fetch_tenants_by_ids($token, $tenantIds);
    if (is_wp_error($tenantMap)) {
      return $tenantMap;
    }

    $profileUserType = (string)($profile['user_type'] ?? '');
    $tenants = vp_app_normalize_tenants($memberships, $tenantMap, $profileUserType);
    $activeTenant = vp_app_active_tenant($tenants, $_COOKIE['vp_tenant'] ?? '');

    return [
      'ok' => true,
      'request_id' => $requestId,
      'user' => [
        'id' => (string)($me['id'] ?? ''),
        'email' => (string)($me['email'] ?? ''),
        'first_name' => (string)($me['first_name'] ?? ''),
        'last_name' => (string)($me['last_name'] ?? ''),
      ],
      'profile' => vp_app_build_profile_payload($profile),
      'tenants' => $tenants,
      'active_tenant' => $activeTenant,
      'asset_version' => VP_APP_ASSET_VERSION,
    ];
  }
}

if (!function_exists('vp_app_require_auth_token')) {
  function vp_app_require_auth_token($requestId, $action = 'auth') {
    $token = vp_app_access_token();
    if ($token === '') {
      vp_app_runtime_log('error', $action, 'Unauthorized: vp_dx_at is missing', ['request_id' => $requestId]);
      return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => 'unauthorized'], 401);
    }
    return $token;
  }
}

add_action('init', function () {
  vp_app_runtime_diag(false);
});

add_action('rest_api_init', function () {
  register_rest_route('vp/v1/app', '/me', [
    'methods' => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function () {
      $requestId = vp_app_request_id();
      $token = vp_app_require_auth_token($requestId, 'app_me');
      if ($token instanceof WP_REST_Response) {
        return $token;
      }

      $payload = vp_app_build_payload($token, $requestId);
      if (is_wp_error($payload)) {
        $status = (int)($payload->get_error_data()['status'] ?? 500);
        vp_app_runtime_log('error', 'app_me', $payload->get_error_message(), [
          'request_id' => $requestId,
          'status' => $status,
          'user_id' => 'unknown',
        ]);
        return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => $payload->get_error_message()], $status);
      }

      vp_app_runtime_log('info', 'app_me_boot', 'Payload built', [
        'request_id' => $requestId,
        'user_id' => (string)($payload['user']['id'] ?? 'unknown'),
        'memberships_count' => count($payload['tenants'] ?? []),
        'active_tenant_id' => (string)($payload['active_tenant']['tenant_id'] ?? ''),
      ]);

      return vp_app_json($payload, 200);
    },
  ]);

  register_rest_route('vp/v1/app', '/tenants', [
    'methods' => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function () {
      $requestId = vp_app_request_id();
      $token = vp_app_require_auth_token($requestId, 'app_tenants');
      if ($token instanceof WP_REST_Response) {
        return $token;
      }

      $me = vp_app_fetch_me($token);
      if (is_wp_error($me)) {
        $status = (int)($me->get_error_data()['status'] ?? 500);
        vp_app_runtime_log('error', 'app_tenants', $me->get_error_message(), ['request_id' => $requestId, 'status' => $status]);
        return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => $me->get_error_message()], $status);
      }

      $userId = (string)($me['id'] ?? '');
      $profile = $userId !== '' ? vp_app_fetch_profile($token, $userId) : null;
      if (is_wp_error($profile)) {
        $status = (int)($profile->get_error_data()['status'] ?? 500);
        vp_app_runtime_log('error', 'app_tenants', $profile->get_error_message(), ['request_id' => $requestId, 'status' => $status, 'user_id' => $userId]);
        return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => $profile->get_error_message()], $status);
      }

      $memberships = vp_app_fetch_memberships($token, $userId);
      if (is_wp_error($memberships)) {
        $status = (int)($memberships->get_error_data()['status'] ?? 500);
        vp_app_runtime_log('error', 'app_tenants', $memberships->get_error_message(), ['request_id' => $requestId, 'status' => $status, 'user_id' => $userId]);
        return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => $memberships->get_error_message()], $status);
      }

      $tenantIds = [];
      foreach ($memberships as $membership) {
        $tenantId = vp_app_membership_tenant_id($membership);
        if ($tenantId !== '') {
          $tenantIds[] = $tenantId;
        }
      }

      $tenantMap = vp_app_fetch_tenants_by_ids($token, $tenantIds);
      if (is_wp_error($tenantMap)) {
        $status = (int)($tenantMap->get_error_data()['status'] ?? 500);
        vp_app_runtime_log('error', 'app_tenants', $tenantMap->get_error_message(), ['request_id' => $requestId, 'status' => $status, 'user_id' => $userId]);
        return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => $tenantMap->get_error_message()], $status);
      }

      return vp_app_json([
        'ok' => true,
        'request_id' => $requestId,
        'tenants' => vp_app_normalize_tenants($memberships, $tenantMap, (string)($profile['user_type'] ?? '')),
      ], 200);
    },
  ]);

  register_rest_route('vp/v1/app', '/tenant', [
    'methods' => 'POST',
    'permission_callback' => '__return_true',
    'callback' => function (WP_REST_Request $request) {
      $requestId = vp_app_request_id();
      $token = vp_app_require_auth_token($requestId, 'tenant_set');
      if ($token instanceof WP_REST_Response) {
        return $token;
      }

      $tenantId = trim((string)$request->get_param('tenant_id'));
      if ($tenantId === '') {
        vp_app_runtime_log('error', 'tenant_set', 'tenant_id is required', ['request_id' => $requestId]);
        return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => 'tenant_id_required'], 400);
      }

      $me = vp_app_fetch_me($token);
      if (is_wp_error($me)) {
        $status = (int)($me->get_error_data()['status'] ?? 500);
        vp_app_runtime_log('error', 'tenant_set', $me->get_error_message(), ['request_id' => $requestId, 'status' => $status]);
        return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => $me->get_error_message()], $status);
      }

      $userId = (string)($me['id'] ?? '');
      $memberships = vp_app_fetch_memberships($token, $userId);
      if (is_wp_error($memberships)) {
        $status = (int)($memberships->get_error_data()['status'] ?? 500);
        vp_app_runtime_log('error', 'tenant_set', $memberships->get_error_message(), ['request_id' => $requestId, 'status' => $status, 'user_id' => $userId]);
        return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => $memberships->get_error_message()], $status);
      }

      $available = vp_app_normalize_tenants($memberships);
      $activeTenant = vp_app_active_tenant($available, $tenantId);
      if ($activeTenant === null) {
        vp_app_runtime_log('error', 'tenant_set', 'Tenant is not available for user', ['request_id' => $requestId, 'tenant_id' => $tenantId, 'user_id' => $userId]);
        return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => 'forbidden'], 403);
      }

      setcookie('vp_tenant', $tenantId, vp_app_cookie_options(time() + (30 * DAY_IN_SECONDS)));
      $_COOKIE['vp_tenant'] = $tenantId;

      vp_app_runtime_log('info', 'tenant_set', 'Tenant switched', ['request_id' => $requestId, 'tenant_id' => $tenantId, 'user_id' => $userId]);
      return vp_app_json(['ok' => true, 'request_id' => $requestId], 200);
    },
  ]);


  register_rest_route('vp/v1/app', '/profile', [
    'methods' => 'PATCH',
    'permission_callback' => '__return_true',
    'callback' => function (WP_REST_Request $request) {
      $requestId = vp_app_request_id();
      $token = vp_app_require_auth_token($requestId, 'profile_update');
      if ($token instanceof WP_REST_Response) {
        return $token;
      }

      $rawParams = $request->get_json_params();
      $firstName = trim((string)($rawParams['first_name'] ?? ''));
      $lastName = trim((string)($rawParams['last_name'] ?? ''));
      $phone = trim((string)($rawParams['phone'] ?? ''));

      if (mb_strlen($firstName) > 80 || mb_strlen($lastName) > 80 || mb_strlen($phone) > 64) {
        vp_app_runtime_log('error', 'profile_update_error', 'Validation failed', ['request_id' => $requestId]);
        return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => 'validation_error'], 400);
      }

      $me = vp_app_fetch_me($token);
      if (is_wp_error($me)) {
        $status = (int)($me->get_error_data()['status'] ?? 500);
        if ($status === 401) {
          return vp_app_session_expired_response($requestId);
        }
        vp_app_runtime_log('error', 'profile_update_error', $me->get_error_message(), ['request_id' => $requestId, 'status' => $status]);
        return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => $me->get_error_message()], $status);
      }

      $userId = (string)($me['id'] ?? '');
      if ($userId === '') {
        vp_app_runtime_log('error', 'profile_update_error', 'User id missing', ['request_id' => $requestId]);
        return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => 'unauthorized'], 401);
      }

      $serviceToken = vp_app_require_service_token($requestId, 'profile_update_error');
      if (is_wp_error($serviceToken)) {
        $status = (int)($serviceToken->get_error_data()['status'] ?? 500);
        return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => $serviceToken->get_error_message()], $status);
      }

      $userPatch = vp_app_directus_request('PATCH', '/users/' . rawurlencode($userId), $serviceToken, [
        'first_name' => $firstName,
        'last_name' => $lastName,
      ]);
      if (is_wp_error($userPatch)) {
        $status = (int)($userPatch->get_error_data()['status'] ?? 500);
        vp_app_runtime_log('error', 'profile_update_error', $userPatch->get_error_message(), ['request_id' => $requestId, 'status' => $status, 'user_id' => $userId]);
        return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => $userPatch->get_error_message()], $status);
      }

      $profile = vp_app_fetch_profile($serviceToken, $userId);
      if (is_wp_error($profile) || !is_array($profile)) {
        $status = is_wp_error($profile) ? (int)($profile->get_error_data()['status'] ?? 500) : 404;
        $message = is_wp_error($profile) ? $profile->get_error_message() : 'profile_not_found';
        vp_app_runtime_log('error', 'profile_update_error', $message, ['request_id' => $requestId, 'status' => $status, 'user_id' => $userId]);
        return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => $message], $status);
      }

      $profileId = (string)($profile['id'] ?? '');
      $profilePatch = vp_app_directus_request('PATCH', '/items/vp_user_profiles/' . rawurlencode($profileId), $serviceToken, [
        'phone' => $phone,
      ]);
      if (is_wp_error($profilePatch)) {
        $status = (int)($profilePatch->get_error_data()['status'] ?? 500);
        vp_app_runtime_log('error', 'profile_update_error', $profilePatch->get_error_message(), ['request_id' => $requestId, 'status' => $status, 'user_id' => $userId]);
        return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => $profilePatch->get_error_message()], $status);
      }

      $payload = vp_app_build_payload($token, $requestId);
      if (is_wp_error($payload)) {
        $status = (int)($payload->get_error_data()['status'] ?? 500);
        vp_app_runtime_log('error', 'profile_update_error', $payload->get_error_message(), ['request_id' => $requestId, 'status' => $status, 'user_id' => $userId]);
        return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => $payload->get_error_message()], $status);
      }

      vp_app_runtime_log('info', 'profile_update_success', 'Profile updated', ['request_id' => $requestId, 'user_id' => $userId]);
      return vp_app_json(['ok' => true, 'request_id' => $requestId, 'me' => $payload], 200);
    },
  ]);

  register_rest_route('vp/v1/app', '/profile/avatar', [
    'methods' => 'POST',
    'permission_callback' => '__return_true',
    'callback' => function () {
      $requestId = vp_app_request_id();
      $token = vp_app_require_auth_token($requestId, 'avatar_upload');
      if ($token instanceof WP_REST_Response) {
        return $token;
      }

      if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
        vp_app_runtime_log('error', 'avatar_upload_error', 'File is missing', ['request_id' => $requestId]);
        return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => 'file_required'], 400);
      }

      $file = $_FILES['file'];
      $tmpPath = (string)($file['tmp_name'] ?? '');
      $size = (int)($file['size'] ?? 0);
      $type = (string)($file['type'] ?? '');
      $name = sanitize_file_name((string)($file['name'] ?? 'avatar'));
      $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
      $maxSize = 5 * 1024 * 1024;

      if ($tmpPath === '' || !file_exists($tmpPath)) {
        return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => 'upload_failed'], 400);
      }

      if (!in_array($type, $allowedTypes, true)) {
        return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => 'invalid_file_type'], 400);
      }

      if ($size <= 0 || $size > $maxSize) {
        return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => 'invalid_file_size'], 400);
      }

      $me = vp_app_fetch_me($token);
      if (is_wp_error($me)) {
        $status = (int)($me->get_error_data()['status'] ?? 500);
        if ($status === 401) {
          return vp_app_session_expired_response($requestId);
        }
        vp_app_runtime_log('error', 'avatar_upload_error', $me->get_error_message(), ['request_id' => $requestId, 'status' => $status]);
        return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => $me->get_error_message()], $status);
      }
      $userId = (string)($me['id'] ?? '');

      $serviceToken = vp_app_require_service_token($requestId, 'avatar_upload_error');
      if (is_wp_error($serviceToken)) {
        $status = (int)($serviceToken->get_error_data()['status'] ?? 500);
        return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => $serviceToken->get_error_message()], $status);
      }

      $upload = vp_app_directus_upload_file($serviceToken, $tmpPath, $name !== '' ? $name : 'avatar', $type);
      if (is_wp_error($upload)) {
        $status = (int)($upload->get_error_data()['status'] ?? 500);
        vp_app_runtime_log('error', 'avatar_upload_error', $upload->get_error_message(), ['request_id' => $requestId, 'status' => $status, 'user_id' => $userId, 'size' => $size, 'type' => $type, 'which_token' => 'service', 'directus_status' => $status]);
        return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => $upload->get_error_message()], $status);
      }

      $fileData = is_array($upload['data'] ?? null) ? $upload['data'] : [];
      $fileId = (string)($fileData['id'] ?? '');
      if ($fileId === '') {
        vp_app_runtime_log('error', 'avatar_upload_error', 'Directus file id missing', ['request_id' => $requestId, 'user_id' => $userId, 'size' => $size, 'type' => $type]);
        return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => 'file_upload_failed'], 500);
      }

      $profile = vp_app_fetch_profile($serviceToken, $userId);
      if (is_wp_error($profile) || !is_array($profile)) {
        $status = is_wp_error($profile) ? (int)($profile->get_error_data()['status'] ?? 500) : 404;
        $message = is_wp_error($profile) ? $profile->get_error_message() : 'profile_not_found';
        return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => $message], $status);
      }

      $avatarField = vp_app_detect_profile_avatar_field($serviceToken);
      if ($avatarField === '') {
        vp_app_runtime_log('error', 'avatar_upload_error', 'Avatar field is missing in vp_user_profiles', ['request_id' => $requestId, 'user_id' => $userId, 'file_id' => $fileId]);
        return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => 'avatar_field_missing'], 400);
      }

      $profileId = (string)($profile['id'] ?? '');
      $patch = vp_app_directus_request('PATCH', '/items/vp_user_profiles/' . rawurlencode($profileId), $serviceToken, [
        $avatarField => $fileId,
      ]);
      if (is_wp_error($patch)) {
        $status = (int)($patch->get_error_data()['status'] ?? 500);
        vp_app_runtime_log('error', 'avatar_upload_error', $patch->get_error_message(), ['request_id' => $requestId, 'status' => $status, 'user_id' => $userId, 'file_id' => $fileId, 'size' => $size, 'type' => $type, 'which_token' => 'service', 'directus_status' => $status]);
        return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => $patch->get_error_message()], $status);
      }

      $payload = vp_app_build_payload($token, $requestId);
      if (is_wp_error($payload)) {
        $status = (int)($payload->get_error_data()['status'] ?? 500);
        return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => $payload->get_error_message()], $status);
      }

      vp_app_runtime_log('info', 'avatar_upload_success', 'Avatar uploaded', ['request_id' => $requestId, 'user_id' => $userId, 'file_id' => $fileId, 'size' => $size, 'type' => $type]);
      return vp_app_json([
        'ok' => true,
        'request_id' => $requestId,
        'file' => [
          'id' => $fileId,
          'filename_download' => (string)($fileData['filename_download'] ?? ''),
          'type' => (string)($fileData['type'] ?? $type),
        ],
        'me' => $payload,
      ], 200);
    },
  ]);

  register_rest_route('vp/v1/app', '/profile/avatar', [
    'methods' => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function () {
      $requestId = vp_app_request_id();
      $token = vp_app_require_auth_token($requestId, 'avatar_proxy');
      if ($token instanceof WP_REST_Response) {
        return $token;
      }

      $me = vp_app_fetch_me($token);
      if (is_wp_error($me)) {
        $status = (int)($me->get_error_data()['status'] ?? 500);
        if ($status === 401) {
          return vp_app_session_expired_response($requestId);
        }
        return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => $me->get_error_message()], $status);
      }

      $userId = (string)($me['id'] ?? '');
      $serviceToken = vp_app_require_service_token($requestId, 'avatar_proxy_error');
      if (is_wp_error($serviceToken)) {
        $status = (int)($serviceToken->get_error_data()['status'] ?? 500);
        return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => $serviceToken->get_error_message()], $status);
      }

      $profile = vp_app_fetch_profile($serviceToken, $userId);
      if (is_wp_error($profile) || !is_array($profile)) {
        return new WP_REST_Response(null, 204);
      }

      $avatar = vp_app_profile_avatar_info($profile);
      $fileId = trim((string)($avatar['avatar_file'] ?? ''));
      if ($fileId === '') {
        return new WP_REST_Response(null, 204);
      }

      $asset = vp_app_directus_fetch_asset($serviceToken, $fileId);
      if (is_wp_error($asset)) {
        $status = (int)($asset->get_error_data()['status'] ?? 500);
        return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => $asset->get_error_message()], $status);
      }

      $response = new WP_REST_Response((string)($asset['body'] ?? ''), 200);
      $response->header('Content-Type', (string)($asset['content_type'] ?? 'application/octet-stream'));
      $response->header('Cache-Control', 'private, max-age=300');
      return $response;
    },
  ]);

  register_rest_route('vp/v1/app', '/diag/runtime', [
    'methods' => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function () {
      $requestId = vp_app_request_id();
      $token = vp_app_require_auth_token($requestId, 'diag_runtime');
      if ($token instanceof WP_REST_Response) {
        return $token;
      }

      $diag = vp_app_runtime_diag(true);
      return vp_app_json([
        'ok' => true,
        'request_id' => $requestId,
        'runtime_path' => $diag['runtime_path'],
        'is_dir' => (bool)$diag['is_dir'],
        'is_writable' => (bool)$diag['is_writable'],
        'php_user' => (string)$diag['php_user'],
        'php_uid' => $diag['php_uid'],
        'php_gid' => $diag['php_gid'],
        'last_error' => $diag['last_error'] !== null ? (string)$diag['last_error'] : null,
      ], 200);
    },
  ]);

  register_rest_route('vp/v1/app', '/dentist/cases', [
    'methods' => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function () {
      $requestId = vp_app_request_id();
      $token = vp_app_require_auth_token($requestId, 'dentist_cases_list_error');
      if ($token instanceof WP_REST_Response) {
        return $token;
      }

      $ctx = vp_app_dentist_context($token, $requestId, 'dentist_cases_list_error');
      if ($ctx instanceof WP_REST_Response) {
        return $ctx;
      }

      $filter = rawurlencode(wp_json_encode(['tenant_id' => ['_eq' => (string)$ctx['tenant_id']]]));
      $fields = rawurlencode('id,case_code,title,status,created_at');
      $res = vp_app_directus_request('GET', '/items/vp_cases?fields=' . $fields . '&limit=50&sort=-created_at&filter=' . $filter, $token);

      if (is_wp_error($res)) {
        $status = (int)($res->get_error_data()['status'] ?? 500);
        vp_app_runtime_log_dentist('error', 'dentist_cases_list_error', $res->get_error_message(), [
          'request_id' => $requestId,
          'status' => $status,
          'user_id' => $ctx['user_id'],
          'tenant_id' => $ctx['tenant_id'],
        ]);
        return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => $res->get_error_message()], $status);
      }

      $items = [];
      foreach (($res['data'] ?? []) as $row) {
        $items[] = [
          'id' => (string)($row['id'] ?? ''),
          'case_code' => (string)($row['case_code'] ?? ''),
          'title' => (string)($row['title'] ?? ''),
          'status' => (string)($row['status'] ?? ''),
          'created_at' => (string)($row['created_at'] ?? ''),
        ];
      }

      vp_app_runtime_log_dentist('info', 'dentist_cases_list_success', 'loaded', [
        'request_id' => $requestId,
        'user_id' => $ctx['user_id'],
        'tenant_id' => $ctx['tenant_id'],
        'count' => count($items),
      ]);

      return vp_app_json([
        'ok' => true,
        'request_id' => $requestId,
        'items' => $items,
      ], 200);
    },
  ]);

  register_rest_route('vp/v1/app', '/dentist/cases', [
    'methods' => 'POST',
    'permission_callback' => '__return_true',
    'callback' => function (WP_REST_Request $request) {
      $requestId = vp_app_request_id();
      $token = vp_app_require_auth_token($requestId, 'dentist_case_create_error');
      if ($token instanceof WP_REST_Response) {
        return $token;
      }

      $ctx = vp_app_dentist_context($token, $requestId, 'dentist_case_create_error');
      if ($ctx instanceof WP_REST_Response) {
        return $ctx;
      }

      $title = trim((string)$request->get_param('title'));
      if ($title === '' || mb_strlen($title) < 1 || mb_strlen($title) > 200) {
        vp_app_runtime_log_dentist('error', 'dentist_case_create_error', 'Invalid title', [
          'request_id' => $requestId,
          'user_id' => $ctx['user_id'],
          'tenant_id' => $ctx['tenant_id'],
        ]);
        return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => 'title_invalid'], 400);
      }

      $clinicId = $request->get_param('clinic_id');
      $patientId = $request->get_param('patient_id');
      $clinicId = $clinicId === null || $clinicId === '' ? null : trim((string)$clinicId);
      $patientId = $patientId === null || $patientId === '' ? null : trim((string)$patientId);

      if (($clinicId !== null && !vp_app_is_directus_id($clinicId)) || ($patientId !== null && !vp_app_is_directus_id($patientId))) {
        vp_app_runtime_log_dentist('error', 'dentist_case_create_error', 'Invalid clinic_id or patient_id', [
          'request_id' => $requestId,
          'user_id' => $ctx['user_id'],
          'tenant_id' => $ctx['tenant_id'],
        ]);
        return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => 'invalid_payload'], 400);
      }

      $res = null;
      $createBody = [];
      $maxAttempts = 5;
      for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $createBody = [
          'case_code' => vp_app_dentist_case_code(),
          'title' => $title,
          'status' => 'new',
          'tenant_id' => $ctx['tenant_id'],
          'clinic_id' => $clinicId,
          'patient_id' => $patientId,
          'created_by' => $ctx['user_id'],
        ];

        $res = vp_app_directus_request('POST', '/items/vp_cases', $token, $createBody);
        if (!is_wp_error($res)) {
          break;
        }

        $status = (int)($res->get_error_data()['status'] ?? 500);
        $message = strtolower((string)$res->get_error_message());
        $isDuplicate = $status === 409 || strpos($message, 'unique') !== false || strpos($message, 'duplicate') !== false;
        if (!$isDuplicate || $attempt === $maxAttempts) {
          vp_app_runtime_log_dentist('error', 'dentist_case_create_error', $res->get_error_message(), [
            'request_id' => $requestId,
            'status' => $status,
            'user_id' => $ctx['user_id'],
            'tenant_id' => $ctx['tenant_id'],
            'attempt' => $attempt,
          ]);
          return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => $res->get_error_message()], $status);
        }
      }

      $item = $res['data'] ?? [];
      vp_app_runtime_log_dentist('info', 'dentist_case_create_success', 'created', [
        'request_id' => $requestId,
        'user_id' => $ctx['user_id'],
        'tenant_id' => $ctx['tenant_id'],
        'case_id' => (string)($item['id'] ?? ''),
      ]);

      return vp_app_json([
        'ok' => true,
        'request_id' => $requestId,
        'item' => [
          'id' => (string)($item['id'] ?? ''),
          'case_code' => (string)($item['case_code'] ?? ''),
          'title' => (string)($item['title'] ?? ''),
          'status' => (string)($item['status'] ?? 'new'),
          'created_at' => (string)($item['created_at'] ?? ''),
        ],
      ], 200);
    },
  ]);
});
