<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is an admin
if (!isset($_SESSION['userid']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();
$message = '';

// Handle booking actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_booking_status'])) {
        $bookingid = $_POST['bookingid'];
        $new_status = $_POST['status'];
        
        $query = "UPDATE booking SET bookingstatus = ? WHERE bookingid = ?";
        $stmt = $db->prepare($query);
        $stmt->bindParam(1, $new_status);
        $stmt->bindParam(2, $bookingid);
        
        if ($stmt->execute()) {
            $message = "Booking status updated successfully!";
        } else {
            $message = "Error updating booking status.";
        }
    }
    
    if (isset($_POST['complete_booking'])) {
        $bookingid = $_POST['bookingid'];
        
        // Update booking status to completed
        $query = "UPDATE booking SET bookingstatus = 'completed' WHERE bookingid = ?";
        $stmt = $db->prepare($query);
        $stmt->bindParam(1, $bookingid);
        
        if ($stmt->execute()) {
            $message = "Booking marked as completed!";
        } else {
            $message = "Error completing booking.";
        }
    }
    
    // delete booking functionality with better error handling
    if (isset($_POST['delete_booking'])) {
        $bookingid = intval($_POST['bookingid']); // Ensure it's an integer
        
        if ($bookingid <= 0) {
            $message = "Error: Invalid booking ID.";
        } else {
            try {
                $db->beginTransaction();
                
                // Check if booking exists first
                $check_query = "SELECT bookingid, bookingstatus FROM booking WHERE bookingid = ?";
                $check_stmt = $db->prepare($check_query);
                $check_stmt->bindParam(1, $bookingid, PDO::PARAM_INT);
                $check_stmt->execute();
                $booking_exists = $check_stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$booking_exists) {
                    $message = "Error: Booking not found.";
                } else {
                    // Delete related ratings first (if any)
                    $query = "DELETE FROM rating WHERE bookingid = ?";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(1, $bookingid, PDO::PARAM_INT);
                    $stmt->execute();
                    
                    // Delete the booking
                    $query = "DELETE FROM booking WHERE bookingid = ?";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(1, $bookingid, PDO::PARAM_INT);
                    
                    if ($stmt->execute()) {
                        if ($stmt->rowCount() > 0) {
                            $db->commit();
                            $message = "Booking #$bookingid deleted successfully!";
                            
                            // Redirect to prevent resubmission on page refresh
                            header("Location: bookings.php?deleted=1");
                            exit();
                        } else {
                            $db->rollback();
                            $message = "Error: No booking was deleted. Please try again.";
                        }
                    } else {
                        $db->rollback();
                        $message = "Error: Failed to delete booking.";
                    }
                }
            } catch (Exception $e) {
                $db->rollback();
                $message = "Error deleting booking: " . $e->getMessage();
                error_log("Delete booking error: " . $e->getMessage());
            }
        }
    }
}

// Check for deletion success message
if (isset($_GET['deleted']) && $_GET['deleted'] == '1') {
    $message = "Booking deleted successfully!";
}

// Get filter parameters 
$status_filter = isset($_GET['status']) && $_GET['status'] !== '' ? trim($_GET['status']) : '';
$date_filter = isset($_GET['date']) && $_GET['date'] !== '' ? trim($_GET['date']) : '';
$driver_filter = isset($_GET['driver']) && $_GET['driver'] !== '' ? trim($_GET['driver']) : '';
$student_filter = isset($_GET['student']) && $_GET['student'] !== '' ? trim($_GET['student']) : '';

// query with better JOIN structure and filtering
$query = "SELECT b.*, 
                 u_student.username as student_name, 
                 u_student.email as student_email, 
                 u_student.notel as student_phone,
                 COALESCE(s.student_number, 'N/A') as student_number, 
                 COALESCE(s.faculty, 'N/A') as faculty,
                 u_driver.username as driver_name, 
                 u_driver.email as driver_email, 
                 u_driver.notel as driver_phone,
                 d.carmodel, d.plate, d.car_type, d.capacity
          FROM booking b
          INNER JOIN user u_student ON b.userid = u_student.userid
          LEFT JOIN student s ON u_student.userid = s.userid
          INNER JOIN driver d ON b.driverid = d.driverid
          INNER JOIN user u_driver ON d.userid = u_driver.userid
          WHERE 1=1";

$params = [];

// filters with proper validation
if (!empty($status_filter)) {
    $query .= " AND b.bookingstatus = ?";
    $params[] = $status_filter;
}

if (!empty($date_filter)) {
    // Handle different date formats and ensure proper comparison
    $query .= " AND DATE(b.pickupdate) = ?";
    $params[] = $date_filter;
}

if (!empty($driver_filter)) {
    // Search in both username and driver details
    $query .= " AND (u_driver.username LIKE ? OR d.carmodel LIKE ? OR d.plate LIKE ?)";
    $params[] = "%$driver_filter%";
    $params[] = "%$driver_filter%";
    $params[] = "%$driver_filter%";
}

if (!empty($student_filter)) {
    // Search in multiple student fields
    $query .= " AND (u_student.username LIKE ? OR s.student_number LIKE ? OR s.faculty LIKE ?)";
    $params[] = "%$student_filter%";
    $params[] = "%$student_filter%";
    $params[] = "%$student_filter%";
}

$query .= " ORDER BY b.bookingdate DESC";

// Execute query with proper error handling
try {
    $stmt = $db->prepare($query);
    
    // Bind parameters properly
    if (!empty($params)) {
        foreach ($params as $index => $param) {
            $stmt->bindValue($index + 1, $param, PDO::PARAM_STR);
        }
    }
    
    $stmt->execute();
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug information
    if (isset($_GET['debug'])) {
        error_log("Query: " . $query);
        error_log("Params: " . print_r($params, true));
        error_log("Results: " . count($bookings));
    }
    
} catch (PDOException $e) {
    $bookings = [];
    $message = "Database error: " . $e->getMessage();
    error_log("Booking query error: " . $e->getMessage());
}

// Get booking statistics
$query = "SELECT 
            COUNT(*) as total_bookings,
            COUNT(CASE WHEN bookingstatus = 'pending' THEN 1 END) as pending_bookings,
            COUNT(CASE WHEN bookingstatus = 'confirmed' THEN 1 END) as confirmed_bookings,
            COUNT(CASE WHEN bookingstatus = 'completed' THEN 1 END) as completed_bookings,
            COUNT(CASE WHEN bookingstatus = 'cancelled' THEN 1 END) as cancelled_bookings,
            SUM(CASE WHEN bookingstatus = 'completed' THEN totalcost END) as total_revenue
          FROM booking";
$stmt = $db->prepare($query);
$stmt->execute();
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Handle null values in stats
if (!$stats) {
    $stats = [
        'total_bookings' => 0,
        'pending_bookings' => 0,
        'confirmed_bookings' => 0,
        'completed_bookings' => 0,
        'cancelled_bookings' => 0,
        'total_revenue' => 0
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Bookings - RenToGo Admin</title>
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

        .main-content {
            margin-left: 250px;
            width: calc(100% - 250px);
            min-height: 100vh;
        }

        .dashboard-header {
            background: white;
            border-bottom: 1px solid #dee2e6;
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .dashboard-content {
            padding: 2rem;
            max-width: 100%;
        }

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

        .btn {
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

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

        .badge {
            font-size: 0.75em;
            font-weight: 500;
            letter-spacing: 0.5px;
            padding: 0.5em 0.75em;
        }

        .status-pending { background-color: #ffc107 !important; color: #000 !important; }
        .status-confirmed { background-color: #17a2b8 !important; }
        .status-completed { background-color: #28a745 !important; }
        .status-cancelled { background-color: #dc3545 !important; }

        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

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
        }

        .container-fluid {
            padding-left: 1rem;
            padding-right: 1rem;
            max-width: 100%;
        }

        .row {
            margin-left: 0;
            margin-right: 0;
        }

        .col, .col-auto, .col-md-2, .col-md-3, .col-md-4 {
            padding-left: 0.5rem;
            padding-right: 0.5rem;
        }

        .trip-details {
            font-size: 0.85rem;
            line-height: 1.4;
        }

        .trip-details .location {
            background: #f8f9fa;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            margin: 0.125rem 0;
            display: inline-block;
            max-width: 150px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
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

        /*Ensure delete button is properly styled and clickable */
        .delete-booking-form {
            display: inline-block;
        }

        .delete-booking-btn {
            cursor: pointer;
            border: 1px solid #dc3545;
            background-color: transparent;
            color: #dc3545;
            transition: all 0.3s ease;
        }

        .delete-booking-btn:hover {
            background-color: #dc3545;
            color: white;
            border-color: #dc3545;
        }

        .delete-booking-btn:active {
            background-color: #c82333 !important;
            border-color: #c82333 !important;
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
                        <a class="nav-link active" href="bookings.php">
                            <i class="bi bi-calendar-check"></i> All Bookings
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="drivers.php">
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
                            <h4 class="mb-0 fw-bold">Booking Management</h4>
                            <small class="text-muted">Monitor and manage all system bookings</small>
                        </div>
                        <div class="col-auto">
                            <div class="dropdown">
                                <button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                    <i class="bi bi-download"></i> Export
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="?export=bookings&format=csv">
                                        <i class="bi bi-filetype-csv"></i> Export as CSV
                                    </a></li>
                                    <li><a class="dropdown-item" href="?export=bookings&format=pdf">
                                        <i class="bi bi-filetype-pdf"></i> Export as PDF
                                    </a></li>
                                </ul>
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
                    <div class="col-md-2">
                        <div class="card stat-card bg-primary text-white">
                            <div class="card-body text-center">
                                <h2 class="mb-1 fw-bold"><?php echo $stats['total_bookings']; ?></h2>
                                <small class="opacity-75">Total</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card stat-card bg-warning text-white">
                            <div class="card-body text-center">
                                <h2 class="mb-1 fw-bold"><?php echo $stats['pending_bookings']; ?></h2>
                                <small class="opacity-75">Pending</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card stat-card bg-info text-white">
                            <div class="card-body text-center">
                                <h2 class="mb-1 fw-bold"><?php echo $stats['confirmed_bookings']; ?></h2>
                                <small class="opacity-75">Confirmed</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card stat-card bg-success text-white">
                            <div class="card-body text-center">
                                <h2 class="mb-1 fw-bold"><?php echo $stats['completed_bookings']; ?></h2>
                                <small class="opacity-75">Completed</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card stat-card bg-danger text-white">
                            <div class="card-body text-center">
                                <h2 class="mb-1 fw-bold"><?php echo $stats['cancelled_bookings']; ?></h2>
                                <small class="opacity-75">Cancelled</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card stat-card bg-secondary text-white">
                            <div class="card-body text-center">
                                <h2 class="mb-1 fw-bold">RM<?php echo number_format($stats['total_revenue'] ?? 0, 0); ?></h2>
                                <small class="opacity-75">Revenue</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" action="" class="row g-3" id="filterForm">
                            <div class="col-md-2">
                                <label for="status" class="form-label fw-semibold">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="">All Statuses</option>
                                    <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="confirmed" <?php echo $status_filter == 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                    <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="date" class="form-label fw-semibold">Pickup Date</label>
                                <input type="date" class="form-control" id="date" name="date" value="<?php echo htmlspecialchars($date_filter); ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="student" class="form-label fw-semibold">Student</label>
                                <input type="text" class="form-control" id="student" name="student" 
                                       value="<?php echo htmlspecialchars($student_filter); ?>" 
                                       placeholder="Search student name...">
                            </div>
                            <div class="col-md-3">
                                <label for="driver" class="form-label fw-semibold">Driver</label>
                                <input type="text" class="form-control" id="driver" name="driver" 
                                       value="<?php echo htmlspecialchars($driver_filter); ?>" 
                                       placeholder="Search driver name...">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-funnel"></i> Filter
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Bookings Table -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 fw-bold">
                            <i class="bi bi-calendar-check"></i> All Bookings
                            <span class="badge bg-secondary"><?php echo count($bookings); ?> found</span>
                        </h5>
                        <a href="bookings.php" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-arrow-clockwise"></i> Reset Filters
                        </a>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($bookings)): ?>
                            <div class="text-center py-5">
                                <i class="bi bi-calendar-x text-muted" style="font-size: 4rem; opacity: 0.5;"></i>
                                <h5 class="mt-3">No bookings found</h5>
                                <p class="text-muted">No bookings match your current filters. Try adjusting your search criteria.</p>
                                <div class="mt-3">
                                    <a href="bookings.php" class="btn btn-outline-primary">
                                        <i class="bi bi-arrow-clockwise"></i> Clear All Filters
                                    </a>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th width="12%">Booking</th>
                                            <th width="20%">Student</th>
                                            <th width="20%">Driver & Vehicle</th>
                                            <th width="30%">Trip Details</th>
                                            <th width="10%">Cost</th>
                                            <th width="8%">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($bookings as $booking): ?>
                                        <tr class="fade-in">
                                            <td>
                                                <strong class="text-primary">#<?php echo htmlspecialchars($booking['bookingid']); ?></strong>
                                                <br>
                                                <small class="text-muted">
                                                    <?php echo date('d M Y', strtotime($booking['bookingdate'])); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($booking['student_name']); ?></strong>
                                                    <br>
                                                    <small class="text-muted">
                                                        <?php echo htmlspecialchars($booking['student_number'] ?? 'N/A'); ?>
                                                    </small>
                                                    <br>
                                                    <small class="text-muted">
                                                        <?php echo htmlspecialchars($booking['faculty'] ?? 'N/A'); ?>
                                                    </small>
                                                </div>
                                            </td>
                                            <td>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($booking['driver_name']); ?></strong>
                                                    <br>
                                                    <small class="text-muted">
                                                        <?php echo htmlspecialchars($booking['carmodel']); ?>
                                                    </small>
                                                    <br>
                                                    <small class="text-muted">
                                                        <span class="badge bg-dark"><?php echo htmlspecialchars($booking['plate']); ?></span>
                                                        <?php echo htmlspecialchars($booking['capacity']); ?> seats
                                                    </small>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="trip-details">
                                                    <div class="mb-2">
                                                        <i class="bi bi-geo-alt-fill text-success me-1"></i>
                                                        <strong><?php echo date('d M, h:i A', strtotime($booking['pickupdate'])); ?></strong>
                                                        <div class="location"><?php echo htmlspecialchars($booking['pickuplocation']); ?></div>
                                                    </div>
                                                    <div class="mb-2">
                                                        <i class="bi bi-geo-fill text-danger me-1"></i>
                                                        <div class="location"><?php echo htmlspecialchars($booking['dropofflocation']); ?></div>
                                                    </div>
                                                    <small class="text-muted">
                                                        <i class="bi bi-people me-1"></i><?php echo htmlspecialchars($booking['pax']); ?> pax • 
                                                        <i class="bi bi-clock me-1"></i><?php echo htmlspecialchars($booking['duration_hours']); ?>h
                                                    </small>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="text-center">
                                                    <strong class="text-primary h6">RM <?php echo number_format($booking['totalcost'], 2); ?></strong>
                                                    <br>
                                                    <span class="badge status-<?php echo $booking['bookingstatus']; ?> px-2 py-1">
                                                        <?php echo ucfirst($booking['bookingstatus']); ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <!-- View Details -->
                                                    <button class="btn btn-outline-info btn-sm" data-bs-toggle="modal" 
                                                            data-bs-target="#bookingModal<?php echo $booking['bookingid']; ?>"
                                                            title="View Details">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    
                                                    <!-- Update Status -->
                                                    <div class="dropdown">
                                                        <button class="btn btn-outline-warning btn-sm dropdown-toggle" 
                                                                type="button" data-bs-toggle="dropdown"
                                                                title="Update Status">
                                                            <i class="bi bi-gear"></i>
                                                        </button>
                                                        <ul class="dropdown-menu">
                                                            <li>
                                                                <form method="POST" action="" style="display: inline; width: 100%;">
                                                                    <input type="hidden" name="bookingid" value="<?php echo $booking['bookingid']; ?>">
                                                                    <input type="hidden" name="status" value="pending">
                                                                    <button type="submit" name="update_booking_status" class="dropdown-item">
                                                                        <i class="bi bi-clock text-warning me-2"></i>Pending
                                                                    </button>
                                                                </form>
                                                            </li>
                                                            <li>
                                                                <form method="POST" action="" style="display: inline; width: 100%;">
                                                                    <input type="hidden" name="bookingid" value="<?php echo $booking['bookingid']; ?>">
                                                                    <input type="hidden" name="status" value="confirmed">
                                                                    <button type="submit" name="update_booking_status" class="dropdown-item">
                                                                        <i class="bi bi-check-circle text-info me-2"></i>Confirmed
                                                                    </button>
                                                                </form>
                                                            </li>
                                                            <li>
                                                                <form method="POST" action="" style="display: inline; width: 100%;">
                                                                    <input type="hidden" name="bookingid" value="<?php echo $booking['bookingid']; ?>">
                                                                    <button type="submit" name="complete_booking" class="dropdown-item">
                                                                        <i class="bi bi-check-circle-fill text-success me-2"></i>Mark as Completed
                                                                    </button>
                                                                </form>
                                                            </li>
                                                            <li><hr class="dropdown-divider"></li>
                                                            <li>
                                                                <form method="POST" action="" style="display: inline; width: 100%;">
                                                                    <input type="hidden" name="bookingid" value="<?php echo $booking['bookingid']; ?>">
                                                                    <input type="hidden" name="status" value="cancelled">
                                                                    <button type="submit" name="update_booking_status" class="dropdown-item text-danger">
                                                                        <i class="bi bi-x-circle me-2"></i>Cancel
                                                                    </button>
                                                                </form>
                                                            </li>
                                                        </ul>
                                                    </div>
                                                    
                                                    <!--Delete Booking with enhanced functionality -->
                                                    <form method="POST" action="" class="delete-booking-form" style="display: inline;">
                                                        <input type="hidden" name="bookingid" value="<?php echo $booking['bookingid']; ?>">
                                                        <button type="submit" name="delete_booking" 
                                                                class="btn btn-outline-danger btn-sm delete-booking-btn"
                                                                title="Delete Booking"
                                                                onclick="return confirm('Are you sure you want to delete booking #<?php echo $booking['bookingid']; ?>?\n\nThis will permanently remove:\n• The booking record\n• Any associated ratings\n\nThis action CANNOT be undone!');">
                                                            <i class="bi bi-trash3"></i>
                                                        </button>
                                                    </form>
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

    <!-- Booking Modals  -->
    <?php foreach ($bookings as $booking): ?>
    <div class="modal fade" id="bookingModal<?php echo $booking['bookingid']; ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-light">
                    <h5 class="modal-title">
                        <i class="bi bi-calendar-check"></i> Booking Details - #<?php echo $booking['bookingid']; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card h-100">
                                <div class="card-header bg-primary text-white">
                                    <h6 class="mb-0"><i class="bi bi-person-circle"></i> Student Information</h6>
                                </div>
                                <div class="card-body">
                                    <table class="table table-borderless table-sm">
                                        <tr>
                                            <td class="fw-semibold text-muted" width="35%">Name:</td>
                                            <td><?php echo htmlspecialchars($booking['student_name']); ?></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-semibold text-muted">Student Number:</td>
                                            <td><?php echo htmlspecialchars($booking['student_number'] ?? 'N/A'); ?></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-semibold text-muted">Faculty:</td>
                                            <td><?php echo htmlspecialchars($booking['faculty'] ?? 'N/A'); ?></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-semibold text-muted">Email:</td>
                                            <td><?php echo htmlspecialchars($booking['student_email']); ?></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-semibold text-muted">Phone:</td>
                                            <td><?php echo htmlspecialchars($booking['student_phone']); ?></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card h-100">
                                <div class="card-header bg-success text-white">
                                    <h6 class="mb-0"><i class="bi bi-car-front"></i> Driver Information</h6>
                                </div>
                                <div class="card-body">
                                    <table class="table table-borderless table-sm">
                                        <tr>
                                            <td class="fw-semibold text-muted" width="35%">Name:</td>
                                            <td><?php echo htmlspecialchars($booking['driver_name']); ?></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-semibold text-muted">Vehicle:</td>
                                            <td><?php echo htmlspecialchars($booking['carmodel']); ?></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-semibold text-muted">Plate:</td>
                                            <td>
                                                <span class="badge bg-dark px-2 py-1">
                                                    <?php echo htmlspecialchars($booking['plate']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td class="fw-semibold text-muted">Email:</td>
                                            <td><?php echo htmlspecialchars($booking['driver_email']); ?></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-semibold text-muted">Phone:</td>
                                            <td><?php echo htmlspecialchars($booking['driver_phone']); ?></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mt-4">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header bg-info text-white">
                                    <h6 class="mb-0"><i class="bi bi-geo-alt"></i> Trip Details</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <h6 class="text-success"><i class="bi bi-geo-alt-fill"></i> Pickup</h6>
                                            <p class="mb-1"><strong><?php echo date('d M Y, h:i A', strtotime($booking['pickupdate'])); ?></strong></p>
                                            <p class="text-muted"><?php echo htmlspecialchars($booking['pickuplocation']); ?></p>
                                        </div>
                                        <div class="col-md-6">
                                            <h6 class="text-danger"><i class="bi bi-geo-fill"></i> Drop Off</h6>
                                            <p class="text-muted"><?php echo htmlspecialchars($booking['dropofflocation']); ?></p>
                                        </div>
                                    </div>
                                    
                                    <hr>
                                    
                                    <div class="row text-center">
                                        <div class="col-3">
                                            <h6 class="text-muted">Passengers</h6>
                                            <h4 class="text-primary"><?php echo htmlspecialchars($booking['pax']); ?></h4>
                                        </div>
                                        <div class="col-3">
                                            <h6 class="text-muted">Duration</h6>
                                            <h4 class="text-primary"><?php echo htmlspecialchars($booking['duration_hours']); ?>h</h4>
                                        </div>
                                        <div class="col-3">
                                            <h6 class="text-muted">Total Cost</h6>
                                            <h4 class="text-success">RM <?php echo number_format($booking['totalcost'], 2); ?></h4>
                                        </div>
                                        <div class="col-3">
                                            <h6 class="text-muted">Status</h6>
                                            <span class="badge status-<?php echo $booking['bookingstatus']; ?> px-3 py-2">
                                                <?php echo ucfirst($booking['bookingstatus']); ?>
                                            </span>
                                        </div>
                                    </div>
                                    
                                    <?php if (isset($booking['notes']) && $booking['notes']): ?>
                                    <hr>
                                    <h6 class="text-warning"><i class="bi bi-sticky"></i> Notes</h6>
                                    <div class="bg-light p-3 rounded"><?php echo htmlspecialchars($booking['notes']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg"></i> Close
                    </button>
                    <a href="mailto:<?php echo htmlspecialchars($booking['student_email']); ?>" class="btn btn-primary">
                        <i class="bi bi-envelope"></i> Email Student
                    </a>
                    <a href="mailto:<?php echo htmlspecialchars($booking['driver_email']); ?>" class="btn btn-success">
                        <i class="bi bi-envelope"></i> Email Driver
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

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
        // filtering with proper validation and error handling
        document.addEventListener('DOMContentLoaded', function() {
            const filterForm = document.getElementById('filterForm');
            const statusSelect = document.getElementById('status');
            const dateInput = document.getElementById('date');
            const studentInput = document.getElementById('student');
            const driverInput = document.getElementById('driver');
            
            // Immediate submit for status and date changes
            if (statusSelect) {
                statusSelect.addEventListener('change', function() {
                    console.log('Status changed to:', this.value);
                    filterForm.submit();
                });
            }
            
            if (dateInput) {
                dateInput.addEventListener('change', function() {
                    console.log('Date changed to:', this.value);
                    filterForm.submit();
                });
            }

            // Debounced search for text inputs with minimum length
            let searchTimeout;
            
            function handleTextSearch(input, fieldName) {
                if (!input) return;
                
                input.addEventListener('input', function() {
                    clearTimeout(searchTimeout);
                    const value = this.value.trim();
                    
                    console.log(`${fieldName} search:`, value);
                    
                    // Submit immediately if empty (to clear filter) or after 2+ characters
                    if (value.length === 0) {
                        filterForm.submit();
                    } else if (value.length >= 2) {
                        searchTimeout = setTimeout(() => {
                            console.log(`Submitting ${fieldName} search:`, value);
                            filterForm.submit();
                        }, 1000);
                    }
                });
                
                // Also submit on Enter key
                input.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        clearTimeout(searchTimeout);
                        filterForm.submit();
                    }
                });
            }
            
            handleTextSearch(studentInput, 'Student');
            handleTextSearch(driverInput, 'Driver');

            // Form validation before submit for filter form only
            if (filterForm) {
                filterForm.addEventListener('submit', function(e) {
                    const formData = new FormData(this);
                    const hasFilters = Array.from(formData.values()).some(value => value.trim() !== '');
                    
                    if (!hasFilters) {
                        console.log('No filters applied, showing all bookings');
                    } else {
                        console.log('Applying filters:', Object.fromEntries(formData));
                    }
                    
                    // Show loading state on filter button only
                    const submitBtn = this.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        const originalText = submitBtn.innerHTML;
                        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Filtering...';
                        submitBtn.disabled = true;
                        
                        // Re-enable after a delay (in case redirect doesn't happen)
                        setTimeout(() => {
                            submitBtn.innerHTML = originalText;
                            submitBtn.disabled = false;
                        }, 5000);
                    }
                });
            }

            // Animation setup
            const rows = document.querySelectorAll('tbody tr');
            rows.forEach((row, index) => {
                row.style.animationDelay = (index * 0.05) + 's';
                row.classList.add('fade-in');
            });

            const cards = document.querySelectorAll('.card, .stat-card');
            cards.forEach((card, index) => {
                card.style.animationDelay = (index * 0.1) + 's';
                card.classList.add('fade-in');
            });

            // Initialize Bootstrap components
            const modalElements = document.querySelectorAll('.modal');
            modalElements.forEach(function(modalElement) {
                new bootstrap.Modal(modalElement);
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

        // Close modal when clicking outside or pressing Escape
        document.getElementById('logoutConfirmation').addEventListener('click', function(e) {
            if (e.target === this) {
                hideLogoutConfirmation();
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                hideLogoutConfirmation();
            }
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
    </script>
</body>
</html>