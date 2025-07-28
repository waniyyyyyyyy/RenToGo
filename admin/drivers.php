<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['userid']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

// Establish database connection first
$database = new Database();
$db = $database->getConnection();
$message = '';

// Check database connection
if (!$db) {
    die("Database connection failed. Please check your database configuration in config/database.php");
}

// Test database connection
try {
    $test_query = "SELECT 1";
    $test_stmt = $db->prepare($test_query);
    $test_stmt->execute();
} catch (Exception $e) {
    die("Database connection test failed: " . $e->getMessage());
}

// Handle driver actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Debug: Log all POST data
    error_log("POST data received: " . print_r($_POST, true));
    
    if (isset($_POST['update_driver_status'])) {
        $driverid = intval($_POST['driverid']);
        $new_status = trim($_POST['new_status']);
        
        // Debug logging
        error_log("Updating driver status - ID: $driverid, Status: $new_status");
        
        // Validate inputs
        if ($driverid <= 0) {
            $message = "Error: Invalid driver ID ($driverid).";
            error_log("Invalid driver ID: $driverid");
        } elseif (empty($new_status) || !in_array($new_status, ['available', 'not available', 'maintenance'])) {
            $message = "Error: Invalid status value ($new_status).";
            error_log("Invalid status: $new_status");
        } else {
            try {
                // Check if driver exists first
                $check_query = "SELECT driverid, status FROM driver WHERE driverid = ?";
                $check_stmt = $db->prepare($check_query);
                $check_stmt->bindValue(1, $driverid, PDO::PARAM_INT);
                $check_stmt->execute();
                $current_driver = $check_stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$current_driver) {
                    $message = "Error: Driver not found (ID: $driverid).";
                    error_log("Driver not found: $driverid");
                } else {
                    error_log("Current driver status: " . $current_driver['status'] . ", New status: $new_status");
                    
                    $query = "UPDATE driver SET status = ? WHERE driverid = ?";
                    $stmt = $db->prepare($query);
                    $stmt->bindValue(1, $new_status, PDO::PARAM_STR);
                    $stmt->bindValue(2, $driverid, PDO::PARAM_INT);
                    
                    if ($stmt->execute()) {
                        $affected_rows = $stmt->rowCount();
                        error_log("Update executed, affected rows: $affected_rows");
                        
                        if ($affected_rows > 0) {
                            $message = "Driver status updated successfully to '$new_status'!";
                            error_log("Driver status updated successfully");
                            // Redirect to prevent form resubmission
                            header("Location: drivers.php?updated=1&status=" . urlencode($new_status));
                            exit();
                        } else {
                            $message = "Error: Status unchanged (possibly same value).";
                            error_log("No rows affected - status might be the same");
                        }
                    } else {
                        $message = "Error: Failed to execute update query.";
                        error_log("Failed to execute update query");
                    }
                }
            } catch (Exception $e) {
                $message = "Error updating driver status: " . $e->getMessage();
                error_log("Driver status update error: " . $e->getMessage());
            }
        }
    }
    
    if (isset($_POST['approve_driver'])) {
        $userid = $_POST['userid'];
        $driverid = $_POST['driverid'];
        
        try {
            $db->beginTransaction();
            
            // Update user status to active
            $query = "UPDATE user SET status = 'active' WHERE userid = ?";
            $stmt = $db->prepare($query);
            $stmt->bindParam(1, $userid);
            $stmt->execute();
            
            // Update driver status to available
            $query = "UPDATE driver SET status = 'available' WHERE driverid = ?";
            $stmt = $db->prepare($query);
            $stmt->bindParam(1, $driverid);
            $stmt->execute();
            
            $db->commit();
            $message = "Driver approved successfully!";
        } catch (Exception $e) {
            $db->rollback();
            $message = "Error approving driver: " . $e->getMessage();
        }
    }
}

// Check for update success message
if (isset($_GET['updated']) && $_GET['updated'] == '1') {
    $updated_status = isset($_GET['status']) ? $_GET['status'] : 'unknown';
    $message = "Driver status updated successfully to '$updated_status'!";
}

// Available car types 
$available_car_types = [
    'Sedan' => 'Standard 4-door passenger car',
    'Hatchback' => 'Compact car with rear door',
    'SUV' => 'Sport Utility Vehicle',
    'MPV' => 'Multi-Purpose Vehicle',
    'Pickup' => 'Pickup truck',
    'Van' => 'Commercial or passenger van',
    'Coupe' => '2-door sports car'
];

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$car_type_filter = isset($_GET['car_type']) ? $_GET['car_type'] : '';
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query with filters - Updated revenue calculation to exclude cancelled bookings
$query = "SELECT u.userid, u.username, u.email, u.notel, u.gender, u.status as user_status, u.created,
                 d.driverid, d.licensenumber, d.carmodel, d.plate, d.capacity, d.car_type, 
                 d.status as driver_status, d.datehired,
                 COUNT(b.bookingid) as total_bookings,
                 COUNT(CASE WHEN b.bookingstatus = 'completed' THEN 1 END) as completed_bookings,
                 SUM(CASE WHEN b.bookingstatus = 'completed' THEN b.totalcost ELSE 0 END) as total_earnings
          FROM user u
          JOIN driver d ON u.userid = d.userid
          LEFT JOIN booking b ON d.driverid = b.driverid
          WHERE 1=1";

$params = [];

if ($status_filter) {
    if ($status_filter == 'pending') {
        $query .= " AND u.status = 'inactive'";
    } elseif ($status_filter == 'busy') {
        $query .= " AND d.status = 'not available'";
    } elseif ($status_filter == 'offline') {
        $query .= " AND d.status = 'maintenance'";
    } else {
        $query .= " AND d.status = ?";
        $params[] = $status_filter;
    }
}

if ($car_type_filter) {
    $query .= " AND d.car_type = ?";
    $params[] = $car_type_filter;
}

if ($search_query) {
    $query .= " AND (u.username LIKE ? OR d.carmodel LIKE ? OR d.plate LIKE ?)";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
}

$query .= " GROUP BY u.userid, d.driverid ORDER BY d.datehired DESC";

try {
    $stmt = $db->prepare($query);
    foreach ($params as $index => $param) {
        $stmt->bindParam($index + 1, $param);
    }
    $stmt->execute();
    $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $drivers = [];
    $message = "Error fetching drivers: " . $e->getMessage();
    error_log("Driver query error: " . $e->getMessage());
}

// Get driver statistics
try {
    $query = "SELECT 
                COUNT(*) as total_drivers,
                COUNT(CASE WHEN d.status = 'available' THEN 1 END) as available_drivers,
                COUNT(CASE WHEN d.status = 'not available' THEN 1 END) as busy_drivers,
                COUNT(CASE WHEN d.status = 'maintenance' THEN 1 END) as offline_drivers,
                COUNT(CASE WHEN u.status = 'inactive' THEN 1 END) as pending_drivers
              FROM user u
              JOIN driver d ON u.userid = d.userid";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Ensure stats has default values if query fails
    if (!$stats) {
        $stats = [
            'total_drivers' => 0,
            'available_drivers' => 0,
            'busy_drivers' => 0,
            'offline_drivers' => 0,
            'pending_drivers' => 0
        ];
    }
} catch (Exception $e) {
    $stats = [
        'total_drivers' => 0,
        'available_drivers' => 0,
        'busy_drivers' => 0,
        'offline_drivers' => 0,
        'pending_drivers' => 0
    ];
    error_log("Driver stats query error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Drivers - RenToGo Admin</title>
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

        .dashboard-sidebar {
            width: 250px;
            min-height: 100vh;
            background: linear-gradient(135deg, #0d6efd 0%, #0056b3 100%);
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
        .main-content {
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
            max-width: 100%;
        }

        /* Cards */
        .stat-card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
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
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.1);
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

        /* Table Improvements */
        .table {
            border-collapse: separate;
            border-spacing: 0;
        }
        
        .table th {
            border-top: none;
            font-weight: 600;
            color: #495057;
            background-color: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
            padding: 1rem 0.75rem;
            font-size: 0.9rem;
        }

        .table td {
            vertical-align: middle;
            border-color: #f0f0f0;
            padding: 1rem 0.75rem;
        }

        .table tr:hover {
            background-color: #f8f9fa;
            transition: background-color 0.2s ease;
        }

        .driver-avatar {
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, #28a745, #20c997);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 0.9rem;
            flex-shrink: 0;
        }

        /* Status badges */
        .badge {
            font-size: 0.75em;
            font-weight: 500;
            letter-spacing: 0.5px;
            padding: 0.5em 0.75em;
        }

        .status-available { background-color: #28a745 !important; }
        .status-not-available { background-color: #ffc107 !important; color: #000 !important; }
        .status-maintenance { background-color: #6c757d !important; }
        .status-pending { background-color: #fd7e14 !important; }

        /* Button group improvements */
        .btn-group .btn {
            border-radius: 6px !important;
            margin-right: 2px;
        }

        .btn-group .btn:last-child {
            margin-right: 0;
        }

        .btn-sm {
            padding: 0.4rem 0.7rem;
            font-size: 0.8rem;
        }

        .logout-btn {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%) !important;
            border: none !important;
            border-radius: 10px !important;
            padding: 0.75rem 1rem !important;
            font-weight: 600 !important;
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.3) !important;
            transition: all 0.3s ease !important;
        }

        .logout-btn:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 6px 20px rgba(220, 53, 69, 0.4) !important;
        }

        /* Animations */
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
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
            
            .main-content {
                margin-left: 0;
                width: 100%;
            }
            
            .dashboard-content {
                padding: 1rem;
            }

            /* Stack buttons vertically on mobile */
            .header-buttons {
                flex-direction: column !important;
                gap: 0.5rem !important;
            }

            .header-buttons > * {
                width: 100% !important;
            }
        }

        /* Container fixes */
        .container-fluid {
            padding-left: 1rem;
            padding-right: 1rem;
            max-width: 100%;
        }

        .row {
            margin-left: 0;
            margin-right: 0;
        }

        .col, .col-auto, .col-md, .col-md-3, .col-md-4 {
            padding-left: 0.5rem;
            padding-right: 0.5rem;
        }

        /* Statistics cards responsive spacing */
        @media (min-width: 768px) {
            .stat-card .card-body {
                padding: 1.5rem 1rem;
            }
        }

        @media (max-width: 767px) {
            .stat-card .card-body {
                padding: 1rem 0.5rem;
            }
        }

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

        /* Header buttons styling */
        .header-buttons {
            display: flex;
            gap: 0.75rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .header-buttons .dropdown {
            flex-shrink: 0;
        }

        /* Ensure dropdowns work properly */
        .dropdown-menu {
            border-radius: 10px;
            border: none;
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            z-index: 1050;
        }

        .dropdown-item {
            padding: 0.7rem 1rem;
            transition: all 0.2s ease;
        }

        .dropdown-item:hover {
            background-color: #f8f9fa;
            transform: translateX(5px);
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
                <small class="text-white-50">Admin Portal</small>
            </div>
            <nav class="sidebar-nav">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="users.php">
                            <i class="bi bi-people"></i> Manage Users
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="bookings.php">
                            <i class="bi bi-calendar-check"></i> All Bookings
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="drivers.php">
                            <i class="bi bi-car-front"></i> Manage Drivers
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="reports.php">
                            <i class="bi bi-file-earmark-text"></i> Reports
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="settings.php">
                            <i class="bi bi-gear"></i> System Settings
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
        <div class="main-content">
            <!-- Header -->
            <div class="dashboard-header">
                <div class="container-fluid">
                    <div class="row align-items-center">
                        <div class="col">
                            <h4 class="mb-0 fw-bold">Driver Management</h4>
                            <small class="text-muted">Manage drivers and vehicles</small>
                        </div>
                        <div class="col-auto">
                            <div class="header-buttons">
                                <div class="dropdown">
                                    <button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                        <i class="bi bi-download"></i> Export
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li><a class="dropdown-item" href="?export=drivers&format=csv">
                                            <i class="bi bi-filetype-csv"></i> Export as CSV
                                        </a></li>
                                        <li><a class="dropdown-item" href="?export=drivers&format=pdf">
                                            <i class="bi bi-filetype-pdf"></i> Export as PDF
                                        </a></li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo strpos($message, 'Error') !== false ? 'danger' : 'success'; ?> alert-dismissible fade show">
                        <i class="bi bi-<?php echo strpos($message, 'Error') !== false ? 'exclamation-triangle' : 'check-circle'; ?>"></i> <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                

                <!-- Statistics Cards -->
                <div class="row g-3 mb-4">
                    <div class="col">
                        <div class="card stat-card bg-primary text-white">
                            <div class="card-body text-center">
                                <h2 class="mb-1 fw-bold"><?php echo $stats['total_drivers']; ?></h2>
                                <small class="opacity-75">Total Drivers</small>
                            </div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="card stat-card bg-success text-white">
                            <div class="card-body text-center">
                                <h2 class="mb-1 fw-bold"><?php echo $stats['available_drivers']; ?></h2>
                                <small class="opacity-75">Available</small>
                            </div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="card stat-card bg-warning text-white">
                            <div class="card-body text-center">
                                <h2 class="mb-1 fw-bold"><?php echo $stats['busy_drivers']; ?></h2>
                                <small class="opacity-75">Not Available</small>
                            </div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="card stat-card bg-secondary text-white">
                            <div class="card-body text-center">
                                <h2 class="mb-1 fw-bold"><?php echo $stats['offline_drivers']; ?></h2>
                                <small class="opacity-75">Maintenance</small>
                            </div>
                        </div>
                    </div>
                    <div class="col">
                        <div class="card stat-card bg-danger text-white">
                            <div class="card-body text-center">
                                <h2 class="mb-1 fw-bold"><?php echo $stats['pending_drivers']; ?></h2>
                                <small class="opacity-75">Pending</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Search and Filters -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" action="" class="row g-3">
                            <div class="col-md-4">
                                <label for="search" class="form-label fw-semibold">Search Drivers</label>
                                <input type="text" class="form-control" id="search" name="search" 
                                       value="<?php echo htmlspecialchars($search_query); ?>" 
                                       placeholder="Search by name, car model, or plate...">
                            </div>
                            <div class="col-md-3">
                                <label for="status" class="form-label fw-semibold">Filter by Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="">All Statuses</option>
                                    <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending Approval</option>
                                    <option value="available" <?php echo $status_filter == 'available' ? 'selected' : ''; ?>>Available</option>
                                    <option value="not available" <?php echo $status_filter == 'not available' ? 'selected' : ''; ?>>Not Available</option>
                                    <option value="maintenance" <?php echo $status_filter == 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="car_type" class="form-label fw-semibold">Filter by Car Type</label>
                                <select class="form-select" id="car_type" name="car_type">
                                    <option value="">All Car Types</option>
                                    <?php foreach ($available_car_types as $car_type => $description): ?>
                                        <option value="<?php echo htmlspecialchars($car_type); ?>" 
                                                <?php echo $car_type_filter == $car_type ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($car_type); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-search"></i> Search
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Results -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 fw-bold">
                            <i class="bi bi-car-front"></i> Drivers List
                            <span class="badge bg-secondary"><?php echo count($drivers); ?> found</span>
                            <?php if (count($drivers) != $stats['total_drivers']): ?>
                                <small class="text-muted">(filtered from <?php echo $stats['total_drivers']; ?> total)</small>
                            <?php endif; ?>
                        </h5>
                        <div class="d-flex gap-2">
                            <a href="drivers.php" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-arrow-clockwise"></i> Reset Filters
                            </a>
                            
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($drivers)): ?>
                            <div class="text-center py-5">
                                <i class="bi bi-car-front text-muted" style="font-size: 4rem;"></i>
                                <h5 class="mt-3">No drivers found</h5>
                                <p class="text-muted">No drivers match your current search criteria.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th width="25%">Driver</th>
                                            <th width="25%">Vehicle Details</th>
                                            <th width="20%">Contact</th>
                                            <th width="10%">Status</th>
                                            <th width="10%">Performance</th>
                                            <th width="10%">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($drivers as $driver): ?>
                                        <tr class="fade-in">
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="driver-avatar me-3">
                                                        <?php echo strtoupper(substr($driver['username'], 0, 2)); ?>
                                                    </div>
                                                    <div>
                                                        <h6 class="mb-1 fw-semibold"><?php echo htmlspecialchars($driver['username']); ?></h6>
                                                        <small class="text-muted">License: <?php echo htmlspecialchars($driver['licensenumber']); ?></small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars($driver['carmodel']); ?></div>
                                                    <small class="text-muted">
                                                        <span class="badge bg-dark me-1"><?php echo htmlspecialchars($driver['plate']); ?></span>
                                                        <?php echo $driver['car_type']; ?> • <?php echo $driver['capacity']; ?> seats
                                                    </small>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="small">
                                                    <div class="mb-1">
                                                        <i class="bi bi-envelope text-primary me-2"></i>
                                                        <?php echo htmlspecialchars($driver['email']); ?>
                                                    </div>
                                                    <div>
                                                        <i class="bi bi-phone text-success me-2"></i>
                                                        <?php echo htmlspecialchars($driver['notel']); ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($driver['user_status'] == 'inactive'): ?>
                                                    <span class="badge status-pending px-2 py-1">
                                                        <i class="bi bi-clock me-1"></i>
                                                        Pending
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge status-<?php echo str_replace(' ', '-', $driver['driver_status']); ?> px-2 py-1">
                                                        <i class="bi bi-<?php echo $driver['driver_status'] == 'available' ? 'check-circle' : ($driver['driver_status'] == 'not available' ? 'hourglass-split' : 'wrench'); ?> me-1"></i>
                                                        <?php echo $driver['driver_status'] == 'not available' ? 'Not Available' : ucfirst($driver['driver_status']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="small text-center">
                                                    <div class="mb-1">
                                                        <strong><?php echo $driver['total_bookings']; ?></strong> trips
                                                    </div>
                                                    <div class="mb-1">
                                                        <strong class="text-success"><?php echo $driver['completed_bookings']; ?></strong> completed
                                                    </div>
                                                    <div class="text-success">
                                                        <strong>RM <?php echo number_format($driver['total_earnings'], 2); ?></strong>
                                                        <br>
                                                        <small class="text-muted">earned</small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <!-- View Details -->
                                                    <button class="btn btn-outline-info btn-sm" data-bs-toggle="modal" 
                                                            data-bs-target="#driverModal<?php echo $driver['driverid']; ?>" 
                                                            title="View Details">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    
                                                    <!-- Status Actions -->
                                                    <?php if ($driver['user_status'] == 'inactive'): ?>
                                                        <form method="POST" action="drivers.php" class="d-inline">
                                                            <input type="hidden" name="userid" value="<?php echo $driver['userid']; ?>">
                                                            <input type="hidden" name="driverid" value="<?php echo $driver['driverid']; ?>">
                                                            <button type="submit" name="approve_driver" class="btn btn-outline-primary btn-sm"
                                                                    title="Approve Driver">
                                                                <i class="bi bi-check-lg"></i>
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <div class="dropdown">
                                                            <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" title="Change Status">
                                                                <i class="bi bi-gear"></i>
                                                            </button>
                                                            <ul class="dropdown-menu">
                                                                <?php if ($driver['driver_status'] != 'available'): ?>
                                                                <li>
                                                                    <button type="button" class="dropdown-item status-update-btn" 
                                                                            data-driver-id="<?php echo $driver['driverid']; ?>" 
                                                                            data-status="available"
                                                                            onclick="updateDriverStatus(<?php echo $driver['driverid']; ?>, 'available')">
                                                                        <i class="bi bi-check-circle text-success me-2"></i>
                                                                        Set Available
                                                                    </button>
                                                                </li>
                                                                <?php endif; ?>
                                                                <?php if ($driver['driver_status'] != 'not available'): ?>
                                                                <li>
                                                                    <button type="button" class="dropdown-item status-update-btn" 
                                                                            data-driver-id="<?php echo $driver['driverid']; ?>" 
                                                                            data-status="not available"
                                                                            onclick="updateDriverStatus(<?php echo $driver['driverid']; ?>, 'not available')">
                                                                        <i class="bi bi-x-circle text-warning me-2"></i>
                                                                        Set Not Available
                                                                    </button>
                                                                </li>
                                                                <?php endif; ?>
                                                                <?php if ($driver['driver_status'] != 'maintenance'): ?>
                                                                <li>
                                                                    <button type="button" class="dropdown-item status-update-btn" 
                                                                            data-driver-id="<?php echo $driver['driverid']; ?>" 
                                                                            data-status="maintenance"
                                                                            onclick="updateDriverStatus(<?php echo $driver['driverid']; ?>, 'maintenance')">
                                                                        <i class="bi bi-wrench text-secondary me-2"></i>
                                                                        Set Maintenance
                                                                    </button>
                                                                </li>
                                                                <?php endif; ?>
                                                            </ul>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Driver Modals -->
    <?php foreach ($drivers as $driver): ?>
    <!-- Driver Details Modal -->
    <div class="modal fade" id="driverModal<?php echo $driver['driverid']; ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-light">
                    <h5 class="modal-title">
                        <div class="d-flex align-items-center">
                            <div class="driver-avatar me-3" style="width: 50px; height: 50px;">
                                <?php echo strtoupper(substr($driver['username'], 0, 2)); ?>
                            </div>
                            <div>
                                <div class="fw-bold"><?php echo htmlspecialchars($driver['username']); ?></div>
                                <small class="text-muted">Driver ID: <?php echo $driver['driverid']; ?></small>
                            </div>
                        </div>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card h-100">
                                <div class="card-header bg-primary text-white">
                                    <h6 class="mb-0"><i class="bi bi-person-circle"></i> Driver Information</h6>
                                </div>
                                <div class="card-body">
                                    <table class="table table-borderless table-sm">
                                        <tr>
                                            <td class="fw-semibold text-muted" width="40%">Username:</td>
                                            <td><?php echo htmlspecialchars($driver['username']); ?></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-semibold text-muted">Email:</td>
                                            <td><?php echo htmlspecialchars($driver['email']); ?></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-semibold text-muted">Phone:</td>
                                            <td><?php echo htmlspecialchars($driver['notel']); ?></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-semibold text-muted">License Number:</td>
                                            <td><?php echo htmlspecialchars($driver['licensenumber']); ?></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-semibold text-muted">Gender:</td>
                                            <td><?php echo $driver['gender']; ?></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-semibold text-muted">Joined:</td>
                                            <td><?php echo date('d M Y', strtotime($driver['datehired'])); ?></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-semibold text-muted">Total Trips:</td>
                                            <td><strong><?php echo $driver['total_bookings']; ?></strong> total</td>
                                        </tr>
                                        <tr>
                                            <td class="fw-semibold text-muted">Completed:</td>
                                            <td><strong class="text-success"><?php echo $driver['completed_bookings']; ?></strong> completed</td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card h-100">
                                <div class="card-header bg-success text-white">
                                    <h6 class="mb-0"><i class="bi bi-car-front"></i> Vehicle Information</h6>
                                </div>
                                <div class="card-body">
                                    <table class="table table-borderless table-sm">
                                        <tr>
                                            <td class="fw-semibold text-muted" width="40%">Car Model:</td>
                                            <td><?php echo htmlspecialchars($driver['carmodel']); ?></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-semibold text-muted">License Plate:</td>
                                            <td>
                                                <span class="badge bg-dark px-2 py-1">
                                                    <?php echo htmlspecialchars($driver['plate']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td class="fw-semibold text-muted">Car Type:</td>
                                            <td>
                                                <span class="badge bg-info px-2 py-1">
                                                    <?php echo $driver['car_type']; ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td class="fw-semibold text-muted">Capacity:</td>
                                            <td><?php echo $driver['capacity']; ?> passengers</td>
                                        </tr>
                                        <tr>
                                            <td class="fw-semibold text-muted">Status:</td>
                                            <td>
                                                <?php if ($driver['user_status'] == 'inactive'): ?>
                                                    <span class="badge status-pending px-2 py-1">Pending Approval</span>
                                                <?php else: ?>
                                                    <span class="badge status-<?php echo str_replace(' ', '-', $driver['driver_status']); ?> px-2 py-1">
                                                        <?php echo $driver['driver_status'] == 'not available' ? 'Not Available' : ucfirst($driver['driver_status']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td class="fw-semibold text-muted">Total Earnings:</td>
                                            <td>
                                                <strong class="text-success">RM <?php echo number_format($driver['total_earnings'], 2); ?></strong>
                                                <br>
                                                <small class="text-muted">From completed trips only</small>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg"></i> Close
                    </button>
                    <a href="mailto:<?php echo $driver['email']; ?>" class="btn btn-primary">
                        <i class="bi bi-envelope"></i> Send Email
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Hidden form for status updates -->
    <form id="statusUpdateForm" method="POST" action="drivers.php" style="display: none;">
        <input type="hidden" id="statusDriverId" name="driverid" value="">
        <input type="hidden" id="statusNewStatus" name="new_status" value="">
        <input type="hidden" name="update_driver_status" value="1">
    </form>

    <div class="logout-confirmation" id="logoutConfirmation">
        <div class="confirmation-box">
            <div class="mb-3">
                <i class="bi bi-exclamation-triangle text-warning" style="font-size: 3rem;"></i>
            </div>
            <h5 class="mb-3">Confirm Logout</h5>
            <p class="text-muted mb-4">Are you sure you want to logout from the admin panel?</p>
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
        // Add animations
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.card, .stat-card');
            cards.forEach((card, index) => {
                card.style.animationDelay = (index * 0.1) + 's';
                card.classList.add('fade-in');
            });
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

        // Function to update driver status
        function updateDriverStatus(driverId, newStatus) {
            console.log('updateDriverStatus called with:', driverId, newStatus);
            
            // Status display names for confirmation
            const statusNames = {
                'available': 'Available',
                'not available': 'Not Available',
                'maintenance': 'Maintenance'
            };
            
            const statusName = statusNames[newStatus] || newStatus;
            
            if (confirm(`Are you sure you want to set this driver status to "${statusName}"?`)) {
                console.log('User confirmed status change');
                
                // Set the hidden form values
                document.getElementById('statusDriverId').value = driverId;
                document.getElementById('statusNewStatus').value = newStatus;
                
                console.log('Form values set:', {
                    driverId: document.getElementById('statusDriverId').value,
                    newStatus: document.getElementById('statusNewStatus').value
                });
                
                // Show loading indicator if available
                const statusButtons = document.querySelectorAll(`[data-driver-id="${driverId}"]`);
                statusButtons.forEach(btn => {
                    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Updating...';
                    btn.disabled = true;
                });
                
                // Submit the form
                console.log('Submitting form...');
                document.getElementById('statusUpdateForm').submit();
            } else {
                console.log('User cancelled status change');
            }
        }

        // Real-time search
        let searchTimeout;
        const searchInput = document.getElementById('search');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    this.form.submit();
                }, 500);
            });
        }

        // Initialize Bootstrap modals
        const modalElements = document.querySelectorAll('.modal');
        modalElements.forEach(function(modalElement) {
            new bootstrap.Modal(modalElement);
        });

        // Enhanced form feedback for POST forms
        const postForms = document.querySelectorAll('form[method="POST"]');
        postForms.forEach(form => {
            form.addEventListener('submit', function(e) {
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn && !submitBtn.disabled) {
                    const originalText = submitBtn.innerHTML;
                    const actionName = submitBtn.name;
                    
                    if (actionName === 'update_driver_status') {
                        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Updating...';
                    } else if (actionName === 'approve_driver') {
                        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Approving...';
                    } else {
                        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';
                    }
                    
                    submitBtn.disabled = true;
                    
                    // Re-enable after timeout in case of errors
                    setTimeout(() => {
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }, 10000);
                }
            });
        });

        // Auto-dismiss alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }, 5000);
            });
        });

        // Auto-refresh statistics every 2 minutes
        setInterval(function() {
            if (document.hasFocus()) {
                // Only refresh if no modals are open
                const openModals = document.querySelectorAll('.modal.show');
                if (openModals.length === 0) {
                    location.reload();
                }
            }
        }, 120000);
    </script>
</body>
</html>