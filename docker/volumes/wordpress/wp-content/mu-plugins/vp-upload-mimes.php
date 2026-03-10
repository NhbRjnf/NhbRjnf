<?php
/**
 * Plugin Name: VP Upload Mimes
 */

if (!defined('ABSPATH')) {
    exit;
}

add_filter('upload_mimes', function ($mimes) {
    $mimes['step'] = 'application/step';
    $mimes['stp']  = 'application/step';
    $mimes['iges'] = 'model/iges';
    $mimes['igs']  = 'model/iges';
    $mimes['stl']  = 'model/stl';
    $mimes['obj']  = 'text/plain';
    $mimes['glb']  = 'model/gltf-binary';
    $mimes['gltf'] = 'model/gltf+json';
    return $mimes;
});

add_filter('wp_check_filetype_and_ext', function ($data, $file, $filename, $mimes) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    $map = [
        'step' => ['ext' => 'step', 'type' => 'application/step'],
        'stp'  => ['ext' => 'stp',  'type' => 'application/step'],
        'iges' => ['ext' => 'iges', 'type' => 'model/iges'],
        'igs'  => ['ext' => 'igs',  'type' => 'model/iges'],
        'stl'  => ['ext' => 'stl',  'type' => 'model/stl'],
        'obj'  => ['ext' => 'obj',  'type' => 'text/plain'],
        'glb'  => ['ext' => 'glb',  'type' => 'model/gltf-binary'],
        'gltf' => ['ext' => 'gltf', 'type' => 'model/gltf+json'],
    ];

    if (isset($map[$ext])) {
        return [
            'ext' => $map[$ext]['ext'],
            'type' => $map[$ext]['type'],
            'proper_filename' => $filename,
        ];
    }

    return $data;
}, 10, 4);