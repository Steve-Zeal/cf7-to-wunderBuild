<?php
/*
Plugin Name: CF7 to Wunderbuild CRM
Description: Sends Contact Form 7 submissions to Wunderbuild CRM via API with configurable API key, endpoint, and dynamic field selection.
Version: 1.4.1
Author: Steve-Zeal
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Include WordPress core functions
require_once(ABSPATH . 'wp-includes/formatting.php');
require_once(ABSPATH . 'wp-includes/http.php');

require_once plugin_dir_path(__FILE__) . 'admin-page.php';

add_action('wpcf7_mail_sent', 'cf7twb_send_to_wunderbuild');

function cf7twb_log($message) {
    $log_file = WP_CONTENT_DIR . '/cf7-to-wunderbuild.log';
    $timestamp = current_time('Y-m-d H:i:s');
    error_log("[$timestamp] $message\n", 3, $log_file);
    error_log($message); // Also log to WordPress default log
}

function cf7twb_send_to_wunderbuild($contact_form) {
    $form_id = $contact_form->id();
    cf7twb_log('Form submission triggered - Form ID: ' . $form_id);
    
    // Check if this form is enabled for Wunderbuild integration
    $enabled_forms = get_option('cf7twb_enabled_forms', []);
    if (!in_array($form_id, $enabled_forms)) {
        cf7twb_log('Form ' . $form_id . ' is not enabled for Wunderbuild integration');
        return;
    }
    cf7twb_log('Form is enabled for Wunderbuild integration');
    
    $submission = WPCF7_Submission::get_instance();
    if (!$submission) {
        cf7twb_log('No submission instance found');
        return;
    }
    cf7twb_log('Submission instance found successfully');

    $api_key = get_option('cf7twb_api_key', '');
    $api_url = get_option('cf7twb_api_url', 'https://publicapi.wunderbuild.com/v1/leads');
    $selected_fields = get_option('cf7twb_selected_fields', []);
    
    cf7twb_log('Configuration loaded - API URL: ' . $api_url);
    cf7twb_log('Selected fields count: ' . count($selected_fields));

    if ( empty($api_key) ) {
        cf7twb_log('Error: No API key set in plugin settings');
        return;
    }
    cf7twb_log('API key found');

    $data = $submission->get_posted_data();
    if (empty($data)) {
        cf7twb_log('Error: No form data found in submission');
        return;
    }
    cf7twb_log('Form data retrieved successfully');
    
    // Get the form object to check field types
    $form_tags = $contact_form->scan_form_tags();
    $field_types = [];
    foreach ($form_tags as $tag) {
        if (!empty($tag->name)) {
            $field_types[$tag->name] = $tag->type;
        }
    }
    cf7twb_log('Form fields and types: ' . wp_json_encode($field_types));
    
    $payload = [];

    // Define field mappings - these can be configured via options if needed
    $field_mappings = [
        'firstName' => [
            'name_ci',
            'first-name',
            'firstname',
            'fname',
            'first_name',
            'name',
            'your-name'
        ],
        'lastName' => [
            'last_ci',
            'last-name',
            'lastname',
            'lname',
            'last_name',
            'your-last-name',
            'surname'
        ]
    ];

    // Get custom field mappings from options
    $custom_mappings = get_option('cf7twb_field_mappings', []);
    if (!empty($custom_mappings)) {
        foreach ($custom_mappings as $wunderbuild_field => $cf7_fields) {
            if (!isset($field_mappings[$wunderbuild_field])) {
                $field_mappings[$wunderbuild_field] = [];
            }
            if (is_array($cf7_fields)) {
                $field_mappings[$wunderbuild_field] = array_merge($field_mappings[$wunderbuild_field], $cf7_fields);
            } else {
                $field_mappings[$wunderbuild_field][] = $cf7_fields;
            }
        }
    }
    
    foreach ($selected_fields as $field) {
        if (isset($data[$field])) {
            $value = $data[$field];
            
            // Handle arrays (checkboxes, multi-select)
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            
            // Make sure we're not sending empty values
            if ($value !== '') {
                $mapped = false;
                
                // Check if this field maps to a Wunderbuild field
                foreach ($field_mappings as $wunderbuild_field => $possible_names) {
                    if (in_array($field, $possible_names)) {
                        $payload[$wunderbuild_field] = sanitize_text_field($value);
                        $mapped = true;
                        break;
                    }
                }
                
                // If no mapping found, use the original field name
                if (!$mapped) {
                    $payload[$field] = sanitize_text_field($value);
                }
                cf7twb_log("Field '$field' (" . ($field_types[$field] ?? 'unknown type') . ") value: " . $value);
            }
        }
    }

    // Log the payload being sent
    cf7twb_log('Sending payload: ' . wp_json_encode($payload));
    cf7twb_log('API URL: ' . $api_url);
    cf7twb_log('Selected fields: ' . wp_json_encode($selected_fields));
    cf7twb_log('Raw form data: ' . wp_json_encode($data));

    $response = wp_remote_post($api_url, [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json'
        ],
        'body'    => wp_json_encode($payload),
        'timeout' => 15
    ]);

    if ( is_wp_error($response) ) {
        cf7twb_log('Wunderbuild API Error: ' . $response->get_error_message());
    } else {
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        cf7twb_log('CF7 to Wunderbuild - Response Code: ' . $response_code);
        cf7twb_log('CF7 to Wunderbuild - Response Body: ' . $response_body);
        
        // Log response headers for debugging
        $headers = wp_remote_retrieve_headers($response);
        cf7twb_log('CF7 to Wunderbuild - Response Headers: ' . json_encode($headers));
    }
}
