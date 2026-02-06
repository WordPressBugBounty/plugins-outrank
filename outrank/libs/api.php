<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

define('OUTRANK_API_SECRET', '7d775a0fd0bc1d92e4d3db1fe313d72e');
require_once plugin_dir_path(__FILE__) . '../includes/image-functions.php';

function sanitize_content($content) {
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

add_action('rest_api_init', function () {
    register_rest_route('outrank/v1', '/submit', [
        'methods' => 'POST',
        'callback' => 'outrank_receive_article',
        'permission_callback' => function ($request) {
            $secretKey = $request->get_header('X-Secret-Key');
            if (!$secretKey) {
                $secretKey = $request->get_header('x-secret-key');
            }
            return $secretKey && hash_equals($secretKey, OUTRANK_API_SECRET);
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
});

function outrank_receive_article($request) {
    global $wpdb;

    // Ensure table exists (handles multisite subsites)
    outrank_ensure_table_exists();

    $params = $request->get_json_params();

    $secret = sanitize_text_field($params['secret'] ?? '');
    $storedSecret = get_option('outrank_api_key');

    if (!$secret || $secret !== $storedSecret) {
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

    // Handle categories
    $category = $params['category'] ?? '';
    $category_ids = [];

    if (!empty($category)) {
        $categories = is_array($category) ? $category : [$category];
        foreach ($categories as $cat_name) {
            $cat_name = sanitize_text_field($cat_name);
            $cat = get_category_by_slug(sanitize_title($cat_name));
            if (!$cat) {
                // Use wp_insert_term instead of wp_create_category (works in REST API context)
                $term = wp_insert_term($cat_name, 'category');
                if (!is_wp_error($term)) {
                    $category_ids[] = $term['term_id'];
                } else {
                    // Fallback to Uncategorized if category creation fails
                    $category_ids[] = 1;
                }
            } else {
                $category_ids[] = $cat->term_id;
            }
        }
    } else {
        // Use WordPress default "Uncategorized" category (ID: 1)
        $category_ids[] = 1;
    }

    // Check if slug exists in custom table and generate unique one if needed
    $unique_slug = $slug;
    $suffix = 2;
    $max_attempts = 10;

    while ($suffix <= $max_attempts) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $existing_in_custom = $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE slug = %s", $unique_slug)
        );

        // Check if slug exists in WordPress posts
        $existing_in_wp = get_page_by_path($unique_slug, OBJECT, 'post');

        if ($existing_in_custom == 0 && !$existing_in_wp) {
            break; // Slug is unique in both tables
        }

        // Slug exists, try next suffix
        $unique_slug = $slug . '-' . $suffix;
        $suffix++;
    }

    // If we couldn't find a unique slug after max attempts, return error
    if ($suffix > $max_attempts) {
        return new WP_REST_Response([
            'error' => 'Too many posts with the same slug. Please use a different slug.'
        ], 409);
    }

    remove_filter('content_save_pre', 'wp_filter_post_kses');

    $sanitized_content = sanitize_content($params['content'] ?? '');

    // Insert post with the unique slug
    $post_id = wp_insert_post([
        'post_title'    => $title,
        'post_content'  => $sanitized_content,
        'post_status'   => get_option('outrank_post_as_draft', 'yes') === 'yes' ? 'draft' : 'publish',
        'post_type'     => 'post',
        'post_name'     => $unique_slug,
        'post_category' => $category_ids,
        'tags_input'    => isset($params['tags']) ? array_map('sanitize_text_field', $params['tags']) : [],
        'post_author'   => $author_id,
    ]);

    add_filter('content_save_pre', 'wp_filter_post_kses');

    if (is_wp_error($post_id)) {
        return new WP_REST_Response(['error' => 'Failed to create post: ' . $post_id->get_error_message()], 500);
    }

    // Get the final slug and status
    $final_slug = get_post_field('post_name', $post_id);
    $post_status = get_post_field('post_status', $post_id);

    // Insert into custom table with the actual WordPress slug
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $inserted = $wpdb->insert($table_name, [
        'image'            => $imageId ? (string) $imageId : '',
        'slug'             => $final_slug,
        'title'            => $title,
        'meta_description' => sanitize_text_field($params['meta_description'] ?? ''),
        'status'           => $post_status,
        'created_at'       => $created_at,
    ]);

    if (!$inserted) {
        // If custom table insert fails, delete the post to maintain consistency
        $db_error = $wpdb->last_error;
        wp_delete_post($post_id, true);
        return new WP_REST_Response([
            'error' => 'Failed to insert into tracking table' . ( $db_error ? ': ' . $db_error : '' )
        ], 500);
    }

    // Set featured image
    if (!empty($imageId)) {
        set_post_thumbnail($post_id, $imageId);
    }

    // Set SEO meta data for popular SEO plugins
    if (!empty($params['meta_description'])) {
        $meta_description = sanitize_text_field($params['meta_description']);
        
        // Yoast SEO
        update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_description);
        
        // Rank Math
        update_post_meta($post_id, 'rank_math_description', $meta_description);
        
        // All in One SEO
        update_post_meta($post_id, '_aioseo_description', $meta_description);
        
        // SEOPress
        update_post_meta($post_id, '_seopress_titles_desc', $meta_description);
    }
    
    // Set focus keyphrase/keyword if provided
    if (!empty($params['focus_keyword']) || !empty($params['focus_keyphrase'])) {
        $focus_keyword = sanitize_text_field($params['focus_keyword'] ?? $params['focus_keyphrase'] ?? '');
        
        // Yoast SEO
        update_post_meta($post_id, '_yoast_wpseo_focuskw', $focus_keyword);
        
        // Rank Math
        update_post_meta($post_id, 'rank_math_focus_keyword', $focus_keyword);
        
        // All in One SEO (stores as JSON)
        $aioseo_keyphrases = json_encode([
            ['keyphrase' => $focus_keyword, 'score' => 0]
        ]);
        update_post_meta($post_id, '_aioseo_keyphrases', $aioseo_keyphrases);
        
        // SEOPress
        update_post_meta($post_id, '_seopress_analysis_target_kw', $focus_keyword);
    }
    
    // Set SEO title using the normal title
    if (!empty($title)) {
        // Yoast SEO
        update_post_meta($post_id, '_yoast_wpseo_title', $title);
        
        // Rank Math
        update_post_meta($post_id, 'rank_math_title', $title);
        
        // All in One SEO
        update_post_meta($post_id, '_aioseo_title', $title);
        
        // SEOPress
        update_post_meta($post_id, '_seopress_titles_title', $title);
    }

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
    
    if ($secret !== $storedSecret) {
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
    if (!$secret || !$storedSecret || $secret !== $storedSecret) {
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