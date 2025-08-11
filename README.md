# Calendly Availability Module

## Overview

This module provides a Drupal block that displays aggregated Calendly availability information. It can fetch available time slots from multiple Calendly event types and display them in either a list or a weekly schedule table view. This is particularly useful for organizations that need to show the combined availability of different staff members or for various services.

The module is designed to be flexible and configurable, allowing site administrators to control which event types are displayed, customize the appearance of the availability information, and provide fallback options for users when no time slots are available.

## Features

* **Display Modes**: Show availability in a simple list or a weekly table view.
* **Event Type Filtering**: Filter event types by keywords or by selecting specific event types from a list.
* **Customizable**:
    * Set the number of days to show availability for.
    * Customize the button text for scheduling.
    * Configure a fallback URL for users if no slots are available.
* **OAuth Support**: Securely connects to the Calendly API using OAuth 2.0 for fetching availability.
* **Multi-environment configuration**: Supports different credentials for production, testing, and development environments.

## Installation

1.  **Dependencies**: This module requires the Drupal Block module.
2.  **Download/Clone**: Place the module in your Drupal site's `modules` directory.
3.  **Enable**: Enable the module through the Drupal UI or by using Drush (`drush en calendly_availability`).

## Configuration

### 1. API Credentials

Before using the module, you need to configure your Calendly API credentials.

1.  Navigate to **Configuration > Services > Calendly API Settings** (`/admin/config/services/calendly_availability`).
2.  Enter the **Base URL**, **Client ID**, and **Client Secret** for each of your environments (Production, Testing, Development). The module will automatically use the settings that match your current website's URL.
3.  Save the configuration.
4.  Click the "Authorize with Calendly for this Environment" button to initiate the OAuth connection. You will be redirected to Calendly to approve the authorization.

### 2. Block Placement

Once the module is configured, you can place the "Calendly Availability Block" in any region of your theme.

1.  Navigate to **Structure > Block layout** (`/admin/structure/block`).
2.  Click "Place block" in the desired region.
3.  Search for "Calendly Availability Block" and click "Place block".

### 3. Block Configuration

When placing the block, you will have several options to customize its behavior:

* **Display Mode**: Choose between "List View" and "Weekly Schedule Table View".
* **Event Type Selection**:
    * **Select Specific Event Types**: Choose one or more event types from a list. This will override the keyword filter.
    * **Event Type Keywords (Fallback)**: If no specific event types are selected, you can enter comma-separated keywords to filter which event types are displayed.
* **Display & Fallback Settings**:
    * **Button Action Text**: Customize the text that appears on the scheduling buttons (e.g., "Book Now", "Schedule").
    * **Staff Name Display**: Choose how to display the staff/owner name for each slot (First Name Only, Full Name, or Do Not Display).
    * **Days to Show Availability For**: Set the number of days (from today) to fetch and display availability.
    * **Fallback Scheduling URL**: Provide a URL for users to go to if no time slots are available.
    * **Fallback Link Text**: Customize the text for the fallback link.
    * **Custom "No Results" Message**: Set a custom message to display when no slots are found and no fallback URL is used.
* **Weekly View Settings**:
    * **Hide empty time columns**: If checked, time blocks that have no slots across all shown days will be hidden from the table.
    * **Hide empty day rows**: If checked, days that have no slots across all visible time blocks will be hidden from the table.

## Theming

The module provides several Twig templates that you can override in your theme for further customization:

* `templates/calendly-availability-block.html.twig`: The main template for the list view.
* `templates/calendly-availability-week-schedule.html.twig`: The template for the weekly schedule view.
* `templates/calendly-availability-fallback.html.twig`: The template for the fallback link when no slots are available.

The module also includes a basic CSS file (`css/calendly_availability.css`) that you can use as a starting point for styling.

## Diagnostics

The module provides a diagnostics page to help you troubleshoot API connectivity issues. You can access it at **Configuration > Services > Calendly API Settings > Diagnostics** (`/admin/config/services/calendly_availability/diagnostics`). This page will show you the status of your API token and whether the module can successfully connect to the Calendly API.