/**
 * RentOGo - Main JavaScript File
 * Common functionality for the entire application
 */

// Global variables
const RentOGo = {
    currentUser: null,
    config: {
        autoRefreshInterval: 30000, // 30 seconds
        animationDelay: 100, // milliseconds between animations
        debounceDelay: 300 // milliseconds for search debouncing
    }
};

// Document ready function
document.addEventListener('DOMContentLoaded', function() {
    initializeApp();
});

/**
 * Initialize the application
 */
function initializeApp() {
    // Initialize common components
    initializeAnimations();
    initializeFormValidation();
    initializeTooltips();
    initializeAlerts();
    initializeModals();
    initializeSearchFunctionality();
    initializeDateTimePickers();
    initializeFileUploads();
    
    // Initialize page-specific functionality
    const page = getPageIdentifier();
    switch(page) {
        case 'login':
            initializeLoginPage();
            break;
        case 'register':
            initializeRegisterPage();
            break;
        case 'dashboard':
            initializeDashboard();
            break;
        case 'booking':
            initializeBookingPage();
            break;
        case 'browse':
            initializeBrowsePage();
            break;
    }
    
    console.log('RentOGo application initialized successfully');
}

/**
 * Get page identifier based on URL or body class
 */
function getPageIdentifier() {
    const path = window.location.pathname;
    const filename = path.split('/').pop().split('.')[0];
    
    if (filename.includes('login')) return 'login';
    if (filename.includes('register')) return 'register';
    if (filename.includes('dashboard')) return 'dashboard';
    if (filename.includes('book')) return 'booking';
    if (filename.includes('browse')) return 'browse';
    
    return 'general';
}

/**
 * Initialize animations
 */
function initializeAnimations() {
    // Fade in elements with .fade-in class
    const fadeElements = document.querySelectorAll('.fade-in, .stats-card, .card');
    fadeElements.forEach((element, index) => {
        element.style.opacity = '0';
        element.style.transform = 'translateY(20px)';
        element.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        
        setTimeout(() => {
            element.style.opacity = '1';
            element.style.transform = 'translateY(0)';
        }, index * RentOGo.config.animationDelay);
    });
    
    // Slide in sidebar elements
    const sidebarElements = document.querySelectorAll('.sidebar-nav .nav-item');
    sidebarElements.forEach((element, index) => {
        element.style.opacity = '0';
        element.style.transform = 'translateX(-20px)';
        element.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
        
        setTimeout(() => {
            element.style.opacity = '1';
            element.style.transform = 'translateX(0)';
        }, (index * 50) + 200);
    });
}

/**
 * Initialize form validation
 */
function initializeFormValidation() {
    const forms = document.querySelectorAll('form[data-validate]');
    
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            if (!validateForm(this)) {
                e.preventDefault();
                e.stopPropagation();
                showAlert('Please correct the errors in the form.', 'danger');
            }
        });
        
        // Real-time validation
        const inputs = form.querySelectorAll('input, select, textarea');
        inputs.forEach(input => {
            input.addEventListener('blur', function() {
                validateField(this);
            });
            
            input.addEventListener('input', function() {
                clearFieldError(this);
            });
        });
    });
}

/**
 * Validate a form
 */
function validateForm(form) {
    let isValid = true;
    const inputs = form.querySelectorAll('input[required], select[required], textarea[required]');
    
    inputs.forEach(input => {
        if (!validateField(input)) {
            isValid = false;
        }
    });
    
    return isValid;
}

/**
 * Validate a single field
 */
function validateField(field) {
    const value = field.value.trim();
    const type = field.type;
    let isValid = true;
    let errorMessage = '';
    
    // Required field check
    if (field.hasAttribute('required') && !value) {
        isValid = false;
        errorMessage = 'This field is required.';
    }
    
    // Type-specific validation
    if (value && isValid) {
        switch(type) {
            case 'email':
                if (!isValidEmail(value)) {
                    isValid = false;
                    errorMessage = 'Please enter a valid email address.';
                }
                break;
            case 'password':
                if (value.length < 6) {
                    isValid = false;
                    errorMessage = 'Password must be at least 6 characters long.';
                }
                break;
            case 'tel':
                if (!isValidPhone(value)) {
                    isValid = false;
                    errorMessage = 'Please enter a valid phone number.';
                }
                break;
            case 'number':
                const min = field.getAttribute('min');
                const max = field.getAttribute('max');
                const numValue = parseFloat(value);
                
                if (min && numValue < parseFloat(min)) {
                    isValid = false;
                    errorMessage = `Value must be at least ${min}.`;
                } else if (max && numValue > parseFloat(max)) {
                    isValid = false;
                    errorMessage = `Value must be no more than ${max}.`;
                }
                break;
        }
    }
    
    // Custom validation patterns
    const pattern = field.getAttribute('pattern');
    if (pattern && value && !new RegExp(pattern).test(value)) {
        isValid = false;
        errorMessage = field.getAttribute('data-pattern-error') || 'Invalid format.';
    }
    
    // Show/hide error
    if (isValid) {
        clearFieldError(field);
    } else {
        showFieldError(field, errorMessage);
    }
    
    return isValid;
}

/**
 * Show field error
 */
function showFieldError(field, message) {
    clearFieldError(field);
    
    field.classList.add('is-invalid');
    
    const errorDiv = document.createElement('div');
    errorDiv.className = 'invalid-feedback';
    errorDiv.textContent = message;
    
    field.parentNode.appendChild(errorDiv);
}

/**
 * Clear field error
 */
function clearFieldError(field) {
    field.classList.remove('is-invalid');
    
    const errorDiv = field.parentNode.querySelector('.invalid-feedback');
    if (errorDiv) {
        errorDiv.remove();
    }
}

/**
 * Validate email format
 */
function isValidEmail(email) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(email);
}

/**
 * Validate phone format
 */
function isValidPhone(phone) {
    const phoneRegex = /^[\+]?[0-9\s\-\(\)]{10,}$/;
    return phoneRegex.test(phone);
}

/**
 * Initialize tooltips
 */
function initializeTooltips() {
    // Initialize Bootstrap tooltips if available
    if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    }
}

/**
 * Initialize alerts
 */
function initializeAlerts() {
    // Auto-dismiss alerts after 5 seconds
    const alerts = document.querySelectorAll('.alert:not(.alert-permanent)');
    alerts.forEach(alert => {
        if (!alert.querySelector('.btn-close')) {
            setTimeout(() => {
                fadeOutElement(alert);
            }, 5000);
        }
    });
}

/**
 * Show alert message
 */
function showAlert(message, type = 'info', container = null) {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
    alertDiv.innerHTML = `
        <i class="bi bi-info-circle"></i> ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    const targetContainer = container || document.querySelector('.dashboard-content .container-fluid') || document.body;
    targetContainer.insertBefore(alertDiv, targetContainer.firstChild);
    
    // Auto-dismiss after 5 seconds
    setTimeout(() => {
        if (alertDiv.parentNode) {
            fadeOutElement(alertDiv);
        }
    }, 5000);
}

/**
 * Initialize modals
 */
function initializeModals() {
    // Add loading state to modal forms
    const modalForms = document.querySelectorAll('.modal form');
    modalForms.forEach(form => {
        form.addEventListener('submit', function() {
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) {
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<div class="spinner"></div> Processing...';
                submitBtn.disabled = true;
                
                // Re-enable after 3 seconds (fallback)
                setTimeout(() => {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }, 3000);
            }
        });
    });
}

/**
 * Initialize search functionality
 */
function initializeSearchFunctionality() {
    const searchInputs = document.querySelectorAll('input[data-search]');
    
    searchInputs.forEach(input => {
        let searchTimeout;
        
        input.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const query = this.value.trim();
            const target = this.getAttribute('data-search-target');
            
            searchTimeout = setTimeout(() => {
                performSearch(query, target);
            }, RentOGo.config.debounceDelay);
        });
    });
}

/**
 * Perform search operation
 */
function performSearch(query, target) {
    const elements = document.querySelectorAll(target);
    
    elements.forEach(element => {
        const text = element.textContent.toLowerCase();
        const matches = query.toLowerCase().split(' ').every(term => text.includes(term));
        
        if (matches || !query) {
            element.style.display = '';
            element.classList.remove('search-hidden');
        } else {
            element.style.display = 'none';
            element.classList.add('search-hidden');
        }
    });
    
    // Update results count if available
    const visibleCount = document.querySelectorAll(target + ':not(.search-hidden)').length;
    const countElement = document.querySelector('[data-search-count]');
    if (countElement) {
        countElement.textContent = `${visibleCount} result(s) found`;
    }
}

/**
 * Initialize date/time pickers
 */
function initializeDateTimePickers() {
    const dateInputs = document.querySelectorAll('input[type="date"], input[type="datetime-local"]');
    
    dateInputs.forEach(input => {
        // Set minimum date to today
        if (!input.getAttribute('min')) {
            const today = new Date();
            const dateString = today.toISOString().split('T')[0];
            
            if (input.type === 'date') {
                input.min = dateString;
            } else if (input.type === 'datetime-local') {
                input.min = today.toISOString().slice(0, 16);
            }
        }
        
        // Add change event for dependent fields
        input.addEventListener('change', function() {
            updateDependentDateFields(this);
        });
    });
}

/**
 * Update dependent date fields
 */
function updateDependentDateFields(changedInput) {
    const inputName = changedInput.name;
    
    // Update return date when pickup date changes
    if (inputName === 'pickupdate') {
        const returnInput = document.querySelector('input[name="returndate"]');
        if (returnInput) {
            returnInput.min = changedInput.value;
            
            // Clear return date if it's before pickup date
            if (returnInput.value && returnInput.value < changedInput.value) {
                returnInput.value = '';
            }
        }
    }
}

/**
 * Initialize file uploads
 */
function initializeFileUploads() {
    const fileInputs = document.querySelectorAll('input[type="file"]');
    
    fileInputs.forEach(input => {
        input.addEventListener('change', function() {
            handleFileUpload(this);
        });
    });
}

/**
 * Handle file upload
 */
function handleFileUpload(input) {
    const file = input.files[0];
    if (!file) return;
    
    // Validate file size (5MB max)
    const maxSize = 5 * 1024 * 1024;
    if (file.size > maxSize) {
        showAlert('File size must be less than 5MB.', 'danger');
        input.value = '';
        return;
    }
    
    // Show file preview if it's an image
    if (file.type.startsWith('image/')) {
        const preview = input.parentNode.querySelector('.file-preview');
        if (preview) {
            const reader = new FileReader();
            reader.onload = function(e) {
                preview.innerHTML = `<img src="${e.target.result}" alt="Preview" style="max-width: 100px; max-height: 100px;">`;
            };
            reader.readAsDataURL(file);
        }
    }
}

/**
 * Initialize login page
 */
function initializeLoginPage() {
    // Remember username
    const usernameInput = document.getElementById('username');
    const rememberCheckbox = document.getElementById('remember');
    
    if (usernameInput && localStorage.getItem('rememberedUsername')) {
        usernameInput.value = localStorage.getItem('rememberedUsername');
        if (rememberCheckbox) rememberCheckbox.checked = true;
    }
    
    // Save username on form submit
    const loginForm = document.querySelector('form');
    if (loginForm) {
        loginForm.addEventListener('submit', function() {
            if (rememberCheckbox && rememberCheckbox.checked) {
                localStorage.setItem('rememberedUsername', usernameInput.value);
            } else {
                localStorage.removeItem('rememberedUsername');
            }
        });
    }
    
    // Password visibility toggle
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
    
    if (togglePassword && passwordInput) {
        togglePassword.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            
            const icon = this.querySelector('i');
            if (icon) {
                icon.classList.toggle('bi-eye');
                icon.classList.toggle('bi-eye-slash');
            }
        });
    }
}

/**
 * Initialize register page
 */
function initializeRegisterPage() {
    // Password confirmation check
    const passwordInput = document.getElementById('password');
    const confirmPasswordInput = document.getElementById('confirm_password');
    
    if (passwordInput && confirmPasswordInput) {
        function checkPasswordMatch() {
            if (confirmPasswordInput.value && passwordInput.value !== confirmPasswordInput.value) {
                showFieldError(confirmPasswordInput, 'Passwords do not match.');
                return false;
            } else {
                clearFieldError(confirmPasswordInput);
                return true;
            }
        }
        
        confirmPasswordInput.addEventListener('input', checkPasswordMatch);
        passwordInput.addEventListener('input', checkPasswordMatch);
    }
    
    // Role-specific field visibility
    const roleInputs = document.querySelectorAll('input[name="role_select"]');
    roleInputs.forEach(input => {
        input.addEventListener('change', function() {
            toggleRoleFields(this.value);
        });
    });
}

/**
 * Toggle role-specific fields
 */
function toggleRoleFields(role) {
    const studentFields = document.querySelectorAll('[data-role="student"]');
    const driverFields = document.querySelectorAll('[data-role="driver"]');
    
    if (role === 'student') {
        studentFields.forEach(field => field.style.display = 'block');
        driverFields.forEach(field => field.style.display = 'none');
    } else if (role === 'driver') {
        studentFields.forEach(field => field.style.display = 'none');
        driverFields.forEach(field => field.style.display = 'block');
    }
}

/**
 * Initialize dashboard
 */
function initializeDashboard() {
    // Auto-refresh dashboard data
    if (RentOGo.config.autoRefreshInterval > 0) {
        setInterval(() => {
            if (document.hasFocus() && !document.querySelector('.modal.show')) {
                refreshDashboardData();
            }
        }, RentOGo.config.autoRefreshInterval);
    }
    
    // Initialize real-time notifications
    initializeNotifications();
}

/**
 * Refresh dashboard data
 */
function refreshDashboardData() {
    // Refresh specific dashboard elements without full page reload
    fetch(window.location.href + '?ajax=1')
        .then(response => response.json())
        .then(data => {
            updateDashboardStats(data);
        })
        .catch(error => {
            console.log('Dashboard refresh failed:', error);
        });
}

/**
 * Update dashboard statistics
 */
function updateDashboardStats(data) {
    if (data.stats) {
        Object.keys(data.stats).forEach(key => {
            const element = document.querySelector(`[data-stat="${key}"]`);
            if (element) {
                const newValue = data.stats[key];
                const currentValue = element.textContent;
                
                if (newValue !== currentValue) {
                    element.style.transition = 'color 0.5s ease';
                    element.style.color = '#28a745';
                    element.textContent = newValue;
                    
                    setTimeout(() => {
                        element.style.color = '';
                    }, 1000);
                }
            }
        });
    }
}

/**
 * Initialize notifications
 */
function initializeNotifications() {
    // Check for browser notification support
    if ('Notification' in window) {
        // Request permission if not granted
        if (Notification.permission === 'default') {
            Notification.requestPermission();
        }
    }
}

/**
 * Show browser notification
 */
function showNotification(title, options = {}) {
    if ('Notification' in window && Notification.permission === 'granted') {
        const defaultOptions = {
            icon: '/assets/images/logo.png',
            badge: '/assets/images/badge.png',
            tag: 'rentogo-notification'
        };
        
        const notification = new Notification(title, { ...defaultOptions, ...options });
        
        notification.onclick = function() {
            window.focus();
            notification.close();
        };
        
        // Auto-close after 5 seconds
        setTimeout(() => {
            notification.close();
        }, 5000);
    }
}

/**
 * Initialize booking page
 */
function initializeBookingPage() {
    // Initialize cost calculation
    initializeCostCalculation();
    
    // Initialize map integration (if available)
    initializeMapIntegration();
    
    // Initialize payment integration
    initializePaymentIntegration();
}

/**
 * Initialize cost calculation
 */
function initializeCostCalculation() {
    const pickupInputs = document.querySelectorAll('input[name="pickupdate"]');
    const returnInputs = document.querySelectorAll('input[name="returndate"]');
    
    pickupInputs.forEach(input => {
        input.addEventListener('change', calculateBookingCost);
    });
    
    returnInputs.forEach(input => {
        input.addEventListener('change', calculateBookingCost);
    });
}

/**
 * Calculate booking cost
 */
function calculateBookingCost() {
    const pickupDate = document.querySelector('input[name="pickupdate"]')?.value;
    const returnDate = document.querySelector('input[name="returndate"]')?.value;
    const pricePerHour = document.querySelector('input[name="price_per_hour"]')?.value;
    
    if (pickupDate && returnDate && pricePerHour) {
        const pickup = new Date(pickupDate);
        const returnTime = new Date(returnDate);
        
        if (returnTime > pickup) {
            const diffMs = returnTime - pickup;
            const diffHours = diffMs / (1000 * 60 * 60);
            const totalCost = diffHours * parseFloat(pricePerHour);
            
            const costElement = document.getElementById('estimated_cost');
            if (costElement) {
                costElement.innerHTML = `
                    <strong class="text-primary">RM ${totalCost.toFixed(2)}</strong>
                    <small class="text-muted d-block">${diffHours.toFixed(1)} hours × RM ${parseFloat(pricePerHour).toFixed(2)}/hour</small>
                `;
            }
        }
    }
}

/**
 * Initialize map integration
 */
function initializeMapIntegration() {
    // Placeholder for map integration (Google Maps, etc.)
    const mapElements = document.querySelectorAll('[data-map]');
    
    mapElements.forEach(element => {
        // Initialize map here if needed
        console.log('Map element found:', element);
    });
}

/**
 * Initialize payment integration
 */
function initializePaymentIntegration() {
    // Placeholder for payment gateway integration
    const paymentForms = document.querySelectorAll('[data-payment]');
    
    paymentForms.forEach(form => {
        form.addEventListener('submit', function(e) {
            // Add payment processing logic here
            console.log('Payment form submitted:', form);
        });
    });
}

/**
 * Initialize browse page
 */
function initializeBrowsePage() {
    // Initialize filter functionality
    initializeFilters();
    
    // Initialize sorting
    initializeSorting();
    
    // Initialize infinite scroll (if needed)
    initializeInfiniteScroll();
}

/**
 * Initialize filters
 */
function initializeFilters() {
    const filterForm = document.querySelector('form[data-filter]');
    if (!filterForm) return;
    
    const inputs = filterForm.querySelectorAll('input, select');
    inputs.forEach(input => {
        input.addEventListener('change', debounce(applyFilters, 500));
    });
}

/**
 * Apply filters
 */
function applyFilters() {
    const filterForm = document.querySelector('form[data-filter]');
    if (!filterForm) return;
    
    const formData = new FormData(filterForm);
    const params = new URLSearchParams(formData);
    
    // Update URL without page reload
    const newUrl = window.location.pathname + '?' + params.toString();
    history.pushState(null, '', newUrl);
    
    // Apply filters to visible elements
    filterResults(formData);
}

/**
 * Filter results
 */
function filterResults(formData) {
    const items = document.querySelectorAll('[data-filterable]');
    let visibleCount = 0;
    
    items.forEach(item => {
        let isVisible = true;
        
        // Apply each filter
        for (let [key, value] of formData.entries()) {
            if (!value) continue;
            
            const itemValue = item.getAttribute(`data-${key}`);
            if (itemValue && !itemValue.toLowerCase().includes(value.toLowerCase())) {
                isVisible = false;
                break;
            }
        }
        
        if (isVisible) {
            item.style.display = '';
            visibleCount++;
        } else {
            item.style.display = 'none';
        }
    });
    
    // Update results count
    const countElement = document.querySelector('[data-results-count]');
    if (countElement) {
        countElement.textContent = `${visibleCount} result(s) found`;
    }
}

/**
 * Initialize sorting
 */
function initializeSorting() {
    const sortSelect = document.querySelector('select[data-sort]');
    if (!sortSelect) return;
    
    sortSelect.addEventListener('change', function() {
        sortResults(this.value);
    });
}

/**
 * Sort results
 */
function sortResults(sortBy) {
    const container = document.querySelector('[data-sort-container]');
    if (!container) return;
    
    const items = Array.from(container.children);
    
    items.sort((a, b) => {
        const aValue = a.getAttribute(`data-sort-${sortBy}`);
        const bValue = b.getAttribute(`data-sort-${sortBy}`);
        
        if (sortBy.includes('price') || sortBy.includes('rating')) {
            return parseFloat(bValue) - parseFloat(aValue);
        } else {
            return aValue.localeCompare(bValue);
        }
    });
    
    // Re-append sorted items
    items.forEach(item => container.appendChild(item));
}

/**
 * Initialize infinite scroll
 */
function initializeInfiniteScroll() {
    if (!document.querySelector('[data-infinite-scroll]')) return;
    
    let loading = false;
    let page = 1;
    
    window.addEventListener('scroll', () => {
        if (loading) return;
        
        const scrollTop = window.scrollY;
        const windowHeight = window.innerHeight;
        const documentHeight = document.documentElement.scrollHeight;
        
        if (scrollTop + windowHeight >= documentHeight - 100) {
            loading = true;
            loadMoreResults(++page).then(() => {
                loading = false;
            });
        }
    });
}

/**
 * Load more results
 */
function loadMoreResults(page) {
    return fetch(`${window.location.pathname}?page=${page}&ajax=1`)
        .then(response => response.json())
        .then(data => {
            if (data.html) {
                const container = document.querySelector('[data-infinite-scroll]');
                container.insertAdjacentHTML('beforeend', data.html);
                
                // Re-initialize animations for new elements
                initializeAnimations();
            }
        })
        .catch(error => {
            console.error('Failed to load more results:', error);
        });
}

/**
 * Utility functions
 */

// Debounce function
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Fade out element
function fadeOutElement(element) {
    element.style.transition = 'opacity 0.5s ease';
    element.style.opacity = '0';
    
    setTimeout(() => {
        if (element.parentNode) {
            element.parentNode.removeChild(element);
        }
    }, 500);
}

// Format currency
function formatCurrency(amount, currency = 'RM') {
    return `${currency} ${parseFloat(amount).toFixed(2)}`;
}

// Format date
function formatDate(date, format = 'short') {
    const options = format === 'short' 
        ? { year: 'numeric', month: 'short', day: 'numeric' }
        : { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' };
    
    return new Date(date).toLocaleDateString('en-MY', options);
}

// Get user's location
function getUserLocation() {
    return new Promise((resolve, reject) => {
        if ('geolocation' in navigator) {
            navigator.geolocation.getCurrentPosition(resolve, reject);
        } else {
            reject(new Error('Geolocation not supported'));
        }
    });
}

// Local storage helpers
const Storage = {
    set: (key, value) => {
        try {
            localStorage.setItem(key, JSON.stringify(value));
            return true;
        } catch (e) {
            console.error('Failed to save to localStorage:', e);
            return false;
        }
    },
    
    get: (key, defaultValue = null) => {
        try {
            const item = localStorage.getItem(key);
            return item ? JSON.parse(item) : defaultValue;
        } catch (e) {
            console.error('Failed to read from localStorage:', e);
            return defaultValue;
        }
    },
    
    remove: (key) => {
        try {
            localStorage.removeItem(key);
            return true;
        } catch (e) {
            console.error('Failed to remove from localStorage:', e);
            return false;
        }
    }
};

// Export for use in other scripts
window.RentOGo = RentOGo;
window.showAlert = showAlert;
window.showNotification = showNotification;
window.formatCurrency = formatCurrency;
window.formatDate = formatDate;
window.Storage = Storage;