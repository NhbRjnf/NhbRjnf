<?php
/**
 * Twenty Twenty-Four functions and definitions
 *
 * @link https://developer.wordpress.org/themes/basics/theme-functions/
 *
 * @package Twenty Twenty-Four
 * @since Twenty Twenty-Four 1.0
 */

/**
 * Register block styles.
 */
if ( ! function_exists( 'twentytwentyfour_block_styles' ) ) :
	/**
	 * Register custom block styles
	 *
	 * @since Twenty Twenty-Four 1.0
	 * @return void
	 */
	function twentytwentyfour_block_styles() {
		register_block_style(
			'core/details',
			array(
				'name'         => 'arrow-icon-details',
				'label'        => __( 'Arrow icon', 'twentytwentyfour' ),
				'inline_style' => '
				.is-style-arrow-icon-details {
					padding-top: var(--wp--preset--spacing--10);
					padding-bottom: var(--wp--preset--spacing--10);
				}

				.is-style-arrow-icon-details summary {
					list-style-type: "\2193\00a0\00a0\00a0";
				}

				.is-style-arrow-icon-details[open] > summary {
					list-style-type: "\2191\00a0\00a0\00a0";
				}',
			)
		);
		register_block_style(
			'core/post-terms',
			array(
				'name'         => 'pill',
				'label'        => __( 'Pill', 'twentytwentyfour' ),
				'inline_style' => '
				.is-style-pill a {
					background-color: var(--wp--preset--color--base-2);
					border-radius: 999px;
					padding: var(--wp--preset--spacing--20) var(--wp--preset--spacing--30);
				}

				.is-style-pill a:hover {
					background-color: var(--wp--preset--color--contrast-3);
				}',
			)
		);
		register_block_style(
			'core/list',
			array(
				'name'         => 'checkmark-list',
				'label'        => __( 'Checkmark', 'twentytwentyfour' ),
				'inline_style' => '
				ul.is-style-checkmark-list {
					list-style-type: "\2713";
				}

				ul.is-style-checkmark-list li {
					padding-inline-start: 1ch;
				}',
			)
		);
		register_block_style(
			'core/navigation-link',
			array(
				'name'         => 'arrow-link',
				'label'        => __( 'With arrow', 'twentytwentyfour' ),
				'inline_style' => '
				.is-style-arrow-link .wp-block-navigation-item__label:after {
					content: "\2197";
					padding-inline-start: 0.25rem;
					vertical-align: middle;
					text-decoration: none;
					display: inline-block;
				}',
			)
		);
		register_block_style(
			'core/heading',
			array(
				'name'         => 'text-balance',
				'label'        => __( 'Text balance', 'twentytwentyfour' ),
				'inline_style' => '
				.is-style-text-balance {
					text-wrap: balance;
				}',
			)
		);
	}
endif;

add_action( 'init', 'twentytwentyfour_block_styles' );

/**
 * Register pattern categories.
 */
if ( ! function_exists( 'twentytwentyfour_pattern_categories' ) ) :
	/**
	 * Register pattern categories
	 *
	 * @since Twenty Twenty-Four 1.0
	 * @return void
	 */
	function twentytwentyfour_pattern_categories() {

		register_block_pattern_category(
			'twentytwentyfour_page',
			array(
				'label'       => __( 'Pages', 'twentytwentyfour' ),
				'description' => __( 'A collection of full page layouts.', 'twentytwentyfour' ),
			)
		);
	}
endif;

add_action( 'init', 'twentytwentyfour_pattern_categories' );

if (!function_exists('vp_theme_asset_version')) {
  function vp_theme_asset_version($relative_path, $fallback = '1.0.0') {
    $relative_path = ltrim((string) $relative_path, '/');
    if ($relative_path === '') {
      return (string) $fallback;
    }

    $absolute_path = get_stylesheet_directory() . '/' . $relative_path;
    if (!file_exists($absolute_path)) {
      return (string) $fallback;
    }

    $mtime = (int) filemtime($absolute_path);
    if ($mtime <= 0) {
      return (string) $fallback;
    }

    return (string) $mtime;
  }
}

if (!function_exists('vp_theme_asset_url')) {
  function vp_theme_asset_url($relative_path) {
    $relative_path = ltrim((string) $relative_path, '/');
    return get_stylesheet_directory_uri() . '/' . $relative_path;
  }
}

if (!function_exists('vp_service_worker_version')) {
  function vp_service_worker_version() {
    $file = ABSPATH . 'service-worker.js';
    if (!file_exists($file)) {
      return '1.0.0';
    }

    $mtime = (int) filemtime($file);
    return $mtime > 0 ? (string) $mtime : '1.0.0';
  }
}

/**
 * VP: assets for /scan and /3d pages
 */
add_action('wp_enqueue_scripts', function () {

  if (is_page(array('scan', 'instruction', '3d'))) {
    wp_enqueue_style(
      'vp-ui',
      vp_theme_asset_url('assets/vp-ui.css'),
      [],
      vp_theme_asset_version('assets/vp-ui.css')
    );

    wp_enqueue_script(
      'vp-menu',
      vp_theme_asset_url('assets/vp-menu.js'),
      [],
      vp_theme_asset_version('assets/vp-menu.js'),
      true
    );

    wp_localize_script('vp-menu', 'VP_MENU', [
      'scanUrl' => home_url('/scan/'),
      'instructionUrl' => home_url('/instruction/'),
      'threeDUrl' => home_url('/3d/'),
    ]);
  }

  if (is_page('instruction')) {
    wp_enqueue_style(
      'vp-instruction',
      vp_theme_asset_url('instruction.css'),
      array('vp-ui'),
      vp_theme_asset_version('instruction.css')
    );
  }

  // /scan (PWA-сканер)
  if (is_page('scan')) {
    wp_enqueue_style(
      'vp-scan',
      vp_theme_asset_url('assets/vp-scan.css'),
      array('vp-ui'),
      vp_theme_asset_version('assets/vp-scan.css')
    );

    // ВАЖНО: только локальная библиотека (самохост)
    wp_enqueue_script(
      'html5-qrcode',
      vp_theme_asset_url('assets/vendor/html5-qrcode.min.js'),
      [],
      vp_theme_asset_version('assets/vendor/html5-qrcode.min.js'),
      true
    );

    wp_enqueue_script(
      'vp-scan',
      vp_theme_asset_url('assets/vp-scan.js'),
      ['html5-qrcode'],
      vp_theme_asset_version('assets/vp-scan.js'),
      true
    );

    // Опционально: если фронт использует локализованные URL (не ломает старый код)
    wp_localize_script('vp-scan', 'VP_SCAN', [
      'lookupUrl'       => home_url('/wp-json/vp/v1/lookup'),
      'suggestUrl'      => home_url('/wp-json/vp/v1/suggest'),
      'instructionUrl'  => home_url('/instruction/'),
      'threeDUrl'       => home_url('/3d/'),
      'scanUrl'         => home_url('/scan/'),
    ]);
  }

  // /3d (просмотр 3D сцен)
  if (is_page('3d')) {
    wp_enqueue_style(
      'vp-3d',
      vp_theme_asset_url('page-3d.css'),
      array('vp-ui'),
      vp_theme_asset_version('page-3d.css')
    );

    wp_enqueue_script(
      'vp-3d',
      vp_theme_asset_url('page-3d.js'),
      [],
      vp_theme_asset_version('page-3d.js'),
      true
    );

    wp_localize_script('vp-3d', 'VP_3D', [
      'lookupUrl' => home_url('/wp-json/vp/v1/lookup'),
      'authUrl'   => home_url('/wp-json/vp/v1/3d/auth'),
      'fileUrl'   => home_url('/wp-json/vp/v1/3d/file'),
      'posterUrl' => home_url('/wp-json/vp/v1/3d/poster'),
      'scanUrl'   => home_url('/scan/'),
      'threeDUrl' => home_url('/3d/'),
    ]);
  }
});

add_action('wp_head', function () {
  if (is_page('scan')) {
    echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
    echo '<link rel="manifest" href="/manifest.json">';
    echo '<meta name="theme-color" content="#0b0f14">';
    echo '<meta name="apple-mobile-web-app-capable" content="yes">';
    echo '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">';
  }
});

add_action('wp_footer', function () {
  if (!is_page('scan')) {
    return;
  }

  $sw_version = vp_service_worker_version();
  ?>
  <script>
    (function () {
      if (!('serviceWorker' in navigator)) {
        return;
      }

      var swUrl = <?php echo wp_json_encode(home_url('/service-worker.js?v=' . rawurlencode($sw_version))); ?>;
      navigator.serviceWorker.register(swUrl, { scope: '/' }).catch(function () {});
    })();
  </script>
  <?php
}, 50);
/**
 * VP: assets for /dentist cabinet pages
 */
add_action('wp_enqueue_scripts', function () {
  if (!is_page(array('dentist-login', 'dentist', 'dentist-new-case'))) {
    return;
  }

  wp_enqueue_style(
    'vp-ui',
    vp_theme_asset_url('assets/vp-ui.css'),
    [],
    vp_theme_asset_version('assets/vp-ui.css')
  );

  wp_enqueue_script(
    'vp-menu',
    vp_theme_asset_url('assets/vp-menu.js'),
    [],
    vp_theme_asset_version('assets/vp-menu.js'),
    true
  );

  wp_localize_script('vp-menu', 'VP_MENU', [
    'scanUrl' => home_url('/scan/'),
    'instructionUrl' => home_url('/instruction/'),
    'threeDUrl' => home_url('/3d/'),
  ]);

  wp_enqueue_style(
    'vp-dentist',
    vp_theme_asset_url('assets/vp-dentist.css'),
    ['vp-ui'],
    vp_theme_asset_version('assets/vp-dentist.css')
  );

  wp_enqueue_script(
    'vp-dentist',
    vp_theme_asset_url('assets/vp-dentist.js'),
    ['vp-menu'],
    vp_theme_asset_version('assets/vp-dentist.js'),
    true
  );
});

/**
 * VP: assets for universal /login page
 */
add_action('wp_enqueue_scripts', function () {
  if (!is_page('login')) {
    return;
  }

  wp_enqueue_style(
    'vp-ui',
    vp_theme_asset_url('assets/vp-ui.css'),
    [],
    vp_theme_asset_version('assets/vp-ui.css')
  );

  wp_enqueue_style(
    'vp-dentist',
    vp_theme_asset_url('assets/vp-dentist.css'),
    ['vp-ui'],
    vp_theme_asset_version('assets/vp-dentist.css')
  );

  wp_enqueue_style(
    'vp-login',
    vp_theme_asset_url('assets/login.css'),
    ['vp-dentist'],
    vp_theme_asset_version('assets/login.css')
  );

  wp_enqueue_script(
    'vp-login',
    vp_theme_asset_url('assets/vp-login.js'),
    [],
    vp_theme_asset_version('assets/vp-login.js'),
    true
  );
});
/**
 * VP: assets for /app shell page
 */
if (!function_exists('vp_app_asset_version')) {
  function vp_app_asset_version() {
    $files = [
      get_stylesheet_directory() . '/assets/app/vp-app.js',
      get_stylesheet_directory() . '/assets/vp-app.css',
      get_stylesheet_directory() . '/assets/app/modules/base.js',
    ];

    $maxMtime = 0;
    foreach ($files as $file) {
      if (file_exists($file)) {
        $mtime = (int) filemtime($file);
        if ($mtime > $maxMtime) {
          $maxMtime = $mtime;
        }
      }
    }

    return $maxMtime > 0 ? (string) $maxMtime : '1.0.0';
  }
}

if (!defined('VP_APP_ASSET_VERSION')) {
  define('VP_APP_ASSET_VERSION', vp_app_asset_version());
}

add_action('wp_enqueue_scripts', function () {
  if (!is_page('app')) {
    return;
  }

  wp_enqueue_style(
    'vp-app',
    vp_theme_asset_url('assets/vp-app.css'),
    [],
    VP_APP_ASSET_VERSION
  );

  wp_enqueue_script(
    'vp-app',
    vp_theme_asset_url('assets/app/vp-app.js'),
    [],
    VP_APP_ASSET_VERSION,
    true
  );

  wp_add_inline_script(
    'vp-app',
    'window.VP_APP_CONFIG=' . wp_json_encode([
      'assetVersion' => VP_APP_ASSET_VERSION,
      'apiBase' => home_url('/wp-json/vp/v1/app'),
    ]) . ';',
    'before'
  );
});