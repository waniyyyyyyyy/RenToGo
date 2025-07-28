<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a student
if (!isset($_SESSION['userid']) || $_SESSION['role'] !== 'student') {
    header("Location: ../auth/login.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();
$message = '';
$error = '';

// Get student info
$query = "SELECT u.*, s.* FROM user u 
          JOIN student s ON u.userid = s.userid 
          WHERE u.userid = ?";
$stmt = $db->prepare($query);
$stmt->bindParam(1, $_SESSION['userid']);
$stmt->execute();
$student = $stmt->fetch(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_profile'])) {
        $username = trim($_POST['username']);
        $full_name = trim($_POST['full_name']); // 
        $email = trim($_POST['email']);
        $notel = trim($_POST['notel']);
        $faculty = $_POST['faculty'];
        $year_of_study = (int)$_POST['year_of_study'];
        
        // Include full_name in validation
        if (empty($username) || empty($full_name) || empty($email) || empty($notel)) {
            $error = "Please fill in all required fields.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
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
                    $db->beginTransaction();
                    
                    // Include full_name in user table update
                    $query = "UPDATE user SET username = ?, full_name = ?, email = ?, notel = ? WHERE userid = ?";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(1, $username);
                    $stmt->bindParam(2, $full_name);
                    $stmt->bindParam(3, $email);
                    $stmt->bindParam(4, $notel);
                    $stmt->bindParam(5, $_SESSION['userid']);
                    $stmt->execute();
                    
                    // Update student table
                    $query = "UPDATE student SET faculty = ?, year_of_study = ? WHERE userid = ?";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(1, $faculty);
                    $stmt->bindParam(2, $year_of_study);
                    $stmt->bindParam(3, $_SESSION['userid']);
                    $stmt->execute();
                    
                    $db->commit();
                    $message = "Profile updated successfully!";
                    
                    // Update session username and full_name
                    $_SESSION['username'] = $username;
                    $_SESSION['full_name'] = $full_name; // Store full_name in session
                    
                    // Refresh student data
                    $query = "SELECT u.*, s.* FROM user u JOIN student s ON u.userid = s.userid WHERE u.userid = ?";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(1, $_SESSION['userid']);
                    $stmt->execute();
                    $student = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                } catch (Exception $e) {
                    $db->rollback();
                    $error = "Failed to update profile. Please try again.";
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
        }
    }
}

// Get student's booking statistics - only count completed bookings for total spent
$query = "SELECT 
            COUNT(*) as total_bookings,
            COUNT(CASE WHEN bookingstatus = 'completed' THEN 1 END) as completed_bookings,
            COALESCE(SUM(CASE WHEN bookingstatus = 'completed' THEN totalcost END), 0) as total_spent,
            MAX(bookingdate) as last_booking
          FROM booking WHERE userid = ?";
$stmt = $db->prepare($query);
$stmt->bindParam(1, $_SESSION['userid']);
$stmt->execute();
$stats = $stmt->fetch(PDO::FETCH_ASSOC);
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
            background: linear-gradient(135deg, #6f42c1 0%, #8b5cf6 100%);
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
            border: 1px solid rgba(111, 66, 193, 0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }

        .card-header {
            background: white;
            border-bottom: 1px solid #eee;
            border-radius: 15px 15px 0 0 !important;
        }

        /* Forms */
        .form-control, .form-select {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 0.75rem 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: #6f42c1;
            box-shadow: 0 0 0 0.2rem rgba(111, 66, 193, 0.1);
        }

        /* Buttons */
        .btn {
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .btn-primary {
            background: linear-gradient(135deg, #6f42c1 0%, #8b5cf6 100%);
            border: none;
            box-shadow: 0 4px 15px rgba(111, 66, 193, 0.3);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #5a2d9a 0%, #7c3aed 100%);
            box-shadow: 0 6px 20px rgba(111, 66, 193, 0.4);
        }

        .btn-outline-secondary {
            border: 2px solid #6c757d;
            color: #6c757d;
        }

        .btn-outline-secondary:hover {
            background-color: #6c757d;
            border-color: #6c757d;
        }

        .btn-warning {
            background: linear-gradient(135deg, #ffc107 0%, #ffca2c 100%);
            border: none;
            color: #212529;
            box-shadow: 0 4px 15px rgba(255, 193, 7, 0.3);
        }

        .btn-warning:hover {
            background: linear-gradient(135deg, #e0a800 0%, #ffb700 100%);
            box-shadow: 0 6px 20px rgba(255, 193, 7, 0.4);
        }

        /* Stats styling */
        .stat-item h4 {
            font-weight: bold;
        }
        
        .stat-item small {
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Profile avatar styling */
        .profile-avatar {
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .avatar-circle {
            width: 120px;
            height: 120px;
            background: linear-gradient(135deg, #6f42c1 0%, #8b5cf6 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 25px rgba(111, 66, 193, 0.3);
            transition: transform 0.3s ease;
        }

        .avatar-circle:hover {
            transform: scale(1.05);
        }

        .avatar-circle i {
            font-size: 3.5rem;
            color: white;
        }

        .faculty-badge {
            font-size: 0.75rem !important;
            max-width: 100%;
            word-wrap: break-word;
            white-space: normal;
            line-height: 1.3;
        }

        /* Validation styles */
        .form-control.is-invalid {
            border-color: #dc3545;
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.1);
        }

        .form-control.is-valid {
            border-color: #28a745;
            box-shadow: 0 0 0 0.2rem rgba(40, 167, 69, 0.1);
        }

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
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
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

        /* Additional styling for better visual hierarchy */
        h4, h5, h6 {
            color: #374151;
            font-weight: 600;
        }

        .text-primary {
            color: #6f42c1 !important;
        }

        .text-muted {
            color: #6b7280 !important;
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
                <small class="text-white-50">Student Portal</small>
            </div>
            <nav class="sidebar-nav">
                <ul class="nav flex-column p-3">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="book-ride.php">
                            <i class="bi bi-plus-circle"></i> Book a Ride
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="my-bookings.php">
                            <i class="bi bi-list-ul"></i> My Bookings
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="browse-drivers.php">
                            <i class="bi bi-search"></i> Browse Drivers
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
                            <small class="text-muted">Manage your account information and settings</small>
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
                            <i class="bi bi-check-circle"></i> <?php echo $message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show">
                            <i class="bi bi-exclamation-circle"></i> <?php echo $error; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <div class="row g-4">
                        <!-- Profile Information -->
                        <div class="col-lg-8">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0">
                                        <i class="bi bi-person"></i> Personal Information
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST" action="">
                                        <div class="row g-3">
                                            <!-- full name field -->
                                            <div class="col-md-6">
                                                <label for="full_name" class="form-label">Full Name *</label>
                                                <input type="text" class="form-control" id="full_name" name="full_name" 
                                                       value="<?php echo htmlspecialchars($student['full_name']); ?>" required>
                                                <small class="text-muted">Your complete legal name</small>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="username" class="form-label">Username *</label>
                                                <input type="text" class="form-control" id="username" name="username" 
                                                       value="<?php echo htmlspecialchars($student['username']); ?>" required>
                                                <small class="text-muted">Used for login and display</small>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="email" class="form-label">Email Address *</label>
                                                <input type="email" class="form-control" id="email" name="email" 
                                                       value="<?php echo htmlspecialchars($student['email']); ?>" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="notel" class="form-label">Phone Number *</label>
                                                <input type="tel" class="form-control" id="notel" name="notel" 
                                                       value="<?php echo htmlspecialchars($student['notel']); ?>" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="gender" class="form-label">Gender</label>
                                                <input type="text" class="form-control" id="gender" 
                                                       value="<?php echo htmlspecialchars($student['gender']); ?>" readonly>
                                                <small class="text-muted">Contact admin to change gender information</small>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="student_number" class="form-label">Student Number</label>
                                                <input type="text" class="form-control" id="student_number" 
                                                       value="<?php echo htmlspecialchars($student['student_number']); ?>" readonly>
                                                <small class="text-muted">Student number cannot be changed</small>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="year_of_study" class="form-label">Year of Study *</label>
                                                <select class="form-control" id="year_of_study" name="year_of_study" required>
                                                    <option value="">Select Year</option>
                                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                                        <option value="<?php echo $i; ?>" <?php echo $student['year_of_study'] == $i ? 'selected' : ''; ?>>
                                                            Year <?php echo $i; ?>
                                                        </option>
                                                    <?php endfor; ?>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <label for="faculty" class="form-label">Faculty *</label>
                                                <select class="form-control" id="faculty" name="faculty" required>
                                                    <option value="">Select Faculty</option>
                                                    <option value="Faculty of Computer and Mathematical Sciences" <?php echo $student['faculty'] == 'Faculty of Computer and Mathematical Sciences' ? 'selected' : ''; ?>>
                                                        Faculty of Computer and Mathematical Sciences
                                                    </option>
                                                    <option value="Faculty of Business and Management" <?php echo $student['faculty'] == 'Faculty of Business and Management' ? 'selected' : ''; ?>>
                                                        Faculty of Business and Management
                                                    </option>
                                                    <option value="Faculty of Engineering" <?php echo $student['faculty'] == 'Faculty of Engineering' ? 'selected' : ''; ?>>
                                                        Faculty of Engineering
                                                    </option>
                                                    <option value="Faculty of Applied Sciences" <?php echo $student['faculty'] == 'Faculty of Applied Sciences' ? 'selected' : ''; ?>>
                                                        Faculty of Applied Sciences
                                                    </option>
                                                    <option value="Faculty of Education" <?php echo $student['faculty'] == 'Faculty of Education' ? 'selected' : ''; ?>>
                                                        Faculty of Education
                                                    </option>
                                                    <option value="Faculty of Law" <?php echo $student['faculty'] == 'Faculty of Law' ? 'selected' : ''; ?>>
                                                        Faculty of Law
                                                    </option>
                                                    <option value="Faculty of Medicine" <?php echo $student['faculty'] == 'Faculty of Medicine' ? 'selected' : ''; ?>>
                                                        Faculty of Medicine
                                                    </option>
                                                </select>
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
                            <div class="card mt-4">
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
                                            </div>
                                            <div class="col-md-6">
                                                <label for="new_password" class="form-label">New Password *</label>
                                                <input type="password" class="form-control" id="new_password" name="new_password" required>
                                                <small class="text-muted">Minimum 6 characters</small>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="confirm_password" class="form-label">Confirm New Password *</label>
                                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
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
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h6 class="mb-0">
                                        <i class="bi bi-person-circle"></i> Profile Summary
                                    </h6>
                                </div>
                                <div class="card-body text-center py-4">
                                    <div class="profile-avatar mb-4">
                                        <div class="avatar-circle">
                                            <i class="bi bi-person-fill"></i>
                                        </div>
                                    </div>
                                    <!-- Display full name -->
                                    <h5 class="mb-2 fw-bold"><?php echo htmlspecialchars($student['full_name']); ?></h5>
                                    <p class="text-muted mb-3 fs-6"><?php echo htmlspecialchars($student['student_number']); ?></p>
                                    <div class="mb-3">
                                        <span class="badge bg-primary px-3 py-2 mb-2 d-inline-block faculty-badge">
                                            <?php echo htmlspecialchars($student['faculty']); ?>
                                        </span>
                                    </div>
                                    <span class="badge bg-info px-3 py-2">Year <?php echo $student['year_of_study']; ?></span>
                                </div>
                            </div>

                            <!-- Account Statistics -->
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h6 class="mb-0">
                                        <i class="bi bi-bar-chart"></i> Account Statistics
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
                                        <div class="col-12">
                                            <div class="stat-item">
                                                <!-- UPDATED: Only shows total spent from completed bookings -->
                                                <h4 class="text-info mb-0">RM <?php echo number_format($stats['total_spent'] ?? 0, 2); ?></h4>
                                                <small class="text-muted">Total Spent (Completed Only)</small>
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

                            <!-- Account Information -->
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="mb-0">
                                        <i class="bi bi-info-circle"></i> Account Information
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <ul class="list-unstyled mb-0">
                                        <li class="mb-2">
                                            <i class="bi bi-calendar text-muted me-2"></i>
                                            <small>Member since <?php echo isset($student['created']) ? date('M Y', strtotime($student['created'])) : 'N/A'; ?></small>
                                        </li>
                                        <li class="mb-2">
                                            <i class="bi bi-shield-check text-muted me-2"></i>
                                            <small>Account Status: <span class="badge bg-success">Active</span></small>
                                        </li>
                                        <li class="mb-0">
                                            <i class="bi bi-person-badge text-muted me-2"></i>
                                            <small>User ID: <?php echo $student['userid']; ?></small>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
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
            <p class="text-muted mb-4">Are you sure you want to logout from the student portal?</p>
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
        // Password confirmation validation
        document.getElementById('passwordForm').addEventListener('submit', function(e) {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (newPassword !== confirmPassword) {
                e.preventDefault();
                alert('New passwords do not match!');
                return false;
            }
            
            if (newPassword.length < 6) {
                e.preventDefault();
                alert('New password must be at least 6 characters long!');
                return false;
            }
        });

        // Real-time password confirmation check
        document.getElementById('confirm_password').addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;
            
            if (confirmPassword && newPassword !== confirmPassword) {
                this.classList.add('is-invalid');
                this.setCustomValidity('Passwords do not match');
            } else {
                this.classList.remove('is-invalid');
                this.setCustomValidity('');
            }
        });

        // Email validation
        document.getElementById('email').addEventListener('blur', function() {
            const email = this.value;
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            
            if (email && !emailRegex.test(email)) {
                this.classList.add('is-invalid');
                this.setCustomValidity('Please enter a valid email address');
            } else {
                this.classList.remove('is-invalid');
                this.setCustomValidity('');
            }
        });

        // Phone number formatting
        document.getElementById('notel').addEventListener('input', function() {
            // Remove non-numeric characters except + and -
            this.value = this.value.replace(/[^\d\+\-]/g, '');
        });

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
    </script>
</body>
</html>