<?php
/**
 * Plugin Name: Gravity Forms Advanced Form Duplcation
 * Description: Adds a button to duplicate a Gravity Form along with its notifications and payment feeds.
 * Version: 1.0
 * Author: Trevor Bice
 * License: GPL2+
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


    protected function copy_notifications($source_form_id, $new_form_id, $field_map)
    {
        error_log('FIELD MAP: ' . print_r($field_map, true));
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
            error_log('NEW SUBJECT: ' . $notification['subject']);
            error_log('NEW MESSAGE: ' . $notification['message']);

            $notification['form_id'] = $new_form_id;
            error_log('CLONED NOTIFICATION: ' . print_r($notification, true));
            GFNotifications::update_notification($new_form_id, $notification);
        }
    }

    protected function copy_entries_grid_meta($source_form_id, $new_form_id)
    {
        global $wpdb;
        $meta_table = $wpdb->prefix . 'gf_form_meta';

        $old_row = $wpdb->get_row(
            $wpdb->prepare("SELECT entries_grid_meta FROM $meta_table WHERE form_id = %d", $source_form_id),
            ARRAY_A
        );
        error_log("ENTRIES_GRID_META - SOURCE ($source_form_id): " . print_r($old_row, true));

        if (!empty($old_row) && isset($old_row['entries_grid_meta'])) {
            $result = $wpdb->update(
                $meta_table,
                ['entries_grid_meta' => $old_row['entries_grid_meta']],
                ['form_id' => $new_form_id]
            );
            error_log("ENTRIES_GRID_META - CLONED TO ($new_form_id): " . $old_row['entries_grid_meta'] . " (Update result: $result)");
        } else {
            error_log("ENTRIES_GRID_META - NOT FOUND on source form $source_form_id");
        }
    }

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

            // Debug: log meta before remapping
            error_log('ORIGINAL META: ' . print_r($meta, true));

            // Recursively update ALL fieldId references and known field keys
            $meta = $this->remap_all_field_ids_recursive($meta, $field_map);

            // Debug: log meta after remapping
            error_log('CLONED META: ' . print_r($meta, true));

            $feed['meta'] = json_encode($meta);

            $wpdb->insert($feeds_table, $feed);
        }
    }

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
     * Recursively update fieldId references in conditional logic.
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
                <p>Are you sure you want to clone the form:</p>
                <h4><strong id="gfcwp-clone-form-name"></strong></h4>
                <p>(including payment feeds, notifications, and confirmations)?
                </p>
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
            </style>
            <script>
                jQuery(document).ready(function ($) {
                    $('a.gfcwp-clone-link').on('click', function (e) {
                        e.preventDefault();
                        var href = $(this).attr('href');
                        var formName = $(this).data('form-name');
                        $('#gfcwp-clone-form-name').text(formName);

                        $("#gfcwp-clone-modal").dialog({
                            modal: true,
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
                });
            </script>
            <?php
        });
    }
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

        $actions[] = sprintf(
            '<a href="%s" title="%s" class="gfcwp-clone-link" data-form-name="%s">%s</a>',
            esc_url($url),
            esc_attr__('Clone this form with payment feeds', 'gf-clone-with-payments'),
            $form_name,
            esc_html__('Clone with Payments', 'gf-clone-with-payments')
        );

        return $actions;
    }

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
