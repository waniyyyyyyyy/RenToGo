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

// UPDATED: Handle booking cancellation with proper functionality
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['cancel_booking'])) {
    $bookingid = $_POST['bookingid'];
    
    // Check if booking can be cancelled (only pending bookings)
    $query = "SELECT bookingstatus, pickupdate FROM booking WHERE bookingid = ? AND userid = ?";
    $stmt = $db->prepare($query);
    $stmt->bindParam(1, $bookingid);
    $stmt->bindParam(2, $_SESSION['userid']);
    $stmt->execute();
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($booking && $booking['bookingstatus'] == 'pending') {
        // Check if pickup date is at least 1 hour from now (optional business rule)
        $pickup_time = strtotime($booking['pickupdate']);
        $current_time = time();
        $time_diff = $pickup_time - $current_time;
        
        if ($time_diff > 3600) { // At least 1 hour before pickup
            $query = "UPDATE booking SET bookingstatus = 'cancelled' WHERE bookingid = ? AND userid = ?";
            $stmt = $db->prepare($query);
            $stmt->bindParam(1, $bookingid);
            $stmt->bindParam(2, $_SESSION['userid']);
            
            if ($stmt->execute()) {
                $message = "Booking cancelled successfully. No charges will be applied.";
            } else {
                $message = "Error cancelling booking. Please try again.";
            }
        } else {
            $message = "Cannot cancel booking less than 1 hour before pickup time.";
        }
    } elseif ($booking && $booking['bookingstatus'] != 'pending') {
        $message = "This booking cannot be cancelled. Only pending bookings can be cancelled.";
    } else {
        $message = "Booking not found or you don't have permission to cancel it.";
    }
}

// Handle rating submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_rating'])) {
    $bookingid = $_POST['bookingid'];
    $driverid = $_POST['driverid'];
    $rating = (int)$_POST['rating'];
    $review = trim($_POST['review']);
    
    // Check if already rated
    $query = "SELECT ratingid FROM rating WHERE bookingid = ? AND userid = ?";
    $stmt = $db->prepare($query);
    $stmt->bindParam(1, $bookingid);
    $stmt->bindParam(2, $_SESSION['userid']);
    $stmt->execute();
    
    if ($stmt->rowCount() == 0) {
        // Insert new rating
        $query = "INSERT INTO rating (bookingid, userid, driverid, rating, review) VALUES (?, ?, ?, ?, ?)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(1, $bookingid);
        $stmt->bindParam(2, $_SESSION['userid']);
        $stmt->bindParam(3, $driverid);
        $stmt->bindParam(4, $rating);
        $stmt->bindParam(5, $review);
        
        if ($stmt->execute()) {
            // Update driver's average rating
            $query = "UPDATE driver SET rating = (SELECT AVG(rating) FROM rating WHERE driverid = ?) WHERE driverid = ?";
            $stmt = $db->prepare($query);
            $stmt->bindParam(1, $driverid);
            $stmt->bindParam(2, $driverid);
            $stmt->execute();
            
            $message = "Rating submitted successfully!";
        } else {
            $message = "Error submitting rating.";
        }
    } else {
        $message = "You have already rated this ride.";
    }
}

// Get filter parameters with proper validation
$status_filter = isset($_GET['status']) && $_GET['status'] !== '' ? trim($_GET['status']) : '';
$date_filter = isset($_GET['date']) && $_GET['date'] !== '' ? trim($_GET['date']) : '';

// UPDATED: Build query with driver's full name instead of username
$query = "SELECT b.*, d.carmodel, d.plate, d.car_type, u.full_name as driver_name, u.notel as driver_phone,
                 r.rating as user_rating, r.review as user_review
          FROM booking b
          INNER JOIN driver d ON b.driverid = d.driverid
          INNER JOIN user u ON d.userid = u.userid
          LEFT JOIN rating r ON b.bookingid = r.bookingid AND r.userid = b.userid
          WHERE b.userid = ?";

$params = [$_SESSION['userid']];

// Add filters with proper validation
if (!empty($status_filter)) {
    $query .= " AND b.bookingstatus = ?";
    $params[] = $status_filter;
}

if (!empty($date_filter)) {
    $query .= " AND DATE(b.pickupdate) = ?";
    $params[] = $date_filter;
}

$query .= " ORDER BY b.bookingdate DESC";

// Execute query with proper error handling
try {
    $stmt = $db->prepare($query);
    
    // Bind parameters properly
    foreach ($params as $index => $param) {
        $stmt->bindValue($index + 1, $param, PDO::PARAM_STR);
    }
    
    $stmt->execute();
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $bookings = [];
    $message = "Database error: " . $e->getMessage();
    error_log("Student bookings query error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bookings - RenToGo</title>
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

        .btn-outline-primary {
            border: 2px solid #6f42c1;
            color: #6f42c1;
        }

        .btn-outline-primary:hover {
            background: linear-gradient(135deg, #6f42c1 0%, #8b5cf6 100%);
            border-color: #6f42c1;
        }

        /* Enhanced cancel button styling */
        .btn-danger {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            border: none;
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.3);
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #c82333 0%, #a71e2a 100%);
            box-shadow: 0 6px 20px rgba(220, 53, 69, 0.4);
        }

        /* Status badges */
        .status-pending {
            background-color: #f59e0b;
            color: #000;
        }

        .status-confirmed {
            background-color: #3b82f6;
        }

        .status-completed {
            background-color: #10b981;
        }

        .status-cancelled {
            background-color: #ef4444;
        }

        /* Rating system */
        .rating-input input[type="radio"] {
            display: none;
        }
        
        .star-label {
            display: inline-block;
            margin: 0 2px;
            transition: color 0.2s ease;
        }
        
        .star-label:hover {
            transform: scale(1.1);
        }

        /* Cancel confirmation modal */
        .cancel-confirmation {
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

        .cancel-confirmation.show {
            display: flex;
            animation: fadeInModal 0.3s ease;
        }

        .cancel-confirmation-box {
            background: white;
            padding: 2rem;
            border-radius: 15px;
            text-align: center;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            transform: scale(0.9);
            transition: transform 0.3s ease;
        }

        .cancel-confirmation.show .cancel-confirmation-box {
            transform: scale(1);
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

        /* Animations */
        .fade-in {
            animation: fadeIn 0.5s ease-in forwards;
            opacity: 0;
        }

        @keyframes fadeIn {
            from { 
                opacity: 0; 
                transform: translateY(20px); 
            }
            to { 
                opacity: 1; 
                transform: translateY(0); 
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

        /* Loading state styles */
        .btn-loading {
            position: relative;
            pointer-events: none;
        }

        .btn-loading::after {
            content: "";
            position: absolute;
            width: 16px;
            height: 16px;
            top: 50%;
            left: 50%;
            margin: -8px 0 0 -8px;
            border: 2px solid transparent;
            border-top-color: currentColor;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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
                        <a class="nav-link active" href="my-bookings.php">
                            <i class="bi bi-list-ul"></i> My Bookings
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="browse-drivers.php">
                            <i class="bi bi-search"></i> Browse Drivers
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
                            <h4 class="mb-0">My Bookings</h4>
                            <small class="text-muted">View and manage your ride bookings</small>
                        </div>
                        <div class="col-auto">
                            <a href="book-ride.php" class="btn btn-primary">
                                <i class="bi bi-plus-circle"></i> Book New Ride
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <div class="container-fluid">
                    
                    <?php if (!empty($message)): ?>
                        <div class="alert alert-<?php echo strpos($message, 'Error') !== false || strpos($message, 'cannot') !== false || strpos($message, 'already') !== false || strpos($message, 'Cannot') !== false ? 'danger' : 'success'; ?> alert-dismissible fade show">
                            <i class="bi bi-<?php echo strpos($message, 'Error') !== false || strpos($message, 'cannot') !== false || strpos($message, 'already') !== false || strpos($message, 'Cannot') !== false ? 'exclamation-triangle' : 'check-circle'; ?>"></i> <?php echo htmlspecialchars($message); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <!-- Filters -->
                    <div class="card mb-4">
                        <div class="card-body">
                            <form method="GET" action="" class="row g-3" id="filterForm">
                                <div class="col-md-4">
                                    <label for="status" class="form-label fw-semibold">Filter by Status</label>
                                    <select class="form-control" id="status" name="status">
                                        <option value="">All Statuses</option>
                                        <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="confirmed" <?php echo $status_filter == 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                        <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                        <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="date" class="form-label fw-semibold">Filter by Pickup Date</label>
                                    <input type="date" class="form-control" id="date" name="date" value="<?php echo htmlspecialchars($date_filter); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">&nbsp;</label>
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-funnel"></i> Filter
                                        </button>
                                        <a href="my-bookings.php" class="btn btn-outline-secondary">
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
                                    <?php if (!empty($status_filter) || !empty($date_filter)): ?>
                                        No bookings match your current filters. Try adjusting your search criteria.
                                    <?php else: ?>
                                        You haven't made any bookings yet.
                                    <?php endif; ?>
                                </p>
                                <div class="mt-3">
                                    <?php if (!empty($status_filter) || !empty($date_filter)): ?>
                                        <a href="my-bookings.php" class="btn btn-outline-primary me-2">
                                            <i class="bi bi-arrow-clockwise"></i> Clear Filters
                                        </a>
                                    <?php endif; ?>
                                    <a href="book-ride.php" class="btn btn-primary">
                                        <i class="bi bi-plus-circle"></i> Book Your First Ride
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="mb-3">
                            <small class="text-muted">
                                <i class="bi bi-list-ul"></i> Found <?php echo count($bookings); ?> booking(s)
                            </small>
                        </div>
                        
                        <?php foreach ($bookings as $booking): ?>
                        <div class="card mb-4 fade-in">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-8">
                                        <div class="d-flex justify-content-between align-items-start mb-3">
                                            <div>
                                                <h5 class="mb-1">
                                                    <!-- Display driver's full name -->
                                                    <?php echo htmlspecialchars($booking['driver_name']); ?>
                                                    <span class="badge status-<?php echo $booking['bookingstatus']; ?> ms-2">
                                                        <?php echo ucfirst($booking['bookingstatus']); ?>
                                                    </span>
                                                </h5>
                                                <p class="text-muted mb-0">
                                                    <i class="bi bi-car-front"></i> 
                                                    <?php echo htmlspecialchars($booking['carmodel']); ?> - 
                                                    <?php echo htmlspecialchars($booking['plate']); ?>
                                                </p>
                                            </div>
                                            <div class="text-end">
                                                <h4 class="text-primary mb-0">RM <?php echo number_format($booking['totalcost'], 2); ?></h4>
                                                <small class="text-muted">
                                                    <?php if ($booking['bookingstatus'] == 'cancelled'): ?>
                                                        <span class="text-danger">(Not charged)</span>
                                                    <?php else: ?>
                                                        <?php echo isset($booking['distance_km']) ? $booking['distance_km'] . ' km' : $booking['duration_hours'] . ' hours'; ?>
                                                    <?php endif; ?>
                                                </small>
                                            </div>
                                        </div>
                                        
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <h6 class="text-muted mb-1">
                                                    <i class="bi bi-play-circle"></i> Pickup
                                                </h6>
                                                <p class="mb-1">
                                                    <i class="bi bi-calendar"></i> 
                                                    <?php echo date('d M Y, h:i A', strtotime($booking['pickupdate'])); ?>
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
                                        
                                        <?php if (!empty($booking['notes'])): ?>
                                        <div class="mb-3">
                                            <h6 class="text-muted mb-1">Notes</h6>
                                            <p class="mb-0"><?php echo htmlspecialchars($booking['notes']); ?></p>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <div class="mb-3">
                                            <small class="text-muted">
                                                <i class="bi bi-people"></i> <?php echo $booking['pax']; ?> passenger(s) • 
                                                <i class="bi bi-clock"></i> Booked on <?php echo date('d M Y, h:i A', strtotime($booking['bookingdate'])); ?>
                                            </small>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-4">
                                        <div class="h-100 d-flex flex-column justify-content-between">
                                            <div>
                                                <!-- Driver Contact -->
                                                <div class="mb-3">
                                                    <h6 class="text-muted mb-2">Driver Contact</h6>
                                                    <a href="tel:<?php echo $booking['driver_phone']; ?>" class="btn btn-outline-primary btn-sm w-100">
                                                        <i class="bi bi-telephone"></i> Call Driver
                                                    </a>
                                                    <small class="text-muted d-block mt-1"><?php echo htmlspecialchars($booking['driver_phone']); ?></small>
                                                </div>
                                            </div>
                                            
                                            <!-- Actions -->
                                            <div>
                                                <?php if ($booking['bookingstatus'] == 'pending'): ?>
                                                    <!-- UPDATED: Enhanced cancel button with confirmation -->
                                                    <button type="button" class="btn btn-danger btn-sm w-100" 
                                                            onclick="showCancelConfirmation(<?php echo $booking['bookingid']; ?>, '<?php echo htmlspecialchars($booking['driver_name'], ENT_QUOTES); ?>', '<?php echo date('d M Y, h:i A', strtotime($booking['pickupdate'])); ?>')">
                                                        <i class="bi bi-x-circle"></i> Cancel Booking
                                                    </button>
                                                    
                                                    <!-- Hidden form for actual cancellation -->
                                                    <form method="POST" action="" id="cancelForm<?php echo $booking['bookingid']; ?>" style="display: none;">
                                                        <input type="hidden" name="bookingid" value="<?php echo $booking['bookingid']; ?>">
                                                        <input type="hidden" name="cancel_booking" value="1">
                                                    </form>
                                                <?php elseif ($booking['bookingstatus'] == 'completed' && !$booking['user_rating']): ?>
                                                    <button class="btn btn-warning btn-sm w-100" data-bs-toggle="modal" 
                                                            data-bs-target="#ratingModal<?php echo $booking['bookingid']; ?>">
                                                        <i class="bi bi-star"></i> Rate Driver
                                                    </button>
                                                <?php elseif ($booking['user_rating']): ?>
                                                    <div class="text-center">
                                                        <div class="text-warning mb-1">
                                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                                <i class="bi bi-star<?php echo $i <= $booking['user_rating'] ? '-fill' : ''; ?>"></i>
                                                            <?php endfor; ?>
                                                        </div>
                                                        <small class="text-muted">You rated this ride</small>
                                                        <?php if (!empty($booking['user_review'])): ?>
                                                            <div class="mt-2">
                                                                <small class="text-muted d-block">"<?php echo htmlspecialchars($booking['user_review']); ?>"</small>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Rating Modal -->
                        <?php if ($booking['bookingstatus'] == 'completed' && !$booking['user_rating']): ?>
                        <div class="modal fade" id="ratingModal<?php echo $booking['bookingid']; ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">
                                            <i class="bi bi-star"></i> Rate Your Ride with <?php echo htmlspecialchars($booking['driver_name']); ?>
                                        </h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <form method="POST" action="">
                                        <div class="modal-body">
                                            <input type="hidden" name="bookingid" value="<?php echo $booking['bookingid']; ?>">
                                            <input type="hidden" name="driverid" value="<?php echo $booking['driverid']; ?>">
                                            
                                            <div class="mb-3">
                                                <label class="form-label fw-semibold">Rating *</label>
                                                <div class="rating-input text-center">
                                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                                        <input type="radio" id="rating<?php echo $booking['bookingid']; ?>_<?php echo $i; ?>" 
                                                               name="rating" value="<?php echo $i; ?>" required>
                                                        <label for="rating<?php echo $booking['bookingid']; ?>_<?php echo $i; ?>" 
                                                               class="star-label">
                                                            <i class="bi bi-star-fill" style="font-size: 2rem; color: #ddd; cursor: pointer;"></i>
                                                        </label>
                                                    <?php endfor; ?>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label for="review<?php echo $booking['bookingid']; ?>" class="form-label fw-semibold">Review (Optional)</label>
                                                <textarea class="form-control" id="review<?php echo $booking['bookingid']; ?>" 
                                                          name="review" rows="3" 
                                                          placeholder="Share your experience with other students..."></textarea>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" name="submit_rating" class="btn btn-primary">
                                                <i class="bi bi-check-circle"></i> Submit Rating
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Cancel Booking Confirmation Modal -->
    <div class="cancel-confirmation" id="cancelConfirmation">
        <div class="cancel-confirmation-box">
            <div class="mb-3">
                <i class="bi bi-exclamation-triangle text-warning" style="font-size: 3rem;"></i>
            </div>
            <h5 class="mb-3">Cancel Booking</h5>
            <div id="cancelDetails" class="mb-4">
                <!-- Details will be populated by JavaScript -->
            </div>
            <div class="alert alert-warning">
                <i class="bi bi-info-circle"></i>
                <strong>Important:</strong> Once cancelled, this booking cannot be restored. No charges will be applied for cancelled bookings.
            </div>
            <div class="d-flex gap-2 justify-content-center">
                <button class="btn btn-secondary" onclick="hideCancelConfirmation()">
                    <i class="bi bi-x-lg"></i> Keep Booking
                </button>
                <button class="btn btn-danger" id="confirmCancelBtn" onclick="confirmCancellation()">
                    <i class="bi bi-check-circle"></i> Yes, Cancel Booking
                </button>
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
        // Global variable to store booking ID for cancellation
        let currentBookingId = null;

        // Show cancel confirmation modal with booking details
        function showCancelConfirmation(bookingId, driverName, pickupDate) {
            currentBookingId = bookingId;
            const modal = document.getElementById('cancelConfirmation');
            const details = document.getElementById('cancelDetails');
            
            details.innerHTML = `
                <p class="text-muted mb-2">You are about to cancel your booking with:</p>
                <div class="text-center p-3 bg-light rounded">
                    <strong class="text-primary">${driverName}</strong><br>
                    <small class="text-muted">Pickup: ${pickupDate}</small>
                </div>
            `;
            
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        // Hide cancel confirmation modal
        function hideCancelConfirmation() {
            const modal = document.getElementById('cancelConfirmation');
            modal.classList.remove('show');
            document.body.style.overflow = 'auto';
            currentBookingId = null;
        }

        // Confirm cancellation and submit form
        function confirmCancellation() {
            if (currentBookingId) {
                const confirmBtn = document.getElementById('confirmCancelBtn');
                const originalText = confirmBtn.innerHTML;
                
                // Show loading state
                confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Cancelling...';
                confirmBtn.disabled = true;
                
                // Submit the form
                const form = document.getElementById(`cancelForm${currentBookingId}`);
                if (form) {
                    form.submit();
                } else {
                    // Fallback: create and submit form
                    const fallbackForm = document.createElement('form');
                    fallbackForm.method = 'POST';
                    fallbackForm.action = '';
                    
                    const bookingInput = document.createElement('input');
                    bookingInput.type = 'hidden';
                    bookingInput.name = 'bookingid';
                    bookingInput.value = currentBookingId;
                    
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'cancel_booking';
                    actionInput.value = '1';
                    
                    fallbackForm.appendChild(bookingInput);
                    fallbackForm.appendChild(actionInput);
                    document.body.appendChild(fallbackForm);
                    fallbackForm.submit();
                }
            }
        }

        // Enhanced filtering with proper form handling
        document.addEventListener('DOMContentLoaded', function() {
            const filterForm = document.getElementById('filterForm');
            const statusSelect = document.getElementById('status');
            const dateInput = document.getElementById('date');
            
            // Immediate submit for status changes
            if (statusSelect) {
                statusSelect.addEventListener('change', function() {
                    filterForm.submit();
                });
            }
            
            // Immediate submit for date changes
            if (dateInput) {
                dateInput.addEventListener('change', function() {
                    filterForm.submit();
                });
            }

            // Rating system
            const ratingInputs = document.querySelectorAll('.rating-input');
            
            ratingInputs.forEach(ratingGroup => {
                const inputs = ratingGroup.querySelectorAll('input[type="radio"]');
                const labels = ratingGroup.querySelectorAll('.star-label i');
                
                inputs.forEach((input, index) => {
                    input.addEventListener('change', function() {
                        labels.forEach((label, labelIndex) => {
                            if (labelIndex <= index) {
                                label.style.color = '#ffc107';
                            } else {
                                label.style.color = '#ddd';
                            }
                        });
                    });
                });
                
                // Hover effects
                labels.forEach((label, index) => {
                    label.addEventListener('mouseenter', function() {
                        labels.forEach((l, lIndex) => {
                            if (lIndex <= index) {
                                l.style.color = '#ffc107';
                            } else {
                                l.style.color = '#ddd';
                            }
                        });
                    });
                });
                
                ratingGroup.addEventListener('mouseleave', function() {
                    const checkedInput = ratingGroup.querySelector('input[type="radio"]:checked');
                    if (checkedInput) {
                        const checkedIndex = Array.from(inputs).indexOf(checkedInput);
                        labels.forEach((label, labelIndex) => {
                            if (labelIndex <= checkedIndex) {
                                label.style.color = '#ffc107';
                            } else {
                                label.style.color = '#ddd';
                            }
                        });
                    } else {
                        labels.forEach(label => {
                            label.style.color = '#ddd';
                        });
                    }
                });
            });

            // Add fade-in animations
            const cards = document.querySelectorAll('.card');
            cards.forEach((card, index) => {
                card.style.animationDelay = (index * 0.1) + 's';
            });

            // Form validation and loading states
            filterForm.addEventListener('submit', function(e) {
                const submitBtn = this.querySelector('button[type="submit"]');
                if (submitBtn) {
                    const originalText = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Filtering...';
                    submitBtn.disabled = true;
                    
                    // Re-enable after timeout
                    setTimeout(() => {
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }, 5000);
                }
            });

            // Enhanced form feedback for POST forms
            const postForms = document.querySelectorAll('form[method="POST"]');
            postForms.forEach(form => {
                form.addEventListener('submit', function() {
                    const submitBtn = this.querySelector('button[type="submit"]');
                    if (submitBtn && !submitBtn.disabled) {
                        const originalText = submitBtn.innerHTML;
                        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';
                        submitBtn.disabled = true;
                        
                        // Re-enable after timeout
                        setTimeout(() => {
                            submitBtn.innerHTML = originalText;
                            submitBtn.disabled = false;
                        }, 5000);
                    }
                });
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

        // Close modals when clicking outside
        document.getElementById('logoutConfirmation').addEventListener('click', function(e) {
            if (e.target === this) {
                hideLogoutConfirmation();
            }
        });

        document.getElementById('cancelConfirmation').addEventListener('click', function(e) {
            if (e.target === this) {
                hideCancelConfirmation();
            }
        });

        // Close modals with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                hideLogoutConfirmation();
                hideCancelConfirmation();
            }
        });
    </script>
</body>
</html>