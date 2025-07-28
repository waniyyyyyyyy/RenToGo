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

// Get driver info with error handling
try {
    $query = "SELECT driverid FROM driver WHERE userid = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$_SESSION['userid']]);
    $driver = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$driver) {
        throw new Exception("Driver record not found");
    }
} catch (Exception $e) {
    error_log("Error getting driver info: " . $e->getMessage());
    $_SESSION['error'] = "Unable to access driver information. Please login again.";
    header("Location: ../auth/logout.php");
    exit();
}

// Handle booking actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        if (isset($_POST['update_booking'])) {
            $bookingid = filter_var($_POST['bookingid'], FILTER_VALIDATE_INT);
            $new_status = trim($_POST['status']);
            
            if (!$bookingid) {
                throw new Exception("Invalid booking ID");
            }
            
            // Validate status
            $allowed_statuses = ['pending', 'confirmed', 'cancelled', 'completed'];
            if (!in_array($new_status, $allowed_statuses)) {
                throw new Exception("Invalid booking status");
            }
            
            $query = "UPDATE booking SET bookingstatus = ? WHERE bookingid = ? AND driverid = ?";
            $stmt = $db->prepare($query);
            
            if ($stmt->execute([$new_status, $bookingid, $driver['driverid']])) {
                $affected_rows = $stmt->rowCount();
                if ($affected_rows > 0) {
                    $message = "Booking status updated successfully!";
                } else {
                    throw new Exception("No booking found to update or you don't have permission");
                }
            } else {
                throw new Exception("Failed to update booking status");
            }
        }
        
        if (isset($_POST['complete_booking'])) {
            $bookingid = filter_var($_POST['bookingid'], FILTER_VALIDATE_INT);
            
            if (!$bookingid) {
                throw new Exception("Invalid booking ID");
            }
            
            $query = "UPDATE booking SET bookingstatus = 'completed' WHERE bookingid = ? AND driverid = ? AND bookingstatus = 'confirmed'";
            $stmt = $db->prepare($query);
            
            if ($stmt->execute([$bookingid, $driver['driverid']])) {
                $affected_rows = $stmt->rowCount();
                if ($affected_rows > 0) {
                    $message = "Booking marked as completed successfully!";
                } else {
                    throw new Exception("No confirmed booking found to complete");
                }
            } else {
                throw new Exception("Failed to complete booking");
            }
        }
    } catch (Exception $e) {
        error_log("Error updating booking: " . $e->getMessage());
        $message = "Error: " . $e->getMessage();
    }
}

// Get and validate filter parameters
$status_filter = '';
$date_filter = '';
$student_filter = '';

// Status filter validation
if (isset($_GET['status']) && !empty(trim($_GET['status']))) {
    $status_input = trim($_GET['status']);
    $allowed_statuses = ['pending', 'confirmed', 'cancelled', 'completed'];
    if (in_array($status_input, $allowed_statuses)) {
        $status_filter = $status_input;
    }
}

// Date filter validation
if (isset($_GET['date']) && !empty(trim($_GET['date']))) {
    $date_input = trim($_GET['date']);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_input)) {
        $date_obj = DateTime::createFromFormat('Y-m-d', $date_input);
        if ($date_obj && $date_obj->format('Y-m-d') === $date_input) {
            $date_filter = $date_input;
        }
    }
}

// Student filter validation
if (isset($_GET['student']) && !empty(trim($_GET['student']))) {
    $student_input = trim($_GET['student']);
    // Remove potentially harmful characters
    $student_input = preg_replace('/[<>\'"]/', '', $student_input);
    if (strlen($student_input) >= 2 && strlen($student_input) <= 100) {
        $student_filter = $student_input;
    }
}

// Build the main query with payment status
$base_query = "SELECT b.bookingid, b.userid, b.driverid, b.pickupdate, b.pickuplocation, 
                      b.dropofflocation, b.pax, b.duration_hours, b.totalcost, b.bookingstatus, 
                      b.notes, b.bookingdate, u.username as student_name, u.full_name as student_full_name, 
                      u.notel as student_phone, u.email as student_email, s.student_number, s.faculty, s.year_of_study,
                      CASE 
                          WHEN b.bookingstatus = 'completed' THEN 'Paid'
                          WHEN b.bookingstatus = 'confirmed' THEN 'Unpaid'
                          WHEN b.bookingstatus = 'pending' THEN 'Unpaid'
                          WHEN b.bookingstatus = 'cancelled' THEN 'N/A'
                          ELSE 'Unpaid'
                      END as payment_status,
                      CASE 
                          WHEN b.bookingstatus IN ('completed', 'confirmed') THEN b.totalcost
                          ELSE 0
                      END as display_cost
               FROM booking b
               INNER JOIN user u ON b.userid = u.userid
               INNER JOIN student s ON u.userid = s.userid
               WHERE b.driverid = ?";

$params = [$driver['driverid']];
$where_conditions = [];

// Add filters
if ($status_filter !== '') {
    $where_conditions[] = "b.bookingstatus = ?";
    $params[] = $status_filter;
}

if ($date_filter !== '') {
    $where_conditions[] = "DATE(b.pickupdate) = ?";
    $params[] = $date_filter;
}

if ($student_filter !== '') {
    $where_conditions[] = "(LOWER(u.username) LIKE LOWER(?) OR LOWER(u.full_name) LIKE LOWER(?) OR LOWER(s.student_number) LIKE LOWER(?) OR LOWER(u.email) LIKE LOWER(?))";
    $search_pattern = "%{$student_filter}%";
    $params[] = $search_pattern;
    $params[] = $search_pattern;
    $params[] = $search_pattern;
    $params[] = $search_pattern;
}

// Combine query with conditions
$final_query = $base_query;
if (!empty($where_conditions)) {
    $final_query .= " AND " . implode(" AND ", $where_conditions);
}

$final_query .= " ORDER BY 
    CASE 
        WHEN b.bookingstatus = 'pending' THEN 1
        WHEN b.bookingstatus = 'confirmed' THEN 2
        WHEN b.bookingstatus = 'completed' THEN 3
        WHEN b.bookingstatus = 'cancelled' THEN 4
        ELSE 5
    END,
    b.pickupdate DESC";

// Execute main query
$bookings = [];
try {
    $stmt = $db->prepare($final_query);
    $stmt->execute($params);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error in bookings query: " . $e->getMessage());
    error_log("Query: " . $final_query);
    error_log("Params: " . print_r($params, true));
    $message = "Error loading bookings. Please refresh the page.";
    $bookings = [];
}

// Get booking statistics
$stats = ['total' => 0, 'pending' => 0, 'confirmed' => 0, 'completed' => 0, 'cancelled' => 0];
try {
    $stats_query = "SELECT 
                COUNT(*) as total,
                COUNT(CASE WHEN bookingstatus = 'pending' THEN 1 END) as pending,
                COUNT(CASE WHEN bookingstatus = 'confirmed' THEN 1 END) as confirmed,
                COUNT(CASE WHEN bookingstatus = 'completed' THEN 1 END) as completed,
                COUNT(CASE WHEN bookingstatus = 'cancelled' THEN 1 END) as cancelled
              FROM booking WHERE driverid = ?";
    $stmt = $db->prepare($stats_query);
    $stmt->execute([$driver['driverid']]);
    $stats_result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($stats_result) {
        $stats = $stats_result;
    }
} catch (PDOException $e) {
    error_log("Database error getting stats: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Bookings - RenToGo</title>
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

        .flex-grow-1 {
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

        .booking-card {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            margin-bottom: 1rem;
        }
        
        .booking-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
        }
        
        .trip-details .row > div {
            border-right: 1px solid #e9ecef;
        }
        
        .trip-details .row > div:last-child {
            border-right: none;
        }
        
        .booking-info {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
        }
        
        .notes {
            font-size: 0.9rem;
        }

        /* Payment status badges */
        .payment-paid {
            background-color: #28a745;
            color: white;
        }

        .payment-unpaid {
            background-color: #ffc107;
            color: #000;
        }

        .payment-na {
            background-color: #6c757d;
            color: white;
        }

        /* Status badges */
        .status-pending {
            background-color: #ffc107;
            color: #000;
        }

        .status-confirmed {
            background-color: #17a2b8;
            color: white;
        }

        .status-completed {
            background-color: #28a745;
            color: white;
        }

        .status-cancelled {
            background-color: #dc3545;
            color: white;
        }

        .filter-active {
            background-color: #e3f2fd !important;
            border-color: #2196f3 !important;
        }

        .clear-filters {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
            border: none;
            color: white;
        }

        .clear-filters:hover {
            background: linear-gradient(135deg, #ee5a52 0%, #d63447 100%);
            color: white;
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

        .btn {
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

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
            
            .flex-grow-1 {
                margin-left: 0;
                width: 100%;
            }
            
            .dashboard-content {
                padding: 1rem;
            }

            .trip-details .row > div {
                border-right: none;
                border-bottom: 1px solid #e9ecef;
                padding-bottom: 1rem;
                margin-bottom: 1rem;
            }
            
            .trip-details .row > div:last-child {
                border-bottom: none;
                margin-bottom: 0;
                padding-bottom: 0;
            }
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
                        <a class="nav-link active" href="bookings.php">
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
                        <a class="nav-link" href="profile.php">
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
                            <h4 class="mb-0 fw-bold">Manage Bookings</h4>
                            <small class="text-muted">View and manage all your ride bookings</small>
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
                        <div class="alert alert-<?php echo strpos($message, 'Error') !== false ? 'danger' : 'success'; ?> alert-dismissible fade show">
                            <i class="bi bi-<?php echo strpos($message, 'Error') !== false ? 'exclamation-triangle' : 'check-circle'; ?>"></i> 
                            <?php echo htmlspecialchars($message); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <!-- Statistics Cards -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-2">
                            <div class="card bg-primary text-white">
                                <div class="card-body text-center">
                                    <h3 class="mb-1"><?php echo (int)$stats['total']; ?></h3>
                                    <small>Total</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card bg-warning text-dark">
                                <div class="card-body text-center">
                                    <h3 class="mb-1"><?php echo (int)$stats['pending']; ?></h3>
                                    <small>Pending</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card bg-info text-white">
                                <div class="card-body text-center">
                                    <h3 class="mb-1"><?php echo (int)$stats['confirmed']; ?></h3>
                                    <small>Confirmed</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card bg-success text-white">
                                <div class="card-body text-center">
                                    <h3 class="mb-1"><?php echo (int)$stats['completed']; ?></h3>
                                    <small>Completed</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card bg-danger text-white">
                                <div class="card-body text-center">
                                    <h3 class="mb-1"><?php echo (int)$stats['cancelled']; ?></h3>
                                    <small>Cancelled</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="card bg-secondary text-white">
                                <div class="card-body text-center">
                                    <h3 class="mb-1"><?php echo count($bookings); ?></h3>
                                    <small>Filtered</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Active Filters Display -->
                    <?php if ($status_filter || $date_filter || $student_filter): ?>
                    <div class="alert alert-info d-flex align-items-center justify-content-between">
                        <div>
                            <i class="bi bi-funnel-fill"></i> 
                            <strong>Active Filters:</strong>
                            <?php if ($status_filter): ?>
                                <span class="badge bg-primary ms-1">Status: <?php echo ucfirst(htmlspecialchars($status_filter)); ?></span>
                            <?php endif; ?>
                            <?php if ($date_filter): ?>
                                <span class="badge bg-info ms-1">Date: <?php echo date('d M Y', strtotime($date_filter)); ?></span>
                            <?php endif; ?>
                            <?php if ($student_filter): ?>
                                <span class="badge bg-success ms-1">Student: <?php echo htmlspecialchars($student_filter); ?></span>
                            <?php endif; ?>
                        </div>
                        <a href="bookings.php" class="btn btn-sm clear-filters">
                            <i class="bi bi-x-circle"></i> Clear All
                        </a>
                    </div>
                    <?php endif; ?>

                    <!-- Filters -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h6 class="mb-0 fw-bold">
                                <i class="bi bi-funnel"></i> Filter Bookings
                            </h6>
                        </div>
                        <div class="card-body">
                            <form method="GET" action="" class="row g-3" id="filterForm">
                                <div class="col-md-3">
                                    <label for="status" class="form-label fw-semibold">Status</label>
                                    <select class="form-select <?php echo $status_filter ? 'filter-active' : ''; ?>" id="status" name="status">
                                        <option value="">All Statuses</option>
                                        <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="confirmed" <?php echo $status_filter === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                        <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                        <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="date" class="form-label fw-semibold">Pickup Date</label>
                                    <input type="date" class="form-control <?php echo $date_filter ? 'filter-active' : ''; ?>" 
                                           id="date" name="date" value="<?php echo htmlspecialchars($date_filter); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label for="student" class="form-label fw-semibold">Student Search</label>
                                    <input type="text" class="form-control <?php echo $student_filter ? 'filter-active' : ''; ?>" 
                                           id="student" name="student" value="<?php echo htmlspecialchars($student_filter); ?>"
                                           placeholder="Search by name, student number, or email..."
                                           maxlength="100" minlength="2">
                                    <div class="form-text">Search by student name, student number, or email address</div>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">&nbsp;</label>
                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-search"></i> Search
                                        </button>
                                        <a href="bookings.php" class="btn btn-outline-secondary">
                                            <i class="bi bi-arrow-clockwise"></i> Reset
                                        </a>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Bookings List -->
                    <?php if (empty($bookings)): ?>
                        <div class="card">
                            <div class="card-body text-center py-5">
                                <i class="bi bi-calendar-x text-muted" style="font-size: 4rem;"></i>
                                <h5 class="mt-3">No bookings found</h5>
                                <p class="text-muted">
                                    <?php if ($status_filter || $date_filter || $student_filter): ?>
                                        No bookings match your current filters. Try adjusting your search criteria.
                                    <?php else: ?>
                                        You don't have any bookings yet. Students will be able to book your car once you're available.
                                    <?php endif; ?>
                                </p>
                                <?php if ($status_filter || $date_filter || $student_filter): ?>
                                    <a href="bookings.php" class="btn btn-primary">Clear All Filters</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="row">
                            <?php foreach ($bookings as $booking): ?>
                            <div class="col-lg-6 mb-4">
                                <div class="card booking-card">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-0 fw-bold">
                                                <i class="bi bi-person-circle"></i> 
                                                <?php echo htmlspecialchars($booking['student_full_name'] ?? $booking['student_name']); ?>
                                            </h6>
                                            <small class="text-muted">
                                                <?php echo htmlspecialchars($booking['student_number']); ?> • 
                                                <?php echo htmlspecialchars($booking['faculty']); ?>
                                            </small>
                                        </div>
                                        <div class="text-end">
                                            <span class="badge status-<?php echo htmlspecialchars($booking['bookingstatus']); ?> px-2 py-1">
                                                <?php echo ucfirst(htmlspecialchars($booking['bookingstatus'])); ?>
                                            </span>
                                            <br>
                                            <span class="badge payment-<?php echo strtolower(str_replace(' ', '', $booking['payment_status'])); ?> px-2 py-1 mt-1">
                                                <?php echo $booking['payment_status']; ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <!-- Trip Details -->
                                        <div class="trip-details mb-3">
                                            <div class="row g-3">
                                                <div class="col-md-6">
                                                    <h6 class="text-muted mb-1">
                                                        <i class="bi bi-play-circle"></i> Pickup
                                                    </h6>
                                                    <p class="mb-1">
                                                        <i class="bi bi-calendar"></i> 
                                                        <?php echo date('d M Y', strtotime($booking['pickupdate'])); ?>
                                                    </p>
                                                    <p class="mb-1">
                                                        <i class="bi bi-clock"></i> 
                                                        <?php echo date('h:i A', strtotime($booking['pickupdate'])); ?>
                                                    </p>
                                                    <p class="mb-0">
                                                        <i class="bi bi-geo-alt"></i> 
                                                        <?php echo htmlspecialchars($booking['pickuplocation']); ?>
                                                    </p>
                                                </div>
                                                <div class="col-md-6">
                                                    <h6 class="text-muted mb-1">
                                                        <i class="bi bi-stop-circle"></i> Drop Off
                                                    </h6>
                                                    <p class="mb-0">
                                                        <i class="bi bi-geo-alt"></i> 
                                                        <?php echo htmlspecialchars($booking['dropofflocation']); ?>
                                                    </p>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Booking Info -->
                                        <div class="booking-info mb-3">
                                            <div class="row g-3">
                                                <div class="col-6">
                                                    <small class="text-muted">Passengers</small>
                                                    <div><i class="bi bi-people"></i> <?php echo (int)$booking['pax']; ?> passenger(s)</div>
                                                </div>
                                                <div class="col-6">
                                                    <small class="text-muted">Duration</small>
                                                    <div><i class="bi bi-clock"></i> <?php echo (int)$booking['duration_hours']; ?> hours</div>
                                                </div>
                                                <div class="col-6">
                                                    <small class="text-muted">Total Cost</small>
                                                    <div class="text-primary fw-bold">
                                                        <i class="bi bi-currency-dollar"></i> 
                                                        <?php if ($booking['bookingstatus'] == 'cancelled'): ?>
                                                            <span class="text-muted">N/A</span>
                                                        <?php elseif ($booking['bookingstatus'] == 'pending'): ?>
                                                            <span class="text-muted">RM <?php echo number_format((float)$booking['totalcost'], 2); ?> (If accepted)</span>
                                                        <?php else: ?>
                                                            RM <?php echo number_format((float)$booking['display_cost'], 2); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="col-6">
                                                    <small class="text-muted">Contact</small>
                                                    <div><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($booking['student_phone']); ?></div>
                                                </div>
                                            </div>
                                        </div>

                                        <?php if (!empty($booking['notes'])): ?>
                                        <div class="notes mb-3">
                                            <small class="text-muted">Student Notes:</small>
                                            <div class="border rounded p-2 bg-light">
                                                <small><?php echo htmlspecialchars($booking['notes']); ?></small>
                                            </div>
                                        </div>
                                        <?php endif; ?>

                                        <!-- Contact & Actions -->
                                        <div class="d-flex flex-wrap gap-2">
                                            <a href="tel:<?php echo htmlspecialchars($booking['student_phone']); ?>" class="btn btn-outline-primary btn-sm">
                                                <i class="bi bi-telephone"></i> Call
                                            </a>
                                            <a href="mailto:<?php echo htmlspecialchars($booking['student_email']); ?>" class="btn btn-outline-info btn-sm">
                                                <i class="bi bi-envelope"></i> Email
                                            </a>
                                            
                                            <?php if ($booking['bookingstatus'] === 'pending'): ?>
                                                <form method="POST" action="" class="d-inline">
                                                    <input type="hidden" name="bookingid" value="<?php echo (int)$booking['bookingid']; ?>">
                                                    <input type="hidden" name="status" value="confirmed">
                                                    <button type="submit" name="update_booking" class="btn btn-success btn-sm">
                                                        <i class="bi bi-check"></i> Accept
                                                    </button>
                                                </form>
                                                <form method="POST" action="" class="d-inline">
                                                    <input type="hidden" name="bookingid" value="<?php echo (int)$booking['bookingid']; ?>">
                                                    <input type="hidden" name="status" value="cancelled">
                                                    <button type="submit" name="update_booking" class="btn btn-danger btn-sm"
                                                            onclick="return confirm('Are you sure you want to decline this booking?')">
                                                        <i class="bi bi-x"></i> Decline
                                                    </button>
                                                </form>
                                            <?php elseif ($booking['bookingstatus'] === 'confirmed'): ?>
                                                <form method="POST" action="" class="d-inline">
                                                    <input type="hidden" name="bookingid" value="<?php echo (int)$booking['bookingid']; ?>">
                                                    <button type="submit" name="complete_booking" class="btn btn-success btn-sm"
                                                            onclick="return confirm('Are you sure you want to mark this booking as completed?')">
                                                        <i class="bi bi-check-circle"></i> Mark Complete
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="card-footer">
                                        <small class="text-muted">
                                            <i class="bi bi-clock-history"></i> 
                                            Booked on <?php echo date('d M Y, h:i A', strtotime($booking['bookingdate'])); ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
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
        // Add animations
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.booking-card, .card');
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

        // Enhanced form handling
        document.getElementById('filterForm').addEventListener('submit', function(e) {
            const studentInput = document.getElementById('student');
            const dateInput = document.getElementById('date');
            
            // Validate student search input
            if (studentInput.value.length > 0 && studentInput.value.length < 2) {
                alert('Student search must be at least 2 characters long.');
                e.preventDefault();
                return false;
            }
            
            // Clean student input
            studentInput.value = studentInput.value.replace(/[<>'"]/g, '');
            
            // Validate date range
            if (dateInput.value) {
                const selectedDate = new Date(dateInput.value);
                const today = new Date();
                const maxPastDate = new Date();
                maxPastDate.setFullYear(maxPastDate.getFullYear() - 2);
                const maxFutureDate = new Date();
                maxFutureDate.setFullYear(maxFutureDate.getFullYear() + 1);
                
                if (selectedDate < maxPastDate || selectedDate > maxFutureDate) {
                    alert('Please select a date within a reasonable range.');
                    e.preventDefault();
                    return false;
                }
            }
        });

        // Real-time filter highlighting
        document.getElementById('student').addEventListener('input', function() {
            this.value = this.value.replace(/[<>'"]/g, '');
            if (this.value.length >= 2) {
                this.classList.add('filter-active');
            } else {
                this.classList.remove('filter-active');
            }
        });

        document.getElementById('status').addEventListener('change', function() {
            if (this.value) {
                this.classList.add('filter-active');
            } else {
                this.classList.remove('filter-active');
            }
        });

        document.getElementById('date').addEventListener('change', function() {
            if (this.value) {
                this.classList.add('filter-active');
            } else {
                this.classList.remove('filter-active');
            }
        });

        // Auto-refresh for pending bookings (only if no filters active)
        setInterval(function() {
            if (document.hasFocus()) {
                const pendingCards = document.querySelectorAll('.booking-card .status-pending');
                const urlParams = new URLSearchParams(window.location.search);
                
                if (pendingCards.length > 0 && !urlParams.has('status') && !urlParams.has('date') && !urlParams.has('student')) {
                    // Could implement AJAX refresh here instead of full page reload
                }
            }
        }, 120000); // 2 minutes
    </script>
</body>
</html>