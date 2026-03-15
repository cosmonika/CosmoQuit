<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/Database.php';

// Check admin authentication
if (!isset($_SESSION['admin_id'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username']) && isset($_POST['password'])) {
        $username = $_POST['username'];
        $password = $_POST['password'];
        
        $db = new Database();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("SELECT id, username, password_hash, full_name FROM admin_users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $admin = $result->fetch_assoc();
            
            // Verify password
            if (password_verify($password, $admin['password_hash'])) {
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_name'] = $admin['full_name'];
                $_SESSION['admin_username'] = $admin['username'];
                
                // Update last login
                $update_stmt = $conn->prepare("UPDATE admin_users SET last_login = NOW() WHERE id = ?");
                $update_stmt->bind_param("i", $admin['id']);
                $update_stmt->execute();
                
                header("Location: admin.php");
                exit();
            } else {
                $error = "Invalid username or password";
            }
        } else {
            $error = "Invalid username or password";
        }
    }
    
    // Show login form
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>CosmoQuit - Admin Login</title>
        <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <style>
            :root {
                --primary: #4f46e5;
                --primary-light: #818cf8;
                --primary-dark: #3730a3;
                --danger: #ef4444;
                --gray-50: #f9fafb;
                --gray-700: #374151;
            }

            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: 'Inter', sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }

            .admin-login-container {
                background: white;
                border-radius: 20px;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
                overflow: hidden;
                width: 100%;
                max-width: 400px;
            }

            .admin-login-header {
                background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
                padding: 40px;
                text-align: center;
                color: white;
            }

            .admin-login-header h1 {
                font-size: 24px;
                font-weight: 700;
                margin-bottom: 8px;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 12px;
            }

            .admin-login-header p {
                opacity: 0.9;
                font-size: 14px;
            }

            .admin-login-form {
                padding: 40px;
            }

            .form-group {
                margin-bottom: 24px;
            }

            .form-group label {
                display: block;
                margin-bottom: 8px;
                font-weight: 500;
                color: var(--gray-700);
            }

            .form-input {
                width: 100%;
                padding: 12px 16px;
                border: 2px solid #e5e7eb;
                border-radius: 10px;
                font-size: 16px;
                transition: all 0.3s ease;
            }

            .form-input:focus {
                outline: none;
                border-color: var(--primary);
                box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
            }

            .error-message {
                background-color: #fee2e2;
                color: var(--danger);
                padding: 12px;
                border-radius: 8px;
                margin-bottom: 20px;
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .btn-admin {
                width: 100%;
                padding: 14px;
                background: linear-gradient(135deg, var(--primary-dark) 0%, #312e81 100%);
                color: white;
                border: none;
                border-radius: 10px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s ease;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
            }

            .btn-admin:hover {
                transform: translateY(-2px);
                box-shadow: 0 10px 20px rgba(79, 70, 229, 0.3);
            }

            .back-link {
                text-align: center;
                margin-top: 24px;
                padding-top: 24px;
                border-top: 1px solid #e5e7eb;
            }

            .back-link a {
                color: var(--primary);
                text-decoration: none;
                font-weight: 500;
            }

            .back-link a:hover {
                text-decoration: underline;
            }
        </style>
    </head>
    <body>
        <div class="admin-login-container">
            <div class="admin-login-header">
                <h1>
                    <i data-lucide="shield"></i>
                    CosmoQuit Admin
                </h1>
                <p>Administrator login required</p>
            </div>
            
            <div class="admin-login-form">
                <?php if (isset($error)): ?>
                    <div class="error-message">
                        <i data-lucide="alert-circle" width="20" height="20"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" 
                               id="username" 
                               name="username" 
                               class="form-input" 
                               placeholder="Enter admin username"
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" 
                               id="password" 
                               name="password" 
                               class="form-input" 
                               placeholder="Enter admin password"
                               required>
                    </div>
                    
                    <button type="submit" class="btn-admin">
                        <i data-lucide="log-in" width="20" height="20"></i>
                        Login as Administrator
                    </button>
                </form>
                
                <div class="back-link">
                    <a href="index.php">
                        <i data-lucide="arrow-left" width="16" height="16"></i>
                        Back to User Login
                    </a>
                </div>
            </div>
        </div>
        
        <script>
            lucide.createIcons();
        </script>
    </body>
    </html>
    <?php
    exit();
}

// Admin is authenticated, show admin panel
$db = new Database();
$conn = $db->getConnection();

// Handle user registration
$success = '';
$error = '';
$generated_key = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_user'])) {
    $name = $_POST['name'];
    $email = $_POST['email'] ?? '';
    $mobile = $_POST['mobile'];
    $dob = $_POST['dob'];
    $cigarettes_per_day = intval($_POST['cigarettes_per_day']);
    $cigarette_cost = floatval($_POST['cigarette_cost']);
    $smoking_years = intval($_POST['smoking_years']);
    
    // Validate inputs
    if (empty($name) || empty($mobile) || empty($dob) || $cigarettes_per_day <= 0 || $cigarette_cost <= 0 || $smoking_years <= 0) {
        $error = "Please fill in all required fields with valid values.";
    } else {
        // Generate product key: 3 letters + 2 numbers + 3 letters + 4 numbers
        $key_part1 = generateRandomLetters(3);
        $key_part2 = str_pad(rand(0, 99), 2, '0', STR_PAD_LEFT);
        $key_part3 = generateRandomLetters(3);
        $key_part4 = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
        $product_key = $key_part1 . $key_part2 . $key_part3 . $key_part4;
        
        // Insert user
        $stmt = $conn->prepare("
            INSERT INTO users (product_key, name, email, mobile, dob, cigarettes_per_day, cigarette_cost, smoking_years, created_by_admin_id) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("sssssiidi", 
            $product_key, 
            $name, 
            $email, 
            $mobile, 
            $dob, 
            $cigarettes_per_day, 
            $cigarette_cost, 
            $smoking_years, 
            $_SESSION['admin_id']
        );
        
        if ($stmt->execute()) {
            $success = "User registered successfully!";
            $generated_key = $product_key;
            
            // Clear form
            $_POST = [];
        } else {
            $error = "Failed to register user. Please try again.";
        }
    }
}

// Get all registered users
$users_stmt = $conn->prepare("
    SELECT u.*, up.current_day, up.current_streak, up.total_money_saved 
    FROM users u 
    LEFT JOIN user_progress up ON u.id = up.user_id 
    WHERE u.is_active = 1 
    ORDER BY u.registration_date DESC
");
$users_stmt->execute();
$users = $users_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get statistics
$stats_stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_users,
        SUM(up.total_days_smoke_free) as total_smoke_free_days,
        SUM(up.total_money_saved) as total_money_saved,
        AVG(up.health_score) as avg_health_score
    FROM users u 
    LEFT JOIN user_progress up ON u.id = up.user_id 
    WHERE u.is_active = 1
");
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();

function generateRandomLetters($length) {
    $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $result = '';
    for ($i = 0; $i < $length; $i++) {
        $result .= $letters[rand(0, strlen($letters) - 1)];
    }
    return $result;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CosmoQuit - Admin Panel</title>
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4f46e5;
            --primary-light: #818cf8;
            --primary-dark: #3730a3;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-700: #374151;
            --gray-900: #111827;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8fafc;
            color: var(--gray-900);
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 16px;
        }

        header {
            background-color: white;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 0;
        }

        .logo-container {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo-icon {
            color: var(--primary);
            width: 32px;
            height: 32px;
        }

        .logo-text {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
            letter-spacing: -0.5px;
        }

        .admin-info {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .admin-name {
            font-weight: 600;
            color: var(--gray-700);
        }

        .logout-button {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background-color: var(--gray-100);
            color: var(--gray-700);
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
        }

        .logout-button:hover {
            background-color: var(--danger);
            color: white;
        }

        .main-content {
            padding: 32px 0;
        }

        .page-header {
            margin-bottom: 32px;
        }

        .page-header h1 {
            font-size: 32px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 8px;
        }

        .page-header p {
            color: var(--gray-700);
            font-size: 18px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }

        .stat-card {
            background-color: white;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .stat-card h3 {
            font-size: 14px;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-value {
            font-size: 36px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 8px;
        }

        .stat-description {
            font-size: 14px;
            color: var(--gray-700);
        }

        .admin-panel {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 32px;
        }

        @media (max-width: 1024px) {
            .admin-panel {
                grid-template-columns: 1fr;
            }
        }

        .registration-card, .users-card {
            background-color: white;
            border-radius: 12px;
            padding: 32px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 2px solid var(--gray-100);
        }

        .card-header h2 {
            font-size: 20px;
            font-weight: 700;
            color: var(--gray-900);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--gray-700);
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--gray-200);
            border-radius: 8px;
            font-family: 'Inter', sans-serif;
            font-size: 16px;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .submit-button {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 24px;
        }

        .submit-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(79, 70, 229, 0.3);
        }

        .message {
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .message.success {
            background-color: #d1fae5;
            color: var(--success);
        }

        .message.error {
            background-color: #fee2e2;
            color: var(--danger);
        }

        .message.info {
            background-color: #e0f2fe;
            color: var(--primary);
        }

        .product-key-display {
            text-align: center;
            padding: 20px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            border-radius: 12px;
            color: white;
            margin: 20px 0;
        }

        .product-key-display h3 {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 8px;
            opacity: 0.9;
        }

        .product-key {
            font-size: 24px;
            font-weight: 700;
            letter-spacing: 2px;
            font-family: monospace;
            background: rgba(255, 255, 255, 0.1);
            padding: 12px;
            border-radius: 8px;
            display: inline-block;
            margin: 8px 0;
        }

        .users-table {
            width: 100%;
            border-collapse: collapse;
        }

        .users-table th {
            background-color: var(--gray-50);
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: var(--gray-700);
            border-bottom: 2px solid var(--gray-200);
        }

        .users-table td {
            padding: 12px;
            border-bottom: 1px solid var(--gray-200);
        }

        .users-table tr:hover {
            background-color: var(--gray-50);
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-badge.active {
            background-color: var(--soft-green);
            color: #047857;
        }

        .status-badge.inactive {
            background-color: var(--soft-red);
            color: #b91c1c;
        }

        .user-actions {
            display: flex;
            gap: 8px;
        }

  .action-button {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            transition: all 0.2s ease;
        }

        .action-button.view {
            background-color: var(--primary-light);
            color: white;
        }

        .action-button.view:hover {
            background-color: var(--primary);
        }

        .action-button.reset {
            background-color: var(--warning);
            color: white;
        }

        .action-button.reset:hover {
            background-color: #d97706;
        }

        .action-button.deactivate {
            background-color: var(--danger);
            color: white;
        }

        .action-button.deactivate:hover {
            background-color: #b91c1c;
        }

        .table-container {
            overflow-x: auto;
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 20px;
        }

        .page-button {
            padding: 8px 12px;
            border: 1px solid var(--gray-200);
            background-color: white;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .page-button:hover {
            background-color: var(--gray-100);
        }

        .page-button.active {
            background-color: var(--primary);
            color: white;
            border-color: var(--primary);
        }
    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="header-content">
                <div class="logo-container">
                    <i data-lucide="shield" class="logo-icon"></i>
                    <span class="logo-text">CosmoQuit Admin</span>
                </div>
                
                <div class="admin-info">
                    <span class="admin-name">
                        <i data-lucide="user"></i>
                        <?php echo htmlspecialchars($_SESSION['admin_name']); ?>
                    </span>
                    <a href="logout.php?admin=1" class="logout-button">
                        <i data-lucide="log-out"></i>
                        Logout
                    </a>
                </div>
            </div>
        </div>
    </header>

    <main class="main-content">
        <div class="container">
            <div class="page-header">
                <h1>Administrator Panel</h1>
                <p>Manage users and monitor smoke-free journey progress</p>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <h3>Total Users</h3>
                    <div class="stat-value"><?php echo $stats['total_users'] ?? 0; ?></div>
                    <p class="stat-description">Active registered users</p>
                </div>

                <div class="stat-card">
                    <h3>Total Smoke-Free Days</h3>
                    <div class="stat-value"><?php echo $stats['total_smoke_free_days'] ?? 0; ?></div>
                    <p class="stat-description">Collective achievement</p>
                </div>

                <div class="stat-card">
                    <h3>Total Money Saved</h3>
                    <div class="stat-value">₹<?php echo number_format($stats['total_money_saved'] ?? 0, 2); ?></div>
                    <p class="stat-description">By all users combined</p>
                </div>

                <div class="stat-card">
                    <h3>Average Health Score</h3>
                    <div class="stat-value"><?php echo round($stats['avg_health_score'] ?? 0); ?>%</div>
                    <p class="stat-description">Overall health improvement</p>
                </div>
            </div>

            <div class="admin-panel">
                <!-- Registration Form -->
                <div class="registration-card">
                    <div class="card-header">
                        <i data-lucide="user-plus"></i>
                        <h2>Register New User</h2>
                    </div>

                    <?php if ($success): ?>
                        <div class="message success">
                            <i data-lucide="check-circle"></i>
                            <?php echo htmlspecialchars($success); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                        <div class="message error">
                            <i data-lucide="alert-circle"></i>
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($generated_key): ?>
                        <div class="product-key-display">
                            <h3>Generated Product Key</h3>
                            <div class="product-key"><?php echo $generated_key; ?></div>
                            <p>Share this key with the user for login</p>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <input type="hidden" name="register_user" value="1">
                        
                        <div class="form-group">
                            <label for="name">Full Name *</label>
                            <input type="text" 
                                   id="name" 
                                   name="name" 
                                   class="form-control" 
                                   value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>" 
                                   required>
                        </div>

                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input type="email" 
                                   id="email" 
                                   name="email" 
                                   class="form-control" 
                                   value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label for="mobile">Mobile Number *</label>
                            <input type="tel" 
                                   id="mobile" 
                                   name="mobile" 
                                   class="form-control" 
                                   value="<?php echo isset($_POST['mobile']) ? htmlspecialchars($_POST['mobile']) : ''; ?>" 
                                   required>
                        </div>

                        <div class="form-group">
                            <label for="dob">Date of Birth *</label>
                            <input type="date" 
                                   id="dob" 
                                   name="dob" 
                                   class="form-control" 
                                   value="<?php echo isset($_POST['dob']) ? htmlspecialchars($_POST['dob']) : ''; ?>" 
                                   required>
                        </div>

                        <div class="form-group">
                            <label for="cigarettes_per_day">Cigarettes per Day *</label>
                            <input type="number" 
                                   id="cigarettes_per_day" 
                                   name="cigarettes_per_day" 
                                   class="form-control" 
                                   min="1" 
                                   max="100" 
                                   value="<?php echo isset($_POST['cigarettes_per_day']) ? htmlspecialchars($_POST['cigarettes_per_day']) : ''; ?>" 
                                   required>
                        </div>

                        <div class="form-group">
                            <label for="cigarette_cost">Cost per Cigarette (INR) *</label>
                            <input type="number" 
                                   id="cigarette_cost" 
                                   name="cigarette_cost" 
                                   class="form-control" 
                                   min="1" 
                                   max="100" 
                                   step="0.01" 
                                   value="<?php echo isset($_POST['cigarette_cost']) ? htmlspecialchars($_POST['cigarette_cost']) : ''; ?>" 
                                   required>
                        </div>

                        <div class="form-group">
                            <label for="smoking_years">Years of Smoking *</label>
                            <input type="number" 
                                   id="smoking_years" 
                                   name="smoking_years" 
                                   class="form-control" 
                                   min="1" 
                                   max="100" 
                                   value="<?php echo isset($_POST['smoking_years']) ? htmlspecialchars($_POST['smoking_years']) : ''; ?>" 
                                   required>
                        </div>

                        <button type="submit" class="submit-button">
                            <i data-lucide="user-plus"></i>
                            Register User & Generate Key
                        </button>
                    </form>
                </div>

<!-- Add to admin.php after user registration form -->
<div class="registration-card" style="margin-top: 32px;">
    <div class="card-header">
        <i data-lucide="user-cog"></i>
        <h2>Create Support Executive</h2>
    </div>

    <?php
    // Handle executive creation
    $executive_success = '';
    $executive_error = '';
    $generated_executive_id = '';
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_executive'])) {
        $full_name = $_POST['executive_name'];
        $username = $_POST['executive_username'];
        $email = $_POST['executive_email'];
        $dob = $_POST['executive_dob'];
        $password = $_POST['executive_password'];
        $confirm_password = $_POST['executive_confirm_password'];
        
        // Validate inputs
        if (empty($full_name) || empty($username) || empty($email) || empty($dob) || empty($password)) {
            $executive_error = "Please fill in all required fields.";
        } elseif ($password !== $confirm_password) {
            $executive_error = "Passwords do not match.";
        } elseif (strlen($password) < 8) {
            $executive_error = "Password must be at least 8 characters long.";
        } else {
            // Generate executive ID: 3 letters, 2 numbers, 3 letters, 4 numbers
            $executive_id = generateExecutiveId();
            
            // Hash password
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert executive
            $stmt = $conn->prepare("
                INSERT INTO support_executives 
                (executive_id, username, password_hash, full_name, email, dob, created_by_admin_id) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("ssssssi", 
                $executive_id, 
                $username, 
                $password_hash, 
                $full_name, 
                $email, 
                $dob, 
                $_SESSION['admin_id']
            );
            
            if ($stmt->execute()) {
                $executive_success = "Support executive created successfully!";
                $generated_executive_id = $executive_id;
                
                // Clear form
                $_POST = array();
            } else {
                $executive_error = "Failed to create executive. Username may already exist.";
            }
        }
    }
    
    function generateExecutiveId() {
        $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $id = '';
        
        // 3 letters
        for ($i = 0; $i < 3; $i++) {
            $id .= $letters[rand(0, strlen($letters) - 1)];
        }
        
        // 2 numbers
        $id .= str_pad(rand(0, 99), 2, '0', STR_PAD_LEFT);
        
        // 3 letters
        for ($i = 0; $i < 3; $i++) {
            $id .= $letters[rand(0, strlen($letters) - 1)];
        }
        
        // 4 numbers
        $id .= str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
        
        return $id;
    }
    ?>
    
    <?php if ($executive_success): ?>
        <div class="message success">
            <i data-lucide="check-circle"></i>
            <?php echo htmlspecialchars($executive_success); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($executive_error): ?>
        <div class="message error">
            <i data-lucide="alert-circle"></i>
            <?php echo htmlspecialchars($executive_error); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($generated_executive_id): ?>
        <div class="product-key-display" style="background: linear-gradient(135deg, var(--warning) 0%, #D97706 100%);">
            <h3>Generated Executive ID</h3>
            <div class="product-key"><?php echo $generated_executive_id; ?></div>
            <p>Share this ID with the executive for login</p>
        </div>
    <?php endif; ?>
    
    <form method="POST" action="" style="margin-top: 20px;">
        <input type="hidden" name="create_executive" value="1">
        
        <div class="form-group">
            <label for="executive_name">Full Name *</label>
            <input type="text" 
                   id="executive_name" 
                   name="executive_name" 
                   class="form-control" 
                   value="<?php echo isset($_POST['executive_name']) ? htmlspecialchars($_POST['executive_name']) : ''; ?>" 
                   required>
        </div>
        
        <div class="form-group">
            <label for="executive_username">Username *</label>
            <input type="text" 
                   id="executive_username" 
                   name="executive_username" 
                   class="form-control" 
                   value="<?php echo isset($_POST['executive_username']) ? htmlspecialchars($_POST['executive_username']) : ''; ?>" 
                   required>
        </div>
        
        <div class="form-group">
            <label for="executive_email">Email *</label>
            <input type="email" 
                   id="executive_email" 
                   name="executive_email" 
                   class="form-control" 
                   value="<?php echo isset($_POST['executive_email']) ? htmlspecialchars($_POST['executive_email']) : ''; ?>" 
                   required>
        </div>
        
        <div class="form-group">
            <label for="executive_dob">Date of Birth *</label>
            <input type="date" 
                   id="executive_dob" 
                   name="executive_dob" 
                   class="form-control" 
                   value="<?php echo isset($_POST['executive_dob']) ? htmlspecialchars($_POST['executive_dob']) : ''; ?>" 
                   required>
        </div>
        
        <div class="form-group">
            <label for="executive_password">Password *</label>
            <input type="password" 
                   id="executive_password" 
                   name="executive_password" 
                   class="form-control" 
                   required>
        </div>
        
        <div class="form-group">
            <label for="executive_confirm_password">Confirm Password *</label>
            <input type="password" 
                   id="executive_confirm_password" 
                   name="executive_confirm_password" 
                   class="form-control" 
                   required>
        </div>
        
        <button type="submit" class="submit-button" style="background: linear-gradient(135deg, var(--warning) 0%, #D97706 100%);">
            <i data-lucide="user-plus"></i>
            Create Support Executive
        </button>
    </form>
</div>

                <!-- Users List -->
                <div class="users-card">
                    <div class="card-header">
                        <i data-lucide="users"></i>
                        <h2>Registered Users (<?php echo count($users); ?>)</h2>
                    </div>

                    <?php if (empty($users)): ?>
                        <div class="message info">
                            <i data-lucide="info"></i>
                            No users registered yet. Register a new user to get started.
                        </div>
                    <?php else: ?>
                        <div class="table-container">
                            <table class="users-table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Product Key</th>
                                        <th>Current Day</th>
                                        <th>Money Saved</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $user): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($user['name']); ?></strong><br>
                                                <small style="color: var(--gray-700);">
                                                    <?php echo htmlspecialchars($user['mobile']); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <code style="font-family: monospace;"><?php echo $user['product_key']; ?></code>
                                            </td>
                                            <td>
                                                Day <?php echo $user['current_day']; ?><br>
                                                <small>Streak: <?php echo $user['current_streak']; ?> days</small>
                                            </td>
                                            <td>
                                                ₹<?php echo number_format($user['total_money_saved'], 2); ?>
                                            </td>
                                            <td>
                                                <span class="status-badge active">Active</span>
                                            </td>
                                            <td>
                                                <div class="user-actions">
                                                    <button class="action-button view" 
                                                            onclick="viewUserDetails(<?php echo $user['id']; ?>)">
                                                        <i data-lucide="eye" width="12" height="12"></i> View
                                                    </button>
                                                    <button class="action-button reset"
                                                            onclick="resetUserProgress(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['name']); ?>')">
                                                        <i data-lucide="refresh-cw" width="12" height="12"></i> Reset
                                                    </button>
                                                    <button class="action-button deactivate"
                                                            onclick="deactivateUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['name']); ?>')">
                                                        <i data-lucide="user-x" width="12" height="12"></i> Deactivate
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="pagination">
                            <button class="page-button active">1</button>
                            <button class="page-button">2</button>
                            <button class="page-button">3</button>
                            <button class="page-button">Next</button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script>
        lucide.createIcons();
        
        function viewUserDetails(userId) {
            alert('Viewing user details for ID: ' + userId + '\n(Would open detailed view in production)');
        }
        
        function resetUserProgress(userId, userName) {
            if (confirm('Reset progress for ' + userName + '?\nThis will set their current day to 0 and reset their streak.')) {
                fetch('api/admin_reset_progress.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ user_id: userId })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Progress reset successfully!');
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                });
            }
        }
        
        function deactivateUser(userId, userName) {
            if (confirm('Deactivate ' + userName + '?\nThey will no longer be able to login.')) {
                fetch('api/admin_deactivate_user.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ user_id: userId })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('User deactivated successfully!');
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                });
            }
        }
        
        // Auto-refresh user list every 30 seconds
        setInterval(() => {
            // In production, this would refresh the table via AJAX
            console.log('Auto-refresh users list...');
        }, 30000);
    </script>
</body>
</html>