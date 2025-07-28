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
$error = '';

// Get driver info
$query = "SELECT d.*, u.username, u.email, u.notel FROM driver d 
          JOIN user u ON d.userid = u.userid 
          WHERE d.userid = ?";
$stmt = $db->prepare($query);
$stmt->bindParam(1, $_SESSION['userid']);
$stmt->execute();
$driver = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$driver) {
    header("Location: ../auth/login.php");
    exit();
}

// Get pricing information for this car type
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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_car'])) {
        $carmodel = trim($_POST['carmodel']);
        $plate = strtoupper(trim($_POST['plate']));
        $capacity = (int)$_POST['capacity'];
        $car_type = $_POST['car_type'];
        $licensenumber = trim($_POST['licensenumber']);
        
        // Validate inputs
        if (empty($carmodel) || empty($plate) || empty($licensenumber)) {
            $error = "Please fill in all required fields.";
        } elseif ($capacity < 1 || $capacity > 20) {
            $error = "Capacity must be between 1 and 20 passengers.";
        } elseif (!preg_match('/^[A-Z0-9\s\-]+$/', $plate)) {
            $error = "Plate number should only contain letters, numbers, spaces, and hyphens.";
        } else {
            try {
                // Check if plate number is unique (excluding current driver)
                $query = "SELECT driverid FROM driver WHERE plate = ? AND driverid != ?";
                $stmt = $db->prepare($query);
                $stmt->bindParam(1, $plate);
                $stmt->bindParam(2, $driver['driverid']);
                $stmt->execute();
                
                if ($stmt->rowCount() > 0) {
                    $error = "This plate number is already registered by another driver.";
                } else {
                    // Update driver information (without price - admin controls pricing)
                    $query = "UPDATE driver SET 
                                carmodel = ?, 
                                plate = ?, 
                                capacity = ?, 
                                car_type = ?, 
                                licensenumber = ? 
                              WHERE userid = ?";
                    
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(1, $carmodel);
                    $stmt->bindParam(2, $plate);
                    $stmt->bindParam(3, $capacity);
                    $stmt->bindParam(4, $car_type);
                    $stmt->bindParam(5, $licensenumber);
                    $stmt->bindParam(6, $_SESSION['userid']);
                    
                    if ($stmt->execute()) {
                        $message = "Car details updated successfully!";
                        
                        // Refresh driver data
                        $query = "SELECT d.*, u.username, u.email, u.notel FROM driver d JOIN user u ON d.userid = u.userid WHERE d.userid = ?";
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(1, $_SESSION['userid']);
                        $stmt->execute();
                        $driver = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        // Refresh pricing info for the new car type
                        try {
                            $query = "SELECT * FROM pricing_settings WHERE car_type = ?";
                            $stmt = $db->prepare($query);
                            $stmt->bindParam(1, $driver['car_type']);
                            $stmt->execute();
                            $pricing_info = $stmt->fetch(PDO::FETCH_ASSOC);
                        } catch (Exception $e) {
                            $pricing_info = null;
                        }
                        
                    } else {
                        $error = "Failed to update car details. Please try again.";
                    }
                }
            } catch (PDOException $e) {
                $error = "Database error occurred. Please try again.";
            }
        }
    }
    
    if (isset($_POST['update_status'])) {
        $new_status = $_POST['status'];
        
        if (!in_array($new_status, ['available', 'not available', 'maintenance'])) {
            $error = "Invalid status selected.";
        } else {
            try {
                $query = "UPDATE driver SET status = ? WHERE userid = ?";
                $stmt = $db->prepare($query);
                $stmt->bindParam(1, $new_status);
                $stmt->bindParam(2, $_SESSION['userid']);
                
                if ($stmt->execute()) {
                    $message = "Status updated successfully!";
                    $driver['status'] = $new_status;
                } else {
                    $error = "Failed to update status.";
                }
            } catch (PDOException $e) {
                $error = "Database error occurred. Please try again.";
            }
        }
    }
}

// Get booking statistics for this driver
try {
    $query = "SELECT 
                COUNT(*) as total_bookings,
                COUNT(CASE WHEN bookingstatus = 'completed' THEN 1 END) as completed_bookings,
                COALESCE(SUM(CASE WHEN bookingstatus = 'completed' THEN totalcost END), 0) as total_earnings,
                AVG(CASE WHEN bookingstatus = 'completed' THEN totalcost END) as avg_booking_value
              FROM booking WHERE driverid = ?";
    $stmt = $db->prepare($query);
    $stmt->bindParam(1, $driver['driverid']);
    $stmt->execute();
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $stats = [
        'total_bookings' => 0,
        'completed_bookings' => 0,
        'total_earnings' => 0,
        'avg_booking_value' => 0
    ];
}

// Get recent ratings
try {
    $query = "SELECT r.rating, r.review, r.created_at, u.username 
              FROM rating r
              JOIN booking b ON r.bookingid = b.bookingid
              JOIN user u ON r.userid = u.userid
              WHERE r.driverid = ?
              ORDER BY r.created_at DESC
              LIMIT 5";
    $stmt = $db->prepare($query);
    $stmt->bindParam(1, $driver['driverid']);
    $stmt->execute();
    $recent_ratings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recent_ratings = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Car Details - RenToGo</title>
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
            transition: transform 0.2s ease, box-shadow 0.2s ease;
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
            border-color: #28a745;
            box-shadow: 0 0 0 0.2rem rgba(40, 167, 69, 0.1);
        }

        .form-control.is-invalid {
            border-color: #dc3545;
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

        /* Statistics */
        .stat-item {
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 10px;
            transition: all 0.3s ease;
        }

        .stat-item:hover {
            background: #e9ecef;
            transform: translateY(-2px);
        }

        /* Pricing info styling */
        .pricing-info {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-top: 1rem;
        }

        .pricing-detail {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
        }

        .pricing-detail:last-child {
            margin-bottom: 0;
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
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* Animations */
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Status indicators */
        .status-available { color: #28a745; }
        .status-not-available { color: #dc3545; }
        .status-maintenance { color: #ffc107; }

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
                        <a class="nav-link active" href="car-details.php">
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
                            <h4 class="mb-0 fw-bold">Car Details & Settings</h4>
                            <small class="text-muted">Manage your vehicle information and availability</small>
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
                        <div class="alert alert-success alert-dismissible fade show">
                            <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($message); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show">
                            <i class="bi bi-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <div class="row g-4">
                        <!-- Car Information Form -->
                        <div class="col-lg-8">
                            <div class="card fade-in">
                                <div class="card-header">
                                    <h5 class="mb-0">
                                        <i class="bi bi-car-front"></i> Vehicle Information
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST" action="" id="carDetailsForm">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label for="carmodel" class="form-label fw-semibold">Car Model *</label>
                                                <input type="text" class="form-control" id="carmodel" name="carmodel" 
                                                       value="<?php echo htmlspecialchars($driver['carmodel']); ?>" 
                                                       placeholder="e.g., Toyota Vios, Honda City" required>
                                                <div class="invalid-feedback"></div>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="plate" class="form-label fw-semibold">License Plate *</label>
                                                <input type="text" class="form-control" id="plate" name="plate" 
                                                       value="<?php echo htmlspecialchars($driver['plate']); ?>" 
                                                       placeholder="e.g., WXY 1234, ABC-1234" required>
                                                <small class="text-muted">Letters, numbers, spaces, and hyphens allowed</small>
                                                <div class="invalid-feedback"></div>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="car_type" class="form-label fw-semibold">Car Type *</label>
                                                <select class="form-select" id="car_type" name="car_type" required>
                                                    <option value="">Select Car Type</option>
                                                    <option value="Sedan" <?php echo $driver['car_type'] == 'Sedan' ? 'selected' : ''; ?>>Sedan</option>
                                                    <option value="Hatchback" <?php echo $driver['car_type'] == 'Hatchback' ? 'selected' : ''; ?>>Hatchback</option>
                                                    <option value="SUV" <?php echo $driver['car_type'] == 'SUV' ? 'selected' : ''; ?>>SUV</option>
                                                    <option value="MPV" <?php echo $driver['car_type'] == 'MPV' ? 'selected' : ''; ?>>MPV</option>
                                                    <option value="Pickup" <?php echo $driver['car_type'] == 'Pickup' ? 'selected' : ''; ?>>Pickup</option>
                                                    <option value="Convertible" <?php echo $driver['car_type'] == 'Convertible' ? 'selected' : ''; ?>>Convertible</option>
                                                </select>
                                                <div class="invalid-feedback"></div>
                                            </div>
                                            <div class="col-md-6">
                                                <label for="capacity" class="form-label fw-semibold">Passenger Capacity *</label>
                                                <select class="form-select" id="capacity" name="capacity" required>
                                                    <option value="">Select Capacity</option>
                                                    <?php for ($i = 1; $i <= 20; $i++): ?>
                                                        <option value="<?php echo $i; ?>" <?php echo $driver['capacity'] == $i ? 'selected' : ''; ?>>
                                                            <?php echo $i; ?> passenger<?php echo $i > 1 ? 's' : ''; ?>
                                                        </option>
                                                    <?php endfor; ?>
                                                </select>
                                                <div class="invalid-feedback"></div>
                                            </div>
                                            <div class="col-12">
                                                <label for="licensenumber" class="form-label fw-semibold">Driver License Number *</label>
                                                <input type="text" class="form-control" id="licensenumber" name="licensenumber" 
                                                       value="<?php echo htmlspecialchars($driver['licensenumber']); ?>" 
                                                       placeholder="e.g., D123456789" required>
                                                <div class="invalid-feedback"></div>
                                            </div>
                                        </div>
                                        
                                        <!-- Pricing Information (Read-only, set by admin) -->
                                        <?php if ($pricing_info): ?>
                                        <div class="pricing-info">
                                            <h6 class="mb-3">
                                                <i class="bi bi-currency-dollar"></i> Current Pricing for <?php echo htmlspecialchars($driver['car_type']); ?>
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
                                                Pricing is set by admin. Formula: Base Fare + (Distance × Price per KM)
                                            </small>
                                        </div>
                                        <?php else: ?>
                                        <div class="alert alert-warning mt-3">
                                            <i class="bi bi-exclamation-triangle"></i>
                                            <strong>No pricing set for <?php echo htmlspecialchars($driver['car_type']); ?> vehicles.</strong>
                                            <br>
                                            <small>Contact admin to set up pricing for your car type. You won't receive bookings until pricing is configured.</small>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <hr class="my-4">
                                        
                                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                            <button type="submit" name="update_car" class="btn btn-primary">
                                                <i class="bi bi-check-circle"></i> Update Car Details
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Status & Statistics -->
                        <div class="col-lg-4">
                            <!-- Availability Status -->
                            <div class="card mb-4 fade-in">
                                <div class="card-header">
                                    <h6 class="mb-0">
                                        <i class="bi bi-toggle-on"></i> Availability Status
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <form method="POST" action="" id="statusForm">
                                        <div class="mb-3">
                                            <label for="status" class="form-label fw-semibold">Current Status</label>
                                            <select class="form-select" id="status" name="status" onchange="updateStatus()">
                                                <option value="available" <?php echo $driver['status'] == 'available' ? 'selected' : ''; ?>>
                                                    Available for Booking
                                                </option>
                                                <option value="not available" <?php echo $driver['status'] == 'not available' ? 'selected' : ''; ?>>
                                                    Not Available
                                                </option>
                                                <option value="maintenance" <?php echo $driver['status'] == 'maintenance' ? 'selected' : ''; ?>>
                                                    Under Maintenance
                                                </option>
                                            </select>
                                        </div>
                                        <input type="hidden" name="update_status" value="1">
                                    </form>
                                    
                                    <div class="alert alert-<?php echo $driver['status'] == 'available' ? 'success' : 'warning'; ?> mb-0">
                                        <i class="bi bi-info-circle"></i>
                                        <span id="status-message">
                                            <?php if ($driver['status'] == 'available'): ?>
                                                Your car is visible to students and available for booking.
                                            <?php elseif ($driver['status'] == 'not available'): ?>
                                                Your car is hidden from students. No new bookings will be accepted.
                                            <?php else: ?>
                                                Your car is marked as under maintenance. Students can see this status.
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Performance Statistics -->
                            <div class="card mb-4 fade-in">
                                <div class="card-header">
                                    <h6 class="mb-0">
                                        <i class="bi bi-bar-chart"></i> Performance Statistics
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row g-3 text-center">
                                        <div class="col-6">
                                            <div class="stat-item">
                                                <h4 class="text-primary mb-0"><?php echo $stats['total_bookings'] ?? 0; ?></h4>
                                                <small class="text-muted">Total Bookings</small>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="stat-item">
                                                <h4 class="text-success mb-0"><?php echo $stats['completed_bookings'] ?? 0; ?></h4>
                                                <small class="text-muted">Completed</small>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="stat-item">
                                                <h4 class="text-info mb-0">RM<?php echo number_format($stats['total_earnings'] ?? 0, 0); ?></h4>
                                                <small class="text-muted">Total Earnings</small>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="stat-item">
                                                <h4 class="text-warning mb-0">
                                                    <?php echo isset($driver['rating']) && $driver['rating'] > 0 ? number_format($driver['rating'], 1) : 'N/A'; ?>
                                                </h4>
                                                <small class="text-muted">Rating</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Driver Info Summary -->
                            <div class="card fade-in">
                                <div class="card-header">
                                    <h6 class="mb-0">
                                        <i class="bi bi-person-circle"></i> Driver Information
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <ul class="list-unstyled mb-0">
                                        <li class="mb-2">
                                            <i class="bi bi-person text-muted me-2"></i>
                                            <strong><?php echo htmlspecialchars($driver['username']); ?></strong>
                                        </li>
                                        <li class="mb-2">
                                            <i class="bi bi-envelope text-muted me-2"></i>
                                            <?php echo htmlspecialchars($driver['email']); ?>
                                        </li>
                                        <li class="mb-2">
                                            <i class="bi bi-phone text-muted me-2"></i>
                                            <?php echo htmlspecialchars($driver['notel']); ?>
                                        </li>
                                        <li class="mb-2">
                                            <i class="bi bi-calendar text-muted me-2"></i>
                                            Member since <?php echo date('M Y', strtotime($driver['datehired'])); ?>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Ratings -->
                    <?php if (!empty($recent_ratings)): ?>
                    <div class="row mt-4">
                        <div class="col-12">
                            <div class="card fade-in">
                                <div class="card-header">
                                    <h5 class="mb-0">
                                        <i class="bi bi-star"></i> Recent Ratings & Reviews
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <?php foreach ($recent_ratings as $rating): ?>
                                        <div class="col-md-6 mb-3">
                                            <div class="border rounded p-3 h-100">
                                                <div class="d-flex justify-content-between align-items-start mb-2">
                                                    <div>
                                                        <h6 class="mb-0"><?php echo htmlspecialchars($rating['username']); ?></h6>
                                                        <small class="text-muted">
                                                            <?php echo date('d M Y', strtotime($rating['created_at'])); ?>
                                                        </small>
                                                    </div>
                                                    <div class="text-warning">
                                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                                            <i class="bi bi-star<?php echo $i <= $rating['rating'] ? '-fill' : ''; ?>"></i>
                                                        <?php endfor; ?>
                                                    </div>
                                                </div>
                                                <?php if ($rating['review']): ?>
                                                    <p class="mb-0 text-muted">
                                                        "<?php echo htmlspecialchars($rating['review']); ?>"
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
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
        // Form validation functions
        function validateField(field, condition, message) {
            const feedback = field.parentElement.querySelector('.invalid-feedback');
            if (condition) {
                field.classList.remove('is-invalid');
                field.classList.add('is-valid');
                if (feedback) feedback.textContent = '';
                return true;
            } else {
                field.classList.remove('is-valid');
                field.classList.add('is-invalid');
                if (feedback) feedback.textContent = message;
                return false;
            }
        }

        // Add animations
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.fade-in');
            cards.forEach((card, index) => {
                card.style.animationDelay = (index * 0.1) + 's';
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

        // Close modal when clicking outside
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

        // Status update function
        function updateStatus() {
            document.getElementById('statusForm').submit();
        }

        // Form validation
        document.getElementById('carDetailsForm').addEventListener('submit', function(e) {
            const carmodel = document.getElementById('carmodel');
            const plate = document.getElementById('plate');
            const carType = document.getElementById('car_type');
            const capacity = document.getElementById('capacity');
            const license = document.getElementById('licensenumber');
            
            let isValid = true;
            
            // Validate car model
            if (!validateField(carmodel, carmodel.value.trim().length > 0, 'Car model is required')) {
                isValid = false;
            }
            
            // Validate plate
            const plateRegex = /^[A-Z0-9\s\-]+$/;
            const plateValue = plate.value.toUpperCase().trim();
            if (!validateField(plate, plateValue.length > 0 && plateRegex.test(plateValue), 'Invalid plate format. Use letters, numbers, spaces, and hyphens only')) {
                isValid = false;
            }
            
            // Validate car type
            if (!validateField(carType, carType.value !== '', 'Please select a car type')) {
                isValid = false;
            }
            
            // Validate capacity
            if (!validateField(capacity, capacity.value !== '', 'Please select passenger capacity')) {
                isValid = false;
            }
            
            // Validate license number
            if (!validateField(license, license.value.trim().length > 0, 'Driver license number is required')) {
                isValid = false;
            }
            
            if (!isValid) {
                e.preventDefault();
                // Scroll to first invalid field
                const firstInvalid = document.querySelector('.is-invalid');
                if (firstInvalid) {
                    firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    firstInvalid.focus();
                }
            } else {
                // Convert plate to uppercase before submission
                plate.value = plateValue;
            }
        });

        // Auto-format plate number to uppercase
        document.getElementById('plate').addEventListener('input', function(e) {
            e.target.value = e.target.value.toUpperCase();
        });

        // Real-time validation
        document.getElementById('carmodel').addEventListener('blur', function() {
            validateField(this, this.value.trim().length > 0, 'Car model is required');
        });

        document.getElementById('plate').addEventListener('blur', function() {
            const plateRegex = /^[A-Z0-9\s\-]+$/;
            const plateValue = this.value.toUpperCase().trim();
            validateField(this, plateValue.length > 0 && plateRegex.test(plateValue), 'Invalid plate format');
        });

        document.getElementById('licensenumber').addEventListener('blur', function() {
            validateField(this, this.value.trim().length > 0, 'Driver license number is required');
        });
    </script>
</body>
</html>