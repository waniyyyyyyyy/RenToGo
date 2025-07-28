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

// Get driver info with full name
$query = "SELECT u.*, d.* FROM user u 
          JOIN driver d ON u.userid = d.userid 
          WHERE u.userid = ?";
$stmt = $db->prepare($query);
$stmt->bindParam(1, $_SESSION['userid']);
$stmt->execute();
$driver = $stmt->fetch(PDO::FETCH_ASSOC);

// Handle status update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_status'])) {
    $new_status = $_POST['status'];
    $query = "UPDATE driver SET status = ? WHERE userid = ?";
    $stmt = $db->prepare($query);
    $stmt->bindParam(1, $new_status);
    $stmt->bindParam(2, $_SESSION['userid']);
    $stmt->execute();
    header("Location: dashboard.php");
    exit();
}

// Handle booking action
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['booking_action'])) {
    $bookingid = $_POST['bookingid'];
    $action = $_POST['action'];
    
    $new_status = ($action == 'accept') ? 'confirmed' : 'cancelled';
    $query = "UPDATE booking SET bookingstatus = ? WHERE bookingid = ? AND driverid = ?";
    $stmt = $db->prepare($query);
    $stmt->bindParam(1, $new_status);
    $stmt->bindParam(2, $bookingid);
    $stmt->bindParam(3, $driver['driverid']);
    $stmt->execute();
    
    $message = ($action == 'accept') ? "Booking confirmed successfully!" : "Booking cancelled.";
}

// Get driver's booking statistics - only count earnings for completed bookings
$query = "SELECT 
            COUNT(*) as total_bookings,
            COUNT(CASE WHEN bookingstatus = 'pending' THEN 1 END) as pending_bookings,
            COUNT(CASE WHEN bookingstatus = 'confirmed' THEN 1 END) as confirmed_bookings,
            COUNT(CASE WHEN bookingstatus = 'completed' THEN 1 END) as completed_bookings,
            COUNT(CASE WHEN bookingstatus = 'cancelled' THEN 1 END) as cancelled_bookings,
            COALESCE(SUM(CASE WHEN bookingstatus = 'completed' THEN totalcost END), 0) as total_earnings,
            COALESCE(SUM(CASE WHEN bookingstatus IN ('confirmed', 'completed') THEN totalcost END), 0) as total_potential_earnings
          FROM booking WHERE driverid = ?";
$stmt = $db->prepare($query);
$stmt->bindParam(1, $driver['driverid']);
$stmt->execute();
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get recent bookings with payment status
$query = "SELECT b.*, u.username as student_name, u.full_name as student_full_name, s.student_number, s.faculty,
            CASE 
                WHEN b.bookingstatus = 'completed' THEN 'Paid'
                WHEN b.bookingstatus = 'confirmed' THEN 'Unpaid'
                WHEN b.bookingstatus = 'pending' THEN 'Unpaid'
                WHEN b.bookingstatus = 'cancelled' THEN 'N/A'
                ELSE 'Unpaid'
            END as payment_status
          FROM booking b
          JOIN user u ON b.userid = u.userid
          JOIN student s ON u.userid = s.userid
          WHERE b.driverid = ?
          ORDER BY b.bookingdate DESC
          LIMIT 8";
$stmt = $db->prepare($query);
$stmt->bindParam(1, $driver['driverid']);
$stmt->execute();
$recent_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get pending bookings
$query = "SELECT b.*, u.username as student_name, u.full_name as student_full_name, u.notel, s.student_number 
          FROM booking b
          JOIN user u ON b.userid = u.userid
          JOIN student s ON u.userid = s.userid
          WHERE b.driverid = ? AND b.bookingstatus = 'pending'
          ORDER BY b.bookingdate DESC";
$stmt = $db->prepare($query);
$stmt->bindParam(1, $driver['driverid']);
$stmt->execute();
$pending_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get pricing for this car type
$pricing_info = null;
try {
    $query = "SELECT * FROM pricing_settings WHERE car_type = ?";
    $stmt = $db->prepare($query);
    $stmt->bindParam(1, $driver['car_type']);
    $stmt->execute();
    $pricing_info = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Pricing table might not exist yet
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driver Dashboard - RenToGo</title>
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

        .stats-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            transition: transform 0.3s ease;
        }

        .stats-card:hover {
            transform: translateY(-5px);
        }

        .stats-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
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
        .status-available {
            background-color: #28a745;
        }

        .status-not-available {
            background-color: #6c757d;
        }

        .status-maintenance {
            background-color: #ffc107;
            color: #000;
        }

        .status-pending {
            background-color: #ffc107;
            color: #000;
        }

        .status-confirmed {
            background-color: #28a745;
        }

        .status-completed {
            background-color: #007bff;
        }

        .status-cancelled {
            background-color: #dc3545;
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

        .pricing-info {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border-radius: 10px;
            padding: 1rem;
            margin-top: 1rem;
        }

        .pricing-detail {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .pricing-detail:last-child {
            margin-bottom: 0;
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
                        <a class="nav-link active" href="dashboard.php">
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
                            <h4 class="mb-0">Welcome back, <?php echo htmlspecialchars($driver['full_name'] ?? $driver['username']); ?>!</h4>
                            <small class="text-muted">
                                <?php echo htmlspecialchars($driver['carmodel']); ?> - <?php echo htmlspecialchars($driver['plate']); ?>
                                <span class="badge status-<?php echo str_replace(' ', '-', $driver['status']); ?> ms-2">
                                    <?php echo ucfirst(str_replace('_', ' ', $driver['status'])); ?>
                                </span>
                            </small>
                        </div>
                        <div class="col-auto">
                            <!-- Status Toggle -->
                            <form method="POST" action="" class="d-inline">
                                <select name="status" class="form-select form-select-sm d-inline-block w-auto" onchange="this.form.submit()">
                                    <option value="available" <?php echo $driver['status'] == 'available' ? 'selected' : ''; ?>>Available</option>
                                    <option value="not available" <?php echo $driver['status'] == 'not available' ? 'selected' : ''; ?>>Not Available</option>
                                    <option value="maintenance" <?php echo $driver['status'] == 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                                </select>
                                <input type="hidden" name="update_status" value="1">
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <div class="container-fluid">
                    
                    <?php if (isset($message)): ?>
                        <div class="alert alert-success alert-dismissible fade show">
                            <i class="bi bi-check-circle"></i> <?php echo $message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <!-- Statistics Cards -->
                    <div class="row g-4 mb-4">
                        <div class="col-md-3">
                            <div class="stats-card">
                                <div class="d-flex align-items-center">
                                    <div class="stats-icon bg-primary me-3">
                                        <i class="bi bi-calendar-check"></i>
                                    </div>
                                    <div>
                                        <h4 class="mb-0"><?php echo $stats['total_bookings']; ?></h4>
                                        <small class="text-muted">Total Bookings</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stats-card">
                                <div class="d-flex align-items-center">
                                    <div class="stats-icon bg-warning me-3">
                                        <i class="bi bi-clock"></i>
                                    </div>
                                    <div>
                                        <h4 class="mb-0"><?php echo $stats['pending_bookings']; ?></h4>
                                        <small class="text-muted">Pending</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stats-card">
                                <div class="d-flex align-items-center">
                                    <div class="stats-icon bg-success me-3">
                                        <i class="bi bi-check-circle"></i>
                                    </div>
                                    <div>
                                        <h4 class="mb-0"><?php echo $stats['completed_bookings']; ?></h4>
                                        <small class="text-muted">Completed</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stats-card">
                                <div class="d-flex align-items-center">
                                    <div class="stats-icon bg-info me-3">
                                        <i class="bi bi-currency-dollar"></i>
                                    </div>
                                    <div>
                                        <h4 class="mb-0">RM <?php echo number_format($stats['total_earnings'], 2); ?></h4>
                                        <small class="text-muted">Paid Earnings</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-4">
                        <!-- Pending Bookings -->
                        <div class="col-lg-6">
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">
                                        <i class="bi bi-clock-history"></i> Pending Bookings
                                        <?php if (count($pending_bookings) > 0): ?>
                                            <span class="badge bg-warning"><?php echo count($pending_bookings); ?></span>
                                        <?php endif; ?>
                                    </h5>
                                    <a href="bookings.php" class="btn btn-sm btn-outline-primary">View All</a>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($pending_bookings)): ?>
                                        <div class="text-center py-4">
                                            <i class="bi bi-calendar-check text-muted" style="font-size: 3rem;"></i>
                                            <h6 class="text-muted mt-2">No pending bookings</h6>
                                            <p class="text-muted">You're all caught up!</p>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($pending_bookings as $booking): ?>
                                        <div class="border rounded p-3 mb-3">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <div>
                                                    <h6 class="mb-1">
                                                        <?php echo htmlspecialchars($booking['student_full_name'] ?? $booking['student_name']); ?>
                                                    </h6>
                                                    <small class="text-muted">
                                                        <?php echo htmlspecialchars($booking['student_number']); ?>
                                                    </small>
                                                </div>
                                                <div class="text-end">
                                                    <span class="badge bg-warning">Pending</span>
                                                    <br>
                                                    <span class="badge payment-unpaid mt-1">Unpaid</span>
                                                </div>
                                            </div>
                                            <p class="mb-2">
                                                <i class="bi bi-calendar"></i> 
                                                <?php echo date('d M Y, h:i A', strtotime($booking['pickupdate'])); ?>
                                                <br>
                                                <i class="bi bi-geo-alt"></i> 
                                                <?php echo htmlspecialchars($booking['pickuplocation']); ?> → 
                                                <?php echo htmlspecialchars($booking['dropofflocation']); ?>
                                                <br>
                                                <i class="bi bi-people"></i> 
                                                <?php echo $booking['pax']; ?> passenger(s) • 
                                                <i class="bi bi-currency-dollar"></i> 
                                                RM <?php echo number_format($booking['totalcost'], 2); ?> (If accepted)
                                            </p>
                                            <div class="d-flex gap-2">
                                                <form method="POST" action="" class="d-inline">
                                                    <input type="hidden" name="bookingid" value="<?php echo $booking['bookingid']; ?>">
                                                    <input type="hidden" name="action" value="accept">
                                                    <button type="submit" name="booking_action" class="btn btn-success btn-sm">
                                                        <i class="bi bi-check"></i> Accept
                                                    </button>
                                                </form>
                                                <form method="POST" action="" class="d-inline">
                                                    <input type="hidden" name="bookingid" value="<?php echo $booking['bookingid']; ?>">
                                                    <input type="hidden" name="action" value="decline">
                                                    <button type="submit" name="booking_action" class="btn btn-danger btn-sm">
                                                        <i class="bi bi-x"></i> Decline
                                                    </button>
                                                </form>
                                                <a href="tel:<?php echo $booking['notel']; ?>" class="btn btn-outline-primary btn-sm">
                                                    <i class="bi bi-telephone"></i> Call
                                                </a>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Recent Bookings -->
                        <div class="col-lg-6">
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">
                                        <i class="bi bi-list-ul"></i> Recent Bookings
                                    </h5>
                                    <a href="bookings.php" class="btn btn-sm btn-outline-primary">View All</a>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($recent_bookings)): ?>
                                        <div class="text-center py-4">
                                            <i class="bi bi-calendar-x text-muted" style="font-size: 3rem;"></i>
                                            <h6 class="text-muted mt-2">No bookings yet</h6>
                                            <p class="text-muted">Bookings will appear here once students start booking your car.</p>
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm">
                                                <tbody>
                                                    <?php foreach ($recent_bookings as $booking): ?>
                                                    <tr>
                                                        <td>
                                                            <div>
                                                                <strong><?php echo htmlspecialchars($booking['student_full_name'] ?? $booking['student_name']); ?></strong>
                                                                <br>
                                                                <small class="text-muted">
                                                                    <?php echo date('d M Y', strtotime($booking['pickupdate'])); ?>
                                                                </small>
                                                            </div>
                                                        </td>
                                                        <td class="text-end">
                                                            <span class="badge status-<?php echo $booking['bookingstatus']; ?>">
                                                                <?php echo ucfirst($booking['bookingstatus']); ?>
                                                            </span>
                                                            <br>
                                                            <span class="badge payment-<?php echo strtolower(str_replace(' ', '', $booking['payment_status'])); ?> mt-1">
                                                                <?php echo $booking['payment_status']; ?>
                                                            </span>
                                                            <br>
                                                            <small class="text-muted">
                                                                <?php if ($booking['bookingstatus'] == 'cancelled'): ?>
                                                                    N/A
                                                                <?php else: ?>
                                                                    RM <?php echo number_format($booking['totalcost'], 2); ?>
                                                                <?php endif; ?>
                                                            </small>
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

                    <!-- Car Info Card -->
                    <div class="row g-4 mt-2">
                        <div class="col-lg-8">
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">
                                        <i class="bi bi-car-front"></i> Vehicle Information
                                    </h5>
                                    <a href="car-details.php" class="btn btn-sm btn-outline-primary">Edit Details</a>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <h6 class="text-primary"><?php echo htmlspecialchars($driver['carmodel']); ?></h6>
                                            <ul class="list-unstyled">
                                                <li><i class="bi bi-credit-card text-muted me-2"></i> <?php echo htmlspecialchars($driver['plate']); ?></li>
                                                <li><i class="bi bi-people text-muted me-2"></i> <?php echo $driver['capacity']; ?> passengers</li>
                                                <li><i class="bi bi-tag text-muted me-2"></i> <?php echo htmlspecialchars($driver['car_type']); ?></li>
                                            </ul>
                                        </div>
                                        <div class="col-md-6">
                                            <h6>Driver Information</h6>
                                            <ul class="list-unstyled">
                                                <li><i class="bi bi-person text-muted me-2"></i> <?php echo htmlspecialchars($driver['full_name'] ?? $driver['username']); ?></li>
                                                <li><i class="bi bi-card-text text-muted me-2"></i> <?php echo htmlspecialchars($driver['licensenumber']); ?></li>
                                                <li><i class="bi bi-calendar text-muted me-2"></i> Joined <?php echo date('d M Y', strtotime($driver['datehired'])); ?></li>
                                                <li><i class="bi bi-star text-muted me-2"></i> Rating: <?php echo $driver['rating'] > 0 ? number_format($driver['rating'], 1) . '/5' : 'Not rated yet'; ?></li>
                                            </ul>
                                        </div>
                                    </div>
                                    
                                    <!-- Distance-Based Pricing Information -->
                                    <?php if ($pricing_info): ?>
                                    <div class="pricing-info">
                                        <h6 class="mb-3">
                                            <i class="bi bi-currency-dollar"></i> Distance-Based Pricing for <?php echo $driver['car_type']; ?>
                                        </h6>
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="pricing-detail">
                                                    <span>Base Fare:</span>
                                                    <strong>RM <?php echo number_format($pricing_info['base_fare'], 2); ?></strong>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="pricing-detail">
                                                    <span>Per KM:</span>
                                                    <strong>RM <?php echo number_format($pricing_info['price_per_km'], 2); ?></strong>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="pricing-detail">
                                                    <span>Minimum:</span>
                                                    <strong>RM <?php echo number_format($pricing_info['minimum_fare'], 2); ?></strong>
                                                </div>
                                            </div>
                                        </div>
                                        <hr class="my-2" style="border-color: rgba(255,255,255,0.3);">
                                        <small class="d-block text-center opacity-75">
                                            <i class="bi bi-info-circle me-1"></i>
                                            Formula: Base Fare + (Distance × Price per KM) | Minimum fare applies for short trips
                                        </small>
                                    </div>
                                    <?php else: ?>
                                    <div class="alert alert-warning mt-3">
                                        <i class="bi bi-exclamation-triangle"></i>
                                        <strong>No pricing set for <?php echo $driver['car_type']; ?> vehicles.</strong>
                                        <br>
                                        <small>Contact admin to set up distance-based pricing for your car type.</small>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Quick Actions -->
                        <div class="col-lg-4">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0">
                                        <i class="bi bi-lightning-charge"></i> Quick Actions
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="d-grid gap-2">
                                        <a href="bookings.php" class="btn btn-primary">
                                            <i class="bi bi-calendar-check"></i> Manage Bookings
                                        </a>
                                        <a href="car-details.php" class="btn btn-outline-primary">
                                            <i class="bi bi-car-front"></i> Update Car Details
                                        </a>
                                        <a href="earnings.php" class="btn btn-outline-success">
                                            <i class="bi bi-currency-dollar"></i> View Earnings
                                        </a>
                                        <a href="profile.php" class="btn btn-outline-info">
                                            <i class="bi bi-person"></i> Edit Profile
                                        </a>
                                    </div>
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
            const cards = document.querySelectorAll('.card, .stats-card');
            cards.forEach((card, index) => {
                card.style.animationDelay = (index * 0.1) + 's';
                card.classList.add('fade-in');
            });
        });

        // Logout confirmation functions
        function showLogoutConfirmation() {
            const modal = document.getElementById('logoutConfirmation');
            modal.classList.add('show');
            
            // Prevent body scroll when modal is open
            document.body.style.overflow = 'hidden';
        }

        function hideLogoutConfirmation() {
            const modal = document.getElementById('logoutConfirmation');
            modal.classList.remove('show');
            
            // Restore body scroll
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

        // Auto-refresh for pending bookings
        setInterval(function() {
            // Check for new pending bookings every 30 seconds
            const pendingCount = <?php echo count($pending_bookings); ?>;
            if (document.hasFocus() && pendingCount === 0) {
                // Only refresh if no pending bookings to avoid disrupting user actions
                fetch(window.location.href)
                    .then(response => response.text())
                    .then(html => {
                        const parser = new DOMParser();
                        const newDoc = parser.parseFromString(html, 'text/html');
                        const newPendingCount = newDoc.querySelectorAll('.badge.bg-warning').length;
                        if (newPendingCount > pendingCount) {
                            location.reload();
                        }
                    });
            }
        }, 30000);

        // Add confirmation for booking actions
        document.querySelectorAll('button[name="booking_action"]').forEach(button => {
            button.addEventListener('click', function(e) {
                const action = this.parentElement.querySelector('input[name="action"]').value;
                const actionText = action === 'accept' ? 'accept' : 'decline';
                
                if (!confirm(`Are you sure you want to ${actionText} this booking?`)) {
                    e.preventDefault();
                }
            });
        });
    </script>
</body>
</html>