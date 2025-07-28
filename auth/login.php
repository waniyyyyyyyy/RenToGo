<?php
session_start();
require_once '../config/database.php';

// If user is already logged in, redirect based on role
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

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    if (!empty($username) && !empty($password)) {
        $database = new Database();
        $db = $database->getConnection();
        
        $query = "SELECT userid, username, password, role, status, full_name FROM user WHERE username = ? AND status = 'active'";
        $stmt = $db->prepare($query);
        $stmt->bindParam(1, $username);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Verify password
            if (password_verify($password, $row['password'])) {
                $_SESSION['userid'] = $row['userid'];
                $_SESSION['username'] = $row['username'];
                $_SESSION['role'] = $row['role'];
                $_SESSION['full_name'] = $row['full_name'];
                
                // Redirect based on role
                switch ($row['role']) {
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
            } else {
                $error = "Invalid username or password.";
            }
        } else {
            $error = "Invalid username or password.";
        }
    } else {
        $error = "Please fill in all fields.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - RenToGo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .auth-container {
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
            max-width: 500px;
            margin: 0 auto;
        }

        .auth-header {
            text-align: center;
            padding: 2rem;
            background: linear-gradient(135deg, #0d6efd 0%, #0056b3 100%);
            color: white;
        }

        .auth-body {
            padding: 2rem;
        }

        .form-control {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 0.75rem 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.1);
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

        @media (max-width: 768px) {
            .auth-container {
                padding: 1rem;
            }
            
            .auth-header, .auth-body {
                padding: 1.5rem;
            }
        }

        .login-help {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .input-group-text {
            border: 2px solid #e9ecef;
            border-left: none;
            border-radius: 0 10px 10px 0;
        }

        .form-control.with-icon {
            border-right: none;
            border-radius: 10px 0 0 10px;
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
                            <h3 class="mb-0">
                                <i class="bi bi-car-front-fill"></i> RenToGo
                            </h3>
                            <p class="mb-0 mt-2 opacity-75">Welcome back! Please sign in to your account.</p>
                        </div>
                        <div class="auth-body">
                            <!-- Login Help -->
                            <div class="login-help">
                                <h6 class="mb-2 text-primary"><i class="bi bi-info-circle"></i> Login Information</h6>
                                <small class="text-muted">
                                    <strong>Students:</strong> Use your student number (e.g., 2023123456)<br>
                                    <strong>Drivers:</strong> Use your driver username (driver_[license number])
                                </small>
                            </div>

                            <?php if ($error): ?>
                                <div class="alert alert-danger">
                                    <i class="bi bi-exclamation-circle"></i> <?php echo $error; ?>
                                </div>
                            <?php endif; ?>
                            
                            <form method="POST" action="">
                                <div class="mb-3">
                                    <label for="username" class="form-label fw-semibold">
                                        <i class="bi bi-person text-primary"></i> Username
                                    </label>
                                    <div class="input-group">
                                        <input type="text" class="form-control with-icon" id="username" name="username" 
                                               placeholder="Student number or driver username"
                                               value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" 
                                               required>
                                        <span class="input-group-text">
                                            <i class="bi bi-person-badge"></i>
                                        </span>
                                    </div>
                                    
                                </div>
                                
                                <div class="mb-3">
                                    <label for="password" class="form-label fw-semibold">
                                        <i class="bi bi-lock text-primary"></i> Password
                                    </label>
                                    <div class="input-group">
                                        <input type="password" class="form-control with-icon" id="password" name="password" required>
                                        <button class="input-group-text btn btn-outline-secondary" type="button" id="togglePassword">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        <i class="bi bi-box-arrow-in-right"></i> Sign In
                                    </button>
                                </div>
                            </form>
                            
                            <hr class="my-4">
                            
                            <div class="text-center">
                                <p class="mb-3">Don't have an account?</p>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <a href="student_register.php" class="btn btn-outline-primary w-100">
                                            <i class="bi bi-mortarboard"></i> Student
                                        </a>
                                    </div>
                                    <div class="col-6">
                                        <a href="driver_register.php" class="btn btn-outline-success w-100">
                                            <i class="bi bi-car-front"></i> Driver
                                        </a>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="text-center mt-4">
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

    <!-- Demo Credentials Modal -->
    <div class="modal fade" id="demoModal" tabindex="-1" aria-labelledby="demoModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
                <div class="modal-header bg-info text-white" style="border-radius: 20px 20px 0 0;">
                    <h5 class="modal-title" id="demoModalLabel">
                        <i class="bi bi-info-circle-fill"></i> Demo Credentials
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-12">
                            <h6 class="text-primary"><i class="bi bi-shield-check"></i> Admin Account</h6>
                            <div class="bg-light p-2 rounded">
                                <strong>Username:</strong> admin<br>
                                <strong>Password:</strong> password
                            </div>
                        </div>
                        <div class="col-12">
                            <h6 class="text-success"><i class="bi bi-car-front"></i> Driver Account</h6>
                            <div class="bg-light p-2 rounded">
                                <strong>Username:</strong> driver1<br>
                                <strong>Password:</strong> password
                            </div>
                        </div>
                        <div class="col-12">
                            <h6 class="text-info"><i class="bi bi-mortarboard"></i> Student Account</h6>
                            <div class="bg-light p-2 rounded">
                                <strong>Username:</strong> student1<br>
                                <strong>Password:</strong> password
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle password visibility
        document.getElementById('togglePassword').addEventListener('click', function() {
            const password = document.getElementById('password');
            const icon = this.querySelector('i');
            
            if (password.type === 'password') {
                password.type = 'text';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            } else {
                password.type = 'password';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        });

        // Auto-show demo credentials modal after 2 seconds
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(function() {
                const demoModal = new bootstrap.Modal(document.getElementById('demoModal'));
                demoModal.show();
            }, 2000);
        });

        // Add demo credentials button
        document.addEventListener('DOMContentLoaded', function() {
            const demoButton = document.createElement('button');
            demoButton.className = 'btn btn-outline-info btn-sm mt-2';
            demoButton.innerHTML = '<i class="bi bi-info-circle"></i> View Demo Credentials';
            demoButton.onclick = function() {
                const demoModal = new bootstrap.Modal(document.getElementById('demoModal'));
                demoModal.show();
            };
            
            const form = document.querySelector('form');
            form.appendChild(demoButton);
        });

        // Username input helper
        document.getElementById('username').addEventListener('input', function() {
            const value = this.value.toLowerCase();
            const helpText = this.parentElement.nextElementSibling;
            
            if (value.startsWith('driver_') || value.startsWith('d')) {
                helpText.innerHTML = 'Driver format: driver_[license number] (e.g., driver_D1234567)';
                helpText.className = 'text-success';
            } else if (/^\d/.test(value)) {
                helpText.innerHTML = 'Student format: Your student number (e.g., 2023123456)';
                helpText.className = 'text-info';
            } else {
                helpText.innerHTML = 'Examples: 2023123456 (student) or driver_D1234567 (driver)';
                helpText.className = 'text-muted';
            }
        });
    </script>
</body>
</html>