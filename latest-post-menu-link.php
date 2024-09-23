<?php
/**
 * Plugin Name: WP Latest Post Menu Link
 * Description: Adds custom endpoints for latest posts and a menu item to link to them.
 * Version: 2.1
 * Author: Lance Boer
 * Author URI: https://lanceboer.com/
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Add rewrite rules for the custom endpoints
add_action('init', 'lb_latest_post_add_rewrite_rules');
function lb_latest_post_add_rewrite_rules() {
    add_rewrite_rule(
        'tag/([^/]+)/latest/?$',
        'index.php?latest_post_redirect=1&latest_post_term=$matches[1]&latest_post_taxonomy=tag',
        'top'
    );
    add_rewrite_rule(
        '([^/]+)/latest/?$',
        'index.php?latest_post_redirect=1&latest_post_term=$matches[1]&latest_post_taxonomy=category',
        'top'
    );
}

// Add query vars
add_filter('query_vars', 'lb_latest_post_add_query_vars');
function lb_latest_post_add_query_vars($vars) {
    $vars[] = 'latest_post_redirect';
    $vars[] = 'latest_post_term';
    $vars[] = 'latest_post_taxonomy';
    return $vars;
}

// Handle the redirect
add_action('template_redirect', 'lb_latest_post_handle_redirect');
function lb_latest_post_handle_redirect() {
    if (get_query_var('latest_post_redirect')) {
        $term_slug = get_query_var('latest_post_term');
        $taxonomy = get_query_var('latest_post_taxonomy');
        
        $args = array(
            'posts_per_page' => 1,
            'post_type' => 'any',
            'orderby' => array('date' => 'DESC', 'ID' => 'DESC'),
            'order' => 'DESC',
            'post_status' => 'publish',
        );

        if (!empty($term_slug)) {
            $term = get_term_by('slug', $term_slug, $taxonomy === 'tag' ? 'post_tag' : 'category');
            
            if ($term) {
                $args['tax_query'] = array(
                    array(
                        'taxonomy' => $term->taxonomy,
                        'field' => 'term_id',
                        'terms' => $term->term_id,
                    ),
                );
            }
        }

        $latest_posts = new WP_Query($args);

        if ($latest_posts->have_posts()) {
            $latest_post = $latest_posts->posts[0];
            
            // Debugging
            error_log('Latest post query: ' . print_r($args, true));
            error_log('Latest post found: ID ' . $latest_post->ID . ', Date: ' . $latest_post->post_date);

            wp_redirect(get_permalink($latest_post->ID), 302);
            exit;
        }
        
        // If no post found or term doesn't exist, redirect to home
        wp_redirect(home_url(), 302);
        exit;
    }
}

// Add custom menu item
add_action('admin_head-nav-menus.php', 'lb_latest_post_add_meta_box');
add_filter('admin_head-nav-menus.php', 'lb_latest_post_register_menu_meta_box');

function lb_latest_post_add_meta_box() {
    add_meta_box(
        'lb_latest_post_menu_link',
        'Latest Post Link',
        'lb_latest_post_menu_link_meta_box',
        'nav-menus',
        'side',
        'low'
    );
}

function lb_latest_post_register_menu_meta_box() {
    global $wp_meta_boxes;
    
    if (!isset($wp_meta_boxes['nav-menus']['side']['low']['lb_latest_post_menu_link'])) {
        add_meta_box(
            'lb_latest_post_menu_link',
            'Latest Post Link',
            'lb_latest_post_menu_link_meta_box',
            'nav-menus',
            'side',
            'low'
        );
    }
}

// Create meta box content
function lb_latest_post_menu_link_meta_box() {
    ?>
    <div id="latest-post-menu-link-wrap">
        <input type="hidden" name="menu-item[-1][menu-item-type]" value="custom">
        <input type="hidden" name="menu-item[-1][menu-item-object]" value="custom">

        <p>
            <label for="latest_post_link_title">Title:</label>
            <input type="text" id="latest_post_link_title" name="latest_post_link_title" value="Latest Post" class="widefat">
            <span class="description">The text shown for this menu item.</span>
        </p>

        <p>
            <label for="latest_post_link_post_type">Post Type:</label>
            <select id="latest_post_link_post_type" name="latest_post_link_post_type" class="widefat">
                <?php
                $post_types = get_post_types(array('public' => true), 'objects');
                foreach ($post_types as $post_type) {
                    echo '<option value="' . esc_attr($post_type->name) . '" data-singular="' . esc_attr($post_type->labels->singular_name) . '">' . esc_html($post_type->label) . '</option>';
                }
                ?>
            </select>
            <span class="description">Select the post type for the latest post.</span>
        </p>

        <p>
            <label>Filter by:</label>
        </p>
        <div class="latest-post-link-radio-group">
            <label>
                <input type="radio" name="latest_post_link_taxonomy" value="category" checked> 
                Category
            </label>
            <label>
                <input type="radio" name="latest_post_link_taxonomy" value="tag"> 
                Tag
            </label>
        </div>

        <p id="latest_post_link_term_wrapper">
            <label for="latest_post_link_term">Select Term:</label>
            <select id="latest_post_link_term" name="latest_post_link_term" class="widefat"></select>
        </p>

        <p class="button-controls">
            <span class="add-to-menu">
                <input type="submit" class="button-secondary submit-add-to-menu right" value="Add to Menu" name="add-latest-post-menu-item" id="submit-latest-post-menu-link">
                <span class="spinner"></span>
            </span>
        </p>
    </div>

    <script>
    jQuery(document).ready(function($) {
        function showLoading() {
            $('#latest_post_link_term').prop('disabled', true).html('<option>Loading...</option>');
            $('#latest_post_link_post_type, input[name="latest_post_link_taxonomy"]').prop('disabled', true);
            $('#submit-latest-post-menu-link').prop('disabled', true);
        }

        function hideLoading() {
            $('#latest_post_link_post_type, input[name="latest_post_link_taxonomy"], #submit-latest-post-menu-link').prop('disabled', false);
        }

        function updateTerms() {
            showLoading();
            var postType = $('#latest_post_link_post_type').val();
            var taxonomy = $('input[name="latest_post_link_taxonomy"]:checked').val();
            
            $.ajax({
                url: ajaxurl,
                data: {
                    action: 'lb_get_terms_for_post_type',
                    post_type: postType,
                    taxonomy: taxonomy
                },
                success: function(response) {
                    $('#latest_post_link_term').html(response).prop('disabled', false);
                    updateTitle();
                    hideLoading();
                },
                error: function() {
                    $('#latest_post_link_term').html('<option>Error loading terms</option>');
                    hideLoading();
                }
            });
        }

        function updateTitle() {
            var taxonomy = $('input[name="latest_post_link_taxonomy"]:checked').val();
            var postType = $('#latest_post_link_post_type option:selected').data('singular');
            var term = $('#latest_post_link_term option:selected').text();
            var title = 'Latest ' + postType + ' ' + (taxonomy === 'category' ? 'in ' : 'with ') + term;
            $('#latest_post_link_title').val(title);
        }

        $('#latest_post_link_post_type, input[name="latest_post_link_taxonomy"]').change(updateTerms);
        $('#latest_post_link_term').change(updateTitle);
        updateTerms();

        $('#submit-latest-post-menu-link').click(function(e) {
            e.preventDefault();

            var taxonomy = $('input[name="latest_post_link_taxonomy"]:checked').val();
            var postType = $('#latest_post_link_post_type').val();
            var termSlug = $('#latest_post_link_term option:selected').val();
            var fullUrl = '<?php echo esc_js(home_url()); ?>/' + (taxonomy === 'tag' ? 'tag/' : '') + termSlug + '/latest';

            var menuItem = {
                '-1': {
                    'menu-item-type': 'latest_post_link',
                    'menu-item-title': $('#latest_post_link_title').val(),
                    'menu-item-url': fullUrl,
                    'menu-item-attr-title': '',
                    'menu-item-target': '',
                    'menu-item-classes': '',
                    'menu-item-xfn': '',
                    'menu-item-object': 'latest_post_link',
                    'menu-item-object-id': $('#latest_post_link_term').val(),
                    'menu-item-description': postType + '|' + taxonomy
                }
            };

            wpNavMenu.addItemToMenu(menuItem, wpNavMenu.addMenuItemToBottom, function() {
                // Clear form fields after adding
                $('#latest_post_link_title').val('Latest Post');
                $('#latest_post_link_post_type').val('post');
                $('input[name="latest_post_link_taxonomy"][value="category"]').prop('checked', true);
                updateTerms();
            });
        });
    });
    </script>

    <style>
    #latest-post-menu-link-wrap select:disabled,
    #latest-post-menu-link-wrap input:disabled {
        background-color: #f0f0f0;
        color: #888;
    }
    </style>
    <?php
}

// AJAX handler for getting terms
add_action('wp_ajax_lb_get_terms_for_post_type', 'lb_get_terms_for_post_type');
function lb_get_terms_for_post_type() {
    $post_type = $_GET['post_type'];
    $taxonomy = $_GET['taxonomy'];

    $terms = get_terms(array(
        'taxonomy' => $taxonomy === 'category' ? get_object_taxonomies($post_type, 'names')[0] : 'post_tag',
        'hide_empty' => false,
    ));

    foreach ($terms as $term) {
        echo '<option value="' . esc_attr($term->term_id) . '">' . esc_html($term->name) . '</option>';
    }

    wp_die();
}

// Register custom menu item type
add_action('init', 'lb_register_latest_post_link_menu_item');
function lb_register_latest_post_link_menu_item() {
    register_post_type('latest_post_link', array(
        'labels' => array('name' => 'Latest Post Links'),
        'public' => false,
    ));
}

// Customize the display of our menu item in the admin
add_filter('wp_setup_nav_menu_item', 'lb_latest_post_link_setup_menu_item');
function lb_latest_post_link_setup_menu_item($menu_item) {
    if ($menu_item->type === 'latest_post_link') {
        $menu_item->type_label = 'Latest Post Link';
    }
    return $menu_item;
}

// Customize the menu item edit screen
add_filter('wp_edit_nav_menu_walker', 'lb_latest_post_link_edit_walker', 10, 2);
function lb_latest_post_link_edit_walker($walker, $menu_id) {
    class lb_Latest_Post_Link_Walker_Nav_Menu_Edit extends Walker_Nav_Menu_Edit {
        function start_el(&$output, $item, $depth = 0, $args = array(), $id = 0) {
            $item_output = '';
            parent::start_el($item_output, $item, $depth, $args, $id);
            
            if ($item->type === 'latest_post_link') {
                $item_output = preg_replace('/class="field-url(.*?)<\/p>/', '', $item_output);
            }
            
            $output .= $item_output;
        }
    }
    return 'lb_Latest_Post_Link_Walker_Nav_Menu_Edit';
}

// Save custom fields when menu is saved
add_action('wp_update_nav_menu_item', 'lb_latest_post_update_nav_menu_item', 10, 3);
function lb_latest_post_update_nav_menu_item($menu_id, $menu_item_db_id, $args) {
    if ($args['menu-item-type'] === 'latest_post_link') {
        $term_id = $args['menu-item-object-id'];
        $post_type_taxonomy = explode('|', $args['menu-item-description']);
        
        update_post_meta($menu_item_db_id, '_latest_post_link_post_type', $post_type_taxonomy[0]);
        update_post_meta($menu_item_db_id, '_latest_post_link_taxonomy', $post_type_taxonomy[1]);
        update_post_meta($menu_item_db_id, '_latest_post_link_term', $term_id);
    }
}

// Modify the URL for our custom menu item on the frontend
add_filter('wp_nav_menu_objects', 'lb_latest_post_link_url_filter');
function lb_latest_post_link_url_filter($menu_items) {
    foreach ($menu_items as $item) {
        if ($item->type === 'latest_post_link') {
            $term_id = get_post_meta($item->ID, '_latest_post_link_term', true);
            $taxonomy = get_post_meta($item->ID, '_latest_post_link_taxonomy', true);
            $term = get_term($term_id, $taxonomy === 'tag' ? 'post_tag' : 'category');
            if ($term && !is_wp_error($term)) {
                $item->url = home_url(($taxonomy === 'tag' ? 'tag/' : '') . $term->slug . '/latest/');
            }
        }
    }
    return $menu_items;
}

// Flush rewrite rules when a menu is saved
add_action('wp_update_nav_menu', 'lb_latest_post_flush_rules_on_menu_save');
function lb_latest_post_flush_rules_on_menu_save($menu_id) {
    lb_latest_post_add_rewrite_rules();
    flush_rewrite_rules();
}

// Flush rewrite rules on plugin activation
register_activation_hook(__FILE__, 'lb_latest_post_flush_rewrite_rules');
function lb_latest_post_flush_rewrite_rules() {
    lb_latest_post_add_rewrite_rules();
    flush_rewrite_rules();
}

// Flush rewrite rules on plugin deactivation
register_deactivation_hook(__FILE__, 'flush_rewrite_rules');