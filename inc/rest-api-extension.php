<?php

/**
 * REST API Extension for Air Reactions
 * Exposes reaction counts in the WordPress REST API
 *
 * @package air-reactions
 */

namespace Air_Reactions;

/**
 * Register custom REST fields for reaction counts
 */
function register_rest_api_fields()
{
    $post_types = get_allowed_post_types();

    // Remove 'comment' as it's not a post type
    $post_types = array_filter($post_types, function ($type) {
        return $type !== 'comment';
    });

    // Get dynamic reaction types from the filter
    $reaction_types = get_types();
    $schema_properties = [];

    foreach ($reaction_types as $key => $type) {
        $schema_properties[$key] = [
            'type' => 'integer',
            'description' => sprintf(__('Number of %s reactions', 'air-reactions'), $key),
        ];
    }

    // Add total to schema
    $schema_properties['total'] = [
        'type' => 'integer',
        'description' => __('Total number of reactions', 'air-reactions'),
    ];

    foreach ($post_types as $post_type) {
        register_rest_field(
            $post_type,
            'reaction_counts',
            [
                'get_callback' => __NAMESPACE__ . '\get_reaction_counts_for_rest',
                'schema' => [
                    'description' => __('Reaction counts for this post', 'air-reactions'),
                    'type' => 'object',
                    'context' => ['view', 'edit'],
                    'properties' => $schema_properties,
                ],
            ]
        );

        // Also add a field to check if current user has reacted
        register_rest_field(
            $post_type,
            'user_reaction',
            [
                'get_callback' => __NAMESPACE__ . '\get_user_reaction_for_rest',
                'schema' => [
                    'description' => __('Current user reaction type', 'air-reactions'),
                    'type' => ['string', 'boolean'],
                    'context' => ['view', 'edit'],
                ],
            ]
        );
    }
}

/**
 * Get reaction counts for REST API response
 *
 * @param array $post Post array
 * @return array Reaction counts
 */
function get_reaction_counts_for_rest($post)
{
    $post_id = $post['id'];
    $counts = count_post_reactions($post_id);

    // Add total count
    $counts['total'] = array_sum($counts);

    return $counts;
}

/**
 * Get current user's reaction for REST API response
 *
 * @param array $post Post array
 * @return string|bool Reaction type or false if no reaction
 */
function get_user_reaction_for_rest($post)
{
    $post_id = $post['id'];
    $user_id = get_current_user_id();

    if (!$user_id) {
        return false;
    }

    return has_user_reacted($post_id, $user_id);
}

/**
 * Add reaction stats to REST API endpoint for bulk queries
 * This adds a custom endpoint to get reaction stats for multiple posts at once
 */
function register_bulk_reactions_endpoint()
{
    register_rest_route(
        REST_NAMESPACE,
        'stats',
        [
            'methods' => 'GET',
            'callback' => __NAMESPACE__ . '\get_bulk_reaction_stats',
            'permission_callback' => '__return_true',
            'args' => [
                'post_ids' => [
                    'required' => false,
                    'type' => 'string',
                    'description' => 'Comma-separated list of post IDs',
                ],
                'post_type' => [
                    'required' => false,
                    'type' => 'string',
                    'default' => 'post',
                    'description' => 'Post type to query',
                ],
                'limit' => [
                    'required' => false,
                    'type' => 'integer',
                    'default' => 10,
                    'description' => 'Number of top posts to return',
                ],
            ],
        ]
    );
}

/**
 * Get bulk reaction statistics
 *
 * @param \WP_REST_Request $request Request object
 * @return array Response data
 */
function get_bulk_reaction_stats($request)
{
    $post_ids = $request->get_param('post_ids');
    $post_type = $request->get_param('post_type');
    $limit = $request->get_param('limit');

    if ($post_ids) {
        // Get stats for specific posts
        $ids = array_map('intval', explode(',', $post_ids));
        $stats = [];

        foreach ($ids as $post_id) {
            $counts = count_post_reactions($post_id);
            $stats[$post_id] = [
                'post_id' => $post_id,
                'reactions' => $counts,
                'total' => array_sum($counts),
            ];
        }

        return $stats;
    } else {
        // Get top posts by reaction count
        return get_top_reacted_posts($post_type, $limit);
    }
}

/**
 * Get top posts by reaction count
 *
 * @param string $post_type Post type
 * @param int $limit Number of posts to return
 * @return array Top posts with reaction counts
 */
function get_top_reacted_posts($post_type = 'post', $limit = 10)
{
    global $wpdb;

    $meta_key = META_FIELD_KEY;

    // Query posts that have reactions
    $query = $wpdb->prepare(
        "SELECT post_id, meta_value 
    FROM {$wpdb->postmeta} pm
    INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
    WHERE pm.meta_key = %s 
    AND p.post_type = %s
    AND p.post_status = 'publish'
    LIMIT %d",
        $meta_key,
        $post_type,
        $limit * 2 // Get more than needed for sorting
    );

    $results = $wpdb->get_results($query);

    if (empty($results)) {
        return [];
    }

    $posts_with_counts = [];

    foreach ($results as $row) {
        $reactions = maybe_unserialize($row->meta_value);
        if (!is_array($reactions)) {
            continue;
        }

        $counts = count_post_reactions($row->post_id);
        $total = array_sum($counts);

        $posts_with_counts[] = [
            'post_id' => (int) $row->post_id,
            'reactions' => $counts,
            'total' => $total,
            'post_title' => get_the_title($row->post_id),
            'post_url' => get_permalink($row->post_id),
        ];
    }

    // Sort by total reactions
    usort($posts_with_counts, function ($a, $b) {
        return $b['total'] - $a['total'];
    });

    return array_slice($posts_with_counts, 0, $limit);
}
