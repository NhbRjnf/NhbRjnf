<?php

if (!defined('ABSPATH')) {
    exit;
}

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