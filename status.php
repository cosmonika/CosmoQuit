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
    SELECT u.*, up.* 
    FROM users u 
    LEFT JOIN user_progress up ON u.id = up.user_id 
    WHERE u.id = ?
");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user = $user_stmt->get_result()->fetch_assoc();

// Get progress history (last 30 days)
$history_stmt = $conn->prepare("
    SELECT * FROM progress_history 
    WHERE user_id = ? 
    ORDER BY history_date DESC 
    LIMIT 30
");
$history_stmt->bind_param("i", $user_id);
$history_stmt->execute();
$progress_history = $history_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get milestone achievements
$milestone_stmt = $conn->prepare("
    SELECT * FROM challenge_milestones 
    WHERE user_id = ? 
    ORDER BY milestone_day ASC
");
$milestone_stmt->bind_param("i", $user_id);
$milestone_stmt->execute();
$milestones = $milestone_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Calculate statistics
$total_cigarettes_avoided   = $user['total_cigarettes_avoided']   ?? 0;
$total_money_saved          = $user['total_money_saved']          ?? 0;
$lung_capacity_percentage   = $user['lung_capacity_percentage']   ?? 0;
$current_streak             = $user['current_streak']             ?? 0;
$longest_streak             = $user['longest_streak']             ?? 0;
$health_score               = $user['health_score']               ?? 0;

$share_success = false;
$share_url = '';

// Handle share progress (snapshot till today)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['share_progress'])) {
    $share_code = bin2hex(random_bytes(16));

    // Snapshot data for this share
    $progress_data = json_encode([
        'name'                     => $user['name'],
        'days_smoke_free'          => $user['total_days_smoke_free'],
        'money_saved'              => $total_money_saved,
        'streak'                   => $current_streak,
        'longest_streak'           => $longest_streak,
        'health_score'             => $health_score,
        'lung_capacity_percentage' => $lung_capacity_percentage,
        'total_cigarettes_avoided' => $total_cigarettes_avoided,
        'generated_at'             => date('Y-m-d H:i:s')
    ]);

    $expires_at = date('Y-m-d H:i:s', strtotime('+7 days'));

    $share_stmt = $conn->prepare("
        INSERT INTO shareable_progress (user_id, share_code, progress_data, expires_at) 
        VALUES (?, ?, ?, ?)
    ");
    $share_stmt->bind_param("isss", $user_id, $share_code, $progress_data, $expires_at);
    $share_stmt->execute();

    // IMPORTANT: share.php inside /CosmoQuit/
    $share_url = "https://" . $_SERVER['HTTP_HOST'] . "/share.php?code=" . $share_code;
    $share_success = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CosmoQuit - My Status</title>
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
        --warning-soft: rgba(245, 158, 11, 0.15);
        --danger: #EF4444;
        --danger-soft: rgba(239, 68, 68, 0.15);
        
        /* Glass Effects */
        --glass-bg: rgba(30, 27, 36, 0.8);
        --glass-border: rgba(139, 92, 246, 0.12);
        --glass-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
        --glass-shadow-heavy: 0 8px 30px rgba(0, 0, 0, 0.35);
        --glass-backdrop: blur(20px) saturate(160%);
        
        /* Task Type Colors - Purple theme variations */
        --task-walk: rgba(190, 242, 100, 0.15);
        --task-water: rgba(96, 165, 250, 0.15);
        --task-breathing: rgba(103, 232, 249, 0.15);
        --task-mind: rgba(167, 139, 250, 0.15);
        --task-nutrition: rgba(251, 207, 232, 0.15);
        --task-environment: rgba(94, 234, 212, 0.15);
        --task-sleep: rgba(100, 116, 139, 0.15);
        --task-social: rgba(253, 186, 116, 0.15);
        --task-health: rgba(251, 113, 133, 0.15);
        --task-reward: rgba(252, 211, 77, 0.15);
        --task-general: rgba(148, 163, 184, 0.15);
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

    /* Glass Header - Logo LEFT, Back button RIGHT */
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

    /* Action Buttons */
    .action-buttons {
        display: flex;
        justify-content: center;
        gap: 16px;
        margin-bottom: 32px;
        flex-wrap: wrap;
    }

    .action-button {
        padding: 12px 24px;
        border-radius: 10px;
        font-weight: 600;
        cursor: pointer;
        border: none;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 16px;
        backdrop-filter: blur(10px);
        position: relative;
        overflow: hidden;
    }

    .action-button::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
        transition: left 0.6s;
    }

    .action-button:hover::before {
        left: 100%;
    }

    .action-button.success {
        background: linear-gradient(135deg, rgba(16, 185, 129, 0.9) 0%, rgba(5, 150, 105, 0.9) 100%);
        color: white;
        border: 1px solid rgba(16, 185, 129, 0.3);
        box-shadow: 0 8px 24px rgba(16, 185, 129, 0.2);
    }

    .action-button.success:hover {
        transform: translateY(-2px);
        box-shadow: 0 15px 35px rgba(16, 185, 129, 0.3);
    }

    .action-button i {
        width: 20px;
        height: 20px;
    }

    /* Success Message & Share Section */
    .success-message {
        background: rgba(16, 185, 129, 0.15);
        color: #A7F3D0;
        padding: 16px;
        border-radius: 12px;
        margin-bottom: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
        border: 1px solid rgba(16, 185, 129, 0.3);
        backdrop-filter: blur(10px);
        animation: fadeIn 0.3s ease;
        margin-top: 24px;
    }

    .success-message i {
        width: 20px;
        height: 20px;
    }

    /* Share Section */
    .share-section {
        background: var(--card-bg);
        backdrop-filter: var(--glass-backdrop);
        -webkit-backdrop-filter: var(--glass-backdrop);
        border-radius: 16px;
        padding: 32px;
        border: 1px solid var(--card-border);
        box-shadow: var(--glass-shadow);
        text-align: center;
        margin-top: 24px;
        margin-bottom: 40px;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* Stats Grid - Dark Cards */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 24px;
        margin-bottom: 40px;
    }

    .stat-card {
        background: var(--card-bg);
        backdrop-filter: var(--glass-backdrop);
        -webkit-backdrop-filter: var(--glass-backdrop);
        border-radius: 16px;
        padding: 24px;
        border: 1px solid var(--card-border);
        box-shadow: var(--glass-shadow);
        transition: all 0.3s ease;
        text-align: center;
        position: relative;
        overflow: hidden;
    }

    .stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, var(--primary) 0%, var(--primary-light) 100%);
    }

    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 12px 32px rgba(139, 92, 246, 0.15);
        border-color: rgba(139, 92, 246, 0.25);
    }

    .stat-icon {
        width: 48px;
        height: 48px;
        background: rgba(139, 92, 246, 0.15);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 16px;
        color: var(--primary);
        border: 1px solid rgba(139, 92, 246, 0.3);
        backdrop-filter: blur(10px);
    }

    .stat-icon i {
        width: 24px;
        height: 24px;
    }

    .stat-card h3 {
        font-size: 14px;
        font-weight: 600;
        color: var(--text-muted);
        margin-bottom: 8px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .stat-value {
        font-size: 32px;
        font-weight: 700;
        color: var(--primary);
        margin-bottom: 8px;
    }

    .stat-description {
        font-size: 14px;
        color: var(--text-muted);
    }

    /* Progress Sections */
    .progress-section {
        background: var(--card-bg);
        backdrop-filter: var(--glass-backdrop);
        -webkit-backdrop-filter: var(--glass-backdrop);
        border-radius: 16px;
        padding: 32px;
        border: 1px solid var(--card-border);
        box-shadow: var(--glass-shadow);
        margin-bottom: 32px;
    }

    .section-title {
        font-size: 20px;
        font-weight: 700;
        margin-bottom: 24px;
        color: var(--text-primary);
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .section-title i {
        color: var(--primary);
        width: 24px;
        height: 24px;
    }

    /* Progress Table */
    .progress-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
    }

    .progress-table th {
        background: rgba(139, 92, 246, 0.1);
        padding: 16px;
        text-align: left;
        font-weight: 600;
        color: var(--text-muted);
        border-bottom: 2px solid var(--card-border);
    }

    .progress-table td {
        padding: 16px;
        border-bottom: 1px solid var(--card-border);
        color: var(--text-primary);
    }

    .progress-table tr:hover {
        background: rgba(139, 92, 246, 0.05);
    }

    .progress-table tr:last-child td {
        border-bottom: none;
    }

    /* Milestone Badges */
    .milestone-badge {
        display: inline-block;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        backdrop-filter: blur(10px);
        border: 1px solid;
    }

    .milestone-badge.easy {
        background: rgba(16, 185, 129, 0.2);
        color: #10B981;
        border-color: rgba(16, 185, 129, 0.3);
    }

    .milestone-badge.medium {
        background: rgba(245, 158, 11, 0.2);
        color: #F59E0B;
        border-color: rgba(245, 158, 11, 0.3);
    }

    .milestone-badge.hard {
        background: rgba(239, 68, 68, 0.2);
        color: #EF4444;
        border-color: rgba(239, 68, 68, 0.3);
    }

    .milestone-badge.completed {
        background: rgba(16, 185, 129, 0.3);
        color: white;
        border-color: rgba(16, 185, 129, 0.5);
    }

    /* Health Timeline */
    .health-timeline {
        position: relative;
        padding-left: 30px;
    }

    .health-timeline::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 2px;
        background: linear-gradient(to bottom, var(--primary), transparent);
    }

    .timeline-item {
        position: relative;
        margin-bottom: 24px;
        padding-bottom: 24px;
        border-bottom: 1px solid var(--card-border);
    }

    .timeline-item:last-child {
        border-bottom: none;
        margin-bottom: 0;
        padding-bottom: 0;
    }

    .timeline-item::before {
        content: '';
        position: absolute;
        left: -34px;
        top: 0;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        background: var(--primary);
        border: 2px solid var(--card-bg);
        box-shadow: 0 0 0 2px var(--primary);
    }

    .timeline-date {
        font-weight: 600;
        color: var(--primary-light);
        margin-bottom: 6px;
        font-size: 14px;
    }

    .timeline-content {
        color: var(--text-primary);
        line-height: 1.5;
    }

    /* Share URL Section */
    .share-url {
        display: flex;
        gap: 12px;
        margin: 20px 0;
    }

    .share-url input {
        flex: 1;
        padding: 14px;
        background: rgba(30, 27, 36, 0.6);
        border: 1px solid var(--card-border);
        border-radius: 10px;
        font-family: 'Inter', sans-serif;
        font-size: 14px;
        color: var(--text-primary);
        backdrop-filter: blur(10px);
    }

    .share-url input:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1);
    }

    .copy-button {
        padding: 14px 20px;
        background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
        color: white;
        border: none;
        border-radius: 10px;
        cursor: pointer;
        font-weight: 600;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 8px;
        border: 1px solid rgba(139, 92, 246, 0.3);
        box-shadow: 0 4px 16px rgba(139, 92, 246, 0.2);
    }

    .copy-button:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(139, 92, 246, 0.3);
    }

    .copy-button i {
        width: 16px;
        height: 16px;
    }

    /* Footer */
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
        .stats-grid {
            grid-template-columns: 1fr;
        }
        
        .progress-section {
            padding: 24px;
        }
        
        .share-url {
            flex-direction: column;
        }
        
        .copy-button {
            width: 100%;
        }
        
        .progress-table {
            display: block;
            overflow-x: auto;
        }
        
        .action-buttons {
            flex-direction: column;
            align-items: stretch;
        }
        
        .action-button {
            justify-content: center;
        }
        
        .page-header h1 {
            font-size: 28px;
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
        
        .page-header p {
            font-size: 16px;
        }
        
        .stat-value {
            font-size: 28px;
        }
        
        .section-title {
            font-size: 18px;
        }
        
        .health-timeline {
            padding-left: 20px;
        }
        
        .timeline-item::before {
            left: -24px;
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
                <h1>My Progress Status</h1>
                <p>Track your smoke-free journey in detail</p>
            </div>

            <div class="action-buttons">
                <!-- Only Share Progress button now -->
                <form method="POST" action="" style="display: inline;">
                    <button type="submit" name="share_progress" class="action-button success">
                        <i data-lucide="share-2"></i>
                        Share Progress
                    </button>
                </form>
            </div>

            <?php if ($share_success): ?>
                <div class="success-message">
                    <i data-lucide="check-circle"></i>
                    Progress shared successfully! Share this link:
                </div>
                
                <div class="share-section">
                    <div class="share-url">
                        <input type="text" id="shareUrl" value="<?php echo htmlspecialchars($share_url); ?>" readonly>
                        <button class="copy-button" onclick="copyShareUrl()">
                            <i data-lucide="copy"></i> Copy
                        </button>
                    </div>
                    <p style="color: var(--text-muted); font-size: 14px;">Link expires in 7 days</p>
                </div>
            <?php endif; ?>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i data-lucide="calendar"></i>
                    </div>
                    <h3>Total Smoke-Free Days</h3>
                    <div class="stat-value"><?php echo (int)$user['total_days_smoke_free']; ?></div>
                    <p class="stat-description">Since starting your journey</p>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <i data-lucide="wallet"></i>
                    </div>
                    <h3>Total Money Saved</h3>
                    <div class="stat-value">₹<?php echo number_format((float)$total_money_saved, 2); ?></div>
                    <p class="stat-description">Based on your smoking habits</p>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <i data-lucide="heart"></i>
                    </div>
                    <h3>Lung Capacity</h3>
                    <div class="stat-value"><?php echo (float)$lung_capacity_percentage; ?>%</div>
                    <p class="stat-description">Recovered lung function</p>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <i data-lucide="trending-up"></i>
                    </div>
                    <h3>Health Score</h3>
                    <div class="stat-value"><?php echo (int)$health_score; ?>/100</div>
                    <p class="stat-description">Overall health improvement</p>
                </div>
            </div>

            <div class="progress-section">
                <h2 class="section-title">
                    <i data-lucide="target"></i>
                    Challenge Milestones
                </h2>
                
                <table class="progress-table">
                    <thead>
                        <tr>
                            <th>Milestone</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Achieved On</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ([10, 20, 30] as $milestone_day): 
                            $milestone = array_filter($milestones, function($m) use ($milestone_day) {
                                return $m['milestone_day'] == $milestone_day;
                            });
                            $milestone = $milestone ? reset($milestone) : null;
                            $type = $milestone_day <= 10 ? 'easy' : ($milestone_day <= 20 ? 'medium' : 'hard');
                        ?>
                        <tr>
                            <td>Day <?php echo (int)$milestone_day; ?> Complete</td>
                            <td><span class="milestone-badge <?php echo $type; ?>"><?php echo ucfirst($type); ?></span></td>
                            <td>
                                <?php if ($milestone): ?>
                                    <span class="milestone-badge completed">Achieved</span>
                                <?php elseif (($user['current_day'] ?? 0) >= $milestone_day): ?>
                                    <span style="color: var(--success);">Ready to Claim</span>
                                <?php else: ?>
                                    <span style="color: var(--text-muted);">In Progress</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo $milestone && !empty($milestone['achieved_date']) ? date('M j, Y', strtotime($milestone['achieved_date'])) : '-'; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="progress-section">
                <h2 class="section-title">
                     <i data-lucide="history"></i>
                    Recent Progress History
                </h2>
                <?php if (empty($progress_history)): ?>
                    <p style="color: var(--text-muted); text-align: center; padding: 20px;">No progress history available yet.</p>
                <?php else: ?>
                    <div class="health-timeline">
                        <?php foreach (array_slice($progress_history, 0, 10) as $history): ?>
                            <div class="timeline-item">
                                <div class="timeline-date">
                                    <?php echo date('F j, Y', strtotime($history['history_date'])); ?>
                                </div>
                                <div class="timeline-content">
                                    <strong>Day <?php echo (int)$history['day_number']; ?>:</strong>
                                    Saved ₹<?php echo number_format((float)$history['money_saved'], 2); ?>,
                                    Completed <?php echo (int)$history['tasks_completed']; ?> tasks,
                                    Lung capacity at <?php echo (float)$history['lung_capacity_change']; ?>%
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <footer>
        <div class="container">
            <nav class="footer-nav">
                <a href="home.php" class="icon-button">
                    <i data-lucide="home"></i>
                </a>
                <a href="status.php" class="icon-button active">
                    <i data-lucide="bar-chart-3"></i>
                </a>
           <a href="diary.php" class="icon-button">
                <i data-lucide="notebook-pen"></i>
            </a>
                <a href="settings.php" class="icon-button">
                    <i data-lucide="settings"></i>
                </a>
            </nav>
        </div>
    </footer>

    <script>
        lucide.createIcons();
        
        function copyShareUrl() {
            const shareUrl = document.getElementById('shareUrl');
            shareUrl.select();
            shareUrl.setSelectionRange(0, 99999);
            document.execCommand('copy');
            alert('Share link copied to clipboard!');
        }
        
        // Auto-refresh stats every 30 seconds
        setInterval(() => {
            fetch('api/get_stats.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const values = document.querySelectorAll('.stat-value');
                        values[0].textContent = data.data.days_smoke_free;
                        values[1].textContent = '₹' + parseFloat(data.data.money_saved).toFixed(2);
                        values[2].textContent = data.data.lung_capacity + '%';
                        values[3].textContent = data.data.health_score + '/100';
                    }
                })
                .catch(() => {});
        }, 30000);
    </script>
</body>
</html>