<?php
session_start();
require_once '../config/database.php';

// Check if TCPDF is available
$tcpdf_available = false;
if (file_exists('../vendor/autoload.php')) {
    require_once '../vendor/autoload.php';
    if (class_exists('TCPDF')) {
        $tcpdf_available = true;
    }
}

// Check if user is logged in and is a student
if (!isset($_SESSION['userid']) || $_SESSION['role'] !== 'student') {
    header("Location: ../auth/login.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();
$message = '';
$error = '';

// Handle booking submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['book_ride'])) {
    $driverid = $_POST['driverid'];
    $pickupdate = $_POST['pickupdate'];
    $pickuplocation = trim($_POST['pickuplocation']);
    $dropofflocation = trim($_POST['dropofflocation']);
    $pax = (int)$_POST['pax'];
    $distance = (float)$_POST['distance'];
    $notes = trim($_POST['notes']);
    
    // Validate input
    if ($distance <= 0) {
        $error = "Please enter a valid distance.";
    } else {
        // Estimate duration based on distance (assuming average speed of 60 km/h)
        $estimated_travel_time = ($distance / 60) * 2; // Round trip time in hours
        $duration_hours = max($estimated_travel_time, 1); // Minimum 1 hour
        
        // Get driver's details and pricing
        $query = "SELECT d.*, u.username, u.notel, u.email,
                         COALESCE(p.base_fare, 5.00) as base_fare,
                         COALESCE(p.price_per_km, 1.50) as price_per_km, 
                         COALESCE(p.minimum_fare, 8.00) as minimum_fare
                  FROM driver d 
                  JOIN user u ON d.userid = u.userid 
                  LEFT JOIN pricing_settings p ON d.car_type = p.car_type
                  WHERE d.driverid = ?";
        $stmt = $db->prepare($query);
        $stmt->bindParam(1, $driverid);
        $stmt->execute();
        $driver = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get student details
        $query_student = "SELECT u.username as student_name, u.email as student_email, u.notel as student_phone
                         FROM user u WHERE u.userid = ?";
        $stmt_student = $db->prepare($query_student);
        $stmt_student->bindParam(1, $_SESSION['userid']);
        $stmt_student->execute();
        $student = $stmt_student->fetch(PDO::FETCH_ASSOC);
        
        if ($driver && $pax <= $driver['capacity']) {
            // Calculate total cost using distance-based pricing
            $calculated_cost = $driver['base_fare'] + ($distance * $driver['price_per_km']);
            $totalcost = max($calculated_cost, $driver['minimum_fare']);
            
            // Insert booking
            try {
                $query = "INSERT INTO booking (userid, driverid, pax, pickupdate, pickuplocation, dropofflocation, totalcost, duration_hours, distance_km, notes, bookingstatus) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
                $stmt = $db->prepare($query);
                $stmt->bindParam(1, $_SESSION['userid']);
                $stmt->bindParam(2, $driverid);
                $stmt->bindParam(3, $pax);
                $stmt->bindParam(4, $pickupdate);
                $stmt->bindParam(5, $pickuplocation);
                $stmt->bindParam(6, $dropofflocation);
                $stmt->bindParam(7, $totalcost);
                $stmt->bindParam(8, $duration_hours);
                $stmt->bindParam(9, $distance);
                $stmt->bindParam(10, $notes);
                
                if ($stmt->execute()) {
                    $booking_id = $db->lastInsertId();
                    
                    // Format dates for display
                    $pickup_formatted = date('d M Y, g:i A', strtotime($pickupdate));
                    
                    // Generate Report (HTML or PDF based on availability)
                    $report_content = generateBookingReport($booking_id, $student, $driver, $pickupdate, $pickuplocation, $dropofflocation, $pax, $distance, $duration_hours, $notes, $totalcost, $calculated_cost);
                    
                    if ($tcpdf_available) {
                        // Generate PDF using TCPDF
                        try {
                            $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
                            
                            // Set document information
                            $pdf->SetCreator('RenToGo System');
                            $pdf->SetAuthor('RenToGo');
                            $pdf->SetTitle('Booking Confirmation Report');
                            $pdf->SetSubject('Ride Booking Confirmation');
                            
                            // Set default header data
                            $pdf->SetHeaderData('', 0, 'RenToGo - Booking Confirmation', 'Booking ID: #' . str_pad($booking_id, 6, '0', STR_PAD_LEFT));
                            
                            // Set header and footer fonts
                            $pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
                            $pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));
                            
                            // Set default monospaced font
                            $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
                            
                            // Set margins
                            $pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
                            $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
                            $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
                            
                            // Set auto page breaks
                            $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
                            
                            // Add a page
                            $pdf->AddPage();
                            
                            // Set font
                            $pdf->SetFont('helvetica', '', 12);
                            
                            // Output the HTML content
                            $pdf->writeHTML($report_content, true, false, true, false, '');
                            
                            // Close and output PDF document
                            $pdf_filename = 'booking_confirmation_' . $booking_id . '.pdf';
                            
                            // Create reports directory if it doesn't exist (use absolute path)
                            $reports_dir = __DIR__ . '/../reports/';
                            if (!file_exists($reports_dir)) {
                                mkdir($reports_dir, 0777, true);
                            }
                            
                            $pdf_path = $reports_dir . $pdf_filename;
                            
                            // Save PDF to file
                            $pdf->Output($pdf_path, 'F');
                            
                            // Set success message with download link
                            $message = "Booking request submitted successfully! Total cost: RM " . number_format($totalcost, 2) . ". 
                                      <br><a href='../reports/{$pdf_filename}' class='btn btn-success mt-2' target='_blank'>
                                      <i class='bi bi-download'></i> Download Booking Confirmation PDF</a>";
                            
                        } catch (Exception $e) {
                            // Fallback to HTML report
                            $html_filename = 'booking_confirmation_' . $booking_id . '.html';
                            
                            // Create reports directory if it doesn't exist (use absolute path)
                            $reports_dir = __DIR__ . '/../reports/';
                            if (!file_exists($reports_dir)) {
                                mkdir($reports_dir, 0777, true);
                            }
                            
                            $html_path = $reports_dir . $html_filename;
                            
                            // Save HTML report
                            file_put_contents($html_path, $report_content);
                            
                            $message = "Booking request submitted successfully! Total cost: RM " . number_format($totalcost, 2) . ". 
                                      <br><a href='../reports/{$html_filename}' class='btn btn-success mt-2' target='_blank'>
                                      <i class='bi bi-file-text'></i> View Booking Confirmation Report</a>
                                      <br><small class='text-muted'>PDF generation failed: " . $e->getMessage() . "</small>";
                        }
                    } else {
                        // Generate HTML report only
                        $html_filename = 'booking_confirmation_' . $booking_id . '.html';
                        
                        // Create reports directory if it doesn't exist (use absolute path)
                        $reports_dir = __DIR__ . '/../reports/';
                        if (!file_exists($reports_dir)) {
                            mkdir($reports_dir, 0777, true);
                        }
                        
                        $html_path = $reports_dir . $html_filename;
                        
                        // Save HTML report
                        file_put_contents($html_path, $report_content);
                        
                        $message = "Booking request submitted successfully! Total cost: RM " . number_format($totalcost, 2) . ". 
                                  <br><a href='../reports/{$html_filename}' class='btn btn-success mt-2' target='_blank'>
                                  <i class='bi bi-file-text'></i> View Booking Confirmation Report</a>
                                  <br><small class='text-muted'>To get PDF reports, please install TCPDF library.</small>";
                    }
                } else {
                    $error = "Failed to submit booking. Please try again.";
                }
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        } else {
            $error = "Invalid driver or passenger count exceeds capacity.";
        }
    }
}

// Get available drivers with pricing information
$search_date = isset($_GET['search_date']) ? $_GET['search_date'] : date('Y-m-d');
$search_capacity = isset($_GET['search_capacity']) ? (int)$_GET['search_capacity'] : '';
$search_type = isset($_GET['search_type']) ? $_GET['search_type'] : '';

$query = "SELECT d.*, u.username, u.notel,
                 COALESCE(p.base_fare, 5.00) as base_fare,
                 COALESCE(p.price_per_km, 1.50) as price_per_km, 
                 COALESCE(p.minimum_fare, 8.00) as minimum_fare
          FROM driver d 
          JOIN user u ON d.userid = u.userid 
          LEFT JOIN pricing_settings p ON d.car_type = p.car_type
          WHERE d.status = 'available' AND u.status = 'active'";
$params = [];

if ($search_capacity) {
    $query .= " AND d.capacity >= ?";
    $params[] = $search_capacity;
}

if ($search_type) {
    $query .= " AND d.car_type = ?";
    $params[] = $search_type;
}

$query .= " ORDER BY d.rating DESC, p.base_fare ASC";

$stmt = $db->prepare($query);
foreach ($params as $index => $param) {
    $stmt->bindParam($index + 1, $param);
}
$stmt->execute();
$drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique car types for filter
$query = "SELECT DISTINCT car_type FROM driver WHERE status = 'available' ORDER BY car_type";
$stmt = $db->prepare($query);
$stmt->execute();
$car_types = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Function to generate booking report HTML
function generateBookingReport($booking_id, $student, $driver, $pickupdate, $pickuplocation, $dropofflocation, $pax, $distance, $duration_hours, $notes, $totalcost, $calculated_cost) {
    $pickup_formatted = date('d M Y, g:i A', strtotime($pickupdate));
    
    $html = '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Booking Confirmation - RenToGo</title>
        <style>
            @media print {
                .no-print { display: none !important; }
                body { margin: 0; font-size: 9px; }
            }
            
            body {
                font-family: Arial, sans-serif;
                line-height: 1.4;
                color: #333;
                max-width: 800px;
                margin: 0 auto;
                padding: 15px;
                background-color: #f9f9f9;
                font-size: 10px;
            }
            
            .container {
                background: white;
                padding: 15px;
                border-radius: 5px;
                box-shadow: 0 0 20px rgba(0,0,0,0.1);
            }
            
            .header {
                background: linear-gradient(135deg, #6f42c1, #8b5cf6);
                color: white;
                padding: 0px;
                text-align: center;
                font-size: 12px;
                font-weight: bold;
                margin: -15px -15px 15px -15px;
                border-radius: 5px 5px 0 0;
            }
            
            .section {
                margin: 8px 0;
                border-bottom: 1px solid #eee;
                padding-bottom: 8px;
            }
            
            .section:last-child {
                border-bottom: none;
            }
            
            .section h2 {
                color: #6f42c1;
                border-bottom: 2px solid #6f42c1;
                padding-bottom: 2px;
                margin-bottom: 6px;
                font-size: 12px;
            }
            
            .info-table {
                width: 100%;
                border-collapse: collapse;
                margin: 6px 0;
                font-size: 9px;
            }
            
            .info-table td {
                padding: 3px 0;
                border-bottom: 1px solid #f0f0f0;
            }
            
            .info-table .label {
                font-weight: bold;
                color: #333;
                width: 35%;
                vertical-align: top;
            }
            
            .info-table .value {
                color: #666;
                padding-left: 15px;
            }
            
            .cost-table {
                width: 100%;
                border-collapse: collapse;
                margin: 8px 0;
                border: 1px solid #ddd;
                font-size: 9px;
            }
            
            .cost-table th,
            .cost-table td {
                border: 1px solid #ddd;
                padding: 6px;
                text-align: left;
            }
            
            .cost-table th {
                background-color: #f8f9fa;
                font-weight: bold;
                color: #333;
                font-size: 10px;
            }
            
            .cost-table .total-row {
                background-color: #6f42c1;
                color: white;
                font-weight: bold;
                font-size: 10px;
            }
            
            .cost-table .total-row td {
                border-color: #6f42c1;
            }
            
            .status-pending {
                color: #ffc107;
                font-weight: bold;
                font-size: 12px;
            }
            
            .footer-note {
                font-size: 7px;
                color: #666;
                margin-top: 15px;
                border-top: 2px solid #6f42c1;
                padding-top: 10px;
                background-color: #f8f9fa;
                padding: 10px;
                border-radius: 5px;
            }
            
            .footer-note ul {
                margin: 5px 0;
                padding-left: 15px;
            }
            
            .footer-note li {
                margin: 2px 0;
            }
            
            .booking-id {
                font-size: 18px;
                font-weight: bold;
                color: #6f42c1;
                text-align: center;
                margin: 10px 0;
            }
            
            .company-info {
                text-align: center;
                margin-bottom: 10px;
                color: #666;
                font-size: 9px;
            }
            
            /* Two column layout for space efficiency */
            .two-columns {
                display: table;
                width: 100%;
            }
            
            .column {
                display: table-cell;
                width: 50%;
                vertical-align: top;
                padding-right: 10px;
            }
            
            .column:last-child {
                padding-right: 0;
                padding-left: 10px;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                RENTOGO - BOOKING CONFIRMATION
            </div>
            
            <div class="company-info">
                <strong>RenToGo Ride Booking System</strong><br>
                Your Trusted Campus Transportation Partner
            </div>
            
            <div class="booking-id">
                Booking ID: #' . str_pad($booking_id, 6, '0', STR_PAD_LEFT) . '
            </div>
            
            <div class="two-columns">
                <div class="column">
                    <div class="section">
                        <h2>Booking Details</h2>
                        <table class="info-table">
                            <tr>
                                <td class="label">Booking Date:</td>
                                <td class="value">' . date('d M Y, g:i A') . '</td>
                            </tr>
                            <tr>
                                <td class="label">Student Name:</td>
                                <td class="value">' . htmlspecialchars($student['student_name']) . '</td>
                            </tr>
                            <tr>
                                <td class="label">Student Phone:</td>
                                <td class="value">' . htmlspecialchars($student['student_phone']) . '</td>
                            </tr>
                            <tr>
                                <td class="label">Driver Name:</td>
                                <td class="value">' . htmlspecialchars($driver['username']) . '</td>
                            </tr>
                            <tr>
                                <td class="label">Driver Phone:</td>
                                <td class="value">' . htmlspecialchars($driver['notel']) . '</td>
                            </tr>
                            <tr>
                                <td class="label">Vehicle:</td>
                                <td class="value">' . htmlspecialchars($driver['carmodel']) . ' (' . htmlspecialchars($driver['plate']) . ')</td>
                            </tr>
                        </table>
                        <div class="section">
                        <h2>Trip Details</h2>
                        <table class="info-table">
                            <tr>
                                <td class="label">Pickup Date & Time:</td>
                                <td class="value"><strong>' . $pickup_formatted . '</strong></td>
                            </tr>
                            <tr>
                                <td class="label">Pickup Location:</td>
                                <td class="value">' . htmlspecialchars($pickuplocation) . '</td>
                            </tr>
                            <tr>
                                <td class="label">Drop-off Location:</td>
                                <td class="value">' . htmlspecialchars($dropofflocation) . '</td>
                            </tr>
                            <tr>
                                <td class="label">Number of Passengers:</td>
                                <td class="value">' . $pax . ' passenger' . ($pax > 1 ? 's' : '') . '</td>
                            </tr>
                            <tr>
                                <td class="label">Total Distance:</td>
                                <td class="value">' . $distance . ' km</td>
                            </tr>';
    
    if (!empty($notes)) {
        $html .= '
                            <tr>
                                <td class="label">Special Notes:</td>
                                <td class="value">' . htmlspecialchars($notes) . '</td>
                            </tr>';
    }
    
    $html .= '
                        </table>
                        <div class="section">
                <h2>Cost Breakdown</h2>
                <table class="cost-table">
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th style="text-align: right;">Amount (RM)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Base Fare</td>
                            <td style="text-align: right;">' . number_format($driver['base_fare'], 2) . '</td>
                        </tr>
                        <tr>
                            <td>Distance Charge (' . $distance . ' km × RM ' . number_format($driver['price_per_km'], 2) . ')</td>
                            <td style="text-align: right;">' . number_format($distance * $driver['price_per_km'], 2) . '</td>
                        </tr>';
    
    if ($totalcost > $calculated_cost) {
        $html .= '
                        <tr>
                            <td>Minimum Fare Applied</td>
                            <td style="text-align: right;">RM ' . number_format($driver['minimum_fare'], 2) . '</td>
                        </tr>';
    }
    
    $html .= '
                        <tr class="total-row">
                            <td><strong>TOTAL AMOUNT</strong></td>
                            <td style="text-align: right;"><strong>RM ' . number_format($totalcost, 2) . '</strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
                    </div>
                </div>
            </div>
                    </div>
                </div>
            
            <div class="footer-note">
                <h4 style="color: #6f42c1; margin-top: 0; font-size: 9px;">Important Notes:</h4>
                <ul>
                    <li>This booking is <strong>pending driver confirmation.</strong></li>
                    <li>You will be notified once the driver accepts or declines your booking.</li>
                    <li>Payment will be processed upon driver confirmation.</li>
                    <li>Please arrive at the pickup location 5 minutes before scheduled time.</li>
                    <li>For any queries or changes, please contact our support team immediately.</li>
                    <li>Keep this confirmation for your records.</li>
                </ul>
                
                <div style="text-align: center; margin-top: 5px; border-top: 1px solid #ddd; padding-top: 5px;">
                    <strong style="color: #6f42c1;">Thank you for choosing RenToGo!</strong><br>
                    <small>Report generated on ' . date('d M Y, g:i A') . '</small>
                </div>
            </div>
        </div>
        
    </body>
    </html>';
    
    return $html;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book a Ride - RenToGo</title>
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
            background: linear-gradient(135deg, #6f42c1 0%, #8b5cf6 100%);
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
            border: 1px solid rgba(111, 66, 193, 0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
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

        /* Driver Cards */
        .driver-card {
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .driver-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(111, 66, 193, 0.15);
        }

        /* Forms */
        .form-control, .form-select {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 0.75rem 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: #6f42c1;
            box-shadow: 0 0 0 0.2rem rgba(111, 66, 193, 0.1);
        }

        /* Buttons */
        .btn {
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .btn-primary {
            background: linear-gradient(135deg, #6f42c1 0%, #8b5cf6 100%);
            border: none;
            box-shadow: 0 4px 15px rgba(111, 66, 193, 0.3);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #5a2d9a 0%, #7c3aed 100%);
            box-shadow: 0 6px 20px rgba(111, 66, 193, 0.4);
        }

        .btn-outline-primary {
            border: 2px solid #6f42c1;
            color: #6f42c1;
        }

        .btn-outline-primary:hover {
            background: linear-gradient(135deg, #6f42c1 0%, #8b5cf6 100%);
            border-color: #6f42c1;
        }

        /* Rating stars */
        .rating .bi-star-fill {
            color: #ffc107;
        }

        .rating .bi-star {
            color: #dee2e6;
        }

        /* Pricing display */
        .pricing-info {
            background: linear-gradient(135deg, #6f42c1, #8b5cf6);
            color: white;
            border-radius: 10px;
            padding: 1rem;
        }

        /* Cost calculator */
        .cost-calculator {
            background: rgba(111, 66, 193, 0.05);
            border: 2px solid rgba(111, 66, 193, 0.1);
            border-radius: 10px;
            padding: 1rem;
        }

        /* Distance input styling */
        .distance-input-group {
            position: relative;
        }

        .distance-input-group .form-control {
            padding-right: 3rem;
        }

        .distance-unit {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #6b7280;
            font-weight: 600;
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
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        /* Animations */
        .fade-in {
            animation: fadeIn 0.5s ease-in forwards;
            opacity: 0;
        }

        @keyframes fadeIn {
            from { 
                opacity: 0; 
                transform: translateY(20px); 
            }
            to { 
                opacity: 1; 
                transform: translateY(0); 
            }
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
            
            .flex-grow-1 {
                margin-left: 0;
                width: 100%;
            }
            
            .dashboard-content {
                padding: 1rem;
            }
        }

        
        h4, h5, h6 {
            color: #374151;
            font-weight: 600;
        }

        .text-primary {
            color: #6f42c1 !important;
        }

        .text-muted {
            color: #6b7280 !important;
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
                <small class="text-white-50">Student Portal</small>
            </div>
            <nav class="sidebar-nav">
                <ul class="nav flex-column p-3">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="book-ride.php">
                            <i class="bi bi-plus-circle"></i> Book a Ride
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="my-bookings.php">
                            <i class="bi bi-list-ul"></i> My Bookings
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="browse-drivers.php">
                            <i class="bi bi-search"></i> Browse Drivers
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
                            <h4 class="mb-0">Book a Ride</h4>
                            <small class="text-muted">Find and book available drivers for your trip</small>
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
                    
                    <!-- Success/Error Messages -->
                    <?php if ($message): ?>
                        <div class="alert alert-success alert-dismissible fade show">
                            <i class="bi bi-check-circle"></i> <?php echo $message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show">
                            <i class="bi bi-exclamation-circle"></i> <?php echo $error; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <!-- Search Filters -->
                    <div class="card mb-4">
                        <div class="card-body">
                            <h5 class="card-title">
                                <i class="bi bi-funnel"></i> Search & Filter Drivers
                            </h5>
                            <form method="GET" action="" class="row g-3">
                                <div class="col-md-3">
                                    <label for="search_date" class="form-label">Preferred Date</label>
                                    <input type="date" class="form-control" id="search_date" name="search_date" 
                                           value="<?php echo $search_date; ?>" min="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div class="col-md-3">
                                    <label for="search_capacity" class="form-label">Passengers Needed</label>
                                    <select class="form-control" id="search_capacity" name="search_capacity">
                                        <option value="">Any capacity</option>
                                        <option value="2" <?php echo $search_capacity == 2 ? 'selected' : ''; ?>>2+ passengers</option>
                                        <option value="4" <?php echo $search_capacity == 4 ? 'selected' : ''; ?>>4+ passengers</option>
                                        <option value="6" <?php echo $search_capacity == 6 ? 'selected' : ''; ?>>6+ passengers</option>
                                        <option value="8" <?php echo $search_capacity == 8 ? 'selected' : ''; ?>>8+ passengers</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="search_type" class="form-label">Car Type</label>
                                    <select class="form-control" id="search_type" name="search_type">
                                        <option value="">Any type</option>
                                        <?php foreach ($car_types as $type): ?>
                                            <option value="<?php echo htmlspecialchars($type); ?>" 
                                                    <?php echo $search_type == $type ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($type); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
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

                    <!-- Available Drivers -->
                    <div class="row">
                        <?php if (empty($drivers)): ?>
                            <div class="col-12">
                                <div class="card text-center">
                                    <div class="card-body py-5">
                                        <i class="bi bi-car-front text-muted" style="font-size: 4rem;"></i>
                                        <h5 class="mt-3">No drivers available</h5>
                                        <p class="text-muted">Try adjusting your search criteria or check back later.</p>
                                        <a href="browse-drivers.php" class="btn btn-primary">Browse All Drivers</a>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($drivers as $driver): ?>
                            <div class="col-lg-6 mb-4">
                                <div class="card h-100 driver-card">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-3">
                                            <div>
                                                <h5 class="card-title mb-1">
                                                    <?php echo htmlspecialchars($driver['username']); ?>
                                                    <?php if ($driver['rating'] > 0): ?>
                                                        <span class="rating text-warning ms-2">
                                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                                <i class="bi bi-star<?php echo $i <= $driver['rating'] ? '-fill' : ''; ?>"></i>
                                                            <?php endfor; ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </h5>
                                                <p class="text-muted mb-1">
                                                    <i class="bi bi-car-front"></i> 
                                                    <?php echo htmlspecialchars($driver['carmodel']); ?> - <?php echo htmlspecialchars($driver['plate']); ?>
                                                </p>
                                                <p class="text-muted mb-0">
                                                    <i class="bi bi-people"></i> 
                                                    <?php echo $driver['capacity']; ?> passengers • 
                                                    <i class="bi bi-tag"></i> 
                                                    <?php echo htmlspecialchars($driver['car_type']); ?>
                                                </p>
                                            </div>
                                            <div class="text-end">
                                                <div class="pricing-info text-center">
                                                    <div><strong>Base: RM <?php echo number_format($driver['base_fare'], 2); ?></strong></div>
                                                    <div><strong>RM <?php echo number_format($driver['price_per_km'], 2); ?>/km</strong></div>
                                                    <small>Min: RM <?php echo number_format($driver['minimum_fare'], 2); ?></small>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <small class="text-muted">
                                                <i class="bi bi-phone"></i> <?php echo htmlspecialchars($driver['notel']); ?>
                                            </small>
                                        </div>
                                        
                                        <button class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#bookingModal<?php echo $driver['driverid']; ?>">
                                            <i class="bi bi-calendar-plus"></i> Book This Driver
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Booking Modal -->
                            <div class="modal fade" id="bookingModal<?php echo $driver['driverid']; ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">
                                                <i class="bi bi-calendar-plus"></i> Book Ride with <?php echo htmlspecialchars($driver['username']); ?>
                                            </h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST" action="">
                                            <div class="modal-body">
                                                <input type="hidden" name="driverid" value="<?php echo $driver['driverid']; ?>">
                                                
                                                <!-- Driver Info Summary -->
                                                <div class="alert alert-info">
                                                    <div class="row">
                                                        <div class="col-md-8">
                                                            <strong><?php echo htmlspecialchars($driver['carmodel']); ?></strong> (<?php echo htmlspecialchars($driver['plate']); ?>)<br>
                                                            <small>Capacity: <?php echo $driver['capacity']; ?> passengers • <?php echo htmlspecialchars($driver['car_type']); ?></small>
                                                        </div>
                                                        <div class="col-md-4 text-md-end">
                                                            <div class="pricing-info">
                                                                <small>Base: RM <?php echo number_format($driver['base_fare'], 2); ?> + RM <?php echo number_format($driver['price_per_km'], 2); ?>/km</small><br>
                                                                <small>Minimum: RM <?php echo number_format($driver['minimum_fare'], 2); ?></small>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="row g-3">
                                                    <div class="col-md-12">
                                                        <label for="pickupdate<?php echo $driver['driverid']; ?>" class="form-label">Pickup Date & Time *</label>
                                                        <input type="datetime-local" class="form-control" 
                                                               id="pickupdate<?php echo $driver['driverid']; ?>" 
                                                               name="pickupdate" required 
                                                               min="<?php echo date('Y-m-d\TH:i'); ?>"
                                                               onchange="calculateDistanceCost(<?php echo $driver['driverid']; ?>)">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label for="pickuplocation<?php echo $driver['driverid']; ?>" class="form-label">Pickup Location *</label>
                                                        <input type="text" class="form-control" 
                                                               id="pickuplocation<?php echo $driver['driverid']; ?>" 
                                                               name="pickuplocation" required 
                                                               placeholder="e.g., UTM Puncak Perdana Main Gate">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label for="dropofflocation<?php echo $driver['driverid']; ?>" class="form-label">Drop-off Location *</label>
                                                        <input type="text" class="form-control" 
                                                               id="dropofflocation<?php echo $driver['driverid']; ?>" 
                                                               name="dropofflocation" required 
                                                               placeholder="e.g., KL Sentral">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label for="distance<?php echo $driver['driverid']; ?>" class="form-label">Estimated Distance (Total) *</label>
                                                        <div class="distance-input-group">
                                                            <input type="number" class="form-control" 
                                                                   id="distance<?php echo $driver['driverid']; ?>" 
                                                                   name="distance" required 
                                                                   step="0.1" min="1" max="500"
                                                                   placeholder="15.5"
                                                                   onchange="calculateDistanceCost(<?php echo $driver['driverid']; ?>)">
                                                            <span class="distance-unit">km</span>
                                                        </div>
                                                        <small class="text-muted">Include total journey distance</small>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label for="pax<?php echo $driver['driverid']; ?>" class="form-label">Number of Passengers *</label>
                                                        <select class="form-control" id="pax<?php echo $driver['driverid']; ?>" name="pax" required>
                                                            <option value="">Select passengers</option>
                                                            <?php for ($i = 1; $i <= $driver['capacity']; $i++): ?>
                                                                <option value="<?php echo $i; ?>"><?php echo $i; ?> passenger<?php echo $i > 1 ? 's' : ''; ?></option>
                                                            <?php endfor; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-12">
                                                        <div class="cost-calculator">
                                                            <h6 class="mb-3">
                                                                <i class="bi bi-calculator"></i> Cost Breakdown
                                                            </h6>
                                                            <div id="cost_breakdown<?php echo $driver['driverid']; ?>">
                                                                <p class="text-muted">Enter distance to calculate cost</p>
                                                            </div>
                                                            <hr>
                                                            <div class="d-flex justify-content-between align-items-center">
                                                                <span><strong>Total Cost:</strong></span>
                                                                <span class="h5 text-primary mb-0" id="total_cost<?php echo $driver['driverid']; ?>">RM 0.00</span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="col-12">
                                                        <label for="notes<?php echo $driver['driverid']; ?>" class="form-label">Additional Notes</label>
                                                        <textarea class="form-control" id="notes<?php echo $driver['driverid']; ?>" 
                                                                  name="notes" rows="3" 
                                                                  placeholder="Any special requirements or notes for the driver..."></textarea>
                                                    </div>
                                                </div>
                                                
                                                <!-- Hidden pricing values -->
                                                <input type="hidden" id="base_fare<?php echo $driver['driverid']; ?>" value="<?php echo $driver['base_fare']; ?>">
                                                <input type="hidden" id="price_per_km<?php echo $driver['driverid']; ?>" value="<?php echo $driver['price_per_km']; ?>">
                                                <input type="hidden" id="minimum_fare<?php echo $driver['driverid']; ?>" value="<?php echo $driver['minimum_fare']; ?>">
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" name="book_ride" class="btn btn-primary">
                                                    <i class="bi bi-check-circle"></i> Confirm Booking
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
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
            <p class="text-muted mb-4">Are you sure you want to logout from the student portal?</p>
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
        function calculateDistanceCost(driverid) {
            const distance = parseFloat(document.getElementById(`distance${driverid}`).value) || 0;
            const baseFare = parseFloat(document.getElementById(`base_fare${driverid}`).value) || 0;
            const pricePerKm = parseFloat(document.getElementById(`price_per_km${driverid}`).value) || 0;
            const minimumFare = parseFloat(document.getElementById(`minimum_fare${driverid}`).value) || 0;
            
            const breakdownElement = document.getElementById(`cost_breakdown${driverid}`);
            const totalElement = document.getElementById(`total_cost${driverid}`);
            
            if (distance > 0) {
                const kmCost = distance * pricePerKm;
                const calculatedTotal = baseFare + kmCost;
                const finalTotal = Math.max(calculatedTotal, minimumFare);
                
                breakdownElement.innerHTML = `
                    <div class="row text-sm">
                        <div class="col-6">Base Fare:</div>
                        <div class="col-6 text-end">RM ${baseFare.toFixed(2)}</div>
                        <div class="col-6">Distance (${distance} km × RM ${pricePerKm.toFixed(2)}):</div>
                        <div class="col-6 text-end">RM ${kmCost.toFixed(2)}</div>
                        <div class="col-6">Subtotal:</div>
                        <div class="col-6 text-end">RM ${calculatedTotal.toFixed(2)}</div>
                        ${finalTotal > calculatedTotal ? `
                        <div class="col-6 text-warning">Minimum Fare Applied:</div>
                        <div class="col-6 text-end text-warning">RM ${minimumFare.toFixed(2)}</div>
                        ` : ''}
                    </div>
                `;
                
                totalElement.textContent = `RM ${finalTotal.toFixed(2)}`;
            } else {
                breakdownElement.innerHTML = '<p class="text-muted">Enter distance to calculate cost</p>';
                totalElement.textContent = 'RM 0.00';
            }
        }

        // Set minimum drop-off date when pickup date changes
        document.addEventListener('DOMContentLoaded', function() {
            const pickupInputs = document.querySelectorAll('input[name="pickupdate"]');
            pickupInputs.forEach(input => {
                input.addEventListener('change', function() {
                    const driverid = this.id.replace('pickupdate', '');
                    const dropoffInput = document.getElementById(`dropoffdate${driverid}`);
                    if (dropoffInput) {
                        dropoffInput.min = this.value;
                    }
                });
            });

            // Add animations
            const cards = document.querySelectorAll('.card');
            cards.forEach((card, index) => {
                card.style.animationDelay = (index * 0.1) + 's';
                card.classList.add('fade-in');
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

        // Form validation
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const pickupDateInput = this.querySelector('input[name="pickupdate"]');
                const dropoffDateInput = this.querySelector('input[name="dropoffdate"]');
                
                if (pickupDateInput && dropoffDateInput) {
                    const pickupDate = new Date(pickupDateInput.value);
                    const dropoffDate = new Date(dropoffDateInput.value);
                    
                    if (dropoffDate <= pickupDate) {
                        e.preventDefault();
                        alert('Drop-off date must be after pickup date');
                        return false;
                    }
                }
                
                const distanceInput = this.querySelector('input[name="distance"]');
                if (distanceInput) {
                    const distance = parseFloat(distanceInput.value);
                    if (!distance || distance < 1) {
                        e.preventDefault();
                        alert('Please enter a valid distance (minimum 1 km)');
                        return false;
                    }
                }
            });
        });
    </script>
</body>
</html>