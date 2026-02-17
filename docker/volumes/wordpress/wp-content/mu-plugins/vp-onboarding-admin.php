<?php
/**
 * MU Plugin: VP Onboarding Admin (Direct REST to Directus)
 */

if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {

  register_rest_route('vp/v1', '/admin/onboarding', [
    'methods'  => 'GET',
    'permission_callback' => function () {
      return is_user_logged_in() && current_user_can('manage_options');
    },
    'callback' => 'vp_onboarding_admin_list',
  ]);

  register_rest_route('vp/v1', '/admin/onboarding/(?P<id>\d+)/approve', [
    'methods'  => 'POST',
    'permission_callback' => function () {
      return is_user_logged_in() && current_user_can('manage_options');
    },
    'callback' => 'vp_onboarding_admin_approve',
  ]);

  register_rest_route('vp/v1', '/admin/onboarding/(?P<id>\d+)/reject', [
    'methods'  => 'POST',
    'permission_callback' => function () {
      return is_user_logged_in() && current_user_can('manage_options');
    },
    'callback' => 'vp_onboarding_admin_reject',
  ]);
});

/** ===== Directus HTTP helper ===== */
function vp_directus_base_url() {
  // ≈сли у теб€ уже есть общий способ (env/константа) Ч подставь сюда.
  return 'https://directus.xn--b1awacccnl0jqa.xn--p1ai';
}

function vp_directus_service_token() {
  // Ћучше хранить в wp-config.php или env, но пока можно так же как у вас в vp-login.php сделано.
  // ≈сли в проекте уже есть константа/опци€ Ч используй еЄ.
  $token = getenv('DIRECTUS_TOKEN');
  if ($token) return $token;

  // FALLBACK: можно временно хранить в опции WP (потом перенести)
  $opt = get_option('vp_directus_service_token');
  return $opt ?: '';
}

function vp_directus_request($method, $path, $body = null, $query = []) {
  $base = rtrim(vp_directus_base_url(), '/');
  $url  = $base . $path;

  if (!empty($query)) {
    $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
  }

  $token = vp_directus_service_token();
  if (!$token) {
    return new WP_Error('vp_no_directus_token', 'Directus token is missing', ['status' => 500]);
  }

  $args = [
    'method'  => $method,
    'timeout' => 25,
    'headers' => [
      'Authorization' => 'Bearer ' . $token,
      'Content-Type'  => 'application/json',
      'Accept'        => 'application/json',
    ],
  ];

  if ($body !== null) {
    $args['body'] = wp_json_encode($body);
  }

  $res = wp_remote_request($url, $args);
  if (is_wp_error($res)) return $res;

  $code = wp_remote_retrieve_response_code($res);
  $raw  = wp_remote_retrieve_body($res);

  $json = null;
  if (is_string($raw) && $raw !== '') {
    $json = json_decode($raw, true);
  }

  if ($code < 200 || $code >= 300) {
    return new WP_Error(
      'vp_directus_http_' . $code,
      'Directus request failed',
      ['status' => 502, 'http_code' => $code, 'response' => $json ?: $raw]
    );
  }

  return $json ?: [];
}

/** ===== Endpoints ===== */

function vp_onboarding_admin_list(WP_REST_Request $req) {
  $status = $req->get_param('status') ?: 'pending';
  $limit  = intval($req->get_param('limit') ?: 50);

  $query = [
    'limit' => $limit,
    'sort'  => '-created_at',
    'fields'=> 'id,email,phone,first_name,last_name,user_type,status,auto_approve_method,invite_code,created_at,updated_at,reviewed_at,decision_reason,reviewed_by,reviewed_by_email,reviewed_by_wp_id,reviewed_by_wp_login',
    'filter[status][_eq]' => $status,
  ];

  $data = vp_directus_request('GET', '/items/vp_onboarding_requests', null, $query);
  if (is_wp_error($data)) return $data;

  return rest_ensure_response($data);
}

function vp_onboarding_admin_approve(WP_REST_Request $req) {
  $id = intval($req['id']);
  $user = wp_get_current_user();

  $patch = [
    'status'              => 'approved',
    'reviewed_at'         => gmdate('c'),
    'updated_at'          => gmdate('c'),
    'decision_reason'     => null,
    // reviewed_by оставл€ем как есть (uuid directus_users) Ч WP его не знает
    'reviewed_by_email'   => $user->user_email,
    'reviewed_by_wp_id'   => $user->ID,
    'reviewed_by_wp_login'=> $user->user_login,
  ];

  // ¬ажно: чтобы не одобр€ть повторно Ч можно сначала прочитать и проверить status
  $current = vp_directus_request('GET', "/items/vp_onboarding_requests/$id", null, ['fields' => 'id,status']);
  if (is_wp_error($current)) return $current;

  $curStatus = $current['data']['status'] ?? null;
  if ($curStatus !== 'pending') {
    return new WP_Error('vp_not_pending', 'Request is not pending', ['status' => 409, 'current_status' => $curStatus]);
  }

  $res = vp_directus_request('PATCH', "/items/vp_onboarding_requests/$id", $patch);
  if (is_wp_error($res)) return $res;

  return rest_ensure_response($res);
}

function vp_onboarding_admin_reject(WP_REST_Request $req) {
  $id = intval($req['id']);
  $user = wp_get_current_user();

  $reason = $req->get_json_params()['decision_reason'] ?? null;
  if (!$reason) $reason = 'rejected';

  $patch = [
    'status'              => 'rejected',
    'reviewed_at'         => gmdate('c'),
    'updated_at'          => gmdate('c'),
    'decision_reason'     => $reason,
    'reviewed_by_email'   => $user->user_email,
    'reviewed_by_wp_id'   => $user->ID,
    'reviewed_by_wp_login'=> $user->user_login,
  ];

  $current = vp_directus_request('GET', "/items/vp_onboarding_requests/$id", null, ['fields' => 'id,status']);
  if (is_wp_error($current)) return $current;

  $curStatus = $current['data']['status'] ?? null;
  if ($curStatus !== 'pending') {
    return new WP_Error('vp_not_pending', 'Request is not pending', ['status' => 409, 'current_status' => $curStatus]);
  }

  $res = vp_directus_request('PATCH', "/items/vp_onboarding_requests/$id", $patch);
  if (is_wp_error($res)) return $res;

  return rest_ensure_response($res);
}
