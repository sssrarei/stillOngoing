<?php $current_page = basename($_SERVER['PHP_SELF']); ?>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h2>MENU</h2>
    </div>

    <div class="sidebar-section">

        <a href="child_records.php"
           class="sidebar-link <?php echo in_array($current_page, ['child_records.php','view_child.php','edit_child.php','add_child.php']) ? 'active' : ''; ?>">
           Child Profiles
        </a>

        <div class="sidebar-title">Monitoring</div>

        <a href="monitoring_reports.php"
           class="sidebar-link <?php echo in_array($current_page, ['monitoring_reports.php','wmr_report.php','individual_child_report.php','feeding_attendance_report.php','nutritional_status_summary.php','terminal_report.php']) ? 'active' : ''; ?>">
           Monitoring Reports
        </a>

        <a href="intervention_guidance.php"
           class="sidebar-link <?php echo in_array($current_page, ['intervention_guidance.php']) ? 'active' : ''; ?>">
           Intervention Guidance
        </a>

        <a href="view_referral_monitoring.php"
           class="sidebar-link <?php echo in_array($current_page, ['view_referral_monitoring.php']) ? 'active' : ''; ?>">
           Referral Monitoring
        </a>

        <div class="sidebar-title">Management</div>

        <a href="add_cdc.php"
           class="sidebar-sublink <?php echo in_array($current_page, ['add_cdc.php','edit_cdc.php']) ? 'active' : ''; ?>">
           CDC Management
        </a>

        <a href="add_user.php"
           class="sidebar-sublink <?php echo in_array($current_page, ['add_user.php','edit_user.php']) ? 'active' : ''; ?>">
           User Management
        </a>

         <a href="manage_events.php"
         class="sidebar-sublink <?php echo in_array($current_page, ['manage_events.php','create_event.php','edit_event.php']) ? 'active' : ''; ?>">
         Event Planner
         </a>

        <div class="sidebar-title">Notifications</div>

        <a href="create_reminder.php"
           class="sidebar-sublink <?php echo in_array($current_page, ['create_reminder.php']) ? 'active' : ''; ?>">
           Create Reminder
        </a>

        <div class="sidebar-title">Settings</div>

        <a href="settings.php"
           class="sidebar-sublink <?php echo in_array($current_page, ['settings.php']) ? 'active' : ''; ?>">
           Profile Information
        </a>

        <a href="change_password.php"
           class="sidebar-sublink <?php echo in_array($current_page, ['change_password.php']) ? 'active' : ''; ?>">
           Change Password
        </a>

    </div>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<?php
function active($pages) {
    $current = basename($_SERVER['PHP_SELF']);
    return in_array($current, $pages) ? 'active' : '';
}
?>