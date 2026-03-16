<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('vp_3d_jobs_directus_base_url')) {
    function vp_3d_jobs_directus_base_url() {
        if (function_exists('vp_directus_base_url')) {
            $url = vp_directus_base_url();
            if (!empty($url)) {
                return rtrim((string)$url, '/');
            }
        }

        $url = getenv('VP_DIRECTUS_URL');
        if (!$url) {
            $url = getenv('DIRECTUS_URL');
        }
        if (!$url) {
            $url = getenv('DIRECTUS_BASE_URL');
        }
        if (!$url) {
            $url = getenv('DIRECTUS_PUBLIC_URL');
        }

        return $url ? rtrim((string)$url, '/') : '';
    }
}

if (!function_exists('vp_3d_jobs_directus_token')) {
    function vp_3d_jobs_directus_token() {
        if (function_exists('vp_directus_token')) {
            $token = vp_directus_token();
            if (!empty($token)) {
                return (string)$token;
            }
        }

        $token = getenv('VP_DIRECTUS_TOKEN');
        if (!$token) {
            $token = getenv('DIRECTUS_TOKEN');
        }
        if (!$token) {
            $token = getenv('DIRECTUS_API_TOKEN');
        }

        return $token ? (string)$token : '';
    }
}

if (!function_exists('vp_3d_jobs_directus_get')) {
    function vp_3d_jobs_directus_get($pathWithQuery) {
        $base = vp_3d_jobs_directus_base_url();
        $token = vp_3d_jobs_directus_token();

        if ($base === '' || $token === '') {
            return new WP_Error('directus_env_missing', 'Directus env missing', ['status' => 500]);
        }

        $res = wp_remote_get(
            $base . $pathWithQuery,
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ],
                'timeout' => 20,
            ]
        );

        if (is_wp_error($res)) {
            return $res;
        }

        $status = (int) wp_remote_retrieve_response_code($res);
        $body = wp_remote_retrieve_body($res);
        $json = json_decode($body, true);

        if ($status < 200 || $status >= 300) {
            return new WP_Error('directus_http', 'Directus request failed', [
                'status' => $status,
                'body' => $body,
            ]);
        }

        return is_array($json) ? $json : [];
    }
}

if (!function_exists('vp_3d_job_make_attachment_link')) {
    function vp_3d_job_make_attachment_link($attachment_id, $ttl_seconds = 3600) {
        global $wpdb;

        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0) {
            return '';
        }

        if (
            !function_exists('vp_files_links_table') ||
            !function_exists('vp_files_random_token')
        ) {
            return '';
        }

        $path = get_attached_file($attachment_id);
        if (!$path || !file_exists($path)) {
            return '';
        }

        $token = vp_files_random_token();

        if (function_exists('vp_files_normalize_expires_at')) {
            $expires_at = vp_files_normalize_expires_at($ttl_seconds, null);
        } else {
            $expires_at = gmdate('Y-m-d H:i:s', time() + (int)$ttl_seconds);
        }

        $meta = [
            'source' => 'vp_3d_job_status',
            'service_ok' => true,
        ];

        $inserted = $wpdb->insert(
            vp_files_links_table(),
            [
                'token' => $token,
                'attachment_id' => $attachment_id,
                'mode' => 'D',
                'tenant_id' => null,
                'require_auth' => 0,
                'password_hash' => null,
                'max_uses' => 0,
                'used_count' => 0,
                'expires_at' => $expires_at,
                'revoked_at' => null,
                'created_at' => current_time('mysql', true),
                'created_by' => get_current_user_id() ?: null,
                'meta' => wp_json_encode($meta, JSON_UNESCAPED_UNICODE),
            ],
            [
                '%s', '%d', '%s', '%s', '%d', '%s',
                '%d', '%d', '%s', '%s', '%s', '%d', '%s',
            ]
        );

        if (!$inserted) {
            return '';
        }

        return home_url('/dl/' . $token);
    }
}

if (!function_exists('vp_3d_job_status_permission')) {
    function vp_3d_job_status_permission() {
        return true;
    }
}

if (!function_exists('vp_3d_job_status')) {
    function vp_3d_job_status(WP_REST_Request $req) {
        $job_id = (int) $req->get_param('job_id');
        if ($job_id <= 0) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'job_id_required',
            ], 400);
        }

        $fields = rawurlencode(
            'id,status,progress,source_file_url,source_file_wp_id,' .
            'result_glb_url,preview_image_url,result_glb_wp_id,preview_image_wp_id'
        );

        $json = vp_3d_jobs_directus_get("/items/vp_3d_jobs/{$job_id}?fields={$fields}");
        if (is_wp_error($json)) {
            $d = $json->get_error_data();
            return new WP_REST_Response([
                'ok' => false,
                'error' => $json->get_error_code(),
                'status' => $d['status'] ?? 500,
                'body' => $d['body'] ?? null,
            ], $d['status'] ?? 500);
        }

        $job = $json['data'] ?? null;
        if (!$job || !is_array($job)) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'job_not_found',
            ], 404);
        }

        $result_glb_wp_id = isset($job['result_glb_wp_id']) ? (int)$job['result_glb_wp_id'] : 0;
        $preview_image_wp_id = isset($job['preview_image_wp_id']) ? (int)$job['preview_image_wp_id'] : 0;

        $viewer_glb_url = $result_glb_wp_id > 0 ? vp_3d_job_make_attachment_link($result_glb_wp_id, 3600) : '';
        $viewer_preview_url = $preview_image_wp_id > 0 ? vp_3d_job_make_attachment_link($preview_image_wp_id, 3600) : '';

        return new WP_REST_Response([
            'ok' => true,
            'job' => [
                'id' => (int) ($job['id'] ?? 0),
                'status' => (string) ($job['status'] ?? ''),
                'progress' => (int) ($job['progress'] ?? 0),
                'source_file_url' => (string) ($job['source_file_url'] ?? ''),
                'source_file_wp_id' => isset($job['source_file_wp_id']) ? (int)$job['source_file_wp_id'] : null,
                'result_glb_url' => (string) ($job['result_glb_url'] ?? ''),
                'preview_image_url' => (string) ($job['preview_image_url'] ?? ''),
                'result_glb_wp_id' => $result_glb_wp_id > 0 ? $result_glb_wp_id : null,
                'preview_image_wp_id' => $preview_image_wp_id > 0 ? $preview_image_wp_id : null,
                'viewer_glb_url' => $viewer_glb_url !== '' ? $viewer_glb_url : null,
                'viewer_preview_url' => $viewer_preview_url !== '' ? $viewer_preview_url : null,
            ],
        ], 200);
    }
}

if (!function_exists('vp_3d_job_create')) {
    function vp_3d_job_create(WP_REST_Request $req) {

        if (empty($_FILES['file'])) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'file_required'
            ], 400);
        }

        $file = $_FILES['file'];

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $upload = wp_handle_upload($file, [
            'test_form' => false
        ]);

        if (isset($upload['error'])) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => $upload['error']
            ], 500);
        }

        $file_path = $upload['file'];
        $file_url = $upload['url'];
        $filename = basename($file_path);

        $attachment_id = 0;

        $mime = '';
        $checked = wp_check_filetype($file_path);
        if (!empty($checked['type'])) {
            $mime = (string)$checked['type'];
        } elseif (!empty($file['type'])) {
            $mime = (string)$file['type'];
        } else {
            $mime = 'application/octet-stream';
        }

        $attachment = [
            'post_mime_type' => $mime,
            'post_title'     => sanitize_text_field(pathinfo((string)$file['name'], PATHINFO_FILENAME)),
            'post_content'   => '',
            'post_status'    => 'inherit',
            'guid'           => $file_url,
        ];

        $inserted = wp_insert_attachment($attachment, $file_path);
        if (!is_wp_error($inserted) && (int)$inserted > 0) {
            $attachment_id = (int)$inserted;

            $meta = wp_generate_attachment_metadata($attachment_id, $file_path);
            if (!is_wp_error($meta) && is_array($meta)) {
                wp_update_attachment_metadata($attachment_id, $meta);
            }

            $maybe_url = wp_get_attachment_url($attachment_id);
            if (!empty($maybe_url)) {
                $file_url = (string)$maybe_url;
            }
        }

        $dx_base = getenv('VP_DIRECTUS_URL');
        $dx_token = getenv('VP_DIRECTUS_TOKEN');

        if (!$dx_base || !$dx_token) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'directus_env_missing'
            ], 500);
        }

        $payload = [
            'source_file_url' => $file_url,
            'status' => 'queued',
            'progress' => 0,
            'meta' => [
                'filename' => basename((string)$file['name'])
            ]
        ];

        if ($attachment_id > 0) {
            $payload['source_file_wp_id'] = $attachment_id;
        }

        $res = wp_remote_post(
            rtrim($dx_base, '/') . '/items/vp_3d_jobs',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $dx_token,
                    'Content-Type' => 'application/json'
                ],
                'body' => wp_json_encode($payload),
                'timeout' => 20
            ]
        );

        if (is_wp_error($res)) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'directus_request_failed'
            ], 500);
        }

        $body = json_decode(wp_remote_retrieve_body($res), true);

        $job_id = $body['data']['id'] ?? null;

        if (!$job_id) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'job_create_failed'
            ], 500);
        }

        $redis = new Redis();
        $redis->connect('redis', 6379);
        $redis->lPush('vp:3d:jobs', $job_id);

        return new WP_REST_Response([
            'ok' => true,
            'job_id' => $job_id,
            'source_file_wp_id' => $attachment_id > 0 ? $attachment_id : null,
            'source_file_url' => $file_url,
        ], 200);
    }
}

add_action('rest_api_init', function () {
    register_rest_route('vp/v1', '/3d/job-status', [
        'methods' => 'GET',
        'callback' => 'vp_3d_job_status',
        'permission_callback' => 'vp_3d_job_status_permission',
    ]);
});