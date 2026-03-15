<?php
require_once 'includes/config.php';
require_once 'includes/Database.php';

$db   = new Database();
$conn = $db->getConnection();

$code       = $_GET['code'] ?? '';
$error      = '';
$share_row  = null;
$share_data = null;
$user       = null;

if (!$code) {
    $error = 'No progress code was provided.';
} else {
    $stmt = $conn->prepare("
        SELECT * FROM shareable_progress 
        WHERE share_code = ? 
        LIMIT 1
    ");
    $stmt->bind_param("s", $code);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if (!($share_row = $result->fetch_assoc())) {
        $error = 'This progress link is invalid.';
    } else {
        if (strtotime($share_row['expires_at']) < time()) {
            $error = 'This progress link has expired.';
        } else {
            $share_data = json_decode($share_row['progress_data'], true) ?: [];
            
            // Fetch user (we WON'T show DOB here)
            $user_stmt = $conn->prepare("
                SELECT u.*, up.* 
                FROM users u
                LEFT JOIN user_progress up ON u.id = up.user_id
                WHERE u.id = ?
            ");
            $uid = (int)$share_row['user_id'];
            $user_stmt->bind_param("i", $uid);
            $user_stmt->execute();
            $user = $user_stmt->get_result()->fetch_assoc();
        }
    }
}

// Avatar + legendary logic
$hasLegendary   = false;
$selectedAvatar = null;

if ($user && $share_row) {
    $shared_user_id = (int)$share_row['user_id'];

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

    $legendaryAvatar = [
        'key'       => 'pegasus',
        'name'      => 'Pegasus',
        'url'       => 'https://img.icons8.com/office/512/pegasus.png',
        'legendary' => true,
    ];

    $hashSource = (string)$shared_user_id;
    $hash       = crc32($hashSource);

    // Manual legendary list (fill if you want to force Pegasus)
    $legendaryUserIds = [4];

    $hasLegendary = in_array($shared_user_id, $legendaryUserIds, true) || ($hash % 10000 === 0);

    if ($hasLegendary) {
        $selectedAvatar = $legendaryAvatar;
    } else {
        $index          = $hash % count($normalAvatars);
        $selectedAvatar = $normalAvatars[$index];
    }

    // Values from snapshot (with fallback)
    $days_smoke_free          = $share_data['days_smoke_free']          ?? ($user['total_days_smoke_free'] ?? 0);
    $money_saved              = $share_data['money_saved']              ?? ($user['total_money_saved'] ?? 0);
    $streak                   = $share_data['streak']                   ?? ($user['current_streak'] ?? 0);
    $longest_streak           = $share_data['longest_streak']           ?? ($user['longest_streak'] ?? 0);
    $health_score             = $share_data['health_score']             ?? ($user['health_score'] ?? 0);
    $lung_capacity_percentage = $share_data['lung_capacity_percentage'] ?? ($user['lung_capacity_percentage'] ?? 0);
    $total_cigs_avoided       = $share_data['total_cigarettes_avoided'] ?? ($user['total_cigarettes_avoided'] ?? 0);
    $generated_at             = $share_data['generated_at']             ?? null;

} else {
    $days_smoke_free          = 0;
    $money_saved              = 0;
    $streak                   = 0;
    $longest_streak           = 0;
    $health_score             = 0;
    $lung_capacity_percentage = 0;
    $total_cigs_avoided       = 0;
    $generated_at             = null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>CosmoQuit – Shared Progress</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
            
            /* Status Colors - KEEP ORIGINAL (green for verified) */
            --success: #10B981;          /* Emerald Green - for verified badge */
            --success-soft: rgba(16, 185, 129, 0.15);
            --warning: #F59E0B;          /* Amber */
            --warning-soft: rgba(245, 158, 11, 0.15);
            --danger: #EF4444;           /* Red */
            --danger-soft: rgba(239, 68, 68, 0.15);
            
            /* Glass Effects - Dark theme */
            --glass-bg: rgba(30, 27, 36, 0.8);
            --glass-border: rgba(139, 92, 246, 0.12);
            --glass-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
            --glass-shadow-heavy: 0 8px 30px rgba(0, 0, 0, 0.35);
            
            /* Legendary Colors */
            --legendary-gold: #FBBF24;
            --legendary-orange: #F97316;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--main-bg);
            color: var(--text-primary);
        }

        .container {
            max-width: 960px;
            margin: 0 auto;
            padding: 16px;
        }

        header {
            background-color: var(--glass-bg);
            box-shadow: var(--glass-shadow);
            margin-bottom: 24px;
            border-radius: 0 0 16px 16px;
            border-bottom: 1px solid var(--glass-border);
        }

        .header-content {
            max-width: 960px;
            margin: 0 auto;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .logo-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .logo-img {
            width: 36px;
            height: 36px;
            object-fit: contain;
            filter: drop-shadow(0 2px 4px rgba(139, 92, 246, 0.3));
        }

        .logo-text {
            font-size: 24px;
            font-weight: 800;
            color: var(--primary);
            letter-spacing: -0.03em;
            text-shadow: 0 2px 4px rgba(139, 92, 246, 0.3);
        }

        .header-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 999px;
            background-color: rgba(30, 27, 36, 0.8);
            font-size: 12px;
            color: var(--text-muted);
            border: 1px solid var(--glass-border);
            backdrop-filter: blur(10px);
        }

        .header-pill i {
            width: 14px;
            height: 14px;
        }

        .card {
            background-color: var(--card-bg);
            border-radius: 16px;
            padding: 24px;
            box-shadow: var(--glass-shadow);
            border: 1px solid var(--card-border);
        }

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 20px;
        }

        .page-title-block {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .page-title {
            font-size: 22px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .page-subtitle {
            font-size: 14px;
            color: var(--text-muted);
        }

        .badge-soft {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            background-color: rgba(30, 27, 36, 0.6);
            font-size: 12px;
            color: var(--text-muted);
            border: 1px solid var(--card-border);
            backdrop-filter: blur(10px);
        }

        .badge-soft i {
            width: 14px;
            height: 14px;
        }

        .error-box {
            text-align: center;
            padding: 40px 16px;
        }

        .error-icon-wrap {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            height: 48px;
            border-radius: 999px;
            background-color: rgba(30, 27, 36, 0.6);
            color: var(--primary);
            margin-bottom: 12px;
            border: 1px solid var(--card-border);
            backdrop-filter: blur(10px);
        }

        .error-title {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--text-primary);
        }

        .error-text {
            font-size: 14px;
            color: var(--text-muted);
        }

        .top-section {
            display: flex;
            flex-wrap: wrap;
            gap: 24px;
            margin-bottom: 20px;
        }

        .avatar-column {
            flex: 0 0 160px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
        }

        .profile-avatar {
            width: 140px;
            height: 140px;
            border-radius: 50%;
            border: 4px solid var(--primary-light);
            display: flex;
            align-items: center;
            justify-content: center;
            background: radial-gradient(circle at 30% 30%, rgba(139, 92, 246, 0.2), rgba(30, 27, 36, 0.8));
            overflow: hidden;
        }

        .profile-avatar.legendary {
            border-color: var(--legendary-gold);
            background: radial-gradient(circle at 30% 30%, rgba(251, 191, 36, 0.3), rgba(249, 115, 22, 0.2));
            box-shadow: 0 0 20px rgba(251, 191, 36, 0.5);
        }

        .profile-avatar img {
            width: 110px;
            height: 110px;
            object-fit: contain;
        }

        .legendary-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 999px;
            background: linear-gradient(135deg, var(--legendary-gold), var(--legendary-orange));
            color: #0F0B16;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            box-shadow: 0 6px 16px rgba(249, 115, 22, 0.4);
        }

        .legendary-badge img {
            width: 18px;
            height: 18px;
            object-fit: contain;
        }

        .profile-info {
            flex: 1;
            min-width: 220px;
        }

        .profile-name-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 6px;
        }

        .profile-name {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .profile-tag {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: var(--success); /* GREEN color for verified */
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .profile-tag i {
            width: 14px;
            height: 14px;
            color: var(--success);
        }

        .profile-description {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 8px;
        }

        .meta-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 6px;
        }

        .meta-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            background-color: rgba(30, 27, 36, 0.6);
            font-size: 12px;
            color: var(--text-primary);
            border: 1px solid var(--card-border);
            backdrop-filter: blur(10px);
        }

        .meta-chip i {
            width: 14px;
            height: 14px;
            color: var(--primary);
        }

        .generated-text {
            font-size: 12px;
            color: var(--text-muted);
        }

        .stats-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
            margin-top: 16px;
        }

        .stats-title {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .stats-title i {
            width: 18px;
            height: 18px;
            color: var(--primary);
        }

        .stats-pill {
            font-size: 12px;
            padding: 4px 10px;
            border-radius: 999px;
            background-color: rgba(30, 27, 36, 0.6);
            color: var(--text-muted);
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid var(--card-border);
            backdrop-filter: blur(10px);
        }

        .stats-pill i {
            width: 14px;
            height: 14px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 12px;
        }

        .stat-card {
            background-color: rgba(30, 27, 36, 0.6);
            border-radius: 14px;
            padding: 12px 12px 14px;
            border: 1px solid var(--card-border);
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(139, 92, 246, 0.15);
            border-color: rgba(139, 92, 246, 0.3);
        }

        .stat-label-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: var(--text-muted);
        }

        .stat-icon {
            width: 24px;
            height: 24px;
            border-radius: 999px;
            background-color: rgba(139, 92, 246, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(139, 92, 246, 0.3);
        }

        .stat-icon i {
            width: 14px;
            height: 14px;
            color: var(--primary);
        }

        .stat-value {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary);
        }

        .stat-sub {
            font-size: 12px;
            color: var(--text-muted);
        }

        .footer-note {
            margin-top: 16px;
            font-size: 12px;
            color: var(--text-muted);
            text-align: center;
        }

        @media (max-width: 640px) {
            .card {
                padding: 20px 16px;
            }
            .top-section {
                flex-direction: column;
            }
            .avatar-column {
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
<header>
    <div class="header-content">
        <div class="logo-group">
            <img src="assets/images/logo.png" alt="CosmoQuit" class="logo-img">
            <span class="logo-text">CosmoQuit</span>
        </div>
        <div class="header-pill">
            <i data-lucide="share-2"></i>
            Shared Progress
        </div>
    </div>
</header>

<main class="container">
    <div class="card">
        <div class="page-header">
            <div class="page-title-block">
                <div class="page-title">Smoke-Free Journey</div>
                <div class="page-subtitle">
                    A read-only snapshot shared from the CosmoQuit app.
                </div>
            </div>
            <div class="badge-soft">
                <i data-lucide="lock"></i>
                Public view · No editing
            </div>
        </div>

        <?php if ($error): ?>
            <div class="error-box">
                <div class="error-icon-wrap">
                    <i data-lucide="alert-triangle"></i>
                </div>
                <div class="error-title">Link not available</div>
                <div class="error-text"><?php echo htmlspecialchars($error); ?></div>
            </div>
        <?php elseif (!$user || !$share_row): ?>
            <div class="error-box">
                <div class="error-icon-wrap">
                    <i data-lucide="slash"></i>
                </div>
                <div class="error-title">Report not found</div>
                <div class="error-text">This shared progress report could not be loaded.</div>
            </div>
        <?php else: ?>

            <section class="top-section">
                <div class="avatar-column">
                    <div class="profile-avatar <?php echo $hasLegendary ? 'legendary' : ''; ?>">
                        <img src="<?php echo htmlspecialchars($selectedAvatar['url']); ?>"
                             alt="<?php echo htmlspecialchars($selectedAvatar['name']); ?> avatar">
                    </div>
                    <?php if ($hasLegendary): ?>
                        <div class="legendary-badge">
                            <img src="https://img.icons8.com/office/512/pegasus.png" alt="Legendary Pegasus">
                            Legendary Avatar · 1 in 10,000
                        </div>
                    <?php endif; ?>
                </div>

                <div class="profile-info">
                    <div class="profile-name-row">
                        <div>
                            <div class="profile-tag">
                                <i data-lucide="shield-check"></i>
                                CosmoQuit User
                            </div>
                            <div class="profile-name">
                                <?php echo htmlspecialchars($share_data['name'] ?? $user['name']); ?>
                            </div>
                        </div>
                        <div class="badge-soft">
                            <i data-lucide="activity"></i>
                            Health score: <?php echo $health_score; ?>/100
                        </div>
                    </div>

                    <p class="profile-description">
                        This page shows an overview of their progress after quitting smoking using CosmoQuit.
                    </p>

                    <div class="meta-row">
                        <div class="meta-chip">
                            <i data-lucide="flame"></i>
                            Streak: <?php echo $streak; ?> days (best <?php echo $longest_streak; ?>)
                        </div>
                        <?php if (!empty($generated_at)): ?>
                            <div class="meta-chip">
                                <i data-lucide="clock"></i>
                                <span class="generated-text">
                                    Report created on <?php echo date('M j, Y H:i', strtotime($generated_at)); ?>
                                </span>
                            </div>
                        <?php endif; ?>
                        <div class="meta-chip">
                            <i data-lucide="heart-pulse"></i>
                            Lung capacity: <?php echo $lung_capacity_percentage; ?>%
                        </div>
                    </div>
                </div>
            </section>

            <section>
                <div class="stats-header">
                    <div class="stats-title">
                        <i data-lucide="bar-chart-3"></i>
                        Progress Overview
                    </div>
                    <div class="stats-pill">
                        <i data-lucide="info"></i>
                        Values based on user's past smoking habits
                    </div>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-label-row">
                            <span class="stat-label">Smoke-free days</span>
                            <span class="stat-icon"><i data-lucide="calendar-check"></i></span>
                        </div>
                        <div class="stat-value"><?php echo $days_smoke_free; ?></div>
                        <div class="stat-sub">Total days without cigarettes</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-label-row">
                            <span class="stat-label">Money saved</span>
                            <span class="stat-icon"><i data-lucide="wallet"></i></span>
                        </div>
                        <div class="stat-value">₹<?php echo number_format($money_saved, 2); ?></div>
                        <div class="stat-sub">Estimated savings after quitting</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-label-row">
                            <span class="stat-label">Lung capacity</span>
                            <span class="stat-icon"><i data-lucide="wind"></i></span>
                        </div>
                        <div class="stat-value"><?php echo $lung_capacity_percentage; ?>%</div>
                        <div class="stat-sub">Recovered lung function</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-label-row">
                            <span class="stat-label">Health score</span>
                            <span class="stat-icon"><i data-lucide="heart"></i></span>
                        </div>
                        <div class="stat-value"><?php echo $health_score; ?>/100</div>
                        <div class="stat-sub">Overall health improvement</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-label-row">
                            <span class="stat-label">Cigarettes avoided</span>
                            <span class="stat-icon"><i data-lucide="cigarette-off"></i></span>
                        </div>
                        <div class="stat-value"><?php echo $total_cigs_avoided; ?></div>
                        <div class="stat-sub">Not smoked since starting</div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-label-row">
                            <span class="stat-label">Current streak</span>
                            <span class="stat-icon"><i data-lucide="trending-up"></i></span>
                        </div>
                        <div class="stat-value"><?php echo $streak; ?> days</div>
                        <div class="stat-sub">Longest streak: <?php echo $longest_streak; ?> days</div>
                    </div>
                </div>
            </section>

            <p class="footer-note">
                This is a read-only shared report. To start your own journey, search for CosmoQuit.
            </p>
        <?php endif; ?>
    </div>
</main>

<script>
    lucide.createIcons();
</script>
</body>
</html>