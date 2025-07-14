<div class="wrap">
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
    <form action="options.php" method="post">
        <?php
        settings_fields( 'mld_options_group' );
        do_settings_sections( 'mld_options_group' );
        submit_button( 'Save Settings' );
        ?>
    </form>
</div>
