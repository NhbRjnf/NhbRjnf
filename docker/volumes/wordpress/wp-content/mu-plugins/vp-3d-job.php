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

if (!function_exists('vp_3d_job_allowed_extensions')) {
    function vp_3d_job_allowed_extensions() {
        return ['stl', 'obj', 'glb', 'gltf'];
    }
}

if (!function_exists('vp_3d_job_allowed_mimes')) {
    function vp_3d_job_allowed_mimes() {
        return [
            'stl'  => 'model/stl',
            'obj'  => 'text/plain',
            'glb'  => 'model/gltf-binary',
            'gltf' => 'model/gltf+json',
        ];
    }
}

if (!function_exists('vp_3d_job_guess_mime_by_extension')) {
    function vp_3d_job_guess_mime_by_extension($filename) {
        $ext = strtolower((string) pathinfo((string) $filename, PATHINFO_EXTENSION));
        $map = vp_3d_job_allowed_mimes();
        return isset($map[$ext]) ? (string) $map[$ext] : '';
    }
}

if (!function_exists('vp_3d_job_max_upload_bytes')) {
    function vp_3d_job_max_upload_bytes() {
        $envMb = (int) getenv('VP_3D_MAX_UPLOAD_MB');
        $mb = $envMb > 0 ? $envMb : 50;
        return $mb * 1024 * 1024;
    }
}

if (!function_exists('vp_3d_job_rate_limit_window_seconds')) {
    function vp_3d_job_rate_limit_window_seconds() {
        $env = (int) getenv('VP_3D_UPLOAD_RATE_LIMIT_WINDOW');
        return $env > 0 ? $env : 600;
    }
}

if (!function_exists('vp_3d_job_rate_limit_max_requests')) {
    function vp_3d_job_rate_limit_max_requests() {
        $env = (int) getenv('VP_3D_UPLOAD_RATE_LIMIT_MAX');
        return $env > 0 ? $env : 5;
    }
}

if (!function_exists('vp_3d_job_client_ip')) {
    function vp_3d_job_client_ip() {
        $candidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
            $_SERVER['HTTP_X_REAL_IP'] ?? '',
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? '',
        ];

        foreach ($candidates as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            if (strpos($value, ',') !== false) {
                $parts = explode(',', $value);
                $value = trim((string) ($parts[0] ?? ''));
            }

            if (filter_var($value, FILTER_VALIDATE_IP)) {
                return $value;
            }
        }

        return 'unknown';
    }
}

if (!function_exists('vp_3d_job_rate_limit_key')) {
    function vp_3d_job_rate_limit_key() {
        return 'vp_3d_job_upload_' . md5(vp_3d_job_client_ip());
    }
}

if (!function_exists('vp_3d_job_check_and_hit_rate_limit')) {
    function vp_3d_job_check_and_hit_rate_limit() {
        $window = vp_3d_job_rate_limit_window_seconds();
        $max = vp_3d_job_rate_limit_max_requests();
        $key = vp_3d_job_rate_limit_key();

        $state = get_transient($key);
        if (!is_array($state) || !isset($state['count'], $state['start'])) {
            $state = [
                'count' => 0,
                'start' => time(),
            ];
        }

        $elapsed = time() - (int) $state['start'];
        if ($elapsed >= $window) {
            $state = [
                'count' => 0,
                'start' => time(),
            ];
        }

        if ((int) $state['count'] >= $max) {
            $retryAfter = max(1, $window - (time() - (int) $state['start']));
            return new WP_Error('rate_limited', 'Слишком много попыток загрузки. Попробуйте позже.', [
                'status' => 429,
                'retry_after' => $retryAfter,
            ]);
        }

        $state['count'] = (int) $state['count'] + 1;
        set_transient($key, $state, $window);

        return true;
    }
}

if (!function_exists('vp_3d_job_upload_error_to_wp_error')) {
    function vp_3d_job_upload_error_to_wp_error($upload_error_code) {
        $code = (int) $upload_error_code;

        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return new WP_Error('file_too_large', 'Файл слишком большой.', [
                    'status' => 413,
                    'max_bytes' => vp_3d_job_max_upload_bytes(),
                ]);

            case UPLOAD_ERR_PARTIAL:
                return new WP_Error('upload_incomplete', 'Файл загружен не полностью.', ['status' => 400]);

            case UPLOAD_ERR_NO_FILE:
                return new WP_Error('file_required', 'Файл не передан.', ['status' => 400]);

            case UPLOAD_ERR_NO_TMP_DIR:
            case UPLOAD_ERR_CANT_WRITE:
            case UPLOAD_ERR_EXTENSION:
                return new WP_Error('upload_failed', 'Не удалось сохранить загруженный файл.', ['status' => 500]);

            case UPLOAD_ERR_OK:
            default:
                return null;
        }
    }
}

if (!function_exists('vp_3d_job_validate_upload_file')) {
    function vp_3d_job_validate_upload_file($file) {
        if (!is_array($file) || empty($file)) {
            return new WP_Error('file_required', 'Файл не передан.', ['status' => 400]);
        }

        $uploadError = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        if ($uploadError !== UPLOAD_ERR_OK) {
            $mapped = vp_3d_job_upload_error_to_wp_error($uploadError);
            if ($mapped instanceof WP_Error) {
                return $mapped;
            }
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        $name = (string) ($file['name'] ?? '');
        $size = isset($file['size']) ? (int) $file['size'] : 0;

        if ($tmpName === '' || !file_exists($tmpName)) {
            return new WP_Error('file_required', 'Временный файл не найден.', ['status' => 400]);
        }

        if ($size <= 0) {
            return new WP_Error('empty_file', 'Файл пустой.', ['status' => 400]);
        }

        $maxBytes = vp_3d_job_max_upload_bytes();
        if ($size > $maxBytes) {
            return new WP_Error('file_too_large', 'Файл слишком большой.', [
                'status' => 413,
                'max_bytes' => $maxBytes,
            ]);
        }

        $allowedExtensions = vp_3d_job_allowed_extensions();
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $allowedExtensions, true)) {
            return new WP_Error('file_type_not_allowed', 'Разрешены только .stl, .obj, .glb и .gltf.', [
                'status' => 415,
                'allowed_extensions' => $allowedExtensions,
            ]);
        }

        $checked = wp_check_filetype_and_ext($tmpName, $name, vp_3d_job_allowed_mimes());
        $realExt = strtolower((string) ($checked['ext'] ?? ''));
        if ($realExt !== '' && !in_array($realExt, $allowedExtensions, true)) {
            return new WP_Error('file_type_not_allowed', 'Формат файла не поддерживается.', [
                'status' => 415,
                'allowed_extensions' => $allowedExtensions,
            ]);
        }

        return true;
    }
}

if (!function_exists('vp_3d_job_wp_error_response')) {
    function vp_3d_job_wp_error_response(WP_Error $error, $extra = []) {
        $data = $error->get_error_data();
        $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 400;

        $payload = array_merge([
            'ok' => false,
            'error' => $error->get_error_code(),
            'message' => $error->get_error_message(),
        ], is_array($data) ? $data : [], $extra);

        unset($payload['status']);

        return new WP_REST_Response($payload, $status);
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
            $expires_at = gmdate('Y-m-d H:i:s', time() + (int) $ttl_seconds);
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

        $result_glb_wp_id = isset($job['result_glb_wp_id']) ? (int) $job['result_glb_wp_id'] : 0;
        $preview_image_wp_id = isset($job['preview_image_wp_id']) ? (int) $job['preview_image_wp_id'] : 0;

        $viewer_glb_url = $result_glb_wp_id > 0 ? vp_3d_job_make_attachment_link($result_glb_wp_id, 3600) : '';
        $viewer_preview_url = $preview_image_wp_id > 0 ? vp_3d_job_make_attachment_link($preview_image_wp_id, 3600) : '';

        return new WP_REST_Response([
            'ok' => true,
            'job' => [
                'id' => (int) ($job['id'] ?? 0),
                'status' => (string) ($job['status'] ?? ''),
                'progress' => (int) ($job['progress'] ?? 0),
                'source_file_url' => (string) ($job['source_file_url'] ?? ''),
                'source_file_wp_id' => isset($job['source_file_wp_id']) ? (int) $job['source_file_wp_id'] : null,
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

if (!function_exists('vp_3d_job_create_permission')) {
    function vp_3d_job_create_permission() {
        return true;
    }
}

if (!function_exists('vp_3d_job_create')) {
    function vp_3d_job_create(WP_REST_Request $req) {
        $rateLimit = vp_3d_job_check_and_hit_rate_limit();
        if (is_wp_error($rateLimit)) {
            return vp_3d_job_wp_error_response($rateLimit);
        }

        if (empty($_FILES['file'])) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'file_required',
                'message' => 'Файл не передан.',
            ], 400);
        }

        $file = $_FILES['file'];

        $validation = vp_3d_job_validate_upload_file($file);
        if (is_wp_error($validation)) {
            return vp_3d_job_wp_error_response($validation);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $upload = wp_handle_upload($file, [
            'test_form' => false,
            'test_type' => false,
            'mimes' => vp_3d_job_allowed_mimes(),
        ]);

        if (isset($upload['error'])) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'upload_failed',
                'message' => (string) $upload['error'],
            ], 500);
        }

        $file_path = (string) $upload['file'];
        $file_url = (string) $upload['url'];
        $original_name = sanitize_file_name((string) ($file['name'] ?? basename($file_path)));

        $attachment_id = 0;

        $checked = wp_check_filetype_and_ext($file_path, $original_name, vp_3d_job_allowed_mimes());
        $mime = '';
        if (!empty($checked['type'])) {
            $mime = (string) $checked['type'];
        }

        if ($mime === '') {
            $mime = vp_3d_job_guess_mime_by_extension($original_name);
        }

        if ($mime === '') {
            $mime = 'application/octet-stream';
        }

        $attachment = [
            'post_mime_type' => $mime,
            'post_title'     => sanitize_text_field(pathinfo($original_name, PATHINFO_FILENAME)),
            'post_content'   => '',
            'post_status'    => 'inherit',
            'guid'           => $file_url,
        ];

        $inserted = wp_insert_attachment($attachment, $file_path);
        if (!is_wp_error($inserted) && (int) $inserted > 0) {
            $attachment_id = (int) $inserted;

            $meta = wp_generate_attachment_metadata($attachment_id, $file_path);
            if (!is_wp_error($meta) && is_array($meta)) {
                wp_update_attachment_metadata($attachment_id, $meta);
            }

            $maybe_url = wp_get_attachment_url($attachment_id);
            if (!empty($maybe_url)) {
                $file_url = (string) $maybe_url;
            }
        }

        $dx_base = getenv('VP_DIRECTUS_URL');
        $dx_token = getenv('VP_DIRECTUS_TOKEN');

        if (!$dx_base || !$dx_token) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'directus_env_missing',
                'message' => 'Directus env missing',
            ], 500);
        }

        $payload = [
            'source_file_url' => $file_url,
            'status' => 'queued',
            'progress' => 0,
            'meta' => [
                'filename' => $original_name,
            ],
        ];

        if ($attachment_id > 0) {
            $payload['source_file_wp_id'] = $attachment_id;
        }

        $res = wp_remote_post(
            rtrim($dx_base, '/') . '/items/vp_3d_jobs',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $dx_token,
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode($payload),
                'timeout' => 20,
            ]
        );

        if (is_wp_error($res)) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'directus_request_failed',
                'message' => 'Не удалось создать job в Directus.',
            ], 500);
        }

        $status = (int) wp_remote_retrieve_response_code($res);
        $bodyRaw = wp_remote_retrieve_body($res);
        $body = json_decode($bodyRaw, true);

        if ($status < 200 || $status >= 300) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'directus_request_failed',
                'message' => 'Directus отклонил создание job.',
                'status' => $status,
                'body' => $bodyRaw,
            ], 500);
        }

        $job_id = isset($body['data']['id']) ? (int) $body['data']['id'] : 0;
        if ($job_id <= 0) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'job_create_failed',
                'message' => 'Не удалось получить job_id.',
            ], 500);
        }

        if (!class_exists('Redis')) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'queue_unavailable',
                'message' => 'Очередь Redis недоступна.',
                'job_id' => $job_id,
            ], 500);
        }

        try {
            $redis = new Redis();
            $redis->connect('redis', 6379);
            $redis->lPush('vp:3d:jobs', $job_id);
            $redis->close();
        } catch (Throwable $e) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'queue_unavailable',
                'message' => 'Не удалось поставить job в очередь.',
                'job_id' => $job_id,
            ], 500);
        }

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

    register_rest_route('vp/v1', '/3d/job', [
        'methods' => 'POST',
        'callback' => 'vp_3d_job_create',
        'permission_callback' => 'vp_3d_job_create_permission',
    ]);
});