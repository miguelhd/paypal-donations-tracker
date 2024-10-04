# PayPal Donations Tracker

## Description
The **PayPal Donations Tracker** is a WordPress plugin that allows you to easily track donations made via PayPal. This plugin integrates with the PayPal Standard SDK and provides real-time tracking of donations, as well as displaying progress toward donation goals.

## Features
- Real-time tracking of PayPal donations
- Display total raised, number of donations, and percentage of goal
- Customizable progress bar for donation goals
- Webhook integration for seamless tracking
- Admin dashboard for managing and viewing donations
- Customizable display settings for color, alignment, and more

## Installation

1. **Download the plugin**: You can download the latest version of the plugin from this repository or clone it directly.

    ```bash
    git clone https://github.com/yourusername/paypal-donations-tracker.git
    ```

2. **Upload the plugin to your WordPress site**:
    - Upload the entire plugin folder (`paypal-donations-tracker`) to the `/wp-content/plugins/` directory on your WordPress installation.

3. **Activate the plugin**:
    - In the WordPress admin dashboard, go to **Plugins > Installed Plugins**, find **PayPal Donations Tracker**, and click **Activate**.

4. **Configure the plugin**:
    - Navigate to **Settings > PayPal Donations Tracker** and enter your PayPal API credentials, set your donation goals, and customize the appearance of the donation tracker.

## Usage

1. **Adding the donation form**:
   - Use the `[paypal_donations_form]` shortcode to embed the donation form anywhere on your site (pages, posts, etc.).
   
2. **Tracking donations**:
   - View the donations and progress directly in the WordPress dashboard under **Donations**.

3. **Customizing the display**:
   - Use the settings page to adjust the progress bar colors, text alignment, and other visual elements.

## Requirements

- WordPress 5.0 or higher
- PHP 7.0 or higher
- PayPal account with API credentials

## Changelog

### v1.0
- Initial release with PayPal Standard SDK integration
- Real-time donation tracking and progress bar
- Admin dashboard for donation management

## License

This project is licensed under the MIT License - see the LICENSE file for details.

## Contributing

Contributions are welcome! Feel free to submit issues or pull requests to improve the functionality of this plugin.
