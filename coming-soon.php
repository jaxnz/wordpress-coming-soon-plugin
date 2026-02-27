<?php
/**
 * Plugin Name: Simple Coming Soon Mode
 * Description: Display a customizable coming soon screen with your logo, headline, and supporting text. Admins can toggle visibility without affecting their own view.
 * Version: 1.1.0
 * Author: Jackson Lee
 * Text Domain: simple-coming-soon-mode
 */

if (!defined('ABSPATH')) {
    exit;
}

class Simple_Coming_Soon_Mode {
    private $option_key = 'scs_mode_settings';
    private $page_slug = 'simple-coming-soon-mode';
    private $cookie_name = 'scs_mode_access';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('template_redirect', [$this, 'maybe_render_coming_soon']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_settings_link']);
    }

    private function defaults() {
        return [
            'enabled' => false,
            'title' => 'Coming Soon',
            'message' => 'We are putting the finishing touches on something great. Stay tuned!',
            'logo_id' => 0,
            'password' => '',
            'contact_form_enabled' => false,
            'mailgun_domain' => '',
            'mailgun_api_key' => '',
            'mailgun_from_name' => get_bloginfo('name'),
            'mailgun_from_email' => '',
            'mailgun_to' => sanitize_email(get_option('admin_email')),
            'mailgun_cc' => '',
            'mailgun_bcc' => '',
            'turnstile_enabled' => false,
            'turnstile_site_key' => '',
            'turnstile_secret_key' => '',
            'seo_allow_indexing' => false,
            'seo_meta_title' => '',
            'seo_meta_description' => '',
        ];
    }

    private function derive_accent_color($attachment_id) {
        $default = '#2563eb';
        if (!$attachment_id) {
            return $default;
        }

        $path = get_attached_file($attachment_id);
        if (!$path || !file_exists($path)) {
            return $default;
        }

        if (!function_exists('imagecreatefromstring')) {
            return $default;
        }

        $data = file_get_contents($path);
        if ($data === false) {
            return $default;
        }

        $img = @imagecreatefromstring($data);
        if (!$img) {
            return $default;
        }

        $width = imagesx($img);
        $height = imagesy($img);
        if ($width < 1 || $height < 1) {
            imagedestroy($img);
            return $default;
        }

        $sample = imagecreatetruecolor(16, 16);
        imagecopyresampled($sample, $img, 0, 0, 0, 0, 16, 16, $width, $height);
        $r = $g = $b = 0;
        $count = 0;
        for ($x = 0; $x < 16; $x++) {
            for ($y = 0; $y < 16; $y++) {
                $rgb = imagecolorat($sample, $x, $y);
                $r += ($rgb >> 16) & 0xFF;
                $g += ($rgb >> 8) & 0xFF;
                $b += $rgb & 0xFF;
                $count++;
            }
        }
        imagedestroy($sample);
        imagedestroy($img);

        if ($count === 0) {
            return $default;
        }

        $r = (int) round($r / $count);
        $g = (int) round($g / $count);
        $b = (int) round($b / $count);

        // Boost saturation and brightness for visibility on light UI.
        $saturationBoost = 1.4;
        $avg = ($r + $g + $b) / 3;
        $r = min(255, max(0, (int) round(($r - $avg) * $saturationBoost + $avg)));
        $g = min(255, max(0, (int) round(($g - $avg) * $saturationBoost + $avg)));
        $b = min(255, max(0, (int) round(($b - $avg) * $saturationBoost + $avg)));

        $brightnessBoost = 1.08;
        $r = min(255, (int) round($r * $brightnessBoost));
        $g = min(255, (int) round($g * $brightnessBoost));
        $b = min(255, (int) round($b * $brightnessBoost));

        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    private function hex_to_rgb_string($hex) {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return '37,99,235'; // default rgb for #2563eb
        }

        $int = hexdec($hex);
        $r = ($int >> 16) & 255;
        $g = ($int >> 8) & 255;
        $b = $int & 255;
        return "{$r},{$g},{$b}";
    }

    private function get_contrast_text_color($hex) {
        $hex = ltrim((string) $hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return '#ffffff';
        }

        $int = hexdec($hex);
        $r = ($int >> 16) & 255;
        $g = ($int >> 8) & 255;
        $b = $int & 255;

        // Perceived luminance for contrast selection.
        $luminance = (0.299 * $r) + (0.587 * $g) + (0.114 * $b);
        return ($luminance > 170) ? '#0f172a' : '#ffffff';
    }

    private function build_password_token($password) {
        return hash_hmac('sha256', 'scs-mode|' . $password, wp_salt('auth'));
    }

    private function has_valid_access_cookie($password) {
        if (empty($password) || !isset($_COOKIE[$this->cookie_name])) {
            return false;
        }

        $token = sanitize_text_field(wp_unslash($_COOKIE[$this->cookie_name]));
        $expected = $this->build_password_token($password);

        return $token && hash_equals($expected, $token);
    }

    private function set_access_cookie($password) {
        if (empty($password)) {
            return;
        }

        $token = $this->build_password_token($password);
        $params = [
            'expires' => time() + WEEK_IN_SECONDS,
            'path' => (defined('COOKIEPATH') && COOKIEPATH) ? COOKIEPATH : '/',
            'domain' => defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        setcookie($this->cookie_name, $token, $params);
    }

    private function get_settings() {
        $settings = get_option($this->option_key, []);
        return wp_parse_args($settings, $this->defaults());
    }

    private function sanitize_mailgun_domain($domain) {
        $domain = strtolower(sanitize_text_field((string) $domain));
        $domain = preg_replace('#^https?://#i', '', $domain);
        $domain = trim($domain, " \t\n\r\0\x0B/");
        return preg_replace('/[^a-z0-9.\-]/', '', $domain);
    }

    private function sanitize_email_list($value) {
        $parts = preg_split('/[\s,;]+/', (string) $value);
        $valid = [];

        if (!$parts) {
            return '';
        }

        foreach ($parts as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }

            $email = sanitize_email($entry);
            if ($email && is_email($email)) {
                $valid[strtolower($email)] = $email;
            }
        }

        return implode(', ', array_values($valid));
    }

    private function email_list_to_array($value) {
        $sanitized = $this->sanitize_email_list($value);
        if ($sanitized === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $sanitized))));
    }

    private function trim_plain_text($value, $max_length) {
        $value = preg_replace('/\s+/', ' ', trim((string) $value));
        if ($value === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max_length);
        }

        return substr($value, 0, $max_length);
    }

    private function build_contact_form_timestamp_signature($timestamp) {
        return hash_hmac('sha256', 'scs-contact-ts|' . (int) $timestamp, wp_salt('nonce'));
    }

    private function get_request_ip() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? wp_unslash($_SERVER['REMOTE_ADDR']) : '';
        $ip = trim((string) $ip);
        if ($ip === '') {
            return '';
        }

        return preg_replace('/[^0-9a-fA-F:\.,]/', '', $ip);
    }

    private function is_turnstile_enabled($settings) {
        return !empty($settings['turnstile_enabled'])
            && !empty($settings['turnstile_site_key'])
            && !empty($settings['turnstile_secret_key']);
    }

    private function verify_turnstile_response($settings) {
        $token = isset($_POST['cf-turnstile-response']) ? sanitize_text_field(wp_unslash($_POST['cf-turnstile-response'])) : '';
        if ($token === '') {
            return new WP_Error('scs_turnstile_missing', __('Please complete the spam protection check.', 'simple-coming-soon-mode'));
        }

        $body = [
            'secret' => sanitize_text_field($settings['turnstile_secret_key'] ?? ''),
            'response' => $token,
        ];

        $ip = $this->get_request_ip();
        if ($ip !== '') {
            $body['remoteip'] = $ip;
        }

        $response = wp_remote_post(
            'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            [
                'timeout' => 10,
                'body' => $body,
            ]
        );

        if (is_wp_error($response)) {
            return new WP_Error('scs_turnstile_request_failed', __('Spam protection verification is temporarily unavailable. Please try again later.', 'simple-coming-soon-mode'));
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($decoded) || empty($decoded['success'])) {
            return new WP_Error('scs_turnstile_failed', __('Spam protection verification failed. Please try again.', 'simple-coming-soon-mode'));
        }

        return true;
    }

    private function check_contact_rate_limit() {
        $ip = $this->get_request_ip();
        if ($ip === '') {
            return true;
        }

        $key = 'scs_contact_rate_' . md5($ip);
        $attempts = (int) get_transient($key);
        $max_attempts = 5;

        if ($attempts >= $max_attempts) {
            return new WP_Error('scs_contact_rate_limited', __('Too many messages were submitted from this connection. Please wait a while and try again.', 'simple-coming-soon-mode'));
        }

        set_transient($key, $attempts + 1, HOUR_IN_SECONDS);
        return true;
    }

    private function validate_contact_spam_controls() {
        $honeypot = isset($_POST['scs_contact_website']) ? trim((string) wp_unslash($_POST['scs_contact_website'])) : '';
        if ($honeypot !== '') {
            return new WP_Error('scs_contact_honeypot', __('We could not submit your message. Please try again.', 'simple-coming-soon-mode'));
        }

        $timestamp = isset($_POST['scs_contact_form_ts']) ? absint($_POST['scs_contact_form_ts']) : 0;
        $signature = isset($_POST['scs_contact_form_sig']) ? sanitize_text_field(wp_unslash($_POST['scs_contact_form_sig'])) : '';
        $expected_signature = $timestamp ? $this->build_contact_form_timestamp_signature($timestamp) : '';

        if (!$timestamp || !$signature || !hash_equals($expected_signature, $signature)) {
            return new WP_Error('scs_contact_timestamp_invalid', __('Security check failed. Please refresh the page and try again.', 'simple-coming-soon-mode'));
        }

        $age = time() - $timestamp;
        if ($age < 3) {
            return new WP_Error('scs_contact_too_fast', __('Please wait a moment and try submitting again.', 'simple-coming-soon-mode'));
        }

        if ($age > DAY_IN_SECONDS) {
            return new WP_Error('scs_contact_form_expired', __('This form has expired. Please refresh the page and try again.', 'simple-coming-soon-mode'));
        }

        return true;
    }

    public function add_settings_page() {
        add_options_page(
            __('Coming Soon Mode', 'simple-coming-soon-mode'),
            __('Coming Soon Mode', 'simple-coming-soon-mode'),
            'manage_options',
            $this->page_slug,
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting(
            'scs_mode_settings_group',
            $this->option_key,
            [
                'sanitize_callback' => [$this, 'sanitize_settings'],
            ]
        );

        add_settings_section(
            'scs_mode_section',
            __('Coming Soon Content', 'simple-coming-soon-mode'),
            function () {
                echo '<p>' . esc_html__('Control what visitors see while the coming soon screen is active.', 'simple-coming-soon-mode') . '</p>';
            },
            $this->page_slug
        );

        add_settings_field(
            'scs_mode_enabled',
            __('Enable Coming Soon', 'simple-coming-soon-mode'),
            [$this, 'render_enabled_field'],
            $this->page_slug,
            'scs_mode_section'
        );

        add_settings_field(
            'scs_mode_password',
            __('Access Password', 'simple-coming-soon-mode'),
            [$this, 'render_password_field'],
            $this->page_slug,
            'scs_mode_section'
        );

        add_settings_field(
            'scs_mode_logo',
            __('Logo Image', 'simple-coming-soon-mode'),
            [$this, 'render_logo_field'],
            $this->page_slug,
            'scs_mode_section'
        );

        add_settings_field(
            'scs_mode_title',
            __('Headline', 'simple-coming-soon-mode'),
            [$this, 'render_title_field'],
            $this->page_slug,
            'scs_mode_section'
        );

        add_settings_field(
            'scs_mode_message',
            __('Supporting Text', 'simple-coming-soon-mode'),
            [$this, 'render_message_field'],
            $this->page_slug,
            'scs_mode_section'
        );

        add_settings_section(
            'scs_mode_contact_section',
            __('Contact Form (Mailgun)', 'simple-coming-soon-mode'),
            function () {
                echo '<p>' . esc_html__('Enable a contact form on the coming soon page and send submissions using Mailgun.', 'simple-coming-soon-mode') . '</p>';
            },
            $this->page_slug
        );

        add_settings_field(
            'scs_contact_form_enabled',
            __('Enable Contact Form', 'simple-coming-soon-mode'),
            [$this, 'render_contact_enabled_field'],
            $this->page_slug,
            'scs_mode_contact_section'
        );

        add_settings_field(
            'scs_mailgun_domain',
            __('Mailgun Domain', 'simple-coming-soon-mode'),
            [$this, 'render_mailgun_domain_field'],
            $this->page_slug,
            'scs_mode_contact_section'
        );

        add_settings_field(
            'scs_mailgun_api_key',
            __('Mailgun API Key', 'simple-coming-soon-mode'),
            [$this, 'render_mailgun_api_key_field'],
            $this->page_slug,
            'scs_mode_contact_section'
        );

        add_settings_field(
            'scs_mailgun_from_name',
            __('From Name', 'simple-coming-soon-mode'),
            [$this, 'render_mailgun_from_name_field'],
            $this->page_slug,
            'scs_mode_contact_section'
        );

        add_settings_field(
            'scs_mailgun_from_email',
            __('From Email', 'simple-coming-soon-mode'),
            [$this, 'render_mailgun_from_email_field'],
            $this->page_slug,
            'scs_mode_contact_section'
        );

        add_settings_field(
            'scs_mailgun_to',
            __('To Address(es)', 'simple-coming-soon-mode'),
            [$this, 'render_mailgun_to_field'],
            $this->page_slug,
            'scs_mode_contact_section'
        );

        add_settings_field(
            'scs_mailgun_cc',
            __('CC Address(es)', 'simple-coming-soon-mode'),
            [$this, 'render_mailgun_cc_field'],
            $this->page_slug,
            'scs_mode_contact_section'
        );

        add_settings_field(
            'scs_mailgun_bcc',
            __('BCC Address(es)', 'simple-coming-soon-mode'),
            [$this, 'render_mailgun_bcc_field'],
            $this->page_slug,
            'scs_mode_contact_section'
        );

        add_settings_section(
            'scs_mode_spam_section',
            __('Spam Protection', 'simple-coming-soon-mode'),
            function () {
                echo '<p>' . esc_html__('Use built-in protections (honeypot, timing, rate limiting) and optionally enable Cloudflare Turnstile for stronger bot prevention on the contact form.', 'simple-coming-soon-mode') . '</p>';
            },
            $this->page_slug
        );

        add_settings_field(
            'scs_turnstile_enabled',
            __('Enable Turnstile', 'simple-coming-soon-mode'),
            [$this, 'render_turnstile_enabled_field'],
            $this->page_slug,
            'scs_mode_spam_section'
        );

        add_settings_field(
            'scs_turnstile_site_key',
            __('Turnstile Site Key', 'simple-coming-soon-mode'),
            [$this, 'render_turnstile_site_key_field'],
            $this->page_slug,
            'scs_mode_spam_section'
        );

        add_settings_field(
            'scs_turnstile_secret_key',
            __('Turnstile Secret Key', 'simple-coming-soon-mode'),
            [$this, 'render_turnstile_secret_key_field'],
            $this->page_slug,
            'scs_mode_spam_section'
        );

        add_settings_section(
            'scs_mode_seo_section',
            __('SEO & Crawling', 'simple-coming-soon-mode'),
            function () {
                echo '<p>' . esc_html__('Configure metadata for the coming soon page. Enable indexing to serve HTTP 200 so search engines can index the page content.', 'simple-coming-soon-mode') . '</p>';
            },
            $this->page_slug
        );

        add_settings_field(
            'scs_seo_allow_indexing',
            __('Allow Search Indexing', 'simple-coming-soon-mode'),
            [$this, 'render_seo_allow_indexing_field'],
            $this->page_slug,
            'scs_mode_seo_section'
        );

        add_settings_field(
            'scs_seo_meta_title',
            __('SEO Title', 'simple-coming-soon-mode'),
            [$this, 'render_seo_meta_title_field'],
            $this->page_slug,
            'scs_mode_seo_section'
        );

        add_settings_field(
            'scs_seo_meta_description',
            __('SEO Description', 'simple-coming-soon-mode'),
            [$this, 'render_seo_meta_description_field'],
            $this->page_slug,
            'scs_mode_seo_section'
        );
    }

    public function sanitize_settings($input) {
        $defaults = $this->defaults();

        return [
            'enabled' => !empty($input['enabled']),
            'title' => sanitize_text_field($input['title'] ?? $defaults['title']),
            'message' => wp_kses_post($input['message'] ?? $defaults['message']),
            'logo_id' => isset($input['logo_id']) ? absint($input['logo_id']) : 0,
            'password' => sanitize_text_field($input['password'] ?? ''),
            'contact_form_enabled' => !empty($input['contact_form_enabled']),
            'mailgun_domain' => $this->sanitize_mailgun_domain($input['mailgun_domain'] ?? ''),
            'mailgun_api_key' => sanitize_text_field($input['mailgun_api_key'] ?? ''),
            'mailgun_from_name' => sanitize_text_field($input['mailgun_from_name'] ?? ''),
            'mailgun_from_email' => sanitize_email($input['mailgun_from_email'] ?? ''),
            'mailgun_to' => $this->sanitize_email_list($input['mailgun_to'] ?? $defaults['mailgun_to']),
            'mailgun_cc' => $this->sanitize_email_list($input['mailgun_cc'] ?? ''),
            'mailgun_bcc' => $this->sanitize_email_list($input['mailgun_bcc'] ?? ''),
            'turnstile_enabled' => !empty($input['turnstile_enabled']),
            'turnstile_site_key' => sanitize_text_field($input['turnstile_site_key'] ?? ''),
            'turnstile_secret_key' => sanitize_text_field($input['turnstile_secret_key'] ?? ''),
            'seo_allow_indexing' => !empty($input['seo_allow_indexing']),
            'seo_meta_title' => $this->trim_plain_text(sanitize_text_field($input['seo_meta_title'] ?? ''), 160),
            'seo_meta_description' => $this->trim_plain_text(sanitize_textarea_field($input['seo_meta_description'] ?? ''), 320),
        ];
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Coming Soon Mode', 'simple-coming-soon-mode'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('scs_mode_settings_group');
                do_settings_sections($this->page_slug);
                submit_button(__('Save Settings', 'simple-coming-soon-mode'));
                ?>
            </form>
        </div>
        <?php
    }

    public function render_enabled_field() {
        $settings = $this->get_settings();
        ?>
        <label for="scs_mode_enabled">
            <input type="checkbox" name="<?php echo esc_attr($this->option_key); ?>[enabled]" id="scs_mode_enabled" value="1" <?php checked($settings['enabled']); ?> />
            <?php esc_html_e('Show coming soon screen to visitors (admins can still view the site).', 'simple-coming-soon-mode'); ?>
        </label>
        <?php
    }

    public function render_password_field() {
        $settings = $this->get_settings();
        ?>
        <input type="password" name="<?php echo esc_attr($this->option_key); ?>[password]" id="scs_mode_password" value="<?php echo esc_attr($settings['password']); ?>" class="regular-text" autocomplete="new-password" />
        <p class="description"><?php esc_html_e('Optional. Visitors who enter this password can view the site normally while coming soon mode is on. Leave blank to disable.', 'simple-coming-soon-mode'); ?></p>
        <?php
    }

    public function render_logo_field() {
        $settings = $this->get_settings();
        $logo_id = absint($settings['logo_id']);
        $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'medium') : '';
        ?>
        <div style="margin-bottom: 8px;">
            <img id="scs-mode-logo-preview" src="<?php echo esc_url($logo_url); ?>" alt="<?php esc_attr_e('Logo preview', 'simple-coming-soon-mode'); ?>" style="max-height: 160px; max-width: 100%; display: <?php echo $logo_url ? 'block' : 'none'; ?>;" />
            <div id="scs-mode-logo-empty" style="color: #555; <?php echo $logo_url ? 'display:none;' : ''; ?>"><?php esc_html_e('No logo selected yet.', 'simple-coming-soon-mode'); ?></div>
        </div>
        <input type="hidden" id="scs_mode_logo_id" name="<?php echo esc_attr($this->option_key); ?>[logo_id]" value="<?php echo esc_attr($logo_id); ?>" />
        <button type="button" class="button" id="scs-mode-select-logo"><?php esc_html_e('Select Logo', 'simple-coming-soon-mode'); ?></button>
        <button type="button" class="button" id="scs-mode-remove-logo" <?php disabled(!$logo_url); ?>><?php esc_html_e('Remove', 'simple-coming-soon-mode'); ?></button>
        <?php
    }

    public function render_title_field() {
        $settings = $this->get_settings();
        ?>
        <input type="text" name="<?php echo esc_attr($this->option_key); ?>[title]" id="scs_mode_title" value="<?php echo esc_attr($settings['title']); ?>" class="regular-text" />
        <?php
    }

    public function render_message_field() {
        $settings = $this->get_settings();
        ?>
        <textarea name="<?php echo esc_attr($this->option_key); ?>[message]" id="scs_mode_message" rows="5" class="large-text"><?php echo esc_textarea($settings['message']); ?></textarea>
        <p class="description"><?php esc_html_e('You can use basic formatting like paragraphs and links.', 'simple-coming-soon-mode'); ?></p>
        <?php
    }

    public function render_contact_enabled_field() {
        $settings = $this->get_settings();
        ?>
        <label for="scs_contact_form_enabled">
            <input type="checkbox" name="<?php echo esc_attr($this->option_key); ?>[contact_form_enabled]" id="scs_contact_form_enabled" value="1" <?php checked($settings['contact_form_enabled']); ?> />
            <?php esc_html_e('Show a contact form on the coming soon page.', 'simple-coming-soon-mode'); ?>
        </label>
        <?php
    }

    public function render_mailgun_domain_field() {
        $settings = $this->get_settings();
        ?>
        <input type="text" name="<?php echo esc_attr($this->option_key); ?>[mailgun_domain]" id="scs_mailgun_domain" value="<?php echo esc_attr($settings['mailgun_domain']); ?>" class="regular-text" placeholder="mg.example.com" />
        <p class="description"><?php esc_html_e('Mailgun sending domain. Example: mg.example.com', 'simple-coming-soon-mode'); ?></p>
        <?php
    }

    public function render_mailgun_api_key_field() {
        $settings = $this->get_settings();
        ?>
        <input type="text" name="<?php echo esc_attr($this->option_key); ?>[mailgun_api_key]" id="scs_mailgun_api_key" value="<?php echo esc_attr($settings['mailgun_api_key']); ?>" class="regular-text code" autocomplete="off" />
        <p class="description"><?php esc_html_e('Mailgun private API key (example: key-xxxxxxxxxxxxxxxxxxxx).', 'simple-coming-soon-mode'); ?></p>
        <?php
    }

    public function render_mailgun_from_name_field() {
        $settings = $this->get_settings();
        ?>
        <input type="text" name="<?php echo esc_attr($this->option_key); ?>[mailgun_from_name]" id="scs_mailgun_from_name" value="<?php echo esc_attr($settings['mailgun_from_name']); ?>" class="regular-text" />
        <?php
    }

    public function render_mailgun_from_email_field() {
        $settings = $this->get_settings();
        ?>
        <input type="email" name="<?php echo esc_attr($this->option_key); ?>[mailgun_from_email]" id="scs_mailgun_from_email" value="<?php echo esc_attr($settings['mailgun_from_email']); ?>" class="regular-text" placeholder="postmaster@mg.example.com" />
        <p class="description"><?php esc_html_e('Verified sender email in Mailgun.', 'simple-coming-soon-mode'); ?></p>
        <?php
    }

    public function render_mailgun_to_field() {
        $settings = $this->get_settings();
        ?>
        <input type="text" name="<?php echo esc_attr($this->option_key); ?>[mailgun_to]" id="scs_mailgun_to" value="<?php echo esc_attr($settings['mailgun_to']); ?>" class="regular-text" />
        <p class="description"><?php esc_html_e('Required. Separate multiple emails with commas.', 'simple-coming-soon-mode'); ?></p>
        <?php
    }

    public function render_mailgun_cc_field() {
        $settings = $this->get_settings();
        ?>
        <input type="text" name="<?php echo esc_attr($this->option_key); ?>[mailgun_cc]" id="scs_mailgun_cc" value="<?php echo esc_attr($settings['mailgun_cc']); ?>" class="regular-text" />
        <p class="description"><?php esc_html_e('Optional. Separate multiple emails with commas.', 'simple-coming-soon-mode'); ?></p>
        <?php
    }

    public function render_mailgun_bcc_field() {
        $settings = $this->get_settings();
        ?>
        <input type="text" name="<?php echo esc_attr($this->option_key); ?>[mailgun_bcc]" id="scs_mailgun_bcc" value="<?php echo esc_attr($settings['mailgun_bcc']); ?>" class="regular-text" />
        <p class="description"><?php esc_html_e('Optional. Separate multiple emails with commas.', 'simple-coming-soon-mode'); ?></p>
        <?php
    }

    public function render_turnstile_enabled_field() {
        $settings = $this->get_settings();
        ?>
        <label for="scs_turnstile_enabled">
            <input type="checkbox" name="<?php echo esc_attr($this->option_key); ?>[turnstile_enabled]" id="scs_turnstile_enabled" value="1" <?php checked($settings['turnstile_enabled']); ?> />
            <?php esc_html_e('Require Cloudflare Turnstile on the coming soon contact form.', 'simple-coming-soon-mode'); ?>
        </label>
        <p class="description"><?php esc_html_e('Optional. Built-in spam protection is always active; Turnstile adds stronger bot filtering.', 'simple-coming-soon-mode'); ?></p>
        <?php
    }

    public function render_turnstile_site_key_field() {
        $settings = $this->get_settings();
        ?>
        <input type="text" name="<?php echo esc_attr($this->option_key); ?>[turnstile_site_key]" id="scs_turnstile_site_key" value="<?php echo esc_attr($settings['turnstile_site_key']); ?>" class="regular-text code" autocomplete="off" />
        <p class="description"><?php esc_html_e('Cloudflare Turnstile Site Key. Create one in Cloudflare Dashboard > Turnstile > Add Site.', 'simple-coming-soon-mode'); ?></p>
        <?php
    }

    public function render_turnstile_secret_key_field() {
        $settings = $this->get_settings();
        ?>
        <input type="password" name="<?php echo esc_attr($this->option_key); ?>[turnstile_secret_key]" id="scs_turnstile_secret_key" value="<?php echo esc_attr($settings['turnstile_secret_key']); ?>" class="regular-text code" autocomplete="off" />
        <p class="description"><?php esc_html_e('Cloudflare Turnstile Secret Key (server-side key). Paste it here and save to enable verification.', 'simple-coming-soon-mode'); ?></p>
        <?php
    }

    public function render_seo_allow_indexing_field() {
        $settings = $this->get_settings();
        ?>
        <label for="scs_seo_allow_indexing">
            <input type="checkbox" name="<?php echo esc_attr($this->option_key); ?>[seo_allow_indexing]" id="scs_seo_allow_indexing" value="1" <?php checked($settings['seo_allow_indexing']); ?> />
            <?php esc_html_e('Serve the coming soon page as HTTP 200 and allow search engines to index it.', 'simple-coming-soon-mode'); ?>
        </label>
        <p class="description"><?php esc_html_e('Disabled (default) keeps HTTP 503 for maintenance mode and outputs noindex. Enable only if you want the coming soon page itself to rank/index.', 'simple-coming-soon-mode'); ?></p>
        <?php
    }

    public function render_seo_meta_title_field() {
        $settings = $this->get_settings();
        ?>
        <input type="text" name="<?php echo esc_attr($this->option_key); ?>[seo_meta_title]" id="scs_seo_meta_title" value="<?php echo esc_attr($settings['seo_meta_title']); ?>" class="regular-text" />
        <p class="description"><?php esc_html_e('Optional. Falls back to the coming soon headline plus site name.', 'simple-coming-soon-mode'); ?></p>
        <?php
    }

    public function render_seo_meta_description_field() {
        $settings = $this->get_settings();
        ?>
        <textarea name="<?php echo esc_attr($this->option_key); ?>[seo_meta_description]" id="scs_seo_meta_description" rows="3" class="large-text"><?php echo esc_textarea($settings['seo_meta_description']); ?></textarea>
        <p class="description"><?php esc_html_e('Optional. Short summary used for search snippets and social previews. Falls back to your supporting text.', 'simple-coming-soon-mode'); ?></p>
        <?php
    }

    public function enqueue_admin_assets($hook_suffix) {
        if ($hook_suffix !== 'settings_page_' . $this->page_slug) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_script(
            'scs-mode-admin',
            plugin_dir_url(__FILE__) . 'assets/js/admin-media.js',
            ['jquery'],
            '1.0.0',
            true
        );
    }

    public function add_settings_link($links) {
        $url = admin_url('options-general.php?page=' . $this->page_slug);
        $links[] = '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'simple-coming-soon-mode') . '</a>';
        return $links;
    }

    private function handle_contact_submission($settings) {
        $values = [
            'name' => isset($_POST['scs_contact_name']) ? sanitize_text_field(wp_unslash($_POST['scs_contact_name'])) : '',
            'email' => isset($_POST['scs_contact_email']) ? sanitize_email(wp_unslash($_POST['scs_contact_email'])) : '',
            'phone' => isset($_POST['scs_contact_phone']) ? sanitize_text_field(wp_unslash($_POST['scs_contact_phone'])) : '',
            'message' => isset($_POST['scs_contact_message']) ? sanitize_textarea_field(wp_unslash($_POST['scs_contact_message'])) : '',
        ];
        $values['phone'] = preg_replace('/[^0-9+\-\(\)\.\sx]/i', '', $values['phone']);
        if (function_exists('mb_substr')) {
            $values['phone'] = mb_substr($values['phone'], 0, 60);
        } else {
            $values['phone'] = substr($values['phone'], 0, 60);
        }

        if (function_exists('mb_substr')) {
            $values['message'] = mb_substr($values['message'], 0, 5000);
        } else {
            $values['message'] = substr($values['message'], 0, 5000);
        }

        $nonce = isset($_POST['scs_contact_nonce']) ? sanitize_text_field(wp_unslash($_POST['scs_contact_nonce'])) : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'scs_contact_form_submit')) {
            return [
                [
                    'type' => 'error',
                    'message' => __('Security check failed. Please refresh the page and try again.', 'simple-coming-soon-mode'),
                ],
                $values,
            ];
        }

        $spam_check = $this->validate_contact_spam_controls();
        if (is_wp_error($spam_check)) {
            return [
                [
                    'type' => 'error',
                    'message' => $spam_check->get_error_message(),
                ],
                $values,
            ];
        }

        if ($values['name'] === '' || $values['email'] === '' || $values['message'] === '') {
            return [
                [
                    'type' => 'error',
                    'message' => __('Please complete all contact form fields.', 'simple-coming-soon-mode'),
                ],
                $values,
            ];
        }

        if (!is_email($values['email'])) {
            return [
                [
                    'type' => 'error',
                    'message' => __('Please provide a valid email address.', 'simple-coming-soon-mode'),
                ],
                $values,
            ];
        }

        if ($this->is_turnstile_enabled($settings)) {
            $turnstile = $this->verify_turnstile_response($settings);
            if (is_wp_error($turnstile)) {
                return [
                    [
                        'type' => 'error',
                        'message' => $turnstile->get_error_message(),
                    ],
                    $values,
                ];
            }
        }

        $rate_limit = $this->check_contact_rate_limit();
        if (is_wp_error($rate_limit)) {
            return [
                [
                    'type' => 'error',
                    'message' => $rate_limit->get_error_message(),
                ],
                $values,
            ];
        }

        $sent = $this->send_contact_email_via_mailgun($settings, $values['name'], $values['email'], $values['phone'], $values['message']);
        if (is_wp_error($sent)) {
            return [
                [
                    'type' => 'error',
                    'message' => $sent->get_error_message(),
                ],
                $values,
            ];
        }

        return [
            [
                'type' => 'success',
                'message' => __('Thanks for reaching out. We will get back to you soon.', 'simple-coming-soon-mode'),
            ],
            ['name' => '', 'email' => '', 'phone' => '', 'message' => ''],
        ];
    }

    private function send_contact_email_via_mailgun($settings, $name, $email, $phone, $message) {
        $domain = $this->sanitize_mailgun_domain($settings['mailgun_domain'] ?? '');
        $api_key = trim((string) ($settings['mailgun_api_key'] ?? ''));
        $to = $this->email_list_to_array($settings['mailgun_to'] ?? '');
        $cc = $this->email_list_to_array($settings['mailgun_cc'] ?? '');
        $bcc = $this->email_list_to_array($settings['mailgun_bcc'] ?? '');
        $from_name = sanitize_text_field($settings['mailgun_from_name'] ?? get_bloginfo('name'));
        $from_email = sanitize_email($settings['mailgun_from_email'] ?? '');

        if ($domain === '' || strpos($domain, '.') === false) {
            return new WP_Error('scs_mailgun_domain_missing', __('Mailgun domain is missing or invalid in plugin settings.', 'simple-coming-soon-mode'));
        }

        if ($api_key === '') {
            return new WP_Error('scs_mailgun_key_missing', __('Mailgun API key is missing in plugin settings.', 'simple-coming-soon-mode'));
        }

        if (empty($to)) {
            return new WP_Error('scs_mailgun_to_missing', __('At least one recipient email is required in the Mailgun "To" field.', 'simple-coming-soon-mode'));
        }

        if ($from_name === '') {
            $from_name = get_bloginfo('name');
        }

        if (!$from_email || !is_email($from_email)) {
            $fallback_email = sanitize_email('postmaster@' . $domain);
            if ($fallback_email && is_email($fallback_email)) {
                $from_email = $fallback_email;
            }
        }

        if (!$from_email || !is_email($from_email)) {
            return new WP_Error('scs_mailgun_from_missing', __('From email is missing or invalid in plugin settings.', 'simple-coming-soon-mode'));
        }

        $subject = sprintf(
            __('New coming soon contact from %s', 'simple-coming-soon-mode'),
            $name
        );

        $text_body = implode("\n", [
            'A new contact form submission was sent from the coming soon page.',
            '',
            'Name: ' . $name,
            'Email: ' . $email,
            'Phone: ' . ($phone !== '' ? $phone : 'Not provided'),
            'Site: ' . home_url('/'),
            'Time: ' . current_time('mysql'),
            '',
            'Message:',
            $message,
        ]);

        $request_body = [
            'from' => sprintf('%s <%s>', $from_name, $from_email),
            'to' => implode(',', $to),
            'subject' => $subject,
            'text' => $text_body,
            'h:Reply-To' => $email,
        ];

        if (!empty($cc)) {
            $request_body['cc'] = implode(',', $cc);
        }
        if (!empty($bcc)) {
            $request_body['bcc'] = implode(',', $bcc);
        }

        $response = wp_remote_post(
            'https://api.mailgun.net/v3/' . $domain . '/messages',
            [
                'timeout' => 20,
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode('api:' . $api_key),
                ],
                'body' => $request_body,
            ]
        );

        if (is_wp_error($response)) {
            return new WP_Error('scs_mailgun_request_error', __('Could not reach Mailgun. Please try again later.', 'simple-coming-soon-mode'));
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code < 200 || $status_code >= 300) {
            $response_body = wp_remote_retrieve_body($response);
            $decoded = json_decode($response_body, true);
            $detail = is_array($decoded) && !empty($decoded['message']) ? sanitize_text_field($decoded['message']) : '';
            if ($detail !== '') {
                return new WP_Error('scs_mailgun_send_failed', sprintf(__('Mailgun rejected the message: %s', 'simple-coming-soon-mode'), $detail));
            }
            return new WP_Error('scs_mailgun_send_failed', __('Mailgun rejected the message. Please verify your Mailgun settings.', 'simple-coming-soon-mode'));
        }

        return true;
    }

    public function maybe_render_coming_soon() {
        if (is_admin() || is_feed() || is_preview() || is_customize_preview()) {
            return;
        }

        $settings = $this->get_settings();
        if (!$settings['enabled']) {
            return;
        }

        if (current_user_can('manage_options')) {
            return;
        }

        $requires_password = !empty($settings['password']);
        $error_message = '';
        $contact_feedback = ['type' => '', 'message' => ''];
        $contact_values = ['name' => '', 'email' => '', 'phone' => '', 'message' => ''];

        if ($requires_password && $this->has_valid_access_cookie($settings['password'])) {
            return;
        }

        if ($requires_password && isset($_POST['scs_password_submit'])) {
            $nonce = isset($_POST['scs_password_nonce']) ? sanitize_text_field(wp_unslash($_POST['scs_password_nonce'])) : '';
            if ($nonce && wp_verify_nonce($nonce, 'scs_password_entry')) {
                $submitted = isset($_POST['scs_mode_password']) ? sanitize_text_field(wp_unslash($_POST['scs_mode_password'])) : '';
                if ($submitted !== '' && hash_equals($settings['password'], $submitted)) {
                    $this->set_access_cookie($settings['password']);
                    $redirect_to = home_url(remove_query_arg(['scs_error'], isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '/'));
                    wp_safe_redirect($redirect_to);
                    exit;
                } else {
                    $error_message = __('That password is incorrect. Please try again.', 'simple-coming-soon-mode');
                }
            } else {
                $error_message = __('Security check failed. Please try again.', 'simple-coming-soon-mode');
            }
        }

        if (!empty($settings['contact_form_enabled']) && isset($_POST['scs_contact_submit'])) {
            [$contact_feedback, $contact_values] = $this->handle_contact_submission($settings);
        }

        if (!empty($settings['seo_allow_indexing'])) {
            status_header(200);
        } else {
            status_header(503);
            header('Retry-After: 3600');
        }
        nocache_headers();
        echo $this->render_frontend($settings, $requires_password, $error_message, $contact_feedback, $contact_values);
        exit;
    }

    private function render_frontend($settings, $requires_password = false, $error_message = '', $contact_feedback = [], $contact_values = []) {
        $logo_url = '';
        if (!empty($settings['logo_id'])) {
            $logo_url = wp_get_attachment_image_url(absint($settings['logo_id']), 'large');
        }

        $title = esc_html($settings['title']);
        $message = wpautop(wp_kses_post($settings['message']));
        $accent = $this->derive_accent_color(!empty($settings['logo_id']) ? absint($settings['logo_id']) : 0);
        $accent_rgb = $this->hex_to_rgb_string($accent);
        $accent_contrast = $this->get_contrast_text_color($accent);
        $contact_enabled = !empty($settings['contact_form_enabled']);
        $contact_feedback_type = isset($contact_feedback['type']) ? sanitize_key($contact_feedback['type']) : '';
        $contact_feedback_message = isset($contact_feedback['message']) ? sanitize_text_field($contact_feedback['message']) : '';
        $contact_name = isset($contact_values['name']) ? $contact_values['name'] : '';
        $contact_email = isset($contact_values['email']) ? $contact_values['email'] : '';
        $contact_phone = isset($contact_values['phone']) ? $contact_values['phone'] : '';
        $contact_message = isset($contact_values['message']) ? $contact_values['message'] : '';
        $contact_form_timestamp = time();
        $contact_form_signature = $this->build_contact_form_timestamp_signature($contact_form_timestamp);
        $turnstile_enabled = $contact_enabled && $this->is_turnstile_enabled($settings);
        $turnstile_site_key = $turnstile_enabled ? sanitize_text_field($settings['turnstile_site_key']) : '';
        $site_name = $this->trim_plain_text(wp_strip_all_tags(get_bloginfo('name')), 120);
        $site_url = home_url('/');
        $seo_title_text = $this->trim_plain_text($settings['seo_meta_title'] ?? '', 160);
        if ($seo_title_text === '') {
            $seo_title_text = $this->trim_plain_text(wp_strip_all_tags(($settings['title'] ?? '') . ' | ' . get_bloginfo('name')), 160);
        }
        $seo_description_text = $this->trim_plain_text($settings['seo_meta_description'] ?? '', 320);
        if ($seo_description_text === '') {
            $seo_description_text = $this->trim_plain_text(wp_strip_all_tags($settings['message'] ?? ''), 320);
        }
        $robots_content = !empty($settings['seo_allow_indexing']) ? 'index,follow,max-image-preview:large' : 'noindex,nofollow,noarchive';
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => $seo_title_text !== '' ? $seo_title_text : $site_name,
            'url' => $site_url,
            'description' => $seo_description_text,
            'isPartOf' => [
                '@type' => 'WebSite',
                'name' => $site_name,
                'url' => $site_url,
            ],
        ];
        if ($logo_url) {
            $schema['primaryImageOfPage'] = esc_url_raw($logo_url);
        }

        ob_start();
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1" />
            <title><?php echo esc_html($seo_title_text !== '' ? $seo_title_text : $settings['title']); ?></title>
            <?php if ($seo_description_text !== '') : ?>
                <meta name="description" content="<?php echo esc_attr($seo_description_text); ?>" />
            <?php endif; ?>
            <meta name="robots" content="<?php echo esc_attr($robots_content); ?>" />
            <link rel="canonical" href="<?php echo esc_url($site_url); ?>" />
            <meta property="og:type" content="website" />
            <meta property="og:url" content="<?php echo esc_url($site_url); ?>" />
            <meta property="og:site_name" content="<?php echo esc_attr($site_name); ?>" />
            <meta property="og:title" content="<?php echo esc_attr($seo_title_text !== '' ? $seo_title_text : $settings['title']); ?>" />
            <?php if ($seo_description_text !== '') : ?>
                <meta property="og:description" content="<?php echo esc_attr($seo_description_text); ?>" />
            <?php endif; ?>
            <?php if ($logo_url) : ?>
                <meta property="og:image" content="<?php echo esc_url($logo_url); ?>" />
            <?php endif; ?>
            <meta name="twitter:card" content="<?php echo esc_attr($logo_url ? 'summary_large_image' : 'summary'); ?>" />
            <meta name="twitter:title" content="<?php echo esc_attr($seo_title_text !== '' ? $seo_title_text : $settings['title']); ?>" />
            <?php if ($seo_description_text !== '') : ?>
                <meta name="twitter:description" content="<?php echo esc_attr($seo_description_text); ?>" />
            <?php endif; ?>
            <?php if ($logo_url) : ?>
                <meta name="twitter:image" content="<?php echo esc_url($logo_url); ?>" />
            <?php endif; ?>
            <script type="application/ld+json"><?php echo wp_json_encode($schema); ?></script>
            <?php if ($turnstile_enabled) : ?>
                <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
            <?php endif; ?>
            <style>
                :root {
                    --scs-bg: #f5f7fb;
                    --scs-card: #ffffff;
                    --scs-text: #0f172a;
                    --scs-accent: <?php echo esc_html($accent); ?>;
                    --scs-accent-rgb: <?php echo esc_html($accent_rgb); ?>;
                    --scs-accent-contrast: <?php echo esc_html($accent_contrast); ?>;
                    --scs-muted: #475569;
                }
                * { box-sizing: border-box; }
                body {
                    margin: 0;
                    min-height: 100vh;
                    background:
                        radial-gradient(circle at 12% 22%, rgba(var(--scs-accent-rgb), 0.28), transparent 32%),
                        radial-gradient(circle at 85% 8%, rgba(var(--scs-accent-rgb), 0.22), transparent 36%),
                        radial-gradient(circle at 55% 100%, rgba(var(--scs-accent-rgb), 0.18), transparent 30%),
                        var(--scs-bg);
                    color: var(--scs-text);
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                }
                .scs-layout {
                    min-height: 100vh;
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                }
                .scs-main-wrap {
                    flex: 1;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    width: 100%;
                    padding: 24px;
                }
                .scs-shell {
                    width: 100%;
                    max-width: 640px;
                    background: var(--scs-card);
                    border: 1px solid #e2e8f0;
                    border-radius: 16px;
                    padding: 36px;
                    box-shadow: 0 30px 60px rgba(15, 23, 42, 0.12);
                    text-align: center;
                    transition: transform 220ms ease, box-shadow 220ms ease;
                }
                .scs-logo {
                    max-width: 260px;
                    max-height: 180px;
                    margin: 0 auto 16px;
                    display: block;
                }
                h1 {
                    margin: 8px 0 12px;
                    font-size: clamp(28px, 4vw, 34px);
                    letter-spacing: -0.5px;
                    color: var(--scs-text);
                }
                .scs-message {
                    color: var(--scs-muted);
                    font-size: 17px;
                    line-height: 1.6;
                }
                .scs-message p {
                    margin-top: 0;
                    margin-bottom: 12px;
                }
                .scs-contact {
                    margin-top: 24px;
                    text-align: left;
                    border: 1px solid #e2e8f0;
                    border-radius: 12px;
                    padding: 16px;
                    background: #f8fafc;
                }
                .scs-contact-title {
                    margin: 0 0 10px;
                    font-size: 18px;
                    letter-spacing: -0.2px;
                    color: var(--scs-text);
                }
                .scs-contact-help {
                    margin: 0 0 12px;
                    color: var(--scs-muted);
                    font-size: 14px;
                }
                .scs-contact-form {
                    position: relative;
                    display: flex;
                    flex-direction: column;
                    gap: 10px;
                }
                .scs-contact-honeypot {
                    position: absolute;
                    left: -10000px;
                    top: auto;
                    width: 1px;
                    height: 1px;
                    overflow: hidden;
                }
                .scs-turnstile-wrap {
                    margin-top: 2px;
                }
                .scs-contact-label {
                    font-weight: 600;
                    color: var(--scs-text);
                    font-size: 14px;
                }
                .scs-contact-input,
                .scs-contact-textarea {
                    width: 100%;
                    padding: 11px 12px;
                    border: 1px solid #cbd5e1;
                    border-radius: 10px;
                    font-size: 15px;
                    outline: none;
                    transition: border-color 140ms ease, box-shadow 140ms ease;
                    background: #fff;
                }
                .scs-contact-input:focus,
                .scs-contact-textarea:focus {
                    border-color: var(--scs-accent);
                    box-shadow: 0 0 0 4px rgba(var(--scs-accent-rgb), 0.14);
                }
                .scs-contact-textarea {
                    min-height: 130px;
                    resize: vertical;
                    line-height: 1.5;
                }
                .scs-contact-button {
                    align-self: flex-start;
                    margin-top: 4px;
                }
                .scs-shell::after {
                    content: '';
                    display: block;
                    width: 80px;
                    height: 4px;
                    background: var(--scs-accent);
                    border-radius: 999px;
                    margin: 20px auto 0;
                    opacity: 0.85;
                }
                .scs-pass-form {
                    margin-top: 18px;
                    display: flex;
                    flex-direction: column;
                    gap: 10px;
                    text-align: left;
                }
                .scs-pass-label {
                    font-weight: 600;
                    color: var(--scs-text);
                    margin-bottom: 2px;
                }
                .scs-pass-row {
                    display: flex;
                    gap: 10px;
                    flex-wrap: wrap;
                }
                .scs-pass-row input[type="password"] {
                    flex: 1 1 220px;
                    padding: 12px 14px;
                    border: 1px solid #e2e8f0;
                    border-radius: 10px;
                    font-size: 16px;
                    box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.04);
                    outline: none;
                    transition: border-color 140ms ease, box-shadow 140ms ease;
                }
                .scs-pass-row input[type="password"]:focus {
                    border-color: var(--scs-accent);
                    box-shadow: 0 0 0 4px rgba(var(--scs-accent-rgb), 0.14);
                }
                .scs-pass-button {
                    background: var(--scs-accent);
                    color: var(--scs-accent-contrast);
                    border: 1px solid rgba(15, 23, 42, 0.14);
                    border-radius: 10px;
                    padding: 12px 18px;
                    font-size: 16px;
                    font-weight: 700;
                    cursor: pointer;
                    box-shadow: 0 12px 30px rgba(var(--scs-accent-rgb), 0.28);
                    transition: transform 120ms ease, box-shadow 120ms ease, filter 120ms ease;
                }
                .scs-pass-button:hover {
                    transform: translateY(-1px);
                    box-shadow: 0 14px 32px rgba(var(--scs-accent-rgb), 0.32);
                }
                .scs-pass-button:active {
                    transform: translateY(0);
                    filter: brightness(0.95);
                }
                .scs-alert {
                    margin: 12px 0 0;
                    padding: 12px 14px;
                    border-radius: 10px;
                    background: rgba(var(--scs-accent-rgb), 0.12);
                    border: 1px solid rgba(var(--scs-accent-rgb), 0.22);
                    color: var(--scs-text);
                    font-weight: 600;
                }
                .scs-alert.scs-alert--error {
                    background: rgba(220, 38, 38, 0.1);
                    border-color: rgba(220, 38, 38, 0.26);
                    color: #7f1d1d;
                }
                .scs-alert.scs-alert--success {
                    background: rgba(22, 163, 74, 0.12);
                    border-color: rgba(22, 163, 74, 0.26);
                    color: #14532d;
                }
                .scs-login-cta {
                    width: 100%;
                    text-align: center;
                    margin: 0 0 18px;
                    color: #94a3b8;
                }
                .scs-login-link {
                    background: transparent;
                    border: none;
                    color: inherit;
                    font-weight: 700;
                    cursor: pointer;
                    font-size: 15px;
                    text-decoration: underline;
                    padding: 6px 8px;
                }
                .scs-login-link:focus-visible {
                    outline: 2px solid var(--scs-accent);
                    outline-offset: 3px;
                }
                .scs-modal-backdrop {
                    position: fixed;
                    inset: 0;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    background: rgba(15, 23, 42, 0.35);
                    opacity: 0;
                    visibility: hidden;
                    pointer-events: none;
                    transition: opacity 120ms ease, visibility 120ms ease;
                    padding: 18px;
                    z-index: 9999;
                }
                .scs-modal-backdrop.is-open {
                    opacity: 1;
                    visibility: visible;
                    pointer-events: auto;
                }
                .scs-modal {
                    width: 100%;
                    max-width: 460px;
                    background: var(--scs-card);
                    border-radius: 14px;
                    padding: 24px;
                    border: 1px solid #e2e8f0;
                    box-shadow: 0 18px 48px rgba(15, 23, 42, 0.26);
                    position: relative;
                    text-align: left;
                }
                .scs-modal h2 {
                    margin: 0 0 12px;
                    font-size: 22px;
                    letter-spacing: -0.3px;
                }
                .scs-modal-close {
                    position: absolute;
                    top: 10px;
                    right: 10px;
                    border: none;
                    background: transparent;
                    color: var(--scs-muted);
                    font-size: 20px;
                    cursor: pointer;
                    padding: 6px;
                    line-height: 1;
                }
                .scs-modal-close:focus-visible {
                    outline: 2px solid var(--scs-accent);
                    outline-offset: 2px;
                }
                .scs-pass-hint {
                    margin: 0 0 10px;
                    color: var(--scs-muted);
                }
                @media (max-width: 640px) {
                    .scs-shell {
                        padding: 24px;
                    }
                    .scs-contact-button {
                        width: 100%;
                        justify-content: center;
                        text-align: center;
                    }
                }
            </style>
        </head>
        <body>
            <div class="scs-layout">
                <div class="scs-main-wrap">
                    <main class="scs-shell" aria-label="<?php esc_attr_e('Coming soon message', 'simple-coming-soon-mode'); ?>">
                        <?php if ($logo_url) : ?>
                            <img class="scs-logo" src="<?php echo esc_url($logo_url); ?>" alt="<?php esc_attr_e('Site logo', 'simple-coming-soon-mode'); ?>" />
                        <?php endif; ?>
                        <h1><?php echo $title; ?></h1>
                        <div class="scs-message"><?php echo $message; ?></div>
                        <?php if ($contact_enabled) : ?>
                            <section class="scs-contact" aria-label="<?php esc_attr_e('Contact form', 'simple-coming-soon-mode'); ?>">
                                <h2 class="scs-contact-title"><?php esc_html_e('Contact Us', 'simple-coming-soon-mode'); ?></h2>
                                <p class="scs-contact-help"><?php esc_html_e('Have a question? Send a message and we will follow up.', 'simple-coming-soon-mode'); ?></p>
                                <?php if ($contact_feedback_message !== '') : ?>
                                    <?php $alert_class = ($contact_feedback_type === 'success') ? 'scs-alert--success' : 'scs-alert--error'; ?>
                                    <div class="scs-alert <?php echo esc_attr($alert_class); ?>"><?php echo esc_html($contact_feedback_message); ?></div>
                                <?php endif; ?>
                                <form method="post" class="scs-contact-form">
                                    <?php wp_nonce_field('scs_contact_form_submit', 'scs_contact_nonce'); ?>
                                    <input type="hidden" name="scs_contact_form_ts" value="<?php echo esc_attr($contact_form_timestamp); ?>" />
                                    <input type="hidden" name="scs_contact_form_sig" value="<?php echo esc_attr($contact_form_signature); ?>" />
                                    <div class="scs-contact-honeypot" aria-hidden="true">
                                        <label for="scs_contact_website"><?php esc_html_e('Website', 'simple-coming-soon-mode'); ?></label>
                                        <input type="text" id="scs_contact_website" name="scs_contact_website" value="" tabindex="-1" autocomplete="off" />
                                    </div>
                                    <label class="scs-contact-label" for="scs_contact_name"><?php esc_html_e('Name', 'simple-coming-soon-mode'); ?></label>
                                    <input class="scs-contact-input" type="text" id="scs_contact_name" name="scs_contact_name" value="<?php echo esc_attr($contact_name); ?>" required />

                                    <label class="scs-contact-label" for="scs_contact_email"><?php esc_html_e('Email', 'simple-coming-soon-mode'); ?></label>
                                    <input class="scs-contact-input" type="email" id="scs_contact_email" name="scs_contact_email" value="<?php echo esc_attr($contact_email); ?>" required />

                                    <label class="scs-contact-label" for="scs_contact_phone"><?php esc_html_e('Phone (Optional)', 'simple-coming-soon-mode'); ?></label>
                                    <input class="scs-contact-input" type="tel" id="scs_contact_phone" name="scs_contact_phone" value="<?php echo esc_attr($contact_phone); ?>" />

                                    <label class="scs-contact-label" for="scs_contact_message"><?php esc_html_e('Message', 'simple-coming-soon-mode'); ?></label>
                                    <textarea class="scs-contact-textarea" id="scs_contact_message" name="scs_contact_message" required><?php echo esc_textarea($contact_message); ?></textarea>

                                    <?php if ($turnstile_enabled) : ?>
                                        <div class="scs-turnstile-wrap">
                                            <div class="cf-turnstile" data-sitekey="<?php echo esc_attr($turnstile_site_key); ?>"></div>
                                        </div>
                                    <?php endif; ?>

                                    <button type="submit" name="scs_contact_submit" class="scs-pass-button scs-contact-button"><?php esc_html_e('Send Message', 'simple-coming-soon-mode'); ?></button>
                                </form>
                            </section>
                        <?php endif; ?>
                    </main>
                </div>
                <?php if ($requires_password) : ?>
                    <div class="scs-login-cta">
                        <button type="button" class="scs-login-link" data-scs-open><?php esc_html_e('Login', 'simple-coming-soon-mode'); ?></button>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($requires_password) : ?>
                <div class="scs-modal-backdrop<?php echo $error_message ? ' is-open' : ''; ?>" id="scs-login-modal" aria-hidden="<?php echo $error_message ? 'false' : 'true'; ?>" <?php echo $error_message ? 'data-open-on-load="1"' : ''; ?>>
                    <div class="scs-modal" role="dialog" aria-labelledby="scs-login-title" aria-modal="true">
                        <button type="button" class="scs-modal-close" data-scs-close aria-label="<?php esc_attr_e('Close login dialog', 'simple-coming-soon-mode'); ?>">&times;</button>
                        <h2 id="scs-login-title"><?php esc_html_e('Enter password to continue', 'simple-coming-soon-mode'); ?></h2>
                        <p class="scs-pass-hint"><?php esc_html_e('Unlock the site with the password provided to you.', 'simple-coming-soon-mode'); ?></p>
                        <?php if (!empty($error_message)) : ?>
                            <div class="scs-alert"><?php echo esc_html($error_message); ?></div>
                        <?php endif; ?>
                        <form method="post" class="scs-pass-form">
                            <?php wp_nonce_field('scs_password_entry', 'scs_password_nonce'); ?>
                            <label class="scs-pass-label" for="scs_mode_password"><?php esc_html_e('Password', 'simple-coming-soon-mode'); ?></label>
                            <div class="scs-pass-row">
                                <input type="password" name="scs_mode_password" id="scs_mode_password" placeholder="<?php esc_attr_e('Password', 'simple-coming-soon-mode'); ?>" required />
                                <button type="submit" name="scs_password_submit" class="scs-pass-button"><?php esc_html_e('Continue', 'simple-coming-soon-mode'); ?></button>
                            </div>
                        </form>
                    </div>
                </div>
                <script>
                    (() => {
                        const modal = document.getElementById('scs-login-modal');
                        const openBtn = document.querySelector('[data-scs-open]');
                        if (!modal || !openBtn) {
                            return;
                        }

                        const closeBtn = modal.querySelector('[data-scs-close]');
                        const passwordField = modal.querySelector('#scs_mode_password');

                        const toggleModal = (open) => {
                            modal.classList.toggle('is-open', open);
                            modal.setAttribute('aria-hidden', open ? 'false' : 'true');
                            if (open && passwordField) {
                                setTimeout(() => passwordField.focus(), 30);
                            }
                        };

                        openBtn.addEventListener('click', (event) => {
                            event.preventDefault();
                            toggleModal(true);
                        });

                        if (closeBtn) {
                            closeBtn.addEventListener('click', (event) => {
                                event.preventDefault();
                                toggleModal(false);
                            });
                        }

                        modal.addEventListener('click', (event) => {
                            if (event.target === modal) {
                                toggleModal(false);
                            }
                        });

                        document.addEventListener('keydown', (event) => {
                            if (event.key === 'Escape') {
                                toggleModal(false);
                            }
                        });

                        if (modal.dataset.openOnLoad === '1') {
                            toggleModal(true);
                        }
                    })();
                </script>
            <?php endif; ?>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
}

new Simple_Coming_Soon_Mode();
