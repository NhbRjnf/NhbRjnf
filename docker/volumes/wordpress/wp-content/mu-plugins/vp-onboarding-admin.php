<?php
/**
 * Plugin Name: VP Onboarding Admin
 * Description: WP admin moderation UI for Directus onboarding requests.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', 'vp_onboarding_admin_register_menu');
add_action('admin_enqueue_scripts', 'vp_onboarding_admin_enqueue_assets');
add_action('wp_ajax_vp_onboarding_fetch_requests', 'vp_onboarding_admin_ajax_fetch_requests');
add_action('wp_ajax_vp_onboarding_decide_request', 'vp_onboarding_admin_ajax_decide_request');

function vp_onboarding_admin_register_menu() {
    add_menu_page(
        'VP Onboarding',
        'VP Onboarding',
        'manage_options',
        'vp-onboarding-admin',
        'vp_onboarding_admin_render_page',
        'dashicons-yes-alt',
        58
    );
}

function vp_onboarding_admin_enqueue_assets($hook) {
    if ($hook !== 'toplevel_page_vp-onboarding-admin') {
        return;
    }

    if (!current_user_can('manage_options')) {
        return;
    }

    $base = content_url('mu-plugins');

    wp_enqueue_style(
        'vp-onboarding-admin',
        $base . '/vp-onboarding-admin.css',
        [],
        '1.0.0'
    );

    wp_enqueue_script(
        'vp-onboarding-admin',
        $base . '/vp-onboarding-admin.js',
        [],
        '1.0.0',
        true
    );

    wp_localize_script('vp-onboarding-admin', 'VPOnboardingAdmin', [
        'ajaxUrl'      => admin_url('admin-ajax.php'),
        'nonce'        => wp_create_nonce('vp_onboarding_admin_nonce'),
        'defaultLimit' => 20,
    ]);
}

function vp_onboarding_admin_render_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'default'));
    }
    ?>
    <div class="wrap vp-onboarding-admin">
        <h1>VP Onboarding</h1>

        <div id="vp-onboarding-notice" class="notice" style="display:none;"></div>

        <div class="vp-onboarding-filters">
            <label>
                Status
                <select id="vp-status-filter" multiple size="4">
                    <option value="pending" selected>pending</option>
                    <option value="needs_review" selected>needs_review</option>
                    <option value="approved">approved</option>
                    <option value="rejected">rejected</option>
                </select>
            </label>

            <label>
                User type
                <input type="text" id="vp-user-type-filter" placeholder="e.g. dentist">
            </label>

            <label>
                Search email/phone
                <input type="text" id="vp-search-filter" placeholder="email or phone">
            </label>

            <button class="button button-primary" id="vp-apply-filters">Apply filters</button>
        </div>

        <table class="widefat striped" id="vp-onboarding-table">
            <thead>
            <tr>
                <th>ID</th>
                <th>Created</th>
                <th>Status</th>
                <th>User type</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Name</th>
                <th>Reason</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td colspan="9">Loading...</td>
            </tr>
            </tbody>
        </table>

        <div class="vp-onboarding-pagination">
            <button class="button" id="vp-prev-page" disabled>Previous</button>
            <span id="vp-page-info">Page 1</span>
            <button class="button" id="vp-next-page">Next</button>
        </div>
    </div>
    <?php
}

function vp_onboarding_admin_ajax_fetch_requests() {
    vp_onboarding_admin_require_permissions();
    check_ajax_referer('vp_onboarding_admin_nonce', 'nonce');

    $statuses = isset($_POST['statuses']) ? (array) wp_unslash($_POST['statuses']) : ['pending', 'needs_review'];
    $statuses = array_values(array_filter(array_map('sanitize_text_field', $statuses)));
    if (empty($statuses)) {
        $statuses = ['pending', 'needs_review'];
    }

    $user_type = isset($_POST['user_type']) ? sanitize_text_field(wp_unslash($_POST['user_type'])) : '';
    $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
    $limit = isset($_POST['limit']) ? max(1, min(100, absint($_POST['limit']))) : 20;
    $offset = isset($_POST['offset']) ? max(0, absint($_POST['offset'])) : 0;

    $filter = [
        'status' => ['_in' => $statuses],
    ];

    if ($user_type !== '') {
        $filter['user_type'] = ['_eq' => $user_type];
    }

    if ($search !== '') {
        $filter['_or'] = [
            ['email' => ['_icontains' => $search]],
            ['phone' => ['_icontains' => $search]],
        ];
    }

    $query = [
        'fields' => 'id,email,phone,first_name,last_name,user_type,status,created_at,reviewed_at,decision_reason,reviewed_by_email,reviewed_by_wp_id,reviewed_by_wp_login',
        'sort' => '-created_at',
        'limit' => $limit,
        'offset' => $offset,
        'meta' => 'filter_count',
        'filter' => wp_json_encode($filter),
    ];

    $response = vp_onboarding_admin_directus_request('GET', '/items/vp_onboarding_requests', null, $query);
    if (is_wp_error($response)) {
        wp_send_json_error([
            'message' => $response->get_error_message(),
            'details' => $response->get_error_data(),
        ], 500);
    }

    wp_send_json_success([
        'rows' => isset($response['data']) && is_array($response['data']) ? $response['data'] : [],
        'filter_count' => isset($response['meta']['filter_count']) ? (int) $response['meta']['filter_count'] : 0,
        'limit' => $limit,
        'offset' => $offset,
    ]);
}

function vp_onboarding_admin_ajax_decide_request() {
    vp_onboarding_admin_require_permissions();
    check_ajax_referer('vp_onboarding_admin_nonce', 'nonce');

    $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
    $decision = isset($_POST['decision']) ? sanitize_text_field(wp_unslash($_POST['decision'])) : '';
    $reason = isset($_POST['reason']) ? sanitize_text_field(wp_unslash($_POST['reason'])) : '';

    if ($id <= 0) {
        wp_send_json_error(['message' => 'Invalid request ID.'], 400);
    }

    if (!in_array($decision, ['approve', 'reject'], true)) {
        wp_send_json_error(['message' => 'Invalid decision.'], 400);
    }

    if ($decision === 'reject' && $reason === '') {
        wp_send_json_error(['message' => 'Reason is required for rejection.'], 400);
    }

    $current_user = wp_get_current_user();
    $payload = [
        'status' => $decision === 'approve' ? 'approved' : 'rejected',
        'reviewed_at' => gmdate('c'),
        'reviewed_by_email' => $current_user->user_email,
        'reviewed_by_wp_id' => (int) $current_user->ID,
        'reviewed_by_wp_login' => $current_user->user_login,
        'decision_reason' => $decision === 'approve' ? null : $reason,
    ];

    $response = vp_onboarding_admin_directus_request('PATCH', '/items/vp_onboarding_requests/' . $id, $payload);
    if (is_wp_error($response)) {
        wp_send_json_error([
            'message' => $response->get_error_message(),
            'details' => $response->get_error_data(),
        ], 500);
    }

    wp_send_json_success([
        'message' => sprintf('Request #%d %s.', $id, $decision === 'approve' ? 'approved' : 'rejected'),
        'item' => $response['data'] ?? null,
    ]);
}

function vp_onboarding_admin_require_permissions() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Forbidden'], 403);
    }
}

function vp_onboarding_admin_directus_base_url() {
    $url = getenv('DIRECTUS_PUBLIC_URL');
    if (!$url) {
        $url = getenv('DIRECTUS_BASE_URL');
    }

    return $url ? rtrim($url, '/') : '';
}

function vp_onboarding_admin_directus_token() {
    $token = getenv('VP_SERVICE_USER_TOKEN');
    return $token ? trim($token) : '';
}

function vp_onboarding_admin_directus_request($method, $path, $body = null, $query = []) {
    $base_url = vp_onboarding_admin_directus_base_url();
    $token = vp_onboarding_admin_directus_token();

    if ($base_url === '' || $token === '') {
        error_log('VP Onboarding Admin: Directus env is missing. DIRECTUS_PUBLIC_URL/DIRECTUS_BASE_URL or VP_SERVICE_USER_TOKEN not set.');
        return new WP_Error('vp_directus_env_missing', 'Directus configuration is missing.');
    }

    $url = $base_url . $path;
    if (!empty($query)) {
        $url = add_query_arg($query, $url);
    }

    $args = [
        'method' => strtoupper($method),
        'timeout' => 25,
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ],
    ];

    if ($body !== null) {
        $args['body'] = wp_json_encode($body);
    }

    $response = wp_remote_request($url, $args);

    if (is_wp_error($response)) {
        error_log('VP Onboarding Admin Directus transport error: ' . $response->get_error_message());
        return new WP_Error('vp_directus_transport_error', 'Directus request failed.', $response->get_error_data());
    }

    $http_code = wp_remote_retrieve_response_code($response);
    $raw_body = wp_remote_retrieve_body($response);
    $decoded = null;

    if (is_string($raw_body) && $raw_body !== '') {
        $decoded = json_decode($raw_body, true);
    }

    if ($http_code < 200 || $http_code >= 300) {
        error_log('VP Onboarding Admin Directus HTTP error [' . $http_code . ']: ' . $raw_body);
        return new WP_Error(
            'vp_directus_http_error',
            'Directus returned an error while processing the request.',
            [
                'http_code' => $http_code,
                'body' => $decoded ?: $raw_body,
                'url' => $url,
            ]
        );
    }

    return is_array($decoded) ? $decoded : [];
}