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

// Get student info with full name
$query = "SELECT u.*, s.* FROM user u 
          JOIN student s ON u.userid = s.userid 
          WHERE u.userid = ?";
$stmt = $db->prepare($query);
$stmt->bindParam(1, $_SESSION['userid']);
$stmt->execute();
$student = $stmt->fetch(PDO::FETCH_ASSOC);

// Get student's booking statistics 
$query = "SELECT 
            COUNT(*) as total_bookings,
            COUNT(CASE WHEN bookingstatus = 'pending' THEN 1 END) as pending_bookings,
            COUNT(CASE WHEN bookingstatus = 'confirmed' THEN 1 END) as confirmed_bookings,
            COUNT(CASE WHEN bookingstatus = 'completed' THEN 1 END) as completed_bookings,
            COALESCE(SUM(CASE WHEN bookingstatus = 'completed' THEN totalcost END), 0) as total_spent
          FROM booking WHERE userid = ?";
$stmt = $db->prepare($query);
$stmt->bindParam(1, $_SESSION['userid']);
$stmt->execute();
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get recent bookings with driver full name
$query = "SELECT b.*, d.carmodel, d.plate, d.car_type, u.full_name as driver_name, u.username as driver_username
          FROM booking b
          JOIN driver d ON b.driverid = d.driverid
          JOIN user u ON d.userid = u.userid
          WHERE b.userid = ?
          ORDER BY b.bookingdate DESC
          LIMIT 5";
$stmt = $db->prepare($query);
$stmt->bindParam(1, $_SESSION['userid']);
$stmt->execute();
$recent_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - RenToGo</title>
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

        /* Stats Cards */
        .stats-card {
            background: white;
            border-radius: 15px;
            padding: 1.5rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border: 1px solid rgba(111, 66, 193, 0.1);
        }

        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }

        .stats-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }

        .stats-icon.bg-primary {
            background: linear-gradient(135deg, #6f42c1 0%, #8b5cf6 100%);
        }

        .stats-icon.bg-warning {
            background: linear-gradient(135deg, #f59e0b 0%, #fbbf24 100%);
        }

        .stats-icon.bg-success {
            background: linear-gradient(135deg, #10b981 0%, #34d399 100%);
        }

        .stats-icon.bg-info {
            background: linear-gradient(135deg, #3b82f6 0%, #60a5fa 100%);
        }

        /* Cards */
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border: 1px solid rgba(111, 66, 193, 0.1);
        }

        .card-header {
            background: white;
            border-bottom: 1px solid #eee;
            border-radius: 15px 15px 0 0 !important;
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

        /* Table styling */
        .table-hover tbody tr:hover {
            background-color: rgba(111, 66, 193, 0.05);
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

            .stats-card {
                margin-bottom: 1rem;
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
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #6f42c1 0%, #8b5cf6 100%);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #5a2d9a 0%, #7c3aed 100%);
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
                        <a class="nav-link active" href="dashboard.php">
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
                            <!-- Display full name -->
                            <h4 class="mb-0">Welcome back, <?php echo htmlspecialchars($student['full_name']); ?>!</h4>
                            <small class="text-muted"><?php echo htmlspecialchars($student['faculty']); ?> - Year <?php echo $student['year_of_study']; ?></small>
                        </div>
                        <div class="col-auto">
                            <button class="btn btn-primary" onclick="window.location.href='book-ride.php'">
                                <i class="bi bi-plus-circle"></i> Book a Ride
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <div class="container-fluid">
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
                                        <!-- shows total spent from completed bookings -->
                                        <h4 class="mb-0">RM <?php echo number_format($stats['total_spent'], 2); ?></h4>
                                        <small class="text-muted">Total Spent</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-4">
                        <!-- Recent Bookings -->
                        <div class="col-lg-8">
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">
                                        <i class="bi bi-clock-history"></i> Recent Bookings
                                    </h5>
                                    <a href="my-bookings.php" class="btn btn-sm btn-outline-primary">View All</a>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($recent_bookings)): ?>
                                        <div class="text-center py-4">
                                            <i class="bi bi-calendar-x text-muted" style="font-size: 3rem;"></i>
                                            <h6 class="text-muted mt-2">No bookings yet</h6>
                                            <p class="text-muted">Start by booking your first ride!</p>
                                            <a href="book-ride.php" class="btn btn-primary">Book Now</a>
                                        </div>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-hover">
                                                <thead>
                                                    <tr>
                                                        <th>Driver & Car</th>
                                                        <th>Date & Time</th>
                                                        <th>Location</th>
                                                        <th>Status</th>
                                                        <th>Cost</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($recent_bookings as $booking): ?>
                                                    <tr>
                                                        <td>
                                                            <div>
                                                                <!-- Display driver's full name -->
                                                                <strong><?php echo htmlspecialchars($booking['driver_name']); ?></strong>
                                                                <br>
                                                                <small class="text-muted">
                                                                    <?php echo htmlspecialchars($booking['carmodel']); ?> 
                                                                    (<?php echo htmlspecialchars($booking['plate']); ?>)
                                                                </small>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <div>
                                                                <?php echo date('d M Y', strtotime($booking['pickupdate'])); ?>
                                                                <br>
                                                                <small class="text-muted">
                                                                    <?php echo date('h:i A', strtotime($booking['pickupdate'])); ?>
                                                                </small>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <small>
                                                                From: <?php echo htmlspecialchars($booking['pickuplocation']); ?>
                                                                <br>
                                                                To: <?php echo htmlspecialchars($booking['dropofflocation']); ?>
                                                            </small>
                                                        </td>
                                                        <td>
                                                            <span class="badge status-<?php echo $booking['bookingstatus']; ?>">
                                                                <?php echo ucfirst($booking['bookingstatus']); ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <strong>RM <?php echo number_format($booking['totalcost'], 2); ?></strong>
                                                            <?php if ($booking['bookingstatus'] == 'cancelled'): ?>
                                                                <br><small class="text-muted">(Not charged)</small>
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
                                        <a href="book-ride.php" class="btn btn-primary">
                                            <i class="bi bi-plus-circle"></i> Book a New Ride
                                        </a>
                                        <a href="browse-drivers.php" class="btn btn-outline-primary">
                                            <i class="bi bi-search"></i> Browse Available Drivers
                                        </a>
                                        <a href="my-bookings.php" class="btn btn-outline-secondary">
                                            <i class="bi bi-list-ul"></i> View My Bookings
                                        </a>
                                        <a href="profile.php" class="btn btn-outline-info">
                                            <i class="bi bi-person"></i> Update Profile
                                        </a>
                                    </div>
                                </div>
                            </div>

                            <!-- Profile Summary -->
                            <div class="card mt-4">
                                <div class="card-header">
                                    <h5 class="mb-0">
                                        <i class="bi bi-person-circle"></i> Profile Summary
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="text-center mb-3">
                                        <i class="bi bi-person-circle text-primary" style="font-size: 4rem;"></i>
                                        <!-- UPDATED: Display full name instead of username -->
                                        <h6 class="mt-2"><?php echo htmlspecialchars($student['full_name']); ?></h6>
                                    </div>
                                    <ul class="list-unstyled">
                                        <li class="mb-2">
                                            <i class="bi bi-envelope text-muted me-2"></i>
                                            <small><?php echo htmlspecialchars($student['email']); ?></small>
                                        </li>
                                        <li class="mb-2">
                                            <i class="bi bi-phone text-muted me-2"></i>
                                            <small><?php echo htmlspecialchars($student['notel']); ?></small>
                                        </li>
                                        <li class="mb-2">
                                            <i class="bi bi-card-text text-muted me-2"></i>
                                            <small><?php echo htmlspecialchars($student['student_number']); ?></small>
                                        </li>
                                        <li class="mb-2">
                                            <i class="bi bi-building text-muted me-2"></i>
                                            <small><?php echo htmlspecialchars($student['faculty']); ?></small>
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
        // Auto-refresh page every 30 seconds for real-time updates
        setInterval(function() {
            // Only refresh if user is active (to avoid interrupting form filling)
            if (document.hasFocus()) {
                
            }
        }, 30000);

        // Add smooth animations
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.stats-card, .card');
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
        function toggleSidebar() {
            const sidebar = document.querySelector('.dashboard-sidebar');
            sidebar.classList.toggle('show');
        }

        // Add click handlers for buttons
        document.addEventListener('DOMContentLoaded', function() {
            // Add hover effects to table rows
            const tableRows = document.querySelectorAll('tbody tr');
            tableRows.forEach(row => {
                row.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateX(3px)';
                });
                
                row.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateX(0)';
                });
            });
        });
    </script>
</body>
</html>