<?php
/*
Plugin Name: PayPal Donations Tracker
Description: A plugin to track donations via PayPal and display progress towards a goal.
Version: 1.1
Author: Your Name
*/

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
        add_action('wp_ajax_save_donation', [$this, 'save_donation']);
        add_action('wp_ajax_nopriv_save_donation', [$this, 'save_donation']);

        // Register the webhook listener
        add_action('rest_api_init', [$this, 'register_webhook_listener']);

        // Output the modal in the footer
        add_action('wp_footer', [$this, 'output_donation_modal']);
    }

    // Method to run on plugin activation
    public static function activate() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'donations';
        $charset_collate = $wpdb->get_charset_collate();

        $installed_version = get_option('paypal_donations_tracker_db_version');
        $current_version = '1.1'; // Update the version as needed

        if ($installed_version != $current_version) {
            $sql = "CREATE TABLE $table_name (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                transaction_id varchar(255) NOT NULL,
                amount decimal(10, 2) NOT NULL,
                currency varchar(10) NOT NULL,
                donor_name varchar(255) NOT NULL,
                donor_email varchar(255) NOT NULL,
                donor_address text,
                created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                PRIMARY KEY  (id)
            ) $charset_collate;";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);

            update_option('paypal_donations_tracker_db_version', $current_version);
        }

        // Flush rewrite rules
        flush_rewrite_rules();
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
        ]);
        register_setting('paypal_donations_tracker', 'paypal_hosted_button_id');
        register_setting('paypal_donations_tracker', 'cta_paragraph', 'sanitize_textarea_field');
        register_setting('paypal_donations_tracker', 'number_of_donations', [
            'default' => 0,
        ]);
        register_setting('paypal_donations_tracker', 'total_amount_raised', [
            'default' => 0,
        ]);

        // Display settings
        register_setting('paypal_donations_tracker', 'show_amount_raised', [
            'default' => '1',
        ]);
        register_setting('paypal_donations_tracker', 'show_percentage_of_goal', [
            'default' => '1',
        ]);
        register_setting('paypal_donations_tracker', 'show_number_of_donations', [
            'default' => '1',
        ]);
        register_setting('paypal_donations_tracker', 'show_cta_paragraph', [
            'default' => '1',
        ]);
        register_setting('paypal_donations_tracker', 'content_alignment', [
            'default' => 'center',
        ]);
        register_setting('paypal_donations_tracker', 'progress_bar_color');
        register_setting('paypal_donations_tracker', 'progress_bar_height');
        register_setting('paypal_donations_tracker', 'progress_bar_well_color');
        register_setting('paypal_donations_tracker', 'progress_bar_well_width');
        register_setting('paypal_donations_tracker', 'progress_bar_border_radius');
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
        ]);

        // Register PayPal fee settings
        register_setting('paypal_donations_tracker', 'paypal_fee_percentage', [
            'default' => '2.9',  // Default PayPal fee percentage
            'sanitize_callback' => 'floatval',
        ]);
        register_setting('paypal_donations_tracker', 'paypal_fixed_fee', [
            'default' => '0.30',  // Default PayPal fixed fee
            'sanitize_callback' => 'floatval',
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

        error_log('Webhook received.');

        // Verify the webhook signature
        $verification_result = $this->verify_webhook_signature($body, $headers);
        if (!$verification_result) {
            error_log('Webhook signature verification failed. Headers: ' . print_r($headers, true));
            error_log('Webhook payload: ' . $body);
            return new WP_Error('invalid_signature', 'Invalid webhook signature', ['status' => 400]);
        }

        error_log('Webhook signature verified.');

        // Decode the JSON body to handle the event
        $event = json_decode($body);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('Invalid JSON body: ' . json_last_error_msg());
            return new WP_Error('invalid_json', 'Invalid JSON body', ['status' => 400]);
        }

        error_log('Event Type: ' . $event->event_type);

        // Handle the PAYMENT.CAPTURE.COMPLETED event
        if ($event->event_type === 'PAYMENT.CAPTURE.COMPLETED') {
            $resource = $event->resource;

            $transactionId = $resource->id;
            $amount = $resource->amount->value;
            $currency = $resource->amount->currency_code;

            // Extract payer information
            $payer = $resource->payer;
            $donorName = $payer->name->given_name . ' ' . $payer->name->surname;
            $donorEmail = $payer->email_address;
            $donorAddress = isset($payer->address) ? $payer->address : null; // Address may not always be available

            error_log('Transaction ID: ' . $transactionId);
            error_log('Amount: ' . $amount . ' ' . $currency);
            error_log('Donor Name: ' . $donorName);
            error_log('Donor Email: ' . $donorEmail);

            // Insert donation into the database
            global $wpdb;
            $table_name = $wpdb->prefix . 'donations';
            $wpdb->insert($table_name, [
                'transaction_id' => sanitize_text_field($transactionId),
                'amount' => floatval($amount),
                'currency' => sanitize_text_field($currency),
                'donor_name' => sanitize_text_field($donorName),
                'donor_email' => sanitize_email($donorEmail),
                'donor_address' => maybe_serialize($donorAddress),
                'created_at' => current_time('mysql'),
            ]);

            // Update donation metrics
            update_option('total_amount_raised', $this->get_current_donations_total());
            update_option('number_of_donations', $this->get_total_donations_count());

            error_log('Donation saved successfully');
            return new WP_REST_Response(['status' => 'success'], 200);
        } else {
            error_log('Unhandled event type: ' . $event->event_type);
            return new WP_Error('unhandled_event', 'Unhandled event type', ['status' => 400]);
        }
    }

    // Method to verify the webhook signature
    private function verify_webhook_signature($body, $headers) {
        $paypal_client_id = get_option('paypal_client_id');
        $paypal_client_secret = get_option('paypal_client_secret');
        $paypal_webhook_id = get_option('paypal_webhook_id');

        // Check if necessary options are set
        if (empty($paypal_client_id) || empty($paypal_client_secret) || empty($paypal_webhook_id)) {
            error_log('PayPal API credentials are not set.');
            return false;
        }

        // Determine the PayPal environment
        $environment = get_option('paypal_environment', 'sandbox');
        $endpoint = 'https://api.paypal.com/v1/notifications/verify-webhook-signature';
        if ($environment === 'sandbox') {
            $endpoint = 'https://api.sandbox.paypal.com/v1/notifications/verify-webhook-signature';
        }

        // Get access token
        $access_token = $this->get_paypal_access_token($paypal_client_id, $paypal_client_secret, $environment);
        if (!$access_token) {
            error_log('Failed to obtain PayPal access token.');
            return false;
        }

        // Correctly access headers (normalize header names)
        $headers = array_change_key_case($headers, CASE_UPPER);
        $signatureVerificationData = [
            'auth_algo' => isset($headers['PAYPAL-AUTH-ALGO'][0]) ? $headers['PAYPAL-AUTH-ALGO'][0] : '',
            'cert_url' => isset($headers['PAYPAL-CERT-URL'][0]) ? $headers['PAYPAL-CERT-URL'][0] : '',
            'transmission_id' => isset($headers['PAYPAL-TRANSMISSION-ID'][0]) ? $headers['PAYPAL-TRANSMISSION-ID'][0] : '',
            'transmission_sig' => isset($headers['PAYPAL-TRANSMISSION-SIG'][0]) ? $headers['PAYPAL-TRANSMISSION-SIG'][0] : '',
            'transmission_time' => isset($headers['PAYPAL-TRANSMISSION-TIME'][0]) ? $headers['PAYPAL-TRANSMISSION-TIME'][0] : '',
            'webhook_id' => $paypal_webhook_id,
            'webhook_event' => json_decode($body, true),
        ];

        // Check for missing headers
        foreach ($signatureVerificationData as $key => $value) {
            if (empty($value) && $key !== 'webhook_event' && $key !== 'webhook_id') {
                error_log("Missing header or value for: $key");
                return false;
            }
        }

        $args = [
            'body' => json_encode($signatureVerificationData),
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $access_token,
            ],
            'method' => 'POST',
            'timeout' => 30,
        ];

        $response = wp_remote_post($endpoint, $args);
        if (is_wp_error($response)) {
            error_log('Webhook signature verification failed: ' . $response->get_error_message());
            return false;
        }

        $response_body = wp_remote_retrieve_body($response);
        $verificationResult = json_decode($response_body, true);

        $verification_status = isset($verificationResult['verification_status']) ? $verificationResult['verification_status'] : 'FAILURE';
        error_log('Verification Status: ' . $verification_status);

        return $verification_status === 'SUCCESS';
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
                        <td><input type="number" id="donations_goal" name="donations_goal" value="<?php echo esc_attr(intval(get_option('donations_goal'))); ?>" placeholder="1000" class="donations-module-regular-text" /></td>
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
                        <td><input type="color" id="progress_bar_color" name="progress_bar_color" value="<?php echo esc_attr(get_option('progress_bar_color', '#00ff00')); ?>" placeholder="#00ff00" class="donations-module-large-color-picker" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="progress_bar_well_color">Color del Fondo de la Barra de Progreso</label></th>
                        <td><input type="color" id="progress_bar_well_color" name="progress_bar_well_color" value="<?php echo esc_attr(get_option('progress_bar_well_color', '#eeeeee')); ?>" placeholder="#eeeeee" class="donations-module-large-color-picker" /></td>
                    </tr>

                    <!-- Sizing Adjustments -->
                    <tr valign="top">
                        <th scope="row"><label for="progress_bar_height">Altura de la Barra de Progreso (px)</th>
                        <td><input type="number" id="progress_bar_height" name="progress_bar_height" value="<?php echo esc_attr(get_option('progress_bar_height', 20)); ?>" placeholder="20" class="donations-module-small-text" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="progress_bar_well_width">Ancho del Fondo de la Barra de Progreso (%)</label></th>
                        <td><input type="number" id="progress_bar_well_width" name="progress_bar_well_width" value="<?php echo esc_attr(get_option('progress_bar_well_width', 100)); ?>" placeholder="100" class="donations-module-small-text" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="progress_bar_border_radius">Esquinas Redondeadas de la Barra de Progreso (px)</label></th>
                        <td><input type="number" id="progress_bar_border_radius" name="progress_bar_border_radius" value="<?php echo esc_attr(get_option('progress_bar_border_radius', 0)); ?>" placeholder="0" class="donations-module-small-text" /></td>
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

        // Fetching the donations list
        $donations = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");

        ?>
        <div class="donations-module-wrapper">
            <h1>Donaciones</h1>

            <!-- Metrics Dashboard -->
            <div class="donations-dashboard">
                <div class="donations-metrics-card">
                    <div class="donations-metric-value"><?php echo '$' . number_format($total_collected, 2); ?></div>
                    <div class="donations-metric-label">Total Recaudado</div>
                </div>
                <div class="donations-metrics-card">
                    <div class="donations-metric-value"><?php echo number_format($percentage_of_goal, 2) . '%'; ?></div>
                    <div class="donations-metric-label">Meta Alcanzada</div>
                    <div class="donations-progress">
                        <div class="donations-progress-bar" style="width: <?php echo $percentage_of_goal; ?>%;"></div>
                    </div>
                </div>
                <div class="donations-metrics-card">
                    <div class="donations-metric-value"><?php echo intval($donations_count); ?></div>
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
                        $donor_address = isset($donation->donor_address) ? maybe_unserialize($donation->donor_address) : null;
                        $formatted_address = '';
                        if (!empty($donor_address)) {
                            if (is_array($donor_address)) {
                                $formatted_address = isset($donor_address['address_line_1']) ? esc_html($donor_address['address_line_1']) : '';
                                $formatted_address .= isset($donor_address['address_line_2']) ? ', ' . esc_html($donor_address['address_line_2']) : '';
                                $formatted_address .= isset($donor_address['admin_area_2']) ? ', ' . esc_html($donor_address['admin_area_2']) : '';
                                $formatted_address .= isset($donor_address['admin_area_1']) ? ', ' . esc_html($donor_address['admin_area_1']) : '';
                                $formatted_address .= isset($donor_address['postal_code']) ? ', ' . esc_html($donor_address['postal_code']) : '';
                                $formatted_address .= isset($donor_address['country_code']) ? ', ' . esc_html($donor_address['country_code']) : '';
                            } else {
                                $formatted_address = esc_html($donor_address);
                            }
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
            }

            .donations-metrics-card {
                background: #f8f9fa;
                border: 1px solid #e9ecef;
                border-radius: 8px;
                padding: 20px;
                text-align: center;
                flex: 1;
                margin: 0 10px;
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

            .donations-progress {
                margin-top: 15px;
                background-color: #e9ecef;
                border-radius: 8px;
                overflow: hidden;
                height: 10px;
                width: 100%;
            }

            .donations-progress-bar {
                height: 100%;
                background-color: #28a745;
                transition: width 0.4s ease;
            }

            .donations-module-table {
                border: 1px solid #e9ecef;
                border-radius: 8px;
                width: 100%;
                margin-top: 20px;
            }

            .donations-module-table th,
            .donations-module-table td {
                border: 1px solid #e9ecef;
            }
        </style>
        <?php
    }

    // Method to enqueue modal assets
    public function enqueue_modal_assets() {
        // Enqueue jQuery
        wp_enqueue_script('jquery');
    }

    // Method to handle the donation form shortcode
    public function donations_form_shortcode() {
        ob_start();
        ?>
        <!-- Trigger Button -->
        <button id="open-donation-modal" class="donation-modal-button">Donate Now</button>
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
                        // Make AJAX call to save the donation
                        $.post("<?php echo admin_url('admin-ajax.php'); ?>", {
                            action: "save_donation",
                            donation_nonce: "<?php echo wp_create_nonce('save_donation'); ?>",
                            transaction_id: details.id,
                            donation_amount: details.purchase_units[0].amount.value
                        }, function(response) {
                            if (response.success) {
                                // No need to hide the modal here as it's already hidden
                            } else {
                                alert("There was a problem saving your donation.");
                            }
                        });
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
    private function get_current_donations_total() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'donations';
        $result = $wpdb->get_var("SELECT SUM(amount) FROM $table_name");
        return $result ? floatval($result) : 0;
    }

    // Method to get the total number of donations
    private function get_total_donations_count() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'donations';
        $result = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        return $result ? intval($result) : 0;
    }

    // Method to calculate campaign progress
    private function get_progress_bar_data() {
        $goal = intval(get_option('donations_goal', 0));
        $current_total = $this->get_current_donations_total();
        $progress = $goal > 0 ? ($current_total / $goal) * 100 : 0;

        return [
            'goal' => $goal,
            'current_total' => $current_total,
            'progress' => $progress
        ];
    }

    // Method to save a donation via AJAX
    public function save_donation() {
        // Check and verify nonce
        if (
            !isset($_POST['donation_nonce']) ||
            !wp_verify_nonce($_POST['donation_nonce'], 'save_donation')
        ) {
            wp_send_json([
                'success' => false,
                'message' => 'Invalid security token.',
            ]);
            return;
        }

        // Sanitize and validate input
        $transaction_id = sanitize_text_field($_POST['transaction_id']);
        $amount = isset($_POST['donation_amount']) ? floatval($_POST['donation_amount']) : 0;

        if (empty($transaction_id) || $amount <= 0) {
            wp_send_json([
                'success' => false,
                'message' => 'Invalid donation data.',
            ]);
            return;
        }

        // Insert data into the database
        global $wpdb;
        $table_name = $wpdb->prefix . 'donations';

        $inserted = $wpdb->insert($table_name, [
            'transaction_id' => $transaction_id,
            'amount' => $amount,
            'currency' => 'USD', // Assuming USD for simplicity
            'donor_name' => '', // Donor name is not provided here
            'donor_email' => '', // Donor email is not provided here
            'donor_address' => '', // Donor address is not provided here
            'created_at' => current_time('mysql'),
        ]);

        // Check if insertion was successful
        if ($inserted === false) {
            wp_send_json([
                'success' => false,
                'message' => 'Failed to save donation.',
            ]);
            return;
        }

        // Send success response
        wp_send_json([
            'success' => true,
            'current_total' => $this->get_current_donations_total(),
        ]);
    }
}

// Initialize the plugin
PayPal_Donations_Tracker::init();

// Register activation and deactivation hooks
register_activation_hook(__FILE__, ['PayPal_Donations_Tracker', 'activate']);
register_deactivation_hook(__FILE__, ['PayPal_Donations_Tracker', 'deactivate']);
