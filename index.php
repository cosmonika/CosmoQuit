<?php

require_once 'includes/session.php';
require_once 'includes/config.php';
require_once 'includes/Database.php';

// Check if already logged in via 30-day auto-login
if (!isset($_SESSION['user_id'])) {
    require_once 'includes/auth.php';
    if (checkPersistentLogin()) {
        header("Location: home.php");
        exit();
    }
}

if (isset($_SESSION['user_id'])) {
    header("Location: home.php");
    exit();
}

$error = '';
$success = '';

// Handle user registration from popup
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['register_user'])) {
        // Registration form submitted
        $name = trim($_POST['name']);
        $email = trim($_POST['email'] ?? '');
        $mobile = trim($_POST['mobile']);
        $dob = $_POST['dob'];
        $cigarettes_per_day = intval($_POST['cigarettes_per_day']);
        $cigarette_cost = floatval($_POST['cigarette_cost']);
        $smoking_years = intval($_POST['smoking_years']);
        
        // Store form data in session to repopulate form if there's an error
        $_SESSION['form_data'] = [
            'name' => $name,
            'email' => $email,
            'mobile' => $mobile,
            'dob' => $dob,
            'cigarettes_per_day' => $cigarettes_per_day,
            'cigarette_cost' => $cigarette_cost,
            'smoking_years' => $smoking_years
        ];
        
        // Validate inputs
        if (empty($name) || empty($mobile) || empty($dob) || $cigarettes_per_day <= 0 || $cigarette_cost <= 0 || $smoking_years <= 0) {
            $_SESSION['registration_error'] = "Please fill in all required fields with valid values.";
            header("Location: index.php");
            exit();
        } else {
            $db = new Database();
            $conn = $db->getConnection();
            
            // Check if email already exists
            if (!empty($email)) {
                $check_email = $conn->prepare("SELECT id FROM users WHERE email = ? AND is_active = 1");
                $check_email->bind_param("s", $email);
                $check_email->execute();
                $check_email->store_result();
                
                if ($check_email->num_rows > 0) {
                    $_SESSION['registration_error'] = "An account with this email already exists. Please login.";
                    header("Location: index.php");
                    exit();
                }
            }
            
            // Check if mobile already exists
            $check_mobile = $conn->prepare("SELECT id FROM users WHERE mobile = ? AND is_active = 1");
            $check_mobile->bind_param("s", $mobile);
            $check_mobile->execute();
            $check_mobile->store_result();
            
            if ($check_mobile->num_rows > 0) {
                $_SESSION['registration_error'] = "An account with this mobile number already exists. Please login.";
                header("Location: index.php");
                exit();
            }
            
            // Generate product key: 3 letters + 2 numbers + 3 letters + 4 numbers
            function generateRandomLetters($length) {
                $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
                $result = '';
                for ($i = 0; $i < $length; $i++) {
                    $result .= $letters[rand(0, strlen($letters) - 1)];
                }
                return $result;
            }
            
            $key_part1 = generateRandomLetters(3);
            $key_part2 = str_pad(rand(0, 99), 2, '0', STR_PAD_LEFT);
            $key_part3 = generateRandomLetters(3);
            $key_part4 = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
            $product_key = $key_part1 . $key_part2 . $key_part3 . $key_part4;
            
            // Insert user with admin_id = 0 (self-registration)
            $stmt = $conn->prepare("
                INSERT INTO users (product_key, name, email, mobile, dob, cigarettes_per_day, cigarette_cost, smoking_years, created_by_admin_id) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $admin_id = 0; // 0 for self-registration
            $stmt->bind_param("sssssiidi", 
                $product_key, 
                $name, 
                $email, 
                $mobile, 
                $dob, 
                $cigarettes_per_day, 
                $cigarette_cost, 
                $smoking_years,
                $admin_id
            );
            
            if ($stmt->execute()) {
                // Get the inserted user ID
                $user_id = $conn->insert_id;
                
                // Initialize user progress
                $progress_stmt = $conn->prepare("
                    INSERT INTO user_progress (user_id, current_day, current_streak, total_days_smoke_free, total_money_saved, health_score) 
                    VALUES (?, 1, 0, 0, 0, 0)
                ");
                $progress_stmt->bind_param("i", $user_id);
                $progress_stmt->execute();
                
                // Store the generated key in session to show in popup
                $_SESSION['generated_key'] = $product_key;
                $_SESSION['registration_success'] = true;
                
                // Clear form data from session
                unset($_SESSION['form_data']);
                
                header("Location: index.php");
                exit();
            } else {
                $_SESSION['registration_error'] = "Failed to create account. Please try again.";
                header("Location: index.php");
                exit();
            }
        }
    } elseif (isset($_POST['product_key'])) {
        // Original login logic
        $product_key = trim($_POST['product_key']);
        
        if (empty($product_key)) {
            $error = "Please enter your product key";
        } else {
            $db = new Database();
            $conn = $db->getConnection();
            
            // Check if product key exists and user is active
            $stmt = $conn->prepare("SELECT id, name, profile_picture FROM users WHERE product_key = ? AND is_active = 1");
            $stmt->bind_param("s", $product_key);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                
                // Create session
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['profile_picture'] = $user['profile_picture'];
                $_SESSION['product_key'] = $product_key;
                
                // Update last login
                $update_stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                $update_stmt->bind_param("i", $user['id']);
                $update_stmt->execute();
                
                // Create session record
                $session_token = bin2hex(random_bytes(32));
                $ip_address = $_SERVER['REMOTE_ADDR'];
                $user_agent = $_SERVER['HTTP_USER_AGENT'];
                
                $session_stmt = $conn->prepare("INSERT INTO user_sessions (user_id, session_token, product_key, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
                $session_stmt->bind_param("issss", $user['id'], $session_token, $product_key, $ip_address, $user_agent);
                $session_stmt->execute();
                
                $_SESSION['session_token'] = $session_token;
                
                
                setcookie(
    'cosmoquit_login',
    $session_token,
    time() + (60 * 60 * 24 * 30),
    '/',
    '',
    false,
    true
);
                
                
                // ALWAYS set 30-day persistent login (no option, always enabled)
                setPersistentLogin($user['id'], $product_key);
                
                setForeverCookie($user['id'], $product_key);
                
                header("Location: home.php");
                exit();
            } else {
                $error = "Invalid product key or account is inactive";
            }
        }
    }
}

// Check for registration messages in session
$registration_error = '';
$registration_success = false;
$generated_key = '';
$form_data = [];

if (isset($_SESSION['registration_error'])) {
    $registration_error = $_SESSION['registration_error'];
    unset($_SESSION['registration_error']);
}

if (isset($_SESSION['registration_success'])) {
    $registration_success = $_SESSION['registration_success'];
    unset($_SESSION['registration_success']);
}

if (isset($_SESSION['generated_key'])) {
    $generated_key = $_SESSION['generated_key'];
    unset($_SESSION['generated_key']);
}

if (isset($_SESSION['form_data'])) {
    $form_data = $_SESSION['form_data'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CosmoQuit - Login</title>
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/auth.css">

<style>
    :root {
        /* Purple Theme - Deep & Calming */
        --primary: #8B5CF6;
        --primary-light: #A78BFA;
        --primary-dark: #7C3AED;
        --primary-soft: rgba(139, 92, 246, 0.1);
        
        /* Dark Backgrounds */
        --main-bg: #0F0B16;
        --card-bg: rgba(30, 27, 36, 0.85);
        --card-border: rgba(139, 92, 246, 0.2);
        
        /* Text Colors */
        --text-primary: #F3E8FF;
        --text-muted: #C4B5FD;
        --text-light: #A78BFA;
        
        /* Status Colors */
        --success: #10B981;
        --warning: #F59E0B;
        --danger: #EF4444;
        
        /* WhatsApp Colors */
        --whatsapp-green: #25D366;
        --whatsapp-green-dark: #059669;
        
        /* Blue Accent */
        --soft-blue: #3B82F6;
        --soft-blue-dark: #2563EB;
        
        /* Glass Effects */
        --glass-bg: rgba(30, 27, 36, 0.75);
        --glass-border: rgba(139, 92, 246, 0.15);
        --glass-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        --glass-backdrop: blur(12px);
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'Inter', sans-serif;
        background: var(--main-bg);
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
        position: relative;
        overflow-x: hidden;
        color: var(--text-primary);
    }

    /* Subtle background pattern */
    body::before {
        content: '';
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-image: 
            radial-gradient(circle at 10% 20%, rgba(139, 92, 246, 0.03) 0%, transparent 20%),
            radial-gradient(circle at 90% 80%, rgba(167, 139, 250, 0.02) 0%, transparent 20%);
        z-index: -1;
        pointer-events: none;
    }

    .login-container {
        background: var(--glass-bg);
        backdrop-filter: var(--glass-backdrop);
        -webkit-backdrop-filter: var(--glass-backdrop);
        border-radius: 20px;
        box-shadow: var(--glass-shadow);
        overflow: hidden;
        width: 100%;
        max-width: 420px;
        border: 1px solid var(--glass-border);
        position: relative;
        z-index: 1;
    }

    /* Foggy glass effect */
    .login-container::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: 
            radial-gradient(circle at 20% 80%, rgba(139, 92, 246, 0.08) 0%, transparent 60%),
            radial-gradient(circle at 80% 20%, rgba(167, 139, 250, 0.05) 0%, transparent 60%);
        border-radius: 20px;
        z-index: -1;
        pointer-events: none;
    }

    .login-header {
        background: rgba(139, 92, 246, 0.1);
        padding: 32px;
        text-align: center;
        color: var(--text-primary);
        border-bottom: 1px solid var(--glass-border);
        position: relative;
        backdrop-filter: blur(10px);
    }

    /* Header subtle gradient */
    .login-header::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(135deg, rgba(139, 92, 246, 0.05) 0%, transparent 100%);
        z-index: 0;
        pointer-events: none;
    }

    .logo-container {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        margin-bottom: 16px;
        position: relative;
        z-index: 1;
    }

    .logo-icon {
        width: 36px;
        height: 36px;
        color: var(--primary);
    }

    .logo-text {
        font-size: 28px;
        font-weight: 700;
        letter-spacing: -0.5px;
        background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .login-header h1 {
        font-size: 20px;
        font-weight: 600;
        margin-bottom: 8px;
        position: relative;
        z-index: 1;
    }

    .login-header p {
        opacity: 0.8;
        font-size: 14px;
        position: relative;
        z-index: 1;
        max-width: 300px;
        margin: 0 auto;
        line-height: 1.5;
        color: var(--text-muted);
    }

    .login-form {
        padding: 32px;
    }

    .form-group {
        margin-bottom: 24px;
    }

    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 500;
        color: var(--text-muted);
        font-size: 14px;
    }

    .form-input {
        width: 100%;
        padding: 14px;
        background: rgba(30, 27, 36, 0.6);
        border: 1px solid var(--glass-border);
        border-radius: 10px;
        font-size: 15px;
        transition: all 0.3s ease;
        color: var(--text-primary);
        font-family: 'Inter', sans-serif;
    }

    .form-input::placeholder {
        color: var(--text-muted);
        opacity: 0.6;
    }

    .form-input:focus {
        outline: none;
        border-color: var(--primary);
        background: rgba(30, 27, 36, 0.8);
        box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
    }

    .error-message {
        background: rgba(239, 68, 68, 0.1);
        color: #FCA5A5;
        padding: 14px;
        border-radius: 10px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
        border: 1px solid rgba(239, 68, 68, 0.2);
        font-size: 14px;
    }

    .success-message {
        background: rgba(16, 185, 129, 0.1);
        color: #A7F3D0;
        padding: 14px;
        border-radius: 10px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
        border: 1px solid rgba(16, 185, 129, 0.2);
        font-size: 14px;
    }

    .btn {
        width: 100%;
        padding: 15px;
        border: none;
        border-radius: 10px;
        font-size: 15px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        text-decoration: none;
        font-family: 'Inter', sans-serif;
    }

    .btn-primary {
        background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
        color: white;
        margin-bottom: 16px;
        border: 1px solid rgba(139, 92, 246, 0.3);
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(139, 92, 246, 0.2);
    }

    .btn-create-key {
        background: linear-gradient(135deg, var(--soft-blue) 0%, var(--soft-blue-dark) 100%);
        color: white;
        margin-bottom: 12px;
        border: 1px solid rgba(59, 130, 246, 0.3);
    }

    .btn-create-key:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(59, 130, 246, 0.2);
    }

    .btn-whatsapp {
        background: linear-gradient(135deg, var(--whatsapp-green) 0%, var(--whatsapp-green-dark) 100%);
        color: white;
        border: 1px solid rgba(37, 211, 102, 0.3);
    }

    .btn-whatsapp:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(37, 211, 102, 0.2);
    }

    .action-buttons {
        margin-bottom: 24px;
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .auto-login-note {
        text-align: center;
        padding: 14px;
        background: rgba(139, 92, 246, 0.08);
        border-radius: 10px;
        font-size: 13px;
        color: var(--text-muted);
        margin-bottom: 24px;
        line-height: 1.5;
        border: 1px solid var(--glass-border);
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 6px;
    }

    .auto-login-note i {
        width: 18px;
        height: 18px;
        color: var(--primary);
    }

    /* Modal Styles - FIXED */
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.7);
        backdrop-filter: blur(8px);
        z-index: 1000;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }

    .modal-content {
        background: var(--glass-bg);
        backdrop-filter: var(--glass-backdrop);
        -webkit-backdrop-filter: var(--glass-backdrop);
        border-radius: 16px;
        width: 100%;
        max-width: 500px;
        max-height: 85vh;
        overflow-y: auto;
        box-shadow: var(--glass-shadow);
        border: 1px solid var(--glass-border);
        position: relative;
    }

    /* Hide scrollbar but keep functionality */
    .modal-content::-webkit-scrollbar {
        display: none;
    }
    
    .modal-content {
        -ms-overflow-style: none;
        scrollbar-width: none;
    }

    .modal-header {
        padding: 24px;
        background: rgba(139, 92, 246, 0.1);
        color: var(--text-primary);
        border-radius: 16px 16px 0 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid var(--glass-border);
        position: sticky;
        top: 0;
        backdrop-filter: blur(10px);
        z-index: 10;
    }

    .modal-header h2 {
        font-size: 18px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 10px;
        color: var(--text-primary);
        margin: 0;
    }

    .close-modal {
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid var(--glass-border);
        color: var(--text-muted);
        font-size: 22px;
        cursor: pointer;
        padding: 0;
        width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        transition: all 0.2s ease;
        flex-shrink: 0;
        line-height: 1;
    }

    .close-modal:hover {
        background: rgba(255, 255, 255, 0.15);
        color: var(--text-primary);
        border-color: var(--primary);
    }

    .modal-body {
        padding: 24px;
    }

    .modal-form .form-group {
        margin-bottom: 20px;
    }

    .modal-form label {
        display: block;
        margin-bottom: 6px;
        font-weight: 500;
        color: var(--text-muted);
        font-size: 14px;
    }

    .modal-form label .required {
        color: #FCA5A5;
        margin-left: 2px;
    }

    .modal-form .form-control {
        width: 100%;
        padding: 12px;
        background: rgba(30, 27, 36, 0.6);
        border: 1px solid var(--glass-border);
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.2s;
        color: var(--text-primary);
        font-family: 'Inter', sans-serif;
    }

    .modal-form .form-control:focus {
        outline: none;
        border-color: var(--primary);
        background: rgba(30, 27, 36, 0.8);
        box-shadow: 0 0 0 2px rgba(139, 92, 246, 0.1);
    }

    /* Date input styling - remove default calendar icon and spinner */
    .modal-form input[type="date"]::-webkit-calendar-picker-indicator {
        filter: invert(0.8) sepia(1) saturate(5) hue-rotate(230deg);
        cursor: pointer;
    }
    
    /* Remove number input spinner */
    .modal-form input[type="number"]::-webkit-inner-spin-button,
    .modal-form input[type="number"]::-webkit-outer-spin-button {
        -webkit-appearance: none;
        margin: 0;
    }
    
    .modal-form input[type="number"] {
        -moz-appearance: textfield;
    }

    .modal-footer {
        padding: 20px 24px;
        background: rgba(30, 27, 36, 0.5);
        border-radius: 0 0 16px 16px;
        text-align: center;
        border-top: 1px solid var(--glass-border);
    }

    .key-display {
        background: rgba(139, 92, 246, 0.1);
        border-radius: 12px;
        padding: 24px;
        text-align: center;
        color: var(--text-primary);
        margin: 20px 0;
        border: 1px solid var(--glass-border);
    }

    .key-display h3 {
        font-size: 16px;
        font-weight: 600;
        margin-bottom: 12px;
        color: var(--primary);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    .product-key-value {
        font-size: 24px;
        font-weight: 700;
        letter-spacing: 2px;
        font-family: 'Courier New', monospace;
        background: rgba(139, 92, 246, 0.15);
        padding: 16px;
        border-radius: 10px;
        margin: 12px 0;
        display: block;
        word-break: break-all;
        color: var(--text-primary);
        border: 1px solid var(--glass-border);
    }

    .key-warning {
        background: rgba(245, 158, 11, 0.1);
        border: 1px solid rgba(245, 158, 11, 0.2);
        border-radius: 10px;
        padding: 12px;
        margin: 16px 0;
        text-align: center;
        font-size: 13px;
        color: #FCD34D;
    }

    .copy-key-btn {
        background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        margin-top: 12px;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-family: 'Inter', sans-serif;
        border: 1px solid rgba(139, 92, 246, 0.3);
    }

    .copy-key-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 16px rgba(139, 92, 246, 0.2);
    }

    .copy-success {
        color: var(--success);
        font-size: 13px;
        margin-top: 8px;
        display: none;
        align-items: center;
        justify-content: center;
        gap: 6px;
    }

    .modal-error {
        background: rgba(239, 68, 68, 0.1);
        color: #FCA5A5;
        padding: 14px;
        border-radius: 10px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
        border: 1px solid rgba(239, 68, 68, 0.2);
        font-size: 14px;
    }

    /* Responsive Design */
    @media (max-width: 480px) {
        body {
            padding: 16px;
        }
        
        .login-container {
            border-radius: 16px;
        }
        
        .login-header {
            padding: 24px;
        }
        
        .login-form {
            padding: 24px;
        }
        
        .logo-text {
            font-size: 24px;
        }
        
        .logo-icon {
            width: 32px;
            height: 32px;
        }
        
        .login-header h1 {
            font-size: 18px;
        }
        
        .modal-content {
            margin: 0;
            max-height: 90vh;
        }
        
        .modal-header,
        .modal-body,
        .modal-footer {
            padding: 20px;
        }
        
        .product-key-value {
            font-size: 20px;
            letter-spacing: 1px;
            padding: 14px;
        }
        
        .btn {
            padding: 14px;
        }
    }

    @media (max-width: 360px) {
        .logo-text {
            font-size: 22px;
        }
        
        .login-header h1 {
            font-size: 16px;
        }
        
        .product-key-value {
            font-size: 18px;
        }
        
        .modal-header h2 {
            font-size: 16px;
        }
    }

    /* Animations */
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    @keyframes modalSlideIn {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* Apply animations */
    .error-message,
    .success-message,
    .modal-error {
        animation: fadeIn 0.3s ease;
    }

    .modal-content {
        animation: modalSlideIn 0.3s ease;
    }
</style>


</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="logo-container">
                <i data-lucide="flame" class="logo-icon"></i>
                <span class="logo-text">CosmoQuit</span>
            </div>
            <h1>Welcome Back</h1>
            <p>Enter your product key to continue your smoke-free journey</p>
        </div>
        
        <div class="login-form">
            <?php if ($error): ?>
                <div class="error-message">
                    <i data-lucide="alert-circle" width="20" height="20"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="product_key">Product Key</label>
                    <input type="text" 
                           id="product_key" 
                           name="product_key" 
                           class="form-input" 
                           placeholder="Enter your product key (e.g., ABC12XYZ3456)"
                           required
                           value="<?php echo isset($_POST['product_key']) ? htmlspecialchars($_POST['product_key']) : ''; ?>">
                </div>
                
                <!-- Action Buttons Section (BETWEEN product key and the note) -->
                <div class="action-buttons">
                    <button type="button" class="btn btn-create-key" id="createAccountBtn">
                        <i data-lucide="key" width="20" height="20"></i>
                        Create Your Private Key
                    </button>
                    
                    <a href="https://wa.me/919751191709?text=Hello%21%20I%20need%20help%20with%20onboarding%20to%20CosmoQuit%2E" 
                       target="_blank" 
                       class="btn btn-whatsapp">
                        <i class="bi bi-whatsapp" style="font-size: 20px;"></i>
                        Need Help With Onboarding?
                    </a>
                </div>
                
                <div class="auto-login-note">
                    <i data-lucide="shield-check"></i>
                    <br>
                    Your quit journey is personal. We keep it private and secure for you.
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <i data-lucide="log-in" width="20" height="20"></i>
                    Login to CosmoQuit
                </button>
            </form>
        </div>
    </div>
    
    <!-- Registration Modal -->
    <div class="modal" id="registrationModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>
                    <i data-lucide="key" width="24" height="24"></i>
                    Create Your Private Key
                </h2>
                <button class="close-modal" id="closeModal">&times;</button>
            </div>
            
            <div class="modal-body">
                <?php if ($registration_success && $generated_key): ?>
                    <div class="key-display">
                        <h3><i data-lucide="check-circle" width="20" height="20"></i> Account Created Successfully!</h3>
                        <p>Your unique product key has been generated:</p>
                        <div class="product-key-value" id="generatedKey">
                            <?php echo htmlspecialchars($generated_key); ?>
                        </div>
                        <div class="key-warning">
                            <i data-lucide="alert-triangle" width="16" height="16"></i>
                            <strong>IMPORTANT:</strong> Save this key securely! You'll need it to login.
                        </div>
                        <button class="copy-key-btn" id="copyKeyBtn">
                            <i data-lucide="copy" width="16" height="16"></i>
                            Copy Key to Clipboard
                        </button>
                        <div class="copy-success" id="copySuccess">
                            <i data-lucide="check" width="16" height="16"></i>
                            Key copied successfully!
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-primary" id="continueToLogin">
                            <i data-lucide="log-in" width="16" height="16"></i>
                            Continue to Login
                        </button>
                    </div>
                    
                <?php else: ?>
                    <?php if ($registration_error): ?>
                        <div class="modal-error">
                            <i data-lucide="alert-circle" width="20" height="20"></i>
                            <?php echo htmlspecialchars($registration_error); ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" action="" class="modal-form" id="registrationForm">
                        <input type="hidden" name="register_user" value="1">
                        
                        <div class="form-group">
                            <label for="name">Full Name <span class="required">*</span></label>
                            <input type="text" 
                                   id="name" 
                                   name="name" 
                                   class="form-control" 
                                   required
                                   placeholder="Enter your full name"
                                   value="<?php echo isset($form_data['name']) ? htmlspecialchars($form_data['name']) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" 
                                   id="email" 
                                   name="email" 
                                   class="form-control" 
                                   placeholder="Enter your email (optional)"
                                   value="<?php echo isset($form_data['email']) ? htmlspecialchars($form_data['email']) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label for="mobile">Mobile Number <span class="required">*</span></label>
                            <input type="tel" 
                                   id="mobile" 
                                   name="mobile" 
                                   class="form-control" 
                                   required
                                   placeholder="Enter your mobile number"
                                   value="<?php echo isset($form_data['mobile']) ? htmlspecialchars($form_data['mobile']) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label for="dob">Date of Birth <span class="required">*</span></label>
                            <input type="date" 
                                   id="dob" 
                                   name="dob" 
                                   class="form-control" 
                                   required
                                   value="<?php echo isset($form_data['dob']) ? htmlspecialchars($form_data['dob']) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label for="cigarettes_per_day">Cigarettes per Day <span class="required">*</span></label>
                            <input type="number" 
                                   id="cigarettes_per_day" 
                                   name="cigarettes_per_day" 
                                   class="form-control" 
                                   min="1" 
                                   max="100" 
                                   required
                                   placeholder="Enter number of cigarettes"
                                   value="<?php echo isset($form_data['cigarettes_per_day']) ? htmlspecialchars($form_data['cigarettes_per_day']) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label for="cigarette_cost">Cost per Cigarette (INR) <span class="required">*</span></label>
                            <input type="number" 
                                   id="cigarette_cost" 
                                   name="cigarette_cost" 
                                   class="form-control" 
                                   min="1" 
                                   max="100" 
                                   step="0.01" 
                                   required
                                   placeholder="Enter cost per cigarette"
                                   value="<?php echo isset($form_data['cigarette_cost']) ? htmlspecialchars($form_data['cigarette_cost']) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label for="smoking_years">Years of Smoking <span class="required">*</span></label>
                            <input type="number" 
                                   id="smoking_years" 
                                   name="smoking_years" 
                                   class="form-control" 
                                   min="1" 
                                   max="100" 
                                   required
                                   placeholder="Enter years of smoking"
                                   value="<?php echo isset($form_data['smoking_years']) ? htmlspecialchars($form_data['smoking_years']) : ''; ?>">
                        </div>
                        
                        <div class="modal-footer">
                            <button type="submit" class="btn btn-primary">
                                <i data-lucide="key" width="16" height="16"></i>
                                Generate My Private Key
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();
        
        // Focus on input field
        document.getElementById('product_key').focus();
        
        // Auto-focus and select if error
        <?php if ($error && !isset($_POST['register_user'])): ?>
            document.getElementById('product_key').select();
        <?php endif; ?>
        
        // Modal functionality
        const modal = document.getElementById('registrationModal');
        const createAccountBtn = document.getElementById('createAccountBtn');
        const closeModalBtn = document.getElementById('closeModal');
        const copyKeyBtn = document.getElementById('copyKeyBtn');
        const copySuccess = document.getElementById('copySuccess');
        const continueToLogin = document.getElementById('continueToLogin');
        
        // Open modal when Create Account button is clicked
        if (createAccountBtn) {
            createAccountBtn.addEventListener('click', () => {
                modal.style.display = 'flex';
                document.body.style.overflow = 'hidden';
                
                // If showing form, focus on first input
                const firstInput = document.querySelector('.modal-form input');
                if (firstInput) {
                    firstInput.focus();
                }
            });
        }
        
        // Close modal
        if (closeModalBtn) {
            closeModalBtn.addEventListener('click', () => {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
            });
        }
        
        // Close modal when clicking outside
        window.addEventListener('click', (event) => {
            if (event.target === modal) {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        });
        
        // Copy key functionality
        if (copyKeyBtn) {
            copyKeyBtn.addEventListener('click', () => {
                const keyElement = document.getElementById('generatedKey');
                const keyText = keyElement.textContent;
                
                navigator.clipboard.writeText(keyText).then(() => {
                    copySuccess.style.display = 'block';
                    copyKeyBtn.innerHTML = '<i data-lucide="check" width="16" height="16"></i> Key Copied!';
                    copyKeyBtn.style.backgroundColor = '#10b981';
                    copyKeyBtn.style.color = 'white';
                    
                    setTimeout(() => {
                        copySuccess.style.display = 'none';
                        copyKeyBtn.innerHTML = '<i data-lucide="copy" width="16" height="16"></i> Copy Key to Clipboard';
                        copyKeyBtn.style.backgroundColor = '';
                        copyKeyBtn.style.color = '';
                    }, 3000);
                });
            });
        }
        
        // Continue to login button
        if (continueToLogin) {
            continueToLogin.addEventListener('click', () => {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
                document.getElementById('product_key').focus();
                
                // Auto-fill the product key if available
                <?php if ($generated_key): ?>
                    document.getElementById('product_key').value = '<?php echo $generated_key; ?>';
                    document.getElementById('product_key').select();
                <?php endif; ?>
            });
        }
        
        // Set max date for DOB (minimum 18 years old)
        const dobInput = document.getElementById('dob');
        if (dobInput) {
            const today = new Date();
            const maxDate = new Date(today.getFullYear() - 18, today.getMonth(), today.getDate());
            dobInput.max = maxDate.toISOString().split('T')[0];
            
            // Set min date (100 years ago)
            const minDate = new Date(today.getFullYear() - 100, today.getMonth(), today.getDate());
            dobInput.min = minDate.toISOString().split('T')[0];
        }
        
        // Form validation
        const registrationForm = document.getElementById('registrationForm');
        if (registrationForm) {
            registrationForm.addEventListener('submit', (e) => {
                const dob = document.getElementById('dob').value;
                const dobDate = new Date(dob);
                const today = new Date();
                const minAgeDate = new Date(today.getFullYear() - 18, today.getMonth(), today.getDate());
                
                if (dobDate > minAgeDate) {
                    e.preventDefault();
                    alert('You must be at least 18 years old to register.');
                    return false;
                }
                
                return true;
            });
        }
        
        // Open modal automatically if there was a registration attempt
        <?php if ($registration_error || $registration_success): ?>
            document.addEventListener('DOMContentLoaded', () => {
                modal.style.display = 'flex';
                document.body.style.overflow = 'hidden';
            });
        <?php endif; ?>
    </script>
</body>
</html>