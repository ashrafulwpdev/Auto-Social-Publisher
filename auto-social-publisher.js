jQuery(document).ready(function($) {
    // Initial setup for Enable Telegram Post toggle state
    var telegramPostToggleSlider = $('#enable_telegram_post').closest('label').find('.toggle-slider');
    var telegramPinToggleSlider = $('#enable_telegram_pin').closest('label').find('.toggle-slider');

    // Update initial state of toggle sliders
    updateToggleState($('#enable_telegram_post').prop('checked'), telegramPostToggleSlider);
    updateToggleState($('#enable_telegram_pin').prop('checked'), telegramPinToggleSlider);
    
    toggleFields(); // Set initial field visibility

    // Toggle switch functionality for Enable Telegram Post
    $('#enable_telegram_post').on('change', function() {
        updateToggleState($(this).prop('checked'), telegramPostToggleSlider);
        toggleFields(); // Update visibility of related fields
    });

    // Toggle switch functionality for Enable Pinning Posts
    $('#enable_telegram_pin').on('change', function() {
        updateToggleState($(this).prop('checked'), telegramPinToggleSlider);
        toggleFields(); // Update visibility of the warning
    });

    // Function to update toggle switch appearance using CSS class
    function updateToggleState(checked, sliderElement) {
        if (checked) {
            sliderElement.addClass('active');
        } else {
            sliderElement.removeClass('active');
        }
    }

    // Function to toggle fields visibility based on Enable Telegram Post
    function toggleFields() {
        var enableTelegramPost = $('#enable_telegram_post').is(':checked');
        var enableTelegramPin = $('#enable_telegram_pin').is(':checked');
        
        var fieldsToShow = ['#wp_telegram_bot_token', '#wp_telegram_chat_id', '#wp_telegram_channel_username'];

        fieldsToShow.forEach(function(selector) {
            $(selector).closest('tr').toggle(enableTelegramPost);
        });

        // Also toggle visibility of the pinning option
        $('#enable_telegram_pin').closest('tr').toggle(enableTelegramPost);

        // Show or hide the warning based on conditions
        if (enableTelegramPost && !enableTelegramPin) {
            $('#telegram-warning').show();
        } else {
            $('#telegram-warning').hide();
        }
    }

    // Toggle password visibility
    $('.toggle-password i').click(function() {
        var input = $(this).closest('.password-wrapper').find('input');
        if (input.attr('type') === 'password') {
            input.attr('type', 'text');
            $(this).removeClass('dashicons-visibility').addClass('dashicons-hidden');
        } else {
            input.attr('type', 'password');
            $(this).removeClass('dashicons-hidden').addClass('dashicons-visibility');
        }
    });

    // Function to show the custom popup
    function showCustomPopup() {
        $('#customPopup').fadeIn(300);

        // Auto-hide popup after 3 seconds
        setTimeout(function() {
            $('#customPopup').fadeOut(300);
        }, 3000);
    }

    // AJAX form submission to save settings and update UI without refresh
    $('form#auto-social-publisher-form').on('submit', function(event) {
        event.preventDefault(); // Prevent default form submission

        var formData = $(this).serialize(); // Serialize form data
        var $submitButton = $(this).find('button[type="submit"]'); // Locate submit button

        // Add loading state to button
        $submitButton.prop('disabled', true).addClass('loading');

        // Send AJAX request to save settings
        $.ajax({
            url: auto_social_publisher_ajax_object.ajax_url,
            type: 'POST',
            data: formData + '&action=save_auto_social_publisher_settings&nonce=' + auto_social_publisher_ajax_object.nonce,
            success: function(response) {
                // Show the custom popup
                showCustomPopup();

                // Update the UI dynamically
                toggleFields(); // Refresh the state of fields based on the new settings

                // Remove loading state and enable the button
                $submitButton.prop('disabled', false).removeClass('loading');

                // Log success message
                console.log('Settings saved successfully:', response);
            },
            error: function(xhr, status, error) {
                console.log('Error saving settings:', error);

                // Remove loading state and enable the button on error
                $submitButton.prop('disabled', false).removeClass('loading');
            }
        });
    });

    // Close the popup when the close button is clicked
    $('#closePopup').click(function() {
        $('#customPopup').fadeOut(300);
    });
});
