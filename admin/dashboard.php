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

// Get admin info
$query = "SELECT u.*, a.* FROM user u 
          JOIN admin a ON u.userid = a.userid 
          WHERE u.userid = ?";
$stmt = $db->prepare($query);
$stmt->bindParam(1, $_SESSION['userid']);
$stmt->execute();
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

// Get system statistics
$stats = [];

// Total users by role
$query = "SELECT role, COUNT(*) as count FROM user WHERE status = 'active' GROUP BY role";
$stmt = $db->prepare($query);
$stmt->execute();
$user_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($user_stats as $stat) {
    $stats[$stat['role'] . '_count'] = $stat['count'];
}

// Total bookings statistics - Only count revenue from completed bookings
$query = "SELECT 
            COUNT(*) as total_bookings,
            COUNT(CASE WHEN bookingstatus = 'pending' THEN 1 END) as pending_bookings,
            COUNT(CASE WHEN bookingstatus = 'confirmed' THEN 1 END) as confirmed_bookings,
            COUNT(CASE WHEN bookingstatus = 'completed' THEN 1 END) as completed_bookings,
            COUNT(CASE WHEN bookingstatus = 'cancelled' THEN 1 END) as cancelled_bookings,
            COALESCE(SUM(CASE WHEN bookingstatus = 'completed' THEN totalcost ELSE 0 END), 0) as total_revenue
          FROM booking";
$stmt = $db->prepare($query);
$stmt->execute();
$booking_stats = $stmt->fetch(PDO::FETCH_ASSOC);
$stats = array_merge($stats, $booking_stats);

// Active drivers
$query = "SELECT COUNT(*) as active_drivers FROM driver WHERE status = 'available'";
$stmt = $db->prepare($query);
$stmt->execute();
$driver_count = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['active_drivers'] = $driver_count['active_drivers'];

// Recent activities
$query = "SELECT 
            'booking' as type,
            b.bookingid as id,
            CONCAT('New booking by ', u.username) as description,
            b.bookingdate as created_at,
            b.bookingstatus as status
          FROM booking b
          JOIN user u ON b.userid = u.userid
          ORDER BY b.bookingdate DESC
          LIMIT 10";
$stmt = $db->prepare($query);
$stmt->execute();
$recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Top drivers by bookings - Only count earnings from completed bookings
$query = "SELECT 
            u.username,
            d.carmodel,
            d.plate,
            COUNT(b.bookingid) as total_bookings,
            COALESCE(AVG(r.rating), 0) as avg_rating,
            SUM(CASE WHEN b.bookingstatus = 'completed' THEN b.totalcost ELSE 0 END) as total_earnings
          FROM driver d
          JOIN user u ON d.userid = u.userid
          LEFT JOIN booking b ON d.driverid = b.driverid
          LEFT JOIN rating r ON d.driverid = r.driverid
          GROUP BY d.driverid
          ORDER BY total_bookings DESC
          LIMIT 5";
$stmt = $db->prepare($query);
$stmt->execute();
$top_drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent registrations
$query = "SELECT username, role, created FROM user WHERE status = 'active' ORDER BY created DESC LIMIT 5";
$stmt = $db->prepare($query);
$stmt->execute();
$recent_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - RenToGo</title>
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

        .stats-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            transition: transform 0.3s ease;
            border: none;
        }

        .stats-card:hover {
            transform: translateY(-5px);
        }

        .stats-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
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
            padding: 1.5rem;
        }

        .card-body {
            padding: 1.5rem;
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

        .timeline-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
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

        .table th {
            border-top: none;
            font-weight: 600;
            color: #495057;
            background-color: #f8f9fa;
        }

        .table td {
            vertical-align: middle;
            border-color: #f0f0f0;
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
            
            .main-content {
                margin-left: 0;
                width: 100%;
            }
            
            .dashboard-content {
                padding: 1rem;
            }
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

        .container-fluid {
            padding-left: 1rem;
            padding-right: 1rem;
            max-width: 100%;
        }

        .row {
            margin-left: 0;
            margin-right: 0;
        }

        .overview-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            text-center;
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
        }

        .overview-card:hover {
            border-color: #0d6efd;
            transform: translateY(-2px);
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
                        <a class="nav-link active" href="dashboard.php">
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
                            <h4 class="mb-0 fw-bold">System Overview</h4>
                            <small class="text-muted">Welcome back, <?php echo htmlspecialchars($admin['username']); ?>!</small>
                        </div>
                        <div class="col-auto">
                            <div class="dropdown">
                                <button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                    <i class="bi bi-download"></i> Export Reports
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="reports.php?export=users"><i class="bi bi-people"></i> Users Report</a></li>
                                    <li><a class="dropdown-item" href="reports.php?export=bookings"><i class="bi bi-calendar"></i> Bookings Report</a></li>
                                    <li><a class="dropdown-item" href="reports.php?export=revenue"><i class="bi bi-currency-dollar"></i> Revenue Report</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <!-- Statistics Cards -->
                <div class="row g-4 mb-4">
                    <div class="col-lg-3 col-md-6">
                        <div class="stats-card">
                            <div class="d-flex align-items-center">
                                <div class="stats-icon bg-primary me-3">
                                    <i class="bi bi-people"></i>
                                </div>
                                <div>
                                    <h4 class="mb-0 fw-bold"><?php echo ($stats['student_count'] ?? 0) + ($stats['driver_count'] ?? 0); ?></h4>
                                    <small class="text-muted">Total Users</small>
                                    <div class="small text-muted">
                                        <?php echo $stats['student_count'] ?? 0; ?> Students, <?php echo $stats['driver_count'] ?? 0; ?> Drivers
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="stats-card">
                            <div class="d-flex align-items-center">
                                <div class="stats-icon bg-success me-3">
                                    <i class="bi bi-calendar-check"></i>
                                </div>
                                <div>
                                    <h4 class="mb-0 fw-bold"><?php echo $stats['total_bookings']; ?></h4>
                                    <small class="text-muted">Total Bookings</small>
                                    <div class="small text-muted">
                                        <?php echo $stats['completed_bookings']; ?> Completed
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="stats-card">
                            <div class="d-flex align-items-center">
                                <div class="stats-icon bg-info me-3">
                                    <i class="bi bi-car-front"></i>
                                </div>
                                <div>
                                    <h4 class="mb-0 fw-bold"><?php echo $stats['active_drivers']; ?></h4>
                                    <small class="text-muted">Active Drivers</small>
                                    <div class="small text-muted">
                                        Available Now
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="stats-card">
                            <div class="d-flex align-items-center">
                                <div class="stats-icon bg-warning me-3">
                                    <i class="bi bi-currency-dollar"></i>
                                </div>
                                <div>
                                    <h4 class="mb-0 fw-bold">RM <?php echo number_format($stats['total_revenue'], 2); ?></h4>
                                    <small class="text-muted">Total Revenue</small>
                                    <div class="small text-muted">
                                        <!-- Now shows only from completed bookings -->
                                        From Completed Bookings
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Booking Status Overview -->
                <div class="row g-4 mb-4">
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0 fw-bold">
                                    <i class="bi bi-bar-chart"></i> Booking Status Overview
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <div class="overview-card">
                                            <h3 class="text-warning mb-1 fw-bold"><?php echo $stats['pending_bookings']; ?></h3>
                                            <small class="text-muted fw-semibold">Pending</small>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="overview-card">
                                            <h3 class="text-info mb-1 fw-bold"><?php echo $stats['confirmed_bookings']; ?></h3>
                                            <small class="text-muted fw-semibold">Confirmed</small>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="overview-card">
                                            <h3 class="text-success mb-1 fw-bold"><?php echo $stats['completed_bookings']; ?></h3>
                                            <small class="text-muted fw-semibold">Completed</small>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="overview-card">
                                            <h3 class="text-danger mb-1 fw-bold"><?php echo $stats['cancelled_bookings']; ?></h3>
                                            <small class="text-muted fw-semibold">Cancelled</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0 fw-bold">
                                    <i class="bi bi-lightning-charge"></i> Quick Actions
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="d-grid gap-3">
                                    <a href="users.php" class="btn btn-primary">
                                        <i class="bi bi-people me-2"></i> Manage Users
                                    </a>
                                    <a href="bookings.php" class="btn btn-outline-primary">
                                        <i class="bi bi-calendar-check me-2"></i> View All Bookings
                                    </a>
                                    <a href="reports.php" class="btn btn-outline-success">
                                        <i class="bi bi-file-earmark-text me-2"></i> Generate Reports
                                    </a>
                                    <a href="drivers.php" class="btn btn-outline-info">
                                        <i class="bi bi-car-front me-2"></i> Manage Drivers
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-4">
                    <!-- Recent Activities -->
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0 fw-bold">
                                    <i class="bi bi-clock-history"></i> Recent Activities
                                </h5>
                                <a href="bookings.php" class="btn btn-sm btn-outline-primary">View All</a>
                            </div>
                            <div class="card-body">
                                <?php if (empty($recent_activities)): ?>
                                    <div class="text-center py-5">
                                        <i class="bi bi-clock-history text-muted" style="font-size: 4rem; opacity: 0.5;"></i>
                                        <h6 class="text-muted mt-3">No recent activities</h6>
                                        <p class="text-muted small">Activities will appear here when users make bookings</p>
                                    </div>
                                <?php else: ?>
                                    <div class="timeline">
                                        <?php foreach ($recent_activities as $activity): ?>
                                        <div class="d-flex mb-3 pb-3 border-bottom">
                                            <div class="timeline-icon bg-primary me-3">
                                                <i class="bi bi-calendar-plus text-white"></i>
                                            </div>
                                            <div class="flex-grow-1">
                                                <h6 class="mb-1 fw-semibold"><?php echo htmlspecialchars($activity['description']); ?></h6>
                                                <small class="text-muted">
                                                    <?php echo date('d M Y, h:i A', strtotime($activity['created_at'])); ?>
                                                </small>
                                                <span class="badge status-<?php echo $activity['status']; ?> ms-2">
                                                    <?php echo ucfirst($activity['status']); ?>
                                                </span>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Top Drivers -->
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0 fw-bold">
                                    <i class="bi bi-trophy"></i> Top Drivers
                                </h5>
                                <a href="drivers.php" class="btn btn-sm btn-outline-primary">View All</a>
                            </div>
                            <div class="card-body">
                                <?php if (empty($top_drivers)): ?>
                                    <div class="text-center py-5">
                                        <i class="bi bi-car-front text-muted" style="font-size: 4rem; opacity: 0.5;"></i>
                                        <h6 class="text-muted mt-3">No drivers yet</h6>
                                        <p class="text-muted small">Driver performance will appear here</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Driver</th>
                                                    <th>Bookings</th>
                                                    <th>Rating</th>
                                                    <th>Earnings</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($top_drivers as $index => $driver): ?>
                                                <tr>
                                                    <td>
                                                        <?php if ($index < 3): ?>
                                                            <i class="bi bi-trophy-fill text-warning me-2"></i>
                                                        <?php endif; ?>
                                                        <strong><?php echo htmlspecialchars($driver['username']); ?></strong>
                                                        <br>
                                                        <small class="text-muted">
                                                            <?php echo htmlspecialchars($driver['carmodel']); ?>
                                                        </small>
                                                    </td>
                                                    <td class="fw-semibold"><?php echo $driver['total_bookings'] ?? 0; ?></td>
                                                    <td>
                                                        <?php if ($driver['avg_rating'] > 0): ?>
                                                            <span class="text-warning fw-semibold">
                                                                <?php echo number_format($driver['avg_rating'], 1); ?> 
                                                                <i class="bi bi-star-fill"></i>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <strong class="text-success">RM <?php echo number_format($driver['total_earnings'] ?? 0, 2); ?></strong>
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

                <!-- Recent Registrations -->
                <div class="row g-4 mt-2">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0 fw-bold">
                                    <i class="bi bi-person-plus"></i> Recent Registrations
                                </h5>
                                <a href="users.php" class="btn btn-sm btn-outline-primary">View All Users</a>
                            </div>
                            <div class="card-body">
                                <?php if (empty($recent_users)): ?>
                                    <div class="text-center py-5">
                                        <i class="bi bi-person-plus text-muted" style="font-size: 4rem; opacity: 0.5;"></i>
                                        <h6 class="text-muted mt-3">No recent registrations</h6>
                                        <p class="text-muted small">New user registrations will appear here</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Username</th>
                                                    <th>Role</th>
                                                    <th>Registration Date</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($recent_users as $user): ?>
                                                <tr>
                                                    <td><strong><?php echo htmlspecialchars($user['username']); ?></strong></td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $user['role'] == 'student' ? 'primary' : ($user['role'] == 'driver' ? 'success' : 'warning'); ?> px-2 py-1">
                                                            <i class="bi bi-<?php echo $user['role'] == 'student' ? 'mortarboard' : ($user['role'] == 'driver' ? 'car-front' : 'shield'); ?> me-1"></i>
                                                            <?php echo ucfirst($user['role']); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo date('d M Y, h:i A', strtotime($user['created'])); ?></td>
                                                    <td>
                                                        <span class="badge bg-success px-2 py-1">
                                                            <i class="bi bi-check-circle me-1"></i>
                                                            Active
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <a href="users.php?view=<?php echo $user['username']; ?>" class="btn btn-sm btn-outline-primary">
                                                            <i class="bi bi-eye"></i> View
                                                        </a>
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

    // Confirm dangerous actions
    document.querySelector('button[name="backup_database"]')?.addEventListener('click', function(e) {
        if (!confirm('Are you sure you want to backup the database? This may take a few minutes.')) {
            e.preventDefault();
        }
    });

    document.querySelector('button[name="clear_logs"]')?.addEventListener('click', function(e) {
        if (!confirm('Are you sure you want to clear all application logs? This action cannot be undone.')) {
            e.preventDefault();
        }
    });
        // Auto-refresh dashboard every 5 minutes
        setInterval(function() {
            if (document.hasFocus()) {
                location.reload();
            }
        }, 300000);

        // Add smooth animations
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.stats-card, .card');
            cards.forEach((card, index) => {
                card.style.animationDelay = (index * 0.1) + 's';
                card.classList.add('fade-in');
            });
        });
    </script>
</body>
</html>