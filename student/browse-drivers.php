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

// Get search parameters
$search_name = isset($_GET['search_name']) ? trim($_GET['search_name']) : '';
$search_capacity = isset($_GET['search_capacity']) ? (int)$_GET['search_capacity'] : '';
$search_type = isset($_GET['search_type']) ? $_GET['search_type'] : '';
$search_rating = isset($_GET['search_rating']) ? (int)$_GET['search_rating'] : '';
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'rating';

// Build query with filters - Updated to include pricing information
$query = "SELECT d.*, u.username, u.notel, u.email,
                 COALESCE(AVG(r.rating), 0) as avg_rating,
                 COUNT(r.rating) as rating_count,
                 COUNT(b.bookingid) as total_bookings,
                 COALESCE(p.base_fare, 5.00) as base_fare,
                 COALESCE(p.price_per_km, 1.50) as price_per_km, 
                 COALESCE(p.minimum_fare, 8.00) as minimum_fare
          FROM driver d 
          JOIN user u ON d.userid = u.userid 
          LEFT JOIN rating r ON d.driverid = r.driverid
          LEFT JOIN booking b ON d.driverid = b.driverid AND b.bookingstatus = 'completed'
          LEFT JOIN pricing_settings p ON d.car_type = p.car_type
          WHERE u.status = 'active'";

$params = [];

if ($search_name) {
    $query .= " AND u.username LIKE ?";
    $params[] = "%$search_name%";
}

if ($search_capacity) {
    $query .= " AND d.capacity >= ?";
    $params[] = $search_capacity;
}

if ($search_type) {
    $query .= " AND d.car_type = ?";
    $params[] = $search_type;
}

if ($search_rating) {
    $query .= " AND d.rating >= ?";
    $params[] = $search_rating;
}

$query .= " GROUP BY d.driverid";

// Add sorting - Updated for distance-based pricing
switch ($sort_by) {
    case 'price_low':
        $query .= " ORDER BY p.base_fare ASC, p.price_per_km ASC";
        break;
    case 'price_high':
        $query .= " ORDER BY p.base_fare DESC, p.price_per_km DESC";
        break;
    case 'rating':
        $query .= " ORDER BY avg_rating DESC, d.rating DESC";
        break;
    case 'bookings':
        $query .= " ORDER BY total_bookings DESC";
        break;
    default:
        $query .= " ORDER BY d.rating DESC, avg_rating DESC";
}

$stmt = $db->prepare($query);
foreach ($params as $index => $param) {
    $stmt->bindParam($index + 1, $param);
}
$stmt->execute();
$drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get car types for filter
$query = "SELECT DISTINCT car_type FROM driver WHERE car_type IS NOT NULL ORDER BY car_type";
$stmt = $db->prepare($query);
$stmt->execute();
$car_types = $stmt->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse Drivers - RenToGo</title>
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

        /* Driver Cards */
        .driver-card {
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .driver-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(111, 66, 193, 0.15);
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

        /* Rating stars */
        .rating .bi-star-fill {
            color: #ffc107;
        }

        .rating .bi-star {
            color: #dee2e6;
        }

        /* Pricing display */
        .pricing-info {
            background: linear-gradient(135deg, #6f42c1, #8b5cf6);
            color: white;
            border-radius: 10px;
            padding: 1rem;
        }

        /* Stats styling */
        .stat-item {
            padding: 0.5rem;
        }
        
        .stat-value {
            font-size: 1.1rem;
            font-weight: bold;
            color: #6f42c1;
        }
        
        .stat-label {
            font-size: 0.8rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .car-details .row {
            font-size: 0.9rem;
        }
        
        .rating i {
            font-size: 0.9rem;
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
                        <a class="nav-link active" href="browse-drivers.php">
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
                            <h4 class="mb-0">Browse Drivers</h4>
                            <small class="text-muted">Find the perfect driver for your trip</small>
                        </div>
                        <div class="col-auto">
                            <div class="d-flex gap-2">
                                <a href="book-ride.php" class="btn btn-primary">
                                    <i class="bi bi-plus-circle"></i> Book a Ride
                                </a>
                                <div class="dropdown">
                                    <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                        <i class="bi bi-sort-down"></i> Sort by
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li><a class="dropdown-item" href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'rating'])); ?>">
                                            <i class="bi bi-star"></i> Highest Rating
                                        </a></li>
                                        <li><a class="dropdown-item" href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'price_low'])); ?>">
                                            <i class="bi bi-arrow-up"></i> Price: Low to High
                                        </a></li>
                                        <li><a class="dropdown-item" href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'price_high'])); ?>">
                                            <i class="bi bi-arrow-down"></i> Price: High to Low
                                        </a></li>
                                        <li><a class="dropdown-item" href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'bookings'])); ?>">
                                            <i class="bi bi-trophy"></i> Most Bookings
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
                <div class="container-fluid">
                    
                    <!-- Search & Filter -->
                    <div class="card mb-4">
                        <div class="card-body">
                            <form method="GET" action="" class="row g-3">
                                <div class="col-md-3">
                                    <label for="search_name" class="form-label">Driver Name</label>
                                    <input type="text" class="form-control" id="search_name" name="search_name" 
                                           value="<?php echo htmlspecialchars($search_name); ?>" 
                                           placeholder="Search by name...">
                                </div>
                                <div class="col-md-2">
                                    <label for="search_capacity" class="form-label">Min. Capacity</label>
                                    <select class="form-control" id="search_capacity" name="search_capacity">
                                        <option value="">Any</option>
                                        <option value="2" <?php echo $search_capacity == 2 ? 'selected' : ''; ?>>2+ passengers</option>
                                        <option value="4" <?php echo $search_capacity == 4 ? 'selected' : ''; ?>>4+ passengers</option>
                                        <option value="6" <?php echo $search_capacity == 6 ? 'selected' : ''; ?>>6+ passengers</option>
                                        <option value="8" <?php echo $search_capacity == 8 ? 'selected' : ''; ?>>8+ passengers</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label for="search_type" class="form-label">Car Type</label>
                                    <select class="form-control" id="search_type" name="search_type">
                                        <option value="">Any type</option>
                                        <?php foreach ($car_types as $type): ?>
                                            <option value="<?php echo $type; ?>" <?php echo $search_type == $type ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($type); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label for="search_rating" class="form-label">Min. Rating</label>
                                    <select class="form-control" id="search_rating" name="search_rating">
                                        <option value="">Any rating</option>
                                        <option value="4" <?php echo $search_rating == 4 ? 'selected' : ''; ?>>4+ stars</option>
                                        <option value="3" <?php echo $search_rating == 3 ? 'selected' : ''; ?>>3+ stars</option>
                                        <option value="2" <?php echo $search_rating == 2 ? 'selected' : ''; ?>>2+ stars</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">&nbsp;</label>
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-search"></i> Search
                                        </button>
                                        <a href="browse-drivers.php" class="btn btn-outline-secondary">
                                            <i class="bi bi-arrow-clockwise"></i> Reset
                                        </a>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Results Count -->
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="mb-0">
                            <?php echo count($drivers); ?> driver(s) found
                            <?php if ($search_name || $search_capacity || $search_type || $search_rating): ?>
                                <small class="text-muted">- filtered results</small>
                            <?php endif; ?>
                        </h6>
                    </div>

                    <!-- Drivers Grid -->
                    <?php if (empty($drivers)): ?>
                        <div class="card">
                            <div class="card-body text-center py-5">
                                <i class="bi bi-car-front text-muted" style="font-size: 4rem;"></i>
                                <h5 class="mt-3">No drivers found</h5>
                                <p class="text-muted">Try adjusting your search criteria or check back later.</p>
                                <a href="browse-drivers.php" class="btn btn-primary">View All Drivers</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="row">
                            <?php foreach ($drivers as $driver): ?>
                            <div class="col-lg-6 col-xl-4 mb-4">
                                <div class="card h-100 driver-card">
                                    <div class="card-body">
                                        <!-- Driver Header -->
                                        <div class="d-flex justify-content-between align-items-start mb-3">
                                            <div>
                                                <h5 class="card-title mb-1">
                                                    <?php echo htmlspecialchars($driver['username']); ?>
                                                    <span class="badge bg-<?php echo $driver['status'] == 'available' ? 'success' : 'secondary'; ?> ms-2">
                                                        <?php echo ucfirst($driver['status']); ?>
                                                    </span>
                                                </h5>
                                                <div class="rating mb-2">
                                                    <?php if ($driver['avg_rating'] > 0): ?>
                                                        <span class="text-warning">
                                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                                <i class="bi bi-star<?php echo $i <= round($driver['avg_rating']) ? '-fill' : ''; ?>"></i>
                                                            <?php endfor; ?>
                                                        </span>
                                                        <small class="text-muted ms-1">
                                                            <?php echo number_format($driver['avg_rating'], 1); ?> (<?php echo $driver['rating_count']; ?> reviews)
                                                        </small>
                                                    <?php else: ?>
                                                        <span class="text-muted">No ratings yet</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="text-end">
                                                <div class="pricing-info text-center">
                                                    <div><strong>Base: RM <?php echo number_format($driver['base_fare'], 2); ?></strong></div>
                                                    <div><strong>RM <?php echo number_format($driver['price_per_km'], 2); ?>/km</strong></div>
                                                    <small>Min: RM <?php echo number_format($driver['minimum_fare'], 2); ?></small>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Car Details -->
                                        <div class="car-details mb-3">
                                            <h6 class="text-muted mb-2">Vehicle Information</h6>
                                            <div class="row g-2 text-sm">
                                                <div class="col-6">
                                                    <i class="bi bi-car-front text-muted me-1"></i>
                                                    <small><?php echo htmlspecialchars($driver['carmodel']); ?></small>
                                                </div>
                                                <div class="col-6">
                                                    <i class="bi bi-credit-card text-muted me-1"></i>
                                                    <small><?php echo htmlspecialchars($driver['plate']); ?></small>
                                                </div>
                                                <div class="col-6">
                                                    <i class="bi bi-people text-muted me-1"></i>
                                                    <small><?php echo $driver['capacity']; ?> passengers</small>
                                                </div>
                                                <div class="col-6">
                                                    <i class="bi bi-tag text-muted me-1"></i>
                                                    <small><?php echo htmlspecialchars($driver['car_type']); ?></small>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Driver Stats -->
                                        <div class="driver-stats mb-3">
                                            <div class="row g-2 text-center">
                                                <div class="col-4">
                                                    <div class="stat-item">
                                                        <div class="stat-value"><?php echo $driver['total_bookings']; ?></div>
                                                        <div class="stat-label">Trips</div>
                                                    </div>
                                                </div>
                                                <div class="col-4">
                                                    <div class="stat-item">
                                                        <div class="stat-value">
                                                            <?php echo date('Y', strtotime($driver['datehired'])); ?>
                                                        </div>
                                                        <div class="stat-label">Joined</div>
                                                    </div>
                                                </div>
                                                <div class="col-4">
                                                    <div class="stat-item">
                                                        <div class="stat-value">
                                                            <?php echo $driver['rating_count']; ?>
                                                        </div>
                                                        <div class="stat-label">Reviews</div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Action Buttons -->
                                        <div class="d-grid gap-2">
                                            <?php if ($driver['status'] == 'available'): ?>
                                                <a href="book-ride.php?driver_id=<?php echo $driver['driverid']; ?>" 
                                                   class="btn btn-primary">
                                                    <i class="bi bi-calendar-plus"></i> Book This Driver
                                                </a>
                                            <?php else: ?>
                                                <button class="btn btn-secondary" disabled>
                                                    <i class="bi bi-x-circle"></i> Not Available
                                                </button>
                                            <?php endif; ?>
                                            
                                            <div class="btn-group">
                                                <a href="tel:<?php echo $driver['notel']; ?>" class="btn btn-outline-primary btn-sm">
                                                    <i class="bi bi-telephone"></i> Call
                                                </a>
                                                <button class="btn btn-outline-info btn-sm" data-bs-toggle="modal" 
                                                        data-bs-target="#driverModal<?php echo $driver['driverid']; ?>">
                                                    <i class="bi bi-info-circle"></i> Details
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Driver Details Modal -->
                            <div class="modal fade" id="driverModal<?php echo $driver['driverid']; ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">
                                                <i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($driver['username']); ?>
                                            </h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <h6 class="text-primary mb-3">Driver Information</h6>
                                                    <ul class="list-unstyled">
                                                        <li class="mb-2">
                                                            <i class="bi bi-person text-muted me-2"></i>
                                                            <strong>Name:</strong> <?php echo htmlspecialchars($driver['username']); ?>
                                                        </li>
                                                        <li class="mb-2">
                                                            <i class="bi bi-phone text-muted me-2"></i>
                                                            <strong>Phone:</strong> <?php echo htmlspecialchars($driver['notel']); ?>
                                                        </li>
                                                        <li class="mb-2">
                                                            <i class="bi bi-card-text text-muted me-2"></i>
                                                            <strong>License:</strong> <?php echo htmlspecialchars($driver['licensenumber']); ?>
                                                        </li>
                                                        <li class="mb-2">
                                                            <i class="bi bi-calendar text-muted me-2"></i>
                                                            <strong>Member Since:</strong> <?php echo date('F Y', strtotime($driver['datehired'])); ?>
                                                        </li>
                                                    </ul>
                                                </div>
                                                <div class="col-md-6">
                                                    <h6 class="text-success mb-3">Vehicle Details</h6>
                                                    <ul class="list-unstyled">
                                                        <li class="mb-2">
                                                            <i class="bi bi-car-front text-muted me-2"></i>
                                                            <strong>Model:</strong> <?php echo htmlspecialchars($driver['carmodel']); ?>
                                                        </li>
                                                        <li class="mb-2">
                                                            <i class="bi bi-credit-card text-muted me-2"></i>
                                                            <strong>Plate:</strong> <?php echo htmlspecialchars($driver['plate']); ?>
                                                        </li>
                                                        <li class="mb-2">
                                                            <i class="bi bi-people text-muted me-2"></i>
                                                            <strong>Capacity:</strong> <?php echo $driver['capacity']; ?> passengers
                                                        </li>
                                                        <li class="mb-2">
                                                            <i class="bi bi-tag text-muted me-2"></i>
                                                            <strong>Type:</strong> <?php echo htmlspecialchars($driver['car_type']); ?>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                            
                                            <!-- Pricing Information -->
                                            <div class="pricing-info mb-3">
                                                <h6 class="mb-3">
                                                    <i class="bi bi-calculator text-white me-2"></i>Pricing Information
                                                </h6>
                                                <div class="row text-center">
                                                    <div class="col-4">
                                                        <div><strong>Base Fare</strong></div>
                                                        <div>RM <?php echo number_format($driver['base_fare'], 2); ?></div>
                                                    </div>
                                                    <div class="col-4">
                                                        <div><strong>Per Kilometer</strong></div>
                                                        <div>RM <?php echo number_format($driver['price_per_km'], 2); ?></div>
                                                    </div>
                                                    <div class="col-4">
                                                        <div><strong>Minimum Fare</strong></div>
                                                        <div>RM <?php echo number_format($driver['minimum_fare'], 2); ?></div>
                                                    </div>
                                                </div>
                                                <hr class="my-3 border-light">
                                                <small class="text-white-50">
                                                    <i class="bi bi-info-circle me-1"></i>
                                                    Final cost = Base Fare + (Distance × Rate per KM), minimum fare applies
                                                </small>
                                            </div>
                                            
                                            <div class="text-center">
                                                <h6 class="mb-3">Rating & Reviews</h6>
                                                <?php if ($driver['avg_rating'] > 0): ?>
                                                    <div class="d-flex justify-content-center align-items-center mb-3">
                                                        <div class="text-center me-4">
                                                            <h2 class="text-primary mb-0"><?php echo number_format($driver['avg_rating'], 1); ?></h2>
                                                            <div class="text-warning">
                                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                                    <i class="bi bi-star<?php echo $i <= round($driver['avg_rating']) ? '-fill' : ''; ?>"></i>
                                                                <?php endfor; ?>
                                                            </div>
                                                            <small class="text-muted"><?php echo $driver['rating_count']; ?> reviews</small>
                                                        </div>
                                                        <div class="text-center">
                                                            <h3 class="text-info mb-0"><?php echo $driver['total_bookings']; ?></h3>
                                                            <small class="text-muted">Completed Trips</small>
                                                        </div>
                                                    </div>
                                                <?php else: ?>
                                                    <p class="text-muted">No ratings yet</p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                            <?php if ($driver['status'] == 'available'): ?>
                                                <a href="book-ride.php?driver_id=<?php echo $driver['driverid']; ?>" 
                                                   class="btn btn-primary">
                                                    <i class="bi bi-calendar-plus"></i> Book This Driver
                                                </a>
                                            <?php endif; ?>
                                        </div>
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
    <div class="logout-confirmation" id="logoutConfirmation" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); display: none; justify-content: center; align-items: center; z-index: 9999; backdrop-filter: blur(5px);">
        <div class="confirmation-box" style="background: white; padding: 2rem; border-radius: 15px; text-align: center; max-width: 400px; width: 90%; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);">
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
        // Logout confirmation functions
        function showLogoutConfirmation() {
            const modal = document.getElementById('logoutConfirmation');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function hideLogoutConfirmation() {
            const modal = document.getElementById('logoutConfirmation');
            modal.style.display = 'none';
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