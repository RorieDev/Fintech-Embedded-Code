<?php
/**
 * Plugin Name: AI Asset Valuer
 * Description: Provides the AI asset valuer shortcode with configurable language and currency.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('AI_Asset_Valuer_Shortcode')) {
    class AI_Asset_Valuer_Shortcode
    {
        private const SHORTCODE = 'ai_asset_valuer';
        private const REST_NAMESPACE = 'aiaas/v1';
        private const POST_TYPE = 'aiaas_asset';

        private const LANGUAGE_TEMPLATES = [
            'en' => 'camera-ai-asset-valuer-english',
            'ar' => 'camera-ai-asset-valuer-arabic',
        ];

        private const LOCALE_MAP = [
            'en' => 'en-GB',
            'ar' => 'ar',
        ];

        public static function register(): void
        {
            $instance = new self();
            $instance->register_post_type();
            add_shortcode(self::SHORTCODE, [$instance, 'render_shortcode']);
            add_action('rest_api_init', [$instance, 'register_rest_routes']);
        }

        public function render_shortcode($atts = [], $content = '', $tag = ''): string
        {
            $atts = shortcode_atts(
                [
                    'language' => 'en',
                    'currency' => 'GBP',
                ],
                $atts,
                self::SHORTCODE
            );

            $language = $this->sanitize_language($atts['language'] ?? '');
            $currency = $this->sanitize_currency($atts['currency'] ?? '');
            $locale   = $this->resolve_locale($language);

            $template_file = $this->resolve_template($language);
            if (!$template_file) {
                return '';
            }

            $markup = file_get_contents($template_file);
            if ($markup === false) {
                return '';
            }

            $dataset = sprintf(
                ' data-aiav-currency="%s" data-aiav-language="%s" data-aiav-locale="%s"',
                $this->esc_attr($currency),
                $this->esc_attr($language),
                $this->esc_attr($locale)
            );

            $markup = preg_replace(
                '/<div\s+id="valuationWidget"/i',
                '<div id="valuationWidget"' . $dataset,
                $markup,
                1
            );

            return $markup;
        }

        private function sanitize_language(string $language): string
        {
            $language = strtolower(preg_replace('/[^a-z_-]/', '', $language));
            if (!isset(self::LANGUAGE_TEMPLATES[$language])) {
                $language = 'en';
            }

            return $language;
        }

        private function sanitize_currency(string $currency): string
        {
            $currency = strtoupper(preg_replace('/[^A-Za-z]/', '', $currency));
            if (strlen($currency) !== 3) {
                $currency = 'GBP';
            }

            return $currency;
        }

        private function resolve_locale(string $language): string
        {
            if (isset(self::LOCALE_MAP[$language])) {
                return self::LOCALE_MAP[$language];
            }

            return $language ?: 'en-GB';
        }

        private function resolve_template(string $language): ?string
        {
            $file = self::LANGUAGE_TEMPLATES[$language] ?? null;
            if (!$file) {
                return null;
            }

            $path = __DIR__ . '/' . $file;
            if (!file_exists($path)) {
                return null;
            }

            return $path;
        }

        private function esc_attr(string $value): string
        {
            if (function_exists('esc_attr')) {
                return esc_attr($value);
            }

            return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }

        public function register_post_type(): void
        {
            $labels = [
                'name' => __('AI Assets', 'ai-asset-valuer'),
                'singular_name' => __('AI Asset', 'ai-asset-valuer'),
                'add_new_item' => __('Add New AI Asset', 'ai-asset-valuer'),
                'edit_item' => __('Edit AI Asset', 'ai-asset-valuer'),
                'new_item' => __('New AI Asset', 'ai-asset-valuer'),
                'view_item' => __('View AI Asset', 'ai-asset-valuer'),
                'search_items' => __('Search AI Assets', 'ai-asset-valuer'),
            ];

            register_post_type(
                self::POST_TYPE,
                [
                    'labels' => $labels,
                    'public' => false,
                    'show_ui' => true,
                    'show_in_menu' => true,
                    'menu_position' => 25,
                    'menu_icon' => 'dashicons-database',
                    'supports' => ['title', 'editor', 'thumbnail', 'custom-fields', 'excerpt'],
                    'capability_type' => 'post',
                    'map_meta_cap' => true,
                ]
            );
        }

        public function register_rest_routes(): void
        {
            register_rest_route(
                self::REST_NAMESPACE,
                '/save-asset',
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'handle_save_asset'],
                    'permission_callback' => [$this, 'can_save_asset'],
                    'args' => $this->get_save_asset_args(),
                ]
            );
        }

        public function can_save_asset(): bool
        {
            if (function_exists('current_user_can')) {
                return current_user_can('edit_posts');
            }

            return false;
        }

        public function handle_save_asset(\WP_REST_Request $request)
        {
            $params = $request->get_json_params();
            if (!is_array($params)) {
                return new \WP_Error('invalid_payload', __('Invalid request payload.', 'ai-asset-valuer'), ['status' => 400]);
            }

            $image = (string)($params['image'] ?? '');
            if ($image === '') {
                return new \WP_Error('missing_image', __('A captured image is required.', 'ai-asset-valuer'), ['status' => 422]);
            }

            $post_data = $this->prepare_post_data($params);
            $post_id = wp_insert_post($post_data, true);
            if (is_wp_error($post_id)) {
                return $post_id;
            }

            $this->store_meta($post_id, $params);

            $attachment_id = $this->store_featured_image($post_id, $image);
            if (is_wp_error($attachment_id)) {
                wp_delete_post($post_id, true);
                return $attachment_id;
            }

            $response = [
                'id' => $post_id,
                'link' => get_edit_post_link($post_id, 'raw'),
                'attachment_id' => $attachment_id,
            ];

            return new \WP_REST_Response($response, 201);
        }

        private function prepare_post_data(array $params): array
        {
            $brand = $this->sanitize_text($params['brand'] ?? '');
            $model = $this->sanitize_text($params['model'] ?? '');
            $category = $this->sanitize_text($params['category'] ?? '');
            $specs = $this->sanitize_textarea($params['specs'] ?? '');

            $title_parts = array_filter([$brand, $model]);
            $title = trim(implode(' ', $title_parts));
            if ($title === '') {
                $title = $category !== '' ? $category : __('Asset', 'ai-asset-valuer');
            }

            $content = $specs;
            if ($content !== '' && strpos($content, '<p>') === false) {
                $content = wpautop($content);
            }

            return [
                'post_type' => self::POST_TYPE,
                'post_status' => 'publish',
                'post_title' => $title,
                'post_content' => $content,
                'post_excerpt' => $this->sanitize_text($params['notes'] ?? ''),
            ];
        }

        private function store_meta(int $post_id, array $params): void
        {
            $meta_map = [
                'currency' => 'sanitize_key',
                'language' => 'sanitize_key',
                'category' => 'sanitize_text_field',
                'brand' => 'sanitize_text_field',
                'model' => 'sanitize_text_field',
                'specs' => 'sanitize_textarea_field',
                'notes' => 'sanitize_textarea_field',
            ];

            foreach ($meta_map as $key => $sanitizer) {
                if (!isset($params[$key])) {
                    continue;
                }
                $value = $params[$key];
                if (is_string($value)) {
                    $value = call_user_func($sanitizer, $value);
                }
                update_post_meta($post_id, 'aiav_' . $key, $value);
            }

            if (isset($params['confidence'])) {
                $confidence = $this->sanitize_number($params['confidence']);
                if ($confidence !== null) {
                    update_post_meta($post_id, 'aiav_confidence', $confidence);
                }
            }

            foreach ($params as $key => $value) {
                if (!is_scalar($value)) {
                    continue;
                }
                if (!preg_match('/^(valuation|price_low|price_high)_/i', (string)$key)) {
                    continue;
                }
                update_post_meta($post_id, 'aiav_' . sanitize_key((string)$key), sanitize_text_field((string)$value));
            }
        }

        private function store_featured_image(int $post_id, string $image)
        {
            $image = trim($image);
            if (!preg_match('/^data:image\/(\w+);base64,/', $image, $matches)) {
                return new \WP_Error('invalid_image', __('Image must be a base64 encoded data URL.', 'ai-asset-valuer'), ['status' => 422]);
            }

            $mime = strtolower($matches[1]);
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            if (!in_array($mime, $allowed, true)) {
                return new \WP_Error('invalid_image_type', __('Unsupported image type.', 'ai-asset-valuer'), ['status' => 415]);
            }

            $data = substr($image, strlen($matches[0]));
            $binary = base64_decode($data, true);
            if ($binary === false) {
                return new \WP_Error('invalid_image_data', __('Unable to decode image data.', 'ai-asset-valuer'), ['status' => 422]);
            }

            $extension = $mime === 'jpeg' ? 'jpg' : $mime;
            $filename = 'ai-asset-' . uniqid('', true) . '.' . $extension;
            $upload = wp_upload_bits($filename, '', $binary);
            if (!empty($upload['error'])) {
                return new \WP_Error('upload_error', $upload['error'], ['status' => 500]);
            }

            $file = $upload['file'];
            $type = wp_check_filetype($file, null);

            $attachment = [
                'post_mime_type' => $type['type'] ?? 'image/' . $extension,
                'post_title' => sanitize_file_name(pathinfo($file, PATHINFO_FILENAME)),
                'post_content' => '',
                'post_status' => 'inherit',
            ];

            $attachment_id = wp_insert_attachment($attachment, $file, $post_id, true);
            if (is_wp_error($attachment_id)) {
                @unlink($file);
                return $attachment_id;
            }

            if (!function_exists('wp_generate_attachment_metadata')) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
            }

            $metadata = wp_generate_attachment_metadata($attachment_id, $file);
            wp_update_attachment_metadata($attachment_id, $metadata);
            set_post_thumbnail($post_id, $attachment_id);

            return $attachment_id;
        }

        private function sanitize_text($value): string
        {
            $value = is_string($value) ? $value : '';
            return sanitize_text_field($value);
        }

        private function sanitize_textarea($value): string
        {
            $value = is_string($value) ? $value : '';
            return sanitize_textarea_field($value);
        }

        private function sanitize_number($value): ?float
        {
            if (is_numeric($value)) {
                return (float)$value;
            }

            if (is_string($value)) {
                $filtered = preg_replace('/[^0-9.+-]/', '', $value);
                if ($filtered !== '' && is_numeric($filtered)) {
                    return (float)$filtered;
                }
            }

            return null;
        }

        private function get_save_asset_args(): array
        {
            return [
                'image' => [
                    'required' => true,
                    'description' => __('Base64 encoded image data URL.', 'ai-asset-valuer'),
                    'type' => 'string',
                ],
                'currency' => [
                    'required' => false,
                    'type' => 'string',
                ],
                'language' => [
                    'required' => false,
                    'type' => 'string',
                ],
            ];
        }
    }
}

add_action('init', ['AI_Asset_Valuer_Shortcode', 'register']);
