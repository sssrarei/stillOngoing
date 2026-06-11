<?php
include '../includes/auth.php';
include '../config/database.php';
checkRole(1);

$success = "";
$error = "";

$barangay_list = [
    "Alima",
    "Aniban I",
    "Aniban II",
    "Aniban III",
    "Aniban IV",
    "Aniban V",
    "Banalo",
    "Bayanan",
    "Campo Santo",
    "Daang Bukid",
    "Digman",
    "Dulong Bayan",
    "Habay I",
    "Habay II",
    "Kaingin",
    "Ligas I",
    "Ligas II",
    "Ligas III",
    "Mabolo I",
    "Mabolo II",
    "Mabolo III",
    "Maliksi I",
    "Maliksi II",
    "Maliksi III",
    "Mambog I",
    "Mambog II",
    "Mambog III",
    "Mambog IV",
    "Mambog V",
    "Molino I",
    "Molino II",
    "Molino III",
    "Molino IV",
    "Molino V",
    "Molino VI",
    "Molino VII",
    "Niog I",
    "Niog II",
    "Niog III",
    "P. F. Espiritu I",
    "P. F. Espiritu II",
    "P. F. Espiritu III",
    "P. F. Espiritu IV",
    "P. F. Espiritu V",
    "P. F. Espiritu VI",
    "P. F. Espiritu VII",
    "P. F. Espiritu VIII",
    "Queens Row Central",
    "Queens Row East",
    "Queens Row West",
    "Real I",
    "Real II",
    "Salinas I",
    "Salinas II",
    "Salinas III",
    "Salinas IV",
    "San Nicolas I",
    "San Nicolas II",
    "San Nicolas III",
    "Sineguelasan",
    "Tabing Dagat",
    "Talaba I",
    "Talaba II",
    "Talaba III",
    "Talaba IV",
    "Talaba V",
    "Talaba VI",
    "Talaba VII",
    "Zapote I",
    "Zapote II",
    "Zapote III",
    "Zapote IV",
    "Zapote V"
];


// Add CDC
// Add CDC
if (isset($_POST['add_cdc'])) {
    $cdc_name = isset($_POST['cdc_name']) ? trim($_POST['cdc_name']) : "";
    $barangay = isset($_POST['barangay']) ? trim($_POST['barangay']) : "";
    $address = isset($_POST['address']) ? trim($_POST['address']) : "";

    if ($cdc_name == "") {
        $error = "Please enter CDC name.";
    } else {
        $check_stmt = $conn->prepare("SELECT cdc_id FROM cdc WHERE cdc_name = ?");
        $check_stmt->bind_param("s", $cdc_name);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result && $check_result->num_rows > 0) {
            $error = "CDC name already exists.";
        } else {
            $stmt = $conn->prepare("INSERT INTO cdc (cdc_name, barangay, address) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $cdc_name, $barangay, $address);

            if ($stmt->execute()) {
                $success = "CDC added successfully.";
                $_POST = [];
            } else {
                $error = "Error adding CDC.";
            }
        }
    }
}

$search = isset($_GET['search']) ? trim($_GET['search']) : "";

// CDC list with child count
if ($search != "") {
    $like = "%" . $search . "%";

    $cdc_stmt = $conn->prepare("
        SELECT 
            c.cdc_id,
            c.cdc_name,
            c.barangay,
            c.address,
            COUNT(ch.child_id) AS total_children
        FROM cdc c
        LEFT JOIN children ch ON c.cdc_id = ch.cdc_id
        WHERE c.cdc_name LIKE ? OR c.barangay LIKE ? OR c.address LIKE ?
        GROUP BY c.cdc_id, c.cdc_name, c.barangay, c.address
        ORDER BY c.cdc_id DESC
    ");
    $cdc_stmt->bind_param("sss", $like, $like, $like);
    $cdc_stmt->execute();
    $cdc_result = $cdc_stmt->get_result();
} else {
    $cdc_result = $conn->query("
        SELECT 
            c.cdc_id,
            c.cdc_name,
            c.barangay,
            c.address,
            COUNT(ch.child_id) AS total_children
        FROM cdc c
        LEFT JOIN children ch ON c.cdc_id = ch.cdc_id
        GROUP BY c.cdc_id, c.cdc_name, c.barangay, c.address
        ORDER BY c.cdc_id DESC
    ");
}

// Summary cards
$total_cdc = 0;
$total_children = 0;

$summary_sql = "
    SELECT 
        COUNT(DISTINCT c.cdc_id) AS total_cdc,
        COUNT(ch.child_id) AS total_children
    FROM cdc c
    LEFT JOIN children ch ON c.cdc_id = ch.cdc_id
";
$summary_result = $conn->query($summary_sql);
if ($summary_result && $summary_result->num_rows > 0) {
    $summary_row = $summary_result->fetch_assoc();
    $total_cdc = (int)$summary_row['total_cdc'];
    $total_children = (int)$summary_row['total_children'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CDC Management</title>
    <link rel="stylesheet" href="../assets/admin/admin-style.css">
    <link rel="stylesheet" href="../assets/admin/add_cdc.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
</head>
<body class="<?php echo (isset($_SESSION['theme_mode']) && $_SESSION['theme_mode'] === 'dark') ? 'dark-mode' : ''; ?>">

<?php include '../includes/admin_sidebar.php'; ?>
<?php include '../includes/admin_topbar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="page-header">
        <h1>CDC Management</h1>
        <p>Manage child development centers and review the total number of children per CDC.</p>
    </div>

    <?php if ($success != "") { ?>
        <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
    <?php } ?>

    <?php if ($error != "") { ?>
        <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
    <?php } ?>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="summary-label">Total CDC</div>
            <div class="summary-value"><?php echo $total_cdc; ?></div>
        </div>
        <div class="summary-card">
            <div class="summary-label">Total Children</div>
            <div class="summary-value"><?php echo $total_children; ?></div>
        </div>
    </div>

    <div class="toolbar-card">
        <form method="GET" class="search-form">
            <input 
                type="text" 
                name="search" 
                class="search-input" 
                placeholder="Search CDC, barangay, or address"
                value="<?php echo htmlspecialchars($search); ?>"
            >
            <button type="submit" class="btn btn-secondary">Search</button>
            <a href="add_cdc.php" class="btn btn-light">Reset</a>
        </form>

        <button type="button" class="btn btn-primary" onclick="openAddCdcForm()">Add CDC</button>
    </div>

<div class="form-card" id="addCdcForm">
            <div class="card-header">
            <h2>Add CDC</h2>
            <p>Enter the CDC information below.</p>
        </div>

        <form method="POST">
            <div class="form-grid">
                <div class="form-group full">
                    <label for="cdc_name">CDC Name</label>
                    <input 
                        type="text" 
                        id="cdc_name"
                        name="cdc_name" 
                        required 
                        value="<?php echo isset($_POST['cdc_name']) ? htmlspecialchars($_POST['cdc_name']) : ''; ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="barangay">Barangay</label>
                    <select id="barangay" name="barangay">
                        <option value="">Select Barangay</option>

                        <?php foreach ($barangay_list as $barangay_option) { ?>
                            <option 
                                value="<?php echo htmlspecialchars($barangay_option); ?>"
                                <?php echo (isset($_POST['barangay']) && $_POST['barangay'] === $barangay_option) ? 'selected' : ''; ?>
                            >
                                <?php echo htmlspecialchars($barangay_option); ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="address">Address</label>
                    <input 
                        type="text" 
                        id="address"
                        name="address" 
                        value="<?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?>"
                    >
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" name="add_cdc" class="btn btn-primary">Save CDC</button>
                <button type="button" class="btn btn-light" onclick="closeAddCdcForm()">Cancel</button>
            </div>
        </form>
    </div>

    <div class="table-card">
        <div class="card-header">
            <h2>CDC List</h2>
            <p>View all registered child development centers.</p>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th style="width: 30%;">CDC Name</th>
                        <th style="width: 18%;">Barangay</th>
                        <th style="width: 32%;">Address</th>
                        <th style="width: 20%; text-align:center;">No. of Child</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($cdc_result && $cdc_result->num_rows > 0) { ?>
                        <?php while ($row = $cdc_result->fetch_assoc()) { ?>
                            <tr>
                                <td class="cdc-name"><?php echo htmlspecialchars($row['cdc_name']); ?></td>
                                <td><?php echo !empty($row['barangay']) ? htmlspecialchars($row['barangay']) : 'N/A'; ?></td>
                                <td><?php echo !empty($row['address']) ? htmlspecialchars($row['address']) : 'N/A'; ?></td>
                                <td class="child-count"><?php echo (int)$row['total_children']; ?></td>
                            </tr>
                        <?php } ?>
                    <?php } else { ?>
                        <tr>
                            <td colspan="4" class="empty-state">No CDC found.</td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="../assets/admin/sidebar.js"></script>

<script>
    function openAddCdcForm() {
        document.getElementById('addCdcForm').classList.add('show');
    }

    function closeAddCdcForm() {
        document.getElementById('addCdcForm').classList.remove('show');
    }
</script>
</body>
</html>