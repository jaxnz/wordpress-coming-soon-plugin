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
        ];
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

    public function render_logo_field() {
        $settings = $this->get_settings();
        $logo_id = absint($settings['logo_id']);
        $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'medium') : '';
        ?>
        <div style="margin-bottom: 8px;">
            <img id="scs-mode-logo-preview" src="<?php echo esc_url($logo_url); ?>" alt="<?php esc_attr_e('Logo preview', 'simple-coming-soon-mode'); ?>" style="max-height: 100px; max-width: 100%; display: <?php echo $logo_url ? 'block' : 'none'; ?>;" />
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

        status_header(503);
        nocache_headers();
        echo $this->render_frontend($settings);
        exit;
    }

    private function render_frontend($settings) {
        $logo_url = '';
        if (!empty($settings['logo_id'])) {
            $logo_url = wp_get_attachment_image_url(absint($settings['logo_id']), 'large');
        }

        $title = esc_html($settings['title']);
        $message = wpautop(wp_kses_post($settings['message']));

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
                    --scs-bg: #0f172a;
                    --scs-card: rgba(255, 255, 255, 0.06);
                    --scs-text: #e2e8f0;
                    --scs-accent: #38bdf8;
                    --scs-muted: #94a3b8;
                }
                * { box-sizing: border-box; }
                body {
                    margin: 0;
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    background: radial-gradient(circle at 20% 20%, rgba(56,189,248,0.15), transparent 35%), radial-gradient(circle at 80% 0%, rgba(94,234,212,0.2), transparent 30%), var(--scs-bg);
                    color: var(--scs-text);
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
                    padding: 24px;
                }
                .scs-shell {
                    width: 100%;
                    max-width: 640px;
                    background: var(--scs-card);
                    border: 1px solid rgba(255,255,255,0.08);
                    border-radius: 16px;
                    padding: 32px;
                    box-shadow: 0 20px 60px rgba(0,0,0,0.35);
                    backdrop-filter: blur(8px);
                    text-align: center;
                }
                .scs-logo {
                    max-width: 180px;
                    max-height: 120px;
                    margin: 0 auto 16px;
                    display: block;
                }
                h1 {
                    margin: 8px 0 12px;
                    font-size: clamp(28px, 4vw, 34px);
                    letter-spacing: -0.5px;
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
            </style>
        </head>
        <body>
            <main class="scs-shell" aria-label="<?php esc_attr_e('Coming soon message', 'simple-coming-soon-mode'); ?>">
                <?php if ($logo_url) : ?>
                    <img class="scs-logo" src="<?php echo esc_url($logo_url); ?>" alt="<?php esc_attr_e('Site logo', 'simple-coming-soon-mode'); ?>" />
                <?php endif; ?>
                <h1><?php echo $title; ?></h1>
                <div class="scs-message"><?php echo $message; ?></div>
            </main>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
}

new Simple_Coming_Soon_Mode();
