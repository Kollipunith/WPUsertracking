# WPUsertracking
A WordPress plugin for tracking user behavior, storing session data, and logging custom events. 

## Table of Contents

- [Features](#features)
- [Installation](#installation)
- [Usage](#usage)
- [Workflow](#workflow)
- [Data Management](#data-management)
- [Contributing](#contributing)
- [License](#license)
- [Support](#support)

## Features

- **User Session Tracking:**  
  Automatically assigns a unique user ID (via cookies) to each visitor and stores session data in a custom database table.

- **Page Visit Logging:**  
  Records each page visit (excluding static resources such as CSS, JS, images, etc.) along with the timestamp.

- **Custom Event Tracking:**  
  Captures custom events (e.g., button clicks, form submissions) via AJAX and logs them in a separate events table.

- **Admin Dashboard:**  
  - **Dashboard View:** Lists all user sessions with details like IP address, browser, device, referrer, last active time, pages visited count, and form submissions.
  - **User Detail Page:** Provides a session summary and detailed activity list (with computed time spent on pages) for individual users.
  - **Sortable and Paginated Tables:** Inspired by WooCommerce-style pagination and sorting, making it easy to manage large amounts of data.

- **Data Management:**  
  Administrators can delete tracking data based on:
  - Data older than a specified number of days.
  - Data for a specific user.
  - Data within a custom date range (with a preview option).
  - All tracking data.

- **Security:**  
  Implements nonce verification, input sanitization, and capability checks to secure AJAX requests and form submissions.

## Installation

1. Clone or download this repository.
2. Upload the plugin folder to your WordPress installation's `/wp-content/plugins/` directory.
3. Activate the plugin through the WordPress admin dashboard.
4. Upon activation, the plugin will create two custom database tables:
   - `wp_user_tracking` – stores user session and page visit data.
   - `wp_uta_events` – stores custom event data.
5. Adjust any configuration in the plugin files if needed.

## Usage

- **User Tracking:**  
  When a visitor accesses the site, the plugin checks for a `user_tracking_id` cookie. If it’s missing, a unique ID is generated and saved. Page visits are recorded using the `wp_head` hook while ignoring static resource requests.

- **Custom Events:**  
  Add the class `uta-track-click` (and an optional data attribute) to elements you want to track. The plugin’s JavaScript will send an AJAX request upon a click or form submission to log the event.

- **Admin Dashboard:**  
  Use the **User Tracking** menu in the admin area to view the dashboard, inspect detailed user sessions, and manage tracking data.

## Workflow

The plugin works as follows:

1. **Plugin Activation:**  
   Creates two custom database tables for storing session data and custom events.

2. **User Identification & Session Tracking:**  
   Checks for a tracking cookie on each visit. If missing, a unique ID is generated and stored.  
   Records page visits (ignoring static resources) along with a timestamp.

3. **Custom Event Logging:**  
   Listens for front-end events (e.g., clicks, form submissions) and logs them via AJAX in the events table.

4. **Administrative Interface:**  
   - **Dashboard:** Displays a sortable, paginated list of user sessions.
   - **User Detail:** Shows session summaries and detailed page activity (with computed "time spent").
   - **Data Management:** Offers options to delete tracking data by date, user, or entirely (with a preview feature for date range deletions).

### Textual Workflow Diagram

[Plugin Activation] │ ▼ [Database Table Creation] │ ▼ [User Visit & Cookie Check] │ ▼ [Unique User ID Generation] │ ▼ [Page Visit Logging (ignoring static resources)] │ ▼ [Custom Event Tracking via AJAX] │ ▼ [Admin Dashboard & User Detail Views] │ ▼ [Data Management (Delete by date, user, range, or all)]


## Data Management

The Data Management page provides administrators with secure options to remove tracking data:

- **Delete Data Older Than X Days:**  
  Remove data older than a specified number of days.

- **Delete Data for a Specific User:**  
  Remove tracking data for a particular user by entering the user ID.

- **Delete Data by Date Range (with Preview):**  
  Select a start and end date to preview the number of records that would be deleted, then confirm deletion.

- **Delete All Data:**  
  Remove all tracking data from the plugin's tables.

## Contributing

Contributions are welcome! If you have improvements, bug fixes, or feature requests:
1. Fork the repository.
2. Create your feature branch (`git checkout -b feature/YourFeature`).
3. Commit your changes (`git commit -m 'Add some feature'`).
4. Push to the branch (`git push origin feature/YourFeature`).
5. Open a pull request for review.

Please adhere to WordPress coding standards and document your changes clearly.

## License

This project is licensed under the [GPL-2.0+ License](https://www.gnu.org/licenses/gpl-2.0.html).

## Support

For issues or support, please open an issue in this repository or contact the maintainer at [your.email@example.com](mailto:info@elixrtech.com).



