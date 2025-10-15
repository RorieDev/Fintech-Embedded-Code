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
            add_shortcode(self::SHORTCODE, [$instance, 'render_shortcode']);
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
    }
}

add_action('init', ['AI_Asset_Valuer_Shortcode', 'register']);
