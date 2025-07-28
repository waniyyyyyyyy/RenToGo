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
$query = "SELECT d.*, u.username, u.full_name FROM driver d 
          JOIN user u ON d.userid = u.userid 
          WHERE d.userid = ?";
$stmt = $db->prepare($query);
$stmt->bindParam(1, $_SESSION['userid']);
$stmt->execute();
$driver = $stmt->fetch(PDO::FETCH_ASSOC);

// Get date filters - allow future dates for bookings
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01'); // First day of current month
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d', strtotime('+30 days')); // Today + 30 days to include future bookings

// Get earnings summary - Only completed bookings count as actual earnings
$query = "SELECT 
            COUNT(*) as total_bookings,
            COUNT(CASE WHEN bookingstatus = 'completed' THEN 1 END) as completed_bookings,
            COUNT(CASE WHEN bookingstatus = 'confirmed' THEN 1 END) as confirmed_bookings,
            COUNT(CASE WHEN bookingstatus = 'pending' THEN 1 END) as pending_bookings,
            COUNT(CASE WHEN bookingstatus = 'cancelled' THEN 1 END) as cancelled_bookings,
            COALESCE(SUM(CASE WHEN bookingstatus = 'completed' THEN totalcost END), 0) as total_earnings,
            COALESCE(SUM(CASE WHEN bookingstatus = 'confirmed' THEN totalcost END), 0) as pending_earnings,
            COALESCE(AVG(CASE WHEN bookingstatus = 'completed' THEN totalcost END), 0) as avg_earning,
            COALESCE(MAX(CASE WHEN bookingstatus = 'completed' THEN totalcost END), 0) as highest_earning,
            COALESCE(MIN(CASE WHEN bookingstatus = 'completed' THEN totalcost END), 0) as lowest_earning
          FROM booking 
          WHERE driverid = ? AND pickupdate BETWEEN ? AND ?";
$stmt = $db->prepare($query);
$driverid = $driver['driverid'];
$end_date_time = $end_date . ' 23:59:59';
$stmt->bindParam(1, $driverid);
$stmt->bindParam(2, $start_date);
$stmt->bindParam(3, $end_date_time);
$stmt->execute();
$earnings_summary = $stmt->fetch(PDO::FETCH_ASSOC);

// Get detailed earnings by date
$query = "SELECT 
            DATE(pickupdate) as booking_date,
            COUNT(*) as daily_bookings,
            SUM(CASE WHEN bookingstatus = 'completed' THEN totalcost ELSE 0 END) as daily_earnings,
            SUM(CASE WHEN bookingstatus = 'confirmed' THEN totalcost ELSE 0 END) as daily_pending_earnings,
            COUNT(CASE WHEN bookingstatus = 'completed' THEN 1 END) as completed_count,
            COUNT(CASE WHEN bookingstatus = 'confirmed' THEN 1 END) as confirmed_count
          FROM booking 
          WHERE driverid = ? AND pickupdate BETWEEN ? AND ?
          GROUP BY DATE(pickupdate)
          ORDER BY booking_date DESC";
$stmt = $db->prepare($query);
$stmt->bindParam(1, $driverid);
$stmt->bindParam(2, $start_date);
$stmt->bindParam(3, $end_date_time);
$stmt->execute();
$daily_earnings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all bookings for detailed view with payment status
$query = "SELECT b.*, u.username as student_name, u.full_name as student_full_name, s.student_number,
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
          JOIN user u ON b.userid = u.userid
          JOIN student s ON u.userid = s.userid
          WHERE b.driverid = ? 
          AND b.pickupdate BETWEEN ? AND ?
          ORDER BY b.pickupdate DESC
          LIMIT 50";
$stmt = $db->prepare($query);
$stmt->bindParam(1, $driverid);
$stmt->bindParam(2, $start_date);
$stmt->bindParam(3, $end_date_time);
$stmt->execute();
$all_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get monthly earnings for chart (last 6 months)
$query = "SELECT 
            DATE_FORMAT(pickupdate, '%Y-%m') as month,
            COUNT(CASE WHEN bookingstatus = 'completed' THEN 1 END) as completed_bookings,
            COUNT(CASE WHEN bookingstatus = 'confirmed' THEN 1 END) as confirmed_bookings,
            SUM(CASE WHEN bookingstatus = 'completed' THEN totalcost ELSE 0 END) as monthly_earnings,
            SUM(CASE WHEN bookingstatus = 'confirmed' THEN totalcost ELSE 0 END) as monthly_pending_earnings
          FROM booking 
          WHERE driverid = ? AND pickupdate >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
          GROUP BY DATE_FORMAT(pickupdate, '%Y-%m')
          ORDER BY month ASC";
$stmt = $db->prepare($query);
$stmt->bindParam(1, $driverid);
$stmt->execute();
$monthly_earnings = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Earnings - RenToGo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        .chart-container {
            position: relative;
            height: 400px;
            width: 100%;
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
            from { opacity: 0; }
            to { opacity: 1; }
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
            
            .chart-container {
                height: 300px;
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
                        <a class="nav-link active" href="earnings.php">
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
                            <h4 class="mb-0 fw-bold">Earnings & Statistics</h4>
                            <small class="text-muted">Track your income and performance for <?php echo htmlspecialchars($driver['full_name'] ?? $driver['username']); ?></small>
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
                    
                    <!-- Date Filter -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-body">
                                    <form method="GET" action="" class="row g-3 align-items-end">
                                        <div class="col-md-4">
                                            <label for="start_date" class="form-label">From Date</label>
                                            <input type="date" class="form-control" id="start_date" name="start_date" 
                                                   value="<?php echo $start_date; ?>">
                                        </div>
                                        <div class="col-md-4">
                                            <label for="end_date" class="form-label">To Date</label>
                                            <input type="date" class="form-control" id="end_date" name="end_date" 
                                                   value="<?php echo $end_date; ?>">
                                        </div>
                                        <div class="col-md-4">
                                            <button type="submit" class="btn btn-primary">
                                                <i class="bi bi-funnel"></i> Filter Results
                                            </button>
                                            <a href="earnings.php" class="btn btn-outline-secondary">
                                                <i class="bi bi-arrow-clockwise"></i> Reset
                                            </a>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Statistics Cards -->
                    <div class="row g-4 mb-4">
                        <div class="col-md-3">
                            <div class="stats-card">
                                <div class="d-flex align-items-center">
                                    <div class="stats-icon bg-success me-3">
                                        <i class="bi bi-currency-dollar"></i>
                                    </div>
                                    <div>
                                        <h4 class="mb-0">RM <?php echo number_format($earnings_summary['total_earnings'], 2); ?></h4>
                                        <small class="text-muted">Paid Earnings</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stats-card">
                                <div class="d-flex align-items-center">
                                    <div class="stats-icon bg-warning me-3">
                                        <i class="bi bi-clock-history"></i>
                                    </div>
                                    <div>
                                        <h4 class="mb-0">RM <?php echo number_format($earnings_summary['pending_earnings'], 2); ?></h4>
                                        <small class="text-muted">Pending Payment</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stats-card">
                                <div class="d-flex align-items-center">
                                    <div class="stats-icon bg-primary me-3">
                                        <i class="bi bi-check-circle"></i>
                                    </div>
                                    <div>
                                        <h4 class="mb-0"><?php echo $earnings_summary['completed_bookings']; ?></h4>
                                        <small class="text-muted">Completed Trips</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stats-card">
                                <div class="d-flex align-items-center">
                                    <div class="stats-icon bg-info me-3">
                                        <i class="bi bi-bar-chart"></i>
                                    </div>
                                    <div>
                                        <h4 class="mb-0">RM <?php echo number_format($earnings_summary['avg_earning'], 2); ?></h4>
                                        <small class="text-muted">Average per Trip</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-4">
                        <!-- Earnings Chart -->
                        <div class="col-lg-8">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0">
                                        <i class="bi bi-graph-up"></i> Monthly Earnings Trend (Last 6 Months)
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="chart-container">
                                        <canvas id="earningsChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Daily Breakdown -->
                        <div class="col-lg-4">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0">
                                        <i class="bi bi-calendar-day"></i> Daily Breakdown
                                    </h5>
                                </div>
                                <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                                    <?php if (empty($daily_earnings)): ?>
                                        <div class="text-center py-4">
                                            <i class="bi bi-calendar-x text-muted" style="font-size: 3rem;"></i>
                                            <h6 class="text-muted mt-2">No data for this period</h6>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($daily_earnings as $day): ?>
                                        <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                            <div>
                                                <h6 class="mb-0"><?php echo date('d M Y', strtotime($day['booking_date'])); ?></h6>
                                                <small class="text-muted">
                                                    <?php echo $day['completed_count']; ?> paid / <?php echo $day['confirmed_count']; ?> unpaid / <?php echo $day['daily_bookings']; ?> total
                                                </small>
                                            </div>
                                            <div class="text-end">
                                                <strong class="text-success">RM <?php echo number_format($day['daily_earnings'], 2); ?></strong>
                                                <?php if ($day['daily_pending_earnings'] > 0): ?>
                                                    <br><small class="text-warning">+RM <?php echo number_format($day['daily_pending_earnings'], 2); ?> pending</small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- All Bookings Details -->
                    <div class="row mt-4">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">
                                        <i class="bi bi-list-check"></i> All Trip Details
                                    </h5>
                                    <span class="badge bg-primary"><?php echo count($all_bookings); ?> trips</span>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($all_bookings)): ?>
                                        <div class="text-center py-4">
                                            <i class="bi bi-calendar-check text-muted" style="font-size: 3rem;"></i>
                                            <h6 class="text-muted mt-2">No trips in this period</h6>
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table">
                                                <thead>
                                                    <tr>
                                                        <th>Date & Time</th>
                                                        <th>Student</th>
                                                        <th>Route</th>
                                                        <th>Status</th>
                                                        <th>Payment</th>
                                                        <th>Amount</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($all_bookings as $booking): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?php echo date('d M Y', strtotime($booking['pickupdate'])); ?></strong><br>
                                                            <small class="text-muted"><?php echo date('h:i A', strtotime($booking['pickupdate'])); ?></small>
                                                        </td>
                                                        <td>
                                                            <strong><?php echo htmlspecialchars($booking['student_full_name'] ?? $booking['student_name']); ?></strong><br>
                                                            <small class="text-muted"><?php echo htmlspecialchars($booking['student_number']); ?></small>
                                                        </td>
                                                        <td>
                                                            <small class="text-muted">
                                                                <i class="bi bi-geo-alt"></i>
                                                                <?php echo htmlspecialchars($booking['pickuplocation']); ?><br>
                                                                <i class="bi bi-arrow-down"></i><br>
                                                                <?php echo htmlspecialchars($booking['dropofflocation']); ?>
                                                            </small>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-<?php 
                                                                echo $booking['bookingstatus'] == 'completed' ? 'success' : 
                                                                    ($booking['bookingstatus'] == 'confirmed' ? 'primary' : 
                                                                    ($booking['bookingstatus'] == 'pending' ? 'warning' : 'danger')); 
                                                            ?>">
                                                                <?php echo ucfirst($booking['bookingstatus']); ?>
                                                            </span><br>
                                                            <small class="text-muted">
                                                                <i class="bi bi-people"></i> <?php echo $booking['pax']; ?> pax
                                                            </small>
                                                        </td>
                                                        <td>
                                                            <span class="badge payment-<?php echo strtolower(str_replace(' ', '', $booking['payment_status'])); ?>">
                                                                <?php echo $booking['payment_status']; ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <?php if ($booking['bookingstatus'] == 'cancelled'): ?>
                                                                <span class="text-muted">N/A</span>
                                                            <?php elseif ($booking['bookingstatus'] == 'pending'): ?>
                                                                <span class="text-muted">RM <?php echo number_format($booking['totalcost'], 2); ?></span><br>
                                                                <small class="text-muted">(If accepted)</small>
                                                            <?php else: ?>
                                                                <strong class="text-<?php echo $booking['bookingstatus'] == 'completed' ? 'success' : 'warning'; ?>">
                                                                    RM <?php echo number_format($booking['display_cost'], 2); ?>
                                                                </strong>
                                                            <?php endif; ?>
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
        // Monthly Earnings Chart
        const ctx = document.getElementById('earningsChart').getContext('2d');
        const monthlyData = <?php echo json_encode($monthly_earnings); ?>;
        
        // Prepare chart data
        const labels = monthlyData.map(item => {
            const date = new Date(item.month + '-01');
            return date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
        });
        
        const paidEarnings = monthlyData.map(item => parseFloat(item.monthly_earnings));
        const pendingEarnings = monthlyData.map(item => parseFloat(item.monthly_pending_earnings));
        const completedBookings = monthlyData.map(item => parseInt(item.completed_bookings));
        const confirmedBookings = monthlyData.map(item => parseInt(item.confirmed_bookings));
        
        const chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Paid Earnings (RM)',
                    data: paidEarnings,
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    yAxisID: 'y'
                }, {
                    label: 'Pending Earnings (RM)',
                    data: pendingEarnings,
                    borderColor: '#ffc107',
                    backgroundColor: 'rgba(255, 193, 7, 0.1)',
                    borderWidth: 2,
                    fill: false,
                    tension: 0.4,
                    yAxisID: 'y'
                }, {
                    label: 'Completed Trips',
                    data: completedBookings,
                    borderColor: '#007bff',
                    backgroundColor: 'rgba(0, 123, 255, 0.1)',
                    borderWidth: 2,
                    fill: false,
                    tension: 0.4,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                scales: {
                    x: {
                        display: true,
                        title: {
                            display: true,
                            text: 'Month'
                        }
                    },
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Earnings (RM)'
                        },
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Number of Trips'
                        },
                        grid: {
                            drawOnChartArea: false,
                        },
                    }
                },
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.parsed.y !== null) {
                                    if (label.includes('Earnings')) {
                                        label += 'RM ' + context.parsed.y.toFixed(2);
                                    } else {
                                        label += context.parsed.y + ' trips';
                                    }
                                }
                                return label;
                            }
                        }
                    }
                }
            }
        });

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

        // FIXED: Allow future dates in the date picker (removed the max date restriction)
        document.addEventListener('DOMContentLoaded', function() {
            // Set minimum date to 2 years ago for reasonable range
            const twoYearsAgo = new Date();
            twoYearsAgo.setFullYear(twoYearsAgo.getFullYear() - 2);
            const minDate = twoYearsAgo.toISOString().split('T')[0];
            
            document.getElementById('start_date').setAttribute('min', minDate);
            document.getElementById('end_date').setAttribute('min', minDate);
            
            // Note: No max date set, allowing future bookings to be selected
        });
    </script>
</body>
</html>