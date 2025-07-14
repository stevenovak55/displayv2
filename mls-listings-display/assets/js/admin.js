/**
 * Admin JavaScript for MLS Listings Display
 * - REFACTOR: Generalizes the media uploader to work with any button that has the `.mld-upload-button` class and corresponding data attributes.
 * - This allows it to be used for the main logo and the new dynamic icon manager.
 */
jQuery(document).ready(function($) {
    'use strict';

    let mediaFrame;

    // Use event delegation for dynamically added buttons
    $(document).on('click', '.mld-upload-button', function(e) {
        e.preventDefault();

        const $button = $(this);
        const targetInput = $button.data('target-input');
        const targetPreview = $button.data('target-preview');

        // If the frame already exists, re-open it.
        if (mediaFrame) {
            mediaFrame.open();
            return;
        }

        // Sets up the media library frame.
        mediaFrame = wp.media.frames.file_frame = wp.media({
            title: 'Choose an Icon or Logo',
            button: {
                text: 'Use this image'
            },
            multiple: false // Do not allow multiple files to be selected
        });

        // Runs when an image is selected.
        mediaFrame.on('select', function() {
            const attachment = mediaFrame.state().get('selection').first().toJSON();

            // Send the attachment URL to the target input field.
            $(targetInput).val(attachment.url);

            // Display the image preview.
            $(targetPreview).html('<img src="' + attachment.url + '" />');
        });

        // Opens the media library frame.
        mediaFrame.open();
    });
});
