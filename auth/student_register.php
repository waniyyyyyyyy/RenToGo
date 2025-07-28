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
    $student_number = trim($_POST['student_number']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $notel = trim($_POST['notel']);
    $gender = $_POST['gender'];
    $faculty = $_POST['faculty'];
    $other_faculty = trim($_POST['other_faculty'] ?? '');
    $year_of_study = $_POST['year_of_study'];
    
    // Use student number as username for students
    $username = $student_number;
    
    // If faculty is "Others", use the other_faculty field
    if ($faculty === 'Others' && !empty($other_faculty)) {
        $faculty = $other_faculty;
    }
    
    // Validate inputs
    if (empty($full_name) || empty($student_number) || empty($email) || empty($password) || empty($notel) || empty($gender) || empty($faculty) || empty($year_of_study)) {
        $error = "Please fill in all required fields.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } elseif ($_POST['faculty'] === 'Others' && empty($other_faculty)) {
        $error = "Please specify your faculty in the 'Others' field.";
    } else {
        $database = new Database();
        $db = $database->getConnection();
        
        // Check if student number already exists (students are identified by student number)
        $query = "SELECT s.student_number FROM student s 
                  INNER JOIN user u ON s.userid = u.userid 
                  WHERE s.student_number = ?";
        $stmt = $db->prepare($query);
        $stmt->bindParam(1, $student_number);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $error = "A student with this student number already exists.";
        } else {
            // Check if email already exists
            $query = "SELECT userid FROM user WHERE email = ?";
            $stmt = $db->prepare($query);
            $stmt->bindParam(1, $email);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $error = "This email address is already registered.";
            } else {
                // Hash password
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                try {
                    $db->beginTransaction();
                    
                    // Insert into user table with student number as username
                    $query = "INSERT INTO user (username, password, email, notel, gender, role, full_name) VALUES (?, ?, ?, ?, ?, 'student', ?)";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(1, $username);
                    $stmt->bindParam(2, $hashed_password);
                    $stmt->bindParam(3, $email);
                    $stmt->bindParam(4, $notel);
                    $stmt->bindParam(5, $gender);
                    $stmt->bindParam(6, $full_name);
                    $stmt->execute();
                    
                    $userid = $db->lastInsertId();
                    
                    // Insert into student table
                    $query = "INSERT INTO student (userid, student_number, faculty, year_of_study) VALUES (?, ?, ?, ?)";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(1, $userid);
                    $stmt->bindParam(2, $student_number);
                    $stmt->bindParam(3, $faculty);
                    $stmt->bindParam(4, $year_of_study);
                    $stmt->execute();
                    
                    $db->commit();
                    $success = "Student registration successful! You can now login with your student number.";
                    
                } catch (Exception $e) {
                    $db->rollback();
                    $error = "Registration failed. Please try again. If the problem persists, contact support.";
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
    <title>Student Registration - RenToGo</title>
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
            max-width: 700px;
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

        .other-faculty-field {
            display: none;
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
                                <i class="bi bi-mortarboard-fill"></i> Student Registration
                            </h2>
                            <p class="mb-0 opacity-75">Create your student account for RenToGo</p>
                        </div>
                        
                        <div class="auth-body">
                            <form method="POST" action="" id="registerForm">
                                <!-- Personal Information -->
                                <h6 class="text-primary mb-3">
                                    <i class="bi bi-person"></i> Personal Information
                                </h6>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="full_name" class="form-label fw-semibold">
                                            <i class="bi bi-person text-primary"></i> Full Name *
                                        </label>
                                        <input type="text" class="form-control" id="full_name" name="full_name" 
                                               placeholder="Enter your full name as in IC/Passport" required>
                                        <small class="text-muted">Please enter your complete name</small>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="student_number" class="form-label fw-semibold">
                                            <i class="bi bi-card-text text-primary"></i> Student Number *
                                        </label>
                                        <input type="text" class="form-control" id="student_number" name="student_number" 
                                               placeholder="e.g., 2023123456" required>
                                        <small class="text-muted">This will be your username</small>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="email" class="form-label fw-semibold">
                                            <i class="bi bi-envelope text-primary"></i> Email *
                                        </label>
                                        <input type="email" class="form-control" id="email" name="email" 
                                               placeholder="studentID@student.uitm.edu.my" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="notel" class="form-label fw-semibold">
                                            <i class="bi bi-phone text-primary"></i> Phone Number *
                                        </label>
                                        <input type="tel" class="form-control" id="notel" name="notel" 
                                               placeholder="+60123456789" required>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="gender" class="form-label fw-semibold">
                                            <i class="bi bi-gender-ambiguous text-primary"></i> Gender *
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
                                <h6 class="text-primary mb-3">
                                    <i class="bi bi-shield-lock"></i> Account Security
                                </h6>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="password" class="form-label fw-semibold">
                                            <i class="bi bi-lock text-primary"></i> Password *
                                        </label>
                                        <input type="password" class="form-control" id="password" name="password" required>
                                        <small class="text-muted">Minimum 6 characters</small>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="confirm_password" class="form-label fw-semibold">
                                            <i class="bi bi-lock-fill text-primary"></i> Confirm Password *
                                        </label>
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                    </div>
                                </div>
                                
                                <!-- Academic Information -->
                                <hr class="my-4">
                                <h6 class="text-primary mb-3">
                                    <i class="bi bi-mortarboard"></i> Academic Information
                                </h6>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="faculty" class="form-label fw-semibold">Faculty *</label>
                                        <select class="form-select" id="faculty" name="faculty" required>
                                            <option value="">Select Faculty</option>
                                            <option value="Faculty of Computer and Mathematical Sciences">Faculty of Computer and Mathematical Sciences</option>
                                            <option value="Faculty of Business and Management">Faculty of Business and Management</option>
                                            <option value="Faculty of Engineering">Faculty of Engineering</option>
                                            <option value="Faculty of Applied Sciences">Faculty of Applied Sciences</option>
                                            <option value="Faculty of Education">Faculty of Education</option>
                                            <option value="Faculty of Medicine">Faculty of Medicine</option>
                                            <option value="Faculty of Law">Faculty of Law</option>
                                            <option value="Faculty of Arts and Social Sciences">Faculty of Arts and Social Sciences</option>
                                            <option value="Others">Others</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="year_of_study" class="form-label fw-semibold">Year of Study *</label>
                                        <select class="form-select" id="year_of_study" name="year_of_study" required>
                                            <option value="">Select Year</option>
                                            <option value="1">Year 1</option>
                                            <option value="2">Year 2</option>
                                            <option value="3">Year 3</option>
                                            <option value="4">Year 4</option>
                                            <option value="5">Year 5</option>
                                            <option value="6">Year 6</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <!-- Other Faculty Field (Hidden by default) -->
                                <div class="other-faculty-field mb-3">
                                    <label for="other_faculty" class="form-label fw-semibold">
                                        Please specify your faculty *
                                    </label>
                                    <input type="text" class="form-control" id="other_faculty" name="other_faculty" 
                                           placeholder="Enter your faculty name">
                                </div>
                                
                                <div class="d-grid gap-2 mt-4">
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        <i class="bi bi-person-plus"></i> Register as Student
                                    </button>
                                </div>
                            </form>
                            
                            <hr class="my-4">
                            
                            <div class="text-center">
                                <p class="mb-3">Already have an account?</p>
                                <a href="login.php" class="btn btn-outline-primary">
                                    <i class="bi bi-box-arrow-in-right"></i> Sign In
                                </a>
                            </div>
                            
                            <div class="text-center mt-3">
                                <p class="mb-2">Want to register as a driver?</p>
                                <a href="driver_register.php" class="btn btn-outline-success">
                                    <i class="bi bi-car-front"></i> Driver Registration
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

        // Faculty selection change handler
        document.getElementById('faculty').addEventListener('change', function() {
            const otherFacultyField = document.querySelector('.other-faculty-field');
            const otherFacultyInput = document.getElementById('other_faculty');
            
            if (this.value === 'Others') {
                otherFacultyField.style.display = 'block';
                otherFacultyInput.required = true;
            } else {
                otherFacultyField.style.display = 'none';
                otherFacultyInput.required = false;
                otherFacultyInput.value = '';
            }
        });

        // Form validation
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const faculty = document.getElementById('faculty').value;
            const otherFaculty = document.getElementById('other_faculty').value;
            
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
            
            if (faculty === 'Others' && !otherFaculty.trim()) {
                e.preventDefault();
                alert('Please specify your faculty in the "Others" field!');
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