<?php
require_once 'database_config.php';

class Booking {
    private $conn;
    private $table_name = "booking";
    
    // Booking properties
    public $bookingid;
    public $userid;
    public $driverid;
    public $pax;
    public $bookingdate;
    public $pickupdate;
    public $returndate;
    public $pickuplocation;
    public $returnlocation;
    public $totalcost;
    public $bookingstatus;
    public $paymentstatus;
    public $notes;
    public $duration_hours;
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    // Create booking
    public function create() {
        $query = "INSERT INTO " . $this->table_name . " 
                  (userid, driverid, pax, pickupdate, returndate, pickuplocation, returnlocation, totalcost, duration_hours, notes) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->conn->prepare($query);
        
        // Clean data
        $this->userid = htmlspecialchars(strip_tags($this->userid));
        $this->driverid = htmlspecialchars(strip_tags($this->driverid));
        $this->pax = htmlspecialchars(strip_tags($this->pax));
        $this->pickupdate = htmlspecialchars(strip_tags($this->pickupdate));
        $this->returndate = htmlspecialchars(strip_tags($this->returndate));
        $this->pickuplocation = htmlspecialchars(strip_tags($this->pickuplocation));
        $this->returnlocation = htmlspecialchars(strip_tags($this->returnlocation));
        $this->totalcost = htmlspecialchars(strip_tags($this->totalcost));
        $this->duration_hours = htmlspecialchars(strip_tags($this->duration_hours));
        $this->notes = htmlspecialchars(strip_tags($this->notes));
        
        // Bind data
        $stmt->bindParam(1, $this->userid);
        $stmt->bindParam(2, $this->driverid);
        $stmt->bindParam(3, $this->pax);
        $stmt->bindParam(4, $this->pickupdate);
        $stmt->bindParam(5, $this->returndate);
        $stmt->bindParam(6, $this->pickuplocation);
        $stmt->bindParam(7, $this->returnlocation);
        $stmt->bindParam(8, $this->totalcost);
        $stmt->bindParam(9, $this->duration_hours);
        $stmt->bindParam(10, $this->notes);
        
        if ($stmt->execute()) {
            $this->bookingid = $this->conn->lastInsertId();
            return true;
        }
        return false;
    }
    
    // Read all bookings
    public function read() {
        $query = "SELECT b.*, 
                         u_student.username as student_name, u_student.email as student_email, u_student.notel as student_phone,
                         s.student_number, s.faculty,
                         u_driver.username as driver_name, u_driver.email as driver_email, u_driver.notel as driver_phone,
                         d.carmodel, d.plate, d.car_type, d.capacity
                  FROM " . $this->table_name . " b
                  JOIN user u_student ON b.userid = u_student.userid
                  JOIN student s ON u_student.userid = s.userid
                  JOIN driver d ON b.driverid = d.driverid
                  JOIN user u_driver ON d.userid = u_driver.userid
                  ORDER BY b.bookingdate DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        
        return $stmt;
    }
    
    // Read single booking
    public function readOne() {
        $query = "SELECT b.*, 
                         u_student.username as student_name, u_student.email as student_email, u_student.notel as student_phone,
                         s.student_number, s.faculty,
                         u_driver.username as driver_name, u_driver.email as driver_email, u_driver.notel as driver_phone,
                         d.carmodel, d.plate, d.car_type, d.capacity
                  FROM " . $this->table_name . " b
                  JOIN user u_student ON b.userid = u_student.userid
                  JOIN student s ON u_student.userid = s.userid
                  JOIN driver d ON b.driverid = d.driverid
                  JOIN user u_driver ON d.userid = u_driver.userid
                  WHERE b.bookingid = ?
                  LIMIT 0,1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->bookingid);
        $stmt->execute();
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row) {
            $this->userid = $row['userid'];
            $this->driverid = $row['driverid'];
            $this->pax = $row['pax'];
            $this->bookingdate = $row['bookingdate'];
            $this->pickupdate = $row['pickupdate'];
            $this->returndate = $row['returndate'];
            $this->pickuplocation = $row['pickuplocation'];
            $this->returnlocation = $row['returnlocation'];
            $this->totalcost = $row['totalcost'];
            $this->bookingstatus = $row['bookingstatus'];
            $this->paymentstatus = $row['paymentstatus'];
            $this->notes = $row['notes'];
            $this->duration_hours = $row['duration_hours'];
            return $row;
        }
        return false;
    }
    
    // Get bookings by user
    public function getByUser($userid) {
        $query = "SELECT b.*, d.carmodel, d.plate, d.car_type, u.username as driver_name, u.notel as driver_phone,
                         r.rating as user_rating, r.review as user_review
                  FROM " . $this->table_name . " b
                  JOIN driver d ON b.driverid = d.driverid
                  JOIN user u ON d.userid = u.userid
                  LEFT JOIN rating r ON b.bookingid = r.bookingid AND r.userid = b.userid
                  WHERE b.userid = ?
                  ORDER BY b.bookingdate DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $userid);
        $stmt->execute();
        
        return $stmt;
    }
    
    // Get bookings by driver
    public function getByDriver($driverid) {
        $query = "SELECT b.*, u.username as student_name, u.notel as student_phone, u.email as student_email,
                         s.student_number, s.faculty, s.year_of_study
                  FROM " . $this->table_name . " b
                  JOIN user u ON b.userid = u.userid
                  JOIN student s ON u.userid = s.userid
                  WHERE b.driverid = ?
                  ORDER BY 
                    CASE 
                        WHEN b.bookingstatus = 'pending' THEN 1
                        WHEN b.bookingstatus = 'confirmed' THEN 2
                        WHEN b.bookingstatus = 'completed' THEN 3
                        WHEN b.bookingstatus = 'cancelled' THEN 4
                    END,
                    b.pickupdate DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $driverid);
        $stmt->execute();
        
        return $stmt;
    }
    
    // Update booking status
    public function updateStatus() {
        $query = "UPDATE " . $this->table_name . " 
                  SET bookingstatus = ?
                  WHERE bookingid = ?";
        
        $stmt = $this->conn->prepare($query);
        
        $this->bookingstatus = htmlspecialchars(strip_tags($this->bookingstatus));
        $this->bookingid = htmlspecialchars(strip_tags($this->bookingid));
        
        $stmt->bindParam(1, $this->bookingstatus);
        $stmt->bindParam(2, $this->bookingid);
        
        if ($stmt->execute()) {
            return true;
        }
        return false;
    }
    
    // Update payment status
    public function updatePaymentStatus() {
        $query = "UPDATE " . $this->table_name . " 
                  SET paymentstatus = ?
                  WHERE bookingid = ?";
        
        $stmt = $this->conn->prepare($query);
        
        $this->paymentstatus = htmlspecialchars(strip_tags($this->paymentstatus));
        $this->bookingid = htmlspecialchars(strip_tags($this->bookingid));
        
        $stmt->bindParam(1, $this->paymentstatus);
        $stmt->bindParam(2, $this->bookingid);
        
        if ($stmt->execute()) {
            return true;
        }
        return false;
    }
    
    // Cancel booking
    public function cancel() {
        $this->bookingstatus = 'cancelled';
        return $this->updateStatus();
    }
    
    // Confirm booking
    public function confirm() {
        $this->bookingstatus = 'confirmed';
        return $this->updateStatus();
    }
    
    // Complete booking
    public function complete() {
        $this->bookingstatus = 'completed';
        return $this->updateStatus();
    }
    
    // Delete booking
    public function delete() {
        // Delete related ratings first
        $query = "DELETE FROM rating WHERE bookingid = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->bookingid);
        $stmt->execute();
        
        // Delete booking
        $query = "DELETE FROM " . $this->table_name . " WHERE bookingid = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $this->bookingid);
        
        if ($stmt->execute()) {
            return true;
        }
        return false;
    }
    
    // Calculate cost
    public function calculateCost($pickup_datetime, $return_datetime, $price_per_hour) {
        $pickup = new DateTime($pickup_datetime);
        $return = new DateTime($return_datetime);
        $duration = $pickup->diff($return);
        
        $duration_hours = ($duration->days * 24) + $duration->h + ($duration->i / 60);
        $total_cost = $duration_hours * $price_per_hour;
        
        return [
            'duration_hours' => $duration_hours,
            'total_cost' => $total_cost
        ];
    }
    
    // Get booking statistics
    public function getStatistics($filters = []) {
        $query = "SELECT 
                    COUNT(*) as total_bookings,
                    COUNT(CASE WHEN bookingstatus = 'pending' THEN 1 END) as pending_bookings,
                    COUNT(CASE WHEN bookingstatus = 'confirmed' THEN 1 END) as confirmed_bookings,
                    COUNT(CASE WHEN bookingstatus = 'completed' THEN 1 END) as completed_bookings,
                    COUNT(CASE WHEN bookingstatus = 'cancelled' THEN 1 END) as cancelled_bookings,
                    SUM(CASE WHEN bookingstatus = 'completed' THEN totalcost END) as total_revenue,
                    AVG(CASE WHEN bookingstatus = 'completed' THEN totalcost END) as avg_booking_value,
                    COUNT(DISTINCT userid) as unique_students,
                    COUNT(DISTINCT driverid) as unique_drivers
                  FROM " . $this->table_name;
        
        $params = [];
        $conditions = [];
        
        if (isset($filters['date_from']) && $filters['date_from']) {
            $conditions[] = "DATE(bookingdate) >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (isset($filters['date_to']) && $filters['date_to']) {
            $conditions[] = "DATE(bookingdate) <= ?";
            $params[] = $filters['date_to'];
        }
        
        if (isset($filters['status']) && $filters['status']) {
            $conditions[] = "bookingstatus = ?";
            $params[] = $filters['status'];
        }
        
        if (isset($filters['userid']) && $filters['userid']) {
            $conditions[] = "userid = ?";
            $params[] = $filters['userid'];
        }
        
        if (isset($filters['driverid']) && $filters['driverid']) {
            $conditions[] = "driverid = ?";
            $params[] = $filters['driverid'];
        }
        
        if (!empty($conditions)) {
            $query .= " WHERE " . implode(" AND ", $conditions);
        }
        
        $stmt = $this->conn->prepare($query);
        
        foreach ($params as $index => $param) {
            $stmt->bindParam($index + 1, $param);
        }
        
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Search bookings
    public function search($filters = []) {
        $query = "SELECT b.*, 
                         u_student.username as student_name, u_student.email as student_email,
                         s.student_number, s.faculty,
                         u_driver.username as driver_name, u_driver.email as driver_email,
                         d.carmodel, d.plate, d.car_type
                  FROM " . $this->table_name . " b
                  JOIN user u_student ON b.userid = u_student.userid
                  JOIN student s ON u_student.userid = s.userid
                  JOIN driver d ON b.driverid = d.driverid
                  JOIN user u_driver ON d.userid = u_driver.userid
                  WHERE 1=1";
        
        $params = [];
        
        if (isset($filters['status']) && $filters['status']) {
            $query .= " AND b.bookingstatus = ?";
            $params[] = $filters['status'];
        }
        
        if (isset($filters['date']) && $filters['date']) {
            $query .= " AND DATE(b.pickupdate) = ?";
            $params[] = $filters['date'];
        }
        
        if (isset($filters['student']) && $filters['student']) {
            $query .= " AND u_student.username LIKE ?";
            $params[] = "%" . $filters['student'] . "%";
        }
        
        if (isset($filters['driver']) && $filters['driver']) {
            $query .= " AND u_driver.username LIKE ?";
            $params[] = "%" . $filters['driver'] . "%";
        }
        
        $query .= " ORDER BY b.bookingdate DESC";
        
        $stmt = $this->conn->prepare($query);
        
        foreach ($params as $index => $param) {
            $stmt->bindParam($index + 1, $param);
        }
        
        $stmt->execute();
        
        return $stmt;
    }
    
    // Get pending bookings for driver
    public function getPendingByDriver($driverid) {
        $query = "SELECT b.*, u.username as student_name, u.notel, s.student_number 
                  FROM " . $this->table_name . " b
                  JOIN user u ON b.userid = u.userid
                  JOIN student s ON u.userid = s.userid
                  WHERE b.driverid = ? AND b.bookingstatus = 'pending'
                  ORDER BY b.bookingdate DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $driverid);
        $stmt->execute();
        
        return $stmt;
    }
    
    // Check availability conflict
    public function checkAvailability($driverid, $pickup_datetime, $return_datetime, $exclude_booking = null) {
        $query = "SELECT COUNT(*) as conflicts 
                  FROM " . $this->table_name . " 
                  WHERE driverid = ? 
                    AND bookingstatus IN ('confirmed', 'pending')
                    AND (
                        (pickupdate <= ? AND returndate >= ?) OR
                        (pickupdate <= ? AND returndate >= ?) OR
                        (pickupdate >= ? AND returndate <= ?)
                    )";
        
        $params = [$driverid, $pickup_datetime, $pickup_datetime, $return_datetime, $return_datetime, $pickup_datetime, $return_datetime];
        
        if ($exclude_booking) {
            $query .= " AND bookingid != ?";
            $params[] = $exclude_booking;
        }
        
        $stmt = $this->conn->prepare($query);
        
        foreach ($params as $index => $param) {
            $stmt->bindParam($index + 1, $param);
        }
        
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['conflicts'] == 0;
    }
    
    // Get recent bookings
    public function getRecent($limit = 10) {
        $query = "SELECT b.*, 
                         u_student.username as student_name,
                         u_driver.username as driver_name,
                         d.carmodel, d.plate
                  FROM " . $this->table_name . " b
                  JOIN user u_student ON b.userid = u_student.userid
                  JOIN driver d ON b.driverid = d.driverid
                  JOIN user u_driver ON d.userid = u_driver.userid
                  ORDER BY b.bookingdate DESC
                  LIMIT ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt;
    }
}
?>