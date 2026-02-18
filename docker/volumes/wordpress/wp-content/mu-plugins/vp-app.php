<?php
/**
 * Plugin Name: VP App Shell API
 * Description: /app endpoints and runtime logging for VP app shell.
 */

if (!defined('ABSPATH')) {
  exit;
}

if (!defined('VP_APP_ASSET_VERSION')) {
  define('VP_APP_ASSET_VERSION', '1.0.0');
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

if (!function_exists('vp_app_runtime_log')) {
  function vp_app_runtime_log($level, $action, $message, $ctx = []) {
    $runtimeDir = '/opt/vseponyatno/runtime';
    $logFile = $runtimeDir . '/vp-app.log';

    if (!is_dir($runtimeDir)) {
      wp_mkdir_p($runtimeDir);
    }

    $userId = 'unknown';
    if (!empty($ctx['user_id'])) {
      $userId = (string)$ctx['user_id'];
    }

    $safeCtx = is_array($ctx) ? $ctx : [];
    foreach (['access_token', 'refresh_token', 'token', 'password', 'vp_dx_at', 'vp_dx_rt'] as $secretKey) {
      if (isset($safeCtx[$secretKey])) {
        unset($safeCtx[$secretKey]);
      }
    }

    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field((string)$_SERVER['REMOTE_ADDR']) : 'unknown';
    $line = sprintf(
      "%s [vp-app] level=%s action=%s user=%s ip=%s msg=\"%s\" ctx=%s\n",
      date(DATE_ATOM),
      sanitize_text_field((string)$level),
      sanitize_text_field((string)$action),
      sanitize_text_field((string)$userId),
      $ip,
      str_replace('"', '\\"', (string)$message),
      wp_json_encode($safeCtx, JSON_UNESCAPED_UNICODE)
    );

    $written = @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    if ($written === false) {
      error_log('[vp-app] runtime log write failed: ' . $line);
    }
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
    $runtimeDir = '/opt/vseponyatno/runtime';
    $logFile = $runtimeDir . '/vp-dentist.log';

    if (!is_dir($runtimeDir)) {
      wp_mkdir_p($runtimeDir);
    }

    $safeCtx = is_array($ctx) ? $ctx : [];
    foreach (['access_token', 'refresh_token', 'token', 'password', 'vp_dx_at', 'vp_dx_rt'] as $secretKey) {
      if (isset($safeCtx[$secretKey])) {
        unset($safeCtx[$secretKey]);
      }
    }

    $line = sprintf(
      "%s [vp-dentist] level=%s action=%s user=%s tenant=%s msg=\"%s\" ctx=%s\n",
      date(DATE_ATOM),
      sanitize_text_field((string)$level),
      sanitize_text_field((string)$action),
      sanitize_text_field((string)($safeCtx['user_id'] ?? 'unknown')),
      sanitize_text_field((string)($safeCtx['tenant_id'] ?? 'unknown')),
      str_replace('"', '\\"', (string)$message),
      wp_json_encode($safeCtx, JSON_UNESCAPED_UNICODE)
    );

    $written = @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    if ($written === false) {
      error_log('[vp-dentist] runtime log write failed: ' . $line);
    }
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

    $profile = vp_app_fetch_profile($token, $userId);
    if (is_wp_error($profile)) {
      $status = (int)($profile->get_error_data()['status'] ?? 500);
      vp_app_runtime_log_dentist('error', $action, $profile->get_error_message(), ['request_id' => $requestId, 'status' => $status, 'user_id' => $userId]);
      return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => $profile->get_error_message()], $status);
    }

    if ((string)($profile['user_type'] ?? '') !== 'dentist') {
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

if (!function_exists('vp_app_fetch_memberships')) {
  function vp_app_fetch_memberships($token, $userId) {
    $fields = rawurlencode('id,role,status,user_type,tenant_id.id,tenant_id.title,tenant_id.name');
    $filter = rawurlencode(wp_json_encode(['user_id' => ['_eq' => (string)$userId], 'status' => ['_eq' => 'active']]));
    $res = vp_app_directus_request('GET', '/items/vp_memberships?limit=100&fields=' . $fields . '&filter=' . $filter, $token);
    if (is_wp_error($res)) {
      return $res;
    }
    return is_array($res['data'] ?? null) ? $res['data'] : [];
  }
}

if (!function_exists('vp_app_normalize_tenants')) {
  function vp_app_normalize_tenants($memberships) {
    $tenants = [];
    foreach ($memberships as $membership) {
      $tenantId = (string)($membership['tenant_id']['id'] ?? '');
      if ($tenantId === '') {
        continue;
      }

      $tenants[] = [
        'tenant_id' => $tenantId,
        'tenant_title' => (string)($membership['tenant_id']['title'] ?? $membership['tenant_id']['name'] ?? $tenantId),
        'member_role' => (string)($membership['role'] ?? 'viewer'),
        'user_type' => (string)($membership['user_type'] ?? ''),
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

    $tenants = vp_app_normalize_tenants($memberships);
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
      'profile' => [
        'id' => (int)($profile['id'] ?? 0),
        'user_id' => (string)($profile['user_id'] ?? ''),
        'user_type' => (string)($profile['user_type'] ?? ''),
        'status' => (string)($profile['status'] ?? ''),
        'phone' => (string)($profile['phone'] ?? ''),
      ],
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

      $memberships = vp_app_fetch_memberships($token, (string)($me['id'] ?? ''));
      if (is_wp_error($memberships)) {
        $status = (int)($memberships->get_error_data()['status'] ?? 500);
        vp_app_runtime_log('error', 'app_tenants', $memberships->get_error_message(), ['request_id' => $requestId, 'status' => $status, 'user_id' => (string)($me['id'] ?? 'unknown')]);
        return vp_app_json(['ok' => false, 'request_id' => $requestId, 'error' => $memberships->get_error_message()], $status);
      }

      return vp_app_json([
        'ok' => true,
        'request_id' => $requestId,
        'tenants' => vp_app_normalize_tenants($memberships),
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
