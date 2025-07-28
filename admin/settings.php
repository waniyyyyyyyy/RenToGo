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
$error = '';

// First, fix the database structure to support Van and Coupe
function fixDatabaseStructure($db) {
    try {
        // Modify the ENUM to include Van and Coupe
        $alterQuery = "ALTER TABLE pricing_settings 
                       MODIFY COLUMN car_type ENUM('Sedan','Hatchback','SUV','MPV','Pickup','Van','Coupe') NOT NULL";
        $db->exec($alterQuery);
        
        // Clean up any broken records (empty car_type)
        $cleanupQuery = "DELETE FROM pricing_settings WHERE car_type = '' OR car_type IS NULL";
        $db->exec($cleanupQuery);
        
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Initialize default pricing (matching your current structure)
function initializeDefaultPricing($db) {
    $defaultPricing = [
        'Sedan' => ['base_fare' => 4.00, 'price_per_km' => 1.50, 'price_per_minute' => 0.30, 'peak_multiplier' => 1.20, 'minimum_fare' => 8.00],
        'Hatchback' => ['base_fare' => 4.50, 'price_per_km' => 1.30, 'price_per_minute' => 0.30, 'peak_multiplier' => 1.20, 'minimum_fare' => 7.00],
        'SUV' => ['base_fare' => 7.00, 'price_per_km' => 2.00, 'price_per_minute' => 0.30, 'peak_multiplier' => 1.20, 'minimum_fare' => 10.00],
        'MPV' => ['base_fare' => 6.50, 'price_per_km' => 1.80, 'price_per_minute' => 0.30, 'peak_multiplier' => 1.20, 'minimum_fare' => 9.00],
        'Pickup' => ['base_fare' => 6.00, 'price_per_km' => 1.70, 'price_per_minute' => 0.30, 'peak_multiplier' => 1.20, 'minimum_fare' => 8.50],
        'Van' => ['base_fare' => 8.00, 'price_per_km' => 2.20, 'price_per_minute' => 0.30, 'peak_multiplier' => 1.20, 'minimum_fare' => 12.00],
        'Coupe' => ['base_fare' => 5.50, 'price_per_km' => 1.60, 'price_per_minute' => 0.30, 'peak_multiplier' => 1.20, 'minimum_fare' => 8.50]
    ];
    
    try {
        foreach ($defaultPricing as $carType => $pricing) {
            $query = "INSERT INTO pricing_settings (car_type, base_fare, price_per_km, price_per_minute, peak_multiplier, minimum_fare) 
                      VALUES (?, ?, ?, ?, ?, ?) 
                      ON DUPLICATE KEY UPDATE 
                      base_fare = VALUES(base_fare), 
                      price_per_km = VALUES(price_per_km),
                      price_per_minute = VALUES(price_per_minute),
                      peak_multiplier = VALUES(peak_multiplier),
                      minimum_fare = VALUES(minimum_fare)";
            $stmt = $db->prepare($query);
            $stmt->execute([
                $carType, 
                $pricing['base_fare'], 
                $pricing['price_per_km'], 
                $pricing['price_per_minute'],
                $pricing['peak_multiplier'],
                $pricing['minimum_fare']
            ]);
        }
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Fix database structure first
if (!fixDatabaseStructure($db)) {
    $error = "Failed to update database structure for Van and Coupe support.";
} else {
    // Check if Van and Coupe exist, if not add them
    $checkQuery = "SELECT car_type FROM pricing_settings WHERE car_type IN ('Van', 'Coupe')";
    $stmt = $db->prepare($checkQuery);
    $stmt->execute();
    $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($existing) < 2) {
        // Add missing Van and Coupe
        $vanExists = in_array('Van', $existing);
        $coupeExists = in_array('Coupe', $existing);
        
        if (!$vanExists) {
            $query = "INSERT INTO pricing_settings (car_type, base_fare, price_per_km, price_per_minute, peak_multiplier, minimum_fare) 
                      VALUES ('Van', 8.00, 2.20, 0.30, 1.20, 12.00)";
            $db->exec($query);
        }
        
        if (!$coupeExists) {
            $query = "INSERT INTO pricing_settings (car_type, base_fare, price_per_km, price_per_minute, peak_multiplier, minimum_fare) 
                      VALUES ('Coupe', 5.50, 1.60, 0.30, 1.20, 8.50)";
            $db->exec($query);
        }
        
        $_SESSION['temp_message'] = "Van and Coupe pricing added successfully!";
    }
}

// Force update all pricing to correct values (remove this after first run if needed)
if (isset($_GET['reset_pricing'])) {
    try {
        // Delete all existing pricing
        $query = "DELETE FROM pricing_settings";
        $stmt = $db->prepare($query);
        $stmt->execute();
        
        // Re-initialize with correct values
        initializeDefaultPricing($db);
        
        $message = "All pricing has been reset to default values!";
        
        // Redirect to remove the reset parameter
        header("Location: settings.php");
        exit();
    } catch (Exception $e) {
        $error = "Error resetting pricing: " . $e->getMessage();
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_pricing'])) {
        $car_type = $_POST['car_type'];
        $base_fare = $_POST['base_fare'];
        $price_per_km = $_POST['price_per_km'];
        $minimum_fare = $_POST['minimum_fare'];
        
        try {
            $query = "INSERT INTO pricing_settings (car_type, base_fare, price_per_km, price_per_minute, peak_multiplier, minimum_fare) 
                      VALUES (?, ?, ?, 0.30, 1.20, ?) 
                      ON DUPLICATE KEY UPDATE 
                      base_fare = VALUES(base_fare), 
                      price_per_km = VALUES(price_per_km),
                      minimum_fare = VALUES(minimum_fare)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(1, $car_type);
            $stmt->bindParam(2, $base_fare);
            $stmt->bindParam(3, $price_per_km);
            $stmt->bindParam(4, $minimum_fare);
            
            if ($stmt->execute()) {
                $message = "Pricing updated successfully for $car_type vehicles!";
            }
        } catch (Exception $e) {
            $error = "Error updating pricing: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['update_system_settings'])) {
        $settings = $_POST['settings'];
        
        try {
            $db->beginTransaction();
            
            foreach ($settings as $key => $value) {
                $query = "INSERT INTO system_settings (setting_key, setting_value) 
                          VALUES (?, ?) 
                          ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
                $stmt = $db->prepare($query);
                $stmt->bindParam(1, $key);
                $stmt->bindParam(2, $value);
                $stmt->execute();
            }
            
            $db->commit();
            $message = "System settings updated successfully!";
        } catch (Exception $e) {
            $db->rollback();
            $error = "Error updating system settings: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['backup_database'])) {
        // Simple backup simulation - in real implementation, you'd use mysqldump
        $message = "Database backup initiated! (Feature requires server-side implementation)";
    }
    
    if (isset($_POST['clear_logs'])) {
        // Clear application logs simulation
        $message = "Application logs cleared successfully!";
    }
}

// Get current pricing settings
try {
    $query = "SELECT * FROM pricing_settings ORDER BY car_type";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $pricing_settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pricing_settings = [];
}

// Get current system settings
try {
    $query = "SELECT * FROM system_settings ORDER BY setting_key";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $system_settings_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Convert to associative array
    $system_settings = [];
    foreach ($system_settings_raw as $setting) {
        $system_settings[$setting['setting_key']] = $setting['setting_value'];
    }
} catch (Exception $e) {
    $system_settings = [];
}

// Get system statistics
$query = "SELECT 
            (SELECT COUNT(*) FROM user) as total_users,
            (SELECT COUNT(*) FROM booking) as total_bookings,
            (SELECT COUNT(*) FROM driver) as total_drivers,
            (SELECT COUNT(*) FROM pricing_settings) as pricing_rules";
$stmt = $db->prepare($query);
$stmt->execute();
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get predefined pricing for JavaScript
$predefinedPricing = [
    'Sedan' => ['base_fare' => 4.00, 'price_per_km' => 1.50, 'minimum_fare' => 8.00],
    'Hatchback' => ['base_fare' => 4.50, 'price_per_km' => 1.30, 'minimum_fare' => 7.00],
    'SUV' => ['base_fare' => 7.00, 'price_per_km' => 2.00, 'minimum_fare' => 10.00],
    'MPV' => ['base_fare' => 6.50, 'price_per_km' => 1.80, 'minimum_fare' => 9.00],
    'Pickup' => ['base_fare' => 6.00, 'price_per_km' => 1.70, 'minimum_fare' => 8.50],
    'Van' => ['base_fare' => 8.00, 'price_per_km' => 2.20, 'minimum_fare' => 12.00],
    'Coupe' => ['base_fare' => 5.50, 'price_per_km' => 1.60, 'minimum_fare' => 8.50]
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - RenToGo Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        /* Reset and base styles */
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

        /* Settings specific styles */
        .settings-section {
            margin-bottom: 2rem;
        }

        .setting-item {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
            border-left: 4px solid #0d6efd;
        }

        .setting-value {
            font-family: 'Courier New', monospace;
            background: #e9ecef;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.9rem;
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

        /* Animations */
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
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

        .col, .col-auto, .col-md-3, .col-md-4, .col-md-6, .col-md-9 {
            padding-left: 0.5rem;
            padding-right: 0.5rem;
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
                        <a class="nav-link" href="reports.php">
                            <i class="bi bi-file-earmark-text"></i> Reports
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="settings.php">
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
                            <h4 class="mb-0 fw-bold">System Settings</h4>
                            <small class="text-muted">Configure system parameters and pricing rules</small>
                        </div>
                        <div class="col-auto">
                            <div class="dropdown">
                                <button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                    <i class="bi bi-tools"></i> System Tools
                                </button>
                                <ul class="dropdown-menu">
                                    <li>
                                        <form method="POST" action="" class="d-inline w-100">
                                            <button type="submit" name="backup_database" class="dropdown-item">
                                                <i class="bi bi-download"></i> Backup Database
                                            </button>
                                        </form>
                                    </li>
                                    <li>
                                        <form method="POST" action="" class="d-inline w-100">
                                            <button type="submit" name="clear_logs" class="dropdown-item">
                                                <i class="bi bi-trash"></i> Clear Logs
                                            </button>
                                        </form>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="reports.php">
                                        <i class="bi bi-bar-chart"></i> View Reports
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
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="bi bi-check-circle"></i> <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="bi bi-exclamation-triangle"></i> <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- System Overview -->
                <div class="row g-3 mb-4">
                    <div class="col-md-3">
                        <div class="card stat-card bg-primary text-white">
                            <div class="card-body text-center">
                                <h2 class="mb-1 fw-bold"><?php echo $stats['total_users'] ?? 0; ?></h2>
                                <small class="opacity-75">Total Users</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card bg-success text-white">
                            <div class="card-body text-center">
                                <h2 class="mb-1 fw-bold"><?php echo $stats['total_bookings'] ?? 0; ?></h2>
                                <small class="opacity-75">Total Bookings</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card bg-info text-white">
                            <div class="card-body text-center">
                                <h2 class="mb-1 fw-bold"><?php echo $stats['total_drivers'] ?? 0; ?></h2>
                                <small class="opacity-75">Active Drivers</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card bg-warning text-white">
                            <div class="card-body text-center">
                                <h2 class="mb-1 fw-bold"><?php echo $stats['pricing_rules'] ?? 0; ?></h2>
                                <small class="opacity-75">Pricing Rules</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row g-4">
                    <!-- Pricing Settings -->
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0 fw-bold">
                                    <i class="bi bi-currency-dollar"></i> Pricing Management
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label for="car_type" class="form-label fw-semibold">Car Type</label>
                                            <select class="form-select" name="car_type" id="car_type" required>
                                                <option value="">Select Car Type</option>
                                                <option value="Sedan">Sedan</option>
                                                <option value="Hatchback">Hatchback</option>
                                                <option value="SUV">SUV</option>
                                                <option value="MPV">MPV</option>
                                                <option value="Pickup">Pickup</option>
                                                <option value="Van">Van</option>
                                                <option value="Coupe">Coupe</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="base_fare" class="form-label fw-semibold">Base Fare (RM)</label>
                                            <input type="number" class="form-control" name="base_fare" id="base_fare" step="0.01" min="0" value="5.00" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="price_per_km" class="form-label fw-semibold">Price per KM (RM)</label>
                                            <input type="number" class="form-control" name="price_per_km" id="price_per_km" step="0.01" min="0" value="1.50" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label for="minimum_fare" class="form-label fw-semibold">Minimum Fare (RM)</label>
                                            <input type="number" class="form-control" name="minimum_fare" id="minimum_fare" step="0.01" min="0" value="8.00" required>
                                        </div>
                                        <div class="col-12">
                                            <button type="submit" name="update_pricing" class="btn btn-primary">
                                                <i class="bi bi-check-lg"></i> Update Pricing
                                            </button>
                                            <a href="settings.php?reset_pricing=1" class="btn btn-warning ms-2" onclick="return confirm('Are you sure you want to reset all pricing to default values?')">
                                                <i class="bi bi-arrow-clockwise"></i> Reset All Pricing
                                            </a>
                                        </div>
                                    </div>
                                </form>

                                <?php if (!empty($pricing_settings)): ?>
                                <hr>
                                <h6 class="fw-semibold mb-3">Current Pricing Rules</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover">
                                        <thead>
                                            <tr>
                                                <th>Car Type</th>
                                                <th>Base Fare</th>
                                                <th>Per KM</th>
                                                <th>Minimum</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($pricing_settings as $pricing): ?>
                                            <tr>
                                                <td><span class="badge bg-primary"><?php echo $pricing['car_type']; ?></span></td>
                                                <td>RM <?php echo number_format($pricing['base_fare'], 2); ?></td>
                                                <td>RM <?php echo number_format($pricing['price_per_km'], 2); ?></td>
                                                <td>RM <?php echo number_format($pricing['minimum_fare'], 2); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- System Settings -->
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0 fw-bold">
                                    <i class="bi bi-gear"></i> System Configuration
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="">
                                    <div class="settings-section">
                                        <h6 class="fw-semibold text-primary mb-3">General Settings</h6>
                                        
                                        <div class="mb-3">
                                            <label for="currency" class="form-label fw-semibold">Currency Symbol</label>
                                            <input type="text" class="form-control" name="settings[currency]" 
                                                   value="<?php echo $system_settings['currency'] ?? 'RM'; ?>">
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="cancellation_fee" class="form-label fw-semibold">Booking Cancellation Fee (RM)</label>
                                            <input type="number" class="form-control" name="settings[booking_cancellation_fee]" 
                                                   step="0.01" min="0" value="<?php echo $system_settings['booking_cancellation_fee'] ?? '2.00'; ?>">
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="commission" class="form-label fw-semibold">Driver Commission (%)</label>
                                            <input type="number" class="form-control" name="settings[driver_commission_percentage]" 
                                                   step="1" min="0" max="100" value="<?php echo $system_settings['driver_commission_percentage'] ?? '20'; ?>">
                                        </div>
                                    </div>

                                    <div class="settings-section">
                                        <h6 class="fw-semibold text-success mb-3">Peak Hours</h6>
                                        
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label for="peak_start" class="form-label fw-semibold">Morning Peak Start</label>
                                                <input type="time" class="form-control" name="settings[peak_hours_start]" 
                                                       value="<?php echo $system_settings['peak_hours_start'] ?? '07:00'; ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label for="peak_end" class="form-label fw-semibold">Morning Peak End</label>
                                                <input type="time" class="form-control" name="settings[peak_hours_end]" 
                                                       value="<?php echo $system_settings['peak_hours_end'] ?? '09:00'; ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label for="peak_evening_start" class="form-label fw-semibold">Evening Peak Start</label>
                                                <input type="time" class="form-control" name="settings[peak_hours_evening_start]" 
                                                       value="<?php echo $system_settings['peak_hours_evening_start'] ?? '17:00'; ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label for="peak_evening_end" class="form-label fw-semibold">Evening Peak End</label>
                                                <input type="time" class="form-control" name="settings[peak_hours_evening_end]" 
                                                       value="<?php echo $system_settings['peak_hours_evening_end'] ?? '19:00'; ?>">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="d-grid">
                                        <button type="submit" name="update_system_settings" class="btn btn-success">
                                            <i class="bi bi-check-lg"></i> Save System Settings
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Current System Settings Display -->
                <?php if (!empty($system_settings)): ?>
                <div class="row g-4 mt-2">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0 fw-bold">
                                    <i class="bi bi-list-check"></i> Current System Settings
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <?php foreach ($system_settings as $key => $value): ?>
                                    <div class="col-md-4 mb-3">
                                        <div class="setting-item">
                                            <h6 class="mb-1 text-primary"><?php echo ucwords(str_replace('_', ' ', $key)); ?></h6>
                                            <span class="setting-value"><?php echo htmlspecialchars($value); ?></span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- System Information -->
                <div class="row g-4 mt-2">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0 fw-bold">
                                    <i class="bi bi-info-circle"></i> System Information
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="text-center p-3 border rounded">
                                            <h6 class="text-muted">PHP Version</h6>
                                            <h5 class="text-primary"><?php echo PHP_VERSION; ?></h5>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-center p-3 border rounded">
                                            <h6 class="text-muted">Server Software</h6>
                                            <h6 class="text-primary"><?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'; ?></h6>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-center p-3 border rounded">
                                            <h6 class="text-muted">Database</h6>
                                            <h6 class="text-primary">MySQL</h6>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="text-center p-3 border rounded">
                                            <h6 class="text-muted">System Status</h6>
                                            <h6 class="text-success">
                                                <i class="bi bi-check-circle"></i> Online
                                            </h6>
                                        </div>
                                    </div>
                                </div>
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
        // Predefined pricing data
        const predefinedPricing = <?php echo json_encode($predefinedPricing); ?>;

        // Auto-fill pricing when car type is selected
        document.getElementById('car_type').addEventListener('change', function() {
            const selectedType = this.value;
            if (selectedType && predefinedPricing[selectedType]) {
                const pricing = predefinedPricing[selectedType];
                document.getElementById('base_fare').value = pricing.base_fare.toFixed(2);
                document.getElementById('price_per_km').value = pricing.price_per_km.toFixed(2);
                document.getElementById('minimum_fare').value = pricing.minimum_fare.toFixed(2);
            }
        });

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
    </script>
</body>
</html>