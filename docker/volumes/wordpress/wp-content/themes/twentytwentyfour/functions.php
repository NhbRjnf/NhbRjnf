<?php
/**
 * Theme functions for VP instruction pages.
 */

if (!defined('ABSPATH')) {
  exit;
}

function vp_enqueue_instruction_assets() {
  if (is_page_template('page-instruction.php')) {
    wp_enqueue_style(
      'vp-instruction',
      get_template_directory_uri() . '/instruction.css',
      [],
      '1.0.0'
    );
  }
}

add_action('wp_enqueue_scripts', 'vp_enqueue_instruction_assets');
