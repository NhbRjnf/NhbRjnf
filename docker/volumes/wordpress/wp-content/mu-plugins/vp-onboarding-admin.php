<?php
/**
 * Plugin Name: VP Onboarding Admin
 * Description: Admin screen for onboarding requests stored in Directus (vp_onboarding_requests).
 * Author: VP
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * -----------------------------
 * Config / Helpers
 * -----------------------------
 */

function vp_onboarding_admin_get_env($key, $default = '') {
    $v = getenv($key);
    if ($v === false || $v === null || $v === '') return $default;
    return $v;
}

function vp_onboarding_admin_get_directus_base_url() {
    $url = vp_onboarding_admin_get_env('VP_DIRECTUS_URL', '');
    $url = rtrim((string)$url, "/");
    return $url;
}

function vp_onboarding_admin_get_directus_token() {
    return (string) vp_onboarding_admin_get_env('VP_DIRECTUS_TOKEN', '');
}

function vp_onboarding_admin_get_directus_collection() {
    return (string) vp_onboarding_admin_get_env('VP_ONBOARDING_COLLECTION', 'vp_onboarding_requests');
}

function vp_onboarding_admin_get_role_map() {
    // Map user_type => Directus role ID
    // You can override via ENV vars.
    return [
        'dentist' => (string) vp_onboarding_admin_get_env('VP_DX_ROLE_DENTIST', ''),
        'auto'    => (string) vp_onboarding_admin_get_env('VP_DX_ROLE_AUTO_SPECIALIST', ''),
        'partner' => (string) vp_onboarding_admin_get_env('VP_DX_ROLE_PARTNER_MANAGER', ''),
        'location'=> (string) vp_onboarding_admin_get_env('VP_DX_ROLE_LOCATION_MANAGER', ''),
        'moderator' => (string) vp_onboarding_admin_get_env('VP_DX_ROLE_VP_MODERATOR', ''),
        'admin'     => (string) vp_onboarding_admin_get_env('VP_DX_ROLE_VP_ADMIN', ''),
    ];
}

function vp_onboarding_admin_user_can_access() {
    return current_user_can('manage_options');
}

/**
 * Basic Directus request helper.
 */
function vp_dx_request($method, $path, $token, $body = null, $timeout = 15) {
    $base = vp_onboarding_admin_get_directus_base_url();
    if ($base === '') {
        return new WP_Error('vp_missing_directus_url', 'Directus URL is not configured (VP_DIRECTUS_URL).');
    }

    $token = (string)$token;
    if ($token === '') {
        return new WP_Error('vp_missing_directus_token', 'Directus token is not configured (VP_DIRECTUS_TOKEN).');
    }

    $url = $base . $path;

    $args = [
        'method'  => strtoupper($method),
        'timeout' => (int)$timeout,
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
        ],
    ];

    if ($body !== null) {
        $args['headers']['Content-Type'] = 'application/json';
        $args['body'] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $res = wp_remote_request($url, $args);
    if (is_wp_error($res)) return $res;

    $code = (int) wp_remote_retrieve_response_code($res);
    $raw  = (string) wp_remote_retrieve_body($res);

    $json = null;
    if ($raw !== '') {
        $json = json_decode($raw, true);
    }

    if ($code < 200 || $code >= 300) {
        $msg = 'Directus HTTP ' . $code;
        if (is_array($json) && isset($json['errors'][0]['message'])) {
            $msg .= ': ' . $json['errors'][0]['message'];
        }
        return new WP_Error('vp_directus_http_error', $msg, [
            'http_code' => $code,
            'raw' => $raw,
            'json' => $json,
            'url' => $url,
            'path' => $path,
            'method' => strtoupper($method),
        ]);
    }

    return $json;
}

function vp_dx_email_is_directus_compatible($email) {
    $email = trim((string)$email);
    if ($email === '') return false;

    // Basic email validation
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;

    // Directus validates emails strictly; ensure the domain looks like a real FQDN (has at least one dot).
    $at = strrpos($email, '@');
    if ($at === false) return false;
    $domain = substr($email, $at + 1);
    if ($domain === '' || strpos($domain, '.') === false) return false;

    return true;
}

/**
 * -----------------------------
 * Admin Page
 * -----------------------------
 */

add_action('admin_menu', function () {
    add_menu_page(
        'VP Onboarding',
        'VP Onboarding',
        'manage_options',
        'vp-onboarding',
        'vp_onboarding_admin_render_page',
        'dashicons-id-alt',
        58
    );
});

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'toplevel_page_vp-onboarding') return;

    $ver = '1.0.1';

    wp_enqueue_style(
        'vp-onboarding-admin',
        plugins_url('vp-onboarding-admin.css', __FILE__),
        [],
        $ver
    );

    wp_enqueue_script(
        'vp-onboarding-admin',
        plugins_url('vp-onboarding-admin.js', __FILE__),
        [],
        $ver,
        true
    );

    wp_localize_script('vp-onboarding-admin', 'VPOnboardingAdmin', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('vp_onboarding_admin_nonce'),
        'actions' => [
            'list'   => 'vp_onboarding_admin_list',
            'get'    => 'vp_onboarding_admin_get',
            'decide' => 'vp_onboarding_admin_decide',
        ],
        'i18n' => [
            'loading' => 'Загрузка…',
            'error' => 'Ошибка',
            'noData' => 'Нет данных',
            'approve' => 'Approve',
            'reject' => 'Reject',
            'details' => 'Details',
            'close' => 'Закрыть',
            'reasonRequired' => 'Причина обязательна для Reject.',
            'confirmApprove' => 'Подтвердить Approve?',
            'confirmReject' => 'Подтвердить Reject?',
        ],
    ]);
});

function vp_onboarding_admin_render_page() {
    if (!vp_onboarding_admin_user_can_access()) {
        echo '<div class="wrap"><h1>VP Onboarding</h1><p>Access denied.</p></div>';
        return;
    }

    ?>
    <div class="wrap vp-onboarding-admin">
        <h1>VP Onboarding</h1>

        <div class="vp-notices" id="vp-notices"></div>

        <div class="vp-toolbar">
            <div class="vp-toolbar__row">
                <label>
                    Status
                    <select id="vp-filter-status">
                        <option value="pending" selected>pending</option>
                        <option value="approved">approved</option>
                        <option value="rejected">rejected</option>
                        <option value="">all</option>
                    </select>
                </label>

                <label>
                    User type
                    <select id="vp-filter-user-type">
                        <option value="">all</option>
                        <option value="dentist">dentist</option>
                        <option value="auto">auto</option>
                        <option value="partner">partner</option>
                        <option value="location">location</option>
                        <option value="moderator">moderator</option>
                        <option value="admin">admin</option>
                    </select>
                </label>

                <label class="vp-search">
                    Search
                    <input type="search" id="vp-filter-search" placeholder="email / name / phone / id" />
                </label>

                <label>
                    Per page
                    <select id="vp-filter-limit">
                        <option value="10">10</option>
                        <option value="25" selected>25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </label>

                <button class="button" id="vp-filter-reset">Reset</button>
            </div>
        </div>

        <div class="vp-table-wrap">
            <table class="widefat striped vp-table" id="vp-table">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Status</th>
                    <th>User type</th>
                    <th>Full name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Created</th>
                    <th>Reviewed by</th>
                    <th>Reviewed at</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody id="vp-table-body">
                <tr><td colspan="10">Загрузка…</td></tr>
                </tbody>
            </table>
        </div>

        <div class="vp-pagination" id="vp-pagination"></div>

        <div id="vp-modal-root"></div>
    </div>
    <?php
}

/**
 * -----------------------------
 * AJAX: List / Get / Decide
 * -----------------------------
 */

add_action('wp_ajax_vp_onboarding_admin_list', 'vp_onboarding_admin_ajax_list');
add_action('wp_ajax_vp_onboarding_admin_get', 'vp_onboarding_admin_ajax_get');
add_action('wp_ajax_vp_onboarding_admin_decide', 'vp_onboarding_admin_ajax_decide');

function vp_onboarding_admin_ajax_guard() {
    if (!vp_onboarding_admin_user_can_access()) {
        wp_send_json_error(['message' => 'Access denied.']);
    }

    check_ajax_referer('vp_onboarding_admin_nonce', 'nonce');
}

function vp_onboarding_admin_ajax_list() {
    vp_onboarding_admin_ajax_guard();

    $status = isset($_POST['status']) ? sanitize_text_field((string)$_POST['status']) : 'pending';
    $user_type = isset($_POST['user_type']) ? sanitize_text_field((string)$_POST['user_type']) : '';
    $search = isset($_POST['search']) ? sanitize_text_field((string)$_POST['search']) : '';
    $offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;
    $limit  = isset($_POST['limit']) ? (int)$_POST['limit'] : 25;

    $collection = vp_onboarding_admin_get_directus_collection();
    $token = vp_onboarding_admin_get_directus_token();

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
            ['id' => ['_icontains' => $search]],
        ];
    }

    $fields = [
        'id',
        'status',
        'user_type',
        'email',
        'phone',
        'first_name',
        'last_name',
        'created_at',
        'reviewed_by',
        'reviewed_at',
        'decision_reason',
        'auto_approve_method',
        'invite_code',
    ];

    $query = [
        'fields' => implode(',', $fields),
        'sort' => '-created_at',
        'limit' => $limit,
        'offset' => $offset,
        'meta' => 'filter_count,total_count',
        'filter' => $filter,
    ];

    $path = '/items/' . rawurlencode($collection) . '?' . http_build_query($query);

    $res = vp_dx_request('GET', $path, $token);
    if (is_wp_error($res)) {
        error_log('[VP Onboarding] list failed: ' . $res->get_error_message());
        wp_send_json_error([
            'message' => $res->get_error_message(),
            'details' => $res->get_error_data(),
        ]);
    }

    $data = isset($res['data']) ? $res['data'] : [];
    $meta = isset($res['meta']) ? $res['meta'] : [];

    wp_send_json_success([
        'items' => $data,
        'meta' => $meta,
    ]);
}

function vp_onboarding_admin_ajax_get() {
    vp_onboarding_admin_ajax_guard();

    $id = isset($_POST['id']) ? sanitize_text_field((string)$_POST['id']) : '';
    if ($id === '') {
        wp_send_json_error(['message' => 'Missing request id.']);
    }

    $collection = vp_onboarding_admin_get_directus_collection();
    $token = vp_onboarding_admin_get_directus_token();

    $fields = [
        'id',
        'status',
        'user_type',
        'email',
        'phone',
        'first_name',
        'last_name',
        'created_at',
        'reviewed_by',
        'reviewed_at',
        'decision_reason',
        'auto_approve_method',
        'invite_code',
    ];

    $path = '/items/' . rawurlencode($collection) . '/' . rawurlencode($id) . '?fields=' . rawurlencode(implode(',', $fields));

    $res = vp_dx_request('GET', $path, $token);
    if (is_wp_error($res)) {
        error_log('[VP Onboarding] get failed: ' . $res->get_error_message());
        wp_send_json_error([
            'message' => $res->get_error_message(),
            'details' => $res->get_error_data(),
        ]);
    }

    wp_send_json_success([
        'item' => isset($res['data']) ? $res['data'] : null,
    ]);
}

/**
 * Decide: approve/reject.
 * On approve:
 *  - Create (or find) Directus user with mapped role for request user_type
 *  - Update request status + reviewed_by/reviewed_at + decision_reason
 */
function vp_onboarding_admin_ajax_decide() {
    vp_onboarding_admin_ajax_guard();

    $id = isset($_POST['id']) ? sanitize_text_field((string)$_POST['id']) : '';
    $decision = isset($_POST['decision']) ? sanitize_text_field((string)$_POST['decision']) : '';
    $reason = isset($_POST['reason']) ? sanitize_text_field((string)$_POST['reason']) : '';

    if ($id === '' || ($decision !== 'approve' && $decision !== 'reject')) {
        wp_send_json_error(['message' => 'Invalid request.']);
    }

    if ($decision === 'reject' && $reason === '') {
        wp_send_json_error(['message' => 'Reason required for reject.']);
    }

    $collection = vp_onboarding_admin_get_directus_collection();
    $token = vp_onboarding_admin_get_directus_token();

    // Load request
    $path_get = '/items/' . rawurlencode($collection) . '/' . rawurlencode($id);
    $get = vp_dx_request('GET', $path_get, $token);
    if (is_wp_error($get)) {
        error_log('[VP Onboarding] decide get failed: ' . $get->get_error_message());
        wp_send_json_error([
            'message' => $get->get_error_message(),
            'details' => $get->get_error_data(),
        ]);
    }

    $request = isset($get['data']) ? $get['data'] : null;
    if (!is_array($request)) {
        wp_send_json_error(['message' => 'Request not found.']);
    }

    $now = gmdate('c');
    $reviewed_by = (string) wp_get_current_user()->user_login;

    $update_payload = [
        'status' => ($decision === 'approve') ? 'approved' : 'rejected',
        'reviewed_by' => $reviewed_by,
        'reviewed_at' => $now,
        'decision_reason' => ($decision === 'reject') ? $reason : '',
    ];

    $created_user = null;

    if ($decision === 'approve') {
        $role_map = vp_onboarding_admin_get_role_map();
        $user_type = isset($request['user_type']) ? (string)$request['user_type'] : '';
        $role_id = isset($role_map[$user_type]) ? (string)$role_map[$user_type] : '';

        if ($role_id === '') {
            wp_send_json_error(['message' => 'Directus role is not configured for user_type: ' . $user_type]);
        }

        $user_res = vp_dx_get_or_create_user($request);
        if (is_wp_error($user_res)) {
            error_log('[VP Onboarding] create user failed: ' . $user_res->get_error_message());
            wp_send_json_error([
                'message' => $user_res->get_error_message(),
                'details' => $user_res->get_error_data(),
            ]);
        }

        $created_user = $user_res;

        // Ensure correct role
        if (!empty($created_user['id'])) {
            $user_id = (string)$created_user['id'];
            $patch = vp_dx_request('PATCH', '/users/' . rawurlencode($user_id), $token, [
                'role' => $role_id,
                'status' => 'active',
            ]);

            if (is_wp_error($patch)) {
                error_log('[VP Onboarding] patch user role failed: ' . $patch->get_error_message());
                wp_send_json_error([
                    'message' => $patch->get_error_message(),
                    'details' => $patch->get_error_data(),
                ]);
            }
        }
    }

    // Update request
    $path_patch = '/items/' . rawurlencode($collection) . '/' . rawurlencode($id);
    $patch = vp_dx_request('PATCH', $path_patch, $token, $update_payload);
    if (is_wp_error($patch)) {
        error_log('[VP Onboarding] decide patch failed: ' . $patch->get_error_message());
        wp_send_json_error([
            'message' => $patch->get_error_message(),
            'details' => $patch->get_error_data(),
        ]);
    }

    wp_send_json_success([
        'result' => [
            'request_id' => $id,
            'decision' => $decision,
            'status' => $update_payload['status'],
            'reviewed_by' => $reviewed_by,
            'reviewed_at' => $now,
            'decision_reason' => $update_payload['decision_reason'],
            'user' => $created_user,
        ],
    ]);
}

/**
 * Find existing user by email, or create a new one.
 */
function vp_dx_get_or_create_user($request) {
    $token = vp_onboarding_admin_get_directus_token();

    $email_raw = isset($request['email']) ? (string)$request['email'] : '';
    $email = sanitize_email($email_raw);

    if ($email === '') {
        return new WP_Error('vp_missing_email', 'Onboarding request has no valid email.');
    }

    if (!vp_dx_email_is_directus_compatible($email)) {
        return new WP_Error('vp_invalid_email', 'Email is not compatible with Directus validation. Use a real domain (e.g. name@domain.tld).', [
            'email_raw' => $email_raw,
            'email_sanitized' => $email,
        ]);
    }

    $first = isset($request['first_name']) ? sanitize_text_field((string)$request['first_name']) : '';
    $last  = isset($request['last_name']) ? sanitize_text_field((string)$request['last_name']) : '';

    // Search user by email
    $path = '/users?filter[email][_eq]=' . rawurlencode($email) . '&fields=id,email,first_name,last_name,role,status';
    $found = vp_dx_request('GET', $path, $token);

    if (is_wp_error($found)) return $found;

    if (!empty($found['data']) && is_array($found['data'])) {
        // Directus may return array of users
        $u = $found['data'][0];
        return is_array($u) ? $u : $found['data'][0];
    }

    // Create user with a random password (user will login via Directus/password reset flow later)
    $password = wp_generate_password(24, true, true);

    $create = vp_dx_request('POST', '/users', $token, [
        'email' => $email,
        'password' => $password,
        'first_name' => $first,
        'last_name' => $last,
        'status' => 'active',
    ]);

    if (is_wp_error($create)) return $create;

    // Directus returns { data: {...} }
    if (isset($create['data']) && is_array($create['data'])) {
        return $create['data'];
    }

    return $create;
}
