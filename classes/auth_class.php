<?php
require_once 'database_config.php';

class Auth {
    private $conn;
    private $table_name = "user";
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    // Login user
    public function login($username, $password) {
        $query = "SELECT userid, username, password, role, status 
                  FROM " . $this->table_name . " 
                  WHERE username = ? AND status = 'active'";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $username);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (password_verify($password, $row['password'])) {
                return $row;
            }
        }
        return false;
    }
    
    // Register user
    public function register($userData) {
        try {
            $this->conn->beginTransaction();
            
            // Insert into user table
            $query = "INSERT INTO " . $this->table_name . " 
                      (username, password, email, notel, gender, role) 
                      VALUES (?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->conn->prepare($query);
            
            $hashed_password = password_hash($userData['password'], PASSWORD_DEFAULT);
            
            $stmt->bindParam(1, $userData['username']);
            $stmt->bindParam(2, $hashed_password);
            $stmt->bindParam(3, $userData['email']);
            $stmt->bindParam(4, $userData['notel']);
            $stmt->bindParam(5, $userData['gender']);
            $stmt->bindParam(6, $userData['role']);
            
            if ($stmt->execute()) {
                $userid = $this->conn->lastInsertId();
                
                // Insert role-specific data
                if ($userData['role'] == 'student') {
                    $this->registerStudent($userid, $userData);
                } elseif ($userData['role'] == 'driver') {
                    $this->registerDriver($userid, $userData);
                }
                
                $this->conn->commit();
                return $userid;
            }
            
            $this->conn->rollback();
            return false;
            
        } catch (Exception $e) {
            $this->conn->rollback();
            return false;
        }
    }
    
    // Register student
    private function registerStudent($userid, $userData) {
        $query = "INSERT INTO student (userid, student_number, faculty, year_of_study) 
                  VALUES (?, ?, ?, ?)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $userid);
        $stmt->bindParam(2, $userData['student_number']);
        $stmt->bindParam(3, $userData['faculty']);
        $stmt->bindParam(4, $userData['year_of_study']);
        
        return $stmt->execute();
    }
    
    // Register driver
    private function registerDriver($userid, $userData) {
        $query = "INSERT INTO driver (userid, licensenumber, carmodel, plate, capacity, datehired, price_per_hour, car_type) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $userid);
        $stmt->bindParam(2, $userData['license_number']);
        $stmt->bindParam(3, $userData['car_model']);
        $stmt->bindParam(4, $userData['plate']);
        $stmt->bindParam(5, $userData['capacity']);
        $stmt->bindParam(6, date('Y-m-d'));
        $stmt->bindParam(7, $userData['price_per_hour']);
        $stmt->bindParam(8, $userData['car_type']);
        
        return $stmt->execute();
    }
    
    // Check if username exists
    public function usernameExists($username, $excludeUserId = null) {
        $query = "SELECT userid FROM " . $this->table_name . " WHERE username = ?";
        
        if ($excludeUserId) {
            $query .= " AND userid != ?";
        }
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $username);
        
        if ($excludeUserId) {
            $stmt->bindParam(2, $excludeUserId);
        }
        
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }
    
    // Check if email exists
    public function emailExists($email, $excludeUserId = null) {
        $query = "SELECT userid FROM " . $this->table_name . " WHERE email = ?";
        
        if ($excludeUserId) {
            $query .= " AND userid != ?";
        }
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $email);
        
        if ($excludeUserId) {
            $stmt->bindParam(2, $excludeUserId);
        }
        
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }
    
    // Update password
    public function updatePassword($userid, $oldPassword, $newPassword) {
        // Verify old password
        $query = "SELECT password FROM " . $this->table_name . " WHERE userid = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $userid);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (password_verify($oldPassword, $row['password'])) {
                // Update to new password
                $query = "UPDATE " . $this->table_name . " SET password = ? WHERE userid = ?";
                $stmt = $this->conn->prepare($query);
                
                $hashed_password = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt->bindParam(1, $hashed_password);
                $stmt->bindParam(2, $userid);
                
                return $stmt->execute();
            }
        }
        return false;
    }
    
    // Update user profile
    public function updateProfile($userid, $userData) {
        $query = "UPDATE " . $this->table_name . " 
                  SET username = ?, email = ?, notel = ? 
                  WHERE userid = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $userData['username']);
        $stmt->bindParam(2, $userData['email']);
        $stmt->bindParam(3, $userData['notel']);
        $stmt->bindParam(4, $userid);
        
        return $stmt->execute();
    }
    
    // Get user by ID
    public function getUserById($userid) {
        $query = "SELECT * FROM " . $this->table_name . " WHERE userid = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $userid);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Toggle user status
    public function toggleUserStatus($userid) {
        $query = "UPDATE " . $this->table_name . " 
                  SET status = CASE 
                      WHEN status = 'active' THEN 'inactive' 
                      ELSE 'active' 
                  END 
                  WHERE userid = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $userid);
        
        return $stmt->execute();
    }
    
    // Delete user
    public function deleteUser($userid) {
        $query = "DELETE FROM " . $this->table_name . " WHERE userid = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $userid);
        
        return $stmt->execute();
    }
    
    // Start session
    public function startSession($userData) {
        session_start();
        $_SESSION['userid'] = $userData['userid'];
        $_SESSION['username'] = $userData['username'];
        $_SESSION['role'] = $userData['role'];
        return true;
    }
    
    // Destroy session
    public function logout() {
        session_start();
        session_unset();
        session_destroy();
        return true;
    }
    
    // Check if logged in
    public function isLoggedIn() {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        return isset($_SESSION['userid']);
    }
    
    // Get current user
    public function getCurrentUser() {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        if (isset($_SESSION['userid'])) {
            return [
                'userid' => $_SESSION['userid'],
                'username' => $_SESSION['username'],
                'role' => $_SESSION['role']
            ];
        }
        return false;
    }
    
    // Check user role
    public function hasRole($role) {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        return isset($_SESSION['role']) && $_SESSION['role'] === $role;
    }
    
    // Redirect based on role
    public function redirectByRole() {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        if (isset($_SESSION['role'])) {
            switch ($_SESSION['role']) {
                case 'student':
                    header("Location: student/dashboard.php");
                    break;
                case 'driver':
                    header("Location: driver/dashboard.php");
                    break;
                case 'admin':
                    header("Location: admin/dashboard.php");
                    break;
            }
            exit();
        }
    }
}
?>