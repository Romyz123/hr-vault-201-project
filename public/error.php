<?php
// public/error.php
session_start();
$code = $_GET['code'] ?? 404;
$title = "Error";
$message = "An unexpected error occurred.";
$icon = "bi-exclamation-triangle";

switch ($code) {
    case 403:
        $title = "403 Forbidden";
        $message = "You do not have permission to access this resource.";
        $icon = "bi-shield-lock-fill";
        break;
    case 404:
        $title = "404 Not Found";
        $message = "The page or file you are looking for could not be found.";
        $icon = "bi-search";
        break;
    case 500:
        $title = "500 Server Error";
        $message = "The server encountered an internal error. Please try again later.";
        $icon = "bi-server";
        break;
    default:
        $title = "Error " . htmlspecialchars($code);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title><?php echo $title; ?></title>
    <link rel="icon" href="assets/images/tesp-logo.png" type="image/png">
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/icons/bootstrap-icons.css">
    <style>
        body {
            background-color: #f8f9fa;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .error-card {
            max-width: 500px;
            width: 100%;
            text-align: center;
            border: none;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            border-radius: 1rem;
        }

        .icon-box {
            font-size: 4rem;
            color: #dc3545;
            margin-bottom: 1rem;
        }
    </style>
</head>

<body>
    <div class="card error-card p-5">
        <div class="icon-box"><i class="bi <?php echo $icon; ?>"></i></div>
        <h1 class="fw-bold text-dark mb-3"><?php echo $title; ?></h1>
        <p class="text-muted mb-4"><?php echo $message; ?></p>
        <div>
            <a href="index.php" class="btn btn-primary px-4 fw-bold">Back to Dashboard</a>
            <a href="javascript:history.back()" class="btn btn-outline-secondary px-4 ms-2">Go Back</a>
        </div>
    </div>
</body>

</html>