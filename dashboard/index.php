<?php
/**
 * RentOGo Dashboard Index
 * 
 * This file serves as the main dashboard router that redirects users
 * to their appropriate dashboard based on their role and login status.
 * 
 * @author RentOGo Development Team
 * @version 1.0
 * @since 2025
 */

session_start();
require_once 'config/database.php';

// Check if user is logged in
if (!isset($_SESSION['userid']) || !isset($_SESSION['role'])) {
    // User is not logged in, redirect to login page
    header("Location: auth/login.php");
    exit();
}

// Get user information from database for verification
$database = new Database();
$db = $database->getConnection();

// Verify user still exists and is active
$query = "SELECT userid, username, role, status FROM user WHERE userid = ? AND status = 'active'";
$stmt = $db->prepare($query);
$stmt->bindParam(1, $_SESSION['userid']);
$stmt->execute();

if ($stmt->rowCount() == 0) {
    // User not found or inactive, destroy session and redirect
    session_unset();
    session_destroy();
    header("Location: auth/login.php?error=account_inactive");
    exit();
}

$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Verify session role matches database role
if ($user['role'] !== $_SESSION['role']) {
    // Role mismatch, update session or redirect to login
    $_SESSION['role'] = $user['role'];
}

// Update session username if it has changed
if ($user['username'] !== $_SESSION['username']) {
    $_SESSION['username'] = $user['username'];
}

// Route user to appropriate dashboard based on their role
switch ($_SESSION['role']) {
    case 'student':
        header("Location: student/dashboard.php");
        exit();
        
    case 'driver':
        header("Location: driver/dashboard.php");
        exit();
        
    case 'admin':
        header("Location: admin/dashboard.php");
        exit();
        
    default:
        // Unknown role, log out and redirect
        session_unset();
        session_destroy();
        header("Location: auth/login.php?error=invalid_role");
        exit();
}

// If we reach this point, something went wrong
// This should never execute, but it's here as a fallback
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - RentOGo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="bg-light">
    <div class="container-fluid vh-100 d-flex align-items-center justify-content-center">
        <div class="text-center">
            <div class="spinner-border text-primary mb-3" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <h4>Redirecting to Dashboard...</h4>
            <p class="text-muted">Please wait while we redirect you to your dashboard.</p>
            
            <!-- Fallback navigation -->
            <div class="mt-4">
                <h6>Having trouble? Choose your dashboard:</h6>
                <div class="btn-group" role="group">
                    <?php if ($_SESSION['role'] == 'student'): ?>
                        <a href="student/dashboard.php" class="btn btn-primary">
                            <i class="bi bi-person"></i> Student Dashboard
                        </a>
                    <?php elseif ($_SESSION['role'] == 'driver'): ?>
                        <a href="driver/dashboard.php" class="btn btn-success">
                            <i class="bi bi-car-front"></i> Driver Dashboard
                        </a>
                    <?php elseif ($_SESSION['role'] == 'admin'): ?>
                        <a href="admin/dashboard.php" class="btn btn-warning">
                            <i class="bi bi-gear"></i> Admin Dashboard
                        </a>
                    <?php endif; ?>
                    
                    <a href="auth/logout.php" class="btn btn-outline-danger">
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </a>
                </div>
            </div>
            
            <!-- System Information -->
            <div class="mt-4 p-3 bg-white rounded shadow-sm">
                <small class="text-muted">
                    <strong>Session Info:</strong><br>
                    User: <?php echo htmlspecialchars($_SESSION['username'] ?? 'Unknown'); ?><br>
                    Role: <?php echo htmlspecialchars($_SESSION['role'] ?? 'Unknown'); ?><br>
                    User ID: <?php echo htmlspecialchars($_SESSION['userid'] ?? 'Unknown'); ?>
                </small>
            </div>
        </div>
    </div>

    <!-- Auto-redirect script -->
    <script>
        // Auto-redirect after 3 seconds if JavaScript is enabled
        setTimeout(function() {
            const role = '<?php echo $_SESSION['role'] ?? ''; ?>';
            
            switch(role) {
                case 'student':
                    window.location.href = 'student/dashboard.php';
                    break;
                case 'driver':
                    window.location.href = 'driver/dashboard.php';
                    break;
                case 'admin':
                    window.location.href = 'admin/dashboard.php';
                    break;
                default:
                    window.location.href = 'auth/login.php';
            }
        }, 3000);
        
        // Show progress indicator
        let dots = 0;
        setInterval(function() {
            dots = (dots + 1) % 4;
            const loadingText = 'Redirecting' + '.'.repeat(dots);
            const element = document.querySelector('h4');
            if (element) {
                element.textContent = loadingText;
            }
        }, 500);
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>