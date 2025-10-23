# TCG Kiosk WordPress Plugin

This repository contains the **TCG Kiosk Filter** WordPress plugin. The plugin bundles JSON databases for multiple trading card games and provides a responsive browsing interface with filtering capabilities.

## Getting Started

1. Copy the `wp-content/plugins/tcg-kiosk-filter` directory into your WordPress installation's `wp-content/plugins` folder.
2. Activate **TCG Kiosk Filter** from the Plugins screen in the WordPress admin area.
3. Visit the automatically generated **TCG Kiosk Browser** page (or place the `[tcg_kiosk_browser]` shortcode on any page) to explore the trading card catalogue.

## Repository Structure

- `wp-content/plugins/tcg-kiosk-filter` – The plugin source code, bundled assets, and JSON databases used for rendering card data.

## Development Notes

The plugin automatically scans the JSON files located in `database/` each time the shortcode renders. Adding new trading card games or updating card data is as simple as placing additional JSON files that follow the existing structure into that directory.
