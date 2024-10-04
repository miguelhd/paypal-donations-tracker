<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
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
        add_action('wp_enqueue_scripts', [$this, 'enqueue_paypal_sdk']);
        
        // Register the webhook listener
        add_action('rest_api_init', [$this, 'register_webhook_listener']);
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

    // Method to enqueue PayPal SDK script
    public function enqueue_paypal_sdk() {
    error_log('enqueue_paypal_sdk called.');

    $client_id = esc_attr(get_option('paypal_client_id'));
    if (!empty($client_id)) {
        wp_enqueue_script(
            'paypal-sdk',
            'https://www.paypal.com/sdk/js?client-id=' . $client_id . '&currency=USD',
            [],
            null,
            true // Ensure the script is loaded in the footer
        );

        // Custom script to ensure SDK is fully loaded before usage
        wp_add_inline_script(
            'paypal-sdk',
            'document.addEventListener("DOMContentLoaded", function() {
                var checkPayPal = setInterval(function() {
                    if (typeof paypal !== "undefined") {
                        clearInterval(checkPayPal);
                        // Trigger any PayPal related code here if necessary
                    }
                }, 500);
            });'
        );

        error_log('PayPal SDK script enqueued.');
    } else {
        error_log('PayPal Client ID is missing.');
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

        // Correctly access headers
        $signatureVerificationData = [
            'auth_algo' => isset($headers['paypal_auth_algo'][0]) ? $headers['paypal_auth_algo'][0] : '',
            'cert_url' => isset($headers['paypal_cert_url'][0]) ? $headers['paypal_cert_url'][0] : '',
            'transmission_id' => isset($headers['paypal_transmission_id'][0]) ? $headers['paypal_transmission_id'][0] : '',
            'transmission_sig' => isset($headers['paypal_transmission_sig'][0]) ? $headers['paypal_transmission_sig'][0] : '',
            'transmission_time' => isset($headers['paypal_transmission_time'][0]) ? $headers['paypal_transmission_time'][0] : '',
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

            .donations-module-table th, .donations-module-table td {
                border: 1px solid #e9ecef;
            }
        </style>
        <?php
    }

    // Method to handle the donation form shortcode
    public function donations_form_shortcode() {
        $goal = intval(get_option('donations_goal', 0));
        $current_total = $this->get_current_donations_total();
        $donations_count = $this->get_total_donations_count();
        $progress = $goal > 0 ? ($current_total / $goal) * 100 : 0;

        $progress_bar_color = esc_attr(get_option('progress_bar_color', '#00ff00'));
        $progress_bar_height = intval(get_option('progress_bar_height', 20));
        $progress_bar_well_color = esc_attr(get_option('progress_bar_well_color', '#eeeeee'));
        $progress_bar_well_width = intval(get_option('progress_bar_well_width', 100));
        $progress_bar_border_radius = intval(get_option('progress_bar_border_radius', 0));
        $text_color = esc_attr(get_option('donations_text_color', '#333333'));

        $show_amount_raised = get_option('show_amount_raised', '1');
        $show_percentage_of_goal = get_option('show_percentage_of_goal', '1');
        $show_number_of_donations = get_option('show_number_of_donations', '1');
        $show_cta_paragraph = get_option('show_cta_paragraph', '1');
        $cta_paragraph = esc_textarea(get_option('cta_paragraph', '¡Ayúdanos a alcanzar nuestra meta! Dona ahora a través de PayPal y marca la diferencia.'));
        $content_alignment = esc_attr(get_option('content_alignment', 'left'));

        ob_start();
        ?>
        <div class="donations-module-wrapper" style="text-align: <?php echo $content_alignment; ?>; color: <?php echo $text_color; ?>; padding: 20px 0;">
            <?php if ($show_cta_paragraph === '1'): ?>
                <p class="donations-cta-paragraph" style="margin-bottom: 20px;"><?php echo $cta_paragraph; ?></p>
            <?php endif; ?>

            <div class="donations-stats" style="margin-bottom: 10px;">
                <?php if ($show_amount_raised === '1'): ?>
                    <div>Total Recaudado: <strong><?php echo '$' . number_format($current_total, 2); ?></strong></div>
                <?php endif; ?>
                <?php if ($show_percentage_of_goal === '1'): ?>
                    <div>Meta Alcanzada: <strong><?php echo number_format($progress, 2); ?>%</strong></div>
                <?php endif; ?>
                <?php if ($show_number_of_donations === '1'): ?>
                    <div>Número de Donaciones: <strong><?php echo intval($donations_count); ?></strong></div>
                <?php endif; ?>
            </div>

            <div id="donation-progress" class="donations-progress-wrapper" style="background-color: <?php echo esc_attr($progress_bar_well_color); ?>; width: 100%; height: <?php echo esc_attr($progress_bar_height); ?>px; border-radius: <?php echo esc_attr($progress_bar_border_radius); ?>px; overflow: hidden;">
                <div id="progress-bar" class="donations-progress-bar" style="background-color: <?php echo esc_attr($progress_bar_color); ?>; height: 100%;"></div>
            </div>
            <p id="donation-summary" style="margin-top: 5px; text-align: left;"><?php echo '$' . number_format($current_total, 0) . ' de $' . number_format($goal, 0); ?></p>

            <form id="donations-form" class="donations-form" onsubmit="return false;" aria-labelledby="donations-form-heading">
                <input type="hidden" name="button_id" value="<?php echo esc_attr(get_option('paypal_hosted_button_id')); ?>">
                <input type="hidden" name="donation_nonce" value="<?php echo wp_create_nonce('save_donation'); ?>">
                <div id="paypal-button-container" style="margin-top: 10px; text-align: left;"></div>
            </form>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
    var checkPayPalInterval = setInterval(function() {
        if (typeof paypal !== 'undefined') {
            clearInterval(checkPayPalInterval);

            // Initialize PayPal buttons once the SDK is loaded
            paypal.Buttons({
                createOrder: function(data, actions) {
                    return actions.order.create({
                        purchase_units: [{
                            amount: {
                                value: '1.00' // Set a default value here
                            }
                        }]
                    });
                },
                onApprove: function(data, actions) {
                    return actions.order.capture().then(function(details) {
                        alert('Transaction completed by ' + details.payer.name.given_name);
                        var donationData = new FormData();
                        donationData.append('action', 'save_donation');
                        donationData.append('donation_nonce', '<?php echo wp_create_nonce('save_donation'); ?>');
                        donationData.append('transaction_id', data.orderID);
                        donationData.append('donation_amount', '1.00');

                        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                            method: 'POST',
                            body: donationData
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                document.getElementById('donation-summary').innerHTML = '<?php echo '$' . number_format($current_total, 0) . ' de $' . number_format($goal, 0); ?>';
                                var newProgress = goal > 0 ? (<?php echo $current_total; ?> / goal) * 100 : 0;
                                document.getElementById('progress-bar').style.width = newProgress + '%';
                            } else {
                                console.error('Error saving donation:', data.message);
                            }
                        })
                        .catch(error => {
                            console.error('Fetch error:', error);
                        });
                    });
                }
            }).render('#paypal-button-container');
        } else {
            console.error('Waiting for PayPal SDK to load...');
        }
    }, 500); // Check every 500ms
});

        </script>

        <style>
            .donations-module-wrapper .donations-cta-paragraph {
                font-size: 1.2em;
                margin-bottom: 20px;
            }

            .donations-module-wrapper .donations-stats div {
                font-size: 1em;
                margin-bottom: 5px;
            }

            .donations-module-wrapper .donations-progress-wrapper {
                margin-top: 10px;
            }

            .donations-module-wrapper #progress-bar {
                transition: width 0.5s ease-in-out;
                text-align: center;
                color: #fff;
                font-weight: bold;
                line-height: <?php echo esc_attr($progress_bar_height); ?>px;
            }

            .donations-module-wrapper #donation-summary {
                margin-top: 5px;
            }
        </style>
        <?php
        return ob_get_clean();
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
            'currency' => '', // Currency is not provided here
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

        // Update donation metrics
        update_option('total_amount_raised', $this->get_current_donations_total());
        update_option('number_of_donations', $this->get_total_donations_count());

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
