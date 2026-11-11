<?php
if (!defined('ABSPATH'))
    exit;

class BBE_Admin_Schedule
{

    public static function init()
    {
        add_action('admin_menu', [__CLASS__, 'add_admin_menu']);
        add_action('admin_init', [__CLASS__, 'handle_form_submissions']);
    }

    public static function add_admin_menu()
    {
        add_submenu_page(
            'woocommerce',
            __('Working Hours & Schedules', 'bbe'),
            __('Booking Schedules', 'bbe'),
            'manage_woocommerce',
            'bbe-schedules',
            [__CLASS__, 'render_admin_page']
        );
    }

    public static function handle_form_submissions()
    {
        if (!isset($_POST['bbe_schedule_nonce']) || !wp_verify_nonce($_POST['bbe_schedule_nonce'], 'bbe_save_schedule')) {
            return;
        }

        global $wpdb;
        $table_schedules = $wpdb->prefix . 'bbe_schedules';

        // 1. Save Weekly Shifts
        if (isset($_POST['action']) && $_POST['action'] === 'save_weekly') {
            $location_id = intval($_POST['location_id']);

            $wpdb->delete($table_schedules, [
                'location_id' => $location_id,
                'specific_date' => NULL
            ]);

            if (!empty($_POST['shifts']) && is_array($_POST['shifts'])) {
                foreach ($_POST['shifts'] as $day => $shift_blocks) {
                    foreach ($shift_blocks as $block) {
                        $start = sanitize_text_field($block['start'] ?? '');
                        $end = sanitize_text_field($block['end'] ?? '');

                        if (!empty($start) && !empty($end)) {
                            $wpdb->insert($table_schedules, [
                                'location_id' => $location_id,
                                'day_of_week' => $day,
                                'specific_date' => NULL,
                                'end_date' => NULL,
                                'start_time' => $start,
                                'end_time' => $end,
                                'override_type' => 'shift'
                            ]);
                        }
                    }
                }
            }

            wp_redirect(admin_url('admin.php?page=bbe-schedules&location_id=' . $location_id . '&updated=1'));
            exit;
        }

        // 2. Add Override / Range Closure / Extra Shift
        if (isset($_POST['action']) && $_POST['action'] === 'add_override') {
            $location_id = intval($_POST['location_id']);
            $override_type = sanitize_text_field($_POST['override_type']); // 'block', 'extra_shift', 'exclude'
            $start_date = sanitize_text_field($_POST['start_date']);
            $end_date = !empty($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : $start_date;
            $start_time = sanitize_text_field($_POST['start_time'] ?? '00:00:00');
            $end_time = sanitize_text_field($_POST['end_time'] ?? '00:00:00');

            if (!empty($start_date)) {
                $wpdb->insert($table_schedules, [
                    'location_id' => $location_id,
                    'day_of_week' => NULL,
                    'specific_date' => $start_date,
                    'end_date' => $end_date,
                    'start_time' => ($override_type === 'block') ? '00:00:00' : ($start_time . ':00'),
                    'end_time' => ($override_type === 'block') ? '00:00:00' : ($end_time . ':00'),
                    'override_type' => $override_type
                ]);
            }

            wp_redirect(admin_url('admin.php?page=bbe-schedules&location_id=' . $location_id . '&updated=1'));
            exit;
        }

        // 3. Delete Override Row
        if (isset($_POST['action']) && $_POST['action'] === 'delete_row') {
            $row_id = intval($_POST['row_id']);
            $location_id = intval($_POST['location_id']);
            $wpdb->delete($table_schedules, ['id' => $row_id]);

            wp_redirect(admin_url('admin.php?page=bbe-schedules&location_id=' . $location_id . '&updated=1'));
            exit;
        }
    }

    public static function render_admin_page()
    {
        global $wpdb;
        $table_schedules = $wpdb->prefix . 'bbe_schedules';

        $locations = get_posts([
            'post_type' => 'bbe_location',
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        if (empty($locations)) {
            echo '<div class="wrap"><h1>' . __('Booking Schedules', 'bbe') . '</h1><p>' . __('Please create at least one Location first.', 'bbe') . '</p></div>';
            return;
        }

        $selected_location = isset($_GET['location_id']) ? intval($_GET['location_id']) : $locations[0]->ID;

        $weekly_raw = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_schedules WHERE location_id = %d AND specific_date IS NULL ORDER BY start_time ASC",
            $selected_location
        ));
        $weekly = [];
        foreach ($weekly_raw as $row) {
            $weekly[$row->day_of_week][] = $row;
        }

        $overrides = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_schedules WHERE location_id = %d AND specific_date IS NOT NULL ORDER BY specific_date ASC",
            $selected_location
        ));

        $days = [
            'mon' => __('Monday', 'bbe'),
            'tue' => __('Tuesday', 'bbe'),
            'wed' => __('Wednesday', 'bbe'),
            'thu' => __('Thursday', 'bbe'),
            'fri' => __('Friday', 'bbe'),
            'sat' => __('Saturday', 'bbe'),
            'sun' => __('Sunday', 'bbe')
        ];
        ?>
        <div class="wrap">
            <h1><?php _e('Working Hours & Advanced Overrides', 'bbe'); ?></h1>

            <?php if (isset($_GET['updated'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e('Schedule saved successfully.', 'bbe'); ?></p>
                </div>
            <?php endif; ?>

            <form method="get" style="margin-bottom: 20px;">
                <input type="hidden" name="page" value="bbe-schedules">
                <label><strong><?php _e('Select Location:', 'bbe'); ?></strong></label>
                <select name="location_id" onchange="this.form.submit()">
                    <?php foreach ($locations as $loc): ?>
                        <option value="<?php echo $loc->ID; ?>" <?php selected($selected_location, $loc->ID); ?>>
                            <?php echo esc_html($loc->post_title); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <hr>

            <!-- Weekly Shifts -->
            <h2><?php _e('Default Weekly Shifts', 'bbe'); ?></h2>
            <form method="post">
                <?php wp_nonce_field('bbe_save_schedule', 'bbe_schedule_nonce'); ?>
                <input type="hidden" name="action" value="save_weekly">
                <input type="hidden" name="location_id" value="<?php echo $selected_location; ?>">

                <div id="bbe-weekly-schedule-container">
                    <?php foreach ($days as $key => $label): ?>
                        <div class="bbe-day-row"
                            style="background:#fff; border:1px solid #ccd0d4; padding:10px; margin-bottom:10px; max-width:700px;">
                            <strong><?php echo $label; ?></strong>
                            <div class="bbe-shifts-list" id="shifts_<?php echo $key; ?>">
                                <?php
                                if (!empty($weekly[$key])):
                                    foreach ($weekly[$key] as $idx => $shift):
                                        ?>
                                        <div class="bbe-shift-item" style="margin: 5px 0;">
                                            <input type="time" name="shifts[<?php echo $key; ?>][<?php echo $idx; ?>][start]"
                                                value="<?php echo esc_attr($shift->start_time); ?>"> to
                                            <input type="time" name="shifts[<?php echo $key; ?>][<?php echo $idx; ?>][end]"
                                                value="<?php echo esc_attr($shift->end_time); ?>">
                                            <button type="button" class="button bbe-remove-shift" style="color:#a00;">Remove</button>
                                        </div>
                                        <?php
                                    endforeach;
                                endif;
                                ?>
                            </div>
                            <button type="button" class="button bbe-add-shift" data-day="<?php echo $key; ?>">+ Add Shift
                                Block</button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p><input type="submit" class="button button-primary" value="<?php _e('Save Weekly Shifts', 'bbe'); ?>"></p>
            </form>

            <hr style="margin: 30px 0;">

            <!-- Advanced Date Overrides & Ranges Form -->
            <h2><?php _e('Date Ranges, Closures & Extra One-Off Shifts', 'bbe'); ?></h2>
            <p><?php _e('Handle multi-week holidays, extra working shifts, or single-day partial breaks.', 'bbe'); ?></p>

            <form method="post"
                style="background: #fff; padding: 15px; border: 1px solid #ccd0d4; max-width: 600px; margin-bottom: 20px;">
                <?php wp_nonce_field('bbe_save_schedule', 'bbe_schedule_nonce'); ?>
                <input type="hidden" name="action" value="add_override">
                <input type="hidden" name="location_id" value="<?php echo $selected_location; ?>">

                <p>
                    <label><strong><?php _e('Rule Type:', 'bbe'); ?></strong></label><br>
                    <select name="override_type" id="bbe_override_type" style="width: 100%;">
                        <option value="block"><?php _e('Full Closure / Holiday Range (Block dates entirely)', 'bbe'); ?>
                        </option>
                        <option value="extra_shift">
                            <?php _e('Add Extra One-Off Shift (e.g., extra shift on Thursday)', 'bbe'); ?>
                        </option>
                        <option value="exclude">
                            <?php _e('Exclude Time Window on Date(s) (e.g. parent-teacher meeting)', 'bbe'); ?>
                        </option>
                    </select>
                </p>

                <p>
                    <label><strong><?php _e('Start Date:', 'bbe'); ?></strong></label><br>
                    <input type="date" name="start_date" required style="width: 100%;">
                </p>

                <p id="bbe_end_date_wrap">
                    <label><strong><?php _e('End Date (Leave blank if single day):', 'bbe'); ?></strong></label><br>
                    <input type="date" name="end_date" style="width: 100%;">
                    <span
                        class="description"><?php _e('Set an end date to block multiple weeks of holiday in one go.', 'bbe'); ?></span>
                </p>

                <div id="bbe_time_inputs"
                    style="display:none; background:#f9f9f9; padding:10px; border:1px solid #eee; margin-top:10px;">
                    <p>
                        <label><?php _e('Shift / Exclusion Start Time:', 'bbe'); ?></label><br>
                        <input type="time" name="start_time" value="09:00">
                    </p>
                    <p>
                        <label><?php _e('Shift / Exclusion End Time:', 'bbe'); ?></label><br>
                        <input type="time" name="end_time" value="13:00">
                    </p>
                </div>

                <p style="margin-top: 15px;"><input type="submit" class="button button-secondary"
                        value="<?php _e('Save Override Rule', 'bbe'); ?>"></p>
            </form>

            <!-- Table of Active Overrides -->
            <h3><?php _e('Active Overrides & Custom Rules', 'bbe'); ?></h3>
            <table class="wp-list-table widefat fixed striped" style="max-width: 800px;">
                <thead>
                    <tr>
                        <th><?php _e('Date Range', 'bbe'); ?></th>
                        <th><?php _e('Type / Details', 'bbe'); ?></th>
                        <th><?php _e('Action', 'bbe'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($overrides)): ?>
                        <tr>
                            <td colspan="3"><?php _e('No custom rules set.', 'bbe'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($overrides as $ov):
                            $date_display = date('M j, Y', strtotime($ov->specific_date));
                            if (!empty($ov->end_date) && $ov->end_date !== $ov->specific_date && $ov->end_date !== '0000-00-00') {
                                $date_display .= ' &rarr; ' . date('M j, Y', strtotime($ov->end_date));
                            }
                            ?>
                            <tr>
                                <td><strong><?php echo $date_display; ?></strong></td>
                                <td>
                                    <?php if ($ov->override_type === 'block'): ?>
                                        <span style="color: red; font-weight: bold;"><?php _e('FULL CLOSURE (Blocked)', 'bbe'); ?></span>
                                    <?php elseif ($ov->override_type === 'extra_shift'): ?>
                                        <span
                                            style="color: green; font-weight: bold;"><?php printf(__('Extra Shift Added: %s - %s', 'bbe'), date('H:i', strtotime($ov->start_time)), date('H:i', strtotime($ov->end_time))); ?></span>
                                    <?php else: ?>
                                        <span
                                            style="color: orange; font-weight: bold;"><?php printf(__('Time Excluded: %s - %s', 'bbe'), date('H:i', strtotime($ov->start_time)), date('H:i', strtotime($ov->end_time))); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="post" style="display:inline;">
                                        <?php wp_nonce_field('bbe_save_schedule', 'bbe_schedule_nonce'); ?>
                                        <input type="hidden" name="action" value="delete_row">
                                        <input type="hidden" name="row_id" value="<?php echo $ov->id; ?>">
                                        <input type="hidden" name="location_id" value="<?php echo $selected_location; ?>">
                                        <input type="submit" class="button button-link-delete" value="<?php _e('Remove', 'bbe'); ?>"
                                            onclick="return confirm('Remove rule?');">
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <script>
            jQuery(document).ready(function ($) {
                $('#bbe_override_type').on('change', function () {
                    const val = $(this).val();
                    if (val === 'block') {
                        $('#bbe_time_inputs').hide();
                        $('#bbe_end_date_wrap').show();
                    } else if (val === 'extra_shift' || val === 'exclude') {
                        $('#bbe_time_inputs').show();
                        // Extra shifts are usually single-day actions
                        if (val === 'extra_shift') {
                            $('#bbe_end_date_wrap').hide();
                        } else {
                            $('#bbe_end_date_wrap').show();
                        }
                    }
                });

                let counter = 100;
                $('.bbe-add-shift').on('click', function () {
                    const day = $(this).data('day');
                    const container = $('#shifts_' + day);
                    const html = `<div class="bbe-shift-item" style="margin: 5px 0;">
                        <input type="time" name="shifts[${day}][${counter}][start]" value="13:00"> to 
                        <input type="time" name="shifts[${day}][${counter}][end]" value="17:00">
                        <button type="button" class="button bbe-remove-shift" style="color:#a00;">Remove</button>
                    </div>`;
                    container.append(html);
                    counter++;
                });

                $(document).on('click', '.bbe-remove-shift', function () {
                    $(this).closest('.bbe-shift-item').remove();
                });
            });
        </script>
        <?php
    }
}
BBE_Admin_Schedule::init();