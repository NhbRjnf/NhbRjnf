<?php
/* Template Name: VP App Shell */

if (!defined('VP_APP_ASSET_VERSION')) {
  define('VP_APP_ASSET_VERSION', '1.0.0');
}

if (empty($_COOKIE['vp_dx_at'])) {
  wp_safe_redirect(home_url('/login/'));
  exit;
}

get_header();
?>
<main class="vp-app-page">
  <div id="vpAppRoot" class="vp-app-shell" data-asset-version="<?php echo esc_attr(VP_APP_ASSET_VERSION); ?>"></div>
</main>
<?php
get_footer();
