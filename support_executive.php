<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/Database.php';

// Check if already logged in
if (isset($_SESSION['executive_id'])) {
    header("Location: executive_dashboard.php");
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $executive_id = $_POST['executive_id'] ?? '';
    
    if (empty($username) || empty($password) || empty($executive_id)) {
        $error = "All fields are required.";
    } else {
        $db = new Database();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("
            SELECT id, executive_id, username, password_hash, full_name, is_active 
            FROM support_executives 
            WHERE username = ? AND executive_id = ? AND is_active = 1
        ");
        $stmt->bind_param("ss", $username, $executive_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $executive = $result->fetch_assoc();
            
            if (password_verify($password, $executive['password_hash'])) {
                $_SESSION['executive_id'] = $executive['id'];
                $_SESSION['executive_name'] = $executive['full_name'];
                $_SESSION['executive_username'] = $executive['username'];
                
                // Update last login
                $update_stmt = $conn->prepare("
                    UPDATE support_executives 
                    SET last_login = NOW() 
                    WHERE id = ?
                ");
                $update_stmt->bind_param("i", $executive['id']);
                $update_stmt->execute();
                
                header("Location: executive_dashboard.php");
                exit();
            } else {
                $error = "Invalid credentials.";
            }
        } else {
            $error = "Invalid executive ID or username.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Executive Login</title>
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #8B5CF6;
            --primary-light: #A78BFA;
            --primary-dark: #7C3AED;
            --main-bg: #0F0B16;
            --card-bg: rgba(30, 27, 36, 0.9);
            --glass-border: rgba(139, 92, 246, 0.15);
            --text-primary: #F3E8FF;
            --text-muted: #C4B5FD;
            --success: #10B981;
            --danger: #EF4444;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--main-bg);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            background: var(--card-bg);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            border: 1px solid var(--glass-border);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 400px;
            overflow: hidden;
        }

        .login-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            padding: 40px;
            text-align: center;
        }

        .login-header h1 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
        }

        .login-header p {
            opacity: 0.9;
            font-size: 14px;
        }

        .login-form {
            padding: 40px;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-muted);
        }

        .form-input {
            width: 100%;
            padding: 12px 16px;
            background: rgba(30, 27, 36, 0.8);
            border: 1px solid var(--glass-border);
            border-radius: 10px;
            color: var(--text-primary);
            font-size: 16px;
            transition: all 0.3s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }

        .error-message {
            background-color: rgba(239, 68, 68, 0.2);
            color: var(--danger);
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .btn-login {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
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

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(139, 92, 246, 0.3);
        }

        .back-link {
            text-align: center;
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid var(--glass-border);
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
    <div class="login-container">
        <div class="login-header">
            <h1>
                <i data-lucide="headphones"></i>
                Support Executive Login
            </h1>
            <p>Login to access chat dashboard</p>
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
                    <label for="executive_id">Executive ID</label>
                    <input type="text" 
                           id="executive_id" 
                           name="executive_id" 
                           class="form-input" 
                           placeholder="Enter your executive ID"
                           required>
                </div>
                
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" 
                           id="username" 
                           name="username" 
                           class="form-input" 
                           placeholder="Enter your username"
                           required>
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" 
                           id="password" 
                           name="password" 
                           class="form-input" 
                           placeholder="Enter your password"
                           required>
                </div>
                
                <button type="submit" class="btn-login">
                    <i data-lucide="log-in" width="20" height="20"></i>
                    Login as Support Executive
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