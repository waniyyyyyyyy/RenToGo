<?php
session_start();
require_once '../config/database.php';

// If user is already logged in, redirect
if (isset($_SESSION['userid'])) {
    switch ($_SESSION['role']) {
        case 'student':
            header("Location: ../student/dashboard.php");
            break;
        case 'driver':
            header("Location: ../driver/dashboard.php");
            break;
        case 'admin':
            header("Location: ../admin/dashboard.php");
            break;
    }
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $notel = trim($_POST['notel']);
    $gender = $_POST['gender'];
    $license_number = trim($_POST['license_number']);
    $car_model = trim($_POST['car_model']);
    $plate = trim($_POST['plate']);
    $capacity = $_POST['capacity'];
    $car_type = $_POST['car_type'];
    
    // Create unique username for driver: driver_license_number
    $username = 'driver_' . $license_number;
    
    // Validate inputs
    if (empty($full_name) || empty($email) || empty($password) || empty($notel) || empty($gender) || 
        empty($license_number) || empty($car_model) || empty($plate) || empty($capacity) || empty($car_type)) {
        $error = "Please fill in all required fields.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } else {
        $database = new Database();
        $db = $database->getConnection();
        
        // Check if license number already exists (drivers are identified by license number)
        $query = "SELECT d.licensenumber FROM driver d 
                  INNER JOIN user u ON d.userid = u.userid 
                  WHERE d.licensenumber = ?";
        $stmt = $db->prepare($query);
        $stmt->bindParam(1, $license_number);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $error = "A driver with this license number already exists.";
        } else {
            // Check if email already exists
            $query = "SELECT userid FROM user WHERE email = ?";
            $stmt = $db->prepare($query);
            $stmt->bindParam(1, $email);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $error = "This email address is already registered.";
            } else {
                // Check if license plate already exists
                $query = "SELECT plate FROM driver WHERE plate = ?";
                $stmt = $db->prepare($query);
                $stmt->bindParam(1, $plate);
                $stmt->execute();
                
                if ($stmt->rowCount() > 0) {
                    $error = "A vehicle with this license plate is already registered.";
                } else {
                    // Hash password
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $date_hired = date('Y-m-d');
                    
                    try {
                        $db->beginTransaction();
                        
                        // Insert into user table with driver username
                        $query = "INSERT INTO user (username, password, email, notel, gender, role, full_name) VALUES (?, ?, ?, ?, ?, 'driver', ?)";
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(1, $username);
                        $stmt->bindParam(2, $hashed_password);
                        $stmt->bindParam(3, $email);
                        $stmt->bindParam(4, $notel);
                        $stmt->bindParam(5, $gender);
                        $stmt->bindParam(6, $full_name);
                        $stmt->execute();
                        
                        $userid = $db->lastInsertId();
                        
                        // Insert into driver table
                        $query = "INSERT INTO driver (userid, licensenumber, carmodel, plate, capacity, datehired, car_type) VALUES (?, ?, ?, ?, ?, ?, ?)";
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(1, $userid);
                        $stmt->bindParam(2, $license_number);
                        $stmt->bindParam(3, $car_model);
                        $stmt->bindParam(4, $plate);
                        $stmt->bindParam(5, $capacity);
                        $stmt->bindParam(6, $date_hired);
                        $stmt->bindParam(7, $car_type);
                        $stmt->execute();
                        
                        $db->commit();
                        $success = "Driver registration successful! Your username is: " . $username . ". You can now login.";
                        
                    } catch (Exception $e) {
                        $db->rollback();
                        $error = "Registration failed. Please try again. If the problem persists, contact support.";
                    }
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driver Registration - RenToGo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .auth-container {
            min-height: 100vh;
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 0;
        }

        .auth-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.15);
            overflow: hidden;
            width: 100%;
            max-width: 700px;
            margin: 0 auto;
        }

        .auth-header {
            text-align: center;
            padding: 2rem;
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
        }

        .auth-body {
            padding: 2rem;
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
            border-radius: 10px;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }

        @media (max-height: 800px) {
            .auth-container {
                align-items: flex-start;
                padding: 1rem 0;
            }
        }

        @media (max-width: 768px) {
            .auth-container {
                padding: 1rem;
                align-items: flex-start;
            }
            
            .auth-header {
                padding: 1.5rem;
            }
            
            .auth-body {
                padding: 1.5rem;
            }
            
            .auth-card {
                max-width: 100%;
            }
        }

        .modal-content {
            border-radius: 20px;
        }

        .modal-header {
            border-radius: 20px 20px 0 0;
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-12">
                    <div class="auth-card">
                        <div class="auth-header">
                            <h2 class="mb-2">
                                <i class="bi bi-car-front-fill"></i> Driver Registration
                            </h2>
                            <p class="mb-0 opacity-75">Join as a driver in the RenToGo community</p>
                        </div>
                        
                        <div class="auth-body">
                            <form method="POST" action="" id="registerForm">
                                <!-- Personal Information -->
                                <h6 class="text-success mb-3">
                                    <i class="bi bi-person"></i> Personal Information
                                </h6>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="full_name" class="form-label fw-semibold">
                                            <i class="bi bi-person text-success"></i> Full Name *
                                        </label>
                                        <input type="text" class="form-control" id="full_name" name="full_name" 
                                               placeholder="Enter your full name as in IC/License" required>
                                        <small class="text-muted">Must match your driver's license</small>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="email" class="form-label fw-semibold">
                                            <i class="bi bi-envelope text-success"></i> Email *
                                        </label>
                                        <input type="email" class="form-control" id="email" name="email" 
                                               placeholder="your.email@example.com" required>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="notel" class="form-label fw-semibold">
                                            <i class="bi bi-phone text-success"></i> Phone Number *
                                        </label>
                                        <input type="tel" class="form-control" id="notel" name="notel" 
                                               placeholder="+60123456789" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="gender" class="form-label fw-semibold">
                                            <i class="bi bi-gender-ambiguous text-success"></i> Gender *
                                        </label>
                                        <select class="form-select" id="gender" name="gender" required>
                                            <option value="">Select Gender</option>
                                            <option value="Male">Male</option>
                                            <option value="Female">Female</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <!-- Account Security -->
                                <hr class="my-4">
                                <h6 class="text-success mb-3">
                                    <i class="bi bi-shield-lock"></i> Account Security
                                </h6>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="password" class="form-label fw-semibold">
                                            <i class="bi bi-lock text-success"></i> Password *
                                        </label>
                                        <input type="password" class="form-control" id="password" name="password" required>
                                        <small class="text-muted">Minimum 6 characters</small>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="confirm_password" class="form-label fw-semibold">
                                            <i class="bi bi-lock-fill text-success"></i> Confirm Password *
                                        </label>
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                    </div>
                                </div>
                                
                                <!-- Driver & Vehicle Information -->
                                <hr class="my-4">
                                <h6 class="text-success mb-3">
                                    <i class="bi bi-car-front"></i> Driver & Vehicle Information
                                </h6>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="license_number" class="form-label fw-semibold">
                                            <i class="bi bi-card-text text-success"></i> Driver's License Number *
                                        </label>
                                        <input type="text" class="form-control" id="license_number" name="license_number" 
                                               placeholder="e.g., D12345678" required>
                                        <small class="text-muted">This will be used to create your username</small>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="car_model" class="form-label fw-semibold">
                                            <i class="bi bi-car-front text-success"></i> Car Model *
                                        </label>
                                        <input type="text" class="form-control" id="car_model" name="car_model" 
                                               placeholder="e.g., Toyota Vios 2020" required>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="plate" class="form-label fw-semibold">
                                            <i class="bi bi-signpost text-success"></i> License Plate *
                                        </label>
                                        <input type="text" class="form-control" id="plate" name="plate" 
                                               placeholder="e.g., WXY1234A" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="capacity" class="form-label fw-semibold">
                                            <i class="bi bi-people text-success"></i> Passenger Capacity *
                                        </label>
                                        <select class="form-select" id="capacity" name="capacity" required>
                                            <option value="">Select Capacity</option>
                                            <option value="1">1 passenger</option>
                                            <option value="2">2 passengers</option>
                                            <option value="3">3 passengers</option>
                                            <option value="4">4 passengers</option>
                                            <option value="5">5 passengers</option>
                                            <option value="6">6 passengers</option>
                                            <option value="7">7 passengers</option>
                                            <option value="8">8 passengers</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="car_type" class="form-label fw-semibold">
                                            <i class="bi bi-truck text-success"></i> Vehicle Type *
                                        </label>
                                        <select class="form-select" id="car_type" name="car_type" required>
                                            <option value="">Select Vehicle Type</option>
                                            <option value="Sedan">Sedan</option>
                                            <option value="Hatchback">Hatchback</option>
                                            <option value="SUV">SUV</option>
                                            <option value="MPV">MPV</option>
                                            <option value="Pickup">Pickup Truck</option>
                                            <option value="Van">Van</option>
                                            <option value="Coupe">Coupe</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="alert alert-info mt-3">
                                    <i class="bi bi-info-circle"></i>
                                    <strong>Note:</strong> Your username will be automatically generated as "driver_[license_number]". 
                                    Please keep this information safe for login purposes.
                                </div>
                                
                                <div class="d-grid gap-2 mt-4">
                                    <button type="submit" class="btn btn-success btn-lg">
                                        <i class="bi bi-car-front"></i> Register as Driver
                                    </button>
                                </div>
                            </form>
                            
                            <hr class="my-4">
                            
                            <div class="text-center">
                                <p class="mb-3">Already have an account?</p>
                                <a href="login.php" class="btn btn-outline-success">
                                    <i class="bi bi-box-arrow-in-right"></i> Sign In
                                </a>
                            </div>
                            
                            <div class="text-center mt-3">
                                <p class="mb-2">Want to register as a student?</p>
                                <a href="student_register.php" class="btn btn-outline-primary">
                                    <i class="bi bi-mortarboard"></i> Student Registration
                                </a>
                            </div>
                            
                            <div class="text-center mt-3">
                                <a href="../index.php" class="text-muted text-decoration-none">
                                    <i class="bi bi-arrow-left"></i> Back to Home
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Registration Result Modal -->
    <div class="modal fade" id="registrationModal" tabindex="-1" aria-labelledby="registrationModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header text-white" id="modalHeader">
                    <h5 class="modal-title d-flex align-items-center" id="registrationModalLabel">
                        <i class="bi me-2" id="modalIcon"></i>
                        <span id="modalTitle">Registration Status</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center py-4">
                    <div id="modalMessage" class="fs-6"></div>
                </div>
                <div class="modal-footer justify-content-center border-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn" id="modalActionBtn" style="display: none;">
                        Go to Login
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // PHP variables passed to JavaScript
        const registrationResult = {
            error: <?php echo json_encode($error); ?>,
            success: <?php echo json_encode($success); ?>
        };

        // Function to show the modal with appropriate styling
        function showRegistrationModal(type, message) {
            const modal = new bootstrap.Modal(document.getElementById('registrationModal'));
            const modalHeader = document.getElementById('modalHeader');
            const modalIcon = document.getElementById('modalIcon');
            const modalTitle = document.getElementById('modalTitle');
            const modalMessage = document.getElementById('modalMessage');
            const modalActionBtn = document.getElementById('modalActionBtn');
            
            if (type === 'success') {
                modalHeader.className = 'modal-header text-white bg-success';
                modalIcon.className = 'bi bi-check-circle-fill me-2';
                modalTitle.textContent = 'Registration Successful!';
                modalMessage.innerHTML = `
                    <div class="text-success mb-3">
                        <i class="bi bi-check-circle" style="font-size: 3rem;"></i>
                    </div>
                    <p class="mb-0">${message}</p>
                `;
                modalActionBtn.className = 'btn btn-success';
                modalActionBtn.style.display = 'inline-block';
                modalActionBtn.onclick = function() {
                    window.location.href = 'login.php';
                };
            } else if (type === 'error') {
                modalHeader.className = 'modal-header text-white bg-danger';
                modalIcon.className = 'bi bi-exclamation-triangle-fill me-2';
                modalTitle.textContent = 'Registration Failed';
                modalMessage.innerHTML = `
                    <div class="text-danger mb-3">
                        <i class="bi bi-exclamation-triangle" style="font-size: 3rem;"></i>
                    </div>
                    <p class="mb-0">${message}</p>
                `;
                modalActionBtn.style.display = 'none';
            }
            
            modal.show();
        }

        // Form validation
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match!');
                return false;
            }
            
            if (password.length < 6) {
                e.preventDefault();
                alert('Password must be at least 6 characters long!');
                return false;
            }
        });

        // Real-time password confirmation check
        document.getElementById('confirm_password').addEventListener('input', function() {
            const password = document.getElementById('password').value;
            const confirmPassword = this.value;
            
            if (confirmPassword && password !== confirmPassword) {
                this.classList.add('is-invalid');
            } else {
                this.classList.remove('is-invalid');
            }
        });

        // Format license plate input
        document.getElementById('plate').addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });

        // Format license number input
        document.getElementById('license_number').addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });

        // Check for registration results when page loads and show modal
        document.addEventListener('DOMContentLoaded', function() {
            if (registrationResult.success) {
                showRegistrationModal('success', registrationResult.success);
            } else if (registrationResult.error) {
                showRegistrationModal('error', registrationResult.error);
            }
        });
    </script>
</body>
</html>