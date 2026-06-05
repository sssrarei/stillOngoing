<?php
if (!isset($current_page)) {
    $current_page = '';
}
?>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <h2>GUARDIAN</h2>
    </div>

    <div class="sidebar-section">

        <a href="health_info.php" class="sidebar-link <?php echo ($current_page === 'health_info') ? 'active' : ''; ?>">
            Child Profile/Health Information
        </a>

        <a href="interventions_reminders.php" class="sidebar-link <?php echo ($current_page === 'interventions_reminders') ? 'active' : ''; ?>">
    Interventions/Reminders
        </a>

        <div class="sidebar-title">Settings</div>

        <a href="profile_information.php" class="sidebar-sublink <?php echo ($current_page === 'profile_information') ? 'active' : ''; ?>">
            Profile Information
        </a>

        <a href="change_password.php" class="sidebar-sublink <?php echo ($current_page === 'change_password') ? 'active' : ''; ?>">
            Change Password
        </a>
    </div>
</aside>