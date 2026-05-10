<?php

/**
 * Plugin Name: SW - ACF Options GraphQL
 * Plugin URI: https://www.seniors.com.co
 * Description: Dynamically exposes all ACF Options Page field groups via WPGraphQL with Polylang language support (via acf-options-for-polylang).
 * Version: 1.0.0
 * Author: Seniors
 * Author URI: https://www.seniors.com.co
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sw-acf-options-graphql
 * Requires PHP: 7.4
 * Requires at least: 5.8
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Convert snake_case to camelCase.
 */
function sw_acf_options_to_camel_case(string $string): string
{
    return lcfirst(str_replace('_', '', ucwords($string, '_')));
}

/**
 * Convert a string to PascalCase (for GraphQL type names).
 */
function sw_acf_options_to_pascal_case(string $string): string
{
    // Replace non-alphanumeric chars with spaces, then ucwords, then remove spaces
    $cleaned = preg_replace('/[^a-zA-Z0-9]+/', ' ', $string);
    return str_replace(' ', '', ucwords($cleaned));
}

/**
 * Map ACF field config to GraphQL scalar types.
 * Returns null for complex types and multi-value fields that cannot be serialized as scalars.
 */
function sw_acf_options_to_graphql_type(array $field)
{
    $type = $field['type'] ?? '';

    switch ($type) {
        case 'text':
        case 'textarea':
        case 'email':
        case 'url':
        case 'password':
        case 'wysiwyg':
        case 'oembed':
        case 'radio':
        case 'button_group':
        case 'color_picker':
        case 'date_picker':
        case 'date_time_picker':
        case 'time_picker':
            return 'String';
        case 'select':
            // Multi-select returns an array, which can't be serialized as String
            return empty($field['multiple']) ? 'String' : null;
        case 'number':
        case 'range':
            return 'Float';
        case 'true_false':
            return 'Boolean';
        case 'checkbox':
            // Checkbox always returns an array
            return null;
        default:
            return null;
    }
}

/**
 * Build a map of ACF Options Page slugs to their post_id values.
 */
function sw_acf_options_get_pages_map(): array
{
    if (!function_exists('acf_get_options_pages')) {
        return [];
    }

    $pages = acf_get_options_pages();
    $map = [];

    if (is_array($pages)) {
        foreach ($pages as $page) {
            $slug = $page['menu_slug'] ?? '';
            $map[$slug] = $page['post_id'] ?? 'options';
        }
    }

    return $map;
}

/**
 * Get the post_id for the options page a field group is assigned to.
 */
function sw_acf_options_get_post_id(array $group, array $options_pages)
{
    if (empty($group['location'])) {
        return false;
    }

    foreach ($group['location'] as $rules) {
        foreach ($rules as $rule) {
            if (isset($rule['param']) && $rule['param'] === 'options_page' && $rule['operator'] === '==') {
                $slug = $rule['value'] ?? '';
                return $options_pages[$slug] ?? 'options';
            }
        }
    }

    return false;
}

/**
 * Dynamically register all ACF Options Page field groups in WPGraphQL
 * with Polylang language support via acf-options-for-polylang.
 *
 * For each field group assigned to an options page, registers:
 * - A GraphQL object type (internal): SwOptions{Name}
 * - A root query field using ACF's graphql_field_name (or generated from title)
 *   with a `language` argument for Polylang translation.
 */
function sw_acf_options_register_graphql_fields(): void
{
    if (!class_exists('WPGraphQL') || !function_exists('acf_get_field_groups') || !function_exists('acf_get_fields') || !function_exists('PLL')) {
        return;
    }

    // LanguageCodeFilterEnum is registered by wp-graphql-polylang when both
    // WPGraphQL and Polylang are active. Fall back to String if unavailable.
    $language_type = 'LanguageCodeFilterEnum';

    $field_groups = acf_get_field_groups();
    $options_pages = sw_acf_options_get_pages_map();

    foreach ($field_groups as $group) {
        $post_id = sw_acf_options_get_post_id($group, $options_pages);

        if (!$post_id) {
            continue;
        }

        $acf_fields = acf_get_fields($group['key']);

        if (empty($acf_fields)) {
            continue;
        }

        // Use ACF's graphql_field_name if set, otherwise generate from title
        $field_name = !empty($group['graphql_field_name'])
            ? $group['graphql_field_name']
            : lcfirst(sw_acf_options_to_pascal_case($group['title']));

        // Skip if the generated name is empty or starts with a digit (invalid GraphQL name)
        if (empty($field_name) || preg_match('/^\d/', $field_name)) {
            continue;
        }

        $type_name = 'SwOptions' . ucfirst($field_name);

        // Build GraphQL fields and field map from ACF
        $graphql_fields = [];
        $field_map = []; // camelCase => acf_field_name

        foreach ($acf_fields as $field) {
            $graphql_type = sw_acf_options_to_graphql_type($field);

            if (!$graphql_type) {
                continue;
            }

            $camel_name = sw_acf_options_to_camel_case($field['name']);
            $graphql_fields[$camel_name] = [
                'type'        => $graphql_type,
                'description' => $field['label'],
            ];
            $field_map[$camel_name] = $field['name'];
        }

        if (empty($graphql_fields)) {
            continue;
        }

        register_graphql_object_type($type_name, [
            'description' => sprintf('ACF Options Page fields: %s', $group['title']),
            'fields'      => $graphql_fields,
        ]);

        register_graphql_field('RootQuery', $field_name, [
            'type'        => $type_name,
            'description' => sprintf('Get translated fields from ACF Options Page group: %s', $group['title']),
            'args'        => [
                'language' => [
                    'type'        => $language_type,
                    'description' => 'Polylang language code (e.g. ES, EN)',
                ],
            ],
            'resolve' => function ($_root, $args) use ($field_map, $post_id) {
                // Set Polylang language context so acf-options-for-polylang
                // returns the correct translated values via get_field()
                $lang_switched = false;
                $previous_lang = null;
                if (!empty($args['language']) && function_exists('PLL')) {
                    $lang = strtolower($args['language']);
                    $language = PLL()->model->get_language($lang);

                    if ($language) {
                        $previous_lang = PLL()->curlang;
                        PLL()->curlang = $language;
                        $lang_switched = true;
                    }
                }

                try {
                    $result = [];
                    foreach ($field_map as $camel_name => $acf_name) {
                        $result[$camel_name] = get_field($acf_name, $post_id) ?? null;
                    }

                    return $result;
                } finally {
                    // Restore previous language context
                    if ($lang_switched) {
                        PLL()->curlang = $previous_lang;
                    }
                }
            },
        ]);
    }
}
add_action('graphql_register_types', 'sw_acf_options_register_graphql_fields');
