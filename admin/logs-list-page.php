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
        <p>
            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline;">
                <?php wp_nonce_field( 'masterspa_clear_logs', 'masterspa_clear_logs_nonce' ); ?>
                <input type="hidden" name="action" value="masterspa_clear_logs">
                <button class="button button-secondary" onclick="return confirm('Ești sigur că vrei să ștergi toate log-urile?');">Clear All Logs</button>
            </form>
        </p>
        <table class="widefat fixed" cellspacing="0">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Time</th>
                    <th>Type</th>
                    <th>SKU</th>
                    <th>Product ID</th>
                    <th>Message</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($logs): foreach ($logs as $log): ?>
                <tr>
                    <td><?php echo esc_html($log->id); ?></td>
                    <td><?php echo esc_html($log->created_at ?? $log->import_date); ?></td>
                    <td><?php echo esc_html($log->log_type ?? ''); ?></td>
                    <td><?php echo esc_html($log->sku ?? '-'); ?></td>
                    <td><?php echo esc_html($log->product_id ?? '-'); ?></td>
                    <td><pre style="white-space:pre-wrap;"><?php echo esc_html($log->message); ?></pre></td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="6">No logs found.</td></tr>
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
