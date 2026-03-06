<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

function outrank_upload_image_from_url($image_url, $post_id = 0) {
    if (empty($image_url)) return false;

    // Check if this exact URL was already downloaded to WP media
    $existing = get_posts([
        'post_type'   => 'attachment',
        'meta_key'    => '_outrank_source_url', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
        'meta_value'  => $image_url, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        'numberposts' => 1,
        'fields'      => 'ids',
    ]);
    if (!empty($existing)) {
        return $existing[0];
    }

    // Extract filename from URL path (ignore query strings)
    $path = wp_parse_url($image_url, PHP_URL_PATH);
    $filename = $path ? basename($path) : '';

    // Ensure filename has a valid image extension
    $valid_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (empty($filename) || !in_array($ext, $valid_extensions, true)) {
        $filename = 'outrank-image-' . time() . '-' . wp_rand(100, 999) . '.jpg';
    }

    // Download image using wp_remote_get (works on all hosts, unlike file_get_contents)
    $image_data = null;
    $response = wp_remote_get($image_url, [
        'timeout'   => 30,
        'sslverify' => false,
    ]);

    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
        $image_data = wp_remote_retrieve_body($response);
    }

    if (!$image_data) return false;

    $upload_dir = wp_upload_dir();
    $filename = wp_unique_filename($upload_dir['path'], $filename);
    $filepath = $upload_dir['path'] . '/' . $filename;

    if (!file_put_contents($filepath, $image_data)) return false;

    $filetype = wp_check_filetype($filename, null);
    $mime_type = $filetype['type'] ?: 'image/jpeg';

    // Inherit the author from the parent post
    $post_author = 0;
    if ($post_id) {
        $parent_post = get_post($post_id);
        if ($parent_post) {
            $post_author = $parent_post->post_author;
        }
    }

    $attachment = [
        'post_mime_type' => $mime_type,
        'post_title'     => sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME)),
        'post_content'   => '',
        'post_status'    => 'inherit',
        'post_author'    => $post_author,
    ];

    $attach_id = wp_insert_attachment($attachment, $filepath, $post_id);
    if (is_wp_error($attach_id) || !$attach_id) return false;

    require_once ABSPATH . 'wp-admin/includes/image.php';
    $attach_data = wp_generate_attachment_metadata($attach_id, $filepath);
    wp_update_attachment_metadata($attach_id, $attach_data);

    // Store source URL so we can reuse this attachment for duplicate images
    update_post_meta($attach_id, '_outrank_source_url', $image_url);

    return $attach_id;
}

function outrank_download_content_images($content, $post_id) {
    if (empty($content)) return $content;

    $site_host = wp_parse_url(home_url(), PHP_URL_HOST);

    // Extract image URLs from src, data-src, and data-lazy-src attributes
    if (!preg_match_all('/<img[^>]+(?:src|data-src|data-lazy-src)=["\']([^"\']+)["\'][^>]*>/i', $content, $matches)) {
        return $content;
    }

    // Deduplicate URLs to avoid downloading the same image twice
    $unique_urls = array_unique($matches[1]);

    foreach ($unique_urls as $image_url) {
        // Skip data URIs
        if (strpos($image_url, 'data:') === 0) continue;

        // Decode HTML entities for downloading (wp_kses encodes & as &amp; in URLs)
        $clean_url = html_entity_decode($image_url, ENT_QUOTES, 'UTF-8');

        // Skip local URLs
        $image_host = wp_parse_url($clean_url, PHP_URL_HOST);
        if ($image_host && $image_host === $site_host) continue;

        $attach_id = outrank_upload_image_from_url($clean_url, $post_id);
        if ($attach_id) {
            $local_url = wp_get_attachment_url($attach_id);
            if ($local_url) {
                // Replace the original HTML-encoded URL in content
                $content = str_replace($image_url, $local_url, $content);
            }
        }
    }

    return $content;
}
