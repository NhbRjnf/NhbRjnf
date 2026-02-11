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

  // /scan (PWA-сканер)
  if (is_page('scan')) {
    // меняй при правках, чтобы сбивать кэш
    $ver = '1.4.6';

    wp_enqueue_style(
      'vp-scan',
      get_stylesheet_directory_uri() . '/assets/vp-scan.css',
      [],
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
    $ver3d = '1.0.0';

    wp_enqueue_style(
      'vp-3d',
      get_stylesheet_directory_uri() . '/page-3d.css',
      [],
      $ver3d
    );

    // model-viewer (локальный vendor). ВАЖНО: это ESM и должен грузиться как type="module"
    //wp_enqueue_script(
    //  'vp-model-viewer',
    //  get_stylesheet_directory_uri() . '/assets/vendor/model-viewer.min.js',
    //  [],
    //  '3.5.0',
    //  true
    //);

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

//add_filter('script_loader_tag', function ($tag, $handle, $src) {
//  if ($handle !== 'vp-model-viewer') {
//    return $tag;
//  }
//
//  return sprintf(
//    "<script type=\"module\" src=\"%s\" id=\"%s-js\"></script>\n",
//    esc_url($src),
//    esc_attr($handle)
//  );
//}, 10, 3);

add_action('wp_head', function () {
  if (is_page('scan')) {
    echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
    echo '<link rel="manifest" href="/manifest.json">';
    echo '<meta name="theme-color" content="#0b0f14">';
  }
});

add_action('wp_footer', function () {
  if (is_page('scan')) {
    echo "<script>if('serviceWorker' in navigator){navigator.serviceWorker.register('/service-worker.js');}</script>";
  }
});

add_filter('query_vars', function ($vars) {
  $vars[] = 'code';
  return $vars;
});

add_filter('redirect_canonical', function ($redirectUrl) {
  if (is_admin()) {
    return $redirectUrl;
  }

  $code = isset($_GET['code']) ? trim((string) $_GET['code']) : '';
  if ($code !== '' && is_page('3d')) {
    return false;
  }

  return $redirectUrl;
}, 10, 1);
