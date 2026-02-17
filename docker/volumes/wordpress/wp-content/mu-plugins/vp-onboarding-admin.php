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
        '1.1.0'
    );

    wp_enqueue_script(
        'vp-onboarding-admin',
        $base . '/vp-onboarding-admin.js',
        [],
        '1.1.0',
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
                <th>Reviewed</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td colspan="10">Loading...</td>
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
        'fields' => 'id,email,phone,first_name,last_name,user_type,status,created_at,reviewed_at,decision_reason,reviewed_by_email,reviewed_by_wp_id,reviewed_by_wp_login,created_user_id,created_profile_id',
        'sort' => '-created_at',
        'limit' => $limit,
        'offset' => $offset,
        'meta' => 'filter_count',
        'filter' => wp_json_encode($filter),
    ];

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

    if ($id <= 0) {
        wp_send_json_error(['message' => 'Invalid request ID.'], 400);
    }

    if (!in_array($decision, ['approve', 'reject'], true)) {
        wp_send_json_error(['message' => 'Invalid decision.'], 400);
    }

    if ($decision === 'reject' && $reason === '') {
        wp_send_json_error(['message' => 'Reason is required for rejection.'], 400);
    }

    $request = vp_dx_get_onboarding_request($id);
    if (is_wp_error($request)) {
        wp_send_json_error([
            'message' => $request->get_error_message(),
            'details' => $request->get_error_data(),
        ], 500);
    }

    $current_user = wp_get_current_user();

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

    wp_send_json_success($result);
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
        'fields' => 'id,email,phone,first_name,last_name,user_type,requested_tenant_slug,status,created_user_id,created_profile_id',
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

    if (!empty($existing['data'][0]['id'])) {
        return [
            'id' => $existing['data'][0]['id'],
            'created' => false,
        ];
    }

    $role_id = vp_dx_get_role_id_for_user_type($request['user_type'] ?? '');
    if (is_wp_error($role_id)) {
        return $role_id;
    }

    $payload = [
        'email' => $email,
        'password' => wp_generate_password(20, true, true),
        'status' => 'active',
        'role' => $role_id,
        'first_name' => sanitize_text_field($request['first_name'] ?? ''),
        'last_name' => sanitize_text_field($request['last_name'] ?? ''),
    ];

    $created = vp_dx_request('POST', '/users', [], $payload);
    if (is_wp_error($created)) {
        return $created;
    }

    if (empty($created['data']['id'])) {
        return new WP_Error('vp_user_create_invalid', 'Directus user create response is missing ID.', $created);
    }

    return [
        'id' => $created['data']['id'],
        'created' => true,
    ];
}

function vp_dx_get_or_create_profile($request, $directus_user_id) {
    if (!empty($request['created_profile_id'])) {
        return [
            'id' => $request['created_profile_id'],
            'created' => false,
        ];
    }

    $existing = vp_dx_request('GET', '/items/vp_user_profiles', [
        'filter[user_id][_eq]' => $directus_user_id,
        'limit' => 1,
        'fields' => 'id,user_id,user_type,phone',
    ]);

    if (is_wp_error($existing)) {
        return $existing;
    }

    if (!empty($existing['data'][0]['id'])) {
        return [
            'id' => $existing['data'][0]['id'],
            'created' => false,
        ];
    }

    $payload = [
        'user_id' => $directus_user_id,
        'user_type' => sanitize_text_field($request['user_type'] ?? ''),
        'phone' => sanitize_text_field($request['phone'] ?? ''),
        'status' => 'active',
    ];

    if (!empty($request['requested_tenant_slug'])) {
        $payload['notes'] = 'Requested tenant: ' . sanitize_text_field($request['requested_tenant_slug']);
    }

    $created = vp_dx_request('POST', '/items/vp_user_profiles', [], $payload);
    if (is_wp_error($created)) {
        return $created;
    }

    if (empty($created['data']['id'])) {
        return new WP_Error('vp_profile_create_invalid', 'Directus profile create response is missing ID.', $created);
    }

    return [
        'id' => $created['data']['id'],
        'created' => true,
    ];
}

function vp_dx_get_role_id_for_user_type($user_type) {
    $user_type = sanitize_key($user_type);

    $name_map = [
        'dentist' => 'vp_dentist_doctor',
        'doctor' => 'vp_dentist_doctor',
        'clinic_admin' => 'vp_clinic_admin',
        'car_owner' => 'vp_car_owner',
        'auto_business' => 'vp_auto_business',
        'location_admin' => 'vp_location_admin',
        'content_partner' => 'vp_content_partner',
    ];

    $role_name = $name_map[$user_type] ?? null;
    if (!$role_name) {
        return new WP_Error('vp_role_map_missing', sprintf('No role mapping for user_type "%s".', $user_type));
    }

    $cache_key = 'vp_dx_roles_map_v1';
    $roles_map = get_transient($cache_key);

    if (!is_array($roles_map) || empty($roles_map)) {
        $roles_response = vp_dx_request('GET', '/roles', [
            'fields' => 'id,name',
            'limit' => 200,
        ]);

        if (is_wp_error($roles_response)) {
            return $roles_response;
        }

        $roles_map = [];
        foreach (($roles_response['data'] ?? []) as $role) {
            if (!empty($role['name']) && !empty($role['id'])) {
                $roles_map[$role['name']] = $role['id'];
            }
        }

        set_transient($cache_key, $roles_map, 10 * MINUTE_IN_SECONDS);
    }

    if (empty($roles_map[$role_name])) {
        return new WP_Error('vp_role_not_found', sprintf('Directus role "%s" not found.', $role_name));
    }

    return $roles_map[$role_name];
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
        error_log('VP Onboarding Admin Directus HTTP error [' . $http_code . '] ' . $method . ' ' . $path . ': ' . $raw_body);
        return new WP_Error(
            'vp_directus_http_error',
            'Directus returned an error while processing the request.',
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