<?php
/*
Plugin Name: Auto Social Publisher
Description: Automatically publishes new and updated posts to Telegram with enhanced features.
Version: 1.0.5
Author: TechSwiftsoft.com
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Hook into the post publish event.
add_action('save_post', 'auto_social_publisher_send_telegram_post_notification', 10, 2);

function auto_social_publisher_send_telegram_post_notification($post_id, $post) {
    // Check if this is an AMP request and return early if so.
    if (function_exists('is_amp_endpoint') && is_amp_endpoint()) {
        return;
    }

    // Check if posting to Telegram is enabled via admin settings.
    $enable_telegram_post = get_option('enable_telegram_post', 'yes');
    if ($enable_telegram_post !== 'yes') {
        return; // Stop execution if Telegram posting is disabled.
    }

    // Determine if this is an update or a new post.
    $is_update = wp_is_post_revision($post_id);
    $post_status = get_post_status($post_id);

    // Get the last modified time of the post.
    $last_modified = get_post_modified_time('U', true, $post_id);

    // Get the timestamp when the last notification was sent (if any).
    $last_sent_time = get_post_meta($post_id, '_telegram_last_sent_time', true);

    // Check if the post is being updated and skip if the post hasn't been modified.
    if ($is_update || ($last_sent_time && $last_modified <= $last_sent_time)) {
        return;
    }

    // Get Telegram settings from options.
    $telegram_bot_token = get_option('wp_telegram_bot_token');
    $telegram_chat_id = get_option('wp_telegram_chat_id');
    $telegram_channel_username = get_option('wp_telegram_channel_username');

    if (empty($telegram_bot_token) || (empty($telegram_chat_id) && empty($telegram_channel_username))) {
        error_log('Telegram bot token, chat ID, or channel username is not set.');
        return;
    }

    // Determine the chat ID based on the settings.
    $chat_id = !empty($telegram_channel_username) ? '@' . $telegram_channel_username : $telegram_chat_id;

    // Prepare post content.
    $title = get_the_title($post_id);
    $link = get_permalink($post_id);
    $summary = wp_trim_words($post->post_content, 25, '');

    // Determine the type of post (new or updated) and set the appropriate emoji and caption.
    $caption = '';
    if (!$is_update && $post_status === 'publish') {
        $caption = "🆕 **New Post!**\n\n[" . $title . "](" . $link . ")\n\n" . $summary;
    } elseif ($is_update && $post_status === 'publish') {
        $caption = "🔄 **Updated Post!**\n\n[" . $title . "](" . $link . ")\n\n" . $summary;
    } else {
        return; // Skip if the post status is not publish (e.g., draft, private)
    }

    // Get the featured image.
    $featured_image = get_the_post_thumbnail_url($post_id, 'full');
    if (!$featured_image) {
        $featured_image = 'default_image_url'; // Optional: Set a default image URL if no featured image is found.
    }

    // Prepare data for Telegram API.
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

    // Telegram API endpoint URL.
    $url = 'https://api.telegram.org/bot' . $telegram_bot_token . '/sendPhoto';

    // Send the message to Telegram.
    $response = wp_remote_post($url, array(
        'body' => $data
    ));

    if (is_wp_error($response)) {
        error_log('Error sending message to Telegram: ' . $response->get_error_message());
    } else {
        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);

        if (!$response_data['ok']) {
            error_log('Telegram API error: ' . $response_data['description']);
        } else {
            // Update the last sent time meta to the current time.
            update_post_meta($post_id, '_telegram_last_sent_time', current_time('timestamp'));
            error_log('Message successfully sent to Telegram.');

            // Pin this post on Telegram channel.
            auto_social_publisher_pin_post_on_telegram($telegram_bot_token, $chat_id, $response_data['result']['message_id']);
        }
    }
}

// Function to pin a post on Telegram channel.
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

            // Unpin the previously pinned post, if any.
            auto_social_publisher_unpin_previous_post_on_telegram($bot_token, $chat_id, $message_id);
        }
    }
}

// Function to unpin the previously pinned post on Telegram channel.
function auto_social_publisher_unpin_previous_post_on_telegram($bot_token, $chat_id, $current_message_id) {
    // Get the previously pinned message ID from post meta.
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

    // Update the last pinned message ID option with the current message ID.
    update_option('auto_social_publisher_last_pinned_message_id', $current_message_id);
}

// Add submenu page under Settings for Auto Social Publisher.
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

function auto_social_publisher_settings_page() {
    ?>
    <div class="wrap">
        <h1>Auto Social Publisher Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('auto_social_publisher_settings');
            do_settings_sections('auto_social_publisher_settings');
            submit_button('Save Settings');
            ?>
        </form>
    </div>
    <?php
}

// Register settings and fields for the Auto Social Publisher Settings page.
add_action('admin_init', 'auto_social_publisher_settings_init');

function auto_social_publisher_settings_init() {
    // Register settings.
    register_setting('auto_social_publisher_settings', 'enable_telegram_post');
    register_setting('auto_social_publisher_settings', 'wp_telegram_bot_token');
    register_setting('auto_social_publisher_settings', 'wp_telegram_chat_id');
    register_setting('auto_social_publisher_settings', 'wp_telegram_channel_username');

    // Add settings section.
    add_settings_section(
        'auto_social_publisher_general_settings_section',
        'General Settings',
        'auto_social_publisher_general_settings_section_callback',
        'auto_social_publisher_settings'
    );

    // Add fields.
    add_settings_field(
        'enable_telegram_post',
        'Enable Telegram Auto Post',
        'enable_telegram_post_field_callback',
        'auto_social_publisher_settings',
        'auto_social_publisher_general_settings_section'
    );

    add_settings_field(
        'wp_telegram_bot_token',
        'Telegram Bot Token',
        'wp_telegram_bot_token_field_callback',
        'auto_social_publisher_settings',
        'auto_social_publisher_general_settings_section'
    );

    add_settings_field(
        'wp_telegram_chat_id',
        'Telegram Chat ID',
        'wp_telegram_chat_id_field_callback',
        'auto_social_publisher_settings',
        'auto_social_publisher_general_settings_section'
    );

    add_settings_field(
        'wp_telegram_channel_username',
        'Telegram Channel Username',
        'wp_telegram_channel_username_field_callback',
        'auto_social_publisher_settings',
        'auto_social_publisher_general_settings_section'
    );
}

// Callback functions for settings fields.
function enable_telegram_post_field_callback() {
    $enable_telegram_post = get_option('enable_telegram_post', 'yes');
    echo '<input type="checkbox" id="enable_telegram_post" name="enable_telegram_post" value="yes" ' . checked('yes', $enable_telegram_post, false) . ' />';
    echo '<label for="enable_telegram_post">Enable auto posting to Telegram</label>';
}

function wp_telegram_bot_token_field_callback() {
    $token = get_option('wp_telegram_bot_token');
    echo '<input type="text" id="wp_telegram_bot_token" name="wp_telegram_bot_token" value="' . esc_attr($token) . '" />';
    echo '<p class="description">Enter your Telegram bot token here.</p>';
}

function wp_telegram_chat_id_field_callback() {
    $chat_id = get_option('wp_telegram_chat_id');
    echo '<input type="text" id="wp_telegram_chat_id" name="wp_telegram_chat_id" value="' . esc_attr($chat_id) . '" />';
    echo '<p class="description">Enter your Telegram chat ID here.</p>';
}

function wp_telegram_channel_username_field_callback() {
    $channel_username = get_option('wp_telegram_channel_username');
    echo '<input type="text" id="wp_telegram_channel_username" name="wp_telegram_channel_username" value="' . esc_attr($channel_username) . '" />';
    echo '<p class="description">Enter your Telegram channel username (without @) here.</p>';
}

function auto_social_publisher_general_settings_section_callback() {
    echo '<p>Configure settings for the Auto Social Publisher plugin.</p>';
}
