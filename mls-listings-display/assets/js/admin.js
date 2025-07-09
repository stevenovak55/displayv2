/**
 * Admin JavaScript for MLS Listings Display
 * - Handles the media uploader for the logo setting.
 */
jQuery(document).ready(function($) {
    'use strict';

    // Instantiates the variable that holds the media library frame.
    let metaImageFrame;

    // Runs when the "Upload Logo" button is clicked.
    $('#mld_upload_logo_button').click(function(e) {
        e.preventDefault();

        // If the frame already exists, re-open it.
        if (metaImageFrame) {
            metaImageFrame.open();
            return;
        }

        // Sets up the media library frame.
        metaImageFrame = wp.media.frames.file_frame = wp.media({
            title: 'Choose a Logo',
            button: {
                text: 'Use this logo'
            },
            multiple: false // Do not allow multiple files to be selected
        });

        // Runs when an image is selected.
        metaImageFrame.on('select', function() {
            // Grabs the attachment selection and creates a JSON representation of the model.
            const media_attachment = metaImageFrame.state().get('selection').first().toJSON();

            // Sends the attachment URL to our custom input field.
            $('#mld_logo_url').val(media_attachment.url);

            // Display the image preview
            $('#mld-logo-preview').html('<img src="' + media_attachment.url + '" style="max-width: 200px; max-height: 50px; margin-top: 10px;" />');
        });

        // Opens the media library frame.
        metaImageFrame.open();
    });
});
