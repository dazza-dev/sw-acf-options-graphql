=== SW - ACF Options GraphQL ===
Contributors: seniors
Tags: acf, wpgraphql, polylang, options-page, headless
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Dynamically exposes all ACF Options Page field groups via WPGraphQL with Polylang language support.

== Description ==

This plugin bridges ACF Options Pages with WPGraphQL and Polylang, enabling translated options page fields in a headless WordPress setup.

It automatically discovers all ACF field groups assigned to Options Pages and registers them as root query fields in WPGraphQL, each with a `language` argument for Polylang translation support.

**How it works:**

* Scans all ACF field groups with `options_page` location rules
* For each group, registers a GraphQL object type and root query field
* Uses the ACF `graphql_field_name` (or generates one from the group title)
* Sets Polylang language context before resolving fields via `get_field()`
* Works with [ACF Options for Polylang](https://wordpress.org/plugins/acf-options-for-polylang/) for per-language option values

**Example query:**

`
{
  sectionHeaders(language: ES) {
    featuresTitle
    featuresSubtitle
    plansTitle
    plansSubtitle
  }
}
`

== Requirements ==

* [Advanced Custom Fields PRO](https://www.advancedcustomfields.com/pro/)
* [WPGraphQL](https://www.wpgraphql.com/)
* [Polylang PRO](https://polylang.pro/)
* [ACF Options for Polylang](https://wordpress.org/plugins/acf-options-for-polylang/)

== Installation ==

1. Upload the `sw-acf-options-graphql` folder to `/wp-content/plugins/`
2. Activate the plugin through the "Plugins" menu in WordPress
3. Ensure ACF Options Pages have field groups with "Show in GraphQL" enabled
4. Query your options via WPGraphQL with the `language` argument

== Changelog ==

= 1.0.0 =
* Initial release
* Dynamic field group discovery from ACF Options Pages
* Polylang language support via acf-options-for-polylang
* Automatic snake_case to camelCase field name conversion
* ACF field type to GraphQL scalar type mapping
