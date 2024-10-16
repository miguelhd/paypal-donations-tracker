<?php

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class PayPal_Donations_Tracker {

    // Constructor method
    public function __construct() {
        // Initialization code
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_shortcode('paypal_donations_form', [$this, 'donations_form_shortcode']);
        add_shortcode('paypal_donations_progress', [$this, 'donations_progress_shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_styles']);

        // Register the webhook listener
        add_action('rest_api_init', [$this, 'register_webhook_listener']);

        // Output the modal in the footer
        add_action('wp_footer', [$this, 'output_donation_modal']);
    }

    // Method to run on plugin activation
    public static function activate() {
        global $wpdb;
        $donations_table = $wpdb->prefix . 'donations';
        $temporary_table = $wpdb->prefix . 'donations_temp';
        $charset_collate = $wpdb->get_charset_collate();

        // Logging function
        if (!function_exists('write_log')) {
            function write_log($log) {
                if (true === WP_DEBUG) {
                    if (is_array($log) || is_object($log)) {
                        error_log(print_r($log, true));
                    } else {
                        error_log($log);
                    }
                }
            }
        }

        write_log('Activating PayPal Donations Tracker plugin...');
        write_log('Creating or updating database tables.');

        // Create or update the main donations table
        $donations_sql = "CREATE TABLE $donations_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            transaction_id varchar(255) NOT NULL UNIQUE,
            amount decimal(10, 2) NOT NULL,
            currency varchar(10) NOT NULL,
            donor_name varchar(255) NOT NULL,
            donor_email varchar(255) NOT NULL,
            donor_address text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        // Create or update the temporary storage table
        $temp_sql = "CREATE TABLE $temporary_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            order_id varchar(255) NOT NULL UNIQUE,
            donor_name varchar(255),
            donor_email varchar(255),
            donor_address text,
            amount decimal(10, 2),
            currency varchar(10),
            status varchar(50) DEFAULT 'pending',
            capture_data text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // Attempt to create or update the donations table
        $donations_result = dbDelta($donations_sql);
        write_log('Donations table creation result:');
        write_log($donations_result);

        // Attempt to create or update the temporary table
        $temp_result = dbDelta($temp_sql);
        write_log('Temporary donations table creation result:');
        write_log($temp_result);

        // Check if tables exist
        if ($wpdb->get_var("SHOW TABLES LIKE '$donations_table'") != $donations_table) {
            write_log("Error: Donations table '$donations_table' not found after activation.");
        } else {
            write_log("Success: Donations table '$donations_table' exists.");
        }

        if ($wpdb->get_var("SHOW TABLES LIKE '$temporary_table'") != $temporary_table) {
            write_log("Error: Temporary table '$temporary_table' not found after activation.");
        } else {
            write_log("Success: Temporary table '$temporary_table' exists.");
        }

        // Update the version number of the database schema
        $current_version = '1.5'; // Incremented version
        update_option('paypal_donations_tracker_db_version', $current_version);
        write_log("Database version updated to $current_version.");
    }

    // Method to run on plugin deactivation
    public static function deactivate() {
        // Placeholder for deactivation logic, if needed
        // Flush rewrite rules
        flush_rewrite_rules();
    }

    // Method to initialize the plugin
    public static function init() {
        // Ensure only one instance of the class is created
        static $instance = null;
        if (is_null($instance)) {
            $instance = new self();
        }
    }

    // Method to add an admin menu
    public function add_admin_menu() {
        $parent_slug = 'paypal-donations-tracker';
        add_menu_page(
            'Configuración del módulo de donaciones',
            'Módulo de donaciones',
            'manage_options',
            $parent_slug,
            [$this, 'settings_page'],
            'dashicons-heart'
        );

        add_submenu_page(
            $parent_slug,
            'Configuración',
            'Configuración',
            'manage_options',
            'paypal-donations-tracker-settings',
            [$this, 'settings_page']
        );

        add_submenu_page(
            $parent_slug,
            'Donaciones',
            'Donaciones',
            'manage_options',
            'paypal-donations-tracker-donations-list',
            [$this, 'donations_list_page']
        );
    }

    // Method to register plugin settings
    public function register_settings() {
        // General settings
        register_setting('paypal_donations_tracker', 'donations_goal', [
            'sanitize_callback' => 'intval',
            'default' => 1000,
        ]);
        register_setting('paypal_donations_tracker', 'paypal_hosted_button_id');
        register_setting('paypal_donations_tracker', 'cta_paragraph', 'sanitize_textarea_field');
        register_setting('paypal_donations_tracker', 'number_of_donations', [
            'default' => 0,
            'sanitize_callback' => 'intval',
        ]);
        register_setting('paypal_donations_tracker', 'total_amount_raised', [
            'default' => 0,
            'sanitize_callback' => 'floatval',
        ]);

        // Display settings
        register_setting('paypal_donations_tracker', 'show_amount_raised', [
            'default' => '1',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('paypal_donations_tracker', 'show_percentage_of_goal', [
            'default' => '1',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('paypal_donations_tracker', 'show_number_of_donations', [
            'default' => '1',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('paypal_donations_tracker', 'show_cta_paragraph', [
            'default' => '1',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('paypal_donations_tracker', 'content_alignment', [
            'default' => 'center',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('paypal_donations_tracker', 'progress_bar_color', [
            'default' => '#28a745',
            'sanitize_callback' => 'sanitize_hex_color',
        ]);
        register_setting('paypal_donations_tracker', 'progress_bar_height', [
            'default' => 20,
            'sanitize_callback' => 'intval',
        ]);
        register_setting('paypal_donations_tracker', 'progress_bar_well_color', [
            'default' => '#e9ecef',
            'sanitize_callback' => 'sanitize_hex_color',
        ]);
        register_setting('paypal_donations_tracker', 'progress_bar_well_width', [
            'default' => 100,
            'sanitize_callback' => 'intval',
        ]);
        register_setting('paypal_donations_tracker', 'progress_bar_border_radius', [
            'default' => 0,
            'sanitize_callback' => 'intval',
        ]);
        register_setting('paypal_donations_tracker', 'donations_text_color', [
            'default' => '#333333', // Default text color (dark gray)
            'sanitize_callback' => 'sanitize_hex_color',
        ]);

        // PayPal API settings
        register_setting('paypal_donations_tracker', 'paypal_client_id');
        register_setting('paypal_donations_tracker', 'paypal_client_secret');
        register_setting('paypal_donations_tracker', 'paypal_webhook_id');
        register_setting('paypal_donations_tracker', 'paypal_environment', [
            'default' => 'sandbox',
            'sanitize_callback' => 'sanitize_text_field',
        ]);

        // PayPal fee settings
        register_setting('paypal_donations_tracker', 'paypal_fee_percentage', [
            'default' => '2.9',  // Default PayPal fee percentage
            'sanitize_callback' => 'floatval',
        ]);
        register_setting('paypal_donations_tracker', 'paypal_fixed_fee', [
            'default' => '0.30',  // Default PayPal fixed fee
            'sanitize_callback' => 'floatval',
        ]);

        // Donate Button customization settings
        register_setting('paypal_donations_tracker', 'donate_button_label', [
            'default' => 'Donate Now',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('paypal_donations_tracker', 'donate_button_color', [
            'default' => '#0077cc',
            'sanitize_callback' => 'sanitize_hex_color',
        ]);
        register_setting('paypal_donations_tracker', 'donate_button_border_radius', [
            'default' => 5,
            'sanitize_callback' => 'intval',
        ]);
        register_setting('paypal_donations_tracker', 'donate_button_width', [
            'default' => 100,
            'sanitize_callback' => 'intval',
        ]);
        register_setting('paypal_donations_tracker', 'donate_button_height', [
            'default' => 40,
            'sanitize_callback' => 'intval',
        ]);
    }

    // Method to register the webhook listener
    public function register_webhook_listener() {
        // Register the REST route for the webhook
        register_rest_route('paypal_donations_tracker/v1', '/webhook/', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_webhook'],
            'permission_callback' => '__return_true', // Allow access to the endpoint
        ]);
    }

    // Method to handle webhook
    public function handle_webhook(WP_REST_Request $request) {
    $body = $request->get_body();
    $headers = $request->get_headers();

    // Verify the webhook signature
    $verification_result = $this->verify_webhook_signature($body, $headers);
    if (!$verification_result) {
        error_log('Webhook signature verification failed.');
        return new WP_Error('invalid_signature', 'Invalid webhook signature', ['status' => 400]);
    }

    $event = json_decode($body);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('Invalid JSON body: ' . json_last_error_msg());
        return new WP_Error('invalid_json', 'Invalid JSON body', ['status' => 400]);
    }

    // Log the full resource for debugging
    error_log(print_r($event->resource, true));

    // Handle the different event types
    switch ($event->event_type) {
        case 'PAYMENT.CAPTURE.COMPLETED':
        case 'PAYMENT.AUTHORIZATION.CREATED':
        case 'CHECKOUT.ORDER.APPROVED':
            $this->handle_webhook_event($event->event_type, $event->resource);
            break;
        default:
            error_log('Unhandled event type: ' . $event->event_type);
            return new WP_Error('unhandled_event', 'Unhandled event type', ['status' => 400]);
    }

    return new WP_REST_Response(['status' => 'success'], 200);
}

    // Method to handle webhook event
    private function handle_webhook_event($event_type, $resource) {
        global $wpdb;
        $temp_table = $wpdb->prefix . 'donations_temp';  // Temporary table for storing data
        $donations_table = $wpdb->prefix . 'donations';  // Final donations table

        // Handle CHECKOUT.ORDER.APPROVED event
        if ($event_type === 'CHECKOUT.ORDER.APPROVED') {
            $order_id = $resource->id;

            // Extract donor details from the event
            $payer = $resource->payer ?? null;
            $donorName = 'N/A';
            $donorEmail = 'N/A';
            $donorAddress = '';

            if ($payer) {
                $donorName = isset($payer->name->given_name) && isset($payer->name->surname)
                    ? $payer->name->given_name . ' ' . $payer->name->surname
                    : 'N/A';
                $donorEmail = $payer->email_address ?? 'N/A';
                $donorAddressArray = [
                    'address_line_1' => $resource->purchase_units[0]->shipping->address->address_line_1 ?? '',
                    'admin_area_2'   => $resource->purchase_units[0]->shipping->address->admin_area_2 ?? '',
                    'admin_area_1'   => $resource->purchase_units[0]->shipping->address->admin_area_1 ?? '',
                    'postal_code'    => $resource->purchase_units[0]->shipping->address->postal_code ?? '',
                    'country_code'   => $resource->purchase_units[0]->shipping->address->country_code ?? '',
                ];
                $donorAddress = serialize($donorAddressArray);
            }

            // Extract the amount and currency from the event
            $amount = $resource->purchase_units[0]->amount->value ?? 0;
            $currency = $resource->purchase_units[0]->amount->currency_code ?? 'USD';

            // Insert or update the donor details into the temporary table
            $wpdb->replace(
                $temp_table,
                [
                    'order_id'      => $order_id,
                    'donor_name'    => $donorName,
                    'donor_email'   => $donorEmail,
                    'donor_address' => $donorAddress,
                    'amount'        => $amount,
                    'currency'      => $currency,
                    'status'        => 'pending',
                    'created_at'    => current_time('mysql'),
                ]
            );

            error_log('Donor details and amount saved temporarily for order: ' . $order_id);

            // Check if capture data is already saved for this order
            $temp_data = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM $temp_table WHERE order_id = %s", $order_id),
                ARRAY_A
            );

            if (!empty($temp_data['capture_data'])) {
                // Unserialize the capture data
                $capture_resource = maybe_unserialize($temp_data['capture_data']);

                // Proceed to process the donation
                $this->process_donation($temp_data, $capture_resource);
            }
        }

        // Handle PAYMENT.CAPTURE.COMPLETED event
        if ($event_type === 'PAYMENT.CAPTURE.COMPLETED') {
            $order_id = $resource->supplementary_data->related_ids->order_id;

            // Fetch the donor info from the temporary table
            $donor_info = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM $temp_table WHERE order_id = %s", $order_id),
                ARRAY_A
            );

            if (!$donor_info) {
                // Save the capture data temporarily using order_id as the key
                $wpdb->replace(
                    $temp_table,
                    [
                        'order_id'     => $order_id,
                        'capture_data' => maybe_serialize($resource),
                        'status'       => 'pending_capture',
                        'created_at'   => current_time('mysql'),
                    ]
                );
                error_log('Capture data saved temporarily for order: ' . $order_id);
                return;
            }

            // Proceed to process the donation
            $this->process_donation($donor_info, $resource);
        }
    }

    // Save temporary donor info
    private function save_temporary_donor_info($order_id, $donor_name, $donor_email, $donor_address, $amount, $currency, $status = 'pending') {
        global $wpdb;
        $temp_table = $wpdb->prefix . 'donations_temp';

        // Log the details before saving
        error_log('Saving temporary donor info:');
        error_log('Order ID: ' . $order_id);
        error_log('Donor Name: ' . $donor_name);
        error_log('Donor Email: ' . $donor_email);
        error_log('Amount: ' . $amount);
        error_log('Currency: ' . $currency);
        error_log('Status: ' . $status);

        // Insert donor info, amount, currency, and status into the temporary table
        $wpdb->insert($temp_table, [
            'order_id' => $order_id,
            'donor_name' => sanitize_text_field($donor_name),
            'donor_email' => sanitize_email($donor_email),
            'donor_address' => maybe_serialize($donor_address),
            'amount' => floatval($amount),
            'currency' => sanitize_text_field($currency),
            'status' => sanitize_text_field($status), // Set the status to 'approved' for CHECKOUT.ORDER.APPROVED
            'created_at' => current_time('mysql'),
        ]);

        // Log after saving
        if ($wpdb->last_error) {
            error_log('Error saving temporary donor info: ' . $wpdb->last_error);
        } else {
            error_log('Temporary donor info saved successfully for order: ' . $order_id);
        }
    }

    // Retrieve temporary donor info by order ID during PAYMENT.CAPTURE.COMPLETED
    private function get_temporary_donor_info($order_id) {
        global $wpdb;
        $temp_table = $wpdb->prefix . 'donations_temp';

        
        // Log SQL query
        error_log('Order ID being queried: ' . $order_id);
        error_log('Order ID length: ' . strlen($order_id));
        error_log('Query for retrieving temp data: ' . $wpdb->prepare("SELECT * FROM $temp_table WHERE order_id = %s", $order_id));

        // Retrieve donor info from the temporary table
        $result = $wpdb->get_row($wpdb->prepare("SELECT * FROM $temp_table WHERE order_id = %s", $order_id), ARRAY_A);

        // Log the retrieved data for debugging
        error_log('Temporary donor info retrieved: ' . print_r($result, true));


        return $result ? $result : null;
    }


    // Remove temporary donor info after PAYMENT.CAPTURE.COMPLETED is processed
    private function remove_temporary_donor_info($order_id) {
        global $wpdb;
        $temp_table = $wpdb->prefix . 'donations_temp';

        $wpdb->delete($temp_table, ['order_id' => $order_id]);
    }


    private function log_transaction_details($transactionId, $amount, $currency, $donorName, $donorEmail) {
        error_log('Transaction ID: ' . $transactionId);
        error_log('Amount: ' . $amount . ' ' . $currency);
        error_log('Donor Name: ' . $donorName);
        error_log('Donor Email: ' . $donorEmail);
    }

    // Save the final transaction
    private function save_transaction($transactionId, $amount, $currency, $donorName, $donorEmail, $donorAddress) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'donations';

        // Log the details before saving
        error_log('Saving final transaction:');
        error_log('Transaction ID: ' . $transactionId);
        error_log('Amount: ' . $amount);
        error_log('Currency: ' . $currency);
        error_log('Donor Name: ' . $donorName);
        error_log('Donor Email: ' . $donorEmail);

        // Check if the transaction already exists
        $existing_transaction = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table_name WHERE transaction_id = %s", $transactionId));

        if ($existing_transaction) {
            error_log('Transaction already exists: ' . $transactionId);
            return;
        }

        // Save the transaction
        $inserted = $wpdb->insert($table_name, [
            'transaction_id' => sanitize_text_field($transactionId),
            'amount' => floatval($amount),
            'currency' => sanitize_text_field($currency),
            'donor_name' => sanitize_text_field($donorName),
            'donor_email' => sanitize_email($donorEmail),
            'donor_address' => maybe_serialize($donorAddress),
            'created_at' => current_time('mysql'),
        ]);

        // Log the result
        if ($inserted === false) {
            error_log('Failed to save transaction: ' . $wpdb->last_error);
        } else {
            error_log('Transaction saved successfully: ' . $transactionId);
        }
    }

    // Method to process the donation and save it to the donations table
    private function process_donation($donor_info, $resource) {
        global $wpdb;
        $donations_table = $wpdb->prefix . 'donations';
        $temp_table = $wpdb->prefix . 'donations_temp';

        $transaction_id = $resource->id;

        // Check if the donation already exists in the donations table
        $existing = $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM $donations_table WHERE transaction_id = %s", $transaction_id)
        );

        if ($existing) {
            error_log('Donation already recorded for transaction ID: ' . $transaction_id);
            // Remove the temporary donor info
            $wpdb->delete($temp_table, ['order_id' => $donor_info['order_id']]);
            return;
        }

        // Insert the final transaction into the donations table
        $wpdb->insert(
            $donations_table,
            [
                'transaction_id' => $transaction_id,
                'amount'         => $donor_info['amount'],
                'currency'       => $donor_info['currency'],
                'donor_name'     => $donor_info['donor_name'],
                'donor_email'    => $donor_info['donor_email'],
                'donor_address'  => $donor_info['donor_address'],
                'created_at'     => current_time('mysql'),
            ]
        );

        if ($wpdb->last_error) {
            error_log('Error inserting donation: ' . $wpdb->last_error);
        } else {
            error_log('Donation completed and saved for transaction: ' . $transaction_id);
        }

        // Remove the temporary donor info
        $wpdb->delete($temp_table, ['order_id' => $donor_info['order_id']]);
    }


    // Method to verify the webhook signature
    private function verify_webhook_signature($body, $headers) {
        // Log that signature verification is being bypassed for testing purposes
        error_log('Bypassing PayPal signature verification for testing.');

        // Log the headers received to ensure they are coming through
        error_log('Webhook headers for signature verification: ' . print_r($headers, true));

        // Log the payload to ensure the body is also received correctly
        error_log('Webhook body for signature verification: ' . $body);

        // For testing, return true to allow the webhook to continue processing
        return true;

        /*
        // Uncomment and use the code below once you re-enable verification for production

        $paypal_client_id = get_option('paypal_client_id');
        $paypal_client_secret = get_option('paypal_client_secret');
        $paypal_webhook_id = get_option('paypal_webhook_id');

        // Check if PayPal credentials are set
        if (empty($paypal_client_id) || empty($paypal_client_secret) || empty($paypal_webhook_id)) {
            error_log('PayPal API credentials or Webhook ID are not set.');
            return false;
        }

        // Determine the PayPal environment (sandbox or live)
        $environment = get_option('paypal_environment', 'sandbox');
        $endpoint = 'https://api.paypal.com/v1/notifications/verify-webhook-signature';
        if ($environment === 'sandbox') {
            $endpoint = 'https://api.sandbox.paypal.com/v1/notifications/verify-webhook-signature';
        }

        // Obtain the PayPal access token
        $access_token = $this->get_paypal_access_token($paypal_client_id, $paypal_client_secret, $environment);
        if (!$access_token) {
            error_log('Failed to obtain PayPal access token.');
            return false;
        }

        // Prepare the signature verification data
        $signatureVerificationData = [
            'auth_algo' => isset($headers['PAYPAL-AUTH-ALGO'][0]) ? $headers['PAYPAL-AUTH-ALGO'][0] : '',
            'cert_url' => isset($headers['PAYPAL-CERT-URL'][0]) ? $headers['PAYPAL-CERT-URL'][0] : '',
            'transmission_id' => isset($headers['PAYPAL-TRANSMISSION-ID'][0]) ? $headers['PAYPAL-TRANSMISSION-ID'][0] : '',
            'transmission_sig' => isset($headers['PAYPAL-TRANSMISSION-SIG'][0]) ? $headers['PAYPAL-TRANSMISSION-SIG'][0] : '',
            'transmission_time' => isset($headers['PAYPAL-TRANSMISSION-TIME'][0]) ? $headers['PAYPAL-TRANSMISSION-TIME'][0] : '',
            'webhook_id' => $paypal_webhook_id,
            'webhook_event' => json_decode($body, true),
        ];

        // Check for missing headers and log any that are missing
        foreach ($signatureVerificationData as $key => $value) {
            if (empty($value) && $key !== 'webhook_event') {
                error_log("Missing header or value for: $key");
                return false;
            }
        }

        // Send the verification request to PayPal
        $args = [
            'body' => json_encode($signatureVerificationData),
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $access_token,
            ],
            'method' => 'POST',
            'timeout' => 30,
        ];

        // Send the request and log the response
        $response = wp_remote_post($endpoint, $args);
        if (is_wp_error($response)) {
            error_log('Webhook signature verification failed: ' . $response->get_error_message());
            return false;
        }

        $response_body = wp_remote_retrieve_body($response);
        $verificationResult = json_decode($response_body, true);

        $verification_status = isset($verificationResult['verification_status']) ? $verificationResult['verification_status'] : 'FAILURE';
        error_log('PayPal Webhook Signature Verification Status: ' . $verification_status);

        return $verification_status === 'SUCCESS';
        */
    }


    // Method to get PayPal access token
    private function get_paypal_access_token($client_id, $client_secret, $environment) {
        $endpoint = 'https://api.paypal.com/v1/oauth2/token';
        if ($environment === 'sandbox') {
            $endpoint = 'https://api.sandbox.paypal.com/v1/oauth2/token';
        }

        $args = [
            'body' => 'grant_type=client_credentials',
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $client_secret),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'method' => 'POST',
            'timeout' => 30,
        ];

        $response = wp_remote_post($endpoint, $args);
        if (is_wp_error($response)) {
            error_log('Failed to obtain PayPal access token: ' . $response->get_error_message());
            return false;
        }

        $response_body = wp_remote_retrieve_body($response);
        $tokenResult = json_decode($response_body, true);

        return isset($tokenResult['access_token']) ? $tokenResult['access_token'] : false;
    }

    // Method to display the settings page
    public function settings_page() {
        ?>
        <div class="donations-module-wrapper">
            <h1>Configuración del módulo de donaciones</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('paypal_donations_tracker');
                do_settings_sections('paypal_donations_tracker');
                ?>

                <!-- General Settings Section -->
                <h2 class="donations-module-title">Configuración General</h2>
                <hr class="donations-module-separator" />
                <table class="donations-module-form-table">
                    <tr valign="top">
                        <th scope="row"><label for="donations_goal">Meta de Donaciones</label></th>
                        <td><input type="number" id="donations_goal" name="donations_goal" value="<?php echo esc_attr(intval(get_option('donations_goal', 1000))); ?>" placeholder="1000" class="donations-module-regular-text" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="paypal_hosted_button_id">PayPal Button ID</label></th>
                        <td><input type="text" id="paypal_hosted_button_id" name="paypal_hosted_button_id" value="<?php echo esc_attr(get_option('paypal_hosted_button_id')); ?>" placeholder="ABC1DEFGHIJ2K" class="donations-module-regular-text" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="cta_paragraph">Párrafo Incentivo</label></th>
                        <td><textarea id="cta_paragraph" name="cta_paragraph" rows="5" cols="50" placeholder="¡Ayúdanos a alcanzar nuestra meta! Dona ahora a través de PayPal y marca la diferencia." class="donations-module-large-text"><?php echo esc_textarea(get_option('cta_paragraph')); ?></textarea></td>
                    </tr>
                </table>

                <!-- PayPal API Settings Section -->
                <h2 class="donations-module-title">Configuración de PayPal API</h2>
                <hr class="donations-module-separator" />
                <table class="donations-module-form-table">
                    <tr valign="top">
                        <th scope="row"><label for="paypal_environment">Entorno de PayPal</label></th>
                        <td>
                            <select id="paypal_environment" name="paypal_environment" class="donations-module-regular-text">
                                <option value="sandbox" <?php selected(get_option('paypal_environment'), 'sandbox'); ?>>Sandbox</option>
                                <option value="live" <?php selected(get_option('paypal_environment'), 'live'); ?>>Live</option>
                            </select>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="paypal_client_id">PayPal Client ID</label></th>
                        <td><input type="text" id="paypal_client_id" name="paypal_client_id" value="<?php echo esc_attr(get_option('paypal_client_id')); ?>" placeholder="Ingrese su Client ID de PayPal" class="donations-module-regular-text" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="paypal_client_secret">PayPal Client Secret</label></th>
                        <td><input type="text" id="paypal_client_secret" name="paypal_client_secret" value="<?php echo esc_attr(get_option('paypal_client_secret')); ?>" placeholder="Ingrese su Client Secret de PayPal" class="donations-module-regular-text" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="paypal_webhook_id">PayPal Webhook ID</label></th>
                        <td><input type="text" id="paypal_webhook_id" name="paypal_webhook_id" value="<?php echo esc_attr(get_option('paypal_webhook_id')); ?>" placeholder="Ingrese su Webhook ID de PayPal" class="donations-module-regular-text" /></td>
                    </tr>
                </table>

                <!-- PayPal Fee Settings Section -->
                <h2 class="donations-module-title">PayPal Fee Configuration</h2>
                <hr class="donations-module-separator" />
                <table class="donations-module-form-table">
                    <tr valign="top">
                        <th scope="row"><label for="paypal_fee_percentage">PayPal Fee Percentage (%)</label></th>
                        <td><input type="number" id="paypal_fee_percentage" name="paypal_fee_percentage" value="<?php echo esc_attr(get_option('paypal_fee_percentage', '2.9')); ?>" step="0.01" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="paypal_fixed_fee">PayPal Fixed Fee (USD)</label></th>
                        <td><input type="number" id="paypal_fixed_fee" name="paypal_fixed_fee" value="<?php echo esc_attr(get_option('paypal_fixed_fee', '0.30')); ?>" step="0.01" /></td>
                    </tr>
                </table>

                <!-- Display Settings Section -->
                <h2 class="donations-module-title">Configuración de Pantalla</h2>
                <hr class="donations-module-separator" />
                <table class="donations-module-form-table">
                    <tr valign="top">
                        <th scope="row"><label for="content_alignment">Alinear Contenido</label></th>
                        <td>
                            <select id="content_alignment" name="content_alignment" class="donations-module-regular-text">
                                <option value="left" <?php selected(get_option('content_alignment'), 'left'); ?>>Izquierda</option>
                                <option value="center" <?php selected(get_option('content_alignment'), 'center'); ?>>Centro</option>
                                <option value="right" <?php selected(get_option('content_alignment'), 'right'); ?>>Derecha</option>
                            </select>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="show_amount_raised">Mostrar Monto Recaudado</label></th>
                        <td><input type="checkbox" id="show_amount_raised" name="show_amount_raised" value="1" <?php checked(get_option('show_amount_raised'), '1'); ?> /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="show_percentage_of_goal">Mostrar Porcentaje de la Meta</label></th>
                        <td><input type="checkbox" id="show_percentage_of_goal" name="show_percentage_of_goal" value="1" <?php checked(get_option('show_percentage_of_goal'), '1'); ?> /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="show_number_of_donations">Mostrar Número de Donaciones</label></th>
                        <td><input type="checkbox" id="show_number_of_donations" name="show_number_of_donations" value="1" <?php checked(get_option('show_number_of_donations'), '1'); ?> /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="show_cta_paragraph">Mostrar Párrafo Incentivo</label></th>
                        <td><input type="checkbox" id="show_cta_paragraph" name="show_cta_paragraph" value="1" <?php checked(get_option('show_cta_paragraph'), '1'); ?> /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="donations_text_color">Color del Texto</label></th>
                        <td><input type="color" id="donations_text_color" name="donations_text_color" value="<?php echo esc_attr(get_option('donations_text_color', '#333333')); ?>" class="donations-module-large-color-picker" /></td>
                    </tr>
                </table>

                <!-- Progress Bar Customization Section -->
                <h2 class="donations-module-title">Personalización de la Barra de Progreso</h2>
                <hr class="donations-module-separator" />
                <table class="donations-module-form-table">
                    <!-- Color Adjustments -->
                    <tr valign="top">
                        <th scope="row"><label for="progress_bar_color">Color de la Barra de Progreso</label></th>
                        <td><input type="color" id="progress_bar_color" name="progress_bar_color" value="<?php echo esc_attr(get_option('progress_bar_color', '#28a745')); ?>" placeholder="#28a745" class="donations-module-large-color-picker" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="progress_bar_well_color">Color del Fondo de la Barra de Progreso</label></th>
                        <td><input type="color" id="progress_bar_well_color" name="progress_bar_well_color" value="<?php echo esc_attr(get_option('progress_bar_well_color', '#e9ecef')); ?>" placeholder="#e9ecef" class="donations-module-large-color-picker" /></td>
                    </tr>

                    <!-- Sizing Adjustments -->
                    <tr valign="top">
                        <th scope="row"><label for="progress_bar_height">Altura de la Barra de Progreso (px)</label></th>
                        <td><input type="number" id="progress_bar_height" name="progress_bar_height" value="<?php echo esc_attr(get_option('progress_bar_height', 20)); ?>" placeholder="20" class="donations-module-small-text" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="progress_bar_well_width">Ancho del Fondo de la Barra de Progreso (%)</label></th>
                        <td><input type="number" id="progress_bar_well_width" name="progress_bar_well_width" value="<?php echo esc_attr(get_option('progress_bar_well_width', 100)); ?>" placeholder="100" class="donations-module-small-text" min="1" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="progress_bar_border_radius">Esquinas Redondeadas de la Barra de Progreso (px)</label></th>
                        <td><input type="number" id="progress_bar_border_radius" name="progress_bar_border_radius" value="<?php echo esc_attr(get_option('progress_bar_border_radius', 0)); ?>" placeholder="0" class="donations-module-small-text" /></td>
                    </tr>
                </table>

                <!-- Donate Button Customization Section -->
                <h2 class="donations-module-title">Personalización del Botón de Donación</h2>
                <hr class="donations-module-separator" />
                <table class="donations-module-form-table">
                    <tr valign="top">
                        <th scope="row"><label for="donate_button_label">Etiqueta del Botón</label></th>
                        <td><input type="text" id="donate_button_label" name="donate_button_label" value="<?php echo esc_attr(get_option('donate_button_label', 'Donate Now')); ?>" placeholder="Donate Now" class="donations-module-regular-text" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="donate_button_color">Color del Botón</label></th>
                        <td><input type="color" id="donate_button_color" name="donate_button_color" value="<?php echo esc_attr(get_option('donate_button_color', '#0077cc')); ?>" class="donations-module-large-color-picker" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="donate_button_border_radius">Esquinas Redondeadas del Botón (px)</label></th>
                        <td><input type="number" id="donate_button_border_radius" name="donate_button_border_radius" value="<?php echo esc_attr(get_option('donate_button_border_radius', 5)); ?>" placeholder="5" class="donations-module-small-text" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="donate_button_width">Ancho del Botón (px)</label></th>
                        <td><input type="number" id="donate_button_width" name="donate_button_width" value="<?php echo esc_attr(get_option('donate_button_width', 100)); ?>" min="60" placeholder="100" class="donations-module-small-text" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="donate_button_height">Altura del Botón (px)</label></th>
                        <td><input type="number" id="donate_button_height" name="donate_button_height" value="<?php echo esc_attr(get_option('donate_button_height', 40)); ?>" placeholder="40" class="donations-module-small-text" /></td>
                    </tr>
                </table>

                <?php submit_button('Guardar Cambios', 'primary large'); ?>
            </form>
        </div>
        <style>
            /* Namespaced CSS to avoid conflicts */
            .donations-module-wrapper h1 {
                font-size: 24px;
                margin-bottom: 20px;
            }

            .donations-module-wrapper .donations-module-title {
                font-size: 20px;
                margin-top: 40px; /* Increased space between sections */
            }

            .donations-module-wrapper .donations-module-separator {
                margin-top: 10px; /* Small space between title and separator */
                margin-bottom: 20px; /* Consistent space between separator and section content */
            }

            .donations-module-wrapper .donations-module-form-table th {
                font-weight: normal;
                width: 240px;
                padding-bottom: 5px;
                padding-top: 5px;
            }

            .donations-module-wrapper .donations-module-form-table td {
                padding-bottom: 5px;
                padding-top: 5px;
            }

            .donations-module-wrapper .donations-module-regular-text {
                width: 100%;
                max-width: 400px;
            }

            .donations-module-wrapper .donations-module-large-text {
                width: 100%;
                max-width: 400px;
            }

            .donations-module-wrapper .donations-module-large-color-picker {
                width: 70px;
                height: 30px;
                padding: 0;
                border: 1px solid #ccc;
                border-radius: 3px;
            }

            .donations-module-wrapper .donations-module-small-text {
                width: 70px;
            }
        </style>
        <?php
    }

    // Method to display the donations list page
    public function donations_list_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'donations';

        // Fetching data
        $total_collected = $this->get_current_donations_total();
        $goal = intval(get_option('donations_goal', 0));
        $donations_count = $this->get_total_donations_count();
        $percentage_of_goal = $goal > 0 ? ($total_collected / $goal) * 100 : 0;

        // Format numbers for display
        $formatted_total_collected = number_format($total_collected, 2);
        $formatted_percentage_of_goal = number_format($percentage_of_goal, 2);
        $formatted_donations_count = number_format($donations_count);

        // Fetching the donations list
        $donations = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");

        ?>
        <div class="donations-module-wrapper">
            <h1>Donaciones</h1>

            <!-- Metrics Dashboard -->
            <div class="donations-dashboard">
                <!-- Total Amount Collected -->
                <div class="donations-metrics-card">
                    <div class="donations-metric-value">$<?php echo $formatted_total_collected; ?> USD</div>
                    <div class="donations-metric-label">Total Recaudado</div>
                </div>

                <!-- Percentage of Goal Achieved -->
                <div class="donations-metrics-card">
                    <div class="donations-metric-value"><?php echo $formatted_percentage_of_goal; ?>%</div>
                    <div class="donations-metric-label">Porcentaje de la Meta</div>
                </div>

                <!-- Number of Donations -->
                <div class="donations-metrics-card">
                    <div class="donations-metric-value"><?php echo $formatted_donations_count; ?></div>
                    <div class="donations-metric-label">Número de Donaciones</div>
                </div>
            </div>

            <!-- Donations Table -->
            <table class="donations-module-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Cantidad</th>
                        <th>ID de Transacción</th>
                        <th>Nombre del Donante</th>
                        <th>Email del Donante</th>
                        <th>Dirección del Donante</th>
                        <th>Fecha</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($donations as $donation) {
                        // Unserialize the donor address
                        $donor_address = isset($donation->donor_address) ? maybe_unserialize($donation->donor_address) : null;
                        $formatted_address = '';

                        if (!empty($donor_address) && is_array($donor_address)) {
                            // Build the formatted address
                            $formatted_address = isset($donor_address['address_line_1']) ? esc_html($donor_address['address_line_1']) : '';
                            $formatted_address .= isset($donor_address['admin_area_2']) ? ', ' . esc_html($donor_address['admin_area_2']) : '';
                            $formatted_address .= isset($donor_address['admin_area_1']) ? ', ' . esc_html($donor_address['admin_area_1']) : '';
                            $formatted_address .= isset($donor_address['postal_code']) ? ', ' . esc_html($donor_address['postal_code']) : '';
                            $formatted_address .= isset($donor_address['country_code']) ? ', ' . esc_html($donor_address['country_code']) : '';
                        } else {
                            $formatted_address = 'N/A';
                        }

                    ?>
                        <tr>
                            <td><?php echo esc_html($donation->id); ?></td>
                            <td><?php echo esc_html(number_format($donation->amount, 2)); ?></td>
                            <td><?php echo esc_html($donation->transaction_id); ?></td>
                            <td><?php echo esc_html($donation->donor_name); ?></td>
                            <td><?php echo esc_html($donation->donor_email); ?></td>
                            <td><?php echo $formatted_address; ?></td>
                            <td><?php echo esc_html($donation->created_at); ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>

        <style>
            /* Scoped styles for donations list page */
            .donations-dashboard {
                display: flex;
                justify-content: space-between;
                margin-bottom: 30px;
                margin-top: 20px;
                flex-wrap: wrap; /* Add this to wrap cards on smaller screens */
            }

            .donations-metrics-card {
                background: #f8f9fa;
                border: 1px solid #e9ecef;
                border-radius: 8px;
                padding: 20px;
                text-align: center;
                flex: 1;
                margin: 10px;
                min-width: 200px; /* Ensure cards don't get too narrow */
            }

            .donations-metric-value {
                font-size: 2em;
                font-weight: bold;
                color: #333;
            }

            .donations-metric-label {
                font-size: 1em;
                color: #666;
                margin-top: 10px;
            }

            .donations-module-table {
                border: 1px solid #e9ecef;
                border-radius: 8px;
                width: 100%;
                margin-top: 20px;
                border-collapse: collapse;
            }

            .donations-module-table th,
            .donations-module-table td {
                border: 1px solid #e9ecef;
                padding: 8px;
                text-align: left;
            }

            .donations-module-table th {
                background-color: #f1f1f1;
            }
        </style>
        <?php
    }


    // Method to enqueue frontend styles
    public function enqueue_frontend_styles() {
        // Enqueue the plugin's CSS file
        wp_enqueue_style('paypal-donations-tracker-styles', plugin_dir_url(__FILE__) . 'css/paypal-donations-tracker.css');
    }

    // Method to enqueue modal assets
    public function enqueue_modal_assets() {
        // Enqueue jQuery
        wp_enqueue_script('jquery');
    }

    // Method to handle the donation form shortcode
    public function donations_form_shortcode() {
        // Retrieve button customization settings
        $donate_button_label = esc_attr(get_option('donate_button_label', 'Donate Now'));
        $donate_button_color = esc_attr(get_option('donate_button_color', '#0077cc'));
        $donate_button_border_radius = intval(get_option('donate_button_border_radius', 5));
        $donate_button_width = intval(get_option('donate_button_width', 100));
        $donate_button_height = intval(get_option('donate_button_height', 40));

        // Ensure minimum button width of 60px
        if ($donate_button_width < 60) {
            $donate_button_width = 60;
        }

        ob_start();
        ?>
        <!-- Trigger Button -->
        <button id="open-donation-modal" class="donation-modal-button" style="
            background-color: <?php echo $donate_button_color; ?>;
            border-radius: <?php echo $donate_button_border_radius; ?>px;
            width: <?php echo $donate_button_width; ?>px;
            height: <?php echo $donate_button_height; ?>px;
            color: #fff;
            border: none;
            cursor: pointer;
            font-size: 16px;
        "><?php echo $donate_button_label; ?></button>
        <?php
        return ob_get_clean();
    }

    // Method to output the donation modal at the end of the body
    public function output_donation_modal() {
        // Enqueue necessary scripts and styles
        $this->enqueue_modal_assets();

        // Enqueue PayPal SDK script
        $client_id = esc_attr(get_option('paypal_client_id'));
        $paypal_environment = get_option('paypal_environment', 'sandbox');
        $paypal_sdk_url = $paypal_environment === 'sandbox' ? 'https://www.sandbox.paypal.com/sdk/js' : 'https://www.paypal.com/sdk/js';

        if (!empty($client_id)) {
            wp_enqueue_script(
                'paypal-sdk',
                $paypal_sdk_url . '?client-id=' . $client_id . '&currency=USD',
                [],
                null,
                true // Ensure the script is loaded in the footer
            );
        }

        // Enqueue jQuery
        wp_enqueue_script('jquery');

        // Register and enqueue custom script with dependencies
        wp_register_script(
            'paypal-donations-script',
            '', // No src since we'll add inline script
            ['jquery', 'paypal-sdk'],
            null,
            true
        );

        // The updated JavaScript code
        ob_start();
        ?>
        (function($) {
            // Modal functionality
            $(document).ready(function() {
                // Open the modal when the button is clicked
                $("#open-donation-modal").on("click", function(e) {
                    e.preventDefault();
                    $("#donation-modal").fadeIn();
                    calculateFees(); // Initialize fee calculation
                });

                // Close the modal when the close button or overlay is clicked
                $("#donation-modal .close, #donation-modal .modal-overlay").on("click", function() {
                    $("#donation-modal").fadeOut();
                });

                // Prevent modal content click from closing the modal
                $("#donation-modal .modal-content").on("click", function(e) {
                    e.stopPropagation();
                });

                // Handle donation amount button clicks
                $("#donation-modal .donation-amount").on("click", function() {
                    $("#donation-modal .donation-amount").removeClass("selected");
                    $(this).addClass("selected");
                    const selectedAmount = $(this).val();
                    $("#donation-modal #custom-amount-field").toggle(selectedAmount === "Other");
                    calculateFees();  // Recalculate fees when amount changes
                });

                // Custom amount input handler
                $("#donation-modal #custom-amount").on("input", function() {
                    calculateFees();  // Recalculate fees when custom amount is entered
                });

                // Checkbox for covering fees
                $("#donation-modal #cover-fees").on("change", function() {
                    calculateFees();  // Recalculate fees when checkbox is toggled
                });

                // Function to calculate and display fees
                function calculateFees() {
                    let selectedAmount = $("#donation-modal .donation-amount.selected").val();
                    if (selectedAmount === "Other") {
                        selectedAmount = $("#donation-modal #custom-amount").val();
                    }

                    selectedAmount = parseFloat(selectedAmount);  // Ensure it's a number

                    const feePercentage = parseFloat("<?php echo esc_attr(get_option('paypal_fee_percentage', 2.9)); ?>");
                    const fixedFee = parseFloat("<?php echo esc_attr(get_option('paypal_fixed_fee', 0.30)); ?>");

                    if (!selectedAmount || isNaN(selectedAmount)) {
                        $("#donation-modal #cover-fees-label").text("Add $0.00 USD to help cover the fees.");
                        return;  // Exit if no valid amount
                    }

                    let fee = (selectedAmount * feePercentage / 100) + fixedFee;
                    fee = fee.toFixed(2);
                    $("#donation-modal #cover-fees-label").text("Add $" + fee + " USD to help cover the fees.");
                }

                // PayPal button rendering inside the modal
                paypal.Buttons({
                    // Hide the modal when the PayPal popup opens
                    onClick: function(data, actions) {
                        // Validate donation amount
                        let selectedButton = $("#donation-modal .donation-amount.selected");
                        let donationAmount = selectedButton.val();

                        if (donationAmount === "Other") {
                            donationAmount = $("#donation-modal #custom-amount").val();
                        }

                        if (!donationAmount || isNaN(parseFloat(donationAmount))) {
                            alert("Please select or enter a valid donation amount.");
                            return actions.reject();
                        }

                        // If validation passes, hide the modal
                        $("#donation-modal").fadeOut();

                        return actions.resolve();
                    },
                    createOrder: function(data, actions) {
                        let selectedButton = $("#donation-modal .donation-amount.selected");
                        let donationAmount = selectedButton.val();

                        if (donationAmount === "Other") {
                            donationAmount = $("#donation-modal #custom-amount").val();
                        }

                        let coverFees = $("#donation-modal #cover-fees").is(":checked");

                        let feePercentage = parseFloat("<?php echo esc_attr(get_option('paypal_fee_percentage', 2.9)); ?>");
                        let fixedFee = parseFloat("<?php echo esc_attr(get_option('paypal_fixed_fee', 0.30)); ?>");
                        let fee = (donationAmount * feePercentage / 100) + fixedFee;
                        let totalAmount = coverFees ? (parseFloat(donationAmount) + parseFloat(fee)).toFixed(2) : parseFloat(donationAmount).toFixed(2);

                        return actions.order.create({
                            purchase_units: [{
                                amount: {
                                    value: totalAmount
                                }
                            }]
                        });
                    },
                    
                    onApprove: function(data, actions) {
                        return actions.order.capture().then(function(details) {
                            // Donation will be saved by the webhook handler; no action needed here
                            // Optionally, you can display a thank-you message or redirect the user
                        });
                    },

                            // Show the modal again if the payment is canceled
                            onCancel: function(data) {
                                $("#donation-modal").fadeIn();
                            },
                            // Handle errors
                            onError: function(err) {
                                $("#donation-modal").fadeIn();
                                alert("An error occurred during the transaction. Please try again.");
                            }
                        }).render("#donation-modal #paypal-button-container");
                    });
        })(jQuery);
        <?php
        $custom_js = ob_get_clean();
        wp_add_inline_script('paypal-donations-script', $custom_js);
        wp_enqueue_script('paypal-donations-script');

        // Output the modal HTML
        ?>
        <!-- Donation Modal -->
        <div id="donation-modal" style="display: none;">
            <div class="modal-overlay"></div>
            <div class="modal-content">
                <span class="close">&times;</span>
                <div class="donations-module-wrapper">
                    <h2>Donate to Trama Tres Vidas</h2>
                    <p>Trama Tres Vidas son proyectos hermanos que brindan servicios enfocados en el acceso a comida de alta densidad nutricional.</p>

                    <!-- Pre-set donation amounts -->
                    <div>
                        <button class="donation-amount" value="5">$5 USD</button>
                        <button class="donation-amount" value="25">$25 USD</button>
                        <button class="donation-amount" value="100">$100 USD</button>
                        <button class="donation-amount" value="Other" id="other-amount">Other</button>
                    </div>

                    <!-- Custom amount field -->
                    <div id="custom-amount-field" style="display: none;">
                        <input type="number" id="custom-amount" name="custom_amount" placeholder="Enter Amount" />
                    </div>

                    <!-- Checkbox for covering fees -->
                    <div>
                        <input type="checkbox" id="cover-fees" name="cover_fees" value="1" />
                        <label id="cover-fees-label" for="cover-fees">Add $0.00 USD to help cover the fees.</label>
                    </div>

                    <!-- Recurring donation checkbox -->
                    <div>
                        <label><input type="checkbox" id="recurring-donation" name="recurring_donation" value="1" /> Make this a recurring donation</label>
                    </div>

                    <!-- PayPal button integration -->
                    <div id="paypal-button-container" style="margin-top: 10px;"></div>
                </div>
            </div>
        </div>

        <!-- Modal Styles -->
        <style>
            /* Modal Styles */
            #donation-modal {
                position: fixed;
                top: 0;
                left: 0;
                width: 100vw;
                height: 100vh;
                z-index: 1000;
            }

            #donation-modal .modal-overlay {
                position: absolute;
                top: 0;
                left: 0;
                width: 100vw;
                height: 100vh;
                background: rgba(0,0,0,0.5);
            }

            #donation-modal .modal-content {
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: #fff;
                width: 90%;
                max-width: 600px;
                padding: 20px;
                border-radius: 5px;
                z-index: 1001;
                box-sizing: border-box;
                overflow-y: auto;
                max-height: 90%;
            }

            #donation-modal .close {
                position: absolute;
                top: 10px;
                right: 15px;
                font-size: 28px;
                font-weight: bold;
                color: #aaa;
                cursor: pointer;
            }

            #donation-modal .close:hover,
            #donation-modal .close:focus {
                color: black;
                text-decoration: none;
                cursor: pointer;
            }

            .donation-modal-button {
                /* Style your trigger button as needed */
                padding: 10px 20px;
                background-color: #0077cc;
                color: #fff;
                border: none;
                cursor: pointer;
                border-radius: 5px;
                font-size: 16px;
            }

            .donation-modal-button:hover {
                background-color: #005fa3;
            }

            /* Responsive adjustments */
            .donations-module-wrapper h2,
            .donations-module-wrapper p {
                text-align: center;
            }

            .donations-module-wrapper .donation-amount {
                padding: 10px;
                margin: 5px;
                border: 1px solid #ccc;
                background-color: #f0f0f0;
                cursor: pointer;
                width: calc(50% - 12px); /* Two buttons per row with margins */
                box-sizing: border-box;
            }

            .donations-module-wrapper .donation-amount.selected {
                background-color: #00aaff;
                color: white;
                border-color: #0077cc;
            }

            @media (max-width: 480px) {
                .donations-module-wrapper .donation-amount {
                    width: 100%; /* One button per row on small screens */
                    margin-left: 0;
                    margin-right: 0;
                }

                #donation-modal .modal-content {
                    padding: 15px;
                }

                #donation-modal .close {
                    font-size: 24px;
                    top: 5px;
                    right: 10px;
                }
            }
        </style>
        <?php
    }

    // Method to get the current total donations
    protected function get_current_donations_total() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'donations';
        $result = $wpdb->get_var("SELECT SUM(amount) FROM $table_name");
        return $result ? floatval($result) : 0;
    }

    // Method to get the total number of donations
    protected function get_total_donations_count() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'donations';
        $result = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        return $result ? intval($result) : 0;
    }

    // Method to calculate campaign progress
    protected function get_progress_bar_data() {
        $goal = intval(get_option('donations_goal', 0));
        $current_total = $this->get_current_donations_total();
        $progress = $goal > 0 ? ($current_total / $goal) * 100 : 0;

        return [
            'goal' => $goal,
            'current_total' => $current_total,
            'progress' => $progress
        ];
    }

    // Method to display the donations progress bar shortcode
    public function donations_progress_shortcode() {
        // Get the progress bar data
        $progress_data = $this->get_progress_bar_data();

        // Get settings for display options
        $show_amount_raised = get_option('show_amount_raised', '1');
        $show_percentage_of_goal = get_option('show_percentage_of_goal', '1');
        $donations_text_color = esc_attr(get_option('donations_text_color', '#333333'));
        $content_alignment = esc_attr(get_option('content_alignment', 'center'));

        // Progress bar customization options
        $progress_bar_color = esc_attr(get_option('progress_bar_color', '#28a745'));
        $progress_bar_height = intval(get_option('progress_bar_height', 20));
        $progress_bar_well_color = esc_attr(get_option('progress_bar_well_color', '#e9ecef'));
        $progress_bar_well_width = intval(get_option('progress_bar_well_width', 100));
        $progress_bar_border_radius = intval(get_option('progress_bar_border_radius', 0));

        // Ensure the progress bar well width is valid
        if ($progress_bar_well_width <= 0) {
            $progress_bar_well_width = 100; // Set default to 100%
        }

        // Calculate the percentage
        $percentage = $progress_data['progress'];
        if ($percentage > 100) {
            $percentage = 100;
        } elseif ($percentage < 0) {
            $percentage = 0;
        }

        // Determine the border radius for the progress bar based on percentage
        if ($percentage >= 100) {
            // Full border radius
            $progress_bar_border_radius_css = $progress_bar_border_radius . 'px';
        } else {
            // Border radius only on left side
            $progress_bar_border_radius_css = $progress_bar_border_radius . 'px 0px 0px ' . $progress_bar_border_radius . 'px';
        }

        // Prepare the HTML output
        ob_start();
        ?>
        <div class="donations-progress-bar-wrapper" style="text-align: <?php echo $content_alignment; ?>;">
            <?php if ($show_amount_raised == '1') : ?>
                <div class="donations-progress-text" style="color: <?php echo $donations_text_color; ?>;">
                    Total Recaudado: $<?php echo number_format($progress_data['current_total'], 2); ?> USD
                </div>
            <?php endif; ?>
            <div class="donations-progress-well" style="
                background-color: <?php echo $progress_bar_well_color; ?>;
                width: <?php echo $progress_bar_well_width; ?>%;
                height: <?php echo $progress_bar_height; ?>px;
                border-radius: <?php echo $progress_bar_border_radius; ?>px;
                margin: 10px auto;
                position: relative;
                overflow: hidden;
                ">
                <div class="donations-progress-bar" style="
                    background-color: <?php echo $progress_bar_color; ?>;
                    width: <?php echo $percentage; ?>%;
                    height: 100%;
                    border-radius: <?php echo $progress_bar_border_radius_css; ?>;
                    max-width: 100%;
                    ">
                </div>
            </div>
            <?php if ($show_percentage_of_goal == '1') : ?>
                <div class="donations-progress-percentage" style="color: <?php echo $donations_text_color; ?>;">
                    <?php echo number_format($progress_data['progress'], 2); ?>% de la meta de $<?php echo number_format($progress_data['goal'], 2); ?> USD
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}

// Initialize the plugin
PayPal_Donations_Tracker::init();

// Register activation and deactivation hooks
register_activation_hook(__FILE__, ['PayPal_Donations_Tracker', 'activate']);
register_deactivation_hook(__FILE__, ['PayPal_Donations_Tracker', 'deactivate']);