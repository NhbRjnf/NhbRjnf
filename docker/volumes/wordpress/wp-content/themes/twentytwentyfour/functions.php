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

/**
 * VP: assets for /scan and /3d pages
 */
add_action('wp_enqueue_scripts', function () {

  if (is_page(array('scan', 'instruction', '3d'))) {
    $uiVer = '1.0.0';

    wp_enqueue_style(
      'vp-ui',
      get_stylesheet_directory_uri() . '/assets/vp-ui.css',
      [],
      $uiVer
    );

    wp_enqueue_script(
      'vp-menu',
      get_stylesheet_directory_uri() . '/assets/vp-menu.js',
      [],
      $uiVer,
      true
    );

    wp_localize_script('vp-menu', 'VP_MENU', [
      'scanUrl' => home_url('/scan/'),
      'instructionUrl' => home_url('/instruction/'),
      'threeDUrl' => home_url('/3d/'),
    ]);
  }

  if (is_page('instruction')) {
    $verInstruction = '1.0.0';
    wp_enqueue_style(
      'vp-instruction',
      get_stylesheet_directory_uri() . '/instruction.css',
      array('vp-ui'),
      $verInstruction
    );
  }

  // /scan (PWA-сканер)
  if (is_page('scan')) {
    // меняй при правках, чтобы сбивать кэш
    $ver = '1.4.6';

    wp_enqueue_style(
      'vp-scan',
      get_stylesheet_directory_uri() . '/assets/vp-scan.css',
      array('vp-ui'),
      $ver
    );

    // ВАЖНО: только локальная библиотека (самохост)
    wp_enqueue_script(
      'html5-qrcode',
      get_stylesheet_directory_uri() . '/assets/vendor/html5-qrcode.min.js',
      [],
      '2.3.8',
      true
    );

    wp_enqueue_script(
      'vp-scan',
      get_stylesheet_directory_uri() . '/assets/vp-scan.js',
      ['html5-qrcode'],
      $ver,
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
    $ver3d = '1.1.0';

    wp_enqueue_style(
      'vp-3d',
      get_stylesheet_directory_uri() . '/page-3d.css',
      array('vp-ui'),
      $ver3d
    );

    wp_enqueue_script(
      'vp-3d',
      get_stylesheet_directory_uri() . '/page-3d.js',
      [],
      $ver3d,
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
/**
 * VP: assets for /dentist cabinet pages
 */
add_action('wp_enqueue_scripts', function () {
  if (!is_page(array('dentist-login', 'dentist', 'dentist-new-case'))) {
    return;
  }

  $uiVer = '1.0.0';
  wp_enqueue_style(
    'vp-ui',
    get_stylesheet_directory_uri() . '/assets/vp-ui.css',
    [],
    $uiVer
  );

  wp_enqueue_script(
    'vp-menu',
    get_stylesheet_directory_uri() . '/assets/vp-menu.js',
    [],
    $uiVer,
    true
  );

  wp_localize_script('vp-menu', 'VP_MENU', [
    'scanUrl' => home_url('/scan/'),
    'instructionUrl' => home_url('/instruction/'),
    'threeDUrl' => home_url('/3d/'),
  ]);

  $verDentist = '1.0.0';
  wp_enqueue_style(
    'vp-dentist',
    get_stylesheet_directory_uri() . '/assets/vp-dentist.css',
    ['vp-ui'],
    $verDentist
  );

  wp_enqueue_script(
    'vp-dentist',
    get_stylesheet_directory_uri() . '/assets/vp-dentist.js',
    ['vp-menu'],
    $verDentist,
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

  $uiVer = '1.0.0';
  wp_enqueue_style(
    'vp-ui',
    get_stylesheet_directory_uri() . '/assets/vp-ui.css',
    [],
    $uiVer
  );

  wp_enqueue_style(
    'vp-dentist',
    get_stylesheet_directory_uri() . '/assets/vp-dentist.css',
    ['vp-ui'],
    '1.0.0'
  );

  wp_enqueue_style(
    'vp-login',
    get_stylesheet_directory_uri() . '/assets/login.css',
    ['vp-dentist'],
    '1.0.0'
  );

  wp_enqueue_script(
    'vp-login',
    get_stylesheet_directory_uri() . '/assets/vp-login.js',
    [],
    '1.0.0',
    true
  );
});
/**
 * VP: assets for /app shell page
 */
if (!defined('VP_APP_ASSET_VERSION')) {
  define('VP_APP_ASSET_VERSION', '1.0.0');
}

add_action('wp_enqueue_scripts', function () {
  if (!is_page('app')) {
    return;
  }

  wp_enqueue_style(
    'vp-app',
    get_stylesheet_directory_uri() . '/assets/vp-app.css',
    [],
    VP_APP_ASSET_VERSION
  );

  wp_enqueue_script(
    'vp-app',
    get_stylesheet_directory_uri() . '/assets/app/vp-app.js',
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