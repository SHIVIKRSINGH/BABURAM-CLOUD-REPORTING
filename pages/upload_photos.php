<?php
require_once "../includes/config.php";

$receipt_id = $_GET['receipt_id'] ?? '';
$branch_id = $_GET['branch_id'] ?? '';

if (!$receipt_id || !$branch_id) {
    echo "Invalid parameters.";
    exit;
}

$uploadSuccess = false;
$uploadError = '';

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_FILES['photos']['name'][0])) {
        $uploadDir = __DIR__ . "/uploads/$receipt_id/";
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

        foreach ($_FILES['photos']['tmp_name'] as $i => $tmpName) {
            $originalName = $_FILES['photos']['name'][$i];
            $error = $_FILES['photos']['error'][$i];
            $size = $_FILES['photos']['size'][$i];

            // Sanitize & rename file
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $safeName = "photo_" . time() . "_$i." . $ext;
            $target = $uploadDir . $safeName;

            if ($error === UPLOAD_ERR_OK && $size > 0 && is_uploaded_file($tmpName)) {
                if (move_uploaded_file($tmpName, $target)) {
                    $uploadSuccess = true;
                } else {
                    $uploadError .= "❌ Failed to move file: $originalName<br>";
                }
            } else {
                $uploadError .= "❌ Upload error for: $originalName (Error code: $error)<br>";
            }
        }
    }
}

// Load existing images
$existingPhotos = [];
$photoDir = __DIR__ . "/uploads/$receipt_id/";
if (is_dir($photoDir)) {
    $existingPhotos = array_diff(scandir($photoDir), ['.', '..']);
}

$baseURL = rtrim(dirname($_SERVER['PHP_SELF']), '/');
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Upload Bill Images</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .card-img-top {
            max-height: 200px;
            object-fit: contain;
        }

        @media (max-width: 576px) {
            h4 {
                font-size: 1.2rem;
            }

            .card-img-top {
                max-height: 150px;
            }

            .btn, 
            input.form-control {
                font-size: 0.9rem;
            }
        }
    </style>
</head>

<body class="bg-light">

    <div class="container py-4">
        <h4 class="mb-3">📸 Upload Bill Photos for Receipt ID: <strong><?= htmlspecialchars($receipt_id) ?></strong></h4>

        <?php if ($uploadSuccess): ?>
            <div class="alert alert-success">✅ Photos uploaded successfully.</div>
        <?php elseif ($uploadError): ?>
            <div class="alert alert-danger"><?= $uploadError ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" class="mb-4">
            <div class="mb-3">
                <label class="form-label">Choose or Capture Photos</label>
                <input
                    type="file"
                    name="photos[]"
                    accept="image/*"
                    multiple
                    capture="environment"
                    class="form-control"
                    required>
            </div>
            <button type="submit" class="btn btn-success me-2">📤 Upload Photos</button>
            <a href="purchase.php" class="btn btn-secondary">🔙 Back</a>
        </form>

        <?php if (!empty($existingPhotos)): ?>
            <div class="row">
                <?php foreach ($existingPhotos as $photo): ?>
                    <div class="col-6 col-sm-4 col-md-3 mb-3">
                        <div class="card">
                            <a href="<?= $baseURL ?>/uploads/<?= $receipt_id ?>/<?= urlencode($photo) ?>" target="_blank">
                                <img src="<?= $baseURL ?>/uploads/<?= $receipt_id ?>/<?= urlencode($photo) ?>" class="card-img-top" alt="Photo">
                            </a>
                            <div class="card-body text-center p-2">
                                <form method="post" action="delete_photo.php" onsubmit="return confirm('Delete this photo?')">
                                    <input type="hidden" name="photo" value="<?= htmlspecialchars($photo) ?>">
                                    <input type="hidden" name="receipt_id" value="<?= htmlspecialchars($receipt_id) ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">🗑 Delete</button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="text-muted">No photos uploaded yet.</p>
        <?php endif; ?>
    </div>

</body>

</html>