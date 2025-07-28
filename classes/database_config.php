<?php
/**
 * Database Configuration Class for RenToGo
 * 
 * This class handles database connection and configuration
 * for the object-oriented implementation of RenToGo system.
 * 
 * @author RenToGo Development Team
 * @version 1.0
 * @since 2025
 */

class DatabaseConfig {
    // Database credentials
    private $host = "localhost";
    private $db_name = "rentogo_db";
    private $username = "root";
    private $password = "";
    private $charset = "utf8mb4";
    
    // Connection instance
    public $conn;
    
    /**
     * Database connection method
     * 
     * @return PDO|null Returns PDO connection object or null on failure
     */
    public function getConnection() {
        $this->conn = null;
        
        try {
            // Build DSN (Data Source Name)
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=" . $this->charset;
            
            // PDO options for better security and performance
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_PERSISTENT         => true,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . $this->charset
            ];
            
            // Create PDO connection
            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
            
            // Set timezone
            $this->conn->exec("SET time_zone = '+08:00'");
            
        } catch(PDOException $exception) {
            // Log error for debugging (in production, log to file)
            error_log("Database Connection Error: " . $exception->getMessage());
            
            // For development, show error. In production, show generic message
            if (defined('DEBUG') && DEBUG === true) {
                echo "Connection error: " . $exception->getMessage();
            } else {
                echo "Database connection failed. Please try again later.";
            }
        }
        
        return $this->conn;
    }
    
    /**
     * Test database connection
     * 
     * @return bool Returns true if connection successful, false otherwise
     */
    public function testConnection() {
        $connection = $this->getConnection();
        return $connection !== null;
    }
    
    /**
     * Get database configuration details
     * 
     * @return array Returns array of connection details (without password)
     */
    public function getConfig() {
        return [
            'host' => $this->host,
            'database' => $this->db_name,
            'username' => $this->username,
            'charset' => $this->charset
        ];
    }
    
    /**
     * Set database credentials (useful for different environments)
     * 
     * @param string $host Database host
     * @param string $db_name Database name
     * @param string $username Database username
     * @param string $password Database password
     */
    public function setCredentials($host, $db_name, $username, $password) {
        $this->host = $host;
        $this->db_name = $db_name;
        $this->username = $username;
        $this->password = $password;
    }
    
    /**
     * Close database connection
     */
    public function closeConnection() {
        $this->conn = null;
    }
    
    /**
     * Begin database transaction
     * 
     * @return bool Returns true on success, false on failure
     */
    public function beginTransaction() {
        try {
            return $this->conn->beginTransaction();
        } catch (PDOException $e) {
            error_log("Transaction begin error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Commit database transaction
     * 
     * @return bool Returns true on success, false on failure
     */
    public function commit() {
        try {
            return $this->conn->commit();
        } catch (PDOException $e) {
            error_log("Transaction commit error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Rollback database transaction
     * 
     * @return bool Returns true on success, false on failure
     */
    public function rollback() {
        try {
            return $this->conn->rollback();
        } catch (PDOException $e) {
            error_log("Transaction rollback error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Execute raw SQL query (use with caution)
     * 
     * @param string $sql SQL query to execute
     * @return PDOStatement|false Returns PDOStatement on success, false on failure
     */
    public function query($sql) {
        try {
            return $this->conn->query($sql);
        } catch (PDOException $e) {
            error_log("Query execution error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Prepare SQL statement
     * 
     * @param string $sql SQL statement to prepare
     * @return PDOStatement|false Returns PDOStatement on success, false on failure
     */
    public function prepare($sql) {
        try {
            return $this->conn->prepare($sql);
        } catch (PDOException $e) {
            error_log("Statement preparation error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get last insert ID
     * 
     * @return string Returns the ID of the last inserted row
     */
    public function lastInsertId() {
        return $this->conn->lastInsertId();
    }
    
    /**
     * Get database server information
     * 
     * @return array Returns server information
     */
    public function getServerInfo() {
        try {
            return [
                'server_version' => $this->conn->getAttribute(PDO::ATTR_SERVER_VERSION),
                'connection_status' => $this->conn->getAttribute(PDO::ATTR_CONNECTION_STATUS),
                'server_info' => $this->conn->getAttribute(PDO::ATTR_SERVER_INFO)
            ];
        } catch (PDOException $e) {
            error_log("Server info error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Check if table exists
     * 
     * @param string $table_name Name of the table to check
     * @return bool Returns true if table exists, false otherwise
     */
    public function tableExists($table_name) {
        try {
            $stmt = $this->conn->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$table_name]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Table existence check error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get table columns information
     * 
     * @param string $table_name Name of the table
     * @return array Returns array of column information
     */
    public function getTableColumns($table_name) {
        try {
            $stmt = $this->conn->prepare("DESCRIBE " . $table_name);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Table columns error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Escape string for SQL queries (when not using prepared statements)
     * 
     * @param string $string String to escape
     * @return string Returns escaped string
     */
    public function escape($string) {
        return $this->conn->quote($string);
    }
}

// Example usage and initialization
if (basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    // This code runs only when file is accessed directly (for testing)
    
    echo "<h2>RentOGo Database Configuration Test</h2>";
    
    $database = new DatabaseConfig();
    
    if ($database->testConnection()) {
        echo "<p style='color: green;'>✅ Database connection successful!</p>";
        
        $config = $database->getConfig();
        echo "<h3>Configuration Details:</h3>";
        echo "<ul>";
        foreach ($config as $key => $value) {
            echo "<li><strong>" . ucfirst($key) . ":</strong> " . $value . "</li>";
        }
        echo "</ul>";
        
        $serverInfo = $database->getServerInfo();
        if (!empty($serverInfo)) {
            echo "<h3>Server Information:</h3>";
            echo "<ul>";
            foreach ($serverInfo as $key => $value) {
                echo "<li><strong>" . ucfirst(str_replace('_', ' ', $key)) . ":</strong> " . $value . "</li>";
            }
            echo "</ul>";
        }
        
        // Check if required tables exist
        $required_tables = ['user', 'student', 'driver', 'booking', 'rating', 'admin'];
        echo "<h3>Table Status:</h3>";
        echo "<ul>";
        foreach ($required_tables as $table) {
            $exists = $database->tableExists($table);
            $status = $exists ? "✅ Exists" : "❌ Missing";
            echo "<li><strong>" . ucfirst($table) . " table:</strong> " . $status . "</li>";
        }
        echo "</ul>";
        
    } else {
        echo "<p style='color: red;'>❌ Database connection failed!</p>";
        echo "<p>Please check your database configuration and ensure MySQL is running.</p>";
    }
    
    $database->closeConnection();
}
?>