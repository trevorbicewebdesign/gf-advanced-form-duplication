# Gravity Forms Advanced Form Duplication

A powerful extension for Gravity Forms that adds a new “Clone with Payments” action, allowing you to duplicate a form along with its fields, notifications, confirmations, and payment feeds—including all associated conditional logic and meta.

---

## Features

- **Clone an Entire Form:** Duplicates form fields, settings, and structure.
- **Copies Payment Feeds:** All Stripe, PayPal, and other payment feeds are duplicated with mapped field IDs and conditional logic.
- **Copies Notifications & Confirmations:** All form notifications and confirmations are cloned, including any logic that references form fields.
- **Retains Conditional Logic:** All field and feed-level conditional logic is mapped to the new form’s field IDs.
- **Entries Grid Meta Support:** Copies entries grid display configuration for the new form.
- **Prevents Accidental Duplication:** Includes a confirmation modal to make sure you want to proceed before cloning.
- **Seamless Integration:** Adds a “Clone with Payments” action to the Gravity Forms list in the WordPress admin.

---

## Installation

1. **Download the plugin** (as a zip or drop the PHP file into your `wp-content/plugins/` folder).
2. **Activate** via the Plugins screen in your WordPress admin.
3. Go to **Forms > All Forms** and look for the new “Clone with Payments” action under each form.

---

## Usage

1. In the Gravity Forms admin, find the form you want to duplicate.
2. Click **Clone with Payments**.
3. Confirm the action in the popup modal.
4. A new form will be created as “[Your Form Name] (Clone)”, including all payment feeds, notifications, confirmations, and logic.

---

## Requirements

- **WordPress** 5.0+
- **Gravity Forms** 2.5+
- (Works with any payment add-ons using the standard Gravity Forms feed system)

---

## Notes

- Does *not* copy form entries, only the structure and feeds.
- All conditional logic and merge tags referencing field IDs are mapped to the new form.
- Use responsibly! Duplicating complex forms can introduce unintended logic if fields are manually changed post-clone.

---

## Author

**Trevor Bice**  
MIT License / GPL2+  
(c) 2025
