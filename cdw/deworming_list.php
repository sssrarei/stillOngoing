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
| SAVE DEWORMING RECORDS
|-------------------------------------------------------
*/
if(isset($_POST['save_deworming_records'])){
    $deworming_date = trim($_POST['deworming_date']);
    $medicine_choice = trim($_POST['medicine'] ?? '');
    $medicine_other = trim($_POST['medicine_other'] ?? '');
    $dosage_choice = trim($_POST['dosage'] ?? '');
    $dosage_other = trim($_POST['dosage_other'] ?? '');

    if($medicine_choice === "Others"){
        $medicine = $medicine_other !== "" ? "Others: " . $medicine_other : "Others";
    } else {
        $medicine = $medicine_choice;
    }

    if($dosage_choice === "Others"){
        $dosage = $dosage_other !== "" ? "Others: " . $dosage_other : "Others";
    } else {
        $dosage = $dosage_choice;
    }

    if($selected_cdc_id <= 0){
        $error = "No CDC selected.";
    } elseif($deworming_date == "" || $medicine == "" || $dosage == ""){
        $error = "Please select date, enter medicine, and dosage first.";
    } else {
        if(isset($_POST['attendance']) && is_array($_POST['attendance'])){
            $insert_sql = "INSERT INTO deworming_records
                           (child_id, deworming_date, attendance, medicine, dosage, remarks, recorded_by)
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

                $row_medicine = $medicine;
                $row_dosage = $dosage;

                if($attendance == "Not Taken"){
                    $row_medicine = NULL;
                    $row_dosage = NULL;
                    $remarks = "Not Taken";
                } else {
                    if($remarks == ""){
                        $remarks = NULL;
                    }
                }

                $insert_stmt->bind_param(
                    "isssssi",
                    $child_id,
                    $deworming_date,
                    $attendance,
                    $row_medicine,
                    $row_dosage,
                    $remarks,
                    $user_id
                );
                $insert_stmt->execute();
            }

            $message = "Deworming records saved successfully.";
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
                        d.deworm_id,
                        d.deworming_date,
                        d.attendance,
                        d.medicine,
                        d.dosage,
                        d.remarks,
                        c.first_name,
                        c.last_name,
                        u.first_name AS recorded_first_name,
                        u.last_name AS recorded_last_name
                   FROM deworming_records d
                   INNER JOIN children c ON d.child_id = c.child_id
                   INNER JOIN users u ON d.recorded_by = u.user_id
                   INNER JOIN cdw_assignments ca ON c.cdc_id = ca.cdc_id
                   WHERE ca.user_id = ?
                   AND c.cdc_id = ?
                   ORDER BY d.deworming_date DESC, d.deworm_id DESC";

    $stmt_recent = $conn->prepare($sql_recent);
    $stmt_recent->bind_param("ii", $user_id, $selected_cdc_id);
    $stmt_recent->execute();
    $result_recent = $stmt_recent->get_result();

    while($row = $result_recent->fetch_assoc()){
        $recent_records[] = $row;
    }
}

/*
|-------------------------------------------------------
| GROUP RECENT RECORDS BY DATE (for accordion display)
| Relies on $recent_records already being ordered by
| deworming_date DESC from the query above.
|-------------------------------------------------------
*/
$grouped_recent_records = [];
foreach($recent_records as $record){
    $date_key = $record['deworming_date'];
    if(!isset($grouped_recent_records[$date_key])){
        $grouped_recent_records[$date_key] = [];
    }
    $grouped_recent_records[$date_key][] = $record;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deworming | NutriTrack</title>

    <link rel="stylesheet" href="../assets/cdw/cdw-style.css">
    <link rel="stylesheet" href="../assets/cdw/deworming.css">
    <link rel="stylesheet" href="../assets/cdw/cdw-topbar-notification.css">

    <style>
        .date-summary-row{
            cursor:pointer;
            background:#f5f7f5;
        }
        .date-summary-row:hover{
            background:#eaf2ea;
        }
        .date-toggle-arrow{
            display:inline-block;
            margin-right:8px;
            font-size:11px;
            transition:transform 0.15s ease;
        }
    </style>
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
            <h1 class="page-title">Deworming</h1>
            <p class="page-subtitle">
                Record and update deworming data for pupils under your active CDC.
            </p>
        </div>

            <?php if($message != ""){ ?>
                <div class="alert-success"><?php echo $message; ?></div>
            <?php } ?>

            <?php if($error != ""){ ?>
                <div class="alert-error"><?php echo $error; ?></div>
            <?php } ?>

            <div class="deworm-form-card">
                <form method="POST" id="dewormingRecordForm">

                    <div class="form-grid">

                        <div class="form-group">
                            <label for="deworming_date">Date</label>
                            <input type="date" name="deworming_date" id="deworming_date" required>
                        </div>

                        <div class="form-group">
                            <label for="medicine">Medicine</label>
                            <select name="medicine" id="medicine" required>
                                <option value="">-- Select Medicine --</option>
                                <option value="Albendazole">Albendazole</option>
                                <option value="Mebendazole">Mebendazole</option>
                                <option value="Others">Others</option>
                            </select>
                        </div>

                        <div class="form-group" id="medicineOtherGroup" style="display:none;">
                            <label for="medicine_other">Specify Medicine</label>
                            <input type="text" name="medicine_other" id="medicine_other" placeholder="Enter medicine name">
                        </div>

                        <div class="form-group">
                            <label for="dosage">Dosage</label>
                            <select name="dosage" id="dosage" required>
                                <option value="">-- Select Dosage --</option>
                                <option value="200mg">200mg</option>
                                <option value="400mg">400mg</option>
                                <option value="500mg">500mg</option>
                                <option value="Others">Others</option>
                            </select>
                        </div>

                        <div class="form-group" id="dosageOtherGroup" style="display:none;">
                            <label for="dosage_other">Specify Dosage</label>
                            <input type="text" name="dosage_other" id="dosage_other" placeholder="Ex. 250mg">
                        </div>

                    </div>

                    <div class="form-actions">
                        <button type="reset" class="btn btn-clear">Clear</button>
                        <button type="button" class="btn btn-save" id="applyToTableBtn">Apply to Table</button>
                    </div>

                    <div class="deworm-table-card">
                        <div class="section-header">
                            <h3>Deworming Attendance</h3>
                        </div>

                        <div class="remarks-all-row">
    <div class="form-group remarks-all-group">
        <label for="remarksForAllSelect">Remarks for All</label>
        <select id="remarksForAllSelect" class="table-select">
            <option value="">Select remarks</option>
            <option value="N/A">N/A</option>
            <option value="Taken">Taken</option>
            <option value="Not Taken">Not Taken</option>
            <option value="Refused">Refused</option>
            <option value="Vomited">Vomited</option>
            <option value="Absent">Absent</option>
            <option value="Others">Others</option>
        </select>
    </div>

    <button type="button" id="applyRemarksToTableBtn" class="btn btn-save remarks-apply-btn">
        Apply Remarks to Table
    </button>
</div>

                        <div class="table-responsive">
                            <table class="deworm-table">
                                <thead>
                                    <tr>
                                        <th>Child Name</th>
                                        <th>Attendance</th>
                                        <th>Medicine</th>
                                        <th>Dosage</th>
                                        <th>Remarks</th>
                                        <th>Recorded By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(!empty($children)){ ?>
                                        <?php foreach($children as $child){ ?>
                                            <tr class="deworm-row">
                                                <td class="child-name-cell">
                                                    <?php echo htmlspecialchars($child['first_name'] . ' ' . $child['last_name']); ?>
                                                </td>

                                                <td>
                                                    <select name="attendance[<?php echo $child['child_id']; ?>]" class="table-select attendance-select">
                                                        <option value="Taken">Taken</option>
                                                        <option value="Not Taken">Not Taken</option>
                                                    </select>
                                                </td>

                                                <td>
                                                    <input
                                                        type="text"
                                                        class="table-input medicine-input"
                                                        placeholder="Medicine"
                                                        readonly
                                                    >
                                                </td>

                                                <td>
                                                    <input
                                                        type="text"
                                                        class="table-input dosage-input"
                                                        placeholder="Dosage"
                                                        readonly
                                                    >
                                                </td>

                                                <td>
                                                    <select
                                                        name="remarks[<?php echo $child['child_id']; ?>]"
                                                        class="table-select remarks-select"
                                                    >
                                                        <option value="N/A">N/A</option>
                                                        <option value="Taken">Taken</option>
                                                        <option value="Not Taken">Not Taken</option>
                                                        <option value="Refused">Refused</option>
                                                        <option value="Vomited">Vomited</option>
                                                        <option value="Absent">Absent</option>
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
                            <button type="submit" name="save_deworming_records" class="btn btn-add">Add to Pupils Record</button>
                        </div>
                    </div>

                </form>
            </div>

            <div class="recent-records-card">
                <div class="section-header">
                    <h3>Recent Deworming Records</h3>
                </div>

                <div class="recent-table-wrapper">
                    <table class="recent-deworm-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Child Name</th>
                                <th>Attendance</th>
                                <th>Medicine</th>
                                <th>Dosage</th>
                                <th>Remarks</th>
                                <th>Recorded By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(!empty($grouped_recent_records)){ ?>
                                <?php $group_index = 0; ?>
                                <?php foreach($grouped_recent_records as $date_key => $date_records){ $group_index++; ?>
                                    <tr class="date-summary-row" onclick="toggleDewormDateGroup(<?php echo $group_index; ?>)">
                                        <td colspan="7">
                                            <span class="date-toggle-arrow" id="dewormArrow<?php echo $group_index; ?>">▶</span>
                                            <strong><?php echo date("M d, Y", strtotime($date_key)); ?></strong>
                                            — <?php echo count($date_records); ?> record<?php echo count($date_records) > 1 ? 's' : ''; ?>
                                        </td>
                                    </tr>
                                    <?php foreach($date_records as $record){ ?>
                                        <tr class="deworm-detail-row deworm-group-<?php echo $group_index; ?>" style="display:none;">
                                            <td><?php echo date("M d, Y", strtotime($record['deworming_date'])); ?></td>
                                            <td><?php echo htmlspecialchars($record['first_name'] . ' ' . $record['last_name']); ?></td>
                                            <td>
                                                <span class="mini-status <?php echo strtolower(str_replace(' ', '-', $record['attendance'])); ?>">
                                                    <?php echo htmlspecialchars($record['attendance']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php echo $record['medicine'] != NULL ? htmlspecialchars($record['medicine']) : "-"; ?>
                                            </td>
                                            <td>
                                                <?php echo $record['dosage'] != NULL ? htmlspecialchars($record['dosage']) : "-"; ?>
                                            </td>
                                            <td>
                                                <?php echo $record['remarks'] != NULL && $record['remarks'] != "" ? htmlspecialchars($record['remarks']) : "-"; ?>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($record['recorded_first_name'] . ' ' . $record['recorded_last_name']); ?>
                                            </td>
                                        </tr>
                                    <?php } ?>
                                <?php } ?>
                            <?php } else { ?>
                                <tr>
                                    <td colspan="7" class="empty-row">No deworming records found yet.</td>
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
    const dewormingDate = document.getElementById("deworming_date");
    const medicine = document.getElementById("medicine");
    const dosage = document.getElementById("dosage");
    const medicineOther = document.getElementById("medicine_other");
    const dosageOther = document.getElementById("dosage_other");
    const medicineOtherGroup = document.getElementById("medicineOtherGroup");
    const dosageOtherGroup = document.getElementById("dosageOtherGroup");
    const applyBtn = document.getElementById("applyToTableBtn");

    function toggleMedicineOther() {
        if (!medicine || !medicineOtherGroup) return;
        if (medicine.value === "Others") {
            medicineOtherGroup.style.display = "block";
        } else {
            medicineOtherGroup.style.display = "none";
            if (medicineOther) medicineOther.value = "";
        }
    }

    function toggleDosageOther() {
        if (!dosage || !dosageOtherGroup) return;
        if (dosage.value === "Others") {
            dosageOtherGroup.style.display = "block";
        } else {
            dosageOtherGroup.style.display = "none";
            if (dosageOther) dosageOther.value = "";
        }
    }

    function getMedicineValue() {
        if (!medicine) return "";
        if (medicine.value === "Others") {
            const other = medicineOther ? medicineOther.value.trim() : "";
            return other !== "" ? "Others: " + other : "Others";
        }
        return medicine.value;
    }

    function getDosageValue() {
        if (!dosage) return "";
        if (dosage.value === "Others") {
            const other = dosageOther ? dosageOther.value.trim() : "";
            return other !== "" ? "Others: " + other : "Others";
        }
        return dosage.value;
    }

    if (medicine) {
        medicine.addEventListener("change", toggleMedicineOther);
        toggleMedicineOther();
    }
    if (dosage) {
        dosage.addEventListener("change", toggleDosageOther);
        toggleDosageOther();
    }

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

    function applyNotTakenBehavior(row) {
        const attendance = row.querySelector(".attendance-select");
        const medicineInput = row.querySelector(".medicine-input");
        const dosageInput = row.querySelector(".dosage-input");
        const remarksSelect = row.querySelector(".remarks-select");
        const remarksOtherInput = row.querySelector(".remarks-other-input");

        if (!attendance || !medicineInput || !dosageInput || !remarksSelect) return;

        if (attendance.value === "Not Taken") {
            medicineInput.value = "";
            dosageInput.value = "";

            remarksSelect.value = "Not Taken";

            if (remarksOtherInput) {
                remarksOtherInput.style.display = "none";
                remarksOtherInput.value = "";
            }

            medicineInput.disabled = true;
            dosageInput.disabled = true;
            remarksSelect.disabled = true;

            if (remarksOtherInput) {
                remarksOtherInput.disabled = true;
            }

            row.classList.add("row-not-taken");
        } else {
            medicineInput.disabled = false;
            dosageInput.disabled = false;
            remarksSelect.disabled = false;

            if (remarksOtherInput) {
                remarksOtherInput.disabled = false;
            }

            row.classList.remove("row-not-taken");
            toggleRemarksOtherInput(remarksSelect);
        }
    }

    function applySetupToTakenRows() {
        if (dewormingDate.value === "" || getMedicineValue() === "" || getDosageValue() === "") {
            alert("Please select date, medicine, and dosage first.");
            return;
        }

        const finalMedicine = getMedicineValue();
        const finalDosage = getDosageValue();

        document.querySelectorAll(".deworm-row").forEach(function (row) {
            const attendance = row.querySelector(".attendance-select");
            const medicineInput = row.querySelector(".medicine-input");
            const dosageInput = row.querySelector(".dosage-input");

            if (!attendance || !medicineInput || !dosageInput) return;

            if (attendance.value === "Taken") {
                medicineInput.value = finalMedicine;
                dosageInput.value = finalDosage;
            } else {
                medicineInput.value = "";
                dosageInput.value = "";
            }

            applyNotTakenBehavior(row);
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

            document.querySelectorAll(".deworm-row").forEach(function (row) {
                const attendance = row.querySelector(".attendance-select");
                const remarksSelect = row.querySelector(".remarks-select");

                if (!attendance || !remarksSelect) return;

                if (attendance.value === "Taken") {
                    remarksSelect.value = selectedRemark;
                    toggleRemarksOtherInput(remarksSelect);
                }
            });
        });
    }

    if (applyBtn) {
        applyBtn.addEventListener("click", function () {
            applySetupToTakenRows();
        });
    }

    document.querySelectorAll(".deworm-row").forEach(function (row) {
        const attendance = row.querySelector(".attendance-select");

        applyNotTakenBehavior(row);

        if (attendance) {
            attendance.addEventListener("change", function () {
                if (this.value === "Taken") {
                    const finalMedicine = getMedicineValue();
                    const finalDosage = getDosageValue();

                    if (finalMedicine !== "") {
                        row.querySelector(".medicine-input").value = finalMedicine;
                    }

                    if (finalDosage !== "") {
                        row.querySelector(".dosage-input").value = finalDosage;
                    }
                }

                applyNotTakenBehavior(row);
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

function toggleDewormDateGroup(groupIndex) {
    const rows = document.querySelectorAll(".deworm-group-" + groupIndex);
    const arrow = document.getElementById("dewormArrow" + groupIndex);

    if (!rows.length) return;

    const isHidden = rows[0].style.display === "none" || rows[0].style.display === "";

    rows.forEach(function (row) {
        row.style.display = isHidden ? "table-row" : "none";
    });

    if (arrow) {
        arrow.textContent = isHidden ? "▼" : "▶";
    }
}

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