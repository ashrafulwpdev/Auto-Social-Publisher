<?php
/*
Plugin Name: Auto Social Publisher
Description: Automatically publishes new posts to Telegram with enhanced features.
Version: 2.0.0
Author: MD ASHRAFUL ISLAM
Author URI: https://ashrafulislam.me
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
GitHub Plugin URI: https://github.com/ashrafulwpdev/Auto-Social-Publisher
Company Name: TechSwiftSoft
Company URL: https://techswiftsoft.com
Support Email: support@techswiftsoft.com
*/



// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Enqueue scripts and styles
add_action('admin_enqueue_scripts', 'auto_social_publisher_enqueue_scripts');

function auto_social_publisher_enqueue_scripts($hook_suffix) {
    if ($hook_suffix == 'settings_page_auto-social-publisher-settings') {
        // Enqueue Google Fonts Icons
        wp_enqueue_style('google-fonts-icons', 'https://fonts.googleapis.com/icon?family=Material+Icons', array(), null);
        // Enqueue plugin stylesheet
        wp_enqueue_style('auto-social-publisher-style', plugins_url('auto-social-publisher.css', __FILE__), array('google-fonts-icons'), null);
        // Enqueue plugin script with localization
        wp_enqueue_script('auto-social-publisher-script', plugins_url('auto-social-publisher.js', __FILE__), array('jquery'), null, true);
        wp_localize_script('auto-social-publisher-script', 'auto_social_publisher_ajax_object', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('auto-social-publisher-nonce')
        ));
        // Specifically hide only the "Settings updated" message
        wp_add_inline_style('auto-social-publisher-style', '#setting-error-settings_updated { display: none !important; }');
    }
}

// Handle AJAX request to update the settings without reloading the page
function save_auto_social_publisher_settings() {
    // Check nonce for security
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'auto-social-publisher-nonce')) {
        wp_send_json_error(array('message' => 'Nonce verification failed.'));
        return;
    }

    // Save each setting if present
    if (isset($_POST['enable_telegram_post'])) {
        update_option('enable_telegram_post', sanitize_text_field($_POST['enable_telegram_post']));
    } else {
        update_option('enable_telegram_post', 'no'); // Save as 'no' if not set
    }

    if (isset($_POST['enable_telegram_pin'])) {
        update_option('enable_telegram_pin', sanitize_text_field($_POST['enable_telegram_pin']));
    } else {
        update_option('enable_telegram_pin', 'no'); // Save as 'no' if not set
    }

    if (isset($_POST['wp_telegram_bot_token'])) {
        update_option('wp_telegram_bot_token', sanitize_text_field($_POST['wp_telegram_bot_token']));
    }

    if (isset($_POST['wp_telegram_chat_id'])) {
        update_option('wp_telegram_chat_id', sanitize_text_field($_POST['wp_telegram_chat_id']));
    }

    if (isset($_POST['wp_telegram_channel_username'])) {
        update_option('wp_telegram_channel_username', sanitize_text_field($_POST['wp_telegram_channel_username']));
    }

    wp_send_json_success(array('message' => 'Settings saved successfully.'));
}
add_action('wp_ajax_save_auto_social_publisher_settings', 'save_auto_social_publisher_settings');



// Handle AJAX request to get updated Telegram settings
function get_updated_telegram_settings() {
    // Check nonce for security
    check_ajax_referer('auto-social-publisher-nonce', '_ajax_nonce');

    // Get the updated settings
    $enable_telegram_post = get_option('enable_telegram_post', 'yes');
    $enable_telegram_pin = get_option('enable_telegram_pin', 'yes');

    // Send a JSON response back to the JavaScript
    wp_send_json_success(array(
        'enable_telegram_post' => $enable_telegram_post === 'yes',
        'enable_telegram_pin' => $enable_telegram_pin === 'yes',
    ));
}

// Hook for AJAX action
add_action('wp_ajax_update_telegram_settings', 'update_telegram_settings');
add_action('wp_ajax_get_updated_telegram_settings', 'get_updated_telegram_settings');

// Send post notification to Telegram on post publish
add_action('publish_post', 'auto_social_publisher_send_telegram_post_notification');

function auto_social_publisher_send_telegram_post_notification($post_id) {
    // Check if Telegram posting is enabled
    $enable_telegram_post = get_option('enable_telegram_post', 'yes');
    if ($enable_telegram_post !== 'yes') {
        return;
    }

    // Retrieve Telegram settings
    $telegram_bot_token = get_option('wp_telegram_bot_token');
    $telegram_chat_id = get_option('wp_telegram_chat_id');
    $telegram_channel_username = get_option('wp_telegram_channel_username');

    // Validate Telegram settings
    if (empty($telegram_bot_token) || (empty($telegram_chat_id) && empty($telegram_channel_username))) {
        error_log('Telegram bot token, chat ID, or channel username is not set.');
        return;
    }

    // Determine chat ID based on username or chat ID
    $chat_id = !empty($telegram_channel_username) ? '@' . $telegram_channel_username : $telegram_chat_id;

    // Fetch post details
    $post = get_post($post_id);

    // Validate post
    if (!$post || $post->post_type !== 'post' || $post->post_status !== 'publish') {
        return;
    }

    // Check if post has already been sent to Telegram
    $telegram_sent = get_post_meta($post_id, '_telegram_sent', true);
    if ($telegram_sent) {
        return;
    }

    // Prepare message data
    $title = get_the_title($post_id);
    $link = get_permalink($post_id);
    $summary = wp_trim_words($post->post_content, 25, '');
    $caption = "**New Post!**\n\n[$title]($link)\n\n$summary";
    $featured_image = get_the_post_thumbnail_url($post_id, 'full');
    if (!$featured_image) {
        $featured_image = 'default_image_url';  // Replace with your default image URL
    }

    $data = array(
        'chat_id' => $chat_id,
        'photo' => $featured_image,
        'caption' => $caption,
        'parse_mode' => 'Markdown',
        'reply_markup' => json_encode(array(
            'inline_keyboard' => array(
                array(
                    array(
                        'text' => 'Read More',
                        'url' => $link
                    )
                )
            )
        ))
    );

    // Telegram API endpoint for sending photo
    $url = 'https://api.telegram.org/bot' . $telegram_bot_token . '/sendPhoto';
    $response = wp_remote_post($url, array(
        'body' => $data
    ));

    // Handle API response
    if (is_wp_error($response)) {
        error_log('Error sending message to Telegram: ' . $response->get_error_message());
    } else {
        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);

        if (!$response_data['ok']) {
            error_log('Telegram API error: ' . $response_data['description']);
        } else {
            error_log('Message successfully sent to Telegram.');
            update_post_meta($post_id, '_telegram_sent', true);

            // Check if pinning posts on Telegram is enabled
            $enable_telegram_pin = get_option('enable_telegram_pin', 'yes');
            if ($enable_telegram_pin === 'yes') {
                auto_social_publisher_pin_post_on_telegram($telegram_bot_token, $chat_id, $response_data['result']['message_id']);
            }
        }
    }
}

// Function to pin a post on Telegram
function auto_social_publisher_pin_post_on_telegram($bot_token, $chat_id, $message_id) {
    $url = 'https://api.telegram.org/bot' . $bot_token . '/pinChatMessage';
    $data = array(
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'disable_notification' => false
    );

    $response = wp_remote_post($url, array(
        'body' => $data
    ));

    if (is_wp_error($response)) {
        error_log('Error pinning message on Telegram: ' . $response->get_error_message());
    } else {
        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);

        if (!$response_data['ok']) {
            error_log('Telegram API error while pinning message: ' . $response_data['description']);
        } else {
            error_log('Message successfully pinned on Telegram.');
            auto_social_publisher_unpin_previous_post_on_telegram($bot_token, $chat_id, $message_id);
        }
    }
}

// Function to unpin the previous post on Telegram
function auto_social_publisher_unpin_previous_post_on_telegram($bot_token, $chat_id, $current_message_id) {
    $previous_message_id = get_option('auto_social_publisher_last_pinned_message_id');

    if (!empty($previous_message_id) && $previous_message_id != $current_message_id) {
        $url = 'https://api.telegram.org/bot' . $bot_token . '/unpinChatMessage';
        $data = array(
            'chat_id' => $chat_id,
            'message_id' => $previous_message_id
        );

        $response = wp_remote_post($url, array(
            'body' => $data
        ));

        if (is_wp_error($response)) {
            error_log('Error unpinning previous message on Telegram: ' . $response->get_error_message());
        } else {
            $response_body = wp_remote_retrieve_body($response);
            $response_data = json_decode($response_body, true);

            if (!$response_data['ok']) {
                error_log('Telegram API error while unpinning previous message: ' . $response_data['description']);
            } else {
                error_log('Previous message successfully unpinned on Telegram.');
            }
        }
    }

    update_option('auto_social_publisher_last_pinned_message_id', $current_message_id);
}

// Add settings page
add_action('admin_menu', 'auto_social_publisher_add_settings_page');

function auto_social_publisher_add_settings_page() {
    add_options_page(
        'Auto Social Publisher Settings',
        'Auto Social Publisher',
        'manage_options',
        'auto-social-publisher-settings',
        'auto_social_publisher_settings_page'
    );
}

// Settings page markup
function auto_social_publisher_settings_page() {
    ?>
    <div class="wrap auto-social-publisher-settings">
        <h1>Auto Social Publisher Settings</h1>
        <form id="auto-social-publisher-form" autocomplete="off">
            <?php
            settings_fields('auto_social_publisher_options');
            do_settings_sections('auto-social-publisher-settings');
            submit_button('Update Settings');
            ?>
        </form>

        <!-- Custom Popup for Settings Saved -->
        <div class="custom-popup" id="customPopup" style="display:none;">
            <span class="close-btn" id="closePopup">&times;</span>
            <h3>Settings Updated</h3>
            <p>Your settings have been successfully saved.</p>
        </div>

        <?php
        // Display warning if Telegram posting is enabled but pinning is disabled
        if (get_option('enable_telegram_post', 'yes') === 'yes' && get_option('enable_telegram_pin', 'yes') !== 'yes') :
            ?>
            <div class="notice notice-warning" id="telegram-warning">
                <p><strong>Warning:</strong> Telegram posting is enabled, but pinning posts is disabled. Enable pinning to effectively manage pinned posts on Telegram.</p>
            </div>
        <?php endif; ?>
        
        <h2>Plugin Information</h2>
        <table class="form-table plugin-info">
            <tr valign="top">
                <th scope="row">Developed by</th>
                <td><a href="https://techswiftsoft.com" target="_blank">TechSwiftsoft</a></td>
            </tr>
            <tr valign="top">
                <th scope="row">Support Email</th>
                <td><a href="mailto:support@techswiftsoft.com">support@techswiftsoft.com</a></td>
            </tr>
        </table>

        <div class="form-table footer-section">
            <h2>Social Media Links</h2>
            <ul class="social-links">
                <li>
                    <a href="https://www.facebook.com/Techswiftsoft" target="_blank">
                        <span class="dashicons dashicons-facebook"></span>
                        Facebook
                    </a>
                </li>
                <li>
                    <a href="https://x.com/Techswiftsoft" target="_blank">
                        <span class="dashicons dashicons-twitter"></span>
                        Twitter
                    </a>
                </li>
                <li>
                    <a href="https://github.com/ashrafulwpdev" target="_blank">
                        <span class="dashicons dashicons-github-alt"></span>
                        GitHub
                    </a>
                </li>
                <li>
                    <a href="https://techswiftsoft.com/" target="_blank">
                        <span class="dashicons dashicons-admin-site"></span>
                        Website
                    </a>
                </li>
            </ul>
        </div>
    </div>
    <?php
}


// Register settings and settings sections
function auto_social_publisher_admin_init() {
    register_setting('auto_social_publisher_options', 'enable_telegram_post', 'sanitize_text_field');
    register_setting('auto_social_publisher_options', 'enable_telegram_pin', 'sanitize_text_field');
    register_setting('auto_social_publisher_options', 'wp_telegram_bot_token', 'sanitize_text_field');
    register_setting('auto_social_publisher_options', 'wp_telegram_chat_id', 'sanitize_text_field');
    register_setting('auto_social_publisher_options', 'wp_telegram_channel_username', 'sanitize_text_field');

    add_settings_section(
        'auto_social_publisher_main',
        'Telegram Settings',
        'auto_social_publisher_section_text',
        'auto-social-publisher-settings'
    );

    add_settings_field(
        'enable_telegram_post',
        'Enable Telegram Post',
        'auto_social_publisher_enable_telegram_post',
        'auto-social-publisher-settings',
        'auto_social_publisher_main'
    );

    add_settings_field(
        'enable_telegram_pin',
        'Enable Pinning Posts on Telegram',
        'auto_social_publisher_enable_telegram_pin',
        'auto-social-publisher-settings',
        'auto_social_publisher_main'
    );

    add_settings_field(
        'wp_telegram_bot_token',
        'Telegram Bot Token',
        'auto_social_publisher_setting_string',
        'auto-social-publisher-settings',
        'auto_social_publisher_main'
    );

    add_settings_field(
        'wp_telegram_chat_id',
        'Telegram Chat ID',
        'auto_social_publisher_setting_chat_id',
        'auto-social-publisher-settings',
        'auto_social_publisher_main'
    );

    add_settings_field(
        'wp_telegram_channel_username',
        'Telegram Channel Username',
        'auto_social_publisher_setting_channel_username',
        'auto-social-publisher-settings',
        'auto_social_publisher_main'
    );
}
add_action('admin_init', 'auto_social_publisher_admin_init');

// Settings section text
function auto_social_publisher_section_text() {
    echo '<p>Configure the settings for Auto Social Publisher.</p>';
}

// Checkbox for enabling Telegram post
function auto_social_publisher_enable_telegram_post() {
    $enable_telegram_post = get_option('enable_telegram_post', 'yes');
    echo "<label class='toggle-switch'><input id='enable_telegram_post' name='enable_telegram_post' type='checkbox' value='yes'" . checked($enable_telegram_post, 'yes', false) . " /><span class='toggle-slider'></span></label>";
}

// Checkbox for enabling Telegram post pinning
function auto_social_publisher_enable_telegram_pin() {
    $enable_telegram_pin = get_option('enable_telegram_pin', 'yes');
    echo "<label class='toggle-switch'><input id='enable_telegram_pin' name='enable_telegram_pin' type='checkbox' value='yes'" . checked($enable_telegram_pin, 'yes', false) . " /><span class='toggle-slider'></span></label>";
}

// Password input field for Telegram Bot Token with autocomplete disabled
function auto_social_publisher_setting_string() {
    $bot_token = get_option('wp_telegram_bot_token');
    echo '
    <div class="password-wrapper">
    <input id="wp_telegram_bot_token" name="wp_telegram_bot_token" type="password" value="' . esc_attr($bot_token) . '" class="password-input" placeholder="Enter Bot Token" autocomplete="new-password" />
    <span class="toggle-password">
    <i class="dashicons dashicons-visibility" id="toggle-bot-token-icon"></i>
    </span>
    </div>
    ';
}

// Password input field for Telegram Chat ID with autocomplete disabled
function auto_social_publisher_setting_chat_id() {
    $chat_id = get_option('wp_telegram_chat_id');
    echo '
    <div class="password-wrapper">
    <input id="wp_telegram_chat_id" name="wp_telegram_chat_id" type="password" value="' . esc_attr($chat_id) . '" class="password-input" placeholder="Enter Chat ID" autocomplete="off" />
    <span class="toggle-password">
    <i class="dashicons dashicons-visibility" id="toggle-chat-id-icon"></i>
    </span>
    </div>
    ';
}

// Password input field for Telegram Channel Username with autocomplete disabled
function auto_social_publisher_setting_channel_username() {
    $channel_username = get_option('wp_telegram_channel_username');
    echo '
    <div class="password-wrapper">
    <input id="wp_telegram_channel_username" name="wp_telegram_channel_username" type="password" value="' . esc_attr($channel_username) . '" class="password-input" placeholder="Enter Channel Username" autocomplete="off" />
    <span class="toggle-password">
    <i class="dashicons dashicons-visibility" id="toggle-channel-username-icon"></i>
    </span>
    </div>
    ';
}

// Add settings link on plugin page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'auto_social_publisher_settings_link');

function auto_social_publisher_settings_link($links) {
    $settings_link = '<a href="options-general.php?page=auto-social-publisher-settings">' . __('Settings') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

// Clear cache on plugin activation
register_activation_hook(__FILE__, 'auto_social_publisher_clear_cache_on_activation');

function auto_social_publisher_clear_cache_on_activation() {
    // Clear WordPress cache if available
    if (function_exists('wp_cache_clear_cache')) {
        wp_cache_clear_cache();
    }
}

// Clear cache on plugin deactivation
register_deactivation_hook(__FILE__, 'auto_social_publisher_clear_cache_on_deactivation');

function auto_social_publisher_clear_cache_on_deactivation() {
    // Clear WordPress cache if available
    if (function_exists('wp_cache_clear_cache')) {
        wp_cache_clear_cache();
    }
}


// Add custom information (Author, Company, Support Email) to the plugin row meta
add_filter('plugin_row_meta', 'add_custom_plugin_details', 10, 2);

function add_custom_plugin_details($links, $file) {
    // Check if we're displaying information for the correct plugin
    if (strpos($file, 'auto-social-publisher') !== false) {
        // Add author details, company name, and support email
        $new_links = array(
            'Github' => '<a href="https://github.com/ashrafulwpdev/Auto-Social-Publisher" target="_blank">Github</a>',
            'Company' => '<a href="https://techswiftsoft.com" target="_blank">TechSwiftSoft</a>',
            'Support' => '<a href="mailto:support@techswiftsoft.com">Support</a>',
        );
        // Merge the new links with the existing ones
        $links = array_merge($links, $new_links);
    }
    return $links;
}


