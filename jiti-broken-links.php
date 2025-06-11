<?php
/*
Plugin Name: Jiti - Broken Links
Description: Scan et affichage en temps réel des liens testés dans l’admin.
Version: 2.0.1
Author: Jiti
Author URI: https://jiti.me
License: Copyleft
*/

add_action('admin_menu', function () {
    add_menu_page(
        'Jiti - Broken Links',
        'Jiti - Broken Links',
        'manage_options',
        'jiti-broken-links',
        'jiti_broken_links_admin_page',
        'dashicons-dismiss'
    );
});

// Charge le JS uniquement sur la page d’admin du plugin
add_action('admin_enqueue_scripts', function($hook) {
    if ($hook !== 'toplevel_page_jiti-broken-links') return;
    wp_enqueue_script('jiti-broken-links-js', plugin_dir_url(__FILE__) . 'jiti-broken-links.js', ['jquery'], '2.0.1', true);
    wp_localize_script('jiti-broken-links-js', 'JitiBrokenLinks', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('jiti_broken_links_ajax'),
    ]);
});

function jiti_broken_links_admin_page() {
    ?>
    <div class="wrap">
        <h1>Jiti - Broken Links</h1>
        <form id="jiti-bl-form" style="display: flex; align-items: center; gap: 10px; margin-bottom: 1em;">
            <label for="link_scope">Type de lien&nbsp;:</label>
            <select name="link_scope" id="link_scope">
                <option value="all">Tous</option>
                <option value="internal">Internes uniquement</option>
                <option value="external">Externes uniquement</option>
            </select>
            <label for="link_status">Type d'erreur&nbsp;:</label>
            <select name="link_status" id="link_status">
                <option value="404">404 uniquement</option>
                <option value="3xx">Redirections uniquement</option>
                <option value="both">404 + Redirections</option>
            </select>
            <button type="button" class="button button-primary" id="jiti-bl-start">Démarrer le scan</button>
        </form>
        <pre id="jiti-bl-progress" style="font-size:1em; background:#fff; padding:1em; border:1px solid #eee; min-height:300px;"></pre>
    </div>
    <?php
}

// AJAX : récupère tous les IDs d’articles à scanner selon le filtre
add_action('wp_ajax_jiti_bl_get_posts', function() {
    check_ajax_referer('jiti_broken_links_ajax', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

    $filter = $_POST['link_scope'] ?? 'all';
    if (!in_array($filter, ['all', 'internal', 'external'])) $filter = 'all';

    $args = [
        'post_type' => 'post',
        'posts_per_page' => -1,
        'post_status' => 'publish',
        'fields' => 'ids',
    ];
    $posts = get_posts($args);
    wp_send_json_success($posts);
});

// AJAX : scanne un article donné (retourne les lignes à afficher)
add_action('wp_ajax_jiti_bl_scan_post', function() {
    check_ajax_referer('jiti_broken_links_ajax', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

    $post_id = intval($_POST['post_id']);
    $filter = $_POST['link_scope'] ?? 'all';
    $status_filter = $_POST['link_status'] ?? '404';
    if (!in_array($filter, ['all', 'internal', 'external'])) $filter = 'all';
    if (!in_array($status_filter, ['404', '3xx', 'both'])) $status_filter = '404';

    $title = get_the_title($post_id);
    $content = get_post_field('post_content', $post_id);
    preg_match_all('/href=[\'"]([^\'"]+)[\'"]/i', $content, $matches);
    $links = array_unique($matches[1]);

    $lines = [];
    $has_issue = false;

    $lines[] = '📄 ' . esc_html($title);

    foreach ($links as $url) {
        $is_internal = strpos($url, home_url()) === 0;
        if (($filter === 'internal' && !$is_internal) || ($filter === 'external' && $is_internal)) continue;

        $response = wp_remote_head($url, ['timeout' => 2]);
        if (is_wp_error($response)) {
            $lines[] = '   → ' . esc_url($url) . ' (erreur réseau) ❌';
            $has_issue = true;
            continue;
        }
        $code = wp_remote_retrieve_response_code($response);
        $ok = true;
        if (
            ($status_filter === '404' && $code === 404) ||
            ($status_filter === '3xx' && $code >= 300 && $code < 400) ||
            ($status_filter === 'both' && ($code === 404 || ($code >= 300 && $code < 400)))
        ) {
            $ok = false;
        }
        $lines[] = '   → ' . esc_url($url) . ' (HTTP ' . $code . ') ' . ($ok ? '✅' : '❌');
        if (!$ok) $has_issue = true;
    }
    if ($has_issue) {
        $edit_link = get_edit_post_link($post_id);
        $lines[] = '   ↪ Lien vers l’éditeur : ' . esc_url($edit_link);
    }
    wp_send_json_success(['lines' => $lines, 'has_issue' => $has_issue]);
});
