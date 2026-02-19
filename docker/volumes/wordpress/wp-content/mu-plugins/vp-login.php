<?php
/**
 * Plugin Name: VP Universal Login
 * Description: Universal login/registration proxy for VP project.
 */

if (!defined('ABSPATH')) {
  exit;
}

if (!function_exists('vp_login_directus_base_url')) {
  function vp_login_directus_base_url() {
    $base = getenv('DIRECTUS_URL');
    if (!$base) {
      $base = getenv('DIRECTUS_BASE_URL');
    }
    return $base ? rtrim((string)$base, '/') : '';
  }
}

if (!function_exists('vp_login_directus_admin_token')) {
  function vp_login_directus_admin_token() {
    $token = getenv('DIRECTUS_API_TOKEN');
    return $token ? trim((string)$token) : '';
  }
}

if (!function_exists('vp_login_cookie_options')) {
  function vp_login_cookie_options($expires) {
    return [
      'expires' => (int)$expires,
      'path' => '/',
      'secure' => is_ssl(),
      'httponly' => true,
      'samesite' => 'Lax',
    ];
  }
}

if (!function_exists('vp_login_set_cookie')) {
  function vp_login_set_cookie($name, $value, $expires) {
    setcookie($name, $value, vp_login_cookie_options($expires));
    $_COOKIE[$name] = $value;
  }
}

if (!function_exists('vp_login_clear_cookie')) {
  function vp_login_clear_cookie($name) {
    setcookie($name, '', vp_login_cookie_options(time() - 3600));
    unset($_COOKIE[$name]);
  }
}

if (!function_exists('vp_login_access_token')) {
  function vp_login_access_token() {
    return isset($_COOKIE['vp_dx_at']) ? trim((string)$_COOKIE['vp_dx_at']) : '';
  }
}

if (!function_exists('vp_login_active_tenant')) {
  function vp_login_active_tenant() {
    return isset($_COOKIE['vp_tenant']) ? trim((string)$_COOKIE['vp_tenant']) : '';
  }
}

if (!function_exists('vp_login_directus_request')) {
  function vp_login_directus_request($method, $path, $body = null, $token = '') {
    $base = vp_login_directus_base_url();
    if ($base === '') {
      return new WP_Error('vp_directus_env_missing', 'DIRECTUS_URL or DIRECTUS_BASE_URL is missing', ['status' => 500]);
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

    if (is_array($body)) {
      $args['headers']['Content-Type'] = 'application/json';
      $args['body'] = wp_json_encode($body);
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
      return new WP_Error('vp_directus_http_error', $message, ['status' => $status, 'body' => $json]);
    }

    return is_array($json) ? $json : ['data' => null];
  }
}

if (!function_exists('vp_login_valid_user_types')) {
  function vp_login_valid_user_types() {
    return ['dentist', 'car_owner', 'business_owner', 'location_owner', 'partner', 'client'];
  }
}

if (!function_exists('vp_login_profile_by_user_type')) {
  function vp_login_profile_by_user_type($userType, $data) {
    $profile = [
      'user_type' => $userType,
      'status' => 'active',
      'phone' => sanitize_text_field((string)($data['phone'] ?? '')),
      'notes' => sanitize_textarea_field((string)($data['evidence_note'] ?? '')),
    ];

    if ($userType === 'dentist') {
      $profile['dentist_clinic_name'] = sanitize_text_field((string)($data['clinic_name'] ?? ''));
      $profile['dentist_specialty'] = sanitize_text_field((string)($data['specialty'] ?? ''));
    } elseif ($userType === 'car_owner') {
      $profile['car_make'] = sanitize_text_field((string)($data['car_make'] ?? ''));
      $profile['car_model'] = sanitize_text_field((string)($data['car_model'] ?? ''));
    } elseif ($userType === 'business_owner') {
      $profile['biz_name'] = sanitize_text_field((string)($data['biz_name'] ?? ''));
      $profile['biz_website'] = esc_url_raw((string)($data['website'] ?? ''));
    } elseif ($userType === 'location_owner') {
      $profile['loc_name'] = sanitize_text_field((string)($data['loc_name'] ?? ''));
      $profile['loc_address'] = sanitize_text_field((string)($data['address'] ?? ''));
    } elseif ($userType === 'partner') {
      $profile['partner_brand'] = sanitize_text_field((string)($data['partner_brand'] ?? ''));
      $profile['partner_website'] = esc_url_raw((string)($data['website'] ?? ''));
    }

    return array_filter($profile, static function ($value) {
      return $value !== '' && $value !== null;
    });
  }
}

if (!function_exists('vp_login_fetch_profile')) {
  function vp_login_fetch_profile($token, $userId) {
    $filter = rawurlencode(wp_json_encode(['user_id' => ['_eq' => (string)$userId]]));
    $res = vp_login_directus_request('GET', '/items/vp_user_profiles?limit=1&filter=' . $filter, null, $token);
    if (is_wp_error($res)) {
      return $res;
    }
    return $res['data'][0] ?? null;
  }
}

if (!function_exists('vp_login_fetch_memberships')) {
  function vp_login_fetch_memberships($token, $userId) {
    $fields = rawurlencode('id,role,status,tenant_id.id,tenant_id.name,tenant_id.slug,tenant_id.status');
    $filter = rawurlencode(wp_json_encode(['user_id' => ['_eq' => (string)$userId], 'status' => ['_eq' => 'active']]));
    $res = vp_login_directus_request('GET', '/items/vp_memberships?limit=100&fields=' . $fields . '&filter=' . $filter, null, $token);
    if (is_wp_error($res)) {
      return [];
    }
    return is_array($res['data'] ?? null) ? $res['data'] : [];
  }
}

if (!function_exists('vp_login_resolve_active_tenant')) {
  function vp_login_resolve_active_tenant($memberships, $requestedTenant = '') {
    $requestedTenant = trim((string)$requestedTenant);
    $first = '';
    foreach ($memberships as $membership) {
      $id = (string)($membership['tenant_id']['id'] ?? '');
      if ($id === '') {
        continue;
      }
      if ($first === '') {
        $first = $id;
      }
      if ($requestedTenant !== '' && $requestedTenant === $id) {
        return $id;
      }
    }
    return $requestedTenant === '' ? $first : '';
  }
}

if (!function_exists('vp_login_resolve_redirect')) {
  function vp_login_resolve_redirect($user, $profile, $memberships) {
    $type = (string)($profile['user_type'] ?? '');
    if ($type === 'dentist') {
      return home_url('/dentist/');
    }
    if ($type === 'car_owner') {
      return home_url('/car/');
    }
    if ($type === 'business_owner') {
      return home_url('/business/');
    }
    if ($type === 'location_owner') {
      return home_url('/location/');
    }
    if ($type === 'partner') {
      return home_url('/partner/');
    }
    return home_url('/app/#/dashboard');
  }
}

if (!function_exists('vp_login_get_me')) {
  function vp_login_get_me($token) {
    $me = vp_login_directus_request('GET', '/users/me', null, $token);
    if (is_wp_error($me)) {
      return $me;
    }
    return $me['data'] ?? null;
  }
}

if (!function_exists('vp_login_prepare_login_response')) {
  function vp_login_prepare_login_response($token, $userData) {
    $profile = vp_login_fetch_profile($token, (string)$userData['id']);
    if (is_wp_error($profile)) {
      $profile = null;
    }

    $memberships = vp_login_fetch_memberships($token, (string)$userData['id']);
    $activeTenant = vp_login_resolve_active_tenant($memberships, vp_login_active_tenant());
    if ($activeTenant !== '') {
      vp_login_set_cookie('vp_tenant', $activeTenant, time() + (30 * DAY_IN_SECONDS));
    }

    return [
      'ok' => true,
      'user' => [
        'id' => (string)$userData['id'],
        'email' => (string)($userData['email'] ?? ''),
        'first_name' => (string)($userData['first_name'] ?? ''),
        'last_name' => (string)($userData['last_name'] ?? ''),
      ],
      'profile' => $profile,
      'redirect' => vp_login_resolve_redirect($userData, $profile, $memberships),
      'tenants_count' => count($memberships),
      'tenant_selected' => $activeTenant !== '',
      'active_tenant' => $activeTenant,
      'memberships_count' => count($memberships),
    ];
  }
}

if (!function_exists('vp_login_role_name_for_type')) {
  function vp_login_role_name_for_type($userType) {
    $map = [
      'dentist' => 'role_dentist',
      'car_owner' => 'role_car_owner',
      'business_owner' => 'role_business_owner',
      'location_owner' => 'role_location_owner',
      'partner' => 'role_partner',
      'client' => 'role_client',
    ];
    return $map[$userType] ?? 'role_client';
  }
}

if (!function_exists('vp_login_role_id_by_name')) {
  function vp_login_role_id_by_name($adminToken, $roleName) {
    $filter = rawurlencode(wp_json_encode(['name' => ['_eq' => $roleName]]));
    $res = vp_login_directus_request('GET', '/roles?limit=1&filter=' . $filter, null, $adminToken);
    if (is_wp_error($res) || empty($res['data'][0]['id'])) {
      return '';
    }
    return (string)$res['data'][0]['id'];
  }
}

if (!function_exists('vp_login_find_invite')) {
  function vp_login_find_invite($adminToken, $code) {
    if ($code === '') {
      return null;
    }

    $filter = rawurlencode(wp_json_encode(['token' => ['_eq' => $code]]));
    $res = vp_login_directus_request('GET', '/items/vp_invites?limit=1&filter=' . $filter, null, $adminToken);
    if (is_wp_error($res) || empty($res['data'][0])) {
      return null;
    }

    $invite = $res['data'][0];
    $expiresAt = isset($invite['expires_at']) ? strtotime((string)$invite['expires_at']) : false;
    if ($expiresAt && $expiresAt < time()) {
      return null;
    }

    return $invite;
  }
}

if (!function_exists('vp_login_find_allowlisted_domain')) {
  function vp_login_find_allowlisted_domain($adminToken, $domain) {
    if ($domain === '') {
      return null;
    }

    $collections = ['vp_allowlist_domains', 'vp_allowlist_domain'];
    foreach ($collections as $collection) {
      $filter = rawurlencode(wp_json_encode(['domain' => ['_eq' => $domain]]));
      $res = vp_login_directus_request('GET', '/items/' . $collection . '?limit=1&filter=' . $filter, null, $adminToken);
      if (is_wp_error($res)) {
        continue;
      }
      if (!empty($res['data'][0])) {
        return $res['data'][0];
      }
    }

    return null;
  }
}

if (!function_exists('vp_login_manual_mode_enabled')) {
  function vp_login_manual_mode_enabled() {
    $value = getenv('VP_ONBOARDING_MANUAL_MODE');
    return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
  }
}

if (!function_exists('vp_login_create_onboarding_request')) {
  function vp_login_create_onboarding_request($adminToken, $payload) {
    return vp_login_directus_request('POST', '/items/vp_onboarding_requests', $payload, $adminToken);
  }
}

if (!function_exists('vp_login_route_login')) {
  function vp_login_route_login(WP_REST_Request $request) {
    $email = sanitize_email((string)$request->get_param('email'));
    $password = (string)$request->get_param('password');

    if ($email === '' || $password === '') {
      return new WP_REST_Response(['error' => 'Заполните email и пароль'], 400);
    }

    $login = vp_login_directus_request('POST', '/auth/login', ['email' => $email, 'password' => $password]);
    if (is_wp_error($login)) {
      return new WP_REST_Response(['error' => 'Неверный логин или пароль'], 401);
    }

    $data = $login['data'] ?? [];
    $access = (string)($data['access_token'] ?? '');
    $refresh = (string)($data['refresh_token'] ?? '');
    $expires = (int)($data['expires'] ?? 900);

    if ($access === '') {
      return new WP_REST_Response(['error' => 'Не получен access_token'], 502);
    }

    vp_login_set_cookie('vp_dx_at', $access, time() + max($expires, 600));
    if ($refresh !== '') {
      vp_login_set_cookie('vp_dx_rt', $refresh, time() + (30 * DAY_IN_SECONDS));
    }

    $me = vp_login_get_me($access);
    if (is_wp_error($me) || empty($me['id'])) {
      return new WP_REST_Response(['error' => 'Не удалось получить профиль пользователя'], 502);
    }

    return new WP_REST_Response(vp_login_prepare_login_response($access, $me), 200);
  }
}

if (!function_exists('vp_login_route_logout')) {
  function vp_login_route_logout() {
    $refresh = isset($_COOKIE['vp_dx_rt']) ? trim((string)$_COOKIE['vp_dx_rt']) : '';

    if ($refresh !== '') {
      vp_login_directus_request('POST', '/auth/logout', ['refresh_token' => $refresh]);
    }

    vp_login_clear_cookie('vp_dx_at');
    vp_login_clear_cookie('vp_dx_rt');
    vp_login_clear_cookie('vp_tenant');

    return new WP_REST_Response(['ok' => true, 'redirect' => home_url('/login/')], 200);
  }
}

if (!function_exists('vp_login_route_me')) {
  function vp_login_route_me() {
    $token = vp_login_access_token();
    if ($token === '') {
      return new WP_Error('vp_auth_required', 'Требуется вход', ['status' => 401]);
    }

    $me = vp_login_get_me($token);
    if (is_wp_error($me) || empty($me['id'])) {
      vp_login_clear_cookie('vp_dx_at');
      vp_login_clear_cookie('vp_dx_rt');
      vp_login_clear_cookie('vp_tenant');
      return new WP_Error('vp_auth_invalid', 'Сессия истекла, войдите снова', ['status' => 401]);
    }

    return vp_login_prepare_login_response($token, $me);
  }
}

if (!function_exists('vp_login_route_register_request')) {
  function vp_login_route_register_request(WP_REST_Request $request) {
    $adminToken = vp_login_directus_admin_token();
    if ($adminToken === '') {
      return new WP_REST_Response(['error' => 'DIRECTUS_API_TOKEN is required for register-request'], 500);
    }

    $payload = $request->get_json_params();
    if (!is_array($payload)) {
      $payload = $request->get_params();
    }

    $userType = sanitize_text_field((string)($payload['user_type'] ?? ''));
    $email = sanitize_email((string)($payload['email'] ?? ''));
    $password = (string)($payload['password'] ?? '');
    $firstName = sanitize_text_field((string)($payload['first_name'] ?? ''));
    $lastName = sanitize_text_field((string)($payload['last_name'] ?? ''));
    $phone = sanitize_text_field((string)($payload['phone'] ?? ''));
    $inviteCode = sanitize_text_field((string)($payload['invite_code'] ?? ''));

    if ($email === '' || $userType === '') {
      return new WP_REST_Response(['error' => 'Поля email и user_type обязательны'], 400);
    }
    if (!in_array($userType, vp_login_valid_user_types(), true)) {
      return new WP_REST_Response(['error' => 'Некорректный user_type'], 400);
    }

    $domain = '';
    if (strpos($email, '@') !== false) {
      $parts = explode('@', $email);
      $domain = strtolower((string)end($parts));
    }

    $invite = vp_login_find_invite($adminToken, $inviteCode);
    $allowlist = vp_login_find_allowlisted_domain($adminToken, $domain);

    $manualMode = vp_login_manual_mode_enabled();
    $canAutoApprove = !$manualMode && ($invite || $allowlist);

    $requestPayload = [
      'email' => $email,
      'phone' => $phone,
      'first_name' => $firstName,
      'last_name' => $lastName,
      'user_type' => $userType,
      'status' => $canAutoApprove ? 'approved' : 'pending',
      'auto_approve_method' => $invite ? 'invite_code' : ($allowlist ? 'email_domain' : 'none'),
      'invite_code' => $inviteCode !== '' ? $inviteCode : null,
      'requested_tenant_name' => sanitize_text_field((string)($payload['requested_tenant_name'] ?? '')),
      'requested_tenant_slug' => sanitize_title((string)($payload['requested_tenant_slug'] ?? '')),
      'evidence_note' => sanitize_textarea_field((string)($payload['evidence_note'] ?? '')),
      'source_ip' => sanitize_text_field((string)($_SERVER['REMOTE_ADDR'] ?? '')),
      'user_agent' => substr(sanitize_textarea_field((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 1000),
      'decision_reason' => $canAutoApprove ? 'Auto approved by policy' : 'Manual review required',
    ];

    $onboardingRes = vp_login_create_onboarding_request($adminToken, array_filter($requestPayload, static function ($v) {
      return $v !== null;
    }));

    if (is_wp_error($onboardingRes) || empty($onboardingRes['data']['id'])) {
      return new WP_REST_Response(['error' => 'Не удалось создать заявку'], 502);
    }

    $onboarding = $onboardingRes['data'];

    if (!$canAutoApprove) {
      return new WP_REST_Response([
        'ok' => true,
        'status' => 'pending',
        'message' => 'Заявка принята. При ручной модерации пароль нужно будет задать после подтверждения.',
      ], 200);
    }

    if ($password === '') {
      return new WP_REST_Response([
        'ok' => true,
        'status' => 'pending',
        'message' => 'Для авто-аппрува нужен пароль. Ваша заявка переведена в ручной режим.',
      ], 200);
    }

    $roleName = vp_login_role_name_for_type($userType);
    $roleId = vp_login_role_id_by_name($adminToken, $roleName);
    if ($roleId === '') {
      $roleId = vp_login_role_id_by_name($adminToken, 'api_public');
    }

    $createUserPayload = [
      'email' => $email,
      'password' => $password,
      'status' => 'active',
      'first_name' => $firstName,
      'last_name' => $lastName,
    ];
    if ($roleId !== '') {
      $createUserPayload['role'] = $roleId;
    }

    $userRes = vp_login_directus_request('POST', '/users', $createUserPayload, $adminToken);
    if (is_wp_error($userRes) || empty($userRes['data']['id'])) {
      return new WP_REST_Response(['error' => 'Заявка создана, но не удалось создать пользователя'], 502);
    }

    $createdUserId = (string)$userRes['data']['id'];

    $profilePayload = vp_login_profile_by_user_type($userType, $payload);
    $profilePayload['user_id'] = $createdUserId;
    $profileRes = vp_login_directus_request('POST', '/items/vp_user_profiles', $profilePayload, $adminToken);
    $createdProfileId = is_wp_error($profileRes) ? null : ($profileRes['data']['id'] ?? null);

    $tenantId = '';
    $membershipRole = 'member';
    if ($invite && !empty($invite['tenant_id'])) {
      $tenantId = (string)$invite['tenant_id'];
      if (!empty($invite['role'])) {
        $membershipRole = sanitize_text_field((string)$invite['role']);
      }
    }

    if ($tenantId !== '') {
      vp_login_directus_request('POST', '/items/vp_memberships', [
        'user_id' => $createdUserId,
        'tenant_id' => $tenantId,
        'role' => $membershipRole,
        'status' => 'active',
      ], $adminToken);
    }

    vp_login_directus_request('PATCH', '/items/vp_onboarding_requests/' . rawurlencode((string)$onboarding['id']), [
      'status' => 'approved',
      'created_user_id' => $createdUserId,
      'created_profile_id' => $createdProfileId,
      'decision_reason' => $invite ? 'Approved by invite code' : 'Approved by allowlist domain',
      'auto_approve_method' => $invite ? 'invite_code' : 'email_domain',
    ], $adminToken);

    return new WP_REST_Response([
      'ok' => true,
      'status' => 'approved',
      'message' => 'Заявка одобрена автоматически. Теперь можно войти.',
      'approved_user_id' => $createdUserId,
    ], 200);
  }
}

if (!function_exists('vp_login_route_register_status')) {
  function vp_login_route_register_status(WP_REST_Request $request) {
    $adminToken = vp_login_directus_admin_token();
    if ($adminToken === '') {
      return new WP_REST_Response(['error' => 'DIRECTUS_API_TOKEN is required'], 500);
    }

    $email = sanitize_email((string)$request->get_param('email'));
    if ($email === '') {
      return new WP_REST_Response(['error' => 'email is required'], 400);
    }

    $filter = rawurlencode(wp_json_encode(['email' => ['_eq' => $email]]));
    $res = vp_login_directus_request('GET', '/items/vp_onboarding_requests?limit=1&sort=-id&fields=id,status,auto_approve_method,decision_reason,created_at&filter=' . $filter, null, $adminToken);

    if (is_wp_error($res)) {
      return new WP_REST_Response(['error' => 'Не удалось получить статус заявки'], 502);
    }

    return new WP_REST_Response([
      'ok' => true,
      'exists' => !empty($res['data'][0]),
      'item' => $res['data'][0] ?? null,
    ], 200);
  }
}

if (!function_exists('vp_login_register_routes')) {
  function vp_login_register_routes() {
    register_rest_route('vp/v1', '/login', [
      'methods' => 'POST',
      'permission_callback' => '__return_true',
      'callback' => 'vp_login_route_login',
    ]);

    register_rest_route('vp/v1', '/logout', [
      'methods' => 'POST',
      'permission_callback' => '__return_true',
      'callback' => 'vp_login_route_logout',
    ]);

    register_rest_route('vp/v1', '/me', [
      'methods' => 'GET',
      'permission_callback' => '__return_true',
      'callback' => 'vp_login_route_me',
    ]);

    register_rest_route('vp/v1', '/register-request', [
      'methods' => 'POST',
      'permission_callback' => '__return_true',
      'callback' => 'vp_login_route_register_request',
    ]);

    register_rest_route('vp/v1', '/register-status', [
      'methods' => 'GET',
      'permission_callback' => '__return_true',
      'callback' => 'vp_login_route_register_status',
    ]);
  }
}
add_action('rest_api_init', 'vp_login_register_routes');

add_action('template_redirect', static function () {
  if (is_page('dentist-login')) {
    wp_safe_redirect(home_url('/login/'), 301);
    exit;
  }
});
