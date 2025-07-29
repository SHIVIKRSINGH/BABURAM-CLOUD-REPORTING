<?php
$receipt_id = $_POST['receipt_id'] ?? '';
$photo = $_POST['photo'] ?? '';

$path = __DIR__ . "/uploads/$receipt_id/$photo";
if (file_exists($path)) {
    unlink($path);
}

header("Location: upload_photos.php?receipt_id=$receipt_id&branch_id=" . ($_GET['branch_id'] ?? ''));
exit;
