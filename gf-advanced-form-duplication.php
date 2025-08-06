<?php
/**
 * Plugin Name: GF Advanced Form Duplication
 * Description: Adds a button to duplicate a Gravity Form along with its notifications and payment feeds.
 * Version: 1.0.3
 * Author: Trevor Bice
 * License: GPL2+
 *
 * This plugin extends Gravity Forms by providing an advanced form duplication feature.
 * When activated, it adds a "Clone with Payments" action to each form in the Gravity Forms admin.
 * The duplication process includes:
 *   - Cloning the form fields, preserving field types, labels, and conditional logic.
 *   - Cloning notifications, remapping field IDs and merge tags to match the new form.
 *   - Cloning confirmations, including their conditional logic and merge tags.
 *   - Cloning payment feeds (from add-ons), updating all field references in feed meta.
 *   - Cloning the entries grid meta for the new form.
 *
 * Main Classes:
 *   - GF_Clone_With_Payments_Cloner: Handles the logic for cloning forms, fields, notifications, confirmations, and payment feeds.
 *   - GF_Clone_With_Payments_Plugin: Integrates with the Gravity Forms admin UI, adds the clone action, and handles clone requests.
 *
 * Key Methods:
 *   - clone_form($source_form_id): Orchestrates the cloning process for a given form ID.
 *   - fix_input_ids($fields): Ensures sub-input IDs are updated to match new field IDs.
 *   - copy_notifications($source_form_id, $new_form_id, $field_map): Clones and remaps notifications.
 *   - copy_confirmations($form, $new_form_id, $field_map): Clones and remaps confirmations.
 *   - copy_payment_feeds($source_form_id, $new_form_id): Clones payment feeds and remaps field references.
 *   - remap_all_field_ids_recursive($data, $id_map): Recursively updates all field ID references in arrays.
 *   - remap_merge_tags($text, $id_map): Updates merge tags in notification/confirmation text to use new field IDs.
 *   - update_fields_conditional_logic($fields, $field_map): Updates conditional logic rules in cloned fields.
 *
 * UI Integration:
 *   - Adds a "Clone with Payments" link to the form actions menu.
 *   - Displays a confirmation modal before cloning.
 *   - Redirects to the new form edit page after cloning.
 *
 * Security:
 *   - Uses WordPress nonces and capability checks to restrict cloning to authorized users.
 *
 * Requirements:
 *   - Gravity Forms must be installed and active.
 *   - User must have 'manage_options' capability to clone forms.
 */

if (!defined('ABSPATH')) {
    exit;
}

class GF_Clone_With_Payments_Cloner
{
    public function clone_form($source_form_id)
    {
        $form = GFAPI::get_form($source_form_id);
        if (!$form) {
            return new WP_Error('form_not_found', 'Original form not found.');
        }

        unset($form['id']);
        $form['title'] .= ' (Clone)';
        $form['is_active'] = true;
        $form['is_trash'] = false;
        unset($form['nextFieldId']);

        // Shallow clone fields, but leave IDs as null so GF assigns new ones
        $new_fields = [];
        foreach ($form['fields'] as $field) {
            if ($field instanceof GF_Field) {
                $field = clone $field;
            } else {
                $field = GF_Fields::create((array) $field);
            }
            if (!$field instanceof GF_Field) {
                continue;
            }
            $field->id = null;
            $field->formId = null;
            $new_fields[] = $field;
        }
        $form['fields'] = $new_fields;

        // Create the new form
        $new_form_id = GFAPI::add_form($form);
        if (is_wp_error($new_form_id)) {
            return $new_form_id;
        }

        // Now fix the input IDs using the newly assigned field IDs
        $form_meta = GFAPI::get_form($new_form_id);
        $form_meta['fields'] = $this->fix_input_ids($form_meta['fields']);

        // Use the new method here!
        $this->copy_entries_grid_meta($source_form_id, $new_form_id);

        // (rest of your function as before…)
        $old_fields = GFAPI::get_form($source_form_id)['fields'];
        $new_fields = $form_meta['fields'];
        $field_map = [];
        foreach ($old_fields as $i => $field) {
            if (isset($new_fields[$i]) && $field->label === $new_fields[$i]->label && $field->type === $new_fields[$i]->type) {
                $old_id = strval($field->id);
                $new_id = strval($new_fields[$i]->id);
                $field_map[$old_id] = $new_id;

                // Map sub-inputs, match by label
                if (!empty($field->inputs) && !empty($new_fields[$i]->inputs)) {
                    foreach ($field->inputs as $old_input) {
                        foreach ($new_fields[$i]->inputs as $new_input) {
                            if ($old_input['label'] === $new_input['label']) {
                                $field_map[(string) $old_input['id']] = (string) $new_input['id'];
                            }
                        }
                    }
                }
            }
        }

        $form['fields'] = $this->update_fields_conditional_logic($form_meta['fields'], $field_map);
        GFAPI::update_form($form_meta);

        $this->copy_notifications($source_form_id, $new_form_id, $field_map);
        $this->copy_confirmations($form, $new_form_id, $field_map);
        $this->copy_payment_feeds($source_form_id, $new_form_id);

        return $new_form_id;
    }

    /**
     * Fixes the input IDs for each field in the provided array.
     *
     * Iterates through each field and its inputs, ensuring that each input's ID
     * is correctly formatted as "{field_id}.{suffix}" if a suffix exists, or just "{field_id}" otherwise.
     * Handles both array and object representations of inputs.
     *
     * @param array $fields Array of field objects, each potentially containing an 'inputs' property.
     * @return array The modified array of fields with corrected input IDs.
     */
    protected function fix_input_ids($fields)
    {
        foreach ($fields as &$field) {
            if (isset($field->inputs) && is_array($field->inputs) && !empty($field->inputs)) {
                $fid = $field->id;
                foreach ($field->inputs as &$input) {
                    // Standard: .2, .3, .4, etc. OR sometimes just numbers
                    if (is_array($input) && isset($input['id'])) {
                        $parts = explode('.', (string) $input['id']);
                        $suffix = isset($parts[1]) ? $parts[1] : '';
                        $input['id'] = $suffix !== '' ? "{$fid}.{$suffix}" : "{$fid}";
                    } elseif (is_object($input) && isset($input->id)) {
                        $parts = explode('.', (string) $input->id);
                        $suffix = isset($parts[1]) ? $parts[1] : '';
                        $input->id = $suffix !== '' ? "{$fid}.{$suffix}" : "{$fid}";
                    }
                }
            }
        }
        return $fields;
    }


    /**
     * Copies notifications from a source Gravity Form to a new form, remapping field IDs and merge tags as necessary.
     *
     * This method retrieves all notifications associated with the source form, updates any conditional logic,
     * routing rules, and merge tags to reference the new field IDs as defined in the provided field map,
     * and then saves the updated notifications to the new form.
     *
     * @param int   $source_form_id The ID of the source form from which notifications are copied.
     * @param int   $new_form_id    The ID of the new form to which notifications are copied.
     * @param array $field_map      An associative array mapping old field IDs to new field IDs.
     *
     * @return void
     */
    protected function copy_notifications($source_form_id, $new_form_id, $field_map)
    {
        $notifications = GFCommon::get_notifications('form_submission', $source_form_id);
        foreach ($notifications as $notification) {
            // Remap conditional logic
            if (!empty($notification['conditionalLogic'])) {
                $notification['conditionalLogic'] = $this->remap_all_field_ids_recursive($notification['conditionalLogic'], $field_map);
            }
            // Remap routing rules
            if (!empty($notification['routing']) && is_array($notification['routing'])) {
                foreach ($notification['routing'] as &$route) {
                    if (isset($route['fieldId']) && isset($field_map[$route['fieldId']])) {
                        $route['fieldId'] = $field_map[$route['fieldId']];
                    }
                    foreach (['value', 'email'] as $rkey) {
                        if (isset($route[$rkey])) {
                            $route[$rkey] = $this->remap_merge_tags($route[$rkey], $field_map);
                        }
                    }
                }
                unset($route);
            }
            // Remap merge tags everywhere else
            foreach (['subject', 'message', 'toField', 'to', 'from', 'fromName', 'replyTo', 'bcc', 'cc'] as $key) {
                if (!empty($notification[$key])) {
                    $notification[$key] = $this->remap_merge_tags($notification[$key], $field_map);
                }
            }

            $notification['form_id'] = $new_form_id;
            GFNotifications::update_notification($new_form_id, $notification);
        }
    }

    /**
     * Copies the 'entries_grid_meta' metadata from a source Gravity Form to a new form.
     *
     * This method retrieves the 'entries_grid_meta' value from the source form's metadata
     * and updates the new form's metadata with this value. It uses the WordPress $wpdb object
     * to interact with the database.
     *
     * @param int $source_form_id The ID of the source form from which to copy the metadata.
     * @param int $new_form_id    The ID of the new form to which the metadata will be copied.
     *
     * @global wpdb $wpdb WordPress database abstraction object.
     */
    protected function copy_entries_grid_meta($source_form_id, $new_form_id)
    {
        global $wpdb;
        $meta_table = $wpdb->prefix . 'gf_form_meta';

        $old_row = $wpdb->get_row(
            $wpdb->prepare("SELECT entries_grid_meta FROM $meta_table WHERE form_id = %d", $source_form_id),
            ARRAY_A
        );

        if (!empty($old_row) && isset($old_row['entries_grid_meta'])) {
            $result = $wpdb->update(
                $meta_table,
                ['entries_grid_meta' => $old_row['entries_grid_meta']],
                ['form_id' => $new_form_id]
            );
        } 
    }

    /**
     * Copies and remaps the confirmations from the original form to a new form.
     *
     * This method duplicates the confirmations array from the given form, remapping any field IDs
     * in conditional logic and merge tags according to the provided field map. It then updates
     * the new form with the remapped confirmations.
     *
     * @param array $form The original form array containing confirmations to copy.
     * @param int $new_form_id The ID of the new form to which confirmations will be copied.
     * @param array $field_map An associative array mapping old field IDs to new field IDs.
     *
     * @return void
     */
    protected function copy_confirmations($form, $new_form_id, $field_map)
    {
        if (!empty($form['confirmations'])) {
            $confirmations = $form['confirmations'];
            foreach ($confirmations as $cid => &$confirmation) {
                // Remap logic rules
                if (isset($confirmation['confirmation_conditional_logic_object'])) {
                    $confirmation['confirmation_conditional_logic_object'] =
                        $this->remap_all_field_ids_recursive($confirmation['confirmation_conditional_logic_object'], $field_map);
                }
                if (isset($confirmation['conditionalLogic'])) {
                    $confirmation['conditionalLogic'] =
                        $this->remap_all_field_ids_recursive($confirmation['conditionalLogic'], $field_map);
                }
                // Remap merge tags in message and url fields
                foreach (['message', 'url', 'subject'] as $field) {
                    if (isset($confirmation[$field])) {
                        $confirmation[$field] = $this->remap_merge_tags($confirmation[$field], $field_map);
                    }
                }
            }
            unset($confirmation);
            $new_form_meta = GFAPI::get_form($new_form_id);
            $new_form_meta['confirmations'] = $confirmations;
            GFAPI::update_form($new_form_meta);
        }
    }

    /**
     * Copies payment feeds from a source Gravity Forms form to a new form, updating all field references.
     *
     * This method retrieves all payment feeds associated with the source form, remaps field IDs (including sub-inputs)
     * to match the new form's fields, and inserts the updated feeds for the new form. It ensures that all field references
     * within the feed meta are recursively updated to maintain consistency.
     *
     * @param int $source_form_id The ID of the source Gravity Forms form.
     * @param int $new_form_id    The ID of the new Gravity Forms form to which feeds will be copied.
     */
    protected function copy_payment_feeds($source_form_id, $new_form_id)
    {
        global $wpdb;
        $feeds_table = $wpdb->prefix . 'gf_addon_feed';
        $feeds = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $feeds_table WHERE form_id = %d", $source_form_id),
            ARRAY_A
        );

        $old_fields = GFAPI::get_form($source_form_id)['fields'];
        $new_fields = GFAPI::get_form($new_form_id)['fields'];

        // Build the field map (old_id => new_id), including sub-inputs (e.g., 4.1 => 45.1)
        $field_map = [];
        foreach ($old_fields as $i => $field) {
            if (isset($new_fields[$i]) && $field->label === $new_fields[$i]->label && $field->type === $new_fields[$i]->type) {
                $old_id = strval($field->id);
                $new_id = strval($new_fields[$i]->id);
                $field_map[$old_id] = $new_id;

                // Map sub-inputs
                if (!empty($field->inputs) && !empty($new_fields[$i]->inputs)) {
                    foreach ($field->inputs as $idx => $input) {
                        if (isset($new_fields[$i]->inputs[$idx])) {
                            $field_map[(string) $input['id']] = (string) $new_fields[$i]->inputs[$idx]['id'];
                        }
                    }
                }
            }
        }

        foreach ($feeds as $feed) {
            unset($feed['id']);
            $feed['form_id'] = $new_form_id;

            // Decode the meta as an array
            $meta = json_decode($feed['meta'], true);

            // Recursively update ALL fieldId references and known field keys
            $meta = $this->remap_all_field_ids_recursive($meta, $field_map);

            $feed['meta'] = json_encode($meta);

            $wpdb->insert($feeds_table, $feed);
        }
    }

    /**
     * Recursively remaps all field IDs within a given data structure using a provided ID map.
     *
     * This function traverses the input data (which can be an array or a nested array) and replaces
     * any values that match keys in the $id_map with their corresponding mapped values. It also handles
     * string values containing a dot ('.'), remapping the base part if it exists in the map and preserving the suffix.
     *
     * @param mixed $data   The data to process. Can be an array or any value.
     * @param array $id_map An associative array mapping old field IDs (as strings) to new field IDs.
     *
     * @return mixed The data structure with all applicable field IDs remapped.
     */
    protected function remap_all_field_ids_recursive($data, $id_map)
    {
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                // For both key and value, check for remap
                if (
                    (is_string($v) || is_numeric($v)) &&
                    isset($id_map[(string) $v])
                ) {
                    $data[$k] = $id_map[(string) $v];
                } elseif (
                    (is_string($v) || is_numeric($v)) &&
                    strpos($v, '.') !== false
                ) {
                    $parts = explode('.', (string) $v);
                    $base = $parts[0];
                    $suffix = isset($parts[1]) ? '.' . $parts[1] : '';
                    if (isset($id_map[$base])) {
                        $data[$k] = $id_map[$base] . $suffix;
                    } else {
                        $data[$k] = $v;
                    }
                } else {
                    $data[$k] = $this->remap_all_field_ids_recursive($v, $id_map);
                }
            }
            return $data;
        }
        // If not array, just return as-is
        return $data;
    }

    /**
     * Remaps merge tags in the given text using the provided ID map.
     *
     * This function searches for Gravity Forms-style merge tags in the format
     * {field_label:field_id[:modifier]} within the input text and replaces the
     * field_id with a new value from the $id_map if a mapping exists.
     *
     * @param string $text   The text containing merge tags to be remapped.
     * @param array  $id_map An associative array mapping original field IDs to new field IDs.
     *
     * @return string The text with merge tags remapped according to the ID map.
     */
    protected function remap_merge_tags($text, $id_map)
    {
        $result = preg_replace_callback('/\{([^:}]+):([0-9.]+)(:[^}]*)?\}/', function ($matches) use ($id_map) {
            $base = $matches[2];
            $modifier = isset($matches[3]) ? $matches[3] : '';
            if (isset($id_map[$base])) {
                error_log("Replacing merge tag: {$matches[0]} with {{$matches[1]}:{$id_map[$base]}$modifier}");
                return '{' . $matches[1] . ':' . $id_map[$base] . $modifier . '}';
            }
            return $matches[0];
        }, $text);
        error_log("remap_merge_tags INPUT: $text OUTPUT: $result");
        return $result;
    }

    /**
     * Updates conditional logic field IDs within the provided meta array using the given ID map.
     *
     * This method searches for specific keys in the meta array that may contain conditional logic
     * referencing field IDs. If found, it recursively remaps those field IDs according to the provided
     * mapping array.
     *
     * @param array $meta    The meta array potentially containing conditional logic with field IDs.
     * @param array $id_map  An associative array mapping old field IDs to new field IDs.
     *
     * @return array The updated meta array with remapped conditional logic field IDs.
     */
    protected function update_conditional_field_ids($meta, $id_map)
    {
        $fields_to_check = [
            'feed_condition_conditional_logic_object',
            'feed_condition_conditional_logic',
            'conditionalLogic',
        ];
        foreach ($fields_to_check as $logic_key) {
            if (isset($meta[$logic_key])) {
                $meta[$logic_key] = $this->remap_field_ids_recursive($meta[$logic_key], $id_map);
            }
        }
        return $meta;
    }

    /**
     * Recursively remaps field IDs in the given data structure using the provided ID map.
     *
     * This function traverses the input data array and replaces any value associated with the 'fieldId' key
     * with its corresponding value from the $id_map, if available. The function processes nested arrays recursively.
     *
     * @param mixed $data   The data structure (array or value) to process.
     * @param array $id_map An associative array mapping old field IDs (as strings) to new field IDs.
     * @return mixed        The data structure with field IDs remapped according to $id_map.
     */

    protected function remap_field_ids_recursive($data, $id_map)
    {
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                if ($k === 'fieldId' && isset($id_map[(string) $v])) {
                    $data[$k] = $id_map[(string) $v];
                } else {
                    $data[$k] = $this->remap_field_ids_recursive($v, $id_map);
                }
            }
        }
        return $data;
    }

    /**
     * Update field conditional logic on the cloned fields.
     * @param array $fields The array of GF_Field objects (already cloned).
     * @param array $field_map Old ID => new ID map.
     * @return array Modified fields array.
     */
    protected function update_fields_conditional_logic($fields, $field_map)
    {
        foreach ($fields as $field) {
            if (!empty($field->conditionalLogic) && isset($field->conditionalLogic['rules'])) {
                foreach ($field->conditionalLogic['rules'] as &$rule) {
                    if (isset($rule['fieldId']) && isset($field_map[(string) $rule['fieldId']])) {
                        $rule['fieldId'] = $field_map[(string) $rule['fieldId']];
                    }
                }
                unset($rule); // break reference
            }
        }
        return $fields;
    }

    /**
     * Recursively remaps 'fieldId' values in a conditional logic array using a provided field mapping.
     *
     * This function traverses the given conditional logic array, and for each occurrence of a 'fieldId' key,
     * it replaces its value with the corresponding value from the provided field map. The function processes
     * nested arrays recursively to ensure all 'fieldId' keys are remapped throughout the structure.
     *
     * @param array $logic      The conditional logic array to be remapped.
     * @param array $field_map  An associative array mapping old field IDs to new field IDs.
     * @return array            The remapped conditional logic array.
     */
    protected function remap_conditional_logic_recursive($logic, $field_map)
    {
        if (is_array($logic)) {
            foreach ($logic as $k => $v) {
                // Remap 'fieldId' keys
                if ($k === 'fieldId' && isset($field_map[(string) $v])) {
                    $logic[$k] = $field_map[(string) $v];
                } else {
                    $logic[$k] = $this->remap_conditional_logic_recursive($v, $field_map);
                }
            }
        }
        return $logic;
    }

}

class GF_Clone_With_Payments_Plugin
{
    protected $cloner;

    public function __construct()
    {
        $this->cloner = new GF_Clone_With_Payments_Cloner();
        add_filter('gform_form_actions', [$this, 'add_clone_action_link'], 10, 2);
        add_action('admin_init', [$this, 'handle_clone_request']);

        add_action('admin_enqueue_scripts', function () {
            wp_enqueue_script('jquery-ui-dialog');
            wp_enqueue_style('wp-jquery-ui-dialog');
        });

        add_action('admin_footer', function () {
            ?>
            <div id="gfcwp-clone-modal" title="Confirm Clone" style="display:none;">
                <h3 style="margin:0px;">Are you sure you want to clone the form:<br/> <strong id="gfcwp-clone-form-name"></strong></h3>
                <p style="margin-top:0.5rem;">(including payment feeds, notifications, and confirmations)?</p>
                <p><strong>After you have cloned, please do the following:</strong></p>
                <ul>
                    <li>- Change the name of the form</li>
                    <li>- Rename the feed for NMI and Stripe with this format. Example: “Stripe Feed - [Your Event Name]"</li>
                    <li>- Check all Confirmations and Notifications to be appropriate for your event: including contact name and email, Program Name, Event Name, etc. These appear in multiple places</li>
                    <li>- Add program specifics where needed (for instance, STP events need AASECT language)</li>
                </ul>
            </div>
            <style>
                .ui-dialog-buttonpane {
                    box-sizing: border-box !important;
                    width: 100% !important;
                }

                .ui-dialog-titlebar {
                    box-sizing: border-box !important;
                    width: 100% !important;
                }
                /* Make the modal wider */
                .ui-dialog {
                    max-width: 100% !important;
                    width: 500px !important;
                }
                .ui-helper-clearfix:after {
                    content: "";
                    display: table;
                    clear: both;
                }
            </style>
            <script type="text/javascript">
                jQuery(function ($) {
                    // Ensure the modal exists before binding
                    if ($('#gfcwp-clone-modal').length) {
                        $('a.gfcwp-clone-link').on('click', function (e) {
                            e.preventDefault();
                            var href = $(this).attr('href');
                            var formName = $(this).data('form-name');
                            $('#gfcwp-clone-form-name').text(formName);

                            $("#gfcwp-clone-modal").dialog({
                                modal: true,
                                width: 500,
                                buttons: {
                                    "Yes, Clone": function () {
                                        window.location = href;
                                    },
                                    Cancel: function () {
                                        $(this).dialog("close");
                                    }
                                }
                            });
                        });
                    }
                });
            </script>
            <?php
        });
    }
    /**
     * Adds a "Clone with Payments" action link to the form actions.
     *
     * This method generates a secure action link that allows users with the 'manage_options'
     * capability to clone a Gravity Forms form along with its payment feeds. The link includes
     * a nonce for security and is appended to the list of available actions for the specified form.
     *
     * @param array $actions Existing array of action links for the form.
     * @param int $form_id The ID of the Gravity Forms form.
     * @return array Modified array of action links including the "Clone with Payments" link if permitted.
     */
    public function add_clone_action_link($actions, $form_id)
    {
        $form_id = absint($form_id);

        if (!$form_id || !current_user_can('manage_options')) {
            return $actions;
        }

        $nonce = wp_create_nonce("gfcwp_clone_{$form_id}");
        $url = add_query_arg([
            'gfcwp_clone_form' => $form_id,
            '_wpnonce' => $nonce,
        ], admin_url('admin.php'));

        $form = GFAPI::get_form($form_id);
        $form_name = esc_attr($form['title'] ?? 'Untitled');

        // Gravity Forms adds spacers " | " between each action by joining the array with " | "
        // Example: implode( ' | ', $actions )
        // So each element in $actions is a separate link, and the " | " is added when rendering.

        $actions['clone_with_payments'] = sprintf(
            '<a href="%s" class="gfcwp-clone-link" aria-label="%s" data-form-name="%s" data-form="%d">%s</a>',
            esc_url($url),
            esc_attr__('Clone this form with payment feeds', 'gf-clone-with-payments'),
            $form_name,
            $form_id,
            esc_html__('Clone with Payments', 'gf-clone-with-payments')
        );

        return $actions;
    }

    /**
     * Handles the request to clone a Gravity Form.
     *
     * This method checks for the required GET parameters, verifies user permissions,
     * and validates the nonce for security. If all checks pass, it attempts to clone
     * the specified form using the cloner object. On success, it redirects the user
     * to the edit page of the newly cloned form. If an error occurs during cloning,
     * it displays an error message.
     *
     * Security:
     * - Requires 'gfcwp_clone_form' and '_wpnonce' GET parameters.
     * - User must have 'manage_options' capability.
     * - Nonce must be valid for the form being cloned.
     *
     * Redirects:
     * - On success, redirects to the edit page of the new form with a 'cloned' flag.
     * - On failure, displays an error message and halts execution.
     */
    public function handle_clone_request()
    {
        if (
            !isset($_GET['gfcwp_clone_form']) ||
            !current_user_can('manage_options') ||
            !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'gfcwp_clone_' . $_GET['gfcwp_clone_form'])
        ) {
            return;
        }

        $source_form_id = absint($_GET['gfcwp_clone_form']);
        $new_form_id = $this->cloner->clone_form($source_form_id);

        if (is_wp_error($new_form_id)) {
            wp_die('Error duplicating form: ' . $new_form_id->get_error_message());
        }

        wp_redirect(admin_url('admin.php?page=gf_edit_forms&id=' . $new_form_id . '&cloned=1'));
        exit;
    }
}

new GF_Clone_With_Payments_Plugin();
