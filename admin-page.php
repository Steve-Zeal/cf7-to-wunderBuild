<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action('admin_menu', 'cf7twb_admin_menu');
function cf7twb_admin_menu() {
    add_options_page(
        'CF7 to Wunderbuild',
        'CF7 to Wunderbuild',
        'manage_options',
        'cf7-to-wunderbuild',
        'cf7twb_settings_page'
    );
}

add_action('admin_init', 'cf7twb_register_settings');
function cf7twb_register_settings() {
    register_setting('cf7twb_settings_group', 'cf7twb_api_key');
    register_setting('cf7twb_settings_group', 'cf7twb_api_url');
    register_setting('cf7twb_settings_group', 'cf7twb_selected_fields');
    register_setting('cf7twb_settings_group', 'cf7twb_enabled_forms');
    register_setting('cf7twb_settings_group', 'cf7twb_field_mappings');
}

function cf7twb_get_all_cf7_fields() {
    if ( ! class_exists('WPCF7_ContactForm') ) {
        return [];
    }

    $fields = [];
    $forms = WPCF7_ContactForm::find();

    foreach ( $forms as $form ) {
        $form_instance = WPCF7_ContactForm::get_instance( $form->id() );
        $tags = $form_instance->scan_form_tags();

        foreach ( $tags as $tag ) {
            if ( ! empty( $tag->name ) && ! in_array( $tag->name, $fields ) ) {
                $fields[] = $tag->name;
            }
        }
    }

    return $fields;
}

function cf7twb_get_all_forms() {
    if (!class_exists('WPCF7_ContactForm')) {
        return [];
    }
    return WPCF7_ContactForm::find();
}

function cf7twb_settings_page() {
    $available_fields = cf7twb_get_all_cf7_fields();
    $selected_fields = get_option('cf7twb_selected_fields', $available_fields);
    $enabled_forms = get_option('cf7twb_enabled_forms', []);
    $all_forms = cf7twb_get_all_forms();
    ?>
    <div class="wrap">
        <h1>CF7 to Wunderbuild CRM Settings</h1>
        <p>Leads will appear in <a href="https://app.wunderbuild.com/leads" target="_blank">Wunderbuild Leads</a></p>
        <form method="post" action="options.php">
            <?php settings_fields('cf7twb_settings_group'); ?>
            <?php do_settings_sections('cf7twb_settings_group'); ?>

            <table class="form-table">
                <tr valign="top">
                    <th scope="row">API Key</th>
                    <td>
                        <input type="text" name="cf7twb_api_key" value="<?php echo esc_attr(get_option('cf7twb_api_key')); ?>" style="width: 400px;">
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">API URL</th>
                    <td>
                        <input type="text" name="cf7twb_api_url" value="<?php echo esc_attr(get_option('cf7twb_api_url', 'https://publicapi.wunderbuild.com/v1/leads')); ?>" style="width: 400px;">
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Enable for Forms</th>
                    <td>
                        <?php if (empty($all_forms)) : ?>
                            <p style="color: red;">No Contact Form 7 forms found. Please create at least one form first.</p>
                        <?php else : ?>
                            <?php foreach ($all_forms as $form) : ?>
                                <label>
                                    <input type="checkbox" name="cf7twb_enabled_forms[]" 
                                           value="<?php echo esc_attr($form->id()); ?>" 
                                           <?php checked(in_array($form->id(), $enabled_forms)); ?>>
                                    <?php echo esc_html($form->title()); ?>
                                </label><br>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <p class="description">Select which forms should send data to Wunderbuild</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Fields to Send</th>
                    <td>
                        <?php if (empty($available_fields)) : ?>
                            <p style="color: red;">No Contact Form 7 fields found. Make sure CF7 is installed and you have at least one form.</p>
                        <?php else : ?>
                            <?php foreach ($available_fields as $field_name) : ?>
                                <label>
                                    <input type="checkbox" name="cf7twb_selected_fields[]" 
                                           value="<?php echo esc_attr($field_name); ?>" 
                                           <?php checked(in_array($field_name, $selected_fields)); ?>>
                                    <?php echo esc_html($field_name); ?>
                                </label><br>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <p class="description">Select which form fields should be sent to Wunderbuild</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Custom Field Mappings</th>
                    <td>
                        <div id="field-mappings">
                            <?php 
                            $field_mappings = get_option('cf7twb_field_mappings', []);
                            if (!empty($field_mappings)) {
                                foreach ($field_mappings as $wunderbuild_field => $cf7_fields) {
                                    ?>
                                    <div class="mapping-row">
                                        <input type="text" 
                                               name="cf7twb_field_mappings[wunderbuild_fields][]" 
                                               value="<?php echo esc_attr($wunderbuild_field); ?>" 
                                               placeholder="Wunderbuild Field Name">
                                        <input type="text" 
                                               name="cf7twb_field_mappings[cf7_fields][]" 
                                               value="<?php echo esc_attr(is_array($cf7_fields) ? implode(',', $cf7_fields) : $cf7_fields); ?>" 
                                               placeholder="CF7 Field Name(s)">
                                        <button type="button" class="button remove-mapping">Remove</button>
                                    </div>
                                    <?php
                                }
                            }
                            ?>
                        </div>
                        <button type="button" class="button" id="add-mapping">Add Field Mapping</button>
                        <p class="description">Map your Contact Form 7 field names to Wunderbuild field names. For multiple CF7 fields, separate them with commas.</p>
                        <p class="description">Example: firstName = name_ci,your-first-name,fname</p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#add-mapping').on('click', function() {
                var row = $('<div class="mapping-row">' +
                    '<input type="text" name="cf7twb_field_mappings[wunderbuild_fields][]" placeholder="Wunderbuild Field Name">' +
                    '<input type="text" name="cf7twb_field_mappings[cf7_fields][]" placeholder="CF7 Field Name(s)">' +
                    '<button type="button" class="button remove-mapping">Remove</button>' +
                    '</div>');
                $('#field-mappings').append(row);
            });

            $(document).on('click', '.remove-mapping', function() {
                $(this).closest('.mapping-row').remove();
            });
        });
        </script>
        <style>
        .mapping-row {
            margin-bottom: 10px;
        }
        .mapping-row input {
            margin-right: 10px;
            width: 200px;
        }
        </style>
    </div>
    <?php
}
