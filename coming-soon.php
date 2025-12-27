<?php
/**
 * Plugin Name: Simple Coming Soon Mode
 * Description: Display a customizable coming soon screen with your logo, headline, and supporting text. Admins can toggle visibility without affecting their own view.
 * Version: 1.0.0
 * Author: Jackson / Codex
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
    }

    public function sanitize_settings($input) {
        $defaults = $this->defaults();

        return [
            'enabled' => !empty($input['enabled']),
            'title' => sanitize_text_field($input['title'] ?? $defaults['title']),
            'message' => wp_kses_post($input['message'] ?? $defaults['message']),
            'logo_id' => isset($input['logo_id']) ? absint($input['logo_id']) : 0,
            'password' => sanitize_text_field($input['password'] ?? ''),
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

        status_header(503);
        nocache_headers();
        echo $this->render_frontend($settings, $requires_password, $error_message);
        exit;
    }

    private function render_frontend($settings, $requires_password = false, $error_message = '') {
        $logo_url = '';
        if (!empty($settings['logo_id'])) {
            $logo_url = wp_get_attachment_image_url(absint($settings['logo_id']), 'large');
        }

        $title = esc_html($settings['title']);
        $message = wpautop(wp_kses_post($settings['message']));
        $accent = $this->derive_accent_color(!empty($settings['logo_id']) ? absint($settings['logo_id']) : 0);
        $accent_rgb = $this->hex_to_rgb_string($accent);

        ob_start();
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1" />
            <title><?php echo esc_html($settings['title']); ?></title>
            <style>
                :root {
                    --scs-bg: #f5f7fb;
                    --scs-card: #ffffff;
                    --scs-text: #0f172a;
                    --scs-accent: <?php echo esc_html($accent); ?>;
                    --scs-accent-rgb: <?php echo esc_html($accent_rgb); ?>;
                    --scs-muted: #475569;
                }
                * { box-sizing: border-box; }
                body {
                    margin: 0;
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    background:
                        radial-gradient(circle at 12% 22%, rgba(var(--scs-accent-rgb), 0.28), transparent 32%),
                        radial-gradient(circle at 85% 8%, rgba(var(--scs-accent-rgb), 0.22), transparent 36%),
                        radial-gradient(circle at 55% 100%, rgba(var(--scs-accent-rgb), 0.18), transparent 30%),
                        var(--scs-bg);
                    color: var(--scs-text);
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
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
                    color: #fff;
                    border: none;
                    border-radius: 10px;
                    padding: 12px 18px;
                    font-size: 16px;
                    font-weight: 600;
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
            </style>
        </head>
        <body>
            <main class="scs-shell" aria-label="<?php esc_attr_e('Coming soon message', 'simple-coming-soon-mode'); ?>">
                <?php if ($logo_url) : ?>
                    <img class="scs-logo" src="<?php echo esc_url($logo_url); ?>" alt="<?php esc_attr_e('Site logo', 'simple-coming-soon-mode'); ?>" />
                <?php endif; ?>
                <h1><?php echo $title; ?></h1>
                <div class="scs-message"><?php echo $message; ?></div>
                <?php if (!empty($error_message)) : ?>
                    <div class="scs-alert"><?php echo esc_html($error_message); ?></div>
                <?php endif; ?>
                <?php if ($requires_password) : ?>
                    <form method="post" class="scs-pass-form">
                        <?php wp_nonce_field('scs_password_entry', 'scs_password_nonce'); ?>
                        <label class="scs-pass-label" for="scs_mode_password"><?php esc_html_e('Enter the access password to view the site.', 'simple-coming-soon-mode'); ?></label>
                        <div class="scs-pass-row">
                            <input type="password" name="scs_mode_password" id="scs_mode_password" placeholder="<?php esc_attr_e('Password', 'simple-coming-soon-mode'); ?>" required />
                            <button type="submit" name="scs_password_submit" class="scs-pass-button"><?php esc_html_e('Continue', 'simple-coming-soon-mode'); ?></button>
                        </div>
                    </form>
                <?php endif; ?>
            </main>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
}

new Simple_Coming_Soon_Mode();
