<?php
// Admin page to list MasterSpa logs with pagination
add_action('admin_menu', function() {
    add_menu_page('MasterSpa Logs', 'Spa Logs', 'manage_options', 'masterspa-logs', 'masterspa_render_logs_page', 'dashicons-list-view', 81);
});

function masterspa_render_logs_page() {
    require_once dirname(__FILE__,2) . '/includes/MasterSpaLogHelper.php';
    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $per_page = 20;
    $result = MasterSpaLogHelper::get_logs($paged, $per_page);
    
    $logs = $result['logs'];
    $total = $result['total'];
    $last_page = $result['last_page'];
    $current_page = $result['current_page'];
    ?>
     <div class="wrap">
        <h1>MasterSpa Logs</h1>
        <table class="widefat fixed" cellspacing="0">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Time</th>
                    <th>Level</th>
                    <th>Message</th>
                    <th>Context</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($logs): foreach ($logs as $log): ?>
                <tr>
                    <td><?php echo esc_html($log->id); ?></td>
                    <td><?php echo esc_html($log->log_time); ?></td>
                    <td><?php echo isset($log->log_level) ? esc_html($log->log_level) : ''; ?></td>
                    <td><?php echo isset($log->log_message) ? esc_html($log->log_message) : esc_html($log->message); ?></td>
                    <td><pre><?php echo isset($log->log_context) ? esc_html($log->log_context) : ''; ?></pre></td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="5">No logs found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <div style="margin-top:20px;">
            <?php
            $base_url = remove_query_arg('paged');
            for ($i = 1; $i <= $last_page; $i++) {
                if ($i == $current_page) {
                    echo '<strong>' . $i . '</strong> ';
                } else {
                    echo '<a href="' . esc_url(add_query_arg('paged', $i, $base_url)) . '">' . $i . '</a> ';
                }
            }
            ?>
        </div>
    </div>
    <?php
}
