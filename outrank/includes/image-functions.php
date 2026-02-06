<?php
// inc/image-functions.php

function outrank_upload_image_from_url($image_url, $post_id = 0) {
    if (empty($image_url)) return false;

    $filename = basename(wp_parse_url($image_url, PHP_URL_PATH));
    if (empty($filename)) {
        $filename = 'image-' . time() . '.jpg';
    }

    // Try file_get_contents first
    $image_data = @file_get_contents($image_url);

    // Fallback to wp_remote_get if file_get_contents fails
    if (!$image_data) {
        $response = wp_remote_get($image_url, [
            'timeout' => 30,
            'sslverify' => false,
        ]);

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $image_data = wp_remote_retrieve_body($response);
        }
    }

    if (!$image_data) return false;

    $upload_dir = wp_upload_dir();
    $filename = wp_unique_filename($upload_dir['path'], $filename);
    $filepath = $upload_dir['path'] . '/' . $filename;

    if (!file_put_contents($filepath, $image_data)) return false;

    $filetype = wp_check_filetype($filename, null);
    $mime_type = $filetype['type'] ?: 'image/jpeg';

    $attachment = [
        'post_mime_type' => $mime_type,
        'post_title'     => sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME)),
        'post_content'   => '',
        'post_status'    => 'inherit',
    ];

    $attach_id = wp_insert_attachment($attachment, $filepath, $post_id);
    if (is_wp_error($attach_id) || !$attach_id) return false;

    require_once ABSPATH . 'wp-admin/includes/image.php';
    $attach_data = wp_generate_attachment_metadata($attach_id, $filepath);
    wp_update_attachment_metadata($attach_id, $attach_data);

    return $attach_id;
}
