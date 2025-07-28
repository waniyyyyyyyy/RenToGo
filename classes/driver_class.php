<?php
require_once 'database_config.php';

class Driver {
    private $conn;
    private $table_name = "driver";
    
    // Driver properties
    public $driverid;
    public $userid;
    public $licensenumber;
    public $carmodel;
    public $plate;
    public $capacity;
    public $status;
    public $rating;
    public $datehired;
    public $price_per_hour;
    public $car_type;
    
    // Constructor
    public function __construct($db) {
        $this->conn = $db;
    }
    
    // Create driver
    public function create() {
        $query = "INSERT INTO " . $this->table_name . " 
                  (userid, licensenumber, carmodel, plate, capacity, datehired, price_per_hour, car_type) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->conn->prepare($query);
        
        // Clean data
        $this->userid = htmlspecialchars(strip_tags($this->userid));
        $this->licensenumber = htmlspecialchars(strip_tags($this->licensenumber));
        $this->carmodel = htmlspecialchars(strip_tags($this->carmodel));
        $this->plate = htmlspecialchars(strip_tags($this->plate));
        $this->capacity = htmlspecialchars(strip_tags($this->capacity));
        $this->datehired = htmlspecialchars(strip_tags($this->datehired));
        $this->price_per_hour = htmlspecialchars(strip_tags($this->price_per_hour));
        $this->car_type = htmlspecialchars(strip_tags($this->car_type));
        
        // Bind data
        $stmt->bindParam(1, $this->userid);
        $stmt->bindParam(2, $this->licensenumber);
        $stmt->bindParam(3, $this->carmodel);
        $stmt->bindParam(4, $this->plate);
        $stmt->bindParam(5, $this->capacity);
        $stmt->bindParam(6, $this->datehired);
        $stmt->bindParam(7, $this->price_per_hour);
        $stmt->bindParam(8, $this->car_type);
        
        if ($stmt->execute()) {
            return true;
        }
        return false;
    }
    
    // Read all drivers
    public function read() {
        $query = "SELECT d.*, u.username, u.email, u.notel, u.gender, u.status as user_status 
                  FROM " . $this->table_name . " d
                  LEFT JOIN user u ON d.userid = u.userid
                  ORDER BY d.datehired DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        
        return $stmt;
    }
    
    // Read available drivers
    public function readAvailable() {
        $query = "SELECT d.*, u.username, u.email, u.notel 
                  FROM " . $this->table_name . " d
                  LEFT JOIN user u ON d.userid = u.userid
                  WHERE d.status = 'available' AND u.status = 'active'
                  ORDER BY d.rating DESC, d.price_per_hour ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        
        return $stmt;
    }
    
    // Read single driver
    public function readOne() {
        $query = "SELECT d.*, u.username, u.email, u.notel, u.gender 
                  FROM " . $this->table_name . " d
                  LEFT JOIN user u ON d.userid = u.userid
                  WHERE d.driverid = ?
                  LIMIT 0,1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->driverid);
        $stmt->execute();
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row) {
            $this->userid = $row['userid'];
            $this->licensenumber = $row['licensenumber'];
            $this->carmodel = $row['carmodel'];
            $this->plate = $row['plate'];
            $this->capacity = $row['capacity'];
            $this->status = $row['status'];
            $this->rating = $row['rating'];
            $this->datehired = $row['datehired'];
            $this->price_per_hour = $row['price_per_hour'];
            $this->car_type = $row['car_type'];
            return true;
        }
        return false;
    }
    
    // Read driver by user ID
    public function readByUserId() {
        $query = "SELECT d.*, u.username, u.email, u.notel, u.gender 
                  FROM " . $this->table_name . " d
                  LEFT JOIN user u ON d.userid = u.userid
                  WHERE d.userid = ?
                  LIMIT 0,1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->userid);
        $stmt->execute();
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row) {
            $this->driverid = $row['driverid'];
            $this->licensenumber = $row['licensenumber'];
            $this->carmodel = $row['carmodel'];
            $this->plate = $row['plate'];
            $this->capacity = $row['capacity'];
            $this->status = $row['status'];
            $this->rating = $row['rating'];
            $this->datehired = $row['datehired'];
            $this->price_per_hour = $row['price_per_hour'];
            $this->car_type = $row['car_type'];
            return true;
        }
        return false;
    }
    
    // Update driver
    public function update() {
        $query = "UPDATE " . $this->table_name . " 
                  SET licensenumber = ?, carmodel = ?, plate = ?, capacity = ?, 
                      price_per_hour = ?, car_type = ?
                  WHERE driverid = ?";
        
        $stmt = $this->conn->prepare($query);
        
        // Clean data
        $this->licensenumber = htmlspecialchars(strip_tags($this->licensenumber));
        $this->carmodel = htmlspecialchars(strip_tags($this->carmodel));
        $this->plate = htmlspecialchars(strip_tags($this->plate));
        $this->capacity = htmlspecialchars(strip_tags($this->capacity));
        $this->price_per_hour = htmlspecialchars(strip_tags($this->price_per_hour));
        $this->car_type = htmlspecialchars(strip_tags($this->car_type));
        $this->driverid = htmlspecialchars(strip_tags($this->driverid));
        
        // Bind data
        $stmt->bindParam(1, $this->licensenumber);
        $stmt->bindParam(2, $this->carmodel);
        $stmt->bindParam(3, $this->plate);
        $stmt->bindParam(4, $this->capacity);
        $stmt->bindParam(5, $this->price_per_hour);
        $stmt->bindParam(6, $this->car_type);
        $stmt->bindParam(7, $this->driverid);
        
        if ($stmt->execute()) {
            return true;
        }
        return false;
    }
    
    // Update status
    public function updateStatus() {
        $query = "UPDATE " . $this->table_name . " 
                  SET status = ?
                  WHERE driverid = ?";
        
        $stmt = $this->conn->prepare($query);
        
        // Clean data
        $this->status = htmlspecialchars(strip_tags($this->status));
        $this->driverid = htmlspecialchars(strip_tags($this->driverid));
        
        // Bind data
        $stmt->bindParam(1, $this->status);
        $stmt->bindParam(2, $this->driverid);
        
        if ($stmt->execute()) {
            return true;
        }
        return false;
    }
    
    // Update rating
    public function updateRating() {
        $query = "UPDATE " . $this->table_name . " 
                  SET rating = (
                      SELECT AVG(rating) 
                      FROM rating 
                      WHERE driverid = ?
                  )
                  WHERE driverid = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->driverid);
        $stmt->bindParam(2, $this->driverid);
        
        if ($stmt->execute()) {
            return true;
        }
        return false;
    }
    
    // Delete driver
    public function delete() {
        $query = "DELETE FROM " . $this->table_name . " WHERE driverid = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->driverid);
        
        if ($stmt->execute()) {
            return true;
        }
        return false;
    }
    
    // Search drivers
    public function search($keywords) {
        $query = "SELECT d.*, u.username, u.email, u.notel 
                  FROM " . $this->table_name . " d
                  LEFT JOIN user u ON d.userid = u.userid
                  WHERE u.username LIKE ? OR d.carmodel LIKE ? OR d.plate LIKE ?
                  ORDER BY d.rating DESC";
        
        $stmt = $this->conn->prepare($query);
        
        $keywords = htmlspecialchars(strip_tags($keywords));
        $keywords = "%{$keywords}%";
        
        $stmt->bindParam(1, $keywords);
        $stmt->bindParam(2, $keywords);
        $stmt->bindParam(3, $keywords);
        
        $stmt->execute();
        
        return $stmt;
    }
    
    // Get driver statistics
    public function getStatistics() {
        $query = "SELECT 
                    COUNT(b.bookingid) as total_bookings,
                    COUNT(CASE WHEN b.bookingstatus = 'completed' THEN 1 END) as completed_bookings,
                    COUNT(CASE WHEN b.bookingstatus = 'pending' THEN 1 END) as pending_bookings,
                    SUM(CASE WHEN b.bookingstatus = 'completed' THEN b.totalcost END) as total_earnings,
                    AVG(CASE WHEN b.bookingstatus = 'completed' THEN b.totalcost END) as avg_booking_value,
                    MAX(b.bookingdate) as last_booking
                  FROM booking b 
                  WHERE b.driverid = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->driverid);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Get driver bookings
    public function getBookings($status = null) {
        $query = "SELECT b.*, u.username as student_name, u.notel as student_phone, 
                         s.student_number, s.faculty
                  FROM booking b
                  JOIN user u ON b.userid = u.userid
                  JOIN student s ON u.userid = s.userid
                  WHERE b.driverid = ?";
        
        if ($status) {
            $query .= " AND b.bookingstatus = ?";
        }
        
        $query .= " ORDER BY b.bookingdate DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->driverid);
        
        if ($status) {
            $stmt->bindParam(2, $status);
        }
        
        $stmt->execute();
        
        return $stmt;
    }
    
    // Get driver ratings
    public function getRatings($limit = null) {
        $query = "SELECT r.*, u.username as student_name 
                  FROM rating r
                  JOIN booking b ON r.bookingid = b.bookingid
                  JOIN user u ON r.userid = u.userid
                  WHERE r.driverid = ?
                  ORDER BY r.created_at DESC";
        
        if ($limit) {
            $query .= " LIMIT " . intval($limit);
        }
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->driverid);
        $stmt->execute();
        
        return $stmt;
    }
    
    // Check if plate exists
    public function plateExists() {
        $query = "SELECT driverid FROM " . $this->table_name . " 
                  WHERE plate = ? AND driverid != ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->plate);
        $stmt->bindParam(2, $this->driverid);
        $stmt->execute();
        
        return $stmt->rowCount() > 0;
    }
    
    // Check if license exists
    public function licenseExists() {
        $query = "SELECT driverid FROM " . $this->table_name . " 
                  WHERE licensenumber = ? AND driverid != ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->licensenumber);
        $stmt->bindParam(2, $this->driverid);
        $stmt->execute();
        
        return $stmt->rowCount() > 0;
    }
    
    // Filter drivers
    public function filter($filters = []) {
        $query = "SELECT d.*, u.username, u.email, u.notel,
                         COALESCE(AVG(r.rating), 0) as avg_rating,
                         COUNT(r.rating) as rating_count,
                         COUNT(b.bookingid) as total_bookings
                  FROM " . $this->table_name . " d 
                  LEFT JOIN user u ON d.userid = u.userid 
                  LEFT JOIN rating r ON d.driverid = r.driverid
                  LEFT JOIN booking b ON d.driverid = b.driverid AND b.bookingstatus = 'completed'
                  WHERE u.status = 'active'";
        
        $params = [];
        
        if (isset($filters['capacity']) && $filters['capacity']) {
            $query .= " AND d.capacity >= ?";
            $params[] = $filters['capacity'];
        }
        
        if (isset($filters['car_type']) && $filters['car_type']) {
            $query .= " AND d.car_type = ?";
            $params[] = $filters['car_type'];
        }
        
        if (isset($filters['status']) && $filters['status']) {
            $query .= " AND d.status = ?";
            $params[] = $filters['status'];
        }
        
        if (isset($filters['min_rating']) && $filters['min_rating']) {
            $query .= " AND d.rating >= ?";
            $params[] = $filters['min_rating'];
        }
        
        if (isset($filters['max_price']) && $filters['max_price']) {
            $query .= " AND d.price_per_hour <= ?";
            $params[] = $filters['max_price'];
        }
        
        $query .= " GROUP BY d.driverid";
        
        // Add sorting
        if (isset($filters['sort_by'])) {
            switch ($filters['sort_by']) {
                case 'price_low':
                    $query .= " ORDER BY d.price_per_hour ASC";
                    break;
                case 'price_high':
                    $query .= " ORDER BY d.price_per_hour DESC";
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
        } else {
            $query .= " ORDER BY d.rating DESC, avg_rating DESC";
        }
        
        $stmt = $this->conn->prepare($query);
        
        foreach ($params as $index => $param) {
            $stmt->bindParam($index + 1, $param);
        }
        
        $stmt->execute();
        
        return $stmt;
    }
}
?>