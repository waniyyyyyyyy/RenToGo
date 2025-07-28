<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RenToGo - Car Booking System for UiTM Students</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm fixed-top">
        <div class="container">
            <a class="navbar-brand fw-bold text-primary" href="index.php">
                <i class="bi bi-car-front-fill"></i> RenToGo
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="#home">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#features">Features</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#about">About</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link btn btn-outline-primary btn-lg ms-2 px-3" href="auth/login.php">Login</a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link btn btn-primary btn-lg ms-2 px-3 text-white dropdown-toggle" href="#" id="registerDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Register
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="registerDropdown">
                            <li><a class="dropdown-item" href="auth/student_register.php">
                                <i class="bi bi-mortarboard text-primary"></i> Student Registration
                            </a></li>
                            <li><a class="dropdown-item" href="auth/driver_register.php">
                                <i class="bi bi-car-front text-success"></i> Driver Registration
                            </a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section id="home" class="hero-section">
        <div class="container">
            <div class="row align-items-center min-vh-100">
                <div class="col-lg-6">
                    <h1 class="display-4 fw-bold text-primary mb-4">
                        Book Your Ride, <br>
                        <span class="text-secondary">Anytime, Anywhere</span>
                    </h1>
                    <p class="lead mb-4">
                        Safe, reliable, and affordable car booking system exclusively for UiTM Puncak Perdana students. 
                        Connect with trusted drivers in your campus community.
                    </p>
                    <div class="d-flex gap-3 flex-wrap">
                        <a href="auth/student_register.php" class="btn btn-primary btn-lg px-4">
                            <i class="bi bi-mortarboard"></i> Register as Student
                        </a>
                        <a href="auth/driver_register.php" class="btn btn-outline-success btn-lg px-4">
                            <i class="bi bi-car-front"></i> Become a Driver
                        </a>
                    </div>
                    <div class="mt-3">
                        <small class="text-muted">
                            Already have an account? <a href="auth/login.php" class="text-primary text-decoration-none fw-semibold">Sign in here</a>
                        </small>
                    </div>
                </div>
                <div class="col-lg-6 text-center">
                    <div class="hero-image">
                        <i class="bi bi-car-front-fill text-primary" style="font-size: 15rem; opacity: 0.8;"></i>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="features" class="py-5 bg-light">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="fw-bold">Why Choose RenToGo?</h2>
                <p class="text-muted">Everything you need for a seamless car booking experience</p>
            </div>
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="card h-100 text-center border-0 shadow-sm">
                        <div class="card-body p-4">
                            <div class="feature-icon mb-3">
                                <i class="bi bi-shield-check" style="font-size: 3rem;"></i>
                            </div>
                            <h5 class="fw-bold">Safe & Verified</h5>
                            <p class="text-muted">All drivers are verified UiTM community members with valid licenses.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card h-100 text-center border-0 shadow-sm">
                        <div class="card-body p-4">
                            <div class="feature-icon mb-3">
                                <i class="bi bi-currency-dollar" style="font-size: 3rem;"></i>
                            </div>
                            <h5 class="fw-bold">Affordable Rates</h5>
                            <p class="text-muted">Student-friendly pricing with transparent costs and no hidden fees.</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card h-100 text-center border-0 shadow-sm">
                        <div class="card-body p-4">
                            <div class="feature-icon mb-3">
                                <i class="bi bi-clock" style="font-size: 3rem;"></i>
                            </div>
                            <h5 class="fw-bold">24/7 Booking</h5>
                            <p class="text-muted">Book anytime, anywhere with our easy-to-use online platform.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- About Section -->
    <section id="about" class="py-5">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-6">
                    <h2 class="fw-bold mb-4">About RenToGo</h2>
                    <p class="mb-4">
                        RenToGo is designed specifically for the UiTM Puncak Perdana community, 
                        connecting students who need transportation with fellow students and community 
                        members who can provide rides.
                    </p>
                    <div class="row g-3">
                        <div class="col-6">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-people-fill text-primary me-2"></i>
                                <span class="fw-bold">Community-Based</span>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-geo-alt-fill text-primary me-2"></i>
                                <span class="fw-bold">Campus Focused</span>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-star-fill text-primary me-2"></i>
                                <span class="fw-bold">Rated Drivers</span>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-credit-card-fill text-primary me-2"></i>
                                <span class="fw-bold">Flexible Payment</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6 text-center">
                    <div class="about-image">
                        <img src="img/uitm.jpeg" alt="UiTM Campus" class="img-fluid rounded shadow">
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- How It Works Section -->
    <section class="py-5 bg-light">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="fw-bold">How It Works</h2>
                <p class="text-muted">Simple steps to get you started</p>
            </div>
            <div class="row g-4">
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-start">
                                <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px;">
                                    <i class="bi bi-mortarboard"></i>
                                </div>
                                <div>
                                    <h5 class="fw-bold text-primary">For Students</h5>
                                    <ol class="text-muted mb-0">
                                        <li>Register with your student number</li>
                                        <li>Browse available drivers and rides</li>
                                        <li>Book your ride and make payment</li>
                                        <li>Enjoy safe transportation to your destination</li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-start">
                                <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px;">
                                    <i class="bi bi-car-front"></i>
                                </div>
                                <div>
                                    <h5 class="fw-bold text-success">For Drivers</h5>
                                    <ol class="text-muted mb-0">
                                        <li>Register with your driver's license</li>
                                        <li>Add your vehicle information</li>
                                        <li>Set your availability and routes</li>
                                        <li>Accept bookings and earn money</li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

<!-- Enhanced Footer -->
<footer class="bg-dark text-white py-5">
    <div class="container">
        <div class="row">
            <div class="col-md-4">
                <h5 class="fw-bold mb-3 text-white">
                    <i class="bi bi-car-front-fill text-primary"></i> RenToGo
                </h5>
                <p class="text-light opacity-75">
                    Your trusted car booking platform for UiTM Puncak Perdana community.
                </p>
                <div class="d-flex gap-2 mt-3">
                    <a href="auth/student_register.php" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-mortarboard"></i> Student
                    </a>
                    <a href="auth/driver_register.php" class="btn btn-outline-success btn-sm">
                        <i class="bi bi-car-front"></i> Driver
                    </a>
                </div>
            </div>
            <div class="col-md-4">
                <h6 class="fw-bold mb-3 text-white">Quick Links</h6>
                <ul class="list-unstyled">
                    <li><a href="#home" class="text-light opacity-75 text-decoration-none">Home</a></li>
                    <li><a href="#features" class="text-light opacity-75 text-decoration-none">Features</a></li>
                    <li><a href="#about" class="text-light opacity-75 text-decoration-none">About</a></li>
                    <li><a href="auth/login.php" class="text-light opacity-75 text-decoration-none">Login</a></li>
                </ul>
            </div>
            <div class="col-md-4">
                <h6 class="fw-bold mb-3 text-white">Contact</h6>
                <p class="text-light opacity-75 mb-1">
                    <i class="bi bi-envelope me-2"></i> info@rentogo.com
                </p>
                <p class="text-light opacity-75 mb-1">
                    <i class="bi bi-phone me-2"></i> +60 12-345-6789
                </p>
                <p class="text-light opacity-75">
                    <i class="bi bi-geo-alt me-2"></i> UiTM Puncak Perdana
                </p>
            </div>
        </div>
        <hr class="my-4 opacity-25">
        <div class="row">
            <div class="col-md-6">
                <p class="text-light opacity-75 mb-0">
                    © 2025 RenToGo. All rights reserved.
                </p>
            </div>
            <div class="col-md-6 text-md-end">
                <p class="text-light opacity-75 mb-0">
                    Built for UiTM Students with ❤️
                </p>
            </div>
        </div>
    </div>
</footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/main.js"></script>
</body>
</html>