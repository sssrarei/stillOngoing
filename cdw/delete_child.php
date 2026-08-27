<?php
include '../includes/auth.php';
include '../config/database.php';

if($_SESSION['role_id'] != 2){
    header("Location: ../login.php");
    exit();
}

if(!isset($_GET['child_id'])){
    die("Invalid request.");
}

$child_id = (int) $_GET['child_id'];
$cdc_id = (int) $_SESSION['active_cdc_id'];
$deleted_by = (int) $_SESSION['user_id'];

$conn->begin_transaction();

try {

    // 1. Soft delete child (WITH CDC CHECK)
    // deleted_at is stamped so deleted_children.php can compute the
    // 30-day restore window (see Part 3). deleted_by records which
    // CDW performed the delete, for audit purposes.
    $sql = "UPDATE children 
            SET is_deleted = 1, deleted_at = NOW(), deleted_by = ? 
            WHERE child_id = ? AND cdc_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $deleted_by, $child_id, $cdc_id);
    $stmt->execute();

    if($stmt->affected_rows === 0){
        throw new Exception("Child not found or already deleted.");
    }

    $stmt->close();

    // 2. Soft delete anthropometric records
    $sql = "UPDATE anthropometric_records SET is_deleted = 1 WHERE child_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $child_id);
    $stmt->execute();
    $stmt->close();

    // 3. feeding
    $sql = "UPDATE feeding_records SET is_deleted = 1 WHERE child_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $child_id);
    $stmt->execute();
    $stmt->close();

    // 4. milk feeding
    $sql = "UPDATE milk_feeding_records SET is_deleted = 1 WHERE child_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $child_id);
    $stmt->execute();
    $stmt->close();

    // 5. deworming
    $sql = "UPDATE deworming_records SET is_deleted = 1 WHERE child_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $child_id);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    header("Location: child_list.php?deleted=1");
    exit();

} catch(Exception $e) {
    $conn->rollback();
    die("Delete failed: " . $e->getMessage());
}
?>