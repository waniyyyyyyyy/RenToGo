<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a driver
if (!isset($_SESSION['userid']) || $_SESSION['role'] !== 'driver') {
    header("Location: ../auth/login.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();
$message = '';
$error = '';

// Get driver info with full name
$query = "SELECT u.*, d.* FROM user u 
          JOIN driver d ON u.userid = d.userid 
          WHERE u.userid = ?";
$stmt = $db->prepare($query);
$stmt->bindParam(1, $_SESSION['userid']);
$stmt->execute();
$driver = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$driver) {
    header("Location: ../auth/login.php");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_profile'])) {
        $username = trim($_POST['username']);
        $full_name = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $notel = trim($_POST['notel']);
        
        // Validate inputs
        if (empty($username) || empty($full_name) || empty($email) || empty($notel)) {
            $error = "Please fill in all required fields.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } elseif (!preg_match('/^[\+]?[0-9\-\s()]+$/', $notel)) {
            $error = "Please enter a valid phone number.";
        } else {
            // Check if username or email already exists (excluding current user)
            $query = "SELECT userid FROM user WHERE (username = ? OR email = ?) AND userid != ?";
            $stmt = $db->prepare($query);
            $stmt->bindParam(1, $username);
            $stmt->bindParam(2, $email);
            $stmt->bindParam(3, $_SESSION['userid']);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $error = "Username or email already exists.";
            } else {
                try {
                    // Update user information including full name
                    $query = "UPDATE user SET username = ?, full_name = ?, email = ?, notel = ? WHERE userid = ?";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(1, $username);
                    $stmt->bindParam(2, $full_name);
                    $stmt->bindParam(3, $email);
                    $stmt->bindParam(4, $notel);
                    $stmt->bindParam(5, $_SESSION['userid']);
                    
                    if ($stmt->execute()) {
                        $message = "Profile updated successfully!";
                        
                        // Update session username and full name
                        $_SESSION['username'] = $username;
                        $_SESSION['full_name'] = $full_name;
                        
                        // Refresh driver data
                        $query = "SELECT u.*, d.* FROM user u JOIN driver d ON u.userid = d.userid WHERE u.userid = ?";
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(1, $_SESSION['userid']);
                        $stmt->execute();
                        $driver = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                    } else {
                        $error = "Failed to update profile. Please try again.";
                    }
                } catch (PDOException $e) {
                    $error = "Database error occurred. Please try again.";
                }
            }
        }
    }
    
    if (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error = "Please fill in all password fields.";
        } elseif (strlen($new_password) < 6) {
            $error = "New password must be at least 6 characters long.";
        } elseif ($new_password !== $confirm_password) {
            $error = "New passwords do not match.";
        } else {
            try {
                // Verify current password
                $query = "SELECT password FROM user WHERE userid = ?";
                $stmt = $db->prepare($query);
                $stmt->bindParam(1, $_SESSION['userid']);
                $stmt->execute();
                $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (password_verify($current_password, $user_data['password'])) {
                    // Update password
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $query = "UPDATE user SET password = ? WHERE userid = ?";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(1, $hashed_password);
                    $stmt->bindParam(2, $_SESSION['userid']);
                    
                    if ($stmt->execute()) {
                        $message = "Password changed successfully!";
                    } else {
                        $error = "Failed to change password. Please try again.";
                    }
                } else {
                    $error = "Current password is incorrect.";
                }
            } catch (PDOException $e) {
                $error = "Database error occurred. Please try again.";
            }
        }
    }
}

// Get driver's performance statistics - only count earnings for completed bookings
try {
    $query = "SELECT 
                COUNT(*) as total_bookings,
                COUNT(CASE WHEN bookingstatus = 'completed' THEN 1 END) as completed_bookings,
                COUNT(CASE WHEN bookingstatus = 'pending' THEN 1 END) as pending_bookings,
                COUNT(CASE WHEN bookingstatus = 'confirmed' THEN 1 END) as confirmed_bookings,
                COALESCE(SUM(CASE WHEN bookingstatus = 'completed' THEN totalcost END), 0) as total_earnings,
                COALESCE(SUM(CASE WHEN bookingstatus = 'confirmed' THEN totalcost END), 0) as pending_earnings,
                AVG(CASE WHEN bookingstatus = 'completed' THEN totalcost END) as avg_booking_value,
                MAX(bookingdate) as last_booking
              FROM booking WHERE driverid = ?";
    $stmt = $db->prepare($query);
    $stmt->bindParam(1, $driver['driverid']);
    $stmt->execute();
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $stats = [
        'total_bookings' => 0,
        'completed_bookings' => 0,
        'pending_bookings' => 0,
        'confirmed_bookings' => 0,
        'total_earnings' => 0,
        'pending_earnings' => 0,
        'avg_booking_value' => 0,
        'last_booking' => null
    ];
}

// Get recent ratings
try {
    $query = "SELECT r.rating, r.review, r.created_at, u.username as student_name, u.full_name as student_full_name 
              FROM rating r
              JOIN booking b ON r.bookingid = b.bookingid
              JOIN user u ON r.userid = u.userid
              WHERE r.driverid = ?
              ORDER BY r.created_at DESC
              LIMIT 3";
    $stmt = $db->prepare($query);
    $stmt->bindParam(1, $driver['driverid']);
    $stmt->execute();
    $recent_ratings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recent_ratings = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - RenToGo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        
        * {
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: #f8f9fa;
            margin: 0;
            padding: 0;
        }

        /* Sidebar Styles */
        .dashboard-sidebar {
            width: 250px;
            min-height: 100vh;
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1000;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }

        .sidebar-nav {
            padding: 0;
        }

        .sidebar-nav .nav-link {
            color: rgba(255,255,255,0.8);
            padding: 1rem 1.5rem;
            border-radius: 0;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
        }

        .sidebar-nav .nav-link:hover,
        .sidebar-nav .nav-link.active {
            color: white;
            background-color: rgba(255,255,255,0.1);
            border-left-color: #fff;
        }

        .sidebar-nav .nav-link i {
            width: 20px;
            margin-right: 10px;
        }

        /* Main Content */
        .flex-grow-1 {
            margin-left: 250px;
            width: calc(100% - 250px);
            min-height: 100vh;
        }

        /* Header */
        .dashboard-header {
            background: white;
            border-bottom: 1px solid #dee2e6;
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        /* Content */
        .dashboard-content {
            padding: 2rem;
        }

        /* Cards */
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            transition: transform 0.3s ease;
        }

        .card:hover {
            transform: translateY(-2px);
        }

        .card-header {
            background: white;
            border-bottom: 1px solid #eee;
            border-radius: 15px 15px 0 0 !important;
        }

        /* Status badges */
        .status-available { background-color: #28a745; }
        .status-not-available { background-color: #6c757d; }
        .status-maintenance { background-color: #ffc107; color: #000; }

        /* Forms */
        .form-control, .form-select {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 0.75rem 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: #28a745;
            box-shadow: 0 0 0 0.2rem rgba(40, 167, 69, 0.1);
        }

        .form-control.is-invalid {
            border-color: #dc3545;
        }

        /* Buttons */
        .btn {
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        /* Profile specific styles */
        .stat-item {
            text-align: center;
            padding: 1rem 0;
        }

        .profile-avatar {
            display: flex;
            justify-content: center;
            align-items: center;
        }

        /* Animations */
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Rating stars */
        .text-warning .bi-star-fill {
            color: #ffc107 !important;
        }

        .text-warning .bi-star {
            color: #dee2e6 !important;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .dashboard-sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            
            .dashboard-sidebar.show {
                transform: translateX(0);
            }
            
            .flex-grow-1 {
                margin-left: 0;
                width: 100%;
            }
            
            .dashboard-content {
                padding: 1rem;
            }
        }

        /* Logout Button Styling */
        .logout-btn {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%) !important;
            border: none !important;
            border-radius: 10px !important;
            padding: 0.75rem 1rem !important;
            font-weight: 600 !important;
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.3) !important;
            transition: all 0.3s ease !important;
            width: 100% !important;
            color: white !important;
        }

        .logout-btn:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 6px 20px rgba(220, 53, 69, 0.4) !important;
            color: white !important;
        }

        /* Password strength indicator */
        .password-strength {
            height: 5px;
            border-radius: 3px;
            margin-top: 5px;
            transition: all 0.3s ease;
        }

        .password-strength.weak { background-color: #dc3545; }
        .password-strength.medium { background-color: #ffc107; }
        .password-strength.strong { background-color: #28a745; }

         /* Logout Confirmation Modal */
        .logout-confirmation {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            backdrop-filter: blur(5px);
        }

        .logout-confirmation.show {
            display: flex;
            animation: fadeInModal 0.3s ease;
        }

        .confirmation-box {
            background: white;
            padding: 2rem;
            border-radius: 15px;
            text-align: center;
            max-width: 400px;
            width: 90%;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            transform: scale(0.9);
            transition: transform 0.3s ease;
        }

        .logout-confirmation.show .confirmation-box {
            transform: scale(1);
        }

        @keyframes fadeInModal {
            from { opacity: 0; }
            to { opacity: 1; }
        }

    </style>
</head>
<body>
    <div class="d-flex">
        <!-- Sidebar -->
        <div class="dashboard-sidebar">
            <div class="p-3 border-bottom border-light border-opacity-25">
                <h5 class="text-white mb-0 fw-bold">
                    <i class="bi bi-car-front-fill"></i> RenToGo
                </h5>
                <small class="text-white-50">Driver Portal</small>
            </div>
            <nav class="sidebar-nav">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="bookings.php">
                            <i class="bi bi-calendar-check"></i> Manage Bookings
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="car-details.php">
                            <i class="bi bi-car-front"></i> Car Details
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="earnings.php">
                            <i class="bi bi-currency-dollar"></i> Earnings
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="profile.php">
                            <i class="bi bi-person"></i> Profile
                        </a>
                    </li>
                    <li class="nav-item mt-4">
                        <div class="px-3 pb-3">
                            <button class="btn btn-danger w-100 logout-btn" onclick="showLogoutConfirmation()">
                                <i class="bi bi-box-arrow-right me-2"></i> 
                                <span>Logout</span>
                            </button>
                        </div>
                    </li>
                </ul>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="flex-grow-1">
            <!-- Header -->
            <div class="dashboard-header">
                <div class="container-fluid">
                    <div class="row align-items-center">
                        <div class="col">
                            <h4 class="mb-0">My Profile</h4>
                            <small class="text-muted">Manage account information for <?php echo htmlspecialchars($driver['full_name'] ?? $driver['username']); ?></small>
                        </div>
                        <div class="col-auto">
                            <a href="dashboard.php" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left"></i> Back to Dashboard
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <div class="container-fluid">
                    
                    <?php if ($message): ?>
                        <div class="alert alert-success alert-dismissible fade show">
                            <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($message); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show">
                            <i class="bi bi-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <div class="row g-4">
                        <!-- Profile Information -->
                        <div class="col-lg-8">
                            <div class="card fade-in">
                                <div class="card-header">
                                    <h5 class="mb-0">
                                        <i class="bi bi-person"></i> Personal Information
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST" action="" id="profileForm">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label for="username" class="form-label">Username *</label>
                                                <input type="text" class="form-control" id="username" name="username" 
                                                       value="<?php echo htmlspecialchars($driver['username']); ?>" required>
                                                <div class="invalid-feedback"></div>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="full_name" class="form-label">Full Name *</label>
                                                <input type="text" class="form-control" id="full_name" name="full_name" 
                                                       value="<?php echo htmlspecialchars($driver['full_name'] ?? ''); ?>" required>
                                                <div class="invalid-feedback"></div>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="email" class="form-label">Email Address *</label>
                                                <input type="email" class="form-control" id="email" name="email" 
                                                       value="<?php echo htmlspecialchars($driver['email']); ?>" required>
                                                <div class="invalid-feedback"></div>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="notel" class="form-label">Phone Number *</label>
                                                <input type="tel" class="form-control" id="notel" name="notel" 
                                                       value="<?php echo htmlspecialchars($driver['notel']); ?>" required>
                                                <div class="invalid-feedback"></div>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="gender" class="form-label">Gender</label>
                                                <input type="text" class="form-control" id="gender" 
                                                       value="<?php echo htmlspecialchars($driver['gender']); ?>" readonly>
                                                <small class="text-muted">Contact admin to change gender information</small>
                                            </div>
                                        </div>
                                        
                                        <hr class="my-4">
                                        
                                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                            <button type="submit" name="update_profile" class="btn btn-primary">
                                                <i class="bi bi-check-circle"></i> Update Profile
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            <!-- Change Password -->
                            <div class="card mt-4 fade-in">
                                <div class="card-header">
                                    <h5 class="mb-0">
                                        <i class="bi bi-shield-lock"></i> Change Password
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST" action="" id="passwordForm">
                                        <div class="row g-3">
                                            <div class="col-12">
                                                <label for="current_password" class="form-label">Current Password *</label>
                                                <input type="password" class="form-control" id="current_password" name="current_password" required>
                                                <div class="invalid-feedback"></div>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="new_password" class="form-label">New Password *</label>
                                                <input type="password" class="form-control" id="new_password" name="new_password" required>
                                                <div class="password-strength" id="passwordStrength"></div>
                                                <small class="text-muted">Minimum 6 characters</small>
                                                <div class="invalid-feedback"></div>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="confirm_password" class="form-label">Confirm New Password *</label>
                                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                                <div class="invalid-feedback"></div>
                                            </div>
                                        </div>
                                        
                                        <hr class="my-4">
                                        
                                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                            <button type="submit" name="change_password" class="btn btn-warning">
                                                <i class="bi bi-key"></i> Change Password
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Profile Summary & Statistics -->
                        <div class="col-lg-4">
                            <!-- Profile Summary -->
                            <div class="card mb-4 fade-in">
                                <div class="card-header">
                                    <h6 class="mb-0">
                                        <i class="bi bi-person-circle"></i> Driver Profile
                                    </h6>
                                </div>
                                <div class="card-body text-center">
                                    <div class="profile-avatar mb-3">
                                        <i class="bi bi-person-circle text-primary" style="font-size: 5rem;"></i>
                                    </div>
                                    <h5 class="mb-1"><?php echo htmlspecialchars($driver['full_name'] ?? $driver['username']); ?></h5>
                                    <p class="text-muted mb-2">@<?php echo htmlspecialchars($driver['username']); ?></p>
                                    <p class="text-muted mb-2"><?php echo htmlspecialchars($driver['licensenumber']); ?></p>
                                    <span class="badge bg-success"><?php echo htmlspecialchars($driver['carmodel']); ?></span>
                                    <br>
                                    <span class="badge bg-info mt-2"><?php echo htmlspecialchars($driver['plate']); ?></span>
                                    <br>
                                    <span class="badge status-<?php echo str_replace(' ', '-', $driver['status']); ?> mt-2">
                                        <?php echo ucfirst(str_replace('_', ' ', $driver['status'])); ?>
                                    </span>
                                </div>
                            </div>

                            <!-- Performance Statistics -->
                            <div class="card mb-4 fade-in">
                                <div class="card-header">
                                    <h6 class="mb-0">
                                        <i class="bi bi-bar-chart"></i> Performance Statistics
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3 text-center">
                                        <div class="col-6">
                                            <div class="stat-item">
                                                <h4 class="text-primary mb-0"><?php echo $stats['total_bookings'] ?? 0; ?></h4>
                                                <small class="text-muted">Total Bookings</small>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="stat-item">
                                                <h4 class="text-success mb-0"><?php echo $stats['completed_bookings'] ?? 0; ?></h4>
                                                <small class="text-muted">Completed</small>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="stat-item">
                                                <h4 class="text-warning mb-0"><?php echo $stats['pending_bookings'] ?? 0; ?></h4>
                                                <small class="text-muted">Pending</small>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="stat-item">
                                                <h4 class="text-info mb-0">
                                                    <?php echo ($driver['rating'] > 0) ? number_format($driver['rating'], 1) : 'N/A'; ?>
                                                </h4>
                                                <small class="text-muted">Rating</small>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="stat-item">
                                                <h4 class="text-success mb-0">RM <?php echo number_format($stats['total_earnings'] ?? 0, 2); ?></h4>
                                                <small class="text-muted">Paid Earnings</small>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="stat-item">
                                                <h4 class="text-warning mb-0">RM <?php echo number_format($stats['pending_earnings'] ?? 0, 2); ?></h4>
                                                <small class="text-muted">Pending Payment</small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <?php if ($stats['last_booking']): ?>
                                    <hr>
                                    <div class="text-center">
                                        <small class="text-muted">
                                            Last booking: <?php echo date('d M Y', strtotime($stats['last_booking'])); ?>
                                        </small>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Vehicle Information -->
                            <div class="card mb-4 fade-in">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0">
                                        <i class="bi bi-car-front"></i> Vehicle Information
                                    </h6>
                                    <a href="car-details.php" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-pencil"></i> Edit
                                    </a>
                                </div>
                                <div class="card-body">
                                    <ul class="list-unstyled mb-0">
                                        <li class="mb-2">
                                            <i class="bi bi-car-front text-muted me-2"></i>
                                            <strong><?php echo htmlspecialchars($driver['carmodel']); ?></strong>
                                        </li>
                                        <li class="mb-2">
                                            <i class="bi bi-credit-card text-muted me-2"></i>
                                            <?php echo htmlspecialchars($driver['plate']); ?>
                                        </li>
                                        <li class="mb-2">
                                            <i class="bi bi-people text-muted me-2"></i>
                                            <?php echo $driver['capacity']; ?> passengers
                                        </li>
                                        <li class="mb-2">
                                            <i class="bi bi-tag text-muted me-2"></i>
                                            <?php echo htmlspecialchars($driver['car_type']); ?>
                                        </li>
                                        <?php if (isset($driver['price_per_hour'])): ?>
                                        <li class="mb-0">
                                            <i class="bi bi-currency-dollar text-muted me-2"></i>
                                            RM <?php echo number_format($driver['price_per_hour'], 2); ?>/hour
                                        </li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </div>

                            <!-- Account Information -->
                            <div class="card fade-in">
                                <div class="card-header">
                                    <h6 class="mb-0">
                                        <i class="bi bi-info-circle"></i> Account Information
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <ul class="list-unstyled mb-0">
                                        <li class="mb-2">
                                            <i class="bi bi-calendar text-muted me-2"></i>
                                            <small>Member since <?php echo date('M Y', strtotime($driver['datehired'])); ?></small>
                                        </li>
                                        <li class="mb-2">
                                            <i class="bi bi-shield-check text-muted me-2"></i>
                                            <small>Account Status: <span class="badge bg-success">Active</span></small>
                                        </li>
                                        <li class="mb-2">
                                            <i class="bi bi-card-text text-muted me-2"></i>
                                            <small>License: <?php echo htmlspecialchars($driver['licensenumber']); ?></small>
                                        </li>
                                        <li class="mb-0">
                                            <i class="bi bi-person-badge text-muted me-2"></i>
                                            <small>Driver ID: <?php echo $driver['driverid']; ?></small>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Ratings -->
                    <?php if (!empty($recent_ratings)): ?>
                    <div class="row mt-4">
                        <div class="col-12">
                            <div class="card fade-in">
                                <div class="card-header">
                                    <h5 class="mb-0">
                                        <i class="bi bi-star"></i> Recent Ratings & Reviews
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <?php foreach ($recent_ratings as $rating): ?>
                                        <div class="col-md-4 mb-3">
                                            <div class="border rounded p-3 h-100">
                                                <div class="d-flex justify-content-between align-items-start mb-2">
                                                    <div>
                                                        <h6 class="mb-0"><?php echo htmlspecialchars($rating['student_full_name'] ?? $rating['student_name']); ?></h6>
                                                        <small class="text-muted">
                                                            <?php echo date('d M Y', strtotime($rating['created_at'])); ?>
                                                        </small>
                                                    </div>
                                                    <div class="text-warning">
                                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                                            <i class="bi bi-star<?php echo $i <= $rating['rating'] ? '-fill' : ''; ?>"></i>
                                                        <?php endfor; ?>
                                                    </div>
                                                </div>
                                                <?php if ($rating['review']): ?>
                                                    <p class="mb-0 text-muted small">
                                                        "<?php echo htmlspecialchars($rating['review']); ?>"
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

     <!-- Logout Confirmation Modal -->
    <div class="logout-confirmation" id="logoutConfirmation">
        <div class="confirmation-box">
            <div class="mb-3">
                <i class="bi bi-exclamation-triangle text-warning" style="font-size: 3rem;"></i>
            </div>
            <h5 class="mb-3">Confirm Logout</h5>
            <p class="text-muted mb-4">Are you sure you want to logout from the driver portal?</p>
            <div class="d-flex gap-2 justify-content-center">
                <button class="btn btn-secondary" onclick="hideLogoutConfirmation()">
                    <i class="bi bi-x-lg"></i> Cancel
                </button>
                <a href="../auth/logout.php" class="btn btn-danger">
                    <i class="bi bi-box-arrow-right"></i> Yes, Logout
                </a>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Logout confirmation functions
        function showLogoutConfirmation() {
            const modal = document.getElementById('logoutConfirmation');
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function hideLogoutConfirmation() {
            const modal = document.getElementById('logoutConfirmation');
            modal.classList.remove('show');
            document.body.style.overflow = 'auto';
        }
        
        // Close modal when clicking outside the confirmation box
        document.getElementById('logoutConfirmation').addEventListener('click', function(e) {
            if (e.target === this) {
                hideLogoutConfirmation();
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                hideLogoutConfirmation();
            }
        });
        
        // Password strength checker
        function checkPasswordStrength(password) {
            let strength = 0;
            const strengthIndicator = document.getElementById('passwordStrength');
            
            if (password.length >= 8) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            
            strengthIndicator.style.width = (strength * 25) + '%';
            strengthIndicator.className = 'password-strength ';
            
            if (strength <= 1) {
                strengthIndicator.className += 'weak';
            } else if (strength <= 2) {
                strengthIndicator.className += 'medium';
            } else {
                strengthIndicator.className += 'strong';
            }
        }

        // Form validation functions
        function validateField(field, condition, message) {
            const feedback = field.parentElement.querySelector('.invalid-feedback');
            if (condition) {
                field.classList.remove('is-invalid');
                field.classList.add('is-valid');
                if (feedback) feedback.textContent = '';
                return true;
            } else {
                field.classList.remove('is-valid');
                field.classList.add('is-invalid');
                if (feedback) feedback.textContent = message;
                return false;
            }
        }

        // Password form validation
        document.getElementById('passwordForm').addEventListener('submit', function(e) {
            const currentPassword = document.getElementById('current_password');
            const newPassword = document.getElementById('new_password');
            const confirmPassword = document.getElementById('confirm_password');
            
            let isValid = true;
            
            // Validate current password
            if (!validateField(currentPassword, currentPassword.value.length > 0, 'Current password is required')) {
                isValid = false;
            }
            
            // Validate new password
            if (!validateField(newPassword, newPassword.value.length >= 6, 'Password must be at least 6 characters')) {
                isValid = false;
            }
            
            // Validate password confirmation
            if (!validateField(confirmPassword, newPassword.value === confirmPassword.value, 'Passwords do not match')) {
                isValid = false;
            }
            
            if (!isValid) {
                e.preventDefault();
            }
        });

        // Profile form validation
        document.getElementById('profileForm').addEventListener('submit', function(e) {
            const username = document.getElementById('username');
            const fullName = document.getElementById('full_name');
            const email = document.getElementById('email');
            const phone = document.getElementById('notel');
            
            let isValid = true;
            
            // Validate username
            if (!validateField(username, username.value.trim().length > 0, 'Username is required')) {
                isValid = false;
            }
            
            // Validate full name
            if (!validateField(fullName, fullName.value.trim().length > 0, 'Full name is required')) {
                isValid = false;
            }
            
            // Validate email
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!validateField(email, emailRegex.test(email.value), 'Please enter a valid email address')) {
                isValid = false;
            }
            
            // Validate phone
            const phoneRegex = /^[\+]?[0-9\-\s()]+$/;
            if (!validateField(phone, phoneRegex.test(phone.value), 'Please enter a valid phone number')) {
                isValid = false;
            }
            
            if (!isValid) {
                e.preventDefault();
            }
        });

        // Real-time validation
        document.getElementById('new_password').addEventListener('input', function() {
            checkPasswordStrength(this.value);
            const confirmPassword = document.getElementById('confirm_password');
            if (confirmPassword.value) {
                validateField(confirmPassword, this.value === confirmPassword.value, 'Passwords do not match');
            }
        });

        document.getElementById('confirm_password').addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            validateField(this, newPassword === this.value, 'Passwords do not match');
        });

        document.getElementById('email').addEventListener('blur', function() {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            validateField(this, emailRegex.test(this.value), 'Please enter a valid email address');
        });

        document.getElementById('notel').addEventListener('input', function() {
            // Allow only numbers, +, -, space, and parentheses
            this.value = this.value.replace(/[^\d\+\-\s()]/g, '');
        });

        // Add fade-in animation on load
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.fade-in');
            cards.forEach((card, index) => {
                card.style.animationDelay = (index * 0.1) + 's';
            });
        });
    </script>
</body>
</html>