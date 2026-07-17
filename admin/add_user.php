<?php
include '../includes/auth.php';
include '../config/database.php';
checkRole(1);

$success = "";
$error = "";

if (isset($_POST['add_user'])) {
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $contact_number = trim($_POST['contact_number']);
    $address = trim($_POST['address']);
    $password = trim($_POST['password']);
    $confirm_password = trim($_POST['confirm_password']);
    $cdc_ids = isset($_POST['cdc_ids']) ? $_POST['cdc_ids'] : [];

    if (
        $first_name == "" || $last_name == "" || $email == "" ||
        $contact_number == "" || $address == "" ||
        $password == "" || $confirm_password == ""
    ) {
        $error = "Please fill in all required fields.";
    } elseif ($password != $confirm_password) {
        $error = "Password and confirm password do not match.";
    } elseif (empty($cdc_ids)) {
        $error = "Please select at least one CDC assignment.";
    } else {
        $check_stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
        $check_stmt->bind_param("s", $email);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result && $check_result->num_rows > 0) {
            $error = "Email already exists.";
        } else {
            $role_id = 2;

            $insert_user = $conn->prepare("
                INSERT INTO users (role_id, first_name, last_name, email, contact_number, address, password)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $insert_user->bind_param(
                "issssss",
                $role_id,
                $first_name,
                $last_name,
                $email,
                $contact_number,
                $address,
                $password
            );

            if ($insert_user->execute()) {
                $new_user_id = $conn->insert_id;

                $assign_stmt = $conn->prepare("INSERT INTO cdw_assignments (user_id, cdc_id) VALUES (?, ?)");

                foreach ($cdc_ids as $cdc_id) {
                    $cdc_id = (int)$cdc_id;
                    $assign_stmt->bind_param("ii", $new_user_id, $cdc_id);
                    $assign_stmt->execute();
                }

                $success = "CDW account added successfully.";
                $_POST = [];
            } else {
                $error = "Failed to add CDW account.";
            }
        }
    }
}

$search = isset($_GET['search']) ? trim($_GET['search']) : "";

$total_users_result = $conn->query("SELECT COUNT(*) AS total_users FROM users WHERE role_id IN (2,3)");
$total_users = $total_users_result ? (int)$total_users_result->fetch_assoc()['total_users'] : 0;

$total_cdw_result = $conn->query("SELECT COUNT(*) AS total_cdw FROM users WHERE role_id = 2");
$total_cdw = $total_cdw_result ? (int)$total_cdw_result->fetch_assoc()['total_cdw'] : 0;

$total_guardian_result = $conn->query("SELECT COUNT(*) AS total_guardian FROM users WHERE role_id = 3");
$total_guardian = $total_guardian_result ? (int)$total_guardian_result->fetch_assoc()['total_guardian'] : 0;

$cdc_list = $conn->query("SELECT cdc_id, cdc_name, barangay FROM cdc ORDER BY cdc_name ASC");

if ($search != "") {
    $like = "%" . $search . "%";
    $users_stmt = $conn->prepare("
        SELECT 
            u.user_id,
            u.first_name,
            u.last_name,
            u.email,
            u.contact_number,
            u.address,
            u.role_id,
            u.created_at,
            u.last_active,
            GROUP_CONCAT(DISTINCT c.cdc_name ORDER BY c.cdc_name ASC SEPARATOR ', ') AS assigned_cdc,
            GROUP_CONCAT(
                DISTINCT CONCAT(ch.first_name, ' ', ch.last_name)
                ORDER BY ch.first_name ASC, ch.last_name ASC
                SEPARATOR ', '
            ) AS linked_children
        FROM users u
        LEFT JOIN cdw_assignments ca ON u.user_id = ca.user_id
        LEFT JOIN cdc c ON ca.cdc_id = c.cdc_id
        LEFT JOIN parent_child_links pcl ON u.user_id = pcl.parent_id
        LEFT JOIN children ch ON pcl.child_id = ch.child_id
        WHERE u.role_id IN (2,3)
        AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)
        GROUP BY u.user_id, u.first_name, u.last_name, u.email, u.contact_number, u.address, u.role_id, u.created_at, u.last_active
        ORDER BY u.user_id DESC
    ");
    $users_stmt->bind_param("sss", $like, $like, $like);
    $users_stmt->execute();
    $users_result = $users_stmt->get_result();
} else {
    $users_result = $conn->query("
        SELECT 
            u.user_id,
            u.first_name,
            u.last_name,
            u.email,
            u.contact_number,
            u.address,
            u.role_id,
            u.created_at,
            u.last_active,
            GROUP_CONCAT(DISTINCT c.cdc_name ORDER BY c.cdc_name ASC SEPARATOR ', ') AS assigned_cdc,
            GROUP_CONCAT(
                DISTINCT CONCAT(ch.first_name, ' ', ch.last_name)
                ORDER BY ch.first_name ASC, ch.last_name ASC
                SEPARATOR ', '
            ) AS linked_children
        FROM users u
        LEFT JOIN cdw_assignments ca ON u.user_id = ca.user_id
        LEFT JOIN cdc c ON ca.cdc_id = c.cdc_id
        LEFT JOIN parent_child_links pcl ON u.user_id = pcl.parent_id
        LEFT JOIN children ch ON pcl.child_id = ch.child_id
        WHERE u.role_id IN (2,3)
        GROUP BY u.user_id, u.first_name, u.last_name, u.email, u.contact_number, u.address, u.role_id, u.created_at, u.last_active
        ORDER BY u.user_id DESC
    ");
}

/*
|--------------------------------------------------------------------------
| PART 13: Rich profile-drawer data
| For Guardians: which child(ren), which CDC each child belongs to, and
| which CDW(s) are assigned to that CDC.
| For CDWs: the existing assigned_cdc string is already enough.
| Additive only — does not change the $users_result query above.
|--------------------------------------------------------------------------
*/
$users_list = array();
if ($users_result) {
    while ($row = $users_result->fetch_assoc()) {
        $users_list[] = $row;
    }
}

$cdw_names_by_cdc_cache = array();

function getCdwNamesForCdc($conn, $cdc_id, &$cache) {
    if ($cdc_id === null || $cdc_id === '') {
        return array();
    }
    if (isset($cache[$cdc_id])) {
        return $cache[$cdc_id];
    }

    $names = array();
    $stmt = $conn->prepare("
        SELECT CONCAT(u.first_name, ' ', u.last_name) AS cdw_name
        FROM cdw_assignments ca
        INNER JOIN users u ON u.user_id = ca.user_id
        WHERE ca.cdc_id = ?
        ORDER BY u.first_name ASC, u.last_name ASC
    ");
    $stmt->bind_param("i", $cdc_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($r = $result->fetch_assoc()) {
        $names[] = trim($r['cdw_name']);
    }

    $cache[$cdc_id] = $names;
    return $names;
}

foreach ($users_list as $key => $user) {
    $users_list[$key]['guardian_children'] = array();

    if ((int)$user['role_id'] !== 3) {
        continue;
    }

    $child_stmt = $conn->prepare("
        SELECT ch.child_id, ch.first_name, ch.last_name, c.cdc_id, c.cdc_name
        FROM parent_child_links pcl
        INNER JOIN children ch ON pcl.child_id = ch.child_id
        LEFT JOIN cdc c ON ch.cdc_id = c.cdc_id
        WHERE pcl.parent_id = ?
          AND ch.is_deleted = 0
        ORDER BY ch.first_name ASC, ch.last_name ASC
    ");
    $child_stmt->bind_param("i", $user['user_id']);
    $child_stmt->execute();
    $child_result = $child_stmt->get_result();

    $children_info = array();
    while ($child_row = $child_result->fetch_assoc()) {
        $cdc_id = $child_row['cdc_id'];
        $cdc_name = !empty($child_row['cdc_name']) ? $child_row['cdc_name'] : 'No CDC assigned';
        $cdw_names = ($cdc_id !== null) ? getCdwNamesForCdc($conn, $cdc_id, $cdw_names_by_cdc_cache) : array();

        $children_info[] = array(
            'child_name' => htmlspecialchars(trim($child_row['first_name'] . ' ' . $child_row['last_name']), ENT_QUOTES, 'UTF-8'),
            'cdc_name' => htmlspecialchars($cdc_name, ENT_QUOTES, 'UTF-8'),
            'cdw_names' => htmlspecialchars(!empty($cdw_names) ? implode(', ', $cdw_names) : 'No CDW assigned yet', ENT_QUOTES, 'UTF-8')
        );
    }

    $users_list[$key]['guardian_children'] = $children_info;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management</title>
    <link rel="stylesheet" href="../assets/admin/admin-style.css">
    <link rel="stylesheet" href="../assets/admin/add_user.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        /*
        |----------------------------------------------------------------
        | PART 13: Profile drawer functionality + guardian child list
        | Self-contained so it works even if add_user.css doesn't already
        | define a working show/hide toggle for these elements.
        |----------------------------------------------------------------
        */
        .drawer-overlay {
            display: none;
            position: fixed;
            top: 88px; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.45);
            z-index: 1000;
        }
        .drawer-overlay.show {
            display: block;
        }

        .profile-drawer {
            position: fixed;
            top: 88px;
            right: -420px;
            width: 100%;
            max-width: 400px;
            height: calc(100vh - 88px);
            background: #ffffff;
            box-shadow: -12px 0 30px rgba(0,0,0,0.12);
            z-index: 1001;
            overflow-y: auto;
            padding: 26px 24px;
            transition: right 0.25s ease;
        }
        .profile-drawer.show {
            right: 0;
        }

        .drawer-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 18px;
        }
        .drawer-header h2 {
            font-family: 'Poppins', sans-serif;
            font-size: 16px;
            font-weight: 700;
            color: #1e293b;
        }
        .drawer-close {
            background: none;
            border: none;
            font-size: 20px;
            color: #94a3b8;
            cursor: pointer;
        }
        .drawer-close:hover {
            color: #475569;
        }

        .drawer-profile-head {
            margin-bottom: 18px;
            padding-bottom: 14px;
            border-bottom: 1px solid #eef1f5;
        }
        .drawer-name {
            font-family: 'Poppins', sans-serif;
            font-size: 17px;
            font-weight: 700;
            color: #1e293b;
        }
        .drawer-role {
            display: inline-block;
            margin-top: 6px;
            font-size: 11.5px;
            font-weight: 600;
            color: #1d4ed8;
            background: #dbeafe;
            padding: 3px 10px;
            border-radius: 999px;
            font-family: 'Inter', sans-serif;
        }

        .drawer-section {
            margin-bottom: 16px;
        }
        .drawer-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #94a3b8;
            font-family: 'Inter', sans-serif;
            margin-bottom: 4px;
        }
        .drawer-value {
            font-size: 13.5px;
            color: #334155;
            font-family: 'Inter', sans-serif;
        }

        .drawer-children-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 6px;
        }
        .drawer-child-row {
            background: #f8fafc;
            border: 1px solid #eef1f5;
            border-radius: 12px;
            padding: 10px 12px;
        }
        .drawer-child-name {
            font-size: 13px;
            font-weight: 600;
            color: #1e293b;
            font-family: 'Inter', sans-serif;
        }
        .drawer-child-meta {
            font-size: 12px;
            color: #64748b;
            font-family: 'Inter', sans-serif;
            margin-top: 3px;
        }
        .drawer-child-empty {
            font-size: 12.5px;
            color: #94a3b8;
            font-family: 'Inter', sans-serif;
            font-style: italic;
        }
    </style>
</head>
<body class="<?php echo (isset($_SESSION['theme_mode']) && $_SESSION['theme_mode'] === 'dark') ? 'dark-mode' : ''; ?>">

<?php include '../includes/admin_sidebar.php'; ?>
<?php include '../includes/admin_topbar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="page-header">
        <h1>User Management</h1>
        <p>Manage CDW and guardian accounts using the CSWD admin interface.</p>
    </div>

    <?php if ($success != "") { ?>
        <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
    <?php } ?>

    <?php if ($error != "") { ?>
        <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
    <?php } ?>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-label">All Users</div>
            <div class="summary-value"><?php echo $total_users; ?></div>
        </div>
        <div class="summary-card">
            <div class="summary-label">CDW Accounts</div>
            <div class="summary-value"><?php echo $total_cdw; ?></div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Guardian Accounts</div>
            <div class="summary-value"><?php echo $total_guardian; ?></div>
        </div>
    </div>

    <div class="toolbar-card">
        <form method="GET" class="search-form">
            <input
                type="text"
                name="search"
                class="search-input"
                placeholder="Search name or email"
                value="<?php echo htmlspecialchars($search); ?>"
            >
            <button type="submit" class="btn btn-secondary">Search</button>
            <a href="add_user.php" class="btn btn-light">Reset</a>
        </form>

        <button type="button" class="btn btn-primary" onclick="openAddUserForm()">Add User</button>
    </div>

<div class="form-card" id="addUserForm">
            <div class="card-header">
            <h2>Add CDW Account</h2>
            <p>Enter the account details and assign at least one CDC.</p>
        </div>

        <form method="POST">
            <div class="form-grid">
                <div class="form-group">
                    <label for="first_name">First Name</label>
                    <input
                        type="text"
                        id="first_name"
                        name="first_name"
                        required
                        value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="last_name">Last Name</label>
                    <input
                        type="text"
                        id="last_name"
                        name="last_name"
                        required
                        value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>"
                    >
                </div>

                <div class="form-group full">
                    <label for="email">Email Address</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        required
                        value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="contact_number">Contact Number</label>
                    <input
                        type="text"
                        id="contact_number"
                        name="contact_number"
                        required
                        value="<?php echo isset($_POST['contact_number']) ? htmlspecialchars($_POST['contact_number']) : ''; ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="address">Address</label>
                    <input
                        type="text"
                        id="address"
                        name="address"
                        required
                        value="<?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>

                <div class="form-group full">
                    <label>Select CDC Assignment</label>
                    <div class="checkbox-group">
                        <?php
                        if ($cdc_list && $cdc_list->num_rows > 0) {
                            while ($cdc = $cdc_list->fetch_assoc()) {
                                $checked = "";
                                if (isset($_POST['cdc_ids']) && in_array($cdc['cdc_id'], $_POST['cdc_ids'])) {
                                    $checked = "checked";
                                }

                                echo "<div class='checkbox-item'>" .
                                    "<input type='checkbox' name='cdc_ids[]' id='cdc_" . $cdc['cdc_id'] . "' value='" . $cdc['cdc_id'] . "' $checked>" .
                                    "<label for='cdc_" . $cdc['cdc_id'] . "'>" . htmlspecialchars($cdc['cdc_name'] . " - " . $cdc['barangay']) . "</label>" .
                                    "</div>";
                            }
                        }
                        ?>
                    </div>
                    <div class="form-note">Select at least one CDC assignment.</div>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" name="add_user" class="btn btn-primary">Save CDW Account</button>
                <button type="button" class="btn btn-light" onclick="closeAddUserForm()">Cancel</button>
            </div>
        </form>
    </div>

    <div class="table-card">
        <div class="card-header">
            <h2>User List</h2>
            <p>View all CDW and guardian accounts.</p>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th style="width: 45%;">User Name</th>
                        <th style="width: 25%;">Last Active</th>
                        <th style="width: 20%;">Date Added</th>
                        <th style="width: 10%; text-align:center;">View</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($users_list)) { ?>
                        <?php foreach ($users_list as $user) { ?>
                            <?php
                                $full_name = trim($user['first_name'] . ' ' . $user['last_name']);
                                $role_name = ($user['role_id'] == 2) ? 'CDW' : (($user['role_id'] == 3) ? 'Guardian' : 'Unknown');
                                $assigned_cdc = !empty($user['assigned_cdc']) ? $user['assigned_cdc'] : 'No assigned CDC';
                                $drawer_label = ($user['role_id'] == 2) ? 'Assigned CDC' : 'Linked Child & CDC';
                                $drawer_value = ($user['role_id'] == 2) ? $assigned_cdc : '';
                                $guardian_children_json = htmlspecialchars(
                                    json_encode($user['guardian_children'], JSON_UNESCAPED_UNICODE),
                                    ENT_QUOTES
                                );
                            ?>
                            <tr>
                                <td>
                                    <div class="name-cell"><?php echo htmlspecialchars($full_name); ?></div>
                                    <div class="email-text"><?php echo htmlspecialchars($user['email']); ?></div>
                                </td>
                                <td>
                                    <?php
                                    if (!empty($user['last_active']) && $user['last_active'] != '0000-00-00 00:00:00') {
                                        echo date("F d, Y g:i A", strtotime($user['last_active']));
                                    } else {
                                        echo "—";
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    if (!empty($user['created_at']) && $user['created_at'] != '0000-00-00 00:00:00') {
                                        echo date("F d, Y", strtotime($user['created_at']));
                                    } else {
                                        echo "—";
                                    }
                                    ?>
                                </td>
                                <td class="view-cell">
                                    <button
                                        type="button"
                                        class="view-profile-btn"
                                        data-name="<?php echo htmlspecialchars($full_name, ENT_QUOTES); ?>"
                                        data-role="<?php echo htmlspecialchars($role_name, ENT_QUOTES); ?>"
                                        data-email="<?php echo htmlspecialchars($user['email'], ENT_QUOTES); ?>"
                                        data-contact="<?php echo htmlspecialchars(!empty($user['contact_number']) ? $user['contact_number'] : '—', ENT_QUOTES); ?>"
                                        data-address="<?php echo htmlspecialchars(!empty($user['address']) ? $user['address'] : '—', ENT_QUOTES); ?>"
                                        data-link-label="<?php echo htmlspecialchars($drawer_label, ENT_QUOTES); ?>"
                                        data-link-value="<?php echo htmlspecialchars($drawer_value, ENT_QUOTES); ?>"
                                        data-guardian-children="<?php echo $guardian_children_json; ?>"
                                        onclick="openProfileDrawer(this)"
                                        aria-label="View Profile"
                                    >
                                        <?php if ($user['role_id'] == 2) { ?>
                                            <svg viewBox="0 0 24 24" class="action-icon" aria-hidden="true">
                                                <path d="M12 12c2.761 0 5-2.239 5-5s-2.239-5-5-5-5 2.239-5 5 2.239 5 5 5zm0 2c-3.866 0-7 3.134-7 7h14c0-3.866-3.134-7-7-7z"></path>
                                            </svg>
                                        <?php } else { ?>
                                            <svg viewBox="0 0 24 24" class="action-icon" aria-hidden="true">
                                                <path d="M9 11c2.209 0 4-1.791 4-4S11.209 3 9 3 5 4.791 5 7s1.791 4 4 4zm6 1c1.657 0 3-1.343 3-3s-1.343-3-3-3c-.295 0-.579.043-.848.123A5.978 5.978 0 0 1 15 7c0 1.641-.66 3.128-1.728 4.214.236-.139.511-.214.728-.214zm-6 1c-3.314 0-6 2.686-6 6h12c0-3.314-2.686-6-6-6zm6 1c-.341 0-.676.029-1 .084C15.729 15.11 17 16.943 17 19h5c0-2.761-2.239-5-5-5z"></path>
                                            </svg>
                                        <?php } ?>
                                    </button>
                                </td>
                            </tr>
                        <?php } ?>
                    <?php } else { ?>
                        <tr>
                            <td colspan="4" class="empty-state">No users found.</td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="drawer-overlay" id="drawerOverlay" onclick="closeProfileDrawer()"></div>

<div class="profile-drawer" id="profileDrawer" aria-hidden="true">
    <div class="drawer-header">
        <h2>Profile Information</h2>
        <button type="button" class="drawer-close" onclick="closeProfileDrawer()" aria-label="Close">
            &times;
        </button>
    </div>

    <div class="drawer-profile-head">
        <div class="drawer-name" id="drawerName">—</div>
        <div class="drawer-role" id="drawerRole">—</div>
    </div>

    <div class="drawer-section">
        <div class="drawer-label">Email Address</div>
        <div class="drawer-value" id="drawerEmail">—</div>
    </div>

    <div class="drawer-section">
        <div class="drawer-label">Contact Number</div>
        <div class="drawer-value" id="drawerContact">—</div>
    </div>

    <div class="drawer-section">
        <div class="drawer-label">Address</div>
        <div class="drawer-value" id="drawerAddress">—</div>
    </div>

    <div class="drawer-section">
        <div class="drawer-label" id="drawerLinkLabel">Assigned CDC</div>
        <div class="drawer-value" id="drawerLinkValue">—</div>
        <div class="drawer-children-list" id="drawerChildrenList"></div>
    </div>
</div>


<script src="../assets/admin/admin/sidebar.js"></script>

<script>
    function openAddUserForm() {
        document.getElementById('addUserForm').classList.add('show');
    }

    function closeAddUserForm() {
        document.getElementById('addUserForm').classList.remove('show');
    }

    /*
    |----------------------------------------------------------------
    | PART 13: Profile drawer — was referenced by the View button but
    | never defined. Added here so clicking "View" actually shows the
    | user's role, contact info, and (for guardians) each linked
    | child's CDC + the CDW(s) assigned to that CDC.
    |----------------------------------------------------------------
    */
    function openProfileDrawer(btn) {
        const name = btn.getAttribute('data-name') || '—';
        const role = btn.getAttribute('data-role') || '—';
        const email = btn.getAttribute('data-email') || '—';
        const contact = btn.getAttribute('data-contact') || '—';
        const address = btn.getAttribute('data-address') || '—';
        const linkLabel = btn.getAttribute('data-link-label') || '';
        const linkValue = btn.getAttribute('data-link-value') || '';
        let guardianChildren = [];

        try {
            guardianChildren = JSON.parse(btn.getAttribute('data-guardian-children') || '[]');
        } catch (e) {
            guardianChildren = [];
        }

        document.getElementById('drawerName').textContent = name;
        document.getElementById('drawerRole').textContent = role;
        document.getElementById('drawerEmail').textContent = email;
        document.getElementById('drawerContact').textContent = contact;
        document.getElementById('drawerAddress').textContent = address;
        document.getElementById('drawerLinkLabel').textContent = linkLabel;

        const linkValueEl = document.getElementById('drawerLinkValue');
        const childrenListEl = document.getElementById('drawerChildrenList');

        if (role === 'CDW') {
            // Simple: which CDC(s) this CDW is linked to.
            linkValueEl.textContent = linkValue || 'No assigned CDC';
            linkValueEl.style.display = '';
            childrenListEl.innerHTML = '';
        } else {
            // Guardian: show each linked child, that child's CDC, and the CDW(s) for that CDC.
            linkValueEl.style.display = 'none';

            if (!guardianChildren || guardianChildren.length === 0) {
                childrenListEl.innerHTML = '<div class="drawer-child-empty">No linked child.</div>';
            } else {
                let html = '';
                guardianChildren.forEach(function (child) {
                    html += '<div class="drawer-child-row">' +
                                '<div class="drawer-child-name">' + child.child_name + '</div>' +
                                '<div class="drawer-child-meta">CDC: ' + child.cdc_name + '</div>' +
                                '<div class="drawer-child-meta">CDW: ' + child.cdw_names + '</div>' +
                            '</div>';
                });
                childrenListEl.innerHTML = html;
            }
        }

        document.getElementById('drawerOverlay').classList.add('show');
        document.getElementById('profileDrawer').classList.add('show');
        document.getElementById('profileDrawer').setAttribute('aria-hidden', 'false');
    }

    function closeProfileDrawer() {
        document.getElementById('drawerOverlay').classList.remove('show');
        document.getElementById('profileDrawer').classList.remove('show');
        document.getElementById('profileDrawer').setAttribute('aria-hidden', 'true');
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeProfileDrawer();
        }
    });
</script>

<script src="../assets/admin/sidebar.js"></script>
</body>
</html>