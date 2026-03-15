<?php
require_once 'includes/session.php';
require_once 'includes/config.php';
require_once 'includes/Database.php';
require_once 'includes/auth.php';

checkAuth();

$db = new Database();
$conn = $db->getConnection();
$user_id = $_SESSION['user_id'];

// Get user data
$user_stmt = $conn->prepare("
    SELECT u.*, us.* 
    FROM users u 
    LEFT JOIN user_settings us ON u.id = us.user_id 
    WHERE u.id = ?
");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user = $user_stmt->get_result()->fetch_assoc();

$success = '';
$error   = '';

// ----------------------------------------------------
// Avatar selection (12 animals + legendary Pegasus)
// Using your HD (512px) Icons8 URLs
// ----------------------------------------------------

// 12 normal avatars
$normalAvatars = [
    [
        'key'  => 'falcon',
        'name' => 'Falcon',
        'url'  => 'https://img.icons8.com/office/512/falcon.png',
    ],
    [
        'key'  => 'duck',
        'name' => 'Duck',
        'url'  => 'https://img.icons8.com/office/512/duck.png',
    ],
    [
        'key'  => 'parrot',
        'name' => 'Parrot',
        'url'  => 'https://img.icons8.com/office/512/parrot.png',
    ],
    [
        'key'  => 'puffin',
        'name' => 'Puffin Bird',
        'url'  => 'https://img.icons8.com/office/512/puffin-bird.png',
    ],
    [
        'key'  => 'pig',
        'name' => 'Pig',
        'url'  => 'https://img.icons8.com/office/512/pig.png',
    ],
    [
        'key'  => 'cat',
        'name' => 'Cat',
        'url'  => 'https://img.icons8.com/office/512/cat--v1.png',
    ],
    [
        'key'  => 'corgi',
        'name' => 'Corgi',
        'url'  => 'https://img.icons8.com/officel/512/corgi.png',
    ],
    [
        'key'  => 'turtle',
        'name' => 'Turtle',
        'url'  => 'https://img.icons8.com/office/512/turtle.png',
    ],
    [
        'key'  => 'clown-fish',
        'name' => 'Clown Fish',
        'url'  => 'https://img.icons8.com/office/512/clown-fish.png',
    ],
    [
        'key'  => 'octopus',
        'name' => 'Octopus',
        'url'  => 'https://img.icons8.com/office/512/octopus.png',
    ],
    [
        'key'  => 'chameleon',
        'name' => 'Chameleon',
        'url'  => 'https://img.icons8.com/office/512/chameleon.png',
    ],
    [
        'key'  => 'whale',
        'name' => 'Whale',
        'url'  => 'https://img.icons8.com/office/512/whale.png',
    ],
];

// Legendary Pegasus
$legendaryAvatar = [
    'key'       => 'pegasus',
    'name'      => 'Pegasus',
    'url'       => 'https://img.icons8.com/office/512/pegasus.png',
    'legendary' => true,
];

// Deterministic hash based on user_id
$hashSource = (string)$user_id;
$hash       = crc32($hashSource);

// Manual legendary override list (option 2)
// 👉 Add user IDs here to FORCE Pegasus for them
$legendaryUserIds = [4];

// Legendary check = manual override OR 1-in-10,000 chance (option 3)
$hasLegendary = in_array($user_id, $legendaryUserIds, true) || ($hash % 10000 === 0);

// Choose avatar
if ($hasLegendary) {
    $selectedAvatar = $legendaryAvatar;
} else {
    $index          = $hash % count($normalAvatars);
    $selectedAvatar = $normalAvatars[$index];
}

// ----------------------------------
// Handle app settings update (no theme picker)
// ----------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_settings'])) {
        $notification_enabled   = isset($_POST['notification_enabled']) ? 1 : 0;
        $notification_interval  = intval($_POST['notification_interval']);
        $daily_reminder_time    = $_POST['daily_reminder_time'];
        $share_progress_enabled = isset($_POST['share_progress_enabled']) ? 1 : 0;

        // Keep existing theme_color (not editable here)
        $theme_color = $user['theme_color'] ?? 'blue';

        if ($notification_interval < 5)  $notification_interval = 5;
        if ($notification_interval > 60) $notification_interval = 60;

        $update_stmt = $conn->prepare("
            UPDATE user_settings 
            SET notification_enabled = ?, 
                notification_interval = ?, 
                theme_color = ?, 
                daily_reminder_time = ?, 
                share_progress_enabled = ? 
            WHERE user_id = ?
        ");

        $update_stmt->bind_param(
            "iisssi",
            $notification_enabled,
            $notification_interval,
            $theme_color,
            $daily_reminder_time,
            $share_progress_enabled,
            $user_id
        );

        if ($update_stmt->execute()) {
            $success = "Settings updated successfully!";
        } else {
            $error = "Failed to update settings. Please try again.";
        }
    }
    // Handle logout from form
    elseif (isset($_POST['logout'])) {
        session_destroy();
        header("Location: login.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CosmoQuit - Settings</title>
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            /* Purple Theme - Deep & Calming */
            --primary: #8B5CF6;
            --primary-light: #A78BFA;
            --primary-dark: #7C3AED;
            --primary-soft: rgba(139, 92, 246, 0.1);
            
            /* Dark Backgrounds */
            --main-bg: #0F0B16;
            --card-bg: rgba(30, 27, 36, 0.9);
            --card-border: rgba(139, 92, 246, 0.15);
            
            /* Text Colors */
            --text-primary: #F3E8FF;
            --text-muted: #C4B5FD;
            --text-light: #A78BFA;
            
            /* Status Colors */
            --success: #10B981;
            --success-soft: rgba(16, 185, 129, 0.15);
            --warning: #F59E0B;
            --warning-soft: rgba(245, 158, 11, 0.2);
            --danger: #EF4444;
            --danger-soft: rgba(239, 68, 68, 0.15);
            
            /* Glass Effects */
            --glass-bg: rgba(30, 27, 36, 0.8);
            --glass-border: rgba(139, 92, 246, 0.12);
            --glass-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
            --glass-shadow-heavy: 0 8px 30px rgba(0, 0, 0, 0.35);
            --glass-backdrop: blur(20px) saturate(160%);
            
            /* Legendary Colors */
            --legendary-gold: #FBBF24;
            --legendary-orange: #F97316;
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
            line-height: 1.5;
            min-height: 100vh;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 16px;
        }

        /* Glass Header - Logo LEFT, Back button RIGHT (Updated to match status.php) */
        header {
            background: var(--glass-bg);
            backdrop-filter: var(--glass-backdrop);
            -webkit-backdrop-filter: var(--glass-backdrop);
            border-bottom: 1px solid var(--glass-border);
            box-shadow: var(--glass-shadow);
            position: sticky;
            top: 0;
            z-index: 100;
            height: 60px;
            display: flex;
            align-items: center;
        }

        .header-content {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo-section {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .logo-png {
            width: 36px;
            height: 36px;
            object-fit: contain;
            display: inline-block;
            filter: drop-shadow(0 2px 4px rgba(139, 92, 246, 0.3));
        }

        .logo-text {
            font-size: 24px;
            font-weight: 800;
            color: var(--primary);
            letter-spacing: -0.5px;
            text-shadow: 0 2px 4px rgba(139, 92, 246, 0.3);
        }

        .back-button {
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            background: rgba(30, 27, 36, 0.8);
            border: 1px solid var(--glass-border);
            color: var(--text-muted);
            font-weight: 500;
            padding: 8px 16px;
            border-radius: 10px;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }

        .back-button:hover {
            background: rgba(139, 92, 246, 0.2);
            color: var(--primary);
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(139, 92, 246, 0.15);
            border-color: rgba(139, 92, 246, 0.3);
        }

        .back-button i {
            width: 20px;
            height: 20px;
        }

        /* Main Content */
        .main-content {
            padding: 24px 0;
            min-height: calc(100vh - 120px);
        }

        .page-header {
            text-align: center;
            margin-bottom: 32px;
        }

        .page-header h1 {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 8px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            text-shadow: 0 2px 10px rgba(139, 92, 246, 0.2);
        }

        .page-header p {
            color: var(--text-muted);
            font-size: 18px;
            max-width: 600px;
            margin: 0 auto;
            line-height: 1.5;
        }

        .settings-container {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 32px;
        }

        @media (max-width: 768px) {
            .settings-container {
                grid-template-columns: 1fr;
            }
        }

        /* Settings Cards */
        .settings-card {
            background: var(--card-bg);
            backdrop-filter: var(--glass-backdrop);
            -webkit-backdrop-filter: var(--glass-backdrop);
            border-radius: 16px;
            padding: 32px;
            border: 1px solid var(--card-border);
            box-shadow: var(--glass-shadow);
            transition: all 0.3s ease;
        }

        .settings-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 32px rgba(139, 92, 246, 0.15);
            border-color: rgba(139, 92, 246, 0.25);
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 2px solid var(--card-border);
        }

        .card-header h2 {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .card-header i {
            width: 24px;
            height: 24px;
            color: var(--primary);
        }

        /* Profile Section */
        .profile-section {
            text-align: center;
        }

        .profile-avatar {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            border: 4px solid var(--primary);
            margin: 0 auto 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(139, 92, 246, 0.1);
            overflow: hidden;
            transition: all 0.3s ease;
            position: relative;
        }

        .profile-avatar.legendary {
            border-color: var(--legendary-gold);
            background: linear-gradient(135deg, rgba(251, 191, 36, 0.2), rgba(249, 115, 22, 0.2));
            box-shadow: 0 0 30px rgba(251, 191, 36, 0.4);
            animation: legendaryPulse 3s infinite;
        }

        @keyframes legendaryPulse {
            0%, 100% { box-shadow: 0 0 30px rgba(251, 191, 36, 0.4); }
            50% { box-shadow: 0 0 50px rgba(251, 191, 36, 0.6); }
        }

        .animal-icon {
            width: 110px;
            height: 110px;
            object-fit: contain;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.2));
        }

        .profile-avatar.legendary .animal-icon {
            filter: drop-shadow(0 4px 12px rgba(251, 191, 36, 0.4));
        }

        .profile-info {
            margin-bottom: 24px;
        }

        .profile-info h3 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 12px;
            color: var(--text-primary);
        }

        .profile-info p {
            color: var(--text-muted);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .profile-info i {
            width: 16px;
            height: 16px;
            color: var(--primary);
        }

        .legendary-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 20px;
            border: none;
            background: linear-gradient(135deg, var(--legendary-gold), var(--legendary-orange));
            color: #0F0B16;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 16px;
            cursor: default;
            box-shadow: 0 6px 16px rgba(249, 115, 22, 0.4);
            backdrop-filter: blur(10px);
        }

        .legendary-badge img {
            width: 20px;
            height: 20px;
            object-fit: contain;
        }

        /* Form Styles */
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

        .form-control {
            width: 100%;
            padding: 14px;
            background: rgba(30, 27, 36, 0.6);
            border: 1px solid var(--card-border);
            border-radius: 10px;
            font-family: 'Inter', sans-serif;
            font-size: 15px;
            transition: all 0.3s ease;
            color: var(--text-primary);
            backdrop-filter: blur(10px);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            background: rgba(30, 27, 36, 0.8);
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
        }

        .form-control.readonly-field {
            background: rgba(30, 27, 36, 0.4);
            color: var(--text-muted);
            cursor: not-allowed;
            border-color: var(--card-border);
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 8px;
        }

        .checkbox-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
            accent-color: var(--primary);
            background: rgba(30, 27, 36, 0.6);
            border: 1px solid var(--card-border);
            border-radius: 4px;
        }

        .checkbox-group label {
            margin: 0;
            font-weight: 400;
            color: var(--text-primary);
            cursor: pointer;
        }

        small {
            display: block;
            color: var(--text-muted);
            font-size: 13px;
            margin-top: 6px;
        }

        .submit-button {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 16px;
            border: 1px solid rgba(139, 92, 246, 0.3);
            box-shadow: 0 8px 24px rgba(139, 92, 246, 0.2);
            position: relative;
            overflow: hidden;
        }

        .submit-button::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transition: left 0.6s;
        }

        .submit-button:hover::before {
            left: 100%;
        }

        .submit-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 35px rgba(139, 92, 246, 0.3);
        }

        .submit-button i {
            width: 20px;
            height: 20px;
        }

        .logout-button {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--danger) 0%, #DC2626 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 16px;
            border: 1px solid rgba(239, 68, 68, 0.3);
            box-shadow: 0 8px 24px rgba(239, 68, 68, 0.2);
            position: relative;
            overflow: hidden;
        }

        .logout-button::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transition: left 0.6s;
        }

        .logout-button:hover::before {
            left: 100%;
        }

        .logout-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 35px rgba(239, 68, 68, 0.3);
        }

        .logout-button i {
            width: 20px;
            height: 20px;
        }

        /* Message Styles */
        .message {
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            backdrop-filter: blur(10px);
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .message.success {
            background: rgba(16, 185, 129, 0.15);
            color: #A7F3D0;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .message.error {
            background: rgba(239, 68, 68, 0.15);
            color: #FCA5A5;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .message i {
            width: 20px;
            height: 20px;
        }

        /* Footer - Same as home.php */
        footer {
            background: var(--glass-bg);
            border-top: 1px solid var(--glass-border);
            padding: 12px 0;
            position: sticky;
            bottom: 0;
            box-shadow: 0 -4px 16px rgba(0, 0, 0, 0.3);
            backdrop-filter: var(--glass-backdrop);
            -webkit-backdrop-filter: var(--glass-backdrop);
        }

        .footer-nav {
            display: flex;
            justify-content: space-around;
            align-items: center;
        }

        .footer-nav .icon-button {
            position: relative;
            padding: 12px;
            text-decoration: none;
            background: rgba(30, 27, 36, 0.8);
            border: 1px solid var(--glass-border);
            border-radius: 10px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }

        .footer-nav .icon-button:hover {
            background: rgba(139, 92, 246, 0.2);
            color: var(--primary);
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(139, 92, 246, 0.15);
            border-color: rgba(139, 92, 246, 0.3);
        }

        .footer-nav .icon-button.active {
            color: var(--primary);
            background: rgba(139, 92, 246, 0.2);
            border-color: rgba(139, 92, 246, 0.4);
        }

        .footer-nav .icon-button i {
            width: 24px;
            height: 24px;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .settings-card {
                padding: 24px;
            }
            
            .page-header h1 {
                font-size: 28px;
            }
            
            .page-header p {
                font-size: 16px;
            }
            
            .profile-avatar {
                width: 120px;
                height: 120px;
            }
            
            .animal-icon {
                width: 90px;
                height: 90px;
            }
            
            .profile-info h3 {
                font-size: 20px;
            }
            
            .card-header h2 {
                font-size: 18px;
            }
            
            .back-button {
                padding: 6px 12px;
                font-size: 14px;
            }
        }

        @media (max-width: 480px) {
            .page-header h1 {
                font-size: 24px;
            }
            
            .settings-card {
                padding: 20px;
            }
            
            .profile-avatar {
                width: 100px;
                height: 100px;
            }
            
            .animal-icon {
                width: 75px;
                height: 75px;
            }
            
            .legendary-badge {
                font-size: 10px;
                padding: 6px 12px;
            }
            
            .back-button span {
                display: none;
            }
            
            .back-button i {
                margin-right: 0;
            }
        }

        /* Scrollbar Styling */
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: rgba(30, 27, 36, 0.5);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb {
            background: rgba(139, 92, 246, 0.3);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: rgba(139, 92, 246, 0.5);
        }
    </style>
</head>
<body>
<header>
    <div class="header-content">
        <div class="logo-section">
            <img src="assets/images/logo.png" alt="CosmoQuit Logo" class="logo-png">
            <span class="logo-text">CosmoQuit</span>
        </div>
        <a href="home.php" class="back-button">
            <i data-lucide="arrow-left"></i>
            <span>Back to Dashboard</span>
        </a>
    </div>
</header>

<main class="main-content">
    <div class="container">
        <div class="page-header">
            <h1>Account Settings</h1>
            <p>Manage your profile and app preferences</p>
        </div>

        <div class="settings-container">
            <!-- Profile Settings Card -->
            <div class="settings-card profile-section">
                <div class="card-header">
                    <i data-lucide="user"></i>
                    <h2>Profile Information</h2>
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

                <div class="profile-avatar <?php echo $hasLegendary ? 'legendary' : ''; ?>">
                    <img
                        src="<?php echo htmlspecialchars($selectedAvatar['url']); ?>"
                        alt="<?php echo htmlspecialchars($selectedAvatar['name']); ?> Avatar"
                        class="animal-icon"
                    >
                </div>

                <div class="profile-info">
                    <?php if ($hasLegendary): ?>
                        <button class="legendary-badge" type="button">
                            <img
                                src="<?php echo htmlspecialchars($legendaryAvatar['url']); ?>"
                                alt="Legendary Pegasus"
                            >
                            Legendary Avatar · 1 in 10,000
                        </button>
                    <?php endif; ?>

                    <h3><?php echo htmlspecialchars($user['name']); ?></h3>
                    <p><i data-lucide="phone"></i> <?php echo htmlspecialchars($user['mobile']); ?></p>
                    <?php if (!empty($user['email'])): ?>
                        <p><i data-lucide="mail"></i> <?php echo htmlspecialchars($user['email']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($user['dob'])): ?>
                        <p><i data-lucide="calendar"></i> Born <?php echo date('F j, Y', strtotime($user['dob'])); ?></p>
                    <?php endif; ?>
                    <!-- Product Key removed as requested -->
                </div>
            </div>

            <!-- App Settings Card -->
            <div class="settings-card">
                <div class="card-header">
                    <i data-lucide="settings"></i>
                    <h2>Application Settings</h2>
                </div>

                <form method="POST" action="">
                    <input type="hidden" name="update_settings" value="1">

                    <div class="form-group">
                        <label>Notifications</label>
                        <div class="checkbox-group">
                            <input type="checkbox"
                                   id="notification_enabled"
                                   name="notification_enabled"
                                <?php echo ($user['notification_enabled'] ?? 1) ? 'checked' : ''; ?>>
                            <label for="notification_enabled">Enable push notifications</label>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="notification_interval">Notification Interval (minutes)</label>
                        <input type="number"
                               id="notification_interval"
                               name="notification_interval"
                               class="form-control"
                               min="5"
                               max="60"
                               value="<?php echo htmlspecialchars($user['notification_interval'] ?? 10); ?>">
                        <small>How often to show motivation quotes and reminders</small>
                    </div>

                    <div class="form-group">
                        <label for="daily_reminder_time">Daily Reminder Time</label>
                        <input type="time"
                               id="daily_reminder_time"
                               name="daily_reminder_time"
                               class="form-control"
                               value="<?php echo htmlspecialchars($user['daily_reminder_time'] ?? '09:00'); ?>">
                    </div>

                    <div class="form-group">
                        <label>Sharing</label>
                        <div class="checkbox-group">
                            <input type="checkbox"
                                   id="share_progress_enabled"
                                   name="share_progress_enabled"
                                <?php echo ($user['share_progress_enabled'] ?? 1) ? 'checked' : ''; ?>>
                            <label for="share_progress_enabled">Allow sharing progress with others</label>
                        </div>
                    </div>

                    <div class="form-group">
                         <label>Smoking History (Read-only)</label>
                        <input type="text"
                               class="form-control readonly-field"
                               value="<?php echo htmlspecialchars($user['cigarettes_per_day']); ?> cigarettes/day for <?php echo htmlspecialchars($user['smoking_years']); ?> years"
                               readonly>
                    </div>

                    <div class="form-group">
                        <label>Registration Date</label>
                        <input type="text"
                               class="form-control readonly-field"
                               value="<?php echo date('F j, Y', strtotime($user['registration_date'])); ?>"
                               readonly>
                    </div>

                    <div class="form-group">
                        <label>Last Login</label>
                        <input type="text"
                               class="form-control readonly-field"
                               value="<?php echo $user['last_login'] ? date('F j, Y H:i', strtotime($user['last_login'])) : 'Never'; ?>"
                               readonly>
                    </div>

                    <button type="submit" class="submit-button">
                        <i data-lucide="save"></i>
                        Save Settings
                    </button>

                      <button type="button" class="logout-button" onclick="window.location.href='logout.php';">
    <i data-lucide="log-out"></i>
    Logout
</button>
                </form>
            </div>
        </div>
    </div>
</main>

<footer>
    <div class="container">
        <nav class="footer-nav">
            <a href="home.php" class="icon-button">
                <i data-lucide="home"></i>
            </a>
            <a href="status.php" class="icon-button">
                <i data-lucide="bar-chart-3"></i>
            </a>
            <a href="diary.php" class="icon-button">
                <i data-lucide="notebook-pen"></i>
            </a>
            <a href="settings.php" class="icon-button active">
                <i data-lucide="settings"></i>
            </a>
        </nav>
    </div>
</footer>

<script>
    lucide.createIcons();
</script>
</body>
</html>