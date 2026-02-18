<?php
/**
 * Plugin Name: Chapter 7 Bankruptcy Calculator
 * Description: A Chapter 7 Bankruptcy Calculator.
 * Version: 1.0
 * Author: Jafar
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Chapter7Calculator {
    
    private $backend_url = 'https://srv1007088.hstgr.cloud'; // Change to your Laravel backend URL
    private $api_key = '23QKos123q4tCfXSob23aQl6YKtokMFs'; // Your API key from Laravel .env
    private $app_id;
    
    public function __construct() {
        add_action('init', [$this, 'init']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_shortcode('chapter7_calculator', [$this, 'render_calculator']);
        
        // Admin hooks
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_notices', [$this, 'show_admin_notices']);
        
        $this->app_id = get_option('ch7_calculator_app_id');
    }
    
    public function init() {
        // Register site with backend if not already registered
        if (!$this->app_id) {
            $this->register_site();
        }
    }
    
    /**
     * Register WordPress site with Laravel backend
     */
    public function register_site() {
        $current_user = wp_get_current_user();
        
        $site_data = [
            'site_name' => get_bloginfo('name'),
            'site_url' => home_url(),
            'wp_admin_email' => get_option('admin_email'),
            'owner_name' => $current_user->display_name ?: 'Site Owner',
            'owner_email' => $current_user->user_email ?: get_option('admin_email'),
        ];
        
        $response = wp_remote_post($this->backend_url . '/api/sites/register', [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($site_data),
            'timeout' => 30,
        ]);
        
        if (!is_wp_error($response)) {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            if ($data && $data['success']) {
                // Store app_id and dashboard URL
                update_option('ch7_calculator_app_id', $data['data']['app_id']);
                update_option('ch7_calculator_dashboard_url', $data['data']['dashboard_url']);
                $this->app_id = $data['data']['app_id'];
                
                // Show success notice
                add_action('admin_notices', function() use ($data) {
                    echo '<div class="notice notice-success is-dismissible">';
                    echo '<p><strong>Chapter 7 Calculator:</strong> Successfully registered with backend! ';
                    echo '<a href="' . esc_url($data['data']['dashboard_url']) . '" target="_blank" class="button button-primary">Setup Dashboard</a>';
                    echo '</p></div>';
                });
            }
        }
    }
    
    /**
     * Get calculator settings from Laravel backend
     */
    public function get_calculator_settings() {
        if (!$this->app_id) {
            return $this->get_default_settings();
        }
        
        $response = wp_remote_get($this->backend_url . '/api/calculator/settings?app_id=' . $this->app_id, [
            'headers' => [
                'X-API-Key' => $this->api_key,
            ],
            'timeout' => 15,
        ]);
        
        if (!is_wp_error($response)) {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            if ($data && $data['success']) {
                return $data['data']['settings'];
            }
        }
        
        // Fallback to default settings
        return $this->get_default_settings();
    }
    
    /**
     * Default settings if backend is unavailable
     */
    private function get_default_settings() {
        return [
            'income_thresholds' => [
                'single' => 50000,
                'married' => 75000,
            ],
            'debt_ratio_threshold' => 0.4,
            'form_fields' => [
                'show_debt_settlement_checkbox' => true,
                'required_fields' => ['first_name', 'last_name', 'email', 'phone'],
            ],
        ];
    }
    
    /**
     * Submit calculation to Laravel backend
     */
    public function submit_calculation($calculation_data) {
        if (!$this->app_id) {
            return ['success' => false, 'message' => 'Site not registered with backend'];
        }
        
        // Add app_id to calculation data
        $calculation_data['app_id'] = $this->app_id;
        
        $response = wp_remote_post($this->backend_url . '/api/calculator/ch7', [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-API-Key' => $this->api_key,
            ],
            'body' => json_encode($calculation_data),
            'timeout' => 30,
        ]);
        
        if (!is_wp_error($response)) {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            if ($data && $data['success']) {
                return [
                    'success' => true,
                    'message' => 'Calculation submitted successfully',
                    'data' => $data['data']
                ];
            }
        }
        
        return ['success' => false, 'message' => 'Failed to submit calculation'];
    }
    
    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts() {
        $plugin_url = plugin_dir_url(__FILE__);
        
        // Enqueue CSS
        wp_enqueue_style('chapter7-calculator-style', $plugin_url . 'assets/style.css');
        
        // Enqueue JS
        wp_enqueue_script('chapter7-calculator-script', $plugin_url . 'assets/index.js', [], '2.0', true);
        
        // Pass settings and config to JavaScript
        wp_localize_script('chapter7-calculator-script', 'ch7Config', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ch7_calculator_nonce'),
            'settings' => $this->get_calculator_settings(),
            'appId' => $this->app_id,
            'backendUrl' => $this->backend_url,
            'apiKey' => $this->api_key, // Add API key for direct backend calls
        ]);
    }
    
    /**
     * Render calculator shortcode
     */
    public function render_calculator($atts) {
        $atts = shortcode_atts([
            'theme' => 'default',
            'show_title' => 'true',
        ], $atts);
        
        ob_start();
        ?>
        <div id="chapter7-calculator" 
             data-theme="<?php echo esc_attr($atts['theme']); ?>"
             data-show-title="<?php echo esc_attr($atts['show_title']); ?>"
             data-app-id="<?php echo esc_attr($this->app_id); ?>">
            <div class="ch7-loading">Loading calculator...</div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_options_page(
            'Chapter 7 Calculator Settings',
            'Ch7 Calculator',
            'manage_options',
            'ch7-calculator',
            [$this, 'admin_page']
        );
    }
    
    /**
     * Admin page
     */
    public function admin_page() {
        $dashboard_url = get_option('ch7_calculator_dashboard_url');
        ?>
        <div class="wrap">
            <h1>Chapter 7 Calculator Settings</h1>
            
            <div class="card">
                <h2>Backend Integration Status</h2>
                <?php if ($this->app_id): ?>
                    <p><strong>Status:</strong> <span style="color: green;">✅ Connected</span></p>
                    <p><strong>App ID:</strong> <code><?php echo esc_html($this->app_id); ?></code></p>
                    <p><strong>Site:</strong> <?php echo esc_html(get_bloginfo('name')); ?></p>
                    
                    <?php if ($dashboard_url): ?>
                        <p>
                            <a href="<?php echo esc_url($dashboard_url); ?>" target="_blank" class="button button-primary">
                                Open Dashboard
                            </a>
                            <span class="description">Manage calculator settings and CRM integrations</span>
                        </p>
                    <?php endif; ?>
                    
                    <hr>
                    <h3>Usage Instructions</h3>
                    <p>Use the shortcode <code>[chapter7_calculator]</code> to display the calculator on any page or post.</p>
                    
                    <h4>Shortcode Options:</h4>
                    <ul>
                        <li><code>[chapter7_calculator]</code> - Basic calculator</li>
                    </ul>
                    
                <?php else: ?>
                    <p><strong>Status:</strong> <span style="color: red;">❌ Not Connected</span></p>
                    <p>The plugin is not connected to the backend. Please check your configuration.</p>
                    <button type="button" class="button button-primary" onclick="location.reload();">
                        Retry Connection
                    </button>
                <?php endif; ?>
            </div>
            
            <div class="card">
                <h2>Recent Activity</h2>
                <?php $this->show_recent_activity(); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Show recent activity (if available)
     */
    private function show_recent_activity() {
        // This could fetch recent calculations from the backend
        echo '<p>Activity tracking will be available once connected to dashboard.</p>';
    }
    
    /**
     * Show admin notices
     */
    public function show_admin_notices() {
        if (!$this->app_id && current_user_can('manage_options')) {
            echo '<div class="notice notice-warning">';
            echo '<p><strong>Chapter 7 Calculator:</strong> Plugin not connected to backend. ';
            echo '<a href="' . admin_url('options-general.php?page=ch7-calculator') . '">Configure now</a>';
            echo '</p></div>';
        }
    }
    
    /**
     * Plugin activation
     */
    public static function activate_plugin() {        
        // Set default options
        $result = add_option('ch7_calculator_version', '1.0');
        
        // Try to register with backend
        $instance = new self();
        $instance->register_site();
        
    }
    
    /**
     * Plugin deactivation
     */
    public static function deactivate_plugin() {
        // Clean up options
        delete_option('ch7_calculator_app_id');
        delete_option('ch7_calculator_dashboard_url');
        delete_option('ch7_calculator_version');
    }
    
    /**
     * Plugin uninstall - Complete cleanup
     */
    public static function uninstall_plugin() {
        // Remove all plugin options
        delete_option('ch7_calculator_app_id');
        delete_option('ch7_calculator_dashboard_url');
        delete_option('ch7_calculator_version');
        
        // Clear any transients/cache if you add them later
        delete_transient('ch7_calculator_settings');
    }
}

// Initialize the plugin
$ch7_calculator_instance = new Chapter7Calculator();

// Register activation/deactivation/uninstall hooks
register_activation_hook(__FILE__, ['Chapter7Calculator', 'activate_plugin']);
register_deactivation_hook(__FILE__, ['Chapter7Calculator', 'deactivate_plugin']);
register_uninstall_hook(__FILE__, ['Chapter7Calculator', 'uninstall_plugin']);

// AJAX handler for form submissions (if needed for fallback)
add_action('wp_ajax_ch7_submit_calculation', 'ch7_handle_ajax_submission');
add_action('wp_ajax_nopriv_ch7_submit_calculation', 'ch7_handle_ajax_submission');

function ch7_handle_ajax_submission() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'ch7_calculator_nonce')) {
        wp_die('Security check failed');
    }
    
    // Get calculation data
    $calculation_data = [
        'first_name' => sanitize_text_field($_POST['first_name']),
        'last_name' => sanitize_text_field($_POST['last_name']),
        'email' => sanitize_email($_POST['email']),
        'phone' => sanitize_text_field($_POST['phone']),
        'anual_income' => intval($_POST['anual_income']),
        'location' => sanitize_text_field($_POST['location']),
        'debt_ratio' => sanitize_text_field($_POST['debt_ratio']),
        'eligibility' => sanitize_text_field($_POST['eligibility']),
        'calculation_data' => json_decode(stripslashes($_POST['calculation_data']), true),
        'calculation_results' => json_decode(stripslashes($_POST['calculation_results']), true),
    ];
    
    // Submit to backend
    $calculator = new Chapter7Calculator();
    $result = $calculator->submit_calculation($calculation_data);
    
    wp_send_json($result);
}
?>