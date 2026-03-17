<?php
/**
 * Plugin Name: VP Proxy Directus
 */

// --- Directus connection helpers ---
if (!function_exists('vp_directus_base_url')) {
  function vp_directus_base_url() {
    // Поддерживаем разные имена, чтобы не путаться
    $u = getenv('DIRECTUS_BASE_URL');
    if (!$u) $u = getenv('DIRECTUS_PUBLIC_URL');
    if (!$u) $u = getenv('VP_DIRECTUS_URL');
    if (!$u) $u = getenv('DIRECTUS_URL');
    return $u ? rtrim($u, '/') : '';
  }
}

if (!function_exists('vp_directus_token')) {
  function vp_directus_token() {
    $t = getenv('DIRECTUS_API_TOKEN');
    if (!$t) $t = getenv('VP_DIRECTUS_TOKEN');
    if (!$t) $t = getenv('DIRECTUS_TOKEN');
    return $t ?: '';
  }
}


if (!function_exists('vp_location_bootstrap_callback')) {
    function vp_location_bootstrap_callback(WP_REST_Request $request) {
        $code = strtoupper(trim((string) $request->get_param('code')));
        if ($code === '') {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'code_required',
            ], 400);
        }

        if (!function_exists('vp_directus_base_url') || !function_exists('vp_directus_token')) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'directus_not_available',
            ], 500);
        }

        $base = rtrim((string) vp_directus_base_url(), '/');
        $token = (string) vp_directus_token();

        if ($base === '' || $token === '') {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'directus_not_available',
            ], 500);
        }

        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ];

        // 1. qr_codes by code
        $qrFields = implode(',', [
            'id',
            'code',
            'title',
            'type',
            'is_active',
        ]);

        $qrUrl = $base . '/items/qr_codes'
            . '?filter[code][_eq]=' . rawurlencode($code)
            . '&limit=1'
            . '&fields=' . rawurlencode($qrFields);

        $qrRes = wp_remote_get($qrUrl, [
            'headers' => $headers,
            'timeout' => 20,
        ]);

        if (is_wp_error($qrRes)) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'qr_request_failed',
                'message' => $qrRes->get_error_message(),
            ], 502);
        }

        $qrBody = json_decode(wp_remote_retrieve_body($qrRes), true);
        $qrItem = $qrBody['data'][0] ?? null;

        if (!$qrItem || !is_array($qrItem)) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'lookup_empty',
            ], 404);
        }

        if (empty($qrItem['is_active'])) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'qr_inactive',
            ], 403);
        }

        if (strtolower((string) ($qrItem['type'] ?? '')) !== 'location') {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'location_not_linked',
            ], 400);
        }

        $qrId = (int) ($qrItem['id'] ?? 0);
        if ($qrId <= 0) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'anchor_not_found',
            ], 404);
        }

        // 2. anchor by qr_code_id
        $anchorFields = implode(',', [
            'id',
            'title',
            'code',
            'kind',
            'x',
            'y',
            'node_id',
            'location_id',
            'level_id',
            'is_active',
        ]);

        $anchorUrl = $base . '/items/vp_location_anchors'
            . '?filter[qr_code_id][_eq]=' . rawurlencode((string) $qrId)
            . '&filter[is_active][_eq]=true'
            . '&limit=1'
            . '&fields=' . rawurlencode($anchorFields);

        $anchorRes = wp_remote_get($anchorUrl, [
            'headers' => $headers,
            'timeout' => 20,
        ]);

        if (is_wp_error($anchorRes)) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'anchor_request_failed',
                'message' => $anchorRes->get_error_message(),
            ], 502);
        }

        $anchorBody = json_decode(wp_remote_retrieve_body($anchorRes), true);
        $anchor = $anchorBody['data'][0] ?? null;

        if (!$anchor || !is_array($anchor)) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'anchor_not_found',
            ], 404);
        }

        $locationId = (int) ($anchor['location_id'] ?? 0);
        $levelId = (int) ($anchor['level_id'] ?? 0);

        if ($locationId <= 0 || $levelId <= 0) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'location_not_linked',
            ], 400);
        }

        // 3. location
        $locationFields = implode(',', [
            'id',
            'title',
            'kind',
            'is_active',
        ]);

        $locationUrl = $base . '/items/vp_locations/' . $locationId
            . '?fields=' . rawurlencode($locationFields);

        $locationRes = wp_remote_get($locationUrl, [
            'headers' => $headers,
            'timeout' => 20,
        ]);

        if (is_wp_error($locationRes)) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'location_request_failed',
                'message' => $locationRes->get_error_message(),
            ], 502);
        }

        $locationBody = json_decode(wp_remote_retrieve_body($locationRes), true);
        $location = $locationBody['data'] ?? null;

        if (!$location || !is_array($location)) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'location_not_found',
            ], 404);
        }

        // 4. level
        $levelFields = implode(',', [
            'id',
            'code',
            'title',
            'is_active',
        ]);

        $levelUrl = $base . '/items/vp_location_levels/' . $levelId
            . '?fields=' . rawurlencode($levelFields);

        $levelRes = wp_remote_get($levelUrl, [
            'headers' => $headers,
            'timeout' => 20,
        ]);

        if (is_wp_error($levelRes)) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'level_request_failed',
                'message' => $levelRes->get_error_message(),
            ], 502);
        }

        $levelBody = json_decode(wp_remote_retrieve_body($levelRes), true);
        $level = $levelBody['data'] ?? null;

        if (!$level || !is_array($level)) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'level_not_found',
            ], 404);
        }

        // 5. pois on same location + level
        $poiFields = implode(',', [
            'id',
            'title',
            'kind',
            'brand',
            'description',
            'opening_hours',
            'node_id',
            'x',
            'y',
            'is_active',
            'is_public',
        ]);

        $poiUrl = $base . '/items/vp_location_pois'
            . '?filter[location_id][_eq]=' . rawurlencode((string) $locationId)
            . '&filter[level_id][_eq]=' . rawurlencode((string) $levelId)
            . '&filter[is_active][_eq]=true'
            . '&filter[is_public][_eq]=true'
            . '&limit=100'
            . '&sort=sort,title'
            . '&fields=' . rawurlencode($poiFields);

        $poiRes = wp_remote_get($poiUrl, [
            'headers' => $headers,
            'timeout' => 20,
        ]);

        if (is_wp_error($poiRes)) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'poi_request_failed',
                'message' => $poiRes->get_error_message(),
            ], 502);
        }

        $poiBody = json_decode(wp_remote_retrieve_body($poiRes), true);
        $pois = is_array($poiBody['data'] ?? null) ? $poiBody['data'] : [];

        return new WP_REST_Response([
            'ok' => true,
            'mode' => 'location',
            'location' => [
                'id' => (int) ($location['id'] ?? 0),
                'title' => (string) ($location['title'] ?? ''),
                'kind' => (string) ($location['kind'] ?? ''),
            ],
            'level' => [
                'id' => (int) ($level['id'] ?? 0),
                'code' => (string) ($level['code'] ?? ''),
                'title' => (string) ($level['title'] ?? ''),
            ],
            'anchor' => [
                'id' => (int) ($anchor['id'] ?? 0),
                'title' => (string) ($anchor['title'] ?? ''),
                'code' => (string) ($anchor['code'] ?? ''),
                'kind' => (string) ($anchor['kind'] ?? ''),
                'node_id' => isset($anchor['node_id']) ? (int) $anchor['node_id'] : null,
                'x' => isset($anchor['x']) ? $anchor['x'] : null,
                'y' => isset($anchor['y']) ? $anchor['y'] : null,
            ],
            'pois' => array_map(function ($poi) {
                return [
                    'id' => (int) ($poi['id'] ?? 0),
                    'title' => (string) ($poi['title'] ?? ''),
                    'kind' => (string) ($poi['kind'] ?? ''),
                    'brand' => (string) ($poi['brand'] ?? ''),
                    'description' => (string) ($poi['description'] ?? ''),
                    'opening_hours' => (string) ($poi['opening_hours'] ?? ''),
                    'node_id' => isset($poi['node_id']) ? (int) $poi['node_id'] : null,
                    'x' => isset($poi['x']) ? $poi['x'] : null,
                    'y' => isset($poi['y']) ? $poi['y'] : null,
                ];
            }, $pois),
        ], 200);
    }
}


add_action('rest_api_init', function () {
  register_rest_route('vp/v1', '/lookup', [
    'methods'  => 'GET',
    'callback' => 'vp_lookup_callback',
    'permission_callback' => '__return_true',
  ]);

  register_rest_route('vp/v1', '/suggest', [
    'methods'  => 'GET',
    'callback' => 'vp_suggest_callback',
    'permission_callback' => '__return_true',
  ]);
  
  register_rest_route('vp/v1', '/instruction', [
    'methods'  => 'GET',
    'callback' => 'vp_instruction_callback',
    'permission_callback' => '__return_true',
  ]);

  register_rest_route('vp/v1', '/3d/auth', [
    'methods'  => 'POST',
    'callback' => 'vp_3d_auth_callback',
    'permission_callback' => '__return_true',
  ]);

  register_rest_route('vp/v1', '/3d/file', [
    'methods'  => 'GET',
    'callback' => 'vp_3d_file_callback',
    'permission_callback' => '__return_true',
  ]);

  register_rest_route('vp/v1', '/3d/poster', [
    'methods'  => 'GET',
    'callback' => 'vp_3d_poster_callback',
    'permission_callback' => '__return_true',
  ]);
  
  register_rest_route('vp/v1', '/3d/job', [
    'methods'  => 'POST',
    'callback' => 'vp_3d_job_create',
    'permission_callback' => '__return_true',
  ]);

  register_rest_route('vp/v1', '/location/bootstrap', [
    'methods'  => 'GET',
    'callback' => 'vp_location_bootstrap_callback',
    'permission_callback' => '__return_true',
  ]);
});



function vp_directus_get($pathWithQuery) {
  $base = vp_directus_base_url();
  $token = vp_directus_token();

  if (!$base || !$token) {
    return new WP_Error('directus_env', 'DIRECTUS env missing', ['status' => 500]);
  }

  $url = $base . $pathWithQuery;

  $res = wp_remote_get($url, [
    'timeout' => 20,
    'headers' => [
      'Authorization' => 'Bearer ' . $token,
      'Accept'        => 'application/json',
    ],
  ]);

  if (is_wp_error($res)) return $res;

  $code = wp_remote_retrieve_response_code($res);
  $body = wp_remote_retrieve_body($res);

  if ($code < 200 || $code >= 300) {
    return new WP_Error('directus_http', 'Directus error', [
      'status' => $code,
      'body'   => $body,
    ]);
  }

  $json = json_decode($body, true);
  return is_array($json) ? $json : [];
}

function vp_directus_request($pathWithQuery, $args = []) {
  $base = vp_directus_base_url();
  $token = vp_directus_token();

  if (!$base || !$token) {
    return new WP_Error('directus_env', 'DIRECTUS env missing', ['status' => 500]);
  }

  $url = $base . $pathWithQuery;
  $defaultArgs = [
    'timeout' => 35,
    'headers' => [
      'Authorization' => 'Bearer ' . $token,
      'Accept'        => '*/*',
    ],
  ];

  $merged = wp_parse_args($args, $defaultArgs);
  $merged['headers'] = array_merge($defaultArgs['headers'], $args['headers'] ?? []);
  return wp_remote_get($url, $merged);
}

function vp_scene_type_3d($rawType) {
  $type = strtolower(trim((string)$rawType));
  return in_array($type, ['3d', '3d/navigation', 'navigation', 'nav', 'location'], true);
}

function vp_scene_expired($expiresAt) {
  if (!$expiresAt) return false;
  $ts = strtotime((string)$expiresAt);
  if (!$ts) return false;
  return $ts < time();
}

function vp_3d_scene_payload($scene, $code = '') {
  if (!is_array($scene) || empty($scene['id'])) return null;

  $requiresPassword = !empty($scene['requires_password']);
  $payload = [
    'id' => $scene['id'],
    'title' => (string)($scene['title'] ?? ''),
    'kind' => (string)($scene['kind'] ?? 'generic'),
    'requires_password' => $requiresPassword,
    'password_hint' => (string)($scene['password_hint'] ?? ''),
    'expires_at' => $scene['expires_at'] ?? null,
    'is_active' => !empty($scene['is_active']),
  ];

  if (!empty($scene['poster_file']) && $code !== '') {
    $payload['poster_url'] = home_url('/wp-json/vp/v1/3d/poster?code=' . rawurlencode($code));
  }

  if (!$requiresPassword && !empty($scene['model_file']) && $code !== '') {
    $payload['model_url'] = home_url('/wp-json/vp/v1/3d/file?code=' . rawurlencode($code));
  }

  return $payload;
}

function vp_3d_fetch_by_code($code) {
  $filter = rawurlencode(json_encode([
    'code' => ['_eq' => $code],
  ], JSON_UNESCAPED_UNICODE));

  $fields = rawurlencode('id,code,title,type,scene_id.id,scene_id.title,scene_id.kind,scene_id.model_file,scene_id.poster_file,scene_id.viewer_config,scene_id.requires_password,scene_id.password_hash,scene_id.password_hint,scene_id.expires_at,scene_id.is_active');
  $json = vp_directus_get("/items/qr_codes?limit=1&fields={$fields}&filter={$filter}");
  if (is_wp_error($json)) return $json;

  $rows = $json['data'] ?? [];
  if (!is_array($rows) || count($rows) === 0) {
    return new WP_Error('scene_not_found', 'QR code not found', ['status' => 404]);
  }

  $row = $rows[0];
  $scene = is_array($row['scene_id'] ?? null) ? $row['scene_id'] : null;

  if (!$scene || empty($scene['id'])) {
    return new WP_Error('scene_not_linked', '3D scene is not linked to this QR code', ['status' => 404]);
  }

  return [
    'row' => $row,
    'scene' => $scene,
  ];
}

function vp_3d_stream_asset($assetId, $noStore = false) {
  $assetId = trim((string)$assetId);
  if ($assetId === '') {
    return new WP_REST_Response(['error' => 'asset_missing'], 404);
  }

  $res = vp_directus_request('/assets/' . rawurlencode($assetId));
  if (is_wp_error($res)) {
    return new WP_REST_Response(['error' => 'directus_asset_error'], 502);
  }

  $status = wp_remote_retrieve_response_code($res);
  $body = wp_remote_retrieve_body($res);
  if ($status < 200 || $status >= 300 || $body === '') {
    return new WP_REST_Response(['error' => 'asset_not_found'], $status >= 400 ? $status : 404);
  }

  $contentType = (string)wp_remote_retrieve_header($res, 'content-type');

  if ($contentType === '') {
    $contentType = 'application/octet-stream';
  }

  if (str_contains($contentType, 'application/octet-stream')) {
    $guess = strtolower(pathinfo($assetId, PATHINFO_EXTENSION));
    if ($guess === 'glb') {
      $contentType = 'model/gltf-binary';
    }
  }

  nocache_headers();
  status_header(200);
  header('Content-Type: ' . $contentType);
  header('Content-Length: ' . strlen($body));
  header('X-Content-Type-Options: nosniff');
  header('Content-Disposition: inline');
  header($noStore ? 'Cache-Control: no-store, max-age=0' : 'Cache-Control: private, max-age=120');

  echo $body;
  exit;
}

function vp_3d_make_token($code, $sceneId) {
  $token = wp_generate_password(48, false, false);
  $key = 'vp_3d_token_' . hash('sha256', $token);
  set_transient($key, [
    'code' => (string)$code,
    'scene_id' => (string)$sceneId,
  ], 10 * MINUTE_IN_SECONDS);
  return $token;
}

function vp_3d_consume_token($token, $code, $sceneId, $consume = true) {
  $token = trim((string)$token);
  if ($token === '') {
    return new WP_Error('token_required', 'Token is required', ['status' => 401]);
  }

  $key = 'vp_3d_token_' . hash('sha256', $token);
  $payload = get_transient($key);
  if (!is_array($payload)) {
    return new WP_Error('token_invalid', 'Token is invalid or expired', ['status' => 401]);
  }

  if ((string)($payload['code'] ?? '') !== (string)$code || (string)($payload['scene_id'] ?? '') !== (string)$sceneId) {
    return new WP_Error('token_mismatch', 'Token does not match requested resource', ['status' => 403]);
  }
  
  if ($consume) {
    delete_transient($key);
  }

  return true;
}

function vp_lookup_callback(WP_REST_Request $req) {
  $code = trim((string)$req->get_param('code'));
  if ($code === '') {
    return new WP_REST_Response(['error' => 'code_required'], 400);
  }

  $filter = rawurlencode(json_encode([
    'code' => ['_eq' => $code],
  ], JSON_UNESCAPED_UNICODE));

  // Не разворачиваем qr_file.* / qr_file_png.*:
  // после перехода на WordPress storage эти relation-expansions валят Directus.
  // Для lookup фронту достаточно scalar-полей qr_file / qr_file_png и product/scene metadata.
  $fields = rawurlencode(
    'id,code,type,title,instruction_url,is_active,notes,location_title,location_payload,service_payload,' .
    'product_id.*,qr_payload_url,qr_file,qr_file_png,instruction_id,tenant_id,' .
    'scene_id.id,scene_id.title,scene_id.kind,scene_id.model_file,scene_id.poster_file,' .
    'scene_id.viewer_config,scene_id.requires_password,scene_id.password_hint,' .
    'scene_id.expires_at,scene_id.is_active'
  );

  $json = vp_directus_get("/items/qr_codes?limit=1&fields={$fields}&filter={$filter}");
  if (is_wp_error($json)) {
    $d = $json->get_error_data();
    return new WP_REST_Response([
      'error'  => $json->get_error_code(),
      'status' => $d['status'] ?? 500,
      'body'   => $d['body'] ?? null
    ], $d['status'] ?? 500);
  }

  if (!empty($json['data']) && is_array($json['data'])) {
    foreach ($json['data'] as &$item) {
      $type = $item['type'] ?? '';
      $scene = is_array($item['scene_id'] ?? null) ? $item['scene_id'] : null;

      if (vp_scene_type_3d($type) && $scene) {
        $item['scene'] = vp_3d_scene_payload($scene, (string)($item['code'] ?? ''));
      }

      unset($item['scene_id']);
    }
    unset($item);
  }

  return new WP_REST_Response($json, 200);
}

function vp_3d_auth_callback(WP_REST_Request $req) {
  $body = $req->get_json_params();
  $code = strtoupper(trim((string)($body['code'] ?? '')));
  $password = (string)($body['password'] ?? '');

  if ($code === '') {
    return new WP_REST_Response(['ok' => false, 'error' => 'code_required'], 400);
  }

  $bundle = vp_3d_fetch_by_code($code);
  if (is_wp_error($bundle)) {
    return new WP_REST_Response(['ok' => false, 'error' => $bundle->get_error_code()], (int)($bundle->get_error_data()['status'] ?? 404));
  }

  $scene = $bundle['scene'];
  if (empty($scene['is_active'])) {
    return new WP_REST_Response(['ok' => false, 'error' => 'scene_disabled'], 403);
  }
  if (vp_scene_expired($scene['expires_at'] ?? null)) {
    return new WP_REST_Response(['ok' => false, 'error' => 'scene_expired'], 410);
  }

  if (!empty($scene['requires_password'])) {
    $hash = (string)($scene['password_hash'] ?? '');
    if ($hash === '' || $password === '' || !password_verify($password, $hash)) {
      return new WP_REST_Response(['ok' => false, 'error' => 'invalid_password'], 401);
    }
  }

  $token = vp_3d_make_token($code, $scene['id']);
  return new WP_REST_Response([
    'ok' => true,
    'token' => $token,
    'expires_in' => 600,
  ], 200);
}

function vp_3d_file_callback(WP_REST_Request $req) {
  $code = strtoupper(trim((string)$req->get_param('code')));
  $token = trim((string)$req->get_param('token'));

  if ($code === '') {
    return new WP_REST_Response(['error' => 'code_required'], 400);
  }

  $bundle = vp_3d_fetch_by_code($code);
  if (is_wp_error($bundle)) {
    return new WP_REST_Response(['error' => $bundle->get_error_code()], (int)($bundle->get_error_data()['status'] ?? 404));
  }

  $scene = $bundle['scene'];
  if (empty($scene['is_active'])) {
    return new WP_REST_Response(['error' => 'scene_disabled'], 403);
  }
  if (vp_scene_expired($scene['expires_at'] ?? null)) {
    return new WP_REST_Response(['error' => 'scene_expired'], 410);
  }

  $requiresPassword = !empty($scene['requires_password']);
  if ($requiresPassword) {
    $shouldConsumeToken = strtoupper((string)$req->get_method()) !== 'HEAD';
    $tokenValidation = vp_3d_consume_token($token, $code, $scene['id'], $shouldConsumeToken);
    if (is_wp_error($tokenValidation)) {
      return new WP_REST_Response(['error' => $tokenValidation->get_error_code()], (int)($tokenValidation->get_error_data()['status'] ?? 401));
    }
  }

  return vp_3d_stream_asset($scene['model_file'] ?? '', $requiresPassword);
}

function vp_3d_poster_callback(WP_REST_Request $req) {
  $code = strtoupper(trim((string)$req->get_param('code')));
  if ($code === '') {
    return new WP_REST_Response(['error' => 'code_required'], 400);
  }

  $bundle = vp_3d_fetch_by_code($code);
  if (is_wp_error($bundle)) {
    return new WP_REST_Response(['error' => $bundle->get_error_code()], (int)($bundle->get_error_data()['status'] ?? 404));
  }

  $scene = $bundle['scene'];
  if (empty($scene['is_active']) || vp_scene_expired($scene['expires_at'] ?? null)) {
    return new WP_REST_Response(['error' => 'scene_unavailable'], 410);
  }

  return vp_3d_stream_asset($scene['poster_file'] ?? '', true);
}

function vp_suggest_callback(WP_REST_Request $req) {
  $term = trim((string)$req->get_param('term'));
  if (mb_strlen($term) < 2) {
    return new WP_REST_Response(['data' => []], 200);
  }

  // Пытаемся искать “по смыслу”:
  // - qr_codes.code
  // - qr_codes.title
  // - qr_codes.type
  // - products.title (через relation product_id)
  $filterObj = [
    '_or' => [
      ['code'  => ['_icontains' => $term]],
      ['title' => ['_icontains' => $term]],
      ['type'  => ['_icontains' => $term]],
      ['product_id' => ['title' => ['_icontains' => $term]]],
      ['product_id' => ['model' => ['_icontains' => $term]]],
      ['product_id' => ['sku'   => ['_icontains' => $term]]],
    ],
  ];

  $filter = rawurlencode(json_encode($filterObj, JSON_UNESCAPED_UNICODE));

  $fields = rawurlencode('id,code,title,type,instruction_url,product_id.id,product_id.title,product_id.model,product_id.sku,product_id.instruction_url');

  $json = vp_directus_get("/items/qr_codes?limit=20&fields={$fields}&filter={$filter}&sort=-id");
  if (is_wp_error($json)) {
    $d = $json->get_error_data();
    return new WP_REST_Response([
      'error'  => $json->get_error_code(),
      'status' => $d['status'] ?? 500,
      'body'   => $d['body'] ?? null
    ], $d['status'] ?? 500);
  }

  $items = $json['data'] ?? [];
  if (!is_array($items)) $items = [];

  // Простое “ранжирование по смыслу”: совпадение в code важнее, затем title, затем product.title
  $t = mb_strtolower($term);
  $score = function($it) use ($t) {
    $code = mb_strtolower((string)($it['code'] ?? ''));
    $ttl  = mb_strtolower((string)($it['title'] ?? ''));
    $typ  = mb_strtolower((string)($it['type'] ?? ''));
    $pt   = mb_strtolower((string)($it['product_id']['title'] ?? ''));

    $s = 0;
    if ($code !== '' && mb_strpos($code, $t) !== false) $s += 60;
    if ($ttl  !== '' && mb_strpos($ttl,  $t) !== false) $s += 35;
    if ($pt   !== '' && mb_strpos($pt,   $t) !== false) $s += 25;
    if ($typ  !== '' && mb_strpos($typ,  $t) !== false) $s += 10;
    return $s;
  };

  usort($items, function($a, $b) use ($score) {
    return $score($b) <=> $score($a);
  });

  $out = [];
  foreach ($items as $it) {
    $code = (string)($it['code'] ?? '');
    if ($code === '') continue;

    $type = (string)($it['type'] ?? '');
    $title = (string)($it['title'] ?? '');
    $p = $it['product_id'] ?? null;

    $productTitle = is_array($p) ? (string)($p['title'] ?? '') : '';
    $productSku   = is_array($p) ? (string)($p['sku'] ?? '') : '';
    $productModel = is_array($p) ? (string)($p['model'] ?? '') : '';

    $sub = '';
    if ($productTitle) $sub = $productTitle;
    if ($productModel) $sub = $sub ? ($sub . ' • ' . $productModel) : $productModel;
    if ($productSku)   $sub = $sub ? ($sub . ' • ' . $productSku) : $productSku;
    if (!$sub && $title) $sub = $title;

    $out[] = [
      'code'  => $code,
      'kind'  => $type ?: 'unknown',
      'title' => $title ?: ($productTitle ?: $code),
      'sub'   => $sub,
    ];
  }

  // убрать дубли
  $uniq = [];
  $final = [];
  foreach ($out as $row) {
    if (isset($uniq[$row['code']])) continue;
    $uniq[$row['code']] = true;
    $final[] = $row;
  }

  return new WP_REST_Response(['data' => array_slice($final, 0, 12)], 200);
}

function vp_directus_asset_url($fileId) {
  $base = vp_directus_base_url();
  if (!$base || !$fileId) return null;
  return $base . '/assets/' . $fileId;
}

function vp_instruction_fallback_response($code, $row, $reason = 'instruction_access_limited') {
  $product = is_array($row['product_id'] ?? null) ? $row['product_id'] : [];
  $payload = [];
  if (!empty($row['location_payload']) && is_array($row['location_payload'])) {
    $payload = $row['location_payload'];
  } elseif (!empty($row['service_payload']) && is_array($row['service_payload'])) {
    $payload = $row['service_payload'];
  }

  $instructionUrl = '';
  if (!empty($row['instruction_url'])) {
    $instructionUrl = (string)$row['instruction_url'];
  } elseif (!empty($product['instruction_url'])) {
    $instructionUrl = (string)$product['instruction_url'];
  }

  $description = '';
  if (!empty($payload['description']) && is_string($payload['description'])) {
    $description = $payload['description'];
  } elseif (!empty($product['description']) && is_string($product['description'])) {
    $description = $product['description'];
  }

  $response = [
    'code' => $code,
    'product' => $product,
    'instruction' => [
      'title' => (string)($row['title'] ?? ($product['title'] ?? "Инструкция ({$code})")),
      'description' => $description,
      'instruction_url' => $instructionUrl,
      'steps' => [],
      'is_fallback' => true,
    ],
    'diagnostics' => [
      'warning' => $reason,
    ],
  ];

  if (array_key_exists('type', $row)) {
    $response['type'] = $row['type'];
  }

  if (!empty($row['location_payload']) && is_array($row['location_payload'])) {
    $response['payload'] = $row['location_payload'];
  } elseif (!empty($row['service_payload']) && is_array($row['service_payload'])) {
    $response['payload'] = $row['service_payload'];
  } elseif (!empty($row['qr_payload_url']) && is_string($row['qr_payload_url'])) {
    $response['payload'] = ['qr_payload_url' => $row['qr_payload_url']];
  }

  return new WP_REST_Response($response, 200);
  }

  function vp_instruction_callback(WP_REST_Request $req) {
  $code = trim((string)$req->get_param('code'));
  if ($code === '') {
    return new WP_REST_Response(['error' => 'code_required'], 400);
  }

  // 1) Получаем instruction_id через qr_codes по code
  $filter = rawurlencode(json_encode([
    'code' => ['_eq' => $code],
  ], JSON_UNESCAPED_UNICODE));

  // Важно: используем только реально существующие и разрешённые поля qr_codes.
  // Поле `payload` в текущей схеме отсутствует, из-за этого возможны ACL/403.
  $fields = rawurlencode('code,title,type,instruction_url,location_title,service_payload,location_payload,qr_payload_url,instruction_id,product_id.*');

  $qr = vp_directus_get("/items/qr_codes?limit=1&fields={$fields}&filter={$filter}");
  if (is_wp_error($qr)) {
    $d = $qr->get_error_data();
    return new WP_REST_Response([
      'error'  => $qr->get_error_code(),
      'status' => $d['status'] ?? 500,
      'body'   => $d['body'] ?? null
    ], $d['status'] ?? 500);
  }

  $rows = $qr['data'] ?? [];
  if (!is_array($rows) || count($rows) === 0) {
    return new WP_REST_Response(['error' => 'NOT_FOUND'], 404);
  }

  $row = $rows[0];
  $instruction_id = $row['instruction_id'] ?? null;

  if (!$instruction_id) {
    return new WP_REST_Response(['error' => 'NO_INSTRUCTION_LINKED'], 404);
  }


  // 2) Забираем instruction_set БЕЗ relation field "steps" (обход Directus ACL на поле steps)
  $instFields = rawurlencode('id,title,brand,model,level,language,notes,source_url,is_published');
  $inst = vp_directus_get("/items/instruction_sets/{$instruction_id}?fields={$instFields}");
  if (is_wp_error($inst)) {
    $d = $inst->get_error_data();

    if (($d['status'] ?? 0) === 401 || ($d['status'] ?? 0) === 403) {
      return vp_instruction_fallback_response($code, $row, 'instruction_set_forbidden');
    }

    return new WP_REST_Response([
      'error'  => $inst->get_error_code(),
      'status' => $d['status'] ?? 500,
      'body'   => $d['body'] ?? null
    ], $d['status'] ?? 500);
  }

  $instruction = $inst['data'] ?? null;
  if (!$instruction) {
    return new WP_REST_Response(['error' => 'NOT_FOUND'], 404);
  }
  
  if (empty($instruction['description'])) {
  $instruction['description'] = (string)($row['product_id']['description'] ?? '');
  }

  // 3) Забираем steps отдельным запросом из instruction_steps
  $stepsFilter = rawurlencode(json_encode([
    'instruction_id' => ['_eq' => intval($instruction_id)],
  ], JSON_UNESCAPED_UNICODE));
  $stepsFields = rawurlencode('id,instruction_id,step_no,title,body,image_file,hotspots');

  $stepsRes = vp_directus_get("/items/instruction_steps?limit=200&fields={$stepsFields}&filter={$stepsFilter}&sort=step_no");
  if (is_wp_error($stepsRes)) {
    $d = $stepsRes->get_error_data();

    if (($d['status'] ?? 0) === 401 || ($d['status'] ?? 0) === 403) {
      $stepsRes = ['data' => []];
      $instruction['steps_access_limited'] = true;
    } else {
      return new WP_REST_Response([
        'error'  => $stepsRes->get_error_code(),
        'status' => $d['status'] ?? 500,
        'body'   => $d['body'] ?? null
      ], $d['status'] ?? 500);
    }
  }

  $steps = $stepsRes['data'] ?? [];
  if (is_array($steps)) {
    foreach ($steps as &$s) {
      if (!empty($s['image_file'])) {
        $s['image_file_url'] = vp_directus_asset_url($s['image_file']);
      }
    }
  }

  $instruction['steps'] = $steps;


  // Нормализуем payload из реальных полей qr_codes.
  $normalizedPayload = null;
  if (!empty($row['location_payload']) && is_array($row['location_payload'])) {
    $normalizedPayload = $row['location_payload'];
  } elseif (!empty($row['service_payload']) && is_array($row['service_payload'])) {
    $normalizedPayload = $row['service_payload'];
  }

  if (!is_array($normalizedPayload)) {
    $normalizedPayload = [];
  }

  if (!empty($row['qr_payload_url']) && is_string($row['qr_payload_url']) && empty($normalizedPayload['qr_payload_url'])) {
    $normalizedPayload['qr_payload_url'] = $row['qr_payload_url'];
  }

  if (!empty($row['location_title']) && is_string($row['location_title']) && empty($normalizedPayload['location_title'])) {
    $normalizedPayload['location_title'] = $row['location_title'];
  }

  $response = [
    'code' => $code,
    'product' => $row['product_id'] ?? null,
    'instruction' => $instruction,
    'payload' => $normalizedPayload,
  ];

  if (array_key_exists('type', $row)) {
  $response['type'] = $row['type'];
  }

  return new WP_REST_Response($response, 200);

  } // конец функции vp_instruction_callback
  


function vp_location_safe_int($value) {
  if (is_numeric($value)) {
    return (int) $value;
  }
  return null;
}

function vp_location_safe_text($value) {
  return is_scalar($value) ? trim((string) $value) : '';
}

function vp_location_safe_bool($value) {
  return !empty($value);
}

function vp_location_extract_relation($value) {
  if (is_array($value) && array_key_exists('id', $value)) {
    return $value;
  }

  if (is_numeric($value)) {
    return ['id' => (int) $value];
  }

  return null;
}

function vp_location_fetch_qr_by_code($code) {
  $filter = rawurlencode(json_encode([
    'code' => ['_eq' => $code],
  ], JSON_UNESCAPED_UNICODE));

  $fields = rawurlencode('id,code,title,type,is_active,location_title,location_payload,tenant_id');
  $json = vp_directus_get("/items/qr_codes?limit=1&fields={$fields}&filter={$filter}");
  if (is_wp_error($json)) {
    return $json;
  }

  $rows = $json['data'] ?? [];
  if (!is_array($rows) || count($rows) === 0) {
    return new WP_Error('lookup_empty', 'QR code not found', ['status' => 404]);
  }

  return $rows[0];
}

function vp_location_fetch_anchor_by_qr_id($qrId) {
  $filter = rawurlencode(json_encode([
    '_and' => [
      ['qr_code_id' => ['_eq' => (int) $qrId]],
      ['is_active' => ['_eq' => true]],
    ],
  ], JSON_UNESCAPED_UNICODE));

  $fields = rawurlencode(
    'id,title,code,kind,x,y,heading_deg,meta_json,is_active,' .
    'location_id.id,location_id.title,location_id.slug,location_id.kind,location_id.description,location_id.is_active,' .
    'level_id.id,level_id.code,level_id.title,level_id.z_index,level_id.is_active,level_id.meta_json'
  );

  $json = vp_directus_get("/items/vp_location_anchors?limit=1&fields={$fields}&filter={$filter}");
  if (is_wp_error($json)) {
    return $json;
  }

  $rows = $json['data'] ?? [];
  if (!is_array($rows) || count($rows) === 0) {
    return new WP_Error('anchor_not_found', 'Location anchor not found', ['status' => 404]);
  }

  return $rows[0];
}

function vp_location_fetch_pois($locationId, $levelId) {
  $and = [
    ['location_id' => ['_eq' => (int) $locationId]],
    ['is_active' => ['_eq' => true]],
  ];

  if ($levelId !== null) {
    $and[] = ['level_id' => ['_eq' => (int) $levelId]];
  }

  $filter = rawurlencode(json_encode([
    '_and' => $and,
  ], JSON_UNESCAPED_UNICODE));

  $fields = rawurlencode('id,title,slug,kind,brand,description,icon,x,y,url,phone,opening_hours,sort,is_public');
  $json = vp_directus_get("/items/vp_location_pois?limit=100&sort=sort,title&fields={$fields}&filter={$filter}");
  if (is_wp_error($json)) {
    return $json;
  }

  $rows = $json['data'] ?? [];
  return is_array($rows) ? $rows : [];
}

function vp_location_normalize_payload($qr, $anchor, $pois) {
  $location = vp_location_extract_relation($anchor['location_id'] ?? null);
  $level = vp_location_extract_relation($anchor['level_id'] ?? null);

  $locationPayload = [
    'id' => vp_location_safe_int($location['id'] ?? null),
    'title' => vp_location_safe_text($location['title'] ?? ($qr['location_title'] ?? '')),
    'slug' => vp_location_safe_text($location['slug'] ?? ''),
    'kind' => vp_location_safe_text($location['kind'] ?? 'location'),
    'description' => vp_location_safe_text($location['description'] ?? ''),
    'is_active' => vp_location_safe_bool($location['is_active'] ?? true),
  ];

  $levelPayload = [
    'id' => vp_location_safe_int($level['id'] ?? null),
    'code' => vp_location_safe_text($level['code'] ?? ''),
    'title' => vp_location_safe_text($level['title'] ?? ''),
    'z_index' => vp_location_safe_int($level['z_index'] ?? null),
    'is_active' => vp_location_safe_bool($level['is_active'] ?? true),
  ];

  $anchorPayload = [
    'id' => vp_location_safe_int($anchor['id'] ?? null),
    'title' => vp_location_safe_text($anchor['title'] ?? ''),
    'code' => vp_location_safe_text($anchor['code'] ?? ''),
    'kind' => vp_location_safe_text($anchor['kind'] ?? 'anchor'),
    'x' => vp_location_safe_int($anchor['x'] ?? null),
    'y' => vp_location_safe_int($anchor['y'] ?? null),
    'heading_deg' => vp_location_safe_int($anchor['heading_deg'] ?? null),
  ];

  $poiPayload = [];
  foreach ($pois as $poi) {
    if (!is_array($poi)) {
      continue;
    }

    $poiPayload[] = [
      'id' => vp_location_safe_int($poi['id'] ?? null),
      'title' => vp_location_safe_text($poi['title'] ?? ''),
      'slug' => vp_location_safe_text($poi['slug'] ?? ''),
      'kind' => vp_location_safe_text($poi['kind'] ?? ''),
      'brand' => vp_location_safe_text($poi['brand'] ?? ''),
      'description' => vp_location_safe_text($poi['description'] ?? ''),
      'icon' => vp_location_safe_text($poi['icon'] ?? ''),
      'x' => vp_location_safe_int($poi['x'] ?? null),
      'y' => vp_location_safe_int($poi['y'] ?? null),
      'url' => vp_location_safe_text($poi['url'] ?? ''),
      'phone' => vp_location_safe_text($poi['phone'] ?? ''),
      'opening_hours' => vp_location_safe_text($poi['opening_hours'] ?? ''),
      'sort' => vp_location_safe_int($poi['sort'] ?? null),
    ];
  }

  return [
    'ok' => true,
    'mode' => 'location',
    'qr' => [
      'id' => vp_location_safe_int($qr['id'] ?? null),
      'code' => vp_location_safe_text($qr['code'] ?? ''),
      'title' => vp_location_safe_text($qr['title'] ?? ''),
      'type' => vp_location_safe_text($qr['type'] ?? 'location'),
    ],
    'location' => $locationPayload,
    'level' => $levelPayload,
    'anchor' => $anchorPayload,
    'pois' => $poiPayload,
  ];
}

function vp_location_bootstrap_callback(WP_REST_Request $request) {
  $code = strtoupper(trim((string) $request->get_param('code')));
  if ($code === '') {
    return new WP_REST_Response([
      'ok' => false,
      'error' => 'code_required',
    ], 400);
  }

  $qr = vp_location_fetch_qr_by_code($code);
  if (is_wp_error($qr)) {
    return new WP_REST_Response([
      'ok' => false,
      'error' => $qr->get_error_code(),
    ], (int) ($qr->get_error_data()['status'] ?? 404));
  }

  if (strtolower((string) ($qr['type'] ?? '')) !== 'location') {
    return new WP_REST_Response([
      'ok' => false,
      'error' => 'location_type_required',
    ], 400);
  }

  if (empty($qr['is_active'])) {
    return new WP_REST_Response([
      'ok' => false,
      'error' => 'scene_disabled',
    ], 404);
  }

  $anchor = vp_location_fetch_anchor_by_qr_id((int) $qr['id']);
  if (is_wp_error($anchor)) {
    return new WP_REST_Response([
      'ok' => false,
      'error' => $anchor->get_error_code(),
    ], (int) ($anchor->get_error_data()['status'] ?? 404));
  }

  $location = vp_location_extract_relation($anchor['location_id'] ?? null);
  $level = vp_location_extract_relation($anchor['level_id'] ?? null);
  $locationId = vp_location_safe_int($location['id'] ?? null);
  $levelId = vp_location_safe_int($level['id'] ?? null);

  if (!$locationId) {
    return new WP_REST_Response([
      'ok' => false,
      'error' => 'location_not_linked',
    ], 404);
  }

  $pois = vp_location_fetch_pois($locationId, $levelId);
  if (is_wp_error($pois)) {
    return new WP_REST_Response([
      'ok' => false,
      'error' => $pois->get_error_code(),
    ], (int) ($pois->get_error_data()['status'] ?? 502));
  }

  return new WP_REST_Response(vp_location_normalize_payload($qr, $anchor, $pois), 200);
}
