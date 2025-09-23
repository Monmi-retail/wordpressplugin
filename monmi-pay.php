<?php
/**
 * Plugin Name: Monmi Pay
 * Description: Provides a Monmi Pay payment gateway and settings for WooCommerce.
 * Version: 0.1.22
 * Author: Monmi
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'MONMI_PAY_PLUGIN_FILE' ) ) {
    define( 'MONMI_PAY_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'MONMI_PAY_PLUGIN_PATH' ) ) {
    define( 'MONMI_PAY_PLUGIN_PATH', plugin_dir_path( MONMI_PAY_PLUGIN_FILE ) );
}

if ( ! defined( 'MONMI_PAY_PLUGIN_URL' ) ) {
    define( 'MONMI_PAY_PLUGIN_URL', plugin_dir_url( MONMI_PAY_PLUGIN_FILE ) );
}

final class Monmi_Pay_Plugin {
    const OPTION_GROUP       = 'monmi_pay_settings';
    const OPTION_API_KEY     = 'monmi_pay_api_key';
    const OPTION_SECRET      = 'monmi_pay_secret_key';
    const OPTION_ENVIRONMENT = 'monmi_pay_environment';
    const VERSION            = '0.1.22';
    private const DEBUG_TRANSIENT_KEY = 'monmi_pay_last_request_snapshot';

    /** @var Monmi_Pay_Plugin|null */
    private static $instance = null;

    /** @var bool */
    private $woocommerce_active = false;
    /** @var array|null */
    private $last_request_context = null;

    /**
     * Retrieve the singleton instance.
     */
    public static function instance(): Monmi_Pay_Plugin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Monmi_Pay_Plugin constructor.
     */
    private function __construct() {
        $this->woocommerce_active = class_exists( 'WooCommerce' );

        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_menu', [ $this, 'register_settings_page' ] );

        if ( ! $this->woocommerce_active ) {
            add_action( 'admin_notices', [ $this, 'render_woocommerce_missing_notice' ] );
            return;
        }

        $this->include_files();

        add_filter( 'woocommerce_payment_gateways', [ $this, 'register_gateway' ] );
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
        add_action( 'init', [ $this, 'register_scripts' ] );
        add_action( 'enqueue_block_assets', [ $this, 'enqueue_block_assets' ] );
        add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_block_assets' ] );
        add_filter( 'woocommerce_blocks_payment_method_type_registration', [ $this, 'register_blocks_payment_method' ] );
        add_action( 'woocommerce_checkout_create_order', [ $this, 'persist_checkout_meta' ], 10, 2 );
    }

    /**
     * Include plugin PHP dependencies.
     */
    private function include_files(): void {
        if ( class_exists( 'Monmi_Pay_Gateway' ) ) {
            return;
        }

        if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
            add_action( 'woocommerce_loaded', [ $this, 'include_files' ] );
            return;
        }

        require_once MONMI_PAY_PLUGIN_PATH . 'includes/class-monmi-pay-gateway.php';
    }
    /**
     * Register shared script handles.
     */
    public function register_scripts(): void {
        if ( ! function_exists( 'wp_register_script' ) ) {
            return;
        }

        wp_register_script(
            'monmi-pay-sdk',
            'https://cdn-payment.monmi.uk/monmi-pay.js',
            [],
            null,
            true
        );

        wp_register_script(
            'monmi-pay-checkout',
            MONMI_PAY_PLUGIN_URL . 'js/monmi-checkout.js',
            [ 'jquery', 'monmi-pay-sdk' ],
            self::VERSION,
            true
        );

        $blocks_dependencies = [
            'wp-element',
            'wp-i18n',
            'wp-html-entities',
            'wc-settings',
            'wc-blocks-registry',
            'monmi-pay-sdk',
        ];
        $blocks_version      = self::VERSION;
        $asset_path          = MONMI_PAY_PLUGIN_PATH . 'build/monmi-blocks.asset.php';

        if ( file_exists( $asset_path ) ) {
            $asset = include $asset_path;
            if ( is_array( $asset ) ) {
                if ( ! empty( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ) {
                    $blocks_dependencies = $asset['dependencies'];
                }

                if ( ! empty( $asset['version'] ) ) {
                    $blocks_version = $asset['version'];
                }
            }
        }

        if ( ! in_array( 'monmi-pay-sdk', $blocks_dependencies, true ) ) {
            $blocks_dependencies[] = 'monmi-pay-sdk';
        }

        wp_register_script(
            'monmi-blocks',
            MONMI_PAY_PLUGIN_URL . 'build/monmi-blocks.js',
            $blocks_dependencies,
            $blocks_version,
            true
        );
    }

    /**
     * Ensure Blocks assets load alongside the payment method.
     */
    public function enqueue_block_assets(): void {
        if ( ! wp_script_is( 'monmi-pay-sdk', 'registered' ) || ! wp_script_is( 'monmi-blocks', 'registered' ) ) {
            $this->register_scripts();
        }

        wp_enqueue_script( 'monmi-pay-sdk' );
        wp_enqueue_script( 'monmi-blocks' );
    }

    /**
     * Register the WooCommerce Blocks payment method integration.
     *
     * @param mixed $payment_method_types Payment method registry or legacy array.
     */
    public function register_blocks_payment_method( $payment_method_types ) {
        if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
            return $payment_method_types;
        }

        if ( ! class_exists( 'Monmi_Pay_Blocks_Payment_Method' ) ) {
            $integration_path = MONMI_PAY_PLUGIN_PATH . 'includes/class-monmi-pay-blocks.php';

            if ( file_exists( $integration_path ) ) {
                require_once $integration_path;
            } else {
                return $payment_method_types;
            }
        }

        if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry' )
            && $payment_method_types instanceof Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry ) {
            if ( ! $payment_method_types->has( Monmi_Pay_Gateway::GATEWAY_ID ) ) {
                $payment_method_types->register( new Monmi_Pay_Blocks_Payment_Method( $this ) );
            }

            return $payment_method_types;
        }

        if ( is_array( $payment_method_types ) ) {
            foreach ( $payment_method_types as $payment_method_type ) {
                if ( $payment_method_type instanceof Monmi_Pay_Blocks_Payment_Method ) {
                    return $payment_method_types;
                }
            }

            $payment_method_types[] = new Monmi_Pay_Blocks_Payment_Method( $this );
        }

        return $payment_method_types;
    }
    /**
     * Register plugin settings.
     */
    public function register_settings(): void {
        register_setting(
            self::OPTION_GROUP,
            self::OPTION_API_KEY,
            [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ]
        );

        register_setting(
            self::OPTION_GROUP,
            self::OPTION_SECRET,
            [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ]
        );

        register_setting(
            self::OPTION_GROUP,
            self::OPTION_ENVIRONMENT,
            [
                'type'              => 'string',
                'sanitize_callback' => [ $this, 'sanitize_environment' ],
                'default'           => 'development',
            ]
        );

        add_settings_section(
            'monmi_pay_api_section',
            __( 'Monmi API Credentials', 'monmi-pay' ),
            '__return_false',
            self::OPTION_GROUP
        );

        add_settings_field(
            self::OPTION_API_KEY,
            __( 'X-API-Key', 'monmi-pay' ),
            [ $this, 'render_api_key_field' ],
            self::OPTION_GROUP,
            'monmi_pay_api_section'
        );

        add_settings_field(
            self::OPTION_SECRET,
            __( 'Secret Key', 'monmi-pay' ),
            [ $this, 'render_secret_field' ],
            self::OPTION_GROUP,
            'monmi_pay_api_section'
        );

        add_settings_section(
            'monmi_pay_env_section',
            __( 'Environment', 'monmi-pay' ),
            '__return_false',
            self::OPTION_GROUP
        );

        add_settings_field(
            self::OPTION_ENVIRONMENT,
            __( 'Environment', 'monmi-pay' ),
            [ $this, 'render_environment_field' ],
            self::OPTION_GROUP,
            'monmi_pay_env_section'
        );
    }

    /**
     * Register admin menu entry.
     */
    public function register_settings_page(): void {
        add_options_page(
            __( 'Monmi Pay', 'monmi-pay' ),
            __( 'Monmi Pay', 'monmi-pay' ),
            'manage_options',
            'monmi-pay',
            [ $this, 'render_settings_page' ]
        );
    }

    /**
     * Render the settings page.
     */
    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Monmi Pay Settings', 'monmi-pay' ); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( self::OPTION_GROUP );
                do_settings_sections( self::OPTION_GROUP );
                submit_button();
                ?>
            </form>
            <div class="monmi-pay-settings__webhook">
                <h2><?php esc_html_e( 'Webhook Endpoint', 'monmi-pay' ); ?></h2>
                <p><?php esc_html_e( 'Use this URL in the Monmi merchant dashboard to receive payment status updates.', 'monmi-pay' ); ?></p>
                <code><?php echo esc_html( rest_url( 'monmi-pay/v1/webhook' ) ); ?></code>
            </div>
            <?php $this->render_payment_methods_overview(); ?>
        </div>
        <?php
    }

    /**
     * Output WooCommerce missing notice.
     */
    public function render_woocommerce_missing_notice(): void {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }

        echo '<div class="notice notice-error"><p>' . esc_html__( 'Monmi Pay requires WooCommerce to be installed and active.', 'monmi-pay' ) . '</p></div>';
    }

    /**
     * Render API key field.
     */
    public function render_api_key_field(): void {
        $value = get_option( self::OPTION_API_KEY, '' );
        printf(
            '<input type="text" class="regular-text" name="%1$s" value="%2$s" autocomplete="off" />',
            esc_attr( self::OPTION_API_KEY ),
            esc_attr( $value )
        );
    }

    /**
     * Render secret field.
     */
    public function render_secret_field(): void {
        $value = get_option( self::OPTION_SECRET, '' );
        printf(
            '<input type="password" class="regular-text" name="%1$s" value="%2$s" autocomplete="off" />',
            esc_attr( self::OPTION_SECRET ),
            esc_attr( $value )
        );
    }

    /**
     * Render environment select field.
     */
    public function render_environment_field(): void {
        $value   = get_option( self::OPTION_ENVIRONMENT, 'development' );
        $options = $this->get_environment_options();

        echo '<select name="' . esc_attr( self::OPTION_ENVIRONMENT ) . '">';
        foreach ( $options as $key => $label ) {
            printf(
                '<option value="%1$s" %2$s>%3$s</option>',
                esc_attr( $key ),
                selected( $value, $key, false ),
                esc_html( $label )
            );
        }
        echo '</select>';
    }

    /**
     * Sanitize environment option.
     */
    public function sanitize_environment( string $value ): string {
        $value   = strtolower( $value );
        $options = array_keys( $this->get_environment_options() );

        return in_array( $value, $options, true ) ? $value : 'development';
    }

    /**
     * Provide environment choices.
     */
    public function get_environment_options(): array {
        return [
            'development' => __( 'Development (Testing/Staging)', 'monmi-pay' ),
            'production'  => __( 'Production (Live)', 'monmi-pay' ),
        ];
    }

    /**
     * Render available payment methods fetched from Monmi.
     */
    public function render_payment_methods_overview(): void {
        echo '<div class="monmi-pay-settings__methods">';
        echo '<h2>' . esc_html__( 'Available Payment Methods', 'monmi-pay' ) . '</h2>';

        $api_key = trim( (string) get_option( self::OPTION_API_KEY, '' ) );
        $secret  = trim( (string) get_option( self::OPTION_SECRET, '' ) );

        if ( '' === $api_key || '' === $secret ) {
            echo '<p>' . esc_html__( 'Enter your API credentials and save changes to load payment methods.', 'monmi-pay' ) . '</p>';
            echo '</div>';
            return;
        }

        $methods = $this->fetch_remote_payment_methods();

        if ( is_wp_error( $methods ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html( $methods->get_error_message() ) . '</p></div>';
            echo '</div>';
            return;
        }

        if ( empty( $methods ) ) {
            echo '<p>' . esc_html__( 'No payment methods are available for this environment.', 'monmi-pay' ) . '</p>';
        } else {
            echo '<ul class="monmi-pay-settings__methods-list">';
            foreach ( $methods as $method ) {
                echo '<li><code>' . esc_html( $method ) . '</code></li>';
            }
            echo '</ul>';
        }

        $environment       = $this->sanitize_environment( get_option( self::OPTION_ENVIRONMENT, 'development' ) );
        $environment_map   = $this->get_environment_options();
        $environment_label = $environment_map[ $environment ] ?? ucfirst( $environment );

        echo '<p class="description">' . sprintf( esc_html__( 'Environment: %s', 'monmi-pay' ), esc_html( $environment_label ) ) . '</p>';

        if ( current_user_can( 'manage_options' ) ) {
            $debug_snapshot = $this->prepare_api_debug_snapshot();
            if ( $debug_snapshot ) {
                echo '<details class="monmi-pay-settings__debug" style="margin-top:1em;">';
                echo '<summary>' . esc_html__( 'Debug: Last Monmi API call', 'monmi-pay' ) . '</summary>';
                echo '<pre style="white-space:pre-wrap;">' . esc_html( wp_json_encode( $debug_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</pre>';
                echo '</details>';
            }
        }

        echo '</div>';
    }



    /**
     * Prepare a masked snapshot of the last Monmi API call for display.
     */
    private function prepare_api_debug_snapshot(): ?array {
        if ( ! empty( $this->last_request_context ) ) {
            return $this->prepare_api_debug_snapshot_from_context( $this->last_request_context );
        }

        $cached = get_transient( self::DEBUG_TRANSIENT_KEY );

        return is_array( $cached ) ? $cached : null;
    }

    /**
     * Build a sanitized snapshot array from a raw context payload.
     */
    private function prepare_api_debug_snapshot_from_context( array $context ): ?array {
        if ( empty( $context ) ) {
            return null;
        }

        $request  = $context['request'] ?? [];
        $response = $context['response'] ?? null;
        $error    = $context['error'] ?? null;

        $headers = [];
        if ( ! empty( $request['headers'] ) && is_array( $request['headers'] ) ) {
            foreach ( $request['headers'] as $name => $value ) {
                $headers[ $name ] = $this->mask_header_value( (string) $name, $value );
            }
        }

        $request_snapshot = [
            'method'      => $request['method'] ?? '',
            'url'         => $request['url'] ?? '',
            'endpoint'    => $request['endpoint'] ?? '',
            'environment' => $request['environment'] ?? '',
            'headers'     => $headers,
        ];

        if ( array_key_exists( 'body', $request ) ) {
            $request_snapshot['body'] = $this->mask_sensitive_structure( $request['body'] );
        }

        if ( array_key_exists( 'body_raw', $request ) && null !== $request['body_raw'] ) {
            $request_snapshot['body_raw'] = $request['body_raw'];
        }

        $response_snapshot = null;
        if ( null !== $response ) {
            $response_snapshot = [
                'status'  => $response['status'] ?? null,
                'headers' => $response['headers'] ?? [],
            ];

            if ( array_key_exists( 'body_raw', $response ) ) {
                $response_snapshot['body_raw'] = $response['body_raw'];
            }

            if ( array_key_exists( 'body_decoded', $response ) ) {
                $response_snapshot['body_decoded'] = $this->mask_sensitive_structure( $response['body_decoded'] );
            }
        }

        return [
            'request'  => $request_snapshot,
            'response' => $response_snapshot,
            'error'    => $error,
        ];
    }

    /**
     * Persist the last request context and store a sanitized snapshot.
     */
    private function store_request_context( array $context ): void {
        $this->last_request_context = $context;

        $snapshot = $this->prepare_api_debug_snapshot_from_context( $context );
        if ( $snapshot ) {
            set_transient( self::DEBUG_TRANSIENT_KEY, $snapshot, 15 * MINUTE_IN_SECONDS );
        }
    }

    /**
     * Log request failures via the WooCommerce logger.
     */
    private function log_request_error( array $context, WP_Error $error ): void {
        if ( ! function_exists( 'wc_get_logger' ) ) {
            return;
        }

        $snapshot = $this->prepare_api_debug_snapshot_from_context( $context );
        $logger   = wc_get_logger();

        $logger->error(
            sprintf( '[Monmi Pay] %s: %s', $error->get_error_code(), $error->get_error_message() ),
            [
                'source'  => 'monmi-pay',
                'context' => [
                    'error_code' => $error->get_error_code(),
                    'error_data' => $error->get_error_data(),
                    'request'    => $snapshot['request'] ?? null,
                    'response'   => $snapshot['response'] ?? null,
                ],
            ]
        );
    }

    /**
     * Mask sensitive header values before rendering.    /**
     * Mask sensitive header values before rendering.
     *
     * @param string $header Header name.
     * @param mixed  $value  Header value.
     * @return mixed
     */
    private function mask_header_value( string $header, $value ) {
        if ( is_array( $value ) ) {
            foreach ( $value as $key => $item ) {
                $value[ $key ] = $this->mask_header_value( $header, $item );
            }
            return $value;
        }

        if ( ! is_scalar( $value ) ) {
            return $value;
        }

        $header = strtolower( $header );
        $value  = (string) $value;
        $sensitive_headers = [ 'x-api-key', 'x-api-signature', 'authorization', 'x-api-secret' ];

        if ( in_array( $header, $sensitive_headers, true ) ) {
            return $this->mask_sensitive_value( $value );
        }

        return $value;
    }

    /**
     * Recursively mask sensitive data within arrays.
     *
     * @param mixed $data Arbitrary data structure.
     * @return mixed
     */
    private function mask_sensitive_structure( $data ) {
        if ( is_array( $data ) ) {
            $masked = [];
            foreach ( $data as $key => $value ) {
                if ( is_string( $key ) && $this->is_sensitive_key( $key ) && is_scalar( $value ) ) {
                    $masked[ $key ] = $this->mask_sensitive_value( (string) $value );
                } else {
                    $masked[ $key ] = $this->mask_sensitive_structure( $value );
                }
            }
            return $masked;
        }

        if ( is_object( $data ) ) {
            return $this->mask_sensitive_structure( (array) $data );
        }

        return $data;
    }

    /**
     * Mask a sensitive scalar value while preserving its shape.
     */
    private function mask_sensitive_value( string $value ): string {
        $length = strlen( $value );
        if ( 0 === $length ) {
            return $value;
        }

        if ( $length <= 8 ) {
            return str_repeat( '*', max( 0, $length - 2 ) ) . substr( $value, -2 );
        }

        $visible = 4;
        $masked_length = max( 0, $length - ( 2 * $visible ) );
        return substr( $value, 0, $visible ) . str_repeat( '*', $masked_length ) . substr( $value, -$visible );
    }

    /**
     * Determine if an array key likely contains sensitive data.
     */
    private function is_sensitive_key( string $key ): bool {
        $key = strtolower( $key );
        $sensitive_keys = [
            'secret',
            'secret_key',
            'secretkey',
            'client_secret',
            'token',
            'payment_token',
            'signature',
            'api_key',
            'apikey',
            'code',
            'authorization',
        ];

        return in_array( $key, $sensitive_keys, true );
    }

    /**
     * Fetch payment methods from Monmi API.
     *
     * @return array|WP_Error
     */
    private function fetch_remote_payment_methods() {
        $environment = $this->sanitize_environment( get_option( self::OPTION_ENVIRONMENT, 'development' ) );
        $cache_key   = 'monmi_pay_methods_' . $environment;
        $cached      = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $response = $this->request_monmi_api( '/api/v1/payment/methods', [], 'GET' );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = $response['body'];

        if ( isset( $body['errorCode'] ) && (int) $body['errorCode'] !== 0 ) {
            $message = ! empty( $body['message'] ) ? sanitize_text_field( (string) $body['message'] ) : __( 'Monmi API reported an error while retrieving payment methods.', 'monmi-pay' );
            return new WP_Error( 'monmi_methods_error', $message );
        }

        $data = $body['data'] ?? [];

        if ( empty( $data ) || ! is_array( $data ) ) {
            set_transient( $cache_key, [], 5 * MINUTE_IN_SECONDS );
            return [];
        }

        $methods = [];
        foreach ( $data as $method ) {
            if ( is_string( $method ) && '' !== $method ) {
                $methods[] = sanitize_text_field( $method );
            }
        }

        $methods = array_values( array_unique( $methods ) );

        set_transient( $cache_key, $methods, 15 * MINUTE_IN_SECONDS );

        return $methods;
    }
    /**
     * Register WooCommerce payment gateway.
     */
    public function register_gateway( array $gateways ): array {
        if ( class_exists( 'Monmi_Pay_Gateway' ) ) {
            $gateways[] = Monmi_Pay_Gateway::class;
        }

        return $gateways;
    }

    /**
     * Register REST API routes.
     */
    public function register_rest_routes(): void {
        register_rest_route(
            'monmi-pay/v1',
            '/create-payment',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'rest_create_payment' ],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            'monmi-pay/v1',
            '/webhook',
            [
                'methods'             => [ WP_REST_Server::CREATABLE, WP_REST_Server::READABLE ],
                'callback'            => [ $this, 'rest_webhook' ],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * Handle create payment REST request.
     */
    public function rest_create_payment( WP_REST_Request $request ) {
        $nonce = $request->get_header( 'x-wp-nonce' );
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new WP_Error( 'monmi_invalid_nonce', __( 'Invalid request signature.', 'monmi-pay' ), [ 'status' => 403 ] );
        }

        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return new WP_Error( 'monmi_no_session', __( 'WooCommerce session not available.', 'monmi-pay' ), [ 'status' => 400 ] );
        }

        if ( function_exists( 'wc_load_cart' ) ) {
            wc_load_cart();
        }

        $payload = $this->build_payment_payload( $request );
        if ( is_wp_error( $payload ) ) {
            return $payload;
        }

        $response = $this->request_monmi_api( '/api/v1/payment', $payload );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = $response['body'] ?? [];
        $data = $body['data'] ?? [];

        if ( empty( $data ) || empty( $data['token'] ) ) {
            return new WP_Error(
                'monmi_missing_token',
                __( 'Payment token missing from Monmi response.', 'monmi-pay' ),
                [
                    'status' => 500,
                    'body'   => $body,
                ]
            );
        }

        $token  = sanitize_text_field( (string) $data['token'] );
        $code   = isset( $data['code'] ) ? sanitize_text_field( (string) $data['code'] ) : '';
        $status = isset( $data['status'] ) ? sanitize_text_field( (string) $data['status'] ) : '';

        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set(
                'monmi_payment_session',
                [
                    'token'  => $token,
                    'code'   => $code,
                    'status' => $status,
                    'data'   => $data,
                ]
            );
        }

        return rest_ensure_response(
            [
                'token'   => $token,
                'payment' => $data,
                'status'  => $status,
                'message' => $body['message'] ?? '',
            ]
        );
    }

    public function rest_webhook( WP_REST_Request $request ) {
        $signature  = $request->get_header( 'x-api-signature' );
        $request_id = $request->get_header( 'x-request-id' );
        $timestamp  = $request->get_header( 'x-timestamp' );

        if ( ! $signature || ! $request_id || ! $timestamp ) {
            return new WP_Error( 'monmi_webhook_missing_headers', __( 'Webhook headers missing required authentication values.', 'monmi-pay' ), [ 'status' => 401 ] );
        }

        $secret = get_option( self::OPTION_SECRET, '' );
        if ( empty( $secret ) ) {
            return new WP_Error( 'monmi_webhook_secret_missing', __( 'Monmi secret key not configured.', 'monmi-pay' ), [ 'status' => 500 ] );
        }

        $expected = $this->generate_signature( $secret, $request_id, $timestamp );
        if ( ! hash_equals( $expected, $signature ) ) {
            return new WP_Error( 'monmi_webhook_invalid_signature', __( 'Webhook signature verification failed.', 'monmi-pay' ), [ 'status' => 401 ] );
        }

        $payload = $request->get_json_params();
        if ( empty( $payload ) || ! is_array( $payload ) ) {
            return new WP_Error( 'monmi_webhook_empty_payload', __( 'Webhook payload is empty or invalid.', 'monmi-pay' ), [ 'status' => 400 ] );
        }

        $order = $this->find_order_from_webhook( $payload );
        if ( is_wp_error( $order ) ) {
            return $order;
        }

        $status = isset( $payload['status'] ) ? sanitize_text_field( (string) $payload['status'] ) : '';
        $token  = isset( $payload['token'] ) ? sanitize_text_field( (string) $payload['token'] ) : '';
        $code   = isset( $payload['code'] ) ? sanitize_text_field( (string) $payload['code'] ) : '';

        if ( $status ) {
            $order->update_meta_data( '_monmi_gateway_status', $status );
        }

        if ( $token && $order->get_meta( '_monmi_payment_token' ) !== $token ) {
            $order->update_meta_data( '_monmi_payment_token', $token );
        }

        if ( $code && $order->get_meta( '_monmi_payment_code' ) !== $code ) {
            $order->update_meta_data( '_monmi_payment_code', $code );
        }

        $order->update_meta_data( '_monmi_webhook_payload', wp_json_encode( $payload ) );

        $this->maybe_update_order_status_from_webhook( $order, $status );

        $order->save();

        return rest_ensure_response(
            [
                'success' => true,
                'status'  => $status,
            ]
        );
    }

    /**
     * Persist checkout data into order meta.
     */
    /**
     * Persist checkout data into order meta.
     */
    public function persist_checkout_meta( $order, array $data ): void {
        if ( ! class_exists( 'WC_Order' ) || ! ( $order instanceof WC_Order ) ) {
            return;
        }
        if ( ! class_exists( 'Monmi_Pay_Gateway' ) ) {
            return;
        }

        $selected_method = $data['payment_method'] ?? ( isset( $_POST['payment_method'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_method'] ) ) : '' );
        if ( Monmi_Pay_Gateway::GATEWAY_ID !== $selected_method ) {
            return;
        }

        $payment_method_data = isset( $data['payment_method_data'] ) && is_array( $data['payment_method_data'] )
            ? $data['payment_method_data']
            : [];

        $token         = '';
        $code          = '';
        $status        = '';
        $payload_raw   = '';
        $payload_array = null;

        if ( ! empty( $payment_method_data ) ) {
            $token  = isset( $payment_method_data['token'] ) ? sanitize_text_field( (string) $payment_method_data['token'] ) : '';
            if ( ! $token && isset( $payment_method_data['monmi_payment_token'] ) ) {
                $token = sanitize_text_field( (string) $payment_method_data['monmi_payment_token'] );
            }

            $code   = isset( $payment_method_data['code'] ) ? sanitize_text_field( (string) $payment_method_data['code'] ) : '';
            if ( ! $code && isset( $payment_method_data['monmi_payment_code'] ) ) {
                $code = sanitize_text_field( (string) $payment_method_data['monmi_payment_code'] );
            }

            $status = isset( $payment_method_data['status'] ) ? sanitize_text_field( (string) $payment_method_data['status'] ) : '';
            if ( ! $status && isset( $payment_method_data['monmi_payment_status'] ) ) {
                $status = sanitize_text_field( (string) $payment_method_data['monmi_payment_status'] );
            }

            if ( array_key_exists( 'payload', $payment_method_data ) ) {
                $payload_value = $payment_method_data['payload'];
            } elseif ( array_key_exists( 'data', $payment_method_data ) ) {
                $payload_value = $payment_method_data['data'];
            } elseif ( array_key_exists( 'monmi_payment_payload', $payment_method_data ) ) {
                $payload_value = $payment_method_data['monmi_payment_payload'];
            } else {
                $payload_value = null;
            }

            if ( is_string( $payload_value ) ) {
                $payload_raw = $payload_value;
                $decoded     = json_decode( $payload_raw, true );
                if ( null !== $decoded ) {
                    $payload_array = $decoded;
                }
            } elseif ( is_array( $payload_value ) ) {
                $payload_array = $payload_value;
            }
        }

        if ( empty( $payment_method_data ) ) {
            if ( isset( $_POST['monmi_payment_token'] ) ) {
                $token = sanitize_text_field( wp_unslash( $_POST['monmi_payment_token'] ) );
            }

            if ( isset( $_POST['monmi_payment_code'] ) ) {
                $code = sanitize_text_field( wp_unslash( $_POST['monmi_payment_code'] ) );
            }

            if ( isset( $_POST['monmi_payment_status'] ) ) {
                $status = sanitize_text_field( wp_unslash( $_POST['monmi_payment_status'] ) );
            }

            if ( isset( $_POST['monmi_payment_payload'] ) ) {
                $payload_raw = wp_unslash( $_POST['monmi_payment_payload'] );
                if ( is_string( $payload_raw ) && '' !== $payload_raw ) {
                    $decoded = json_decode( $payload_raw, true );
                    if ( null !== $decoded ) {
                        $payload_array = $decoded;
                    }
                }
            }
        }

        if ( $token ) {
            $order->update_meta_data( '_monmi_payment_token', $token );
        }

        if ( $code ) {
            $order->update_meta_data( '_monmi_payment_code', $code );
        }

        if ( $status ) {
            $order->update_meta_data( '_monmi_payment_status', $status );
        }

        if ( is_array( $payload_array ) ) {
            if ( function_exists( 'wc_clean' ) ) {
                $payload_array = wc_clean( $payload_array );
            }

            $order->update_meta_data( '_monmi_payment_payload', wp_json_encode( $payload_array ) );
        } elseif ( is_string( $payload_raw ) && '' !== $payload_raw ) {
            $order->update_meta_data( '_monmi_payment_payload_raw', sanitize_textarea_field( $payload_raw ) );
        }

        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->__unset( 'monmi_payment_session' );
        }
    }

    /**
     * Inject checkout bootstrap markup after the checkout form.
     */
    public function inject_checkout_bootstrap(): void {
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
            return;
        }

        if ( ! $this->is_gateway_available_for_checkout() ) {
            return;
        }

        echo '<div id="monmi-pay-bootstrap" data-monmi-pay="1"></div>';
        echo '<script type="text/javascript">window.MonmiPayCheckoutHook = true;</script>';
    }

    /**
     * Determine if the Monmi gateway is available on checkout.
     */
    private function is_gateway_available_for_checkout(): bool {
        if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
            return false;
        }

        $gateways = WC()->payment_gateways();
        if ( ! is_object( $gateways ) ) {
            return false;
        }
        if ( ! $gateways ) {
            return false;
        }

        $available = $gateways->get_available_payment_gateways();

        return isset( $available[ Monmi_Pay_Gateway::GATEWAY_ID ] );
    }

    /**
     * Build Monmi payment payload from request and cart context.
     */
    private function find_order_from_webhook( array $payload ) {
        if ( ! class_exists( 'WC_Order' ) ) {
            return new WP_Error( 'monmi_webhook_unavailable', __( 'WooCommerce order class unavailable.', 'monmi-pay' ), [ 'status' => 500 ] );
        }
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return new WP_Error( 'monmi_webhook_unavailable', __( 'WooCommerce helpers are unavailable.', 'monmi-pay' ), [ 'status' => 500 ] );
        }

        if ( isset( $payload['order_id'] ) && is_numeric( $payload['order_id'] ) ) {
            $order = wc_get_order( absint( $payload['order_id'] ) );
            if ( $order instanceof WC_Order ) {
                return $order;
            }
        }

        $meta_map = [
            '_monmi_payment_token'          => isset( $payload['token'] ) ? $payload['token'] : '',
            '_monmi_payment_code'           => isset( $payload['code'] ) ? $payload['code'] : '',
            '_monmi_partner_transaction_id' => isset( $payload['partnerTransactionId'] ) ? $payload['partnerTransactionId'] : '',
        ];

        foreach ( $meta_map as $meta_key => $meta_value ) {
            if ( '' === $meta_value ) {
                continue;
            }

            $orders = wc_get_orders( [
                'limit'      => 1,
                'meta_key'   => $meta_key,
                'meta_value' => sanitize_text_field( (string) $meta_value ),
            ] );

            if ( ! empty( $orders ) && $orders[0] instanceof WC_Order ) {
                return $orders[0];
            }
        }

        return new WP_Error( 'monmi_webhook_order_not_found', __( 'Unable to locate order for Monmi webhook.', 'monmi-pay' ), [ 'status' => 404 ] );
    }

    private function maybe_update_order_status_from_webhook( WC_Order $order, string $status ): void {
        $status = trim( strtolower( $status ) );
        if ( '' === $status ) {
            return;
        }

        $order->add_order_note( sprintf( __( 'Monmi webhook status: %s', 'monmi-pay' ), $status ) );

        if ( $this->is_webhook_success_status( $status ) ) {
            if ( ! $order->is_paid() ) {
                $order->payment_complete();
            }
            return;
        }

        if ( $this->is_webhook_failed_status( $status ) ) {
            if ( ! $order->has_status( 'failed' ) ) {
                $order->update_status( 'failed', __( 'Monmi reported the payment as failed via webhook.', 'monmi-pay' ) );
            }
        }
    }

    private function is_webhook_success_status( string $status ): bool {
        $success_values = [ 'success', 'succeeded', 'paid', 'completed', 'complete', 'authorised', 'authorized' ];
        if ( in_array( $status, $success_values, true ) ) {
            return true;
        }

        $numeric_success = [ '9', '0', '00', '200' ];
        return in_array( $status, $numeric_success, true );
    }

    private function is_webhook_failed_status( string $status ): bool {
        $failed_values = [ 'failed', 'declined', 'cancelled', 'canceled', 'voided', 'refused' ];
        if ( in_array( $status, $failed_values, true ) ) {
            return true;
        }

        $numeric_failed = [ '10', '400', '402', '500' ];
        return in_array( $status, $numeric_failed, true );
    }

    private function build_payment_payload( WP_REST_Request $request ) {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return new WP_Error( 'monmi_no_cart', __( 'WooCommerce cart not available.', 'monmi-pay' ), [ 'status' => 400 ] );
        }

        $billing  = $this->sanitize_address( (array) $request->get_param( 'billing' ) );
        $shipping = $this->sanitize_address( (array) $request->get_param( 'shipping' ) );

        $cart     = WC()->cart;
        $currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
        $items    = $this->build_cart_items( $cart );

        if ( empty( $items ) ) {
            return new WP_Error( 'monmi_missing_items', __( 'Unable to determine cart items for Monmi payment.', 'monmi-pay' ), [ 'status' => 400 ] );
        }

        $transaction_id = wp_generate_uuid4();
        $timestamp      = (int) round( microtime( true ) * 1000 );
        $discount_total = (float) $cart->get_discount_total();

        $store = [
            'name'    => get_bloginfo( 'name' ),
            'email'   => sanitize_email( get_bloginfo( 'admin_email' ) ),
            'orderId' => $transaction_id,
        ];

        $payer = [
            'firstName' => $billing['first_name'] ?? '',
            'lastName'  => $billing['last_name'] ?? '',
            'email'     => $billing['email'] ?? '',
            'phone'     => $billing['phone'] ?? '',
            'address'   => [
                'line'    => $billing['address_1'] ?? '',
                'street'  => $billing['address_2'] ?? '',
                'city'    => $billing['city'] ?? '',
                'state'   => $billing['state'] ?? '',
                'country' => $billing['country'] ?? '',
                'zipCode' => $billing['postcode'] ?? '',
            ],
        ];

        $return_url = esc_url_raw( add_query_arg( 'monmi', 'success', wc_get_checkout_url() ) );
        $cancel_url = esc_url_raw( add_query_arg( 'monmi', 'cancel', wc_get_checkout_url() ) );

        $payload = [
            'timestamp'     => $timestamp,
            'transactionId' => $transaction_id,
            'method'        => 'CARD',
            'currency'      => $currency,
            'items'         => $items,
            'store'         => $store,
            'payer'         => $payer,
            'discount'      => $discount_total,
            'returnUrl'     => $return_url,
            'cancelUrl'     => $cancel_url,
        ];

        /**
         * Filter the payment payload before it is sent to Monmi.
         */
        return apply_filters( 'monmi_pay_payment_payload', $payload, $request, $cart );
    }

    /**
     * Sanitize address data array.
     */
    private function sanitize_address( array $address ): array {
        $fields = [
            'first_name',
            'last_name',
            'company',
            'address_1',
            'address_2',
            'city',
            'state',
            'postcode',
            'country',
            'email',
            'phone',
        ];

        $sanitized = [];
        foreach ( $fields as $field ) {
            if ( isset( $address[ $field ] ) ) {
                $sanitized[ $field ] = sanitize_text_field( wp_unslash( $address[ $field ] ) );
            }
        }

        return $sanitized;
    }

    /**
     * Build cart items payload for Monmi API.
     */
    private function build_cart_items( WC_Cart $cart ): array {
        $items = [];

        foreach ( $cart->get_cart() as $cart_item ) {
            $product  = isset( $cart_item['data'] ) && $cart_item['data'] instanceof WC_Product ? $cart_item['data'] : null;
            $name     = $product ? $product->get_name() : __( 'Item', 'monmi-pay' );
            $quantity = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 0;
            $quantity = $quantity > 0 ? $quantity : 1;

            $line_total = isset( $cart_item['line_total'] ) ? (float) $cart_item['line_total'] : 0.0;
            if ( 0 >= $line_total && $product ) {
                $line_total = function_exists( 'wc_get_price_excluding_tax' ) ? (float) wc_get_price_excluding_tax( $product ) : 0.0;
                if ( 0 >= $line_total ) {
                    $line_total = (float) $product->get_price();
                }
            }

            $unit_total = $quantity > 0 ? ( $line_total / $quantity ) : $line_total;

            $items[] = [
                'name'     => $name,
                'amount'   => $this->format_amount( $unit_total ),
                'quantity' => (string) $quantity,
            ];
        }

        return $items;
    }

    /**
     * Format amount for API payload.
     */
    public function format_amount( float $amount ): string {
        return number_format( $amount, 2, '.', '' );
    }




    /**
     * Execute request to Monmi API.
     */
    public function request_monmi_api( string $endpoint, array $body = [], string $method = 'POST' ) {
        $api_key = get_option( self::OPTION_API_KEY, '' );
        $secret  = get_option( self::OPTION_SECRET, '' );
        $env     = get_option( self::OPTION_ENVIRONMENT, 'development' );
        $base    = $this->get_environment_base_url( $env );

        $method = strtoupper( $method );
        $context = [
            'request'  => [
                'endpoint'     => $endpoint,
                'method'       => $method,
                'environment'  => $env,
                'body'         => ! empty( $body ) ? $body : null,
                'body_raw'     => null,
            ],
            'response' => null,
            'error'    => null,
        ];

        if ( empty( $api_key ) || empty( $secret ) ) {
            $error = new WP_Error( 'monmi_missing_credentials', __( 'Monmi API credentials are missing.', 'monmi-pay' ) );
            $context['error'] = [
                'code'    => $error->get_error_code(),
                'message' => $error->get_error_message(),
                'data'    => $error->get_error_data(),
            ];
            $this->store_request_context( $context );
            return $error;
        }

        if ( ! $base ) {
            $error = new WP_Error( 'monmi_missing_base', __( 'Monmi API base URL is not configured.', 'monmi-pay' ) );
            $context['error'] = [
                'code'    => $error->get_error_code(),
                'message' => $error->get_error_message(),
                'data'    => $error->get_error_data(),
            ];
            $this->store_request_context( $context );
            return $error;
        }

        $request_id = wp_generate_uuid4();
        $timestamp  = (string) (int) round( microtime( true ) * 1000 );
        $headers    = [
            'Content-Type'    => 'application/json',
            'x-api-key'       => $api_key,
            'x-request-id'    => $request_id,
            'x-timestamp'     => $timestamp,
            'x-api-signature' => $this->generate_signature( $secret, $request_id, $timestamp ),
        ];

        $context['request']['headers'] = $headers;

        $args = [
            'method'  => $method,
            'headers' => $headers,
            'timeout' => 20,
        ];

        if ( ! empty( $body ) && 'GET' !== $method ) {
            $args['body']                  = wp_json_encode( $body );
            $context['request']['body_raw'] = $args['body'];
        }

        $url = trailingslashit( $base ) . ltrim( $endpoint, '/' );
        $context['request']['url'] = $url;

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            $context['error'] = [
                'code'    => $response->get_error_code(),
                'message' => $response->get_error_message(),
                'data'    => $response->get_error_data(),
            ];
            $this->store_request_context( $context );
            $this->log_request_error( $context, $response );
            return $response;
        }

        $code         = wp_remote_retrieve_response_code( $response );
        $raw_headers  = wp_remote_retrieve_headers( $response );
        if ( is_object( $raw_headers ) && method_exists( $raw_headers, 'getAll' ) ) {
            $raw_headers = $raw_headers->getAll();
        } elseif ( ! is_array( $raw_headers ) ) {
            $raw_headers = [];
        }

        $response_body_raw = wp_remote_retrieve_body( $response );

        $context['response'] = [
            'status'   => $code,
            'headers'  => $raw_headers,
            'body_raw' => $response_body_raw,
        ];

        if ( $code < 200 || $code >= 300 ) {
            $error = new WP_Error(
                'monmi_api_http_error',
                sprintf( __( 'Monmi API responded with HTTP %d.', 'monmi-pay' ), $code ),
                [
                    'status' => $code,
                    'body'   => $response_body_raw,
                ]
            );
            $context['error'] = [
                'code'    => $error->get_error_code(),
                'message' => $error->get_error_message(),
                'data'    => $error->get_error_data(),
            ];
            $this->store_request_context( $context );
            $this->log_request_error( $context, $error );
            return $error;
        }

        $decoded = json_decode( $response_body_raw, true );
        if ( null === $decoded ) {
            $error = new WP_Error( 'monmi_api_invalid_json', __( 'Unable to parse Monmi API response.', 'monmi-pay' ) );
            $context['error'] = [
                'code'    => $error->get_error_code(),
                'message' => $error->get_error_message(),
                'data'    => $error->get_error_data(),
            ];
            $this->store_request_context( $context );
            $this->log_request_error( $context, $error );
            return $error;
        }

        $context['response']['body_decoded'] = $decoded;
        $this->store_request_context( $context );
        return [
            'body'             => $decoded,
            'headers'          => $headers,
            'response_headers' => $raw_headers,
        ];
    }

    /**
     * Retrieve the last Monmi API request context.
     */
    public function get_last_request_context(): ?array {
        if ( null !== $this->last_request_context ) {
            return $this->last_request_context;
        }

        $cached = get_transient( self::DEBUG_TRANSIENT_KEY );

        return is_array( $cached ) ? $cached : null;
    }

    /**
     * Generate signature for Monmi API request.
     */
    

    public function generate_signature( string $secret, string $request_id, string $timestamp ): string {
        $payload = $request_id . '.' . $timestamp;
        return hash_hmac( 'sha256', $payload, $secret );
    }

    /**
     * Retrieve base URL for environment.
     */
    public function get_environment_base_url( ?string $environment = null ): string {
        $environment = $environment ?: get_option( self::OPTION_ENVIRONMENT, 'development' );

        $map = [
            'development' => 'https://store-hub-api-develop.myepis.cloud',
            'production'  => 'https://store-hub-api.myepis.cloud',
        ];

        return $map[ $environment ] ?? '';
    }

    /**
     * Confirm payment via Monmi API.
     */
    public function confirm_payment( WC_Order $order, string $client_secret, string $payment_method = '' ) {
        $payload = [
            'client_secret' => $client_secret,
            'order_id'      => $order->get_id(),
            'amount'        => [
                'currency' => $order->get_currency(),
                'value'    => $this->format_amount( (float) $order->get_total() ),
            ],
            'metadata'      => [
                'order_key' => $order->get_order_key(),
                'site_url'  => home_url(),
            ],
        ];

        if ( $payment_method ) {
            $payload['payment_method'] = [ 'id' => $payment_method ];
        }

        $payload = apply_filters( 'monmi_pay_confirm_payload', $payload, $order );

        return $this->request_monmi_api( '/payments/confirm', $payload );
    }
}
add_action(
    'plugins_loaded',
    static function () {
        Monmi_Pay_Plugin::instance();
    },
    20
);





