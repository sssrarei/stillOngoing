<?php

include '../includes/auth.php';
include '../config/database.php';

if($_SESSION['role_id'] != 2){
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$recorded_by_name = trim($_SESSION['first_name'] . ' ' . $_SESSION['last_name']);

$message = "";
$error = "";

$children = [];
$recent_records = [];

/*
|-------------------------------------------------------
| GET ACTIVE / SELECTED CDC
|-------------------------------------------------------
*/
$selected_cdc_id = 0;

if(isset($_GET['cdc_id']) && !empty($_GET['cdc_id'])){
    $selected_cdc_id = (int) $_GET['cdc_id'];
    $_SESSION['selected_cdc_id'] = $selected_cdc_id;
} elseif(isset($_SESSION['selected_cdc_id']) && !empty($_SESSION['selected_cdc_id'])) {
    $selected_cdc_id = (int) $_SESSION['selected_cdc_id'];
} elseif(isset($_SESSION['cdc_id']) && !empty($_SESSION['cdc_id'])) {
    $selected_cdc_id = (int) $_SESSION['cdc_id'];
} elseif(isset($_SESSION['active_cdc_id']) && !empty($_SESSION['active_cdc_id'])) {
    $selected_cdc_id = (int) $_SESSION['active_cdc_id'];
}

/*
|-------------------------------------------------------
| VALIDATE IF SELECTED CDC IS ASSIGNED TO LOGGED-IN CDW
|-------------------------------------------------------
*/
if($selected_cdc_id <= 0){
    $error = "No CDC selected.";
} else {
    $check_cdc_sql = "SELECT assignment_id 
                      FROM cdw_assignments 
                      WHERE user_id = ? AND cdc_id = ?
                      LIMIT 1";
    $check_cdc_stmt = $conn->prepare($check_cdc_sql);
    $check_cdc_stmt->bind_param("ii", $user_id, $selected_cdc_id);
    $check_cdc_stmt->execute();
    $check_cdc_result = $check_cdc_stmt->get_result();

    if($check_cdc_result->num_rows == 0){
        $error = "You are not assigned to this CDC.";
        $selected_cdc_id = 0;
    }
}

/*
|-------------------------------------------------------
| GET CHILDREN UNDER LOGGED-IN CDW AND SELECTED CDC ONLY
|-------------------------------------------------------
*/
if($selected_cdc_id > 0){
    $sql_children = "SELECT c.child_id, c.first_name, c.last_name
                     FROM children c
                     INNER JOIN cdw_assignments ca ON c.cdc_id = ca.cdc_id
                     WHERE ca.user_id = ?
                     AND c.cdc_id = ?
                     ORDER BY c.last_name ASC, c.first_name ASC";

    $stmt_children = $conn->prepare($sql_children);
    $stmt_children->bind_param("ii", $user_id, $selected_cdc_id);
    $stmt_children->execute();
    $result_children = $stmt_children->get_result();

    while($row = $result_children->fetch_assoc()){
        $children[] = $row;
    }
}

/*
|-------------------------------------------------------
| SAVE MILK FEEDING RECORDS
|-------------------------------------------------------
*/
if(isset($_POST['save_milk_records'])){
    $feeding_date = trim($_POST['feeding_date']);
    $milk_type = trim($_POST['milk_type']);
    $amount = trim($_POST['amount']);

    if($selected_cdc_id <= 0){
        $error = "No CDC selected.";
    } elseif($feeding_date == "" || $milk_type == "" || $amount == ""){
        $error = "Please select date, milk type, and amount first.";
    } else {
        if(isset($_POST['attendance']) && is_array($_POST['attendance'])){
            $insert_sql = "INSERT INTO milk_feeding_records 
                           (child_id, feeding_date, attendance, milk_type, amount, remarks, recorded_by)
                           VALUES (?, ?, ?, ?, ?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_sql);

            $validate_child_sql = "SELECT child_id 
                                   FROM children 
                                   WHERE child_id = ? AND cdc_id = ?
                                   AND is_deleted = 0
                                   LIMIT 1";
            $validate_child_stmt = $conn->prepare($validate_child_sql);

            foreach($_POST['attendance'] as $child_id => $attendance){
                $child_id = (int) $child_id;
                $attendance = trim($attendance);
                $remarks_choice = isset($_POST['remarks'][$child_id]) ? trim($_POST['remarks'][$child_id]) : "";
                $remarks_other = isset($_POST['remarks_other'][$child_id]) ? trim($_POST['remarks_other'][$child_id]) : "";

                if ($remarks_choice === "Others") {
                    $remarks = $remarks_other !== "" ? "Others: " . $remarks_other : "Others";
                } else {
                    $remarks = $remarks_choice;
                }

                $validate_child_stmt->bind_param("ii", $child_id, $selected_cdc_id);
                $validate_child_stmt->execute();
                $validate_child_result = $validate_child_stmt->get_result();

                if($validate_child_result->num_rows == 0){
                    continue;
                }

                $row_milk_type = $milk_type;
                $row_amount = $amount;

                if($attendance == "Absent"){
                    $row_milk_type = NULL;
                    $row_amount = NULL;
                    $remarks = "Absent";
                } else {
                    if($remarks == ""){
                        $remarks = NULL;
                    }
                }

                $insert_stmt->bind_param(
                    "isssssi",
                    $child_id,
                    $feeding_date,
                    $attendance,
                    $row_milk_type,
                    $row_amount,
                    $remarks,
                    $user_id
                );
                $insert_stmt->execute();
            }

            $message = "Milk feeding records saved successfully.";
        } else {
            $error = "No child attendance data found.";
        }
    }
}

/*
|-------------------------------------------------------
| GET RECENT / HISTORY RECORDS FROM DATABASE
| SELECTED CDC ONLY
|-------------------------------------------------------
*/
if($selected_cdc_id > 0){
    $sql_recent = "SELECT 
                        m.milk_record_id,
                        m.feeding_date,
                        m.attendance,
                        m.milk_type,
                        m.amount,
                        m.remarks,
                        c.first_name,
                        c.last_name,
                        u.first_name AS recorded_first_name,
                        u.last_name AS recorded_last_name
                   FROM milk_feeding_records m
                   INNER JOIN children c ON m.child_id = c.child_id
                   INNER JOIN users u ON m.recorded_by = u.user_id
                   INNER JOIN cdw_assignments ca ON c.cdc_id = ca.cdc_id
                   WHERE ca.user_id = ?
                   AND c.cdc_id = ?
                   ORDER BY m.feeding_date DESC, m.milk_record_id DESC";

    $stmt_recent = $conn->prepare($sql_recent);
    $stmt_recent->bind_param("ii", $user_id, $selected_cdc_id);
    $stmt_recent->execute();
    $result_recent = $stmt_recent->get_result();

    while($row = $result_recent->fetch_assoc()){
        $recent_records[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Milk Feeding | NutriTrack</title>

    <link rel="stylesheet" href="../assets/cdw/cdw-style.css">
    <link rel="stylesheet" href="../assets/cdw/milk_feeding.css">
    <link rel="stylesheet" href="../assets/cdw/cdw-topbar-notification.css">
</head>
<?php include __DIR__ . '/../includes/auth.php'; ?>
<body class="<?php echo themeClass(); ?>">

<div class="main-container">

    <?php include '../includes/cdw_sidebar.php'; ?>

    <div class="main-content" id="mainContent">

        <?php include '../includes/cdw_topbar.php'; ?>

        <div class="page-wrapper">

            <div class="page-header">
                <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
                <h1 class="page-title">Milk Feeding</h1>
                <div class="page-subtitle">
                    Record and update milk feeding data for pupils under your active CDC.
                </div>
            </div>


            <?php if($message != ""){ ?>
                <div class="alert-success"><?php echo $message; ?></div>
            <?php } ?>

            <?php if($error != ""){ ?>
                <div class="alert-error"><?php echo $error; ?></div>
            <?php } ?>

            <div class="milk-form-card">
                <form method="POST" id="milkRecordForm">

                    <div class="form-grid">

                        <div class="form-group">
                            <label for="feeding_date">Date</label>
                            <input type="date" name="feeding_date" id="feeding_date" required>
                        </div>

                        <div class="form-group">
                            <label for="milk_type">Milk Type</label>
                            <select name="milk_type" id="milk_type" required>
                                <option value="">Select Milk</option>
                                <option value="Whole Milk">Whole Milk</option>
                                <option value="Low Fat Milk">Low Fat Milk</option>
                                <option value="Skimmed / Non-Fat Milk">Skimmed / Non-Fat Milk</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="amount">Amount</label>
                            <select name="amount" id="amount" required>
                                <option value="">Select Amount</option>
                                <option value="1/4 cup">1/4 cup</option>
                                <option value="1/2 cup">1/2 cup</option>
                                <option value="2/3 cup">2/3 cup</option>
                                <option value="1 cup">1 cup</option>
                                <option value="1 tetrabrick">1 tetrabrick</option>
                            </select>
                        </div>

                    </div>

                    <div class="form-actions">
                        <button type="reset" class="btn btn-clear">Clear</button>
                        <button type="button" class="btn btn-save" id="applyToTableBtn">Apply to Table</button>
                    </div>

                    <div class="milk-table-card">
                        <div class="section-header">
                            <h3>Milk Feeding Attendance</h3>
                        </div>

                        <div class="remarks-all-row">
    <div class="form-group remarks-all-group">
        <label for="remarksForAllSelect">Remarks for All</label>
        <select id="remarksForAllSelect" class="table-select">
            <option value="">Select remarks</option>
            <option value="N/A">N/A</option>
            <option value="Finished">Finished</option>
            <option value="Half">Half</option>
            <option value="Refused">Refused</option>
            <option value="Vomited">Vomited</option>
            <option value="Leftover">Leftover</option>
            <option value="Others">Others</option>
        </select>
    </div>

    <button type="button" id="applyRemarksToTableBtn" class="btn btn-save remarks-apply-btn">
        Apply Remarks to Table
    </button>
</div>

                        <div class="table-responsive">
                            <table class="milk-table">
                                <thead>
                                    <tr>
                                        <th>Child Name</th>
                                        <th>Attendance</th>
                                        <th>Milk Type</th>
                                        <th>Amount</th>
                                        <th>Remarks</th>
                                        <th>Recorded By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(!empty($children)){ ?>
                                        <?php foreach($children as $child){ ?>
                                            <tr class="milk-row">
                                                <td class="child-name-cell">
                                                    <?php echo htmlspecialchars($child['first_name'] . ' ' . $child['last_name']); ?>
                                                </td>

                                                <td>
                                                    <select name="attendance[<?php echo $child['child_id']; ?>]" class="table-select attendance-select">
                                                        <option value="Present">Present</option>
                                                        <option value="Absent">Absent</option>
                                                    </select>
                                                </td>

                                                <td>
                                                    <input
                                                        type="text"
                                                        class="table-input milk-type-input"
                                                        placeholder="Milk type"
                                                        readonly
                                                    >
                                                </td>

                                                <td>
                                                    <input
                                                        type="text"
                                                        class="table-input amount-input"
                                                        placeholder="Amount"
                                                        readonly
                                                    >
                                                </td>

                                                <td>
                                                    <select
                                                        name="remarks[<?php echo $child['child_id']; ?>]"
                                                        class="table-select remarks-select"
                                                    >
                                                        <option value="N/A">N/A</option>
                                                        <option value="Finished">Finished</option>
                                                        <option value="Half">Half</option>
                                                        <option value="Refused">Refused</option>
                                                        <option value="Vomited">Vomited</option>
                                                        <option value="Leftover">Leftover</option>
                                                        <option value="Others">Others</option>
                                                    </select>

                                                    <input
                                                        type="text"
                                                        name="remarks_other[<?php echo $child['child_id']; ?>]"
                                                        class="table-input remarks-other-input"
                                                        placeholder="Please specify"
                                                        style="display:none; margin-top:8px;"
                                                    >
                                                </td>

                                                <td>
                                                    <input
                                                        type="text"
                                                        class="table-input recorded-by-input"
                                                        value="<?php echo htmlspecialchars($recorded_by_name); ?>"
                                                        readonly
                                                    >
                                                </td>
                                            </tr>
                                        <?php } ?>
                                    <?php } else { ?>
                                        <tr>
                                            <td colspan="6" class="empty-row">No children found under your selected CDC.</td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="table-actions single-action">
                            <button type="submit" name="save_milk_records" class="btn btn-add">Add to Pupils Record</button>
                        </div>
                    </div>

                </form>
            </div>

            <div class="recent-records-card">
                <div class="section-header">
                    <h3>Recent Milk Feeding Records</h3>
                </div>

                <div class="recent-table-wrapper">
                    <table class="recent-milk-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Child Name</th>
                                <th>Attendance</th>
                                <th>Milk Type</th>
                                <th>Amount</th>
                                <th>Remarks</th>
                                <th>Recorded By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(!empty($recent_records)){ ?>
                                <?php foreach($recent_records as $record){ ?>
                                    <tr>
                                        <td><?php echo date("M d, Y", strtotime($record['feeding_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($record['first_name'] . ' ' . $record['last_name']); ?></td>
                                        <td>
                                            <span class="mini-status <?php echo strtolower($record['attendance']); ?>">
                                                <?php echo htmlspecialchars($record['attendance']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo $record['milk_type'] != NULL ? htmlspecialchars($record['milk_type']) : "-"; ?>
                                        </td>
                                        <td>
                                            <?php echo $record['amount'] != NULL ? htmlspecialchars($record['amount']) : "-"; ?>
                                        </td>
                                        <td>
                                            <?php echo $record['remarks'] != NULL && $record['remarks'] != "" ? htmlspecialchars($record['remarks']) : "-"; ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($record['recorded_first_name'] . ' ' . $record['recorded_last_name']); ?>
                                        </td>
                                    </tr>
                                <?php } ?>
                            <?php } else { ?>
                                <tr>
                                    <td colspan="7" class="empty-row">No milk feeding records found yet.</td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<script>

document.addEventListener("DOMContentLoaded", function () {
    const feedingDate = document.getElementById("feeding_date");
    const milkType = document.getElementById("milk_type");
    const amount = document.getElementById("amount");
    const applyBtn = document.getElementById("applyToTableBtn");

    const remarksForAllSelect = document.getElementById("remarksForAllSelect");
    const applyRemarksToTableBtn = document.getElementById("applyRemarksToTableBtn");

    function toggleRemarksOtherInput(select) {
        const row = select.closest("tr");
        const otherInput = row ? row.querySelector(".remarks-other-input") : null;

        if (!otherInput) return;

        if (select.value === "Others") {
            otherInput.style.display = "block";
        } else {
            otherInput.style.display = "none";
            otherInput.value = "";
        }
    }

    function applyAbsentBehavior(row) {
        const attendance = row.querySelector(".attendance-select");
        const milkTypeInput = row.querySelector(".milk-type-input");
        const amountInput = row.querySelector(".amount-input");
        const remarksSelect = row.querySelector(".remarks-select");
        const remarksOtherInput = row.querySelector(".remarks-other-input");

        if (!attendance || !milkTypeInput || !amountInput || !remarksSelect) return;

        if (attendance.value === "Absent") {
            milkTypeInput.value = "";
            amountInput.value = "";

            remarksSelect.value = "N/A";

            if (remarksOtherInput) {
                remarksOtherInput.style.display = "none";
                remarksOtherInput.value = "";
            }

            milkTypeInput.disabled = true;
            amountInput.disabled = true;
            remarksSelect.disabled = true;

            if (remarksOtherInput) {
                remarksOtherInput.disabled = true;
            }

            row.classList.add("row-absent");
        } else {
            milkTypeInput.disabled = false;
            amountInput.disabled = false;
            remarksSelect.disabled = false;

            if (remarksOtherInput) {
                remarksOtherInput.disabled = false;
            }

            row.classList.remove("row-absent");
            toggleRemarksOtherInput(remarksSelect);
        }
    }

    function applySetupToPresentRows() {
        if (feedingDate.value === "" || milkType.value === "" || amount.value === "") {
            alert("Please select date, milk type, and amount first.");
            return;
        }

        document.querySelectorAll(".milk-row").forEach(function (row) {
            const attendance = row.querySelector(".attendance-select");
            const milkTypeInput = row.querySelector(".milk-type-input");
            const amountInput = row.querySelector(".amount-input");

            if (!attendance || !milkTypeInput || !amountInput) return;

            if (attendance.value === "Present") {
                milkTypeInput.value = milkType.value;
                amountInput.value = amount.value;
            } else {
                milkTypeInput.value = "";
                amountInput.value = "";
            }

            applyAbsentBehavior(row);
        });
    }

    document.querySelectorAll(".remarks-select").forEach(function (select) {
        select.addEventListener("change", function () {
            toggleRemarksOtherInput(select);
        });

        toggleRemarksOtherInput(select);
    });

    if (applyRemarksToTableBtn && remarksForAllSelect) {
        applyRemarksToTableBtn.addEventListener("click", function () {
            const selectedRemark = remarksForAllSelect.value;

            if (selectedRemark === "") {
                alert("Please select a remark first.");
                return;
            }

            document.querySelectorAll(".milk-row").forEach(function (row) {
                const attendance = row.querySelector(".attendance-select");
                const remarksSelect = row.querySelector(".remarks-select");

                if (!attendance || !remarksSelect) return;

                if (attendance.value === "Present") {
                    remarksSelect.value = selectedRemark;
                    toggleRemarksOtherInput(remarksSelect);
                }
            });
        });
    }

    if (applyBtn) {
        applyBtn.addEventListener("click", function () {
            applySetupToPresentRows();
        });
    }

    document.querySelectorAll(".milk-row").forEach(function (row) {
        const attendance = row.querySelector(".attendance-select");

        applyAbsentBehavior(row);

        if (attendance) {
            attendance.addEventListener("change", function () {
                if (this.value === "Present") {
                    if (milkType.value !== "") {
                        row.querySelector(".milk-type-input").value = milkType.value;
                    }

                    if (amount.value !== "") {
                        row.querySelector(".amount-input").value = amount.value;
                    }
                }

                applyAbsentBehavior(row);
            });
        }
    });

    const sidebar = document.getElementById("sidebar");
    const content = document.getElementById("mainContent");
    const overlay = document.getElementById("sidebarOverlay");

    const sidebarHidden = localStorage.getItem("cdw_sidebar_hidden");

    if (sidebarHidden === "true") {
        if (sidebar) sidebar.classList.add("hide");
        if (content) content.classList.add("full");
        if (overlay) overlay.classList.remove("show");
    }
});

function toggleSidebar() {
    const sidebar = document.getElementById("sidebar");
    const content = document.getElementById("mainContent");
    const overlay = document.getElementById("sidebarOverlay");

    if (!sidebar || !content) return;

    sidebar.classList.toggle("closed");
    content.classList.toggle("full");

    if (overlay && window.innerWidth <= 991) {
        overlay.classList.toggle("show");
    }

    const isHidden = sidebar.classList.contains("hide");
    localStorage.setItem("cdw_sidebar_hidden", isHidden ? "true" : "false");
}

function closeSidebar() {
    const sidebar = document.getElementById("sidebar");
    const content = document.getElementById("mainContent");
    const overlay = document.getElementById("sidebarOverlay");

    if (!sidebar || !content) return;

    sidebar.classList.add("closed");
    content.classList.add("full");

    if (overlay && window.innerWidth <= 991) {
        overlay.classList.remove("show");
    }

    localStorage.setItem("cdw_sidebar_hidden", "true");
}

</script>
<script src="../assets/cdw/sidebar.js"></script>
</body>
</html>