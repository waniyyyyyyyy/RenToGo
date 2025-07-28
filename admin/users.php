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

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['toggle_status'])) {
        $userid = $_POST['userid'];
        $current_status = $_POST['current_status'];
        $new_status = ($current_status == 'active') ? 'inactive' : 'active';
        
        $query = "UPDATE user SET status = ? WHERE userid = ?";
        $stmt = $db->prepare($query);
        $stmt->bindParam(1, $new_status);
        $stmt->bindParam(2, $userid);
        
        if ($stmt->execute()) {
            $message = "User status updated successfully!";
        }
    }
    
    if (isset($_POST['delete_user'])) {
        $userid = $_POST['userid'];
        
        try {
            $db->beginTransaction();
            
            // Delete user and related records (CASCADE should handle this)
            $query = "DELETE FROM user WHERE userid = ?";
            $stmt = $db->prepare($query);
            $stmt->bindParam(1, $userid);
            $stmt->execute();
            
            $db->commit();
            $message = "User deleted successfully!";
        } catch (Exception $e) {
            $db->rollback();
            $message = "Error deleting user: " . $e->getMessage();
        }
    }
}

// Get filter parameters
$role_filter = isset($_GET['role']) ? $_GET['role'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query with filters
$query = "SELECT u.*, 
                 CASE 
                     WHEN u.role = 'student' THEN s.student_number
                     WHEN u.role = 'driver' THEN d.licensenumber
                     WHEN u.role = 'admin' THEN a.admin_level
                 END as role_specific_info,
                 CASE 
                     WHEN u.role = 'student' THEN s.faculty
                     WHEN u.role = 'driver' THEN d.carmodel
                     WHEN u.role = 'admin' THEN a.department
                 END as additional_info,
                 CASE 
                     WHEN u.role = 'student' THEN s.year_of_study
                     WHEN u.role = 'driver' THEN d.plate
                     ELSE NULL
                 END as extra_info,
                 CASE 
                     WHEN u.role = 'driver' THEN d.status
                     ELSE NULL
                 END as driver_status
          FROM user u
          LEFT JOIN student s ON u.userid = s.userid
          LEFT JOIN driver d ON u.userid = d.userid
          LEFT JOIN admin a ON u.userid = a.userid
          WHERE 1=1";

$params = [];

if ($role_filter) {
    $query .= " AND u.role = ?";
    $params[] = $role_filter;
}

if ($status_filter) {
    $query .= " AND u.status = ?";
    $params[] = $status_filter;
}

if ($search_query) {
    $query .= " AND (u.username LIKE ? OR u.email LIKE ?)";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
}

$query .= " ORDER BY u.created DESC";

$stmt = $db->prepare($query);
foreach ($params as $index => $param) {
    $stmt->bindParam($index + 1, $param);
}
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get user statistics
$query = "SELECT 
            COUNT(*) as total_users,
            COUNT(CASE WHEN role = 'student' THEN 1 END) as total_students,
            COUNT(CASE WHEN role = 'driver' THEN 1 END) as total_drivers,
            COUNT(CASE WHEN role = 'admin' THEN 1 END) as total_admins,
            COUNT(CASE WHEN status = 'active' THEN 1 END) as active_users,
            COUNT(CASE WHEN status = 'inactive' THEN 1 END) as inactive_users
          FROM user";
$stmt = $db->prepare($query);
$stmt->execute();
$stats = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - RenToGo Admin</title>
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

        /* Main Content - FIXED */
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

        .user-avatar {
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, #0d6efd, #6610f2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 0.9rem;
            flex-shrink: 0;
        }

        /* Badge improvements */
        .badge {
            font-size: 0.75em;
            font-weight: 500;
            letter-spacing: 0.5px;
        }

        /* Button group improvements */
        .btn-group .btn {
            border-radius: 6px !important;
            margin-right: 2px;
        }

        .btn-group .btn:last-child {
            margin-right: 0;
        }

        .btn-sm {
            padding: 0.4rem 0.7rem;
            font-size: 0.8rem;
        }

        /* Status badges */
        .badge {
            font-size: 0.75em;
            padding: 0.5em 0.75em;
        }

        .status-available { background-color: #28a745 !important; }
        .status-busy { background-color: #ffc107 !important; }
        .status-offline { background-color: #6c757d !important; }

        /* Animations */
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
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
                        <a class="nav-link active" href="users.php">
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
                            <h4 class="mb-0 fw-bold">User Management</h4>
                            <small class="text-muted">Manage all system users and their accounts</small>
                        </div>
                        <div class="col-auto">
                            <div class="dropdown">
                                <button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                    <i class="bi bi-download"></i> Export
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a class="dropdown-item" href="?export=users&format=csv">
                                        <i class="bi bi-filetype-csv"></i> Export as CSV
                                    </a></li>
                                    <li><a class="dropdown-item" href="?export=users&format=pdf">
                                        <i class="bi bi-filetype-pdf"></i> Export as PDF
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

                <!-- Statistics Cards -->
                <div class="row g-3 mb-4">
                    <div class="col-md-2">
                        <div class="card stat-card bg-primary text-white">
                            <div class="card-body text-center">
                                <h2 class="mb-1 fw-bold"><?php echo $stats['total_users']; ?></h2>
                                <small class="opacity-75">Total Users</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card stat-card bg-info text-white">
                            <div class="card-body text-center">
                                <h2 class="mb-1 fw-bold"><?php echo $stats['total_students']; ?></h2>
                                <small class="opacity-75">Students</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card stat-card bg-success text-white">
                            <div class="card-body text-center">
                                <h2 class="mb-1 fw-bold"><?php echo $stats['total_drivers']; ?></h2>
                                <small class="opacity-75">Drivers</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card stat-card bg-warning text-white">
                            <div class="card-body text-center">
                                <h2 class="mb-1 fw-bold"><?php echo $stats['total_admins']; ?></h2>
                                <small class="opacity-75">Admins</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card stat-card bg-success text-white">
                            <div class="card-body text-center">
                                <h2 class="mb-1 fw-bold"><?php echo $stats['active_users']; ?></h2>
                                <small class="opacity-75">Active</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card stat-card bg-secondary text-white">
                            <div class="card-body text-center">
                                <h2 class="mb-1 fw-bold"><?php echo $stats['inactive_users']; ?></h2>
                                <small class="opacity-75">Inactive</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Search and Filters -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" action="" class="row g-3">
                            <div class="col-md-4">
                                <label for="search" class="form-label fw-semibold">Search Users</label>
                                <input type="text" class="form-control" id="search" name="search" 
                                       value="<?php echo htmlspecialchars($search_query); ?>" 
                                       placeholder="Search by username or email...">
                            </div>
                            <div class="col-md-3">
                                <label for="role" class="form-label fw-semibold">Filter by Role</label>
                                <select class="form-select" id="role" name="role">
                                    <option value="">All Roles</option>
                                    <option value="student" <?php echo $role_filter == 'student' ? 'selected' : ''; ?>>Students</option>
                                    <option value="driver" <?php echo $role_filter == 'driver' ? 'selected' : ''; ?>>Drivers</option>
                                    <option value="admin" <?php echo $role_filter == 'admin' ? 'selected' : ''; ?>>Admins</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="status" class="form-label fw-semibold">Filter by Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="">All Statuses</option>
                                    <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $status_filter == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-search"></i> Search
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Results -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 fw-bold">
                            <i class="bi bi-people"></i> Users List
                            <span class="badge bg-secondary"><?php echo count($users); ?> found</span>
                            <?php if (count($users) != $stats['total_users']): ?>
                                <small class="text-muted">(filtered from <?php echo $stats['total_users']; ?> total)</small>
                            <?php endif; ?>
                        </h5>
                        <div class="d-flex gap-2">
                            <a href="users.php" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-arrow-clockwise"></i> Reset Filters
                            </a>
                            <?php if (empty($users)): ?>
                                <button class="btn btn-sm btn-outline-info" onclick="location.reload()">
                                    <i class="bi bi-arrow-clockwise"></i> Refresh
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($users)): ?>
                            <div class="text-center py-5">
                                <i class="bi bi-people text-muted" style="font-size: 4rem;"></i>
                                <h5 class="mt-3">No users found</h5>
                                <p class="text-muted">No users match your current search criteria.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th width="20%">User</th>
                                            <th width="15%">Role</th>
                                            <th width="25%">Contact</th>
                                            <th width="10%">Status</th>
                                            <th width="15%">Joined</th>
                                            <th width="15%">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($users as $user): ?>
                                        <tr class="fade-in">
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="user-avatar me-3">
                                                        <?php echo strtoupper(substr($user['username'], 0, 2)); ?>
                                                    </div>
                                                    <div>
                                                        <h6 class="mb-1 fw-semibold"><?php echo htmlspecialchars($user['username']); ?></h6>
                                                        <small class="text-muted">ID: <?php echo $user['userid']; ?></small>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $user['role'] == 'student' ? 'primary' : ($user['role'] == 'driver' ? 'success' : 'warning'); ?> px-2 py-1">
                                                    <?php echo ucfirst($user['role']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="small">
                                                    <div class="mb-1">
                                                        <i class="bi bi-envelope text-primary me-2"></i>
                                                        <?php echo htmlspecialchars($user['email']); ?>
                                                    </div>
                                                    <div class="mb-1">
                                                        <i class="bi bi-phone text-success me-2"></i>
                                                        <?php echo htmlspecialchars($user['notel']); ?>
                                                    </div>
                                                    <div>
                                                        <i class="bi bi-person text-info me-2"></i>
                                                        <?php echo $user['gender']; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $user['status'] == 'active' ? 'success' : 'secondary'; ?> px-2 py-1">
                                                    <i class="bi bi-<?php echo $user['status'] == 'active' ? 'check-circle' : 'x-circle'; ?> me-1"></i>
                                                    <?php echo ucfirst($user['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="small text-muted">
                                                    <div><?php echo date('d M Y', strtotime($user['created'])); ?></div>
                                                    <div><?php echo date('h:i A', strtotime($user['created'])); ?></div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <!-- View Details -->
                                                    <button class="btn btn-outline-info btn-sm" data-bs-toggle="modal" 
                                                            data-bs-target="#userModal<?php echo $user['userid']; ?>" 
                                                            title="View Details">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    
                                                    <!-- Toggle Status -->
                                                    <?php if ($user['userid'] != $_SESSION['userid']): ?>
                                                        <form method="POST" action="" class="d-inline">
                                                            <input type="hidden" name="userid" value="<?php echo $user['userid']; ?>">
                                                            <input type="hidden" name="current_status" value="<?php echo $user['status']; ?>">
                                                            <button type="submit" name="toggle_status" 
                                                                    class="btn btn-outline-<?php echo $user['status'] == 'active' ? 'warning' : 'success'; ?> btn-sm"
                                                                    title="<?php echo $user['status'] == 'active' ? 'Deactivate User' : 'Activate User'; ?>">
                                                                <i class="bi bi-<?php echo $user['status'] == 'active' ? 'pause-circle' : 'play-circle'; ?>"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    
                                                    <!-- Delete User -->
                                                    <?php if ($user['userid'] != $_SESSION['userid'] && $user['role'] != 'admin'): ?>
                                                        <form method="POST" action="" class="d-inline">
                                                            <input type="hidden" name="userid" value="<?php echo $user['userid']; ?>">
                                                            <button type="submit" name="delete_user" class="btn btn-outline-danger btn-sm"
                                                                    title="Delete User"
                                                                    onclick="return confirm('Are you sure you want to delete this user? This action cannot be undone.')">
                                                                <i class="bi bi-trash3"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
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

    <!-- User Modals (Outside the main container) -->
    <?php foreach ($users as $user): ?>
    <!-- User Details Modal -->
    <div class="modal fade" id="userModal<?php echo $user['userid']; ?>" tabindex="-1" aria-labelledby="userModalLabel<?php echo $user['userid']; ?>" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-light">
                    <h5 class="modal-title" id="userModalLabel<?php echo $user['userid']; ?>">
                        <div class="d-flex align-items-center">
                            <div class="user-avatar me-3" style="width: 50px; height: 50px;">
                                <?php echo strtoupper(substr($user['username'], 0, 2)); ?>
                            </div>
                            <div>
                                <div class="fw-bold"><?php echo htmlspecialchars($user['username']); ?></div>
                                <small class="text-muted">User ID: <?php echo $user['userid']; ?></small>
                            </div>
                        </div>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card h-100">
                                <div class="card-header bg-primary text-white">
                                    <h6 class="mb-0"><i class="bi bi-person-circle"></i> Basic Information</h6>
                                </div>
                                <div class="card-body">
                                    <table class="table table-borderless table-sm">
                                        <tr>
                                            <td class="fw-semibold text-muted" width="35%">Username:</td>
                                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                                        </tr>
                                        <tr>
                                            <td class="fw-semibold text-muted">Email:</td>
                                            <td>
                                                <a href="mailto:<?php echo $user['email']; ?>" class="text-decoration-none">
                                                    <?php echo htmlspecialchars($user['email']); ?>
                                                </a>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td class="fw-semibold text-muted">Phone:</td>
                                            <td>
                                                <a href="tel:<?php echo $user['notel']; ?>" class="text-decoration-none">
                                                    <?php echo htmlspecialchars($user['notel']); ?>
                                                </a>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td class="fw-semibold text-muted">Gender:</td>
                                            <td>
                                                <i class="bi bi-gender-<?php echo strtolower($user['gender']); ?> me-1"></i>
                                                <?php echo $user['gender']; ?>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td class="fw-semibold text-muted">Role:</td>
                                            <td>
                                                <span class="badge bg-<?php echo $user['role'] == 'student' ? 'primary' : ($user['role'] == 'driver' ? 'success' : 'warning'); ?> px-2 py-1">
                                                    <i class="bi bi-<?php echo $user['role'] == 'student' ? 'mortarboard' : ($user['role'] == 'driver' ? 'car-front' : 'shield'); ?> me-1"></i>
                                                    <?php echo ucfirst($user['role']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td class="fw-semibold text-muted">Status:</td>
                                            <td>
                                                <span class="badge bg-<?php echo $user['status'] == 'active' ? 'success' : 'secondary'; ?> px-2 py-1">
                                                    <i class="bi bi-<?php echo $user['status'] == 'active' ? 'check-circle' : 'x-circle'; ?> me-1"></i>
                                                    <?php echo ucfirst($user['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td class="fw-semibold text-muted">Joined:</td>
                                            <td>
                                                <div><?php echo date('d M Y', strtotime($user['created'])); ?></div>
                                                <small class="text-muted"><?php echo date('h:i A', strtotime($user['created'])); ?></small>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card h-100">
                                <div class="card-header bg-success text-white">
                                    <h6 class="mb-0">
                                        <i class="bi bi-info-circle"></i> Role-Specific Details
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <?php if ($user['role_specific_info'] || $user['additional_info']): ?>
                                        <table class="table table-borderless table-sm">
                                            <?php if ($user['role'] == 'student'): ?>
                                                <tr>
                                                    <td class="fw-semibold text-muted" width="45%">Student Number:</td>
                                                    <td><?php echo htmlspecialchars($user['role_specific_info']); ?></td>
                                                </tr>
                                                <tr>
                                                    <td class="fw-semibold text-muted">Faculty:</td>
                                                    <td><?php echo htmlspecialchars($user['additional_info']); ?></td>
                                                </tr>
                                                <?php if ($user['extra_info']): ?>
                                                <tr>
                                                    <td class="fw-semibold text-muted">Year of Study:</td>
                                                    <td>
                                                        <span class="badge bg-info px-2 py-1">
                                                            Year <?php echo htmlspecialchars($user['extra_info']); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                                <?php endif; ?>
                                            <?php elseif ($user['role'] == 'driver'): ?>
                                                <tr>
                                                    <td class="fw-semibold text-muted">License Number:</td>
                                                    <td><?php echo htmlspecialchars($user['role_specific_info']); ?></td>
                                                </tr>
                                                <tr>
                                                    <td class="fw-semibold text-muted">Car Model:</td>
                                                    <td><?php echo htmlspecialchars($user['additional_info']); ?></td>
                                                </tr>
                                                <?php if ($user['extra_info']): ?>
                                                <tr>
                                                    <td class="fw-semibold text-muted">License Plate:</td>
                                                    <td>
                                                        <span class="badge bg-dark px-2 py-1">
                                                            <?php echo htmlspecialchars($user['extra_info']); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                                <?php endif; ?>
                                                <?php if ($user['driver_status']): ?>
                                                <tr>
                                                    <td class="fw-semibold text-muted">Driver Status:</td>
                                                    <td>
                                                        <span class="badge status-<?php echo str_replace(' ', '-', $user['driver_status']); ?> px-2 py-1">
                                                            <?php echo ucfirst($user['driver_status']); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                                <?php endif; ?>
                                            <?php elseif ($user['role'] == 'admin'): ?>
                                                <tr>
                                                    <td class="fw-semibold text-muted">Admin Level:</td>
                                                    <td>
                                                        <span class="badge bg-warning text-dark px-2 py-1">
                                                            <?php echo htmlspecialchars($user['role_specific_info']); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td class="fw-semibold text-muted">Department:</td>
                                                    <td><?php echo htmlspecialchars($user['additional_info']); ?></td>
                                                </tr>
                                            <?php endif; ?>
                                        </table>
                                    <?php else: ?>
                                        <div class="text-center py-4">
                                            <i class="bi bi-info-circle text-muted" style="font-size: 2rem;"></i>
                                            <p class="text-muted mt-2">No additional role-specific details available.</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-lg"></i> Close
                    </button>
                    <a href="mailto:<?php echo $user['email']; ?>" class="btn btn-primary">
                        <i class="bi bi-envelope"></i> Send Email
                    </a>
                    <?php if ($user['userid'] != $_SESSION['userid']): ?>
                        <div class="dropdown">
                            <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="bi bi-gear"></i> Actions
                            </button>
                            <ul class="dropdown-menu">
                                <li>
                                    <form method="POST" action="" class="d-inline w-100">
                                        <input type="hidden" name="userid" value="<?php echo $user['userid']; ?>">
                                        <input type="hidden" name="current_status" value="<?php echo $user['status']; ?>">
                                        <button type="submit" name="toggle_status" class="dropdown-item">
                                            <i class="bi bi-<?php echo $user['status'] == 'active' ? 'pause-circle text-warning' : 'play-circle text-success'; ?> me-2"></i>
                                            <?php echo $user['status'] == 'active' ? 'Deactivate User' : 'Activate User'; ?>
                                        </button>
                                    </form>
                                </li>
                                <?php if ($user['role'] != 'admin'): ?>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <form method="POST" action="" class="d-inline w-100">
                                        <input type="hidden" name="userid" value="<?php echo $user['userid']; ?>">
                                        <button type="submit" name="delete_user" class="dropdown-item text-danger"
                                                onclick="return confirm('Are you sure you want to delete this user? This action cannot be undone.')">
                                            <i class="bi bi-trash3 me-2"></i>
                                            Delete User
                                        </button>
                                    </form>
                                </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
                                    </tbody>
                                </table>
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
        // Real-time search
        let searchTimeout;
        const searchInput = document.getElementById('search');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    this.form.submit();
                }, 500);
            });
        }

        // Confirm dangerous actions
        document.querySelectorAll('button[name="delete_user"]').forEach(button => {
            button.addEventListener('click', function(e) {
                if (!confirm('Are you sure you want to delete this user? This action cannot be undone and will remove all associated data.')) {
                    e.preventDefault();
                }
            });
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