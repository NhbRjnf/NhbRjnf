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
    $dir = WP_CONTENT_DIR . '/mu-plugins';
    $css_path = $dir . '/vp-onboarding-admin.css';
    $js_path = $dir . '/vp-onboarding-admin.js';
    $css_ver = file_exists($css_path) ? (string) filemtime($css_path) : '1.2.0';
    $js_ver = file_exists($js_path) ? (string) filemtime($js_path) : '1.2.0';

    wp_enqueue_style(
        'vp-onboarding-admin',
        $base . '/vp-onboarding-admin.css',
        [],
        $css_ver
    );

    wp_enqueue_script(
        'vp-onboarding-admin',
        $base . '/vp-onboarding-admin.js',
        [],
        $js_ver,
        true
    );

    wp_localize_script('vp-onboarding-admin', 'VPOnboardingAdmin', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('vp_onboarding_admin_nonce'),
        'defaultLimit' => 20,
        'assetVersion' => $js_ver,
        'isDryRun' => vp_dx_dry_run_enabled(),
    ]);
}

function vp_onboarding_admin_render_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'default'));
    }
    ?>
    <div class="wrap vp-onboarding-admin">
        <h1 class="wp-heading-inline">VP Onboarding</h1>
        <p class="description">Модерация onboarding-заявок из Directus.</p>

        <div id="vp-onboarding-notice" aria-live="polite" aria-atomic="true"></div>

        <section class="vp-onboarding-toolbar" aria-label="Фильтры заявок">
            <label class="vp-filter-control" for="vp-status-filter">
                <span class="vp-filter-label">Status</span>
                <select id="vp-status-filter">
                    <option value="">Все</option>
                    <option value="pending" selected>pending</option>
                    <option value="needs_review">needs_review</option>
                    <option value="approved">approved</option>
                    <option value="rejected">rejected</option>
                </select>
            </label>

            <label class="vp-filter-control" for="vp-user-type-filter">
                <span class="vp-filter-label">User type</span>
                <input type="text" id="vp-user-type-filter" placeholder="например, dentist" autocomplete="off">
            </label>

            <label class="vp-filter-control vp-filter-control--search" for="vp-search-filter">
                <span class="vp-filter-label">Search</span>
                <input type="search" id="vp-search-filter" placeholder="email, phone, name" autocomplete="off">
            </label>

            <label class="vp-filter-control" for="vp-limit-select">
                <span class="vp-filter-label">Rows per page</span>
                <select id="vp-limit-select">
                    <option value="20" selected>20</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </label>

            <div class="vp-filter-actions">
                <button class="button" id="vp-reset-filters" type="button">Сбросить</button>
            </div>
        </section>

        <div class="vp-table-shell">
            <div class="vp-table-loading" id="vp-table-loading" hidden>
                <span class="spinner is-active"></span>
                <span>Загрузка заявок…</span>
            </div>

            <table class="widefat striped" id="vp-onboarding-table">
                <thead>
                <tr>
                    <th>Created</th>
                    <th>Status</th>
                    <th>User type</th>
                    <th>Full name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Auto-approve</th>
                    <th>Reviewed</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <tr>
                    <td colspan="9">Загрузка…</td>
                </tr>
                </tbody>
            </table>
        </div>

        <div class="vp-onboarding-pagination">
            <button class="button" id="vp-prev-page" disabled type="button">Prev</button>
            <span id="vp-page-info">Показано 0–0 из 0</span>
            <button class="button" id="vp-next-page" disabled type="button">Next</button>
        </div>

        <div id="vp-modal-root" class="vp-modal-root" hidden></div>
    </div>
    <?php
}

function vp_onboarding_admin_ajax_fetch_requests() {
    vp_onboarding_admin_require_permissions();
    check_ajax_referer('vp_onboarding_admin_nonce', 'nonce');

    $status = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : '';
    $user_type = isset($_POST['user_type']) ? sanitize_text_field(wp_unslash($_POST['user_type'])) : '';
    $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
    $limit = isset($_POST['limit']) ? max(1, min(100, absint($_POST['limit']))) : 20;
    $offset = isset($_POST['offset']) ? max(0, absint($_POST['offset'])) : 0;

    $filter = [];

    if ($status !== '') {
        $filter['status'] = ['_eq' => $status];
    }

    if ($user_type !== '') {
        $filter['user_type'] = ['_eq' => $user_type];
    }

    if ($search !== '') {
        $filter['_or'] = [
            ['email' => ['_icontains' => $search]],
            ['phone' => ['_icontains' => $search]],
            ['first_name' => ['_icontains' => $search]],
            ['last_name' => ['_icontains' => $search]],
        ];
    }

    $query = [
        'fields' => 'id,email,phone,first_name,last_name,user_type,status,auto_approve_method,created_at,reviewed_at,decision_reason,reviewed_by_email,reviewed_by_wp_id,reviewed_by_wp_login,created_user_id,created_profile_id',
        'sort' => '-created_at',
        'limit' => $limit,
        'offset' => $offset,
        'meta' => 'filter_count',
    ];

    if (!empty($filter)) {
        $query['filter'] = wp_json_encode($filter);
    }

    $response = vp_dx_request('GET', '/items/vp_onboarding_requests', $query);
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

    if (!$id) {
        wp_send_json_error(['message' => 'Invalid id'], 400);
    }

    if (!in_array($decision, ['approve', 'reject'], true)) {
        wp_send_json_error(['message' => 'Invalid decision'], 400);
    }

    $request = vp_dx_get_onboarding_request($id);
    if (is_wp_error($request)) {
        wp_send_json_error(['message' => $request->get_error_message(), 'details' => $request->get_error_data()], 500);
    }

    if (!isset($request['status'])) {
        wp_send_json_error(['message' => 'Directus response missing status'], 500);
    }

    if (!in_array($request['status'], ['pending', 'needs_review'], true)) {
        wp_send_json_error(['message' => 'Request already decided'], 409);
    }

    $current_user = wp_get_current_user();
    if (!$current_user || !$current_user->exists()) {
        wp_send_json_error(['message' => 'No current user'], 403);
    }

    if ($decision === 'approve') {
        $result = vp_dx_approve_request($request, $current_user);
    } else {
        $result = vp_dx_reject_request($request, $current_user, $reason);
    }

    if (is_wp_error($result)) {
        wp_send_json_error([
            'message' => $result->get_error_message(),
            'details' => $result->get_error_data(),
        ], 500);
    }

    wp_send_json_success([
        'result' => $result,
    ]);
}

function vp_onboarding_admin_require_permissions() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Forbidden'], 403);
    }
}

function vp_dx_approve_request($request, $current_user) {
    $user_result = vp_dx_get_or_create_user($request);
    if (is_wp_error($user_result)) {
        return $user_result;
    }

    $profile_result = vp_dx_get_or_create_profile($request, $user_result['id']);
    if (is_wp_error($profile_result)) {
        return $profile_result;
    }

    $payload = vp_dx_build_audit_payload('approved', $current_user, null);
    $payload['created_user_id'] = $user_result['id'];
    if (!empty($profile_result['id'])) {
        $payload['created_profile_id'] = $profile_result['id'];
    }

    $patched = vp_dx_request('PATCH', '/items/vp_onboarding_requests/' . (int) $request['id'], [], $payload);
    if (is_wp_error($patched)) {
        return $patched;
    }

    $message = sprintf(
        'Request #%d approved. User %s: %s',
        (int) $request['id'],
        $user_result['created'] ? 'created' : 'reused',
        $user_result['id']
    );

    if (!empty($profile_result['id'])) {
        $message .= sprintf(' | Profile %s: %s', $profile_result['created'] ? 'created' : 'reused', $profile_result['id']);
    }

    return [
        'message' => $message,
        'item' => $patched['data'] ?? null,
        'user' => $user_result,
        'profile' => $profile_result,
        'dry_run' => !empty($patched['meta']['dry_run']),
    ];
}

function vp_dx_reject_request($request, $current_user, $reason) {
    $payload = vp_dx_build_audit_payload('rejected', $current_user, $reason);

    $patched = vp_dx_request('PATCH', '/items/vp_onboarding_requests/' . (int) $request['id'], [], $payload);
    if (is_wp_error($patched)) {
        return $patched;
    }

    return [
        'message' => sprintf('Request #%d rejected.', (int) $request['id']),
        'item' => $patched['data'] ?? null,
        'dry_run' => !empty($patched['meta']['dry_run']),
    ];
}

function vp_dx_build_audit_payload($status, $current_user, $reason) {
    return [
        'status' => $status,
        'reviewed_at' => gmdate('c'),
        'reviewed_by_email' => $current_user->user_email,
        'reviewed_by_wp_id' => (int) $current_user->ID,
        'reviewed_by_wp_login' => $current_user->user_login,
        'decision_reason' => $reason,
    ];
}

function vp_dx_get_onboarding_request($id) {
    $response = vp_dx_request('GET', '/items/vp_onboarding_requests/' . absint($id), [
        'fields' => 'id,email,phone,first_name,last_name,user_type,auto_approve_method,requested_tenant_slug,status,created_user_id,created_profile_id',
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    if (empty($response['data']) || !is_array($response['data'])) {
        return new WP_Error('vp_request_not_found', 'Onboarding request not found.');
    }

    return $response['data'];
}

function vp_dx_get_or_create_user($request) {
    $email = isset($request['email']) ? sanitize_email($request['email']) : '';
    if ($email === '') {
        return new WP_Error('vp_missing_email', 'Onboarding request has no valid email.');
    }

    $existing = vp_dx_request('GET', '/users', [
        'filter[email][_eq]' => $email,
        'limit' => 1,
        'fields' => 'id,email,status,role',
    ]);

    if (is_wp_error($existing)) {
        return $existing;
    }

    if (!empty($existing['data']) && is_array($existing['data']) && !empty($existing['data'][0]['id'])) {
        $user_id = (string) $existing['data'][0]['id'];
        return [
            'id' => $user_id,
            'created' => false,
        ];
    }

    $role = vp_dx_default_directus_role_id();
    $password = wp_generate_password(24, true, true);
    $payload = [
        'email' => $email,
        'password' => $password,
        'role' => $role,
        'status' => 'active',
    ];

    $created = vp_dx_request('POST', '/users', [], $payload);
    if (is_wp_error($created)) {
        return $created;
    }

    if (empty($created['data']['id'])) {
        return new WP_Error('vp_user_create_failed', 'Directus user create did not return id.');
    }

    return [
        'id' => (string) $created['data']['id'],
        'created' => true,
        'password' => $password,
    ];
}

function vp_dx_get_or_create_profile($request, $user_id) {
    $user_id = (string) $user_id;
    if ($user_id === '') {
        return new WP_Error('vp_missing_user_id', 'Cannot create profile without user id.');
    }

    $existing = vp_dx_request('GET', '/items/vp_profiles', [
        'filter[user_id][_eq]' => $user_id,
        'limit' => 1,
        'fields' => 'id,user_id',
    ]);

    if (is_wp_error($existing)) {
        return $existing;
    }

    if (!empty($existing['data']) && is_array($existing['data']) && !empty($existing['data'][0]['id'])) {
        return [
            'id' => (string) $existing['data'][0]['id'],
            'created' => false,
        ];
    }

    $first_name = isset($request['first_name']) ? sanitize_text_field($request['first_name']) : '';
    $last_name = isset($request['last_name']) ? sanitize_text_field($request['last_name']) : '';
    $phone = isset($request['phone']) ? sanitize_text_field($request['phone']) : '';
    $user_type = isset($request['user_type']) ? sanitize_text_field($request['user_type']) : '';

    $payload = [
        'user_id' => $user_id,
        'first_name' => $first_name,
        'last_name' => $last_name,
        'phone' => $phone,
        'user_type' => $user_type,
    ];

    $created = vp_dx_request('POST', '/items/vp_profiles', [], $payload);
    if (is_wp_error($created)) {
        return $created;
    }

    if (empty($created['data']['id'])) {
        return new WP_Error('vp_profile_create_failed', 'Directus profile create did not return id.');
    }

    return [
        'id' => (string) $created['data']['id'],
        'created' => true,
    ];
}

function vp_dx_default_directus_role_id() {
    $role_id = getenv('VP_DIRECTUS_DEFAULT_ROLE_ID');
    return $role_id ? trim($role_id) : '';
}

function vp_onboarding_admin_directus_base_url() {
    $base = getenv('DIRECTUS_BASE_URL');
    if ($base) {
        return rtrim(trim($base), '/');
    }
    $base = getenv('DIRECTUS_PUBLIC_URL');
    if ($base) {
        return rtrim(trim($base), '/');
    }
    return '';
}

function vp_onboarding_admin_directus_token() {
    $token = getenv('VP_SERVICE_USER_TOKEN');
    return $token ? trim($token) : '';
}

function vp_dx_dry_run_enabled() {
    return (string) getenv('VP_ONBOARDING_DRY_RUN') === '1';
}

function vp_dx_request($method, $path, $query = [], $body = null) {
    $base_url = vp_onboarding_admin_directus_base_url();
    $token = vp_onboarding_admin_directus_token();

    if ($base_url === '' || $token === '') {
        error_log('VP Onboarding Admin: Directus env is missing. DIRECTUS_PUBLIC_URL/DIRECTUS_BASE_URL or VP_SERVICE_USER_TOKEN not set.');
        return new WP_Error('vp_directus_env_missing', 'Directus configuration is missing.');
    }

    $method = strtoupper($method);
    $url = $base_url . $path;
    if (!empty($query)) {
        $url = add_query_arg($query, $url);
    }

    if (vp_dx_dry_run_enabled() && in_array($method, ['POST', 'PATCH', 'PUT', 'DELETE'], true)) {
        error_log('VP Onboarding Admin dry-run: skipping ' . $method . ' ' . $path);
        return [
            'data' => [
                'id' => 'dry-run',
            ],
            'meta' => [
                'dry_run' => true,
                'method' => $method,
                'path' => $path,
                'payload' => $body,
            ],
        ];
    }

    $args = [
        'method' => $method,
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
        error_log('VP Onboarding Admin Directus transport error: endpoint=' . $path . '; error=' . $response->get_error_message());
        return new WP_Error('vp_directus_transport_error', 'Directus request failed.', $response->get_error_data());
    }

    $http_code = wp_remote_retrieve_response_code($response);
    $raw_body = wp_remote_retrieve_body($response);
    $decoded = null;

    if (is_string($raw_body) && $raw_body !== '') {
        $decoded = json_decode($raw_body, true);
    }

    if ($http_code < 200 || $http_code >= 300) {
        $dx_message = vp_dx_extract_error_message($decoded, $raw_body);
        error_log('VP Onboarding Admin Directus error: endpoint=' . $method . ' ' . $path . '; http_code=' . $http_code . '; message=' . $dx_message);
        return new WP_Error(
            'vp_directus_http_error',
            'Directus returned an error while processing the request: ' . $dx_message,
            [
                'http_code' => $http_code,
                'body' => $decoded ?: $raw_body,
                'path' => $path,
            ]
        );
    }

    return is_array($decoded) ? $decoded : [];
}

function vp_onboarding_admin_directus_request($method, $path, $body = null, $query = []) {
    return vp_dx_request($method, $path, $query, $body);
}

function vp_dx_extract_error_message($decoded, $raw_body) {
    if (is_array($decoded) && !empty($decoded['errors'][0]['message'])) {
        return sanitize_text_field((string) $decoded['errors'][0]['message']);
    }

    if (is_array($decoded) && !empty($decoded['error']['message'])) {
        return sanitize_text_field((string) $decoded['error']['message']);
    }

    if (is_string($raw_body) && $raw_body !== '') {
        return sanitize_text_field(wp_strip_all_tags($raw_body));
    }

    return 'Unknown Directus error';
}