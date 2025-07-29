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

// Handle report generation with better default values
$report_type = isset($_GET['type']) ? $_GET['type'] : 'overview';
$date_from = isset($_GET['date_from']) && !empty($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) && !empty($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');

// Initialize report data array
$report_data = [];
$error_message = '';

// Generate report data based on type with better error handling
try {
    switch ($report_type) {
        case 'overview':
        case 'bookings':
            // Get daily booking data with proper date handling
            $query = "SELECT 
                        DATE(b.bookingdate) as booking_date,
                        COUNT(*) as total_bookings,
                        COUNT(CASE WHEN b.bookingstatus = 'completed' THEN 1 END) as completed_bookings,
                        COUNT(CASE WHEN b.bookingstatus = 'cancelled' THEN 1 END) as cancelled_bookings,
                        COUNT(CASE WHEN b.bookingstatus = 'pending' THEN 1 END) as pending_bookings,
                        COUNT(CASE WHEN b.bookingstatus = 'confirmed' THEN 1 END) as confirmed_bookings,
                        SUM(CASE WHEN b.bookingstatus = 'completed' THEN b.totalcost ELSE 0 END) as total_revenue
                      FROM booking b
                      WHERE DATE(b.bookingdate) BETWEEN ? AND ?
                      GROUP BY DATE(b.bookingdate)
                      ORDER BY booking_date DESC";
            
            $stmt = $db->prepare($query);
            $stmt->bindValue(1, $date_from, PDO::PARAM_STR);
            $stmt->bindValue(2, $date_to, PDO::PARAM_STR);
            $stmt->execute();
            $report_data['daily_bookings'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'revenue':
            // Revenue analysis with all bookings (not just completed)
            $query = "SELECT 
                        DATE(b.bookingdate) as date,
                        SUM(CASE WHEN b.bookingstatus = 'completed' THEN b.totalcost ELSE 0 END) as total_revenue,
                        COUNT(CASE WHEN b.bookingstatus = 'completed' THEN 1 END) as booking_count,
                        AVG(CASE WHEN b.bookingstatus = 'completed' THEN b.totalcost END) as avg_booking_value,
                        SUM(b.totalcost) as potential_revenue
                      FROM booking b
                      WHERE DATE(b.bookingdate) BETWEEN ? AND ?
                      GROUP BY DATE(b.bookingdate)
                      ORDER BY date DESC";
            
            $stmt = $db->prepare($query);
            $stmt->bindValue(1, $date_from, PDO::PARAM_STR);
            $stmt->bindValue(2, $date_to, PDO::PARAM_STR);
            $stmt->execute();
            $report_data['revenue_data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'drivers':
            // Driver performance with LEFT JOINs to include all drivers
            $query = "SELECT 
                        u.username,
                        d.carmodel,
                        d.plate,
                        d.driverid,
                        COUNT(b.bookingid) as total_bookings,
                        COUNT(CASE WHEN b.bookingstatus = 'completed' THEN 1 END) as completed_bookings,
                        COUNT(CASE WHEN b.bookingstatus = 'cancelled' THEN 1 END) as cancelled_bookings,
                        SUM(CASE WHEN b.bookingstatus = 'completed' THEN b.totalcost ELSE 0 END) as total_earnings,
                        AVG(r.rating) as avg_rating,
                        COUNT(r.ratingid) as total_ratings
                      FROM driver d
                      INNER JOIN user u ON d.userid = u.userid
                      LEFT JOIN booking b ON d.driverid = b.driverid 
                        AND DATE(b.bookingdate) BETWEEN ? AND ?
                      LEFT JOIN rating r ON b.bookingid = r.bookingid
                      GROUP BY d.driverid, u.username, d.carmodel, d.plate
                      ORDER BY total_bookings DESC, completed_bookings DESC";
            
            $stmt = $db->prepare($query);
            $stmt->bindValue(1, $date_from, PDO::PARAM_STR);
            $stmt->bindValue(2, $date_to, PDO::PARAM_STR);
            $stmt->execute();
            $report_data['driver_performance'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'students':
            // Booking History with proper JOINs
            $query = "SELECT 
                        u.username,
                        COALESCE(s.student_number, 'N/A') as student_number,
                        COALESCE(s.faculty, 'N/A') as faculty,
                        COUNT(b.bookingid) as total_bookings,
                        COUNT(CASE WHEN b.bookingstatus = 'completed' THEN 1 END) as completed_bookings,
                        SUM(CASE WHEN b.bookingstatus = 'completed' THEN b.totalcost ELSE 0 END) as total_spent,
                        MAX(b.bookingdate) as last_booking,
                        AVG(CASE WHEN b.bookingstatus = 'completed' THEN b.totalcost END) as avg_booking_value
                      FROM user u
                      LEFT JOIN student s ON u.userid = s.userid
                      LEFT JOIN booking b ON u.userid = b.userid 
                        AND DATE(b.bookingdate) BETWEEN ? AND ?
                      WHERE u.role = 'student'
                      GROUP BY u.userid, u.username, s.student_number, s.faculty
                      HAVING total_bookings > 0
                      ORDER BY total_bookings DESC, total_spent DESC";
            
            $stmt = $db->prepare($query);
            $stmt->bindValue(1, $date_from, PDO::PARAM_STR);
            $stmt->bindValue(2, $date_to, PDO::PARAM_STR);
            $stmt->execute();
            $report_data['student_activity'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
    }
} catch (PDOException $e) {
    $error_message = "Database error: " . $e->getMessage();
    error_log("Reports query error: " . $e->getMessage());
}

// Get summary statistics with better error handling
try {
    $query = "SELECT 
                COUNT(*) as total_bookings,
                COUNT(CASE WHEN bookingstatus = 'completed' THEN 1 END) as completed_bookings,
                COUNT(CASE WHEN bookingstatus = 'cancelled' THEN 1 END) as cancelled_bookings,
                COUNT(CASE WHEN bookingstatus = 'pending' THEN 1 END) as pending_bookings,
                COUNT(CASE WHEN bookingstatus = 'confirmed' THEN 1 END) as confirmed_bookings,
                SUM(CASE WHEN bookingstatus = 'completed' THEN totalcost ELSE 0 END) as total_revenue,
                AVG(CASE WHEN bookingstatus = 'completed' THEN totalcost ELSE NULL END) as avg_booking_value,
                COUNT(DISTINCT userid) as unique_students,
                COUNT(DISTINCT driverid) as unique_drivers
              FROM booking 
              WHERE DATE(bookingdate) BETWEEN ? AND ?";

    $stmt = $db->prepare($query);
    $stmt->bindValue(1, $date_from, PDO::PARAM_STR);
    $stmt->bindValue(2, $date_to, PDO::PARAM_STR);
    $stmt->execute();
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);

    // Ensure summary has default values
    if (!$summary) {
        $summary = [
            'total_bookings' => 0,
            'completed_bookings' => 0,
            'cancelled_bookings' => 0,
            'pending_bookings' => 0,
            'confirmed_bookings' => 0,
            'total_revenue' => 0,
            'avg_booking_value' => 0,
            'unique_students' => 0,
            'unique_drivers' => 0
        ];
    }
} catch (PDOException $e) {
    $error_message = "Summary query error: " . $e->getMessage();
    $summary = [
        'total_bookings' => 0,
        'completed_bookings' => 0,
        'cancelled_bookings' => 0,
        'pending_bookings' => 0,
        'confirmed_bookings' => 0,
        'total_revenue' => 0,
        'avg_booking_value' => 0,
        'unique_students' => 0,
        'unique_drivers' => 0
    ];
}


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - RenToGo Admin</title>
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

        /* Sidebar Styles */
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

        /* Chart containers */
        .chart-container {
            position: relative;
            height: 300px;
            margin: 1rem 0;
        }

        .chart-container canvas {
            max-height: 300px;
        }

        

        /* Print styles */
        @media print {
            .dashboard-sidebar,
            .btn,
            .dropdown {
                display: none !important;
            }
            
            .main-content {
                margin-left: 0 !important;
                width: 100% !important;
            }
            
            .dashboard-content {
                padding: 1rem !important;
            }
            
            .card {
                border: 1px solid #ddd !important;
                box-shadow: none !important;
                break-inside: avoid;
            }

            .chart-container {
                height: 250px;
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
            
            .main-content {
                margin-left: 0;
                width: 100%;
            }
            
            .dashboard-content {
                padding: 1rem;
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

        .col, .col-auto, .col-md-2, .col-md-3, .col-md-4 {
            padding-left: 0.5rem;
            padding-right: 0.5rem;
        }

        /* Report specific styles */
        .report-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 2rem;
        }

        .report-header {
            background: linear-gradient(135deg, #0d6efd 0%, #0056b3 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 15px 15px 0 0;
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

        /* Animations */
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
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
                        <a class="nav-link" href="drivers.php">
                            <i class="bi bi-car-front"></i> Manage Drivers
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="reports.php">
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
                            <h4 class="mb-0 fw-bold">Reports & Analytics</h4>
                            <small class="text-muted">Generate detailed reports and view system analytics</small>
                        </div>
                        <div class="col-auto">
                            <div class="dropdown">
                                <button class="btn btn-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                    <i class="bi bi-download"></i> Export Report
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="#" onclick="exportToPDF()">
                                        <i class="bi bi-file-pdf"></i> Export as PDF
                                    </a></li>
                                    <li><a class="dropdown-item" href="#" onclick="exportToCSV()">
                                        <i class="bi bi-file-csv"></i> Export as CSV
                                    </a></li>
                                    <li><a class="dropdown-item" href="#" onclick="window.print()">
                                        <i class="bi bi-printer"></i> Print Report
                                    </a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Dashboard Content -->
            <div class="dashboard-content">

                <!-- Error message display -->
                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($error_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                
                
                <!-- Report Controls -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" action="" class="row g-3" id="reportForm">
                            <div class="col-md-3">
                                <label for="type" class="form-label fw-semibold">Report Type</label>
                                <select class="form-select" id="type" name="type">
                                    <option value="overview" <?php echo $report_type == 'overview' ? 'selected' : ''; ?>>System Overview</option>
                                    <option value="bookings" <?php echo $report_type == 'bookings' ? 'selected' : ''; ?>>Booking Analytics</option>
                                    <option value="revenue" <?php echo $report_type == 'revenue' ? 'selected' : ''; ?>>Revenue Report</option>
                                    <option value="drivers" <?php echo $report_type == 'drivers' ? 'selected' : ''; ?>>Driver Performance</option>
                                    <option value="students" <?php echo $report_type == 'students' ? 'selected' : ''; ?>>Booking History</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="date_from" class="form-label fw-semibold">From Date</label>
                                <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                            </div>
                            <div class="col-md-3">
                                <label for="date_to" class="form-label fw-semibold">To Date</label>
                                <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">&nbsp;</label>
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-bar-chart"></i> Generate Report
                                </button>
                            </div>
                        </form>
                        <div class="mt-2">
                            <small class="text-muted">
                                <i class="bi bi-info-circle"></i> 
                                Showing data from <?php echo date('d M Y', strtotime($date_from)); ?> to <?php echo date('d M Y', strtotime($date_to)); ?>
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Summary Statistics -->
                <div class="row g-3 mb-4">
                    <div class="col-md-2">
                        <div class="card stat-card bg-primary text-white">
                            <div class="card-body text-center">
                                <h2 class="mb-1 fw-bold"><?php echo $summary['total_bookings']; ?></h2>
                                <small class="opacity-75">Total Bookings</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card stat-card bg-success text-white">
                            <div class="card-body text-center">
                                <h2 class="mb-1 fw-bold"><?php echo $summary['completed_bookings']; ?></h2>
                                <small class="opacity-75">Completed</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card stat-card bg-danger text-white">
                            <div class="card-body text-center">
                                <h2 class="mb-1 fw-bold"><?php echo $summary['cancelled_bookings']; ?></h2>
                                <small class="opacity-75">Cancelled</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card stat-card bg-info text-white">
                            <div class="card-body text-center">
                                <h2 class="mb-1 fw-bold">RM<?php echo number_format($summary['total_revenue'], 0); ?></h2>
                                <small class="opacity-75">Total Revenue</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card stat-card bg-warning text-white">
                            <div class="card-body text-center">
                                <h2 class="mb-1 fw-bold"><?php echo $summary['unique_students']; ?></h2>
                                <small class="opacity-75">Active Students</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card stat-card bg-secondary text-white">
                            <div class="card-body text-center">
                                <h2 class="mb-1 fw-bold"><?php echo $summary['unique_drivers']; ?></h2>
                                <small class="opacity-75">Active Drivers</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Report Content -->
                <?php if ($report_type == 'overview' || $report_type == 'bookings'): ?>
                <div class="row g-4 mb-4">
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0 fw-bold">
                                    <i class="bi bi-bar-chart"></i> Booking Trends
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="bookingChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0 fw-bold">
                                    <i class="bi bi-pie-chart"></i> Booking Status
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="statusChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($report_type == 'revenue'): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0 fw-bold">
                            <i class="bi bi-currency-dollar"></i> Revenue Analysis
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="revenueChart"></canvas>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Data Tables -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0 fw-bold">
                            <i class="bi bi-table"></i> Detailed Data
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($report_data) || (isset($report_data['daily_bookings']) && empty($report_data['daily_bookings'])) && 
                                   (isset($report_data['driver_performance']) && empty($report_data['driver_performance'])) && 
                                   (isset($report_data['student_activity']) && empty($report_data['student_activity'])) && 
                                   (isset($report_data['revenue_data']) && empty($report_data['revenue_data']))): ?>
                            <div class="text-center py-5">
                                <i class="bi bi-bar-chart text-muted" style="font-size: 4rem; opacity: 0.5;"></i>
                                <h5 class="mt-3">No Data Available</h5>
                                <p class="text-muted">No data found for the selected date range and report type.</p>
                                <div class="mt-3">
                                    <button type="button" class="btn btn-primary" onclick="resetFilters()">
                                        <i class="bi bi-arrow-clockwise"></i> Reset Filters
                                    </button>
                                    <a href="?debug=1&type=<?php echo $report_type; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" class="btn btn-outline-secondary ms-2">
                                        <i class="bi bi-bug"></i> Debug Info
                                    </a>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php if (isset($report_data['daily_bookings']) && !empty($report_data['daily_bookings'])): ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Date</th>
                                            <th>Total Bookings</th>
                                            <th>Completed</th>
                                            <th>Cancelled</th>
                                            <th>Pending</th>
                                            <th>Revenue</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($report_data['daily_bookings'] as $row): ?>
                                        <tr>
                                            <td class="fw-semibold"><?php echo date('d M Y', strtotime($row['booking_date'])); ?></td>
                                            <td><span class="badge bg-primary px-2 py-1"><?php echo $row['total_bookings']; ?></span></td>
                                            <td><span class="badge bg-success px-2 py-1"><?php echo $row['completed_bookings']; ?></span></td>
                                            <td><span class="badge bg-danger px-2 py-1"><?php echo $row['cancelled_bookings']; ?></span></td>
                                            <td><span class="badge bg-warning px-2 py-1"><?php echo ($row['pending_bookings'] + $row['confirmed_bookings']); ?></span></td>
                                            <td><strong class="text-success">RM <?php echo number_format($row['total_revenue'], 2); ?></strong></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>

                            <?php if (isset($report_data['driver_performance']) && !empty($report_data['driver_performance'])): ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Driver</th>
                                            <th>Vehicle</th>
                                            <th>Total Bookings</th>
                                            <th>Completed</th>
                                            <th>Cancelled</th>
                                            <th>Earnings</th>
                                            <th>Avg Rating</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($report_data['driver_performance'] as $index => $row): ?>
                                        <tr>
                                            <td>
                                                <?php if ($index < 3 && $row['total_bookings'] > 0): ?>
                                                    <i class="bi bi-trophy-fill text-warning me-2"></i>
                                                <?php endif; ?>
                                                <strong><?php echo htmlspecialchars($row['username']); ?></strong>
                                            </td>
                                            <td>
                                                <div>
                                                    <?php echo htmlspecialchars($row['carmodel']); ?>
                                                    <br>
                                                    <small class="text-muted">
                                                        <span class="badge bg-dark"><?php echo htmlspecialchars($row['plate']); ?></span>
                                                    </small>
                                                </div>
                                            </td>
                                            <td><span class="badge bg-primary px-2 py-1"><?php echo $row['total_bookings']; ?></span></td>
                                            <td><span class="badge bg-success px-2 py-1"><?php echo $row['completed_bookings']; ?></span></td>
                                            <td><span class="badge bg-danger px-2 py-1"><?php echo $row['cancelled_bookings']; ?></span></td>
                                            <td><strong class="text-success">RM <?php echo number_format($row['total_earnings'], 2); ?></strong></td>
                                            <td>
                                                <?php if ($row['avg_rating'] && $row['total_ratings'] > 0): ?>
                                                    <span class="text-warning fw-semibold">
                                                        <?php echo number_format($row['avg_rating'], 1); ?> 
                                                        <i class="bi bi-star-fill"></i>
                                                        <small class="text-muted">(<?php echo $row['total_ratings']; ?>)</small>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">No ratings</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>

                            <?php if (isset($report_data['student_activity']) && !empty($report_data['student_activity'])): ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Student</th>
                                            <th>Student Number</th>
                                            <th>Faculty</th>
                                            <th>Total Bookings</th>
                                            <th>Completed</th>
                                            <th>Total Spent</th>
                                            <th>Avg Value</th>
                                            <th>Last Booking</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($report_data['student_activity'] as $row): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($row['username']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($row['student_number']); ?></td>
                                            <td><small><?php echo htmlspecialchars($row['faculty']); ?></small></td>
                                            <td><span class="badge bg-primary px-2 py-1"><?php echo $row['total_bookings']; ?></span></td>
                                            <td><span class="badge bg-success px-2 py-1"><?php echo $row['completed_bookings']; ?></span></td>
                                            <td><strong class="text-success">RM <?php echo number_format($row['total_spent'], 2); ?></strong></td>
                                            <td>RM <?php echo number_format($row['avg_booking_value'] ?? 0, 2); ?></td>
                                            <td>
                                                <?php if ($row['last_booking']): ?>
                                                    <small><?php echo date('d M Y', strtotime($row['last_booking'])); ?></small>
                                                <?php else: ?>
                                                    <span class="text-muted">Never</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>

                            <?php if (isset($report_data['revenue_data']) && !empty($report_data['revenue_data'])): ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Date</th>
                                            <th>Revenue</th>
                                            <th>Completed Bookings</th>
                                            <th>Avg Value</th>
                                            <th>Potential Revenue</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($report_data['revenue_data'] as $row): ?>
                                        <tr>
                                            <td class="fw-semibold"><?php echo date('d M Y', strtotime($row['date'])); ?></td>
                                            <td><strong class="text-success">RM <?php echo number_format($row['total_revenue'], 2); ?></strong></td>
                                            <td><span class="badge bg-primary px-2 py-1"><?php echo $row['booking_count']; ?></span></td>
                                            <td>RM <?php echo number_format($row['avg_booking_value'] ?? 0, 2); ?></td>
                                            <td class="text-info">RM <?php echo number_format($row['potential_revenue'], 2); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

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
        // Chart.js configurations with better data handling
        document.addEventListener('DOMContentLoaded', function() {
            // Add animations
            const cards = document.querySelectorAll('.card, .stat-card');
            cards.forEach((card, index) => {
                card.style.animationDelay = (index * 0.1) + 's';
                card.classList.add('fade-in');
            });

            // Booking trends chart with better data checking
            const bookingCtx = document.getElementById('bookingChart');
            if (bookingCtx) {
                const hasData = <?php echo json_encode(!empty($report_data['daily_bookings'])); ?>;
                
                if (hasData) {
                    const chartData = <?php echo json_encode($report_data['daily_bookings'] ?? []); ?>;
                    
                    new Chart(bookingCtx, {
                        type: 'line',
                        data: {
                            labels: chartData.map(item => {
                                const date = new Date(item.booking_date);
                                return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                            }),
                            datasets: [{
                                label: 'Total Bookings',
                                data: chartData.map(item => parseInt(item.total_bookings)),
                                borderColor: '#0d6efd',
                                backgroundColor: 'rgba(13, 110, 253, 0.1)',
                                tension: 0.4,
                                fill: true
                            }, {
                                label: 'Completed Bookings',
                                data: chartData.map(item => parseInt(item.completed_bookings)),
                                borderColor: '#28a745',
                                backgroundColor: 'rgba(40, 167, 69, 0.1)',
                                tension: 0.4,
                                fill: true
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'top',
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        stepSize: 1
                                    }
                                }
                            }
                        }
                    });
                } else {
                    // Show "No data" message in chart
                    bookingCtx.getContext('2d').font = '16px Arial';
                    bookingCtx.getContext('2d').textAlign = 'center';
                    bookingCtx.getContext('2d').fillText('No booking data available', bookingCtx.width/2, bookingCtx.height/2);
                }
            }

            // Status pie chart with actual data
            const statusCtx = document.getElementById('statusChart');
            if (statusCtx) {
                const completedBookings = <?php echo $summary['completed_bookings']; ?>;
                const cancelledBookings = <?php echo $summary['cancelled_bookings']; ?>;
                const pendingBookings = <?php echo $summary['pending_bookings']; ?>;
                const confirmedBookings = <?php echo $summary['confirmed_bookings']; ?>;

                if (completedBookings > 0 || cancelledBookings > 0 || pendingBookings > 0 || confirmedBookings > 0) {
                    new Chart(statusCtx, {
                        type: 'doughnut',
                        data: {
                            labels: ['Completed', 'Cancelled', 'Pending', 'Confirmed'],
                            datasets: [{
                                data: [completedBookings, cancelledBookings, pendingBookings, confirmedBookings],
                                backgroundColor: ['#28a745', '#dc3545', '#ffc107', '#17a2b8'],
                                borderWidth: 3,
                                borderColor: '#fff'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                }
                            }
                        }
                    });
                } else {
                    statusCtx.getContext('2d').font = '16px Arial';
                    statusCtx.getContext('2d').textAlign = 'center';
                    statusCtx.getContext('2d').fillText('No status data available', statusCtx.width/2, statusCtx.height/2);
                }
            }

            // Revenue chart with better data handling
            const revenueCtx = document.getElementById('revenueChart');
            if (revenueCtx) {
                const hasRevenueData = <?php echo json_encode(!empty($report_data['revenue_data'])); ?>;
                
                if (hasRevenueData) {
                    const revenueData = <?php echo json_encode($report_data['revenue_data'] ?? []); ?>;
                    
                    new Chart(revenueCtx, {
                        type: 'bar',
                        data: {
                            labels: revenueData.map(item => {
                                const date = new Date(item.date);
                                return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                            }),
                            datasets: [{
                                label: 'Daily Revenue (RM)',
                                data: revenueData.map(item => parseFloat(item.total_revenue)),
                                backgroundColor: 'rgba(13, 110, 253, 0.8)',
                                borderColor: '#0d6efd',
                                borderWidth: 2,
                                borderRadius: 8
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        callback: function(value) {
                                            return 'RM ' + value.toLocaleString();
                                        }
                                    }
                                }
                            }
                        }
                    });
                } else {
                    revenueCtx.getContext('2d').font = '16px Arial';
                    revenueCtx.getContext('2d').textAlign = 'center';
                    revenueCtx.getContext('2d').fillText('No revenue data available', revenueCtx.width/2, revenueCtx.height/2);
                }
            }
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

        // Export functions
        function exportToPDF() {
            window.print();
        }

        function exportToCSV() {
            const tables = document.querySelectorAll('table');
            if (tables.length > 0) {
                let csv = '';
                const table = tables[0];
                const rows = table.querySelectorAll('tr');
                
                rows.forEach(row => {
                    const cols = row.querySelectorAll('th, td');
                    const csvRow = Array.from(cols).map(col => 
                        '"' + col.textContent.replace(/"/g, '""').trim() + '"'
                    ).join(',');
                    csv += csvRow + '\n';
                });
                
                const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `rentogo-report-${new Date().toISOString().split('T')[0]}.csv`;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                window.URL.revokeObjectURL(url);
            } else {
                alert('No data available to export');
            }
        }

        function resetFilters() {
            window.location.href = 'reports.php';
        }

        // Auto-submit form on type change with loading state
        document.getElementById('type').addEventListener('change', function() {
            const form = document.getElementById('reportForm');
            const submitBtn = form.querySelector('button[type="submit"]');
            
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Loading...';
            submitBtn.disabled = true;
            
            form.submit();
        });

        // Form validation
        document.getElementById('reportForm').addEventListener('submit', function(e) {
            const dateFrom = document.getElementById('date_from').value;
            const dateTo = document.getElementById('date_to').value;
            
            if (dateFrom && dateTo && new Date(dateFrom) > new Date(dateTo)) {
                e.preventDefault();
                alert('Start date cannot be later than end date');
                return false;
            }
            
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Generating...';
            submitBtn.disabled = true;
        });
    </script>
</body>
</html>