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

function vp_lookup_callback(WP_REST_Request $req) {
  $code = trim((string)$req->get_param('code'));
  if ($code === '') {
    return new WP_REST_Response(['error' => 'code_required'], 400);
  }

  $filter = rawurlencode(json_encode([
    'code' => ['_eq' => $code],
  ], JSON_UNESCAPED_UNICODE));

  // product_id.* чтобы сразу отдавать “смысл”
  $fields = rawurlencode('*,product_id.*,qr_file.*,qr_file_png.*');

  $json = vp_directus_get("/items/qr_codes?limit=1&fields={$fields}&filter={$filter}");
  if (is_wp_error($json)) {
    $d = $json->get_error_data();
    return new WP_REST_Response([
      'error'  => $json->get_error_code(),
      'status' => $d['status'] ?? 500,
      'body'   => $d['body'] ?? null
    ], $d['status'] ?? 500);
  }

  return new WP_REST_Response($json, 200);
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

function vp_instruction_callback(WP_REST_Request $req) {
  $code = trim((string)$req->get_param('code'));
  if ($code === '') {
    return new WP_REST_Response(['error' => 'code_required'], 400);
  }

  // 1) Получаем instruction_id через qr_codes по code
  $filter = rawurlencode(json_encode([
    'code' => ['_eq' => $code],
  ], JSON_UNESCAPED_UNICODE));

  $fields = rawurlencode('code,title,type,payload,instruction_id,product_id.*');

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

  // 3) Забираем steps отдельным запросом из instruction_steps
  $stepsFilter = rawurlencode(json_encode([
    'instruction_id' => ['_eq' => intval($instruction_id)],
  ], JSON_UNESCAPED_UNICODE));
  $stepsFields = rawurlencode('id,instruction_id,step_no,title,body,image_file,hotspots');

  $stepsRes = vp_directus_get("/items/instruction_steps?limit=200&fields={$stepsFields}&filter={$stepsFilter}&sort=step_no");
  if (is_wp_error($stepsRes)) {
    $d = $stepsRes->get_error_data();
    return new WP_REST_Response([
      'error'  => $stepsRes->get_error_code(),
      'status' => $d['status'] ?? 500,
      'body'   => $d['body'] ?? null
    ], $d['status'] ?? 500);
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


  $response = [
    'code' => $code,
    'product' => $row['product_id'] ?? null,
    'instruction' => $instruction,
  ];

  if (array_key_exists('type', $row)) {
    $response['type'] = $row['type'];
  }
  if (array_key_exists('payload', $row)) {
    $response['payload'] = $row['payload'];
  }

  return new WP_REST_Response($response, 200);
}
