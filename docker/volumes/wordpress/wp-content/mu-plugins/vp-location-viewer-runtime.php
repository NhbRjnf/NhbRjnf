<?php
/**
 * Plugin Name: VP Location Viewer Runtime
 * Description: Safe WordPress proxy bootstrap for indoor navigation viewer.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('vp_lvr_directus_base_url')) {
    function vp_lvr_directus_base_url() {
        $u = getenv('DIRECTUS_BASE_URL');
        if (!$u) $u = getenv('DIRECTUS_PUBLIC_URL');
        if (!$u) $u = getenv('VP_DIRECTUS_URL');
        if (!$u) $u = getenv('DIRECTUS_URL');
        return $u ? rtrim((string) $u, '/') : '';
    }
}

if (!function_exists('vp_lvr_directus_token')) {
    function vp_lvr_directus_token() {
        $t = getenv('DIRECTUS_API_TOKEN');
        if (!$t) $t = getenv('VP_DIRECTUS_TOKEN');
        if (!$t) $t = getenv('DIRECTUS_TOKEN');
        return $t ? (string) $t : '';
    }
}

if (!function_exists('vp_lvr_directus_get')) {
    function vp_lvr_directus_get($pathWithQuery) {
        $base = vp_lvr_directus_base_url();
        $token = vp_lvr_directus_token();

        if ($base === '' || $token === '') {
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

        if (is_wp_error($res)) {
            return $res;
        }

        $code = (int) wp_remote_retrieve_response_code($res);
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
}

if (!function_exists('vp_lvr_extract_relation')) {
    function vp_lvr_extract_relation($value) {
        if (is_array($value) && array_key_exists('id', $value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return ['id' => (int) $value];
        }

        return null;
    }
}

if (!function_exists('vp_lvr_safe_int')) {
    function vp_lvr_safe_int($value) {
        return is_numeric($value) ? (int) $value : null;
    }
}

if (!function_exists('vp_lvr_safe_text')) {
    function vp_lvr_safe_text($value) {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}

if (!function_exists('vp_lvr_safe_bool')) {
    function vp_lvr_safe_bool($value) {
        return !empty($value);
    }
}

if (!function_exists('vp_lvr_scene_type_3d')) {
    function vp_lvr_scene_type_3d($rawType) {
        $type = strtolower(trim((string) $rawType));
        return in_array($type, ['3d', '3d/navigation', 'navigation', 'nav', 'location'], true);
    }
}

if (!function_exists('vp_lvr_scene_payload')) {
    function vp_lvr_scene_payload($scene, $code = '') {
        if (!is_array($scene) || empty($scene['id'])) {
            return null;
        }

        $requiresPassword = !empty($scene['requires_password']);

        $payload = [
            'id' => (int) $scene['id'],
            'title' => (string) ($scene['title'] ?? ''),
            'kind' => (string) ($scene['kind'] ?? 'generic'),
            'requires_password' => $requiresPassword,
            'password_hint' => (string) ($scene['password_hint'] ?? ''),
            'expires_at' => $scene['expires_at'] ?? null,
            'is_active' => !empty($scene['is_active']),
        ];

        if ($code !== '' && !empty($scene['poster_file'])) {
            $payload['poster_url'] = home_url('/wp-json/vp/v1/3d/poster?code=' . rawurlencode($code));
        }

        if ($code !== '' && !$requiresPassword && !empty($scene['model_file'])) {
            $payload['model_url'] = home_url('/wp-json/vp/v1/3d/file?code=' . rawurlencode($code));
        }

        return $payload;
    }
}

if (!function_exists('vp_lvr_fetch_qr_by_code')) {
    function vp_lvr_fetch_qr_by_code($code) {
        $filter = rawurlencode(json_encode([
            'code' => ['_eq' => $code],
        ], JSON_UNESCAPED_UNICODE));

        $fields = rawurlencode(
            'id,code,title,type,is_active,location_title,' .
            'scene_id.id,scene_id.title,scene_id.kind,scene_id.model_file,scene_id.poster_file,' .
            'scene_id.viewer_config,scene_id.requires_password,scene_id.password_hint,' .
            'scene_id.expires_at,scene_id.is_active'
        );

        $json = vp_lvr_directus_get("/items/qr_codes?limit=1&fields={$fields}&filter={$filter}");
        if (is_wp_error($json)) {
            return $json;
        }

        $rows = $json['data'] ?? [];
        if (!is_array($rows) || count($rows) === 0) {
            return new WP_Error('lookup_empty', 'QR code not found', ['status' => 404]);
        }

        return $rows[0];
    }
}

if (!function_exists('vp_lvr_fetch_anchor_by_qr_id')) {
    function vp_lvr_fetch_anchor_by_qr_id($qrId) {
        $filter = rawurlencode(json_encode([
            '_and' => [
                ['qr_code_id' => ['_eq' => (int) $qrId]],
                ['is_active' => ['_eq' => true]],
            ],
        ], JSON_UNESCAPED_UNICODE));

        $fields = rawurlencode(
            'id,title,code,kind,x,y,heading_deg,meta_json,is_active,' .
            'location_id.id,location_id.title,location_id.slug,location_id.kind,location_id.description,' .
            'location_id.address,location_id.city,location_id.country,location_id.timezone,' .
            'location_id.default_language,location_id.is_active,' .
            'level_id.id,level_id.code,level_id.title,level_id.z_index,level_id.is_active,' .
            'node_id.id,node_id.title,node_id.kind,node_id.x,node_id.y,node_id.z'
        );

        $json = vp_lvr_directus_get("/items/vp_location_anchors?limit=1&fields={$fields}&filter={$filter}");
        if (is_wp_error($json)) {
            return $json;
        }

        $rows = $json['data'] ?? [];
        if (!is_array($rows) || count($rows) === 0) {
            return new WP_Error('anchor_not_found', 'Location anchor not found', ['status' => 404]);
        }

        return $rows[0];
    }
}

if (!function_exists('vp_lvr_fetch_nodes')) {
    function vp_lvr_fetch_nodes($locationId, $levelId = null) {
        $and = [
            ['location_id' => ['_eq' => (int) $locationId]],
            ['is_active' => ['_eq' => true]],
            ['is_public' => ['_eq' => true]],
        ];

        if ($levelId !== null) {
            $and[] = ['level_id' => ['_eq' => (int) $levelId]];
        }

        $filter = rawurlencode(json_encode([
            '_and' => $and,
        ], JSON_UNESCAPED_UNICODE));

        $fields = rawurlencode(
            'id,title,kind,x,y,z,is_active,is_public,accessibility_tags,meta_json'
        );

        $json = vp_lvr_directus_get("/items/vp_location_nodes?limit=1000&fields={$fields}&filter={$filter}&sort=id");
        if (is_wp_error($json)) {
            return $json;
        }

        $rows = $json['data'] ?? [];
        return is_array($rows) ? $rows : [];
    }
}

if (!function_exists('vp_lvr_fetch_edges')) {
    function vp_lvr_fetch_edges($locationId) {
        $filter = rawurlencode(json_encode([
            '_and' => [
                ['location_id' => ['_eq' => (int) $locationId]],
                ['is_active' => ['_eq' => true]],
            ],
        ], JSON_UNESCAPED_UNICODE));

        $fields = rawurlencode(
            'id,kind,distance_m,duration_s,is_bidirectional,is_active,is_accessible,' .
            'level_change,restrictions_json,meta_json,' .
            'from_node_id.id,to_node_id.id'
        );

        $json = vp_lvr_directus_get("/items/vp_location_edges?limit=2000&fields={$fields}&filter={$filter}&sort=id");
        if (is_wp_error($json)) {
            return $json;
        }

        $rows = $json['data'] ?? [];
        return is_array($rows) ? $rows : [];
    }
}

if (!function_exists('vp_lvr_fetch_pois')) {
    function vp_lvr_fetch_pois($locationId, $levelId = null) {
        $and = [
            ['location_id' => ['_eq' => (int) $locationId]],
            ['is_active' => ['_eq' => true]],
            ['is_public' => ['_eq' => true]],
        ];

        if ($levelId !== null) {
            $and[] = ['level_id' => ['_eq' => (int) $levelId]];
        }

        $filter = rawurlencode(json_encode([
            '_and' => $and,
        ], JSON_UNESCAPED_UNICODE));

        $fields = rawurlencode(
            'id,title,slug,kind,brand,description,icon,x,y,url,phone,opening_hours,sort,' .
            'keywords,is_public,node_id.id,scene_id.id,instruction_id'
        );

        $json = vp_lvr_directus_get("/items/vp_location_pois?limit=500&fields={$fields}&filter={$filter}&sort=sort,title");
        if (is_wp_error($json)) {
            return $json;
        }

        $rows = $json['data'] ?? [];
        return is_array($rows) ? $rows : [];
    }
}

if (!function_exists('vp_lvr_normalize_nodes')) {
    function vp_lvr_normalize_nodes($rows) {
        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = vp_lvr_safe_int($row['id'] ?? null);
            if (!$id) {
                continue;
            }

            $out[] = [
                'id' => $id,
                'title' => vp_lvr_safe_text($row['title'] ?? ''),
                'kind' => vp_lvr_safe_text($row['kind'] ?? ''),
                'x' => vp_lvr_safe_int($row['x'] ?? null),
                'y' => vp_lvr_safe_int($row['y'] ?? null),
                'z' => vp_lvr_safe_int($row['z'] ?? null),
                'is_active' => vp_lvr_safe_bool($row['is_active'] ?? true),
                'is_public' => vp_lvr_safe_bool($row['is_public'] ?? true),
                'accessibility_tags' => is_array($row['accessibility_tags'] ?? null) ? $row['accessibility_tags'] : [],
            ];
        }

        return $out;
    }
}

if (!function_exists('vp_lvr_normalize_edges')) {
    function vp_lvr_normalize_edges($rows) {
        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $from = vp_lvr_extract_relation($row['from_node_id'] ?? null);
            $to = vp_lvr_extract_relation($row['to_node_id'] ?? null);

            $fromId = vp_lvr_safe_int($from['id'] ?? null);
            $toId = vp_lvr_safe_int($to['id'] ?? null);

            if (!$fromId || !$toId) {
                continue;
            }

            $out[] = [
                'id' => vp_lvr_safe_int($row['id'] ?? null),
                'from_node_id' => $fromId,
                'to_node_id' => $toId,
                'kind' => vp_lvr_safe_text($row['kind'] ?? ''),
                'distance_m' => vp_lvr_safe_int($row['distance_m'] ?? null),
                'duration_s' => vp_lvr_safe_int($row['duration_s'] ?? null),
                'is_bidirectional' => vp_lvr_safe_bool($row['is_bidirectional'] ?? false),
                'is_active' => vp_lvr_safe_bool($row['is_active'] ?? true),
                'is_accessible' => vp_lvr_safe_bool($row['is_accessible'] ?? true),
                'level_change' => vp_lvr_safe_int($row['level_change'] ?? null),
            ];
        }

        return $out;
    }
}

if (!function_exists('vp_lvr_normalize_pois')) {
    function vp_lvr_normalize_pois($rows) {
        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $node = vp_lvr_extract_relation($row['node_id'] ?? null);
            $scene = vp_lvr_extract_relation($row['scene_id'] ?? null);

            $out[] = [
                'id' => vp_lvr_safe_int($row['id'] ?? null),
                'title' => vp_lvr_safe_text($row['title'] ?? ''),
                'slug' => vp_lvr_safe_text($row['slug'] ?? ''),
                'kind' => vp_lvr_safe_text($row['kind'] ?? ''),
                'brand' => vp_lvr_safe_text($row['brand'] ?? ''),
                'description' => vp_lvr_safe_text($row['description'] ?? ''),
                'icon' => vp_lvr_safe_text($row['icon'] ?? ''),
                'x' => vp_lvr_safe_int($row['x'] ?? null),
                'y' => vp_lvr_safe_int($row['y'] ?? null),
                'url' => vp_lvr_safe_text($row['url'] ?? ''),
                'phone' => vp_lvr_safe_text($row['phone'] ?? ''),
                'opening_hours' => vp_lvr_safe_text($row['opening_hours'] ?? ''),
                'sort' => vp_lvr_safe_int($row['sort'] ?? null),
                'keywords' => is_array($row['keywords'] ?? null) ? $row['keywords'] : [],
                'is_public' => vp_lvr_safe_bool($row['is_public'] ?? true),
                'node_id' => vp_lvr_safe_int($node['id'] ?? null),
                'scene_id' => vp_lvr_safe_int($scene['id'] ?? null),
                'instruction_id' => vp_lvr_safe_int($row['instruction_id'] ?? null),
            ];
        }

        return $out;
    }
}

if (!function_exists('vp_lvr_viewer_bootstrap_callback')) {
    function vp_lvr_viewer_bootstrap_callback(WP_REST_Request $request) {
        $code = strtoupper(trim((string) $request->get_param('code')));

        if ($code === '') {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'code_required',
            ], 400);
        }

        $qr = vp_lvr_fetch_qr_by_code($code);
        if (is_wp_error($qr)) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => $qr->get_error_code(),
            ], (int) ($qr->get_error_data()['status'] ?? 404));
        }

        if (empty($qr['is_active'])) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'qr_inactive',
            ], 403);
        }

        if (strtolower((string) ($qr['type'] ?? '')) !== 'location') {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'location_type_required',
            ], 400);
        }

        $anchor = vp_lvr_fetch_anchor_by_qr_id((int) $qr['id']);
        if (is_wp_error($anchor)) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => $anchor->get_error_code(),
            ], (int) ($anchor->get_error_data()['status'] ?? 404));
        }

        $location = vp_lvr_extract_relation($anchor['location_id'] ?? null);
        $level = vp_lvr_extract_relation($anchor['level_id'] ?? null);
        $node = vp_lvr_extract_relation($anchor['node_id'] ?? null);

        $locationId = vp_lvr_safe_int($location['id'] ?? null);
        $levelId = vp_lvr_safe_int($level['id'] ?? null);
        $anchorNodeId = vp_lvr_safe_int($node['id'] ?? null);

        if (!$locationId || !$levelId || !$anchorNodeId) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'location_not_linked',
            ], 404);
        }

        $nodes = vp_lvr_fetch_nodes($locationId, $levelId);
        if (is_wp_error($nodes)) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => $nodes->get_error_code(),
            ], (int) ($nodes->get_error_data()['status'] ?? 502));
        }

        $edges = vp_lvr_fetch_edges($locationId);
        if (is_wp_error($edges)) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => $edges->get_error_code(),
            ], (int) ($edges->get_error_data()['status'] ?? 502));
        }

        $pois = vp_lvr_fetch_pois($locationId, $levelId);
        if (is_wp_error($pois)) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => $pois->get_error_code(),
            ], (int) ($pois->get_error_data()['status'] ?? 502));
        }

        $scene = is_array($qr['scene_id'] ?? null) ? $qr['scene_id'] : null;
        $scenePayload = null;

        if ($scene && vp_lvr_scene_type_3d($qr['type'] ?? '')) {
            $scenePayload = vp_lvr_scene_payload($scene, $code);
        }

        return new WP_REST_Response([
            'ok' => true,
            'mode' => 'location',
            'qr' => [
                'id' => (int) $qr['id'],
                'code' => (string) ($qr['code'] ?? ''),
                'title' => (string) ($qr['title'] ?? ''),
                'type' => (string) ($qr['type'] ?? ''),
            ],
            'location' => [
                'id' => $locationId,
                'title' => vp_lvr_safe_text($location['title'] ?? ($qr['location_title'] ?? '')),
                'slug' => vp_lvr_safe_text($location['slug'] ?? ''),
                'kind' => vp_lvr_safe_text($location['kind'] ?? 'location'),
                'description' => vp_lvr_safe_text($location['description'] ?? ''),
                'address' => vp_lvr_safe_text($location['address'] ?? ''),
                'city' => vp_lvr_safe_text($location['city'] ?? ''),
                'country' => vp_lvr_safe_text($location['country'] ?? ''),
                'timezone' => vp_lvr_safe_text($location['timezone'] ?? ''),
                'default_language' => vp_lvr_safe_text($location['default_language'] ?? ''),
                'is_active' => vp_lvr_safe_bool($location['is_active'] ?? true),
            ],
            'level' => [
                'id' => $levelId,
                'code' => vp_lvr_safe_text($level['code'] ?? ''),
                'title' => vp_lvr_safe_text($level['title'] ?? ''),
                'z_index' => vp_lvr_safe_int($level['z_index'] ?? null),
                'is_active' => vp_lvr_safe_bool($level['is_active'] ?? true),
            ],
            'anchor' => [
                'id' => vp_lvr_safe_int($anchor['id'] ?? null),
                'title' => vp_lvr_safe_text($anchor['title'] ?? ''),
                'code' => vp_lvr_safe_text($anchor['code'] ?? ''),
                'kind' => vp_lvr_safe_text($anchor['kind'] ?? 'anchor'),
                'x' => vp_lvr_safe_int($anchor['x'] ?? null),
                'y' => vp_lvr_safe_int($anchor['y'] ?? null),
                'heading_deg' => vp_lvr_safe_int($anchor['heading_deg'] ?? null),
                'node_id' => $anchorNodeId,
            ],
            'nodes' => vp_lvr_normalize_nodes($nodes),
            'edges' => vp_lvr_normalize_edges($edges),
            'pois' => vp_lvr_normalize_pois($pois),
            'scene' => $scenePayload,
        ], 200);
    }
}

add_action('rest_api_init', function () {
    register_rest_route('vp/v1', '/location/viewer-bootstrap', [
        'methods' => 'GET',
        'callback' => 'vp_lvr_viewer_bootstrap_callback',
        'permission_callback' => '__return_true',
    ]);
});
