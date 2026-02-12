<?php
/**
 * Plugin Name: VP Dentist Cabinet MVP
 * Description: REST proxy and auth for dentist cabinet pages.
 */

if (!defined('ABSPATH')) {
  exit;
}

if (!function_exists('vp_dentist_directus_base_url')) {
  function vp_dentist_directus_base_url() {
    $base = getenv('DIRECTUS_URL');
    if (!$base) {
      return '';
    }
    return rtrim((string)$base, '/');
  }
}

if (!function_exists('vp_dentist_cookie_options')) {
  function vp_dentist_cookie_options($expires) {
    return [
      'expires' => (int)$expires,
      'path' => '/',
      'secure' => is_ssl(),
      'httponly' => true,
      'samesite' => 'Lax',
    ];
  }
}

if (!function_exists('vp_dentist_set_cookie')) {
  function vp_dentist_set_cookie($name, $value, $expires) {
    setcookie($name, $value, vp_dentist_cookie_options($expires));
    $_COOKIE[$name] = $value;
  }
}

if (!function_exists('vp_dentist_clear_cookie')) {
  function vp_dentist_clear_cookie($name) {
    setcookie($name, '', vp_dentist_cookie_options(time() - 3600));
    unset($_COOKIE[$name]);
  }
}

if (!function_exists('vp_dentist_access_token')) {
  function vp_dentist_access_token() {
    return isset($_COOKIE['vp_dx_at']) ? trim((string)$_COOKIE['vp_dx_at']) : '';
  }
}

if (!function_exists('vp_dentist_active_tenant')) {
  function vp_dentist_active_tenant() {
    return isset($_COOKIE['vp_tenant']) ? trim((string)$_COOKIE['vp_tenant']) : '';
  }
}

if (!function_exists('vp_dentist_directus_request')) {
  function vp_dentist_directus_request($method, $path, $body = null, $token = '') {
    $base = vp_dentist_directus_base_url();
    if ($base === '') {
      return new WP_Error('directus_env_missing', 'DIRECTUS_URL is missing', ['status' => 500]);
    }

    $headers = [
      'Accept' => 'application/json',
    ];

    if ($token !== '') {
      $headers['Authorization'] = 'Bearer ' . $token;
    }

    $args = [
      'method' => strtoupper((string)$method),
      'timeout' => 30,
      'headers' => $headers,
    ];

    if (is_array($body)) {
      $args['headers']['Content-Type'] = 'application/json';
      $args['body'] = wp_json_encode($body);
    }

    $response = wp_remote_request($base . $path, $args);
    if (is_wp_error($response)) {
      return $response;
    }

    $status = (int)wp_remote_retrieve_response_code($response);
    $rawBody = (string)wp_remote_retrieve_body($response);
    $json = json_decode($rawBody, true);

    if ($status < 200 || $status >= 300) {
      $message = 'Directus request failed';
      if (is_array($json) && !empty($json['errors'][0]['message'])) {
        $message = (string)$json['errors'][0]['message'];
      }
      return new WP_Error('directus_http_error', $message, [
        'status' => $status,
        'body' => $json,
      ]);
    }

    if (!is_array($json)) {
      return ['data' => null];
    }

    return $json;
  }
}

if (!function_exists('vp_dentist_get_me')) {
  function vp_dentist_get_me($token) {
    $me = vp_dentist_directus_request('GET', '/users/me', null, $token);
    if (is_wp_error($me)) {
      return $me;
    }
    return $me['data'] ?? null;
  }
}

if (!function_exists('vp_dentist_get_memberships')) {
  function vp_dentist_get_memberships($token, $userId) {
    $fields = rawurlencode('id,role,status,tenant_id.id,tenant_id.name,tenant_id.slug,tenant_id.status');
    $filter = rawurlencode(wp_json_encode([
      '_and' => [
        ['user_id' => ['_eq' => (string)$userId]],
        ['status' => ['_eq' => 'active']],
      ],
    ]));

    $res = vp_dentist_directus_request('GET', '/items/vp_memberships?limit=100&fields=' . $fields . '&filter=' . $filter, null, $token);
    if (is_wp_error($res)) {
      return $res;
    }

    return is_array($res['data'] ?? null) ? $res['data'] : [];
  }
}

if (!function_exists('vp_dentist_resolve_tenant')) {
  function vp_dentist_resolve_tenant($memberships, $requestedTenant = '') {
    $requestedTenant = trim((string)$requestedTenant);
    $firstTenant = '';

    foreach ($memberships as $membership) {
      $tenantId = (string)($membership['tenant_id']['id'] ?? '');
      if ($tenantId === '') {
        continue;
      }
      if ($firstTenant === '') {
        $firstTenant = $tenantId;
      }
      if ($requestedTenant !== '' && $tenantId === $requestedTenant) {
        return $tenantId;
      }
    }

    return $requestedTenant === '' ? $firstTenant : '';
  }
}

if (!function_exists('vp_dentist_require_session')) {
  function vp_dentist_require_session() {
    $token = vp_dentist_access_token();
    if ($token === '') {
      return new WP_Error('dentist_auth_required', 'Требуется вход', ['status' => 401]);
    }

    $me = vp_dentist_get_me($token);
    if (is_wp_error($me)) {
      vp_dentist_clear_cookie('vp_dx_at');
      vp_dentist_clear_cookie('vp_dx_rt');
      vp_dentist_clear_cookie('vp_tenant');
      return new WP_Error('dentist_auth_invalid', 'Сессия истекла, войдите снова', ['status' => 401]);
    }

    return [
      'token' => $token,
      'me' => $me,
    ];
  }
}

if (!function_exists('vp_dentist_extract_raw_format')) {
  function vp_dentist_extract_raw_format($filename) {
    $ext = strtolower((string)pathinfo((string)$filename, PATHINFO_EXTENSION));
    if (in_array($ext, ['stl', 'obj', 'ply'], true)) {
      return $ext;
    }
    return '';
  }
}

if (!function_exists('vp_dentist_directus_upload_file')) {
  function vp_dentist_directus_upload_file($token, $fileInfo) {
    if (empty($fileInfo['tmp_name']) || !file_exists($fileInfo['tmp_name'])) {
      return new WP_Error('upload_missing', 'Файл не найден', ['status' => 400]);
    }

    if (!function_exists('curl_file_create')) {
      return new WP_Error('curl_missing', 'cURL extension is required for upload', ['status' => 500]);
    }

    $base = vp_dentist_directus_base_url();
    $ch = curl_init($base . '/files');

    $curlFile = curl_file_create(
      $fileInfo['tmp_name'],
      (string)($fileInfo['type'] ?? 'application/octet-stream'),
      (string)($fileInfo['name'] ?? 'scan')
    );

    $postFields = [
      'file' => $curlFile,
    ];

    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
      ],
      CURLOPT_POSTFIELDS => $postFields,
      CURLOPT_TIMEOUT => 45,
    ]);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $error !== '') {
      return new WP_Error('upload_failed', 'Не удалось загрузить файл в Directus', ['status' => 502]);
    }

    $json = json_decode((string)$body, true);
    if ($status < 200 || $status >= 300 || !is_array($json)) {
      return new WP_Error('upload_failed', 'Ошибка загрузки файла', ['status' => $status > 0 ? $status : 502, 'body' => $json]);
    }

    return $json['data'] ?? null;
  }
}

if (!function_exists('vp_dentist_register_routes')) {
  function vp_dentist_register_routes() {
    register_rest_route('vp/v1', '/dentist/login', [
      'methods' => 'POST',
      'permission_callback' => '__return_true',
      'callback' => 'vp_dentist_route_login',
    ]);

    register_rest_route('vp/v1', '/dentist/logout', [
      'methods' => 'POST',
      'permission_callback' => '__return_true',
      'callback' => 'vp_dentist_route_logout',
    ]);

    register_rest_route('vp/v1', '/dentist/me', [
      'methods' => 'GET',
      'permission_callback' => '__return_true',
      'callback' => 'vp_dentist_route_me',
    ]);

    register_rest_route('vp/v1', '/dentist/tenants', [
      'methods' => 'GET',
      'permission_callback' => '__return_true',
      'callback' => 'vp_dentist_route_tenants',
    ]);

    register_rest_route('vp/v1', '/dentist/tenant', [
      'methods' => 'POST',
      'permission_callback' => '__return_true',
      'callback' => 'vp_dentist_route_set_tenant',
    ]);

    register_rest_route('vp/v1', '/dentist/cases', [
      [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => 'vp_dentist_route_cases_list',
      ],
      [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => 'vp_dentist_route_cases_create',
      ],
    ]);

    register_rest_route('vp/v1', '/dentist/cases/(?P<id>[a-zA-Z0-9-]+)/upload-scan', [
      'methods' => 'POST',
      'permission_callback' => '__return_true',
      'callback' => 'vp_dentist_route_case_upload_scan',
    ]);

    register_rest_route('vp/v1', '/dentist/clinics', [
      'methods' => 'GET',
      'permission_callback' => '__return_true',
      'callback' => 'vp_dentist_route_clinics',
    ]);
  }
}
add_action('rest_api_init', 'vp_dentist_register_routes');

if (!function_exists('vp_dentist_route_login')) {
  function vp_dentist_route_login(WP_REST_Request $request) {
    $email = sanitize_email((string)$request->get_param('email'));
    $password = (string)$request->get_param('password');

    if ($email === '' || $password === '') {
      return new WP_REST_Response(['error' => 'Заполните email и пароль'], 400);
    }

    $login = vp_dentist_directus_request('POST', '/auth/login', [
      'email' => $email,
      'password' => $password,
    ]);

    if (is_wp_error($login)) {
      return new WP_REST_Response(['error' => 'Неверный логин или пароль'], 401);
    }

    $data = $login['data'] ?? [];
    $accessToken = (string)($data['access_token'] ?? '');
    $refreshToken = (string)($data['refresh_token'] ?? '');
    $expiresSeconds = (int)($data['expires'] ?? 900);

    if ($accessToken === '') {
      return new WP_REST_Response(['error' => 'Не получен access_token'], 502);
    }

    vp_dentist_set_cookie('vp_dx_at', $accessToken, time() + max($expiresSeconds, 600));
    if ($refreshToken !== '') {
      vp_dentist_set_cookie('vp_dx_rt', $refreshToken, time() + (30 * DAY_IN_SECONDS));
    }

    $me = vp_dentist_get_me($accessToken);
    if (is_wp_error($me) || empty($me['id'])) {
      return new WP_REST_Response(['error' => 'Не удалось получить профиль'], 502);
    }

    $memberships = vp_dentist_get_memberships($accessToken, $me['id']);
    if (is_wp_error($memberships)) {
      return new WP_REST_Response(['error' => 'Не удалось получить организации'], 502);
    }

    $activeTenant = vp_dentist_resolve_tenant($memberships, vp_dentist_active_tenant());
    if ($activeTenant !== '') {
      vp_dentist_set_cookie('vp_tenant', $activeTenant, time() + (30 * DAY_IN_SECONDS));
    }

    return new WP_REST_Response([
      'ok' => true,
      'redirect' => home_url('/dentist/'),
      'tenants_count' => count($memberships),
      'tenant_selected' => $activeTenant !== '',
    ], 200);
  }
}

if (!function_exists('vp_dentist_route_logout')) {
  function vp_dentist_route_logout() {
    vp_dentist_clear_cookie('vp_dx_at');
    vp_dentist_clear_cookie('vp_dx_rt');
    vp_dentist_clear_cookie('vp_tenant');

    return new WP_REST_Response([
      'ok' => true,
      'redirect' => home_url('/dentist-login/'),
    ], 200);
  }
}

if (!function_exists('vp_dentist_route_me')) {
  function vp_dentist_route_me() {
    $session = vp_dentist_require_session();
    if (is_wp_error($session)) {
      return $session;
    }

    $memberships = vp_dentist_get_memberships($session['token'], $session['me']['id']);
    if (is_wp_error($memberships)) {
      return $memberships;
    }

    $activeTenant = vp_dentist_resolve_tenant($memberships, vp_dentist_active_tenant());
    if ($activeTenant !== '') {
      vp_dentist_set_cookie('vp_tenant', $activeTenant, time() + (30 * DAY_IN_SECONDS));
    }

    return [
      'ok' => true,
      'user' => [
        'id' => (string)$session['me']['id'],
        'email' => (string)($session['me']['email'] ?? ''),
        'first_name' => (string)($session['me']['first_name'] ?? ''),
        'last_name' => (string)($session['me']['last_name'] ?? ''),
      ],
      'active_tenant' => $activeTenant,
      'memberships_count' => count($memberships),
    ];
  }
}

if (!function_exists('vp_dentist_route_tenants')) {
  function vp_dentist_route_tenants() {
    $session = vp_dentist_require_session();
    if (is_wp_error($session)) {
      return $session;
    }

    $memberships = vp_dentist_get_memberships($session['token'], $session['me']['id']);
    if (is_wp_error($memberships)) {
      return $memberships;
    }

    $tenants = [];
    foreach ($memberships as $membership) {
      $tenant = $membership['tenant_id'] ?? null;
      if (!is_array($tenant) || empty($tenant['id'])) {
        continue;
      }

      $tenants[] = [
        'id' => (string)$tenant['id'],
        'name' => (string)($tenant['name'] ?? ''),
        'slug' => (string)($tenant['slug'] ?? ''),
        'status' => (string)($tenant['status'] ?? ''),
        'role' => (string)($membership['role'] ?? ''),
      ];
    }

    $activeTenant = vp_dentist_resolve_tenant($memberships, vp_dentist_active_tenant());

    return [
      'ok' => true,
      'active_tenant' => $activeTenant,
      'tenants' => $tenants,
    ];
  }
}

if (!function_exists('vp_dentist_route_set_tenant')) {
  function vp_dentist_route_set_tenant(WP_REST_Request $request) {
    $session = vp_dentist_require_session();
    if (is_wp_error($session)) {
      return $session;
    }

    $tenantId = sanitize_text_field((string)$request->get_param('tenant_id'));
    if ($tenantId === '') {
      return new WP_REST_Response(['error' => 'tenant_id обязателен'], 400);
    }

    $memberships = vp_dentist_get_memberships($session['token'], $session['me']['id']);
    if (is_wp_error($memberships)) {
      return $memberships;
    }

    $resolved = vp_dentist_resolve_tenant($memberships, $tenantId);
    if ($resolved === '') {
      return new WP_REST_Response(['error' => 'Нет доступа к выбранной организации'], 403);
    }

    vp_dentist_set_cookie('vp_tenant', $resolved, time() + (30 * DAY_IN_SECONDS));

    return [
      'ok' => true,
      'active_tenant' => $resolved,
    ];
  }
}

if (!function_exists('vp_dentist_route_cases_list')) {
  function vp_dentist_route_cases_list() {
    $session = vp_dentist_require_session();
    if (is_wp_error($session)) {
      return $session;
    }

    $tenantId = vp_dentist_active_tenant();
    if ($tenantId === '') {
      return new WP_REST_Response(['error' => 'Сначала выберите организацию'], 400);
    }

    $fields = rawurlencode('id,title,status,created_at,clinic_id.id,clinic_id.name');
    $filter = rawurlencode(wp_json_encode([
      'tenant_id' => ['_eq' => $tenantId],
    ]));

    $casesRes = vp_dentist_directus_request('GET', '/items/vp_cases?limit=100&sort=-created_at&fields=' . $fields . '&filter=' . $filter, null, $session['token']);
    if (is_wp_error($casesRes)) {
      return $casesRes;
    }

    $cases = is_array($casesRes['data'] ?? null) ? $casesRes['data'] : [];
    $caseIds = [];
    foreach ($cases as $row) {
      if (!empty($row['id'])) {
        $caseIds[] = (string)$row['id'];
      }
    }

    $sceneByCase = [];
    if (count($caseIds) > 0) {
      $sceneFields = rawurlencode('id,case_scan_id.case_id');
      $sceneFilter = rawurlencode(wp_json_encode([
        'tenant_id' => ['_eq' => $tenantId],
        'case_scan_id' => ['case_id' => ['_in' => $caseIds]],
      ]));
      $sceneRes = vp_dentist_directus_request('GET', '/items/vp_3d_scenes?limit=200&fields=' . $sceneFields . '&filter=' . $sceneFilter, null, $session['token']);
      if (!is_wp_error($sceneRes)) {
        foreach (($sceneRes['data'] ?? []) as $scene) {
          $caseId = (string)($scene['case_scan_id']['case_id'] ?? '');
          if ($caseId !== '' && !isset($sceneByCase[$caseId])) {
            $sceneByCase[$caseId] = (string)$scene['id'];
          }
        }
      }

      $sceneIds = array_values($sceneByCase);
      if (count($sceneIds) > 0) {
        $qrFields = rawurlencode('id,code,scene_id');
        $qrFilter = rawurlencode(wp_json_encode([
          'scene_id' => ['_in' => $sceneIds],
          'is_active' => ['_eq' => true],
        ]));
        $qrRes = vp_dentist_directus_request('GET', '/items/qr_codes?limit=200&fields=' . $qrFields . '&filter=' . $qrFilter, null, $session['token']);
        if (!is_wp_error($qrRes)) {
          $qrByScene = [];
          foreach (($qrRes['data'] ?? []) as $qr) {
            $sceneId = is_array($qr['scene_id'] ?? null) ? (string)($qr['scene_id']['id'] ?? '') : (string)($qr['scene_id'] ?? '');
            if ($sceneId !== '' && !isset($qrByScene[$sceneId])) {
              $qrByScene[$sceneId] = (string)($qr['code'] ?? '');
            }
          }

          foreach ($sceneByCase as $cid => $sid) {
            if (!empty($qrByScene[$sid])) {
              $sceneByCase[$cid] = home_url('/3d/?code=' . rawurlencode($qrByScene[$sid]));
            } else {
              $sceneByCase[$cid] = home_url('/3d/?scene=' . rawurlencode($sid));
            }
          }
        }
      }
    }

    $items = [];
    foreach ($cases as $caseItem) {
      $cid = (string)($caseItem['id'] ?? '');
      $items[] = [
        'id' => $cid,
        'title' => (string)($caseItem['title'] ?? ''),
        'status' => (string)($caseItem['status'] ?? ''),
        'created_at' => (string)($caseItem['created_at'] ?? ''),
        'clinic_name' => (string)($caseItem['clinic_id']['name'] ?? ''),
        'open_url' => $sceneByCase[$cid] ?? '',
        'has_3d' => !empty($sceneByCase[$cid]),
      ];
    }

    return [
      'ok' => true,
      'items' => $items,
    ];
  }
}

if (!function_exists('vp_dentist_route_cases_create')) {
  function vp_dentist_route_cases_create(WP_REST_Request $request) {
    $session = vp_dentist_require_session();
    if (is_wp_error($session)) {
      return $session;
    }

    $tenantId = vp_dentist_active_tenant();
    if ($tenantId === '') {
      return new WP_REST_Response(['error' => 'Сначала выберите организацию'], 400);
    }

    $title = sanitize_text_field((string)$request->get_param('title'));
    $clinicId = sanitize_text_field((string)$request->get_param('clinic_id'));
    $patientExternalId = sanitize_text_field((string)$request->get_param('patient_external_id'));

    if ($title === '') {
      return new WP_REST_Response(['error' => 'Название кейса обязательно'], 400);
    }

    $payload = [
      'tenant_id' => $tenantId,
      'title' => $title,
      'status' => 'draft',
      'created_by' => (string)$session['me']['id'],
    ];

    if ($clinicId !== '') {
      $payload['clinic_id'] = $clinicId;
    }

    if ($patientExternalId !== '') {
      $payload['patient_external_id'] = $patientExternalId;
    }

    $created = vp_dentist_directus_request('POST', '/items/vp_cases', $payload, $session['token']);
    if (is_wp_error($created)) {
      return $created;
    }

    return [
      'ok' => true,
      'item' => $created['data'] ?? null,
    ];
  }
}

if (!function_exists('vp_dentist_route_case_upload_scan')) {
  function vp_dentist_route_case_upload_scan(WP_REST_Request $request) {
    $session = vp_dentist_require_session();
    if (is_wp_error($session)) {
      return $session;
    }

    $tenantId = vp_dentist_active_tenant();
    if ($tenantId === '') {
      return new WP_REST_Response(['error' => 'Сначала выберите организацию'], 400);
    }

    $caseId = sanitize_text_field((string)$request['id']);
    if ($caseId === '') {
      return new WP_REST_Response(['error' => 'Некорректный case id'], 400);
    }

    $caseFilter = rawurlencode(wp_json_encode([
      'id' => ['_eq' => $caseId],
      'tenant_id' => ['_eq' => $tenantId],
    ]));
    $caseRes = vp_dentist_directus_request('GET', '/items/vp_cases?limit=1&fields=id&filter=' . $caseFilter, null, $session['token']);
    if (is_wp_error($caseRes) || empty($caseRes['data'][0]['id'])) {
      return new WP_REST_Response(['error' => 'Кейс не найден в выбранной организации'], 404);
    }

    $files = $request->get_file_params();
    $scanFile = $files['scan_file'] ?? null;
    if (!is_array($scanFile) || empty($scanFile['name'])) {
      return new WP_REST_Response(['error' => 'Загрузите файл scan_file'], 400);
    }

    $format = vp_dentist_extract_raw_format((string)$scanFile['name']);
    if ($format === '') {
      return new WP_REST_Response(['error' => 'Поддерживаются только STL/OBJ/PLY'], 400);
    }

    $uploaded = vp_dentist_directus_upload_file($session['token'], $scanFile);
    if (is_wp_error($uploaded) || empty($uploaded['id'])) {
      return new WP_REST_Response(['error' => 'Не удалось загрузить файл'], 502);
    }

    $scanPayload = [
      'tenant_id' => $tenantId,
      'case_id' => $caseId,
      'raw_file' => (string)$uploaded['id'],
      'raw_format' => $format,
      'conversion_status' => 'pending',
      'created_by' => (string)$session['me']['id'],
    ];

    $scanItem = vp_dentist_directus_request('POST', '/items/vp_case_scans', $scanPayload, $session['token']);
    if (is_wp_error($scanItem)) {
      return $scanItem;
    }

    return [
      'ok' => true,
      'item' => $scanItem['data'] ?? null,
    ];
  }
}

if (!function_exists('vp_dentist_route_clinics')) {
  function vp_dentist_route_clinics() {
    $session = vp_dentist_require_session();
    if (is_wp_error($session)) {
      return $session;
    }

    $tenantId = vp_dentist_active_tenant();
    if ($tenantId === '') {
      return [
        'ok' => true,
        'items' => [],
      ];
    }

    $fields = rawurlencode('id,name,address');
    $filter = rawurlencode(wp_json_encode([
      'tenant_id' => ['_eq' => $tenantId],
    ]));

    $clinics = vp_dentist_directus_request('GET', '/items/vp_clinics?limit=200&sort=name&fields=' . $fields . '&filter=' . $filter, null, $session['token']);
    if (is_wp_error($clinics)) {
      return $clinics;
    }

    return [
      'ok' => true,
      'items' => $clinics['data'] ?? [],
    ];
  }
}
