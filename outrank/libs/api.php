<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if (!defined('OUTRANK_API_SECRET')) {
    define('OUTRANK_API_SECRET', '7d775a0fd0bc1d92e4d3db1fe313d72e');
}

add_action('rest_api_init', function () {
    register_rest_route('outrank/v1', '/submit', [
        'methods' => 'POST',
        'callback' => 'outrank_receive_article',
        'permission_callback' => function ($request) {
            $secretKey = $request->get_header('X-Secret-Key');
            if (!$secretKey) {
                $secretKey = $request->get_header('x-secret-key');
            }
            return $secretKey && hash_equals(OUTRANK_API_SECRET, $secretKey);
        }
    ]);
    
    register_rest_route('outrank/v1', '/test-integration', [
        'methods' => 'POST',
        'callback' => 'outrank_test_integration',
        'permission_callback' => '__return_true'
    ]);
    
    register_rest_route('outrank/v1', '/posts', [
        'methods' => 'GET',
        'callback' => 'outrank_get_posts',
        'permission_callback' => '__return_true',
        'args' => [
            'page' => [
                'default' => 1,
                'sanitize_callback' => 'absint'
            ],
            'per_page' => [
                'default' => 500,
                'sanitize_callback' => 'absint'
            ],
            'status' => [
                'default' => 'publish',
                'sanitize_callback' => 'sanitize_text_field'
            ]
        ]
    ]);

    register_rest_route('outrank/v1', '/set-integration-id', [
        'methods' => 'POST',
        'callback' => 'outrank_set_integration_id',
        'permission_callback' => '__return_true'
    ]);
});

function outrank_set_integration_id($request) {
    $params = $request->get_json_params();

    $secret = sanitize_text_field($params['secret'] ?? '');
    $storedSecret = get_option('outrank_api_key');

    if (!$secret || !$storedSecret || !hash_equals($storedSecret, $secret)) {
        return new WP_REST_Response(['error' => 'Invalid or missing secret'], 403);
    }

    $integration_id = sanitize_text_field($params['integration_id'] ?? '');
    if (empty($integration_id)) {
        return new WP_REST_Response(['error' => 'Missing integration_id'], 400);
    }

    update_option('outrank_integration_id', $integration_id);

    return new WP_REST_Response(['success' => true], 200);
}

function outrank_receive_article($request) {
    global $wpdb;

    // Ensure table exists (handles multisite subsites)
    outrank_ensure_table_exists();

    $params = $request->get_json_params();

    $secret = sanitize_text_field($params['secret'] ?? '');
    $storedSecret = get_option('outrank_api_key');

    if (!$secret || !$storedSecret || !hash_equals($storedSecret, $secret)) {
        return new WP_REST_Response(['error' => 'Invalid or missing secret'], 403);
    }

    $title = sanitize_text_field($params['title'] ?? 'Untitled');
    $slug = sanitize_title($params['slug'] ?? $title);
    $created_at = !empty($params['created_at']) ? gmdate('Y-m-d H:i:s', strtotime($params['created_at'])) : current_time('mysql');

    $table_name = $wpdb->prefix . 'outrank_manage';

    // Upload featured image
    $imageId = outrank_upload_image_from_url($params['image_url'] ?? '');

    // Handle author
    $author = $params['author'] ?? '';
    $author_id = 1;
    if (!empty($author)) {
        if (is_numeric($author)) {
            $author_id = (int) $author;
        } else {
            $user = get_user_by('login', $author);
            if ($user) $author_id = $user->ID;
        }
    }

    $category_ids = outrank_resolve_category_ids($params['category'] ?? '');

    $unique_slug = outrank_generate_unique_slug($slug, $table_name);
    if (!$unique_slug) {
        return new WP_REST_Response([
            'error' => 'Too many posts with the same slug. Please use a different slug.'
        ], 409);
    }

    $sanitized_content = outrank_sanitize_content($params['content'] ?? '');

    $post_id = outrank_create_post_with_images([
        'post_title'    => $title,
        'post_content'  => $sanitized_content,
        'post_status'   => get_option('outrank_post_as_draft', 'yes') === 'yes' ? 'draft' : 'publish',
        'post_type'     => 'post',
        'post_name'     => $unique_slug,
        'post_category' => $category_ids,
        'tags_input'    => isset($params['tags']) ? array_map('sanitize_text_field', $params['tags']) : [],
        'post_author'   => $author_id,
    ]);

    if (is_wp_error($post_id)) {
        // Clean up the uploaded image to avoid orphaned attachments
        if (!empty($imageId)) {
            wp_delete_attachment($imageId, true);
        }
        return new WP_REST_Response(['error' => 'Failed to create post: ' . $post_id->get_error_message()], 500);
    }

    $final_slug = get_post_field('post_name', $post_id);
    $post_status = get_post_field('post_status', $post_id);

    // Insert into custom table with the actual WordPress slug
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $inserted = $wpdb->insert($table_name, [
        'image'            => $imageId ? (string) $imageId : '',
        'slug'             => $final_slug,
        'title'            => $title,
        'meta_description' => sanitize_text_field($params['meta_description'] ?? ''),
        'status'           => $post_status,
        'created_at'       => $created_at,
    ]);

    if (!$inserted) {
        // If custom table insert fails, delete the post and image to maintain consistency
        $db_error = $wpdb->last_error;
        wp_delete_post($post_id, true);
        if (!empty($imageId)) {
            wp_delete_attachment($imageId, true);
        }
        return new WP_REST_Response([
            'error' => 'Failed to insert into tracking table' . ( $db_error ? ': ' . $db_error : '' )
        ], 500);
    }

    // Set featured image and update its author/parent to match the post
    if (!empty($imageId)) {
        set_post_thumbnail($post_id, $imageId);
        wp_update_post([
            'ID'          => $imageId,
            'post_author' => $author_id,
            'post_parent' => $post_id,
        ]);
    }

    $focus_keyword = sanitize_text_field($params['focus_keyword'] ?? $params['focus_keyphrase'] ?? '');
    $meta_desc = sanitize_text_field($params['meta_description'] ?? '');
    outrank_set_seo_meta($post_id, $title, $meta_desc, $focus_keyword);
    outrank_set_squirrly_seo($post_id, $title, $meta_desc, $focus_keyword);

    return new WP_REST_Response(['success' => true, 'post_id' => $post_id], 200);
}

function outrank_test_integration($request) {
    // 1. Get integration key from request
    $params = $request->get_json_params();
    $secret = sanitize_text_field($params['secret'] ?? '');
    
    // 2. Get stored integration key (what user saved in settings)
    $storedSecret = get_option('outrank_api_key');
    
    // 3. Verify the key with specific error codes
    if (!$secret) {
        return new WP_REST_Response([
            'success' => false, 
            'error_code' => 'invalid_integration_key'
        ], 403);
    }
    
    if (!$storedSecret) {
        return new WP_REST_Response([
            'success' => false, 
            'error_code' => 'integration_not_configured'
        ], 403);
    }
    
    if (!hash_equals($storedSecret, $secret)) {
        return new WP_REST_Response([
            'success' => false,
            'error_code' => 'invalid_integration_key'
        ], 403);
    }

    // 4. Create test post with dummy data
    $test_post_id = wp_insert_post([
        'post_title'    => 'Test Post - Outrank Integration',
        'post_content'  => 'This is a test post to verify Outrank integration is working correctly.',
        'post_status'   => 'draft',
        'post_type'     => 'post',
        'post_name'     => 'outrank-test-post-' . time(),
        'post_author'   => 1, // Admin user
    ]);
    
    // 5. Check if creation was successful
    if (is_wp_error($test_post_id)) {
        return new WP_REST_Response([
            'success' => false, 
            'error_code' => 'post_creation_failed'
        ], 500);
    }
    
    // 6. Delete the test post immediately (but don't fail if cleanup fails)
    wp_delete_post($test_post_id, true);
    
    // 7. Return success
    return new WP_REST_Response([
        'success' => true, 
        'message' => 'Integration test successful'
    ], 200);
}

function outrank_get_posts($request) {
    // 1. Get integration key from request headers or params
    $secret = '';
    
    // Try to get from headers first (WordPress way)
    $auth_header = $request->get_header('X-Integration-Key');
    if ($auth_header) {
        $secret = $auth_header;
    } else {
        // Fallback to query parameter
        $secret = $request->get_param('secret') ?? '';
    }
    
    // 2. Verify integration key
    $storedSecret = get_option('outrank_api_key');
    if (!$secret || !$storedSecret || !hash_equals($storedSecret, $secret)) {
        return new WP_REST_Response([
            'success' => false,
            'error_code' => 'invalid_integration_key'
        ], 403);
    }
    
    // 3. Get parameters
    $page = $request->get_param('page');
    $per_page = min($request->get_param('per_page'), 500); // Max 500 per page
    $status = $request->get_param('status');
    
    // Validate status parameter
    $allowed_statuses = ['publish', 'draft', 'private', 'pending', 'future', 'trash'];
    if (!in_array($status, $allowed_statuses)) {
        $status = 'publish'; // Default to publish if invalid
    }
    
    // 4. Query posts
    $args = [
        'post_type' => 'post',
        'post_status' => $status,
        'posts_per_page' => $per_page,
        'paged' => $page,
        'orderby' => 'date',
        'order' => 'DESC'
    ];
    
    $query = new WP_Query($args);
    $posts = [];
    
    // 5. Format post data
    foreach ($query->posts as $post) {
        $categories = wp_get_post_categories($post->ID, ['fields' => 'names']);
        $tags = wp_get_post_tags($post->ID, ['fields' => 'names']);
        $featured_image = get_the_post_thumbnail_url($post->ID, 'full');
        
        $posts[] = [
            'id' => $post->ID,
            'title' => $post->post_title,
            'slug' => $post->post_name,
            'content' => $post->post_content,
            'excerpt' => $post->post_excerpt ?: wp_trim_words($post->post_content, 55),
            'status' => $post->post_status,
            'date' => $post->post_date,
            'modified' => $post->post_modified,
            'categories' => $categories,
            'tags' => $tags,
            'featured_image' => $featured_image ?: null,
            'url' => get_permalink($post->ID)
        ];
    }
    
    // 6. Return response with pagination info
    return new WP_REST_Response([
        'success' => true,
        'posts' => $posts,
        'pagination' => [
            'page' => (int) $page,
            'per_page' => (int) $per_page,
            'total_posts' => (int) $query->found_posts,
            'total_pages' => (int) $query->max_num_pages
        ]
    ], 200);
}

// --- Helper functions for article submission ---

function outrank_sanitize_content($content) {
    $allowed_html = wp_kses_allowed_html('post');

    $allowed_html['iframe'] = array(
        'src' => array(),
        'width' => array(),
        'height' => array(),
        'frameborder' => array(),
        'allowfullscreen' => array(),
        'allow' => array(),
        'style' => array(),
    );

    $sanitized = wp_kses($content, $allowed_html);

    $sanitized = preg_replace_callback(
        '/<iframe[^>]*>/i',
        function($matches) {
            $iframe = $matches[0];

            if (preg_match('/src=["\']([^"\']*)["\']/', $iframe, $src_matches)) {
                $src = trim($src_matches[1]);

                if (preg_match('/^https:\/\/(www\.)?youtube\.com\/embed\/[a-zA-Z0-9_-]{11}(\?[^"\'<>]*)?$/i', $src) ||
                    preg_match('/^https:\/\/(www\.)?youtube-nocookie\.com\/embed\/[a-zA-Z0-9_-]{11}(\?[^"\'<>]*)?$/i', $src)) {
                    return $iframe;
                }
            }

            return '';
        },
        $sanitized
    );

    return $sanitized;
}

function outrank_resolve_category_ids($category) {
    $category_ids = [];
    if (!empty($category)) {
        $categories = is_array($category) ? $category : [$category];
        foreach ($categories as $cat_name) {
            $cat_name = sanitize_text_field($cat_name);
            $cat = get_category_by_slug(sanitize_title($cat_name));
            if (!$cat) {
                $term = wp_insert_term($cat_name, 'category');
                if (!is_wp_error($term)) {
                    $category_ids[] = $term['term_id'];
                } else {
                    $category_ids[] = 1;
                }
            } else {
                $category_ids[] = $cat->term_id;
            }
        }
    } else {
        $category_ids[] = 1;
    }
    return $category_ids;
}

function outrank_generate_unique_slug($slug, $table_name) {
    global $wpdb;
    $max_attempts = 10;
    for ($i = 0; $i < $max_attempts; $i++) {
        $test_slug = ($i === 0) ? $slug : $slug . '-' . ($i + 1);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $in_custom = $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM %i WHERE slug = %s", $table_name, $test_slug)
        );
        $in_wp = get_page_by_path($test_slug, OBJECT, 'post');
        if ($in_custom == 0 && !$in_wp) {
            return $test_slug;
        }
    }
    return false;
}

function outrank_set_seo_meta($post_id, $title, $meta_description = '', $focus_keyword = '') {
    if (!empty($meta_description)) {
        update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_description);
        update_post_meta($post_id, 'rank_math_description', $meta_description);
        update_post_meta($post_id, '_aioseo_description', $meta_description);
        update_post_meta($post_id, '_seopress_titles_desc', $meta_description);
    }
    if (!empty($focus_keyword)) {
        update_post_meta($post_id, '_yoast_wpseo_focuskw', $focus_keyword);
        update_post_meta($post_id, 'rank_math_focus_keyword', $focus_keyword);
        update_post_meta($post_id, '_aioseo_keyphrases', json_encode([
            ['keyphrase' => $focus_keyword, 'score' => 0]
        ]));
        update_post_meta($post_id, '_seopress_analysis_target_kw', $focus_keyword);
    }
    if (!empty($title)) {
        update_post_meta($post_id, '_yoast_wpseo_title', $title);
        update_post_meta($post_id, 'rank_math_title', $title);
        update_post_meta($post_id, '_aioseo_title', $title);
        update_post_meta($post_id, '_seopress_titles_title', $title);
    }
}

function outrank_set_squirrly_seo($post_id, $title, $meta_description = '', $focus_keyword = '') {
    global $wpdb;
    $sq_table = $wpdb->prefix . 'qss';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $sq_table)) !== $sq_table) return;

    $url_hash = md5(strval($post_id));

    $sq_defaults = array(
        'doseo' => 1, 'noindex' => 0, 'nofollow' => 0, 'nositemap' => 0,
        'title' => '', 'description' => '', 'keywords' => '',
        'canonical' => '', 'primary_category' => '',
        'redirect' => '', 'redirect_type' => 301,
        'robots' => null, 'focuspage' => null,
        'tw_media' => '', 'tw_title' => '', 'tw_description' => '', 'tw_type' => '',
        'og_title' => '', 'og_description' => '', 'og_author' => '', 'og_type' => '', 'og_media' => '',
        'jsonld' => '', 'jsonld_types' => array(), 'fpixel' => '',
        'patterns' => null, 'sep' => null, 'optimizations' => null, 'innerlinks' => null,
    );

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT id, seo FROM %i WHERE url_hash = %s",
        $sq_table,
        $url_hash
    ));

    if ($existing) {
        $seo_data = maybe_unserialize($existing->seo);
        if (!is_array($seo_data)) {
            $seo_data = $sq_defaults;
        }
    } else {
        $seo_data = $sq_defaults;
    }

    $seo_data['title'] = $title ?? '';
    $seo_data['description'] = $meta_description;
    $seo_data['keywords'] = $focus_keyword;
    $seo_data['doseo'] = 1;

    if ($existing) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update($sq_table, array(
            'seo'       => serialize($seo_data),
            'date_time' => current_time('mysql'),
        ), array('id' => $existing->id));
    } else {
        $post_url = get_permalink($post_id);
        $post_obj = serialize(array(
            'ID' => $post_id, 'post_type' => 'post', 'term_id' => 0, 'taxonomy' => '',
        ));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->insert($sq_table, array(
            'blog_id'   => get_current_blog_id(),
            'post'      => $post_obj,
            'URL'       => $post_url,
            'url_hash'  => $url_hash,
            'seo'       => serialize($seo_data),
            'date_time' => current_time('mysql'),
        ));
    }
}

function outrank_create_post_with_images($args) {
    // Remove ALL kses filters (handles both admin and cron contexts)
    kses_remove_filters();

    $post_id = wp_insert_post($args);

    if (is_wp_error($post_id)) {
        kses_init_filters();
        return $post_id;
    }

    $updated_content = outrank_download_content_images($args['post_content'], $post_id);
    if ($updated_content !== $args['post_content']) {
        wp_update_post([
            'ID'           => $post_id,
            'post_content' => $updated_content,
        ]);
    }

    kses_init_filters();
    return $post_id;
}