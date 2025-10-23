# TCG Kiosk Filter

This WordPress plugin bundles JSON databases for several trading card games and exposes a fully client-side browser with filtering tools.

## Installation

1. Copy the `tcg-kiosk-filter` directory into the `wp-content/plugins` directory of your WordPress installation.
2. Activate **TCG Kiosk Filter** from the WordPress admin Plugins screen.

Activation automatically creates a page titled **TCG Kiosk Browser** containing the shortcode `[tcg_kiosk_browser]`. You can also place the shortcode manually on any page or post.

## Features

- Automatically indexes every trading card JSON file that ships with the plugin.
- Filters cards by trading card game, set, and card name search term.
- Displays card artwork using the URLs referenced in the JSON datasets.
- Responsive layout that adapts to desktop and mobile screens.

## Customisation

The database lives in the plugin's `database` directory. New trading card games can be added by dropping additional folders (matching the existing structure) into that directory. Files are parsed on demand—no additional configuration is required.
