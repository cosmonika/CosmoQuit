<?php  
require_once 'includes/session.php';
require_once 'includes/config.php';  
require_once 'includes/Database.php';  
require_once 'includes/auth.php';  

checkAuth();  

$db = new Database();  
$conn = $db->getConnection();  
$user_id = $_SESSION['user_id'];  

// -----------------------------------------------------------------------------  
// 1. LOAD USER + PROGRESS + SETTINGS  
// -----------------------------------------------------------------------------  
$user_stmt = $conn->prepare("  
    SELECT u.*, up.*, us.notification_enabled, us.theme_color   
    FROM users u   
    LEFT JOIN user_progress up ON u.id = up.user_id   
    LEFT JOIN user_settings us ON u.id = us.user_id   
    WHERE u.id = ?  
");  
$user_stmt->bind_param("i", $user_id);  
$user_stmt->execute();  
$user = $user_stmt->get_result()->fetch_assoc();  

if (!$user) {  
    header("Location: logout.php");  
    exit();  
}  

// Progress values  
$current_day = isset($user['current_day']) ? (int)$user['current_day'] : 0;  
if ($current_day < 0) $current_day = 0;  
if ($current_day > 30) $current_day = 30;  

// Stats values  
$cigarettes_per_day       = (int)($user['cigarettes_per_day'] ?? 0);  
$cigarette_cost           = (float)($user['cigarette_cost'] ?? 0);  
$total_days_smoke_free    = (int)($user['total_days_smoke_free'] ?? 0);  
$total_money_saved        = (float)($user['total_money_saved'] ?? 0);  
$lung_capacity_percentage = (int)($user['lung_capacity_percentage'] ?? 0);  
$current_streak           = (int)($user['current_streak'] ?? 0);  
$longest_streak           = (int)($user['longest_streak'] ?? 0);  
$health_score             = (int)($user['health_score'] ?? 0);  

// -----------------------------------------------------------------------------  
// 2. LOAD QUOTES (WELCOME + POPUP)  
// -----------------------------------------------------------------------------  
$quotes_data = [];  
$quotes_file = 'data/quotes_advice.json';  

if (file_exists($quotes_file)) {  
    $quotes_json = file_get_contents($quotes_file);  
    $quotes_data = json_decode($quotes_json, true);  
    if (!is_array($quotes_data)) {  
        $quotes_data = [];  
    }  
}  

// Welcome quote  
$welcome_quote = null;  
if (!empty($quotes_data['quotes']) && is_array($quotes_data['quotes'])) {  
    $idx = array_rand($quotes_data['quotes']);  
    $welcome_quote = $quotes_data['quotes'][$idx];  
}  

// -----------------------------------------------------------------------------  
// 3. LOAD DAILY TASKS TEMPLATE FROM JSON  
// -----------------------------------------------------------------------------  
$today            = date('Y-m-d');  
$today_task_data  = null;  
$tasks_file       = 'data/daily_tasks.json';  

if (file_exists($tasks_file)) {  
    $tasks_json = file_get_contents($tasks_file);  
    $all_tasks  = json_decode($tasks_json, true);  

    if (is_array($all_tasks)) {  
        $day_key = 'day_' . $current_day;  
        if (isset($all_tasks[$day_key])) {  
            $today_task_data = $all_tasks[$day_key];  
        } else {  
            if ($current_day == 0 && isset($all_tasks['day_0'])) {  
                $today_task_data = $all_tasks['day_0'];  
            } elseif ($current_day >= 1 && $current_day <= 10 && isset($all_tasks['day_1'])) {  
                $today_task_data = $all_tasks['day_1'];  
            } elseif ($current_day >= 11 && $current_day <= 20 && isset($all_tasks['day_11'])) {  
                $today_task_data = $all_tasks['day_11'];  
            } elseif ($current_day >= 21 && $current_day <= 30 && isset($all_tasks['day_21'])) {  
                $today_task_data = $all_tasks['day_21'];  
            }  
        }  
    }  
}  

// -----------------------------------------------------------------------------  
// 4. FETCH TODAY'S TASKS FROM DB (IF ANY)  
// -----------------------------------------------------------------------------  
$task_stmt = $conn->prepare("  
    SELECT * FROM daily_tasks  
    WHERE user_id = ? AND task_date = ?  
    ORDER BY id ASC  
");  
$task_stmt->bind_param("is", $user_id, $today);  
$task_stmt->execute();  
$today_tasks = $task_stmt->get_result()->fetch_all(MYSQLI_ASSOC);  

// -----------------------------------------------------------------------------  
// 5. IF NO TASKS IN DB â†’ INSERT FROM JSON TEMPLATE (FIXED VERSION)
// -----------------------------------------------------------------------------  
if (empty($today_tasks) && $today_task_data && $current_day <= 30) {
    if (!empty($today_task_data['tasks']) && is_array($today_task_data['tasks'])) {
        foreach ($today_task_data['tasks'] as $t) {
            if (!isset($t['type'], $t['description'], $t['title'])) {
                continue;
            }

            $user_id_int      = (int)$user_id;
            $task_day_int     = (int)$current_day;
            $task_type_str    = (string)$t['type'];
            $task_title_str   = (string)$t['title'];
            $task_desc_str    = (string)$t['description'];
            $task_date_str    = $today;
            $is_completed_int = 0;
            $skipped_int      = 0;

            $insert_stmt = $conn->prepare("
                INSERT INTO daily_tasks
                    (user_id, task_day, task_type, task_title, task_description, task_date, is_completed, skipped)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            if (!$insert_stmt) {
                error_log('daily_tasks INSERT prepare failed: ' . $conn->error);
                continue;
            }

            $insert_stmt->bind_param(
                "iissssii",
                $user_id_int,
                $task_day_int,
                $task_type_str,
                $task_title_str,
                $task_desc_str,
                $task_date_str,
                $is_completed_int,
                $skipped_int
            );

            if (!$insert_stmt->execute()) {
                error_log('daily_tasks INSERT execute failed: ' . $insert_stmt->error);
            }
        }
    }

    // Reload from DB after insert
    $task_stmt->execute();
    $today_tasks = $task_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// -----------------------------------------------------------------------------  
// 6. CHECK IF ALL TASKS COMPLETED  
// -----------------------------------------------------------------------------  
$all_tasks_completed = false;  
if (!empty($today_tasks)) {  
    $completed_count = 0;  
    foreach ($today_tasks as $task) {  
        if (!empty($task['is_completed'])) {  
            $completed_count++;  
        }  
    }  
    $all_tasks_completed = ($completed_count === count($today_tasks));  
}  

// -----------------------------------------------------------------------------  
// 7. DETERMINE CHALLENGE PHASE (FOR BADGE / COLORS)  
// -----------------------------------------------------------------------------  
if ($current_day === 0) {  
    $challenge_phase = "Preparation";  
    $phase_color     = "#10b981";  
    $phase_icon      = "sparkles";  
} elseif ($current_day <= 10) {  
    $challenge_phase = "Easy";  
    $phase_color     = "#10b981";  
    $phase_icon      = "smile";  
} elseif ($current_day <= 20) {  
    $challenge_phase = "Medium";  
    $phase_color     = "#f59e0b";  
    $phase_icon      = "meh";  
} else {  
    $challenge_phase = "Hard";  
    $phase_color     = "#ef4444";  
    $phase_icon      = "frown";  
}  

// -----------------------------------------------------------------------------  
// 8. HANDLE SMOKING INCIDENT (RESET STREAK)  
// -----------------------------------------------------------------------------  
$show_warning = false;  

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['smoke_incident'])) {  
    $cigarettes_count = isset($_POST['cigarettes_count']) ? (int)$_POST['cigarettes_count'] : 1;  
    $reason           = $_POST['reason'] ?? '';  
    $incident_date    = date('Y-m-d');  

    $incident_stmt = $conn->prepare("  
        INSERT INTO smoking_incidents  
            (user_id, incident_date, incident_time, cigarettes_count, reason, triggered_reset)  
        VALUES (?, ?, NOW(), ?, ?, TRUE)  
    ");  
    $incident_stmt->bind_param("isis", $user_id, $incident_date, $cigarettes_count, $reason);  
    $incident_stmt->execute();  

    // Reset progress  
    $reset_stmt = $conn->prepare("  
        UPDATE user_progress  
        SET current_day = 0,  
            current_streak = 0,  
            last_reset_date = CURDATE()  
        WHERE user_id = ?  
    ");  
    $reset_stmt->bind_param("i", $user_id);  
    $reset_stmt->execute();  

    $show_warning = true;  
    header("Location: home.php");  
    exit();  
}  

// -----------------------------------------------------------------------------  
// 9. HANDLE TASK COMPLETION (FIXED VERSION)
// -----------------------------------------------------------------------------  
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_task'])) {  
    $task_id = (int)$_POST['task_id'];  

    // Mark this task as completed  
    $complete_stmt = $conn->prepare("  
        UPDATE daily_tasks  
        SET is_completed = 1,  
            skipped = 0,
            completed_at = NOW()  
        WHERE id = ? AND user_id = ?  
    ");  
    $complete_stmt->bind_param("ii", $task_id, $user_id);  
    $complete_stmt->execute();  

    // Re-check if ALL tasks for today are now completed  
    $check_all_stmt = $conn->prepare("  
        SELECT COUNT(*) AS total, SUM(is_completed) AS completed  
        FROM daily_tasks  
        WHERE user_id = ? AND task_date = ?  
    ");  
    $check_all_stmt->bind_param("is", $user_id, $today);  
    $check_all_stmt->execute();  
    $task_status = $check_all_stmt->get_result()->fetch_assoc();  

    if ($task_status['total'] > 0 && (int)$task_status['completed'] === (int)$task_status['total']) {  
        // All tasks completed -> move progress to next challenge day  
        $next_day = $current_day + 1;  

        if ($next_day <= 30) {  
            $update_stmt = $conn->prepare("  
                UPDATE user_progress  
                SET current_day = ?,  
                    total_days_smoke_free = total_days_smoke_free + 1,  
                    current_streak = current_streak + 1,  
                    longest_streak = GREATEST(longest_streak, current_streak + 1),  
                    total_cigarettes_avoided = total_cigarettes_avoided + ?,  
                    total_money_saved = total_money_saved + (? * ?),  
                    lung_capacity_percentage = LEAST(100, lung_capacity_percentage + 3),  
                    health_score = LEAST(100, health_score + 5)  
                WHERE user_id = ?  
            ");  
            $update_stmt->bind_param("iiddi", 
                $next_day, 
                $cigarettes_per_day, 
                $cigarettes_per_day,
                $cigarette_cost,
                $user_id
            );  
            $update_stmt->execute();  
        }  
    }  

    header("Location: home.php");  
    exit();  
}  

// -----------------------------------------------------------------------------  
// 10. HANDLE TASK SKIPPING (FIXED VERSION)
// -----------------------------------------------------------------------------  
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['skip_task'])) {  
    $task_id = (int)$_POST['task_id'];  

    // Mark this task as skipped  
    $skip_stmt = $conn->prepare("  
        UPDATE daily_tasks  
        SET skipped = 1,  
            is_completed = 0,
            completed_at = NULL  
        WHERE id = ? AND user_id = ?  
    ");  
    $skip_stmt->bind_param("ii", $task_id, $user_id);  
    $skip_stmt->execute();  

    header("Location: home.php");  
    exit();  
}  

// -----------------------------------------------------------------------------  
// 11. LOAD BLOGS FROM JSON  
// -----------------------------------------------------------------------------  
$blogs_data = [];  
$blogs_file = 'data/blogs.json';  

if (file_exists($blogs_file)) {  
    $blogs_json = file_get_contents($blogs_file);  
    $blogs_data = json_decode($blogs_json, true);  
    if (!is_array($blogs_data)) {  
        $blogs_data = [];  
    }  
}  

// Get latest 3 blogs  
$latest_blogs = [];  
if (!empty($blogs_data) && is_array($blogs_data)) {  
    usort($blogs_data, function($a, $b) {  
        return strtotime($b['date']) - strtotime($a['date']);  
    });  
    $latest_blogs = array_slice($blogs_data, 0, 3);  
}  

// -----------------------------------------------------------------------------  
// 12. BUILD 30-DAY CHALLENGE GRID  
// -----------------------------------------------------------------------------  
$challenge_days = [];  
for ($i = 1; $i <= 30; $i++) {  
    $challenge_days[] = [  
        'day'       => $i,  
        'completed' => $i <= $current_day,  
        'current'   => $i === $current_day,  
        'phase'     => $i <= 10 ? 'easy' : ($i <= 20 ? 'medium' : 'hard'),  
    ];  
}  

// -----------------------------------------------------------------------------  
// 13. RANDOM QUOTE / ADVICE / FACT FOR POPUP  
// -----------------------------------------------------------------------------  
$popup_content = null;  
if (!empty($quotes_data)) {  
    $all_content = array_merge(  
        $quotes_data['quotes'] ?? [],  
        $quotes_data['advice'] ?? [],  
        $quotes_data['facts']  ?? []  
    );  

    if (!empty($all_content)) {  
        $idx = array_rand($all_content);  
        $popup_content = $all_content[$idx];  
    }  
}  
?>  

<!DOCTYPE html>  
<html lang="en">  
<head>  
    <meta charset="UTF-8">  
    <meta name="viewport" content="width=device-width, initial-scale=1.0">  
    <title>CosmoQuit - Dashboard</title>  
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>  
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">  
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
<style>
    :root {
        /* Purple Theme - Subdued & Calming */
        --primary: #8B5CF6;          /* Purple */
        --primary-light: #A78BFA;    /* Light Purple */
        --primary-dark: #7C3AED;     /* Dark Purple */
        --primary-soft: rgba(139, 92, 246, 0.1); /* Very soft purple */
        --primary-transparent: rgba(139, 92, 246, 0.08); /* Extra soft */
        
        /* Neutral Backgrounds - Dark but soft */
        --main-bg: #0F0B16;          /* Deep dark purple-black */
        --card-bg: rgba(30, 27, 36, 0.9); /* Dark purple-gray with transparency */
        --card-border: rgba(139, 92, 246, 0.15); /* Subtle purple border */
        
        /* Text Colors */
        --text-primary: #F3E8FF;     /* Soft lavender white */
        --text-muted: #C4B5FD;       /* Muted purple */
        --text-light: #A78BFA;       /* Light purple */
        
        /* Status Colors - Keep original but softer */
        --success: #10B981;          /* Emerald Green */
        --success-soft: rgba(16, 185, 129, 0.2);
        --warning: #F59E0B;          /* Amber */
        --warning-soft: rgba(245, 158, 11, 0.2);
        
        /* Accent Colors */
        --accent-teal: #14B8A6;      /* Teal */
        --highlight-yellow: #FCD34D; /* Golden Yellow */
        
        /* Challenge Phase Colors - Softer versions */
        --phase-easy: #10B981;       /* Green */
        --phase-easy-soft: rgba(16, 185, 129, 0.25);
        --phase-medium: #F59E0B;     /* Yellow/Orange */
        --phase-medium-soft: rgba(245, 158, 11, 0.25);
        --phase-hard: #EF4444;       /* Red */
        --phase-hard-soft: rgba(239, 68, 68, 0.25);
        
        /* Glass Effect Colors - Purple Theme */
        --glass-bg: rgba(30, 27, 36, 0.7);
        --glass-border: rgba(139, 92, 246, 0.12);
        --glass-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
        --glass-shadow-heavy: 0 8px 30px rgba(0, 0, 0, 0.35);
        --glass-backdrop: blur(20px) saturate(180%);
        
        /* Hero Section Glass Effect */
        --hero-glass-bg: rgba(139, 92, 246, 0.08);
        --hero-glass-border: rgba(139, 92, 246, 0.15);
        --hero-glass-shadow: 0 8px 32px rgba(139, 92, 246, 0.1);
        
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
        
        /* Gray scale - Purple tinted */
        --gray-50: #FAF5FF;
        --gray-100: #F3E8FF;
        --gray-200: #E9D5FF;
        --gray-300: #D8B4FE;
        --gray-400: #C4B5FD;
        --gray-500: #A78BFA;
        --gray-600: #8B5CF6;
        --gray-700: #7C3AED;
        --gray-800: #6D28D9;
        --gray-900: #0F0B16;
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
        background-image: 
            radial-gradient(circle at 10% 20%, rgba(139, 92, 246, 0.05) 0%, transparent 20%),
            radial-gradient(circle at 90% 80%, rgba(167, 139, 250, 0.05) 0%, transparent 20%);
    }

    .container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 16px;
    }

    /* Glass Header - Soft Purple */
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

    .header-container {
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
        flex-shrink: 0;
    }

    .logo-png {
        width: 36px;
        height: 36px;
        object-fit: contain;
        margin-right: 0px;
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

    .header-actions {
        display: flex;
        gap: 6px;
        align-items: center;
    }

    .icon-button {
        background: rgba(30, 27, 36, 0.8);
        border: 1px solid var(--glass-border);
        cursor: pointer;
        color: var(--text-muted);
        padding: 8px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.3s ease;
        position: relative;
        width: 44px;
        height: 44px;
        backdrop-filter: blur(10px);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    }

    .icon-button:hover {
        background: rgba(139, 92, 246, 0.2);
        color: var(--primary);
        transform: translateY(-2px);
        box-shadow: 0 8px 16px rgba(139, 92, 246, 0.15);
        border-color: rgba(139, 92, 246, 0.3);
    }

    .icon-button:active {
        transform: translateY(0);
    }

    .icon-button i {
        width: 20px;
        height: 20px;
    }

    /* Main Content */
    .main-content {
        padding: 24px 0;
        min-height: calc(100vh - 120px);
    }

    /* Hero Section - Glassy Foggy Purple */
    .welcome-section {
        background: var(--hero-glass-bg);
        backdrop-filter: blur(24px) saturate(180%);
        -webkit-backdrop-filter: blur(24px) saturate(180%);
        border-radius: 20px;
        padding: 32px;
        color: var(--text-primary);
        margin-bottom: 32px;
        border: 1px solid var(--hero-glass-border);
        box-shadow: var(--hero-glass-shadow);
        position: relative;
        overflow: hidden;
    }

    /* Foggy overlay effect */
    .welcome-section::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: 
            radial-gradient(circle at 20% 80%, rgba(139, 92, 246, 0.15) 0%, transparent 50%),
            radial-gradient(circle at 80% 20%, rgba(167, 139, 250, 0.1) 0%, transparent 50%);
        border-radius: 20px;
        z-index: -1;
    }

    .welcome-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 16px;
        position: relative;
        z-index: 1;
    }

    .welcome-header h1 {
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 8px;
        color: var(--text-primary);
        text-shadow: 0 2px 8px rgba(139, 92, 246, 0.2);
    }

    .welcome-header p {
        color: var(--text-muted);
    }

    .current-day {
        background: rgba(139, 92, 246, 0.15);
        padding: 8px 16px;
        border-radius: 20px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
        backdrop-filter: blur(10px);
        border: 1px solid rgba(139, 92, 246, 0.25);
        color: var(--primary-light);
    }

    .motivation-quote {
        font-style: italic;
        margin-top: 24px;
        padding: 24px;
        background: rgba(139, 92, 246, 0.08);
        border-radius: 16px;
        backdrop-filter: blur(10px);
        border: 1px solid rgba(139, 92, 246, 0.15);
        color: var(--text-muted);
        position: relative;
        z-index: 1;
    }

    .quote-author {
        text-align: right;
        font-size: 14px;
        opacity: 0.8;
        margin-top: 12px;
        color: var(--primary-light);
    }

    /* Stats Grid - Soft Purple Cards */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 24px;
        margin-bottom: 32px;
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
    }

    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 12px 32px rgba(139, 92, 246, 0.15);
        border-color: rgba(139, 92, 246, 0.25);
        background: rgba(30, 27, 36, 0.95);
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
    }

    .stat-subtext {
        font-size: 14px;
        color: var(--text-muted);
        margin-top: 4px;
    }

    /* Blogs Section - Soft Purple Cards */
    .blogs-section {
        background: var(--card-bg);
        backdrop-filter: var(--glass-backdrop);
        -webkit-backdrop-filter: var(--glass-backdrop);
        border-radius: 16px;
        padding: 32px;
        border: 1px solid var(--card-border);
        box-shadow: var(--glass-shadow);
        margin-bottom: 32px;
    }

    .blogs-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 24px;
        margin-top: 16px;
    }

    .blog-card {
        background: var(--card-bg);
        backdrop-filter: var(--glass-backdrop);
        -webkit-backdrop-filter: var(--glass-backdrop);
        border-radius: 16px;
        overflow: hidden;
        transition: all 0.3s ease;
        cursor: pointer;
        border: 1px solid var(--card-border);
        box-shadow: var(--glass-shadow);
    }

    .blog-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 20px 40px rgba(139, 92, 246, 0.15);
        border-color: rgba(139, 92, 246, 0.25);
        background: rgba(30, 27, 36, 0.95);
    }

    .blog-thumbnail {
        width: 100%;
        height: 180px;
        object-fit: cover;
    }

    .blog-content {
        padding: 20px;
    }

    .blog-category {
        display: inline-block;
        background: rgba(139, 92, 246, 0.2);
        color: var(--primary);
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        margin-bottom: 8px;
        backdrop-filter: blur(10px);
        border: 1px solid rgba(139, 92, 246, 0.3);
    }

    .blog-title {
        font-size: 18px;
        font-weight: 700;
        color: var(--text-primary);
        margin-bottom: 8px;
    }

    .blog-description {
        font-size: 14px;
        color: var(--text-muted);
        margin-bottom: 12px;
        line-height: 1.5;
    }

    .blog-date {
        font-size: 12px;
        color: var(--text-muted);
    }

    .under-development {
        text-align: center;
        padding: 40px;
        background: var(--card-bg);
        backdrop-filter: var(--glass-backdrop);
        -webkit-backdrop-filter: var(--glass-backdrop);
        border-radius: 16px;
        border: 2px dashed rgba(139, 92, 246, 0.3);
    }

    .under-development i {
        color: var(--primary);
        margin-bottom: 16px;
    }

    /* Challenge Section - Soft Purple Cards */
    .challenge-section {
        background: var(--card-bg);
        backdrop-filter: var(--glass-backdrop);
        -webkit-backdrop-filter: var(--glass-backdrop);
        border-radius: 16px;
        padding: 32px;
        border: 1px solid var(--card-border);
        box-shadow: var(--glass-shadow);
        margin-bottom: 32px;
    }

    .section-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
    }

    .section-header h2 {
        font-size: 20px;
        font-weight: 700;
        color: var(--text-primary);
    }

    /* Challenge Phase Colors - Softer versions */
    .challenge-phase {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 16px;
        border-radius: 20px;
        font-weight: 600;
        background: var(--phase-easy-soft);
        color: var(--phase-easy);
        border: 1px solid rgba(16, 185, 129, 0.4);
        backdrop-filter: blur(10px);
    }

    .challenge-phase.medium {
        background: var(--phase-medium-soft);
        color: var(--phase-medium);
        border-color: rgba(245, 158, 11, 0.4);
    }
    
    .challenge-phase.hard {
        background: var(--phase-hard-soft);
        color: var(--phase-hard);
        border-color: rgba(239, 68, 68, 0.4);
    }

    .challenge-phase.preparation {
        background: rgba(139, 92, 246, 0.25);
        color: var(--primary);
        border-color: rgba(139, 92, 246, 0.4);
    }

    .challenge-days {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 24px;
        justify-content: center;
    }

    .day-box {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        position: relative;
        cursor: pointer;
        transition: all 0.2s ease;
        font-size: 14px;
        background: rgba(30, 27, 36, 0.8);
        border: 1px solid var(--card-border);
        color: var(--text-muted);
        backdrop-filter: blur(5px);
    }

    /* Day boxes with softer colors */
    .day-box.easy {
        background: var(--phase-easy-soft);
        color: var(--phase-easy);
        border-color: rgba(16, 185, 129, 0.4);
    }

    .day-box.medium {
        background: var(--phase-medium-soft);
        color: var(--phase-medium);
        border-color: rgba(245, 158, 11, 0.4);
    }

    .day-box.hard {
        background: var(--phase-hard-soft);
        color: var(--phase-hard);
        border-color: rgba(239, 68, 68, 0.4);
    }

    .day-box.completed {
        background: var(--success);
        color: white;
        border-color: rgba(16, 185, 129, 0.5);
    }

    .day-box.current {
        border: 3px solid var(--primary);
        transform: scale(1.1);
        box-shadow: 0 4px 16px rgba(139, 92, 246, 0.3);
        background: rgba(139, 92, 246, 0.2);
    }

    /* Tasks Section - Soft Purple Cards */
    .tasks-section {
        background: var(--card-bg);
        backdrop-filter: var(--glass-backdrop);
        -webkit-backdrop-filter: var(--glass-backdrop);
        border-radius: 16px;
        padding: 32px;
        border: 1px solid var(--card-border);
        box-shadow: var(--glass-shadow);
        margin-bottom: 32px;
    }

    .task-list {
        display: flex;
        flex-direction: column;
        gap: 16px;
    }

    .task-item {
        background: var(--card-bg);
        backdrop-filter: var(--glass-backdrop);
        -webkit-backdrop-filter: var(--glass-backdrop);
        border-radius: 16px;
        padding: 20px;
        border: 1px solid var(--card-border);
        transition: all 0.3s ease;
        box-shadow: var(--glass-shadow);
    }

    .task-item:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 28px rgba(139, 92, 246, 0.15);
        border-color: rgba(139, 92, 246, 0.25);
        background: rgba(30, 27, 36, 0.95);
    }

    .task-item.completed {
        background: rgba(16, 185, 129, 0.15);
        border-color: rgba(16, 185, 129, 0.3);
    }

    .task-item.skipped {
        background: rgba(245, 158, 11, 0.15);
        border-color: rgba(245, 158, 11, 0.3);
    }

    .task-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
    }

    .task-title {
        font-size: 16px;
        font-weight: 600;
        color: var(--text-primary);
        margin: 0;
    }

    .task-type {
        display: flex;
        align-items: center;
        gap: 6px;
        background: rgba(139, 92, 246, 0.2);
        color: var(--primary);
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
        border: 1px solid rgba(139, 92, 246, 0.3);
    }

    .task-type.walk { background: var(--task-walk); color: #BEF264; border-color: rgba(190, 242, 100, 0.3); }
    .task-type.water { background: var(--task-water); color: #60A5FA; border-color: rgba(96, 165, 250, 0.3); }
    .task-type.breathing { background: var(--task-breathing); color: #67E8F9; border-color: rgba(103, 232, 249, 0.3); }
    .task-type.mind { background: var(--task-mind); color: #A78BFA; border-color: rgba(167, 139, 250, 0.3); }
    .task-type.nutrition { background: var(--task-nutrition); color: #FBCFE8; border-color: rgba(251, 207, 232, 0.3); }
    .task-type.environment { background: var(--task-environment); color: #5EEAD4; border-color: rgba(94, 234, 212, 0.3); }
    .task-type.sleep { background: var(--task-sleep); color: #94A3B8; border-color: rgba(148, 163, 184, 0.3); }
    .task-type.social { background: var(--task-social); color: #FDBA74; border-color: rgba(253, 186, 116, 0.3); }
    .task-type.health { background: var(--task-health); color: #FB7185; border-color: rgba(251, 113, 133, 0.3); }
    .task-type.reward { background: var(--task-reward); color: #FCD34D; border-color: rgba(252, 211, 77, 0.3); }
    .task-type.general { background: var(--task-general); color: #94A3B8; border-color: rgba(148, 163, 184, 0.3); }

    .task-type i {
        width: 14px;
        height: 14px;
    }

    .task-description {
        color: var(--text-muted);
        font-size: 14px;
        line-height: 1.5;
        margin-bottom: 12px;
    }

    .task-duration {
        display: flex;
        align-items: center;
        gap: 6px;
        color: var(--text-muted);
        font-size: 13px;
        font-weight: 500;
        margin-bottom: 16px;
    }

    .task-duration i {
        width: 14px;
        height: 14px;
    }

    .task-status {
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: rgba(139, 92, 246, 0.1);
        border-radius: 10px;
        padding: 10px 14px;
        margin-bottom: 16px;
        font-size: 13px;
        font-weight: 500;
        opacity: 0;
        height: 0;
        overflow: hidden;
        transition: all 0.3s ease;
        border: 1px solid rgba(139, 92, 246, 0.2);
    }

    .task-item.completed .task-status,
    .task-item.skipped .task-status {
        opacity: 1;
        height: auto;
        padding: 10px 14px;
        margin-bottom: 16px;
    }

    .task-item.completed .task-status {
        background: rgba(16, 185, 129, 0.2);
        color: #10B981;
        border: 1px solid rgba(16, 185, 129, 0.3);
    }

    .task-item.skipped .task-status {
        background: rgba(245, 158, 11, 0.2);
        color: #F59E0B;
        border: 1px solid rgba(245, 158, 11, 0.3);
    }

    .status-left {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .status-right {
        font-weight: 400;
        color: var(--text-muted);
    }

    .task-actions {
        display: flex;
        gap: 12px;
    }

    .task-button {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 10px 16px;
        border-radius: 10px;
        border: none;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s ease;
        backdrop-filter: blur(10px);
    }

    .task-button.complete {
        background: var(--success);
        color: white;
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2);
    }

    .task-button.complete:hover:not(.disabled) {
        background: #0DA671;
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(16, 185, 129, 0.3);
    }

    .task-button.skip {
        background: rgba(139, 92, 246, 0.2);
        color: var(--text-muted);
        border: 1px solid rgba(139, 92, 246, 0.3);
    }

    .task-button.skip:hover:not(.disabled) {
        background: rgba(139, 92, 246, 0.3);
        transform: translateY(-2px);
        color: var(--primary);
        box-shadow: 0 6px 20px rgba(139, 92, 246, 0.15);
    }

    .task-button.disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none !important;
    }

    .task-button i {
        width: 16px;
        height: 16px;
    }

    .no-tasks {
        text-align: center;
        padding: 40px;
        background: var(--card-bg);
        backdrop-filter: var(--glass-backdrop);
        -webkit-backdrop-filter: var(--glass-backdrop);
        border-radius: 16px;
        border: 2px dashed rgba(139, 92, 246, 0.3);
    }

    .no-tasks h4 {
        font-size: 18px;
        color: var(--text-primary);
        margin-bottom: 8px;
    }

    .no-tasks p {
        color: var(--text-muted);
        font-size: 14px;
    }

    .congratulations-message {
        text-align: center;
        padding: 32px;
        background: linear-gradient(135deg, rgba(16, 185, 129, 0.9) 0%, rgba(5, 150, 105, 0.9) 100%);
        border-radius: 16px;
        color: white;
        border: 1px solid rgba(255, 255, 255, 0.2);
        box-shadow: var(--glass-shadow-heavy);
        backdrop-filter: blur(10px);
    }

    .congratulations-message h3 {
        font-size: 22px;
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
    }

    .congratulations-message p {
        font-size: 15px;
        opacity: 0.9;
        max-width: 500px;
        margin: 0 auto;
        line-height: 1.5;
    }

    .smoke-button-container {
        text-align: center;
        margin: 32px 0;
    }

    .btn-danger {
        background: linear-gradient(135deg, rgba(239, 68, 68, 0.9) 0%, rgba(220, 38, 38, 0.9) 100%);
        color: white;
        padding: 14px 32px;
        border: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 12px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 8px 24px rgba(239, 68, 68, 0.3);
        backdrop-filter: blur(10px);
    }

    .btn-danger:hover {
        transform: translateY(-3px);
        box-shadow: 0 15px 35px rgba(239, 68, 68, 0.4);
        background: linear-gradient(135deg, rgba(239, 68, 68, 1) 0%, rgba(220, 38, 38, 1) 100%);
    }

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
    }

    .footer-nav .icon-button.active {
        color: var(--primary);
        background: rgba(139, 92, 246, 0.2);
        border-color: rgba(139, 92, 246, 0.4);
    }

    /* Modal Styles */
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.7);
        backdrop-filter: blur(8px);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 1000;
    }

    .modal-overlay.active {
        display: flex;
    }

    .modal {
        background: var(--card-bg);
        backdrop-filter: var(--glass-backdrop);
        -webkit-backdrop-filter: var(--glass-backdrop);
        border-radius: 20px;
        padding: 32px;
        max-width: 500px;
        width: 90%;
        border: 1px solid var(--card-border);
        box-shadow: var(--glass-shadow-heavy);
        animation: modalFadeIn 0.3s ease;
    }

    @keyframes modalFadeIn {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 16px;
    }

    .modal-header h2 {
        font-size: 24px;
        font-weight: 700;
        color: var(--primary);
    }

    .modal-content {
        margin-bottom: 24px;
    }

    .modal-content p {
        font-size: 16px;
        color: var(--text-muted);
        margin-bottom: 16px;
    }

    .modal-content label {
        display: block;
        margin-bottom: 8px;
        font-weight: 500;
        color: var(--text-primary);
    }

    .modal-content select,
    .modal-content textarea {
        width: 100%;
        padding: 12px;
        border: 1px solid var(--card-border);
        background: rgba(30, 27, 36, 0.8);
        border-radius: 10px;
        margin-bottom: 16px;
        font-family: 'Inter', sans-serif;
        color: var(--text-primary);
        backdrop-filter: blur(10px);
    }

    .modal-actions {
        display: flex;
        justify-content: flex-end;
        gap: 12px;
        align-items: center;
    }

    .modal-button {
        padding: 10px 20px;
        border-radius: 10px;
        font-weight: 500;
        cursor: pointer;
        border: none;
        transition: all 0.2s ease;
        backdrop-filter: blur(10px);
    }

    .modal-button.primary {
        background-color: var(--primary);
        color: white;
    }

    .modal-button.primary:hover {
        background-color: var(--primary-dark);
    }

    .modal-button.danger {
        background-color: var(--phase-hard);
        color: white;
    }

    .modal-button.danger:hover {
        background-color: #DC2626;
    }

    .modal-button.warning {
        background-color: var(--phase-medium);
        color: white;
    }

    .modal-button.warning:hover {
        background-color: #D97706;
    }

    .modal-button.secondary {
        background-color: rgba(139, 92, 246, 0.2);
        color: var(--text-muted);
        border: 1px solid rgba(139, 92, 246, 0.3);
    }

    .modal-button.secondary:hover {
        background-color: rgba(139, 92, 246, 0.3);
        color: var(--primary);
    }

/* Game Grid */
#gameGridContainer {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 18px;
    place-items: center;
}

/* HERO-STYLE GAME CARD */
.game-card-small {
    width: 110px;
    height: 110px;
    border-radius: 20px;
    padding: 18px;

    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 12px; /* ðŸ‘ˆ space between icon & text */

    text-decoration: none;
    cursor: pointer;
    position: relative;

    /* Hero / Glass background */
    background: linear-gradient(
        135deg,
        rgba(139, 92, 246, 0.18),
        rgba(139, 92, 246, 0.05)
    );

    backdrop-filter: blur(18px) saturate(160%);
    -webkit-backdrop-filter: blur(18px) saturate(160%);

    border: 1px solid rgba(139, 92, 246, 0.35);

    /* Faint glow */
    box-shadow:
        0 0 0 rgba(139, 92, 246, 0),
        0 12px 30px rgba(139, 92, 246, 0.15);

    transition: all 0.35s ease;
}

/* Hover = hero lift + glow */
.game-card-small:hover {
    transform: translateY(-6px) scale(1.03);

    box-shadow:
        0 0 25px rgba(139, 92, 246, 0.45),
        0 25px 50px rgba(139, 92, 246, 0.35);

    border-color: rgba(167, 139, 250, 0.8);
}

/* ICON â€” PURE WHITE */
.game-card-small i {
    width: 30px;
    height: 30px;
    color: #ffffff;
    filter: drop-shadow(0 0 8px rgba(255, 255, 255, 0.35));
}

/* TEXT */
.game-card-small span {
    font-size: 12px;
    font-weight: 600;
    text-align: center;
    color: #ffffff;
    letter-spacing: 0.3px;
}

/* COMING SOON CARD */
.game-card-small.coming-soon {
    background: linear-gradient(
        135deg,
        rgba(30, 27, 36, 0.85),
        rgba(30, 27, 36, 0.6)
    );

    border: 1px solid rgba(196, 181, 253, 0.25);
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
}

.game-card-small.coming-soon i,
.game-card-small.coming-soon span {
    color: var(--text-muted);
    filter: none;
}

/* SOON BADGE â€” SOFT HERO TAG */
.soon-badge {
    position: absolute;
    top: 10px;
    right: 10px;

    background: rgba(245, 158, 11, 0.95);
    color: #0F0B16;

    font-size: 9px;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 999px;

    box-shadow: 0 6px 18px rgba(245, 158, 11, 0.4);
}

/* FORCE WHITE ICONS IN GAME CARDS (Lucide SVG FIX) */
.game-card-small svg {
    stroke: #ffffff !important;
    fill: none !important;
}

.game-card-small svg path {
    stroke: #ffffff !important;
}

/* Optional: slightly glowing white icon */
.game-card-small svg {
    filter: drop-shadow(0 0 6px rgba(255, 255, 255, 0.35));
}

    /* Quote Modal */
    .quote-modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.7);
        backdrop-filter: blur(8px);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 1000;
    }

    .quote-modal-overlay.active {
        display: flex;
    }

    .quote-modal {
        background: var(--card-bg);
        backdrop-filter: var(--glass-backdrop);
        -webkit-backdrop-filter: var(--glass-backdrop);
        border-radius: 20px;
        padding: 32px;
        max-width: 500px;
        width: 90%;
        border: 1px solid rgba(139, 92, 246, 0.3);
        box-shadow: var(--glass-shadow-heavy);
        animation: modalFadeIn 0.3s ease;
    }

    .quote-modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 16px;
    }

    .quote-modal-header h3 {
        font-size: 20px;
        font-weight: 700;
        color: var(--primary);
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .quote-modal-content {
        font-style: italic;
        color: var(--text-primary);
        line-height: 1.6;
        margin-bottom: 16px;
        font-size: 18px;
        text-align: center;
    }

    .quote-modal-author {
        text-align: center;
        font-size: 14px;
        color: var(--text-muted);
        font-style: normal;
        font-weight: 500;
    }

    .quote-modal-actions {
        display: flex;
        justify-content: center;
        margin-top: 20px;
    }

    /* Social Buttons */
    .social-buttons {
        display: flex;
        gap: 12px;
        margin-top: 16px;
    }

    .social-button {
        flex: 1;
        padding: 12px;
        border-radius: 10px;
        font-weight: 500;
        cursor: pointer;
        border: none;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        color: white;
        backdrop-filter: blur(10px);
    }

    .social-button.cosmobot {
        background-color: #0088cc;
    }

    .social-button.chat {
        background-color: #25D366;
    }

    .social-button.community {
        background-color: var(--primary);
    }

    .social-button:hover {
        opacity: 0.9;
        transform: translateY(-2px);
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
    }

    /* Responsive Design */
    @media (max-width: 768px) {
        .welcome-section {
            padding: 24px;
        }

        .challenge-section, .tasks-section, .blogs-section {
            padding: 24px;
        }

        .day-box {
            width: 32px;
            height: 32px;
            font-size: 12px;
        }

        .task-item {
            padding: 16px;
        }

        .task-actions {
            flex-direction: column;
        }

        .task-button {
            width: 100%;
        }

        .blogs-grid {
            grid-template-columns: 1fr;
        }

        .social-buttons {
            flex-direction: column;
        }

        #gameGridContainer {
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
        }

        .game-card-small {
            width: 90px;
            height: 90px;
            padding: 12px;
        }
    }

    @media (max-width: 480px) {
        #gameGridContainer {
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
        }

        .game-card-small {
            width: 80px;
            height: 80px;
            padding: 10px;
        }
        
        .game-card-small i {
            width: 24px;
            height: 24px;
        }
        
        .game-card-small span {
            font-size: 10px;
        }
        
        .welcome-header {
            flex-direction: column;
            gap: 16px;
            align-items: flex-start;
        }
        
        .current-day {
            align-self: flex-start;
        }
    }
</style>
</head>  
<body>  
    <!-- Glass Header -->  
    <header>  
        <div class="header-container">  
            <div class="logo-section">  
                <img src="assets/images/logo.png" alt="CosmoQuit Logo" class="logo-png">  
                <span class="logo-text">CosmoQuit</span>  
            </div>  
            <div class="header-actions">  
                <button class="icon-button" id="gamePadBtn">  
                    <i data-lucide="gamepad-2"></i>  
                </button>  
                <button class="icon-button" id="supportBtn">  
                    <i data-lucide="help-circle"></i>  
                </button>  
            </div>  
        </div>  
    </header>  

    <!-- Main Content -->  
    <main class="main-content">  
        <div class="container">  
            <!-- Welcome Section -->  
            <section class="welcome-section">  
                <div class="welcome-header">  
                     <div>  
                        <h1>Welcome, <?php echo htmlspecialchars($user['name']); ?>!</h1>  
                        <p>You're on day <?php echo $current_day; ?> of your 30-day smoke-free journey.</p>  
                    </div>  
                    <div class="current-day">  
                        <i data-lucide="calendar"></i>  
                        Day <?php echo $current_day; ?> of 30  
                    </div>  
                </div>  

                <?php if ($welcome_quote): ?>  
                <div class="motivation-quote">  
                    "<?php echo htmlspecialchars($welcome_quote['content']); ?>"  
                    <?php if (!empty($welcome_quote['author'])): ?>  
                    <div class="quote-author">â€” <?php echo htmlspecialchars($welcome_quote['author']); ?></div>  
                    <?php endif; ?>  
                </div>  
                <?php endif; ?>  
            </section>  

            <!-- Stats Grid -->  
            <div class="stats-grid">  
                <div class="stat-card">  
                    <h3>Money Saved</h3>  
                    <div class="stat-value">₹<?php echo number_format($total_money_saved, 2); ?></div>  
                    <div class="stat-subtext">Based on <?php echo $cigarettes_per_day; ?> cigarettes/day @ ₹<?php echo $cigarette_cost; ?> each</div>  
                </div>  

                <div class="stat-card">  
                    <h3>Lung Capacity</h3>  
                    <div class="stat-value"><?php echo $lung_capacity_percentage; ?>%</div>  
                    <div class="stat-subtext">Recovered (Target: 100%)</div>  
                </div>  

                <div class="stat-card">  
                    <h3>Current Streak</h3>  
                    <div class="stat-value"><?php echo $current_streak; ?> days</div>  
                    <div class="stat-subtext">Longest: <?php echo $longest_streak; ?> days</div>  
                </div>  

                <div class="stat-card">  
                    <h3>Health Score</h3>  
                    <div class="stat-value"><?php echo $health_score; ?>/100</div>  
                    <div class="stat-subtext">Overall health improvement</div>  
                </div>  
                
                
                
                <!-- Achievement Card - Same style and size as other stat cards -->
<div class="stat-card" onclick="window.location.href='achievement.php'" style="cursor: pointer; position: relative;">
    <h3>Achievements</h3>
    <div class="stat-value" style="font-size: 28px; color: var(--primary); margin-bottom: 4px;">
        View All
    </div>
    <div class="stat-subtext">Track your progress & badges</div>
    
    <!-- Large centered icon -->
    <img src="https://img.icons8.com/glassmorphism/480/warranty.png" alt="Achievements" 
         style="position: absolute; 
                top: 50%; 
                right: 20px; 
                transform: translateY(-50%); 
                width: 70px; 
                height: 70px; 
                opacity: 0.8;
                filter: drop-shadow(0 2px 12px rgba(139, 92, 246, 0.5));">
</div>


                
            </div>  

            <!-- 30-Day Challenge -->  
            <section class="challenge-section">  
                <div class="section-header">  
                    <h2>30-Day Smoke-Free Challenge</h2>  
                    <!-- FIXED: Phase colors now showing properly -->
                    <div class="challenge-phase <?php echo strtolower($challenge_phase); ?>">  
                        <i data-lucide="<?php echo $phase_icon; ?>"></i>  
                        <?php echo $challenge_phase; ?> Phase  
                    </div>  
                </div>  

                <div class="challenge-days">  
                    <?php foreach ($challenge_days as $day): ?>  
                        <div class="day-box <?php echo $day['phase']; ?> <?php echo $day['completed'] ? 'completed' : ''; ?> <?php echo $day['current'] ? 'current' : ''; ?>"  
                             title="Day <?php echo $day['day']; ?>: <?php echo ucfirst($day['phase']); ?>">  
                            <?php echo $day['day']; ?>  
                        </div>  
                    <?php endforeach; ?>  
                </div>  
            </section>  

            <!-- Blogs Section -->  
            <section class="blogs-section">  
                <div class="section-header">  
                    <h2>Latest Health Blogs</h2>  
                    <?php if (!empty($latest_blogs)): ?>  
                        <a href="blogs.php" style="color: var(--primary); text-decoration: none; font-weight: 500;">  
                            View All <i data-lucide="arrow-right"></i>  
                        </a>  
                    <?php endif; ?>  
                </div>  

                <?php if (!empty($latest_blogs)): ?>  
                    <div class="blogs-grid">  
                        <?php foreach ($latest_blogs as $blog): ?>  
                            <div class="blog-card" onclick="window.open('<?php echo htmlspecialchars($blog['article_link']); ?>', '_blank')">  
                                <?php if (!empty($blog['thumbnail'])): ?>  
                                    <img src="<?php echo htmlspecialchars($blog['thumbnail']); ?>" alt="<?php echo htmlspecialchars($blog['title']); ?>" class="blog-thumbnail">  
                                <?php else: ?>  
                                    <div style="height: 180px; background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary) 100%); display: flex; align-items: center; justify-content: center;">  
                                        <i data-lucide="book-open" style="color: white; width: 48px; height: 48px;"></i>  
                                    </div>  
                                <?php endif; ?>  
                                <div class="blog-content">  
                                    <span class="blog-category">Health</span>  
                                    <h3 class="blog-title"><?php echo htmlspecialchars($blog['title']); ?></h3>  
                                    <p class="blog-description"><?php echo htmlspecialchars(substr($blog['description'] ?? '', 0, 100)) . '...'; ?></p>  
                                    <!-- FIXED: Removed dots from date -->
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 12px;">  
                                        <div class="blog-date">  
                                            <i data-lucide="calendar" style="width: 14px; height: 14px;"></i>  
                                            <?php echo date('M j, Y', strtotime($blog['date'] ?? 'now')); ?>  
                                        </div>  
                                        <div style="font-size: 12px; color: var(--gray-600);">  
                                            <i data-lucide="user" style="width: 14px; height: 14px;"></i>  
                                            <?php echo htmlspecialchars($blog['author'] ?? 'Unknown'); ?>  
                                        </div>  
                                    </div>  
                                </div>  
                            </div>  
                        <?php endforeach; ?>  
                    </div>  
                <?php else: ?>  
                    <div class="under-development">  
                        <i data-lucide="construction" style="width: 64px; height: 64px;"></i>  
                        <h3>Feature Under Development</h3>  
                        <p>Our health blog section is coming soon with informative articles to help you on your smoke-free journey!</p>  
                    </div>  
                <?php endif; ?>  
            </section>  

            <!-- Daily Tasks - ORIGINAL STRUCTURE RESTORED -->  
            <section class="tasks-section">  
                <div class="section-header">  
                    <h2>Today's Tasks</h2>  
                    <div class="current-day">  
                        <?php echo date('F j, Y'); ?>  
                        <?php if ($today_task_data): ?>  
                            <span style="margin-left: 8px; background: <?php echo $phase_color; ?>20; color: <?php echo $phase_color; ?>; padding: 4px 8px; border-radius: 12px; font-size: 12px; backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.2);">  
                                <?php echo $today_task_data['title']; ?>  
                            </span>  
                        <?php endif; ?>  
                    </div>  
                </div>  

                <div class="task-list">  
                    <?php if ($all_tasks_completed && !empty($today_tasks)): ?>  
                        <div class="congratulations-message">  
                            <h3>  
                                <i data-lucide="party-popper"></i>  
                                Congratulations!  
                            </h3>  
                            <p>You've completed all tasks for today! Your progress has been saved. Come back tomorrow for new challenges!</p>  
                        </div>  
                    <?php elseif (empty($today_tasks)): ?>  
                        <div class="no-tasks">  
                            <h4>No tasks for today</h4>  
                            <p>Enjoy your smoke-free day! Come back tomorrow for new tasks.</p>  
                        </div>  
                    <?php else: ?>  
                        <?php foreach ($today_tasks as $task): ?>  
                            <?php 
                            // Get duration from JSON
                            $task_duration = "N/A";
                            if ($today_task_data && !empty($today_task_data['tasks'])) {
                                foreach ($today_task_data['tasks'] as $json_task) {
                                    if (isset($json_task['title']) && $json_task['title'] == $task['task_title']) {
                                        $task_duration = $json_task['duration'] ?? "N/A";
                                        break;
                                    }
                                }
                            }
                            
                            // Get task type class
                            $task_type_class = 'general';
                            if ($task['task_type'] == 'walk') $task_type_class = 'walk';
                            elseif ($task['task_type'] == 'water') $task_type_class = 'water';
                            elseif ($task['task_type'] == 'breathing') $task_type_class = 'breathing';
                            elseif ($task['task_type'] == 'mind') $task_type_class = 'mind';
                            elseif ($task['task_type'] == 'nutrition') $task_type_class = 'nutrition';
                            elseif ($task['task_type'] == 'environment') $task_type_class = 'environment';
                            elseif ($task['task_type'] == 'sleep') $task_type_class = 'sleep';
                            elseif ($task['task_type'] == 'social') $task_type_class = 'social';
                            elseif ($task['task_type'] == 'health') $task_type_class = 'health';
                            elseif ($task['task_type'] == 'reward') $task_type_class = 'reward';
                            ?>
                            
                            <div class="task-item <?php echo $task['is_completed'] ? 'completed' : ''; ?> <?php echo $task['skipped'] ? 'skipped' : ''; ?>" 
                                 data-task-id="<?php echo $task['id']; ?>">
                                
                                <!-- TOP ROW: Title left, Icon & Type right -->
                                <div class="task-header">
                                    <h4 class="task-title"><?php echo htmlspecialchars($task['task_title'] ?? $task['task_description']); ?></h4>
                                    
                                    <div class="task-type <?php echo $task_type_class; ?>">
                                        <?php 
                                        $icon = 'check-circle'; // Default icon
                                        
                                        // Map task types to Lucide icons
                                        if ($task['task_type'] == 'walk') $icon = 'footprints';
                                        if ($task['task_type'] == 'water') $icon = 'droplets';
                                        if ($task['task_type'] == 'breathing') $icon = 'wind';
                                        if ($task['task_type'] == 'mind') $icon = 'brain';
                                        if ($task['task_type'] == 'nutrition') $icon = 'apple';
                                        if ($task['task_type'] == 'environment') $icon = 'home';
                                        if ($task['task_type'] == 'sleep') $icon = 'moon';
                                        if ($task['task_type'] == 'social') $icon = 'users';
                                        if ($task['task_type'] == 'health') $icon = 'heart-pulse';
                                        if ($task['task_type'] == 'reward') $icon = 'gift';
                                        ?>
                                        
                                        <i data-lucide="<?php echo $icon; ?>" width="16" height="16"></i>
                                        <span>
                                            <?php 
                                            // Map task types to display names
                                            if ($task['task_type'] == 'walk') echo 'Walking';
                                            elseif ($task['task_type'] == 'water') echo 'Hydration';
                                            elseif ($task['task_type'] == 'breathing') echo 'Breathing';
                                            elseif ($task['task_type'] == 'mind') echo 'Mindfulness';
                                            elseif ($task['task_type'] == 'nutrition') echo 'Nutrition';
                                            elseif ($task['task_type'] == 'environment') echo 'Environment';
                                            elseif ($task['task_type'] == 'sleep') echo 'Sleep';
                                            elseif ($task['task_type'] == 'social') echo 'Social';
                                            elseif ($task['task_type'] == 'health') echo 'Health';
                                            elseif ($task['task_type'] == 'reward') echo 'Reward';
                                            else echo 'General';
                                            ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <!-- DESCRIPTION -->
                                <p class="task-description"><?php echo htmlspecialchars($task['task_description']); ?></p>
                                
                                <!-- DURATION -->
                                <div class="task-duration">
                                    <i data-lucide="clock" width="14" height="14"></i>
                                    <span><?php echo $task_duration; ?></span>
                                </div>
                                
                                <!-- STATUS BAR - Only shows when completed/skipped -->
                                <?php if ($task['is_completed'] || $task['skipped']): ?>
                                    <div class="task-status">
                                        <div class="status-left">
                                            <?php if ($task['is_completed']): ?>
                                                <i data-lucide="check-circle" width="14" height="14"></i>
                                                <span>Completed</span>
                                            <?php else: ?>
                                                <i data-lucide="skip-forward" width="14" height="14"></i>
                                                <span>Skipped</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="status-right">
                                            <?php if ($task['is_completed'] && !empty($task['completed_at'])): ?>
                                                <?php echo date('h:i A', strtotime($task['completed_at'])); ?>
                                            <?php elseif ($task['skipped']): ?>
                                                <?php echo date('h:i A', strtotime($task['updated_at'] ?? 'now')); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- ACTION BUTTONS -->
                                <div class="task-actions">  
                                    <?php if (!$task['is_completed'] && !$task['skipped']): ?>  
                                        <form method="POST" action="" class="task-form">  
                                            <input type="hidden" name="complete_task" value="1">  
                                            <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">  
                                            <button type="submit" class="task-button complete" onclick="this.disabled=true; this.innerHTML='<i data-lucide=\'loader\'></i> Completing...'; this.form.submit();">  
                                                <i data-lucide="check"></i> Complete  
                                            </button>  
                                        </form>  
                                        <button class="task-button skip" onclick="showSkipModal(<?php echo $task['id']; ?>, '<?php echo htmlspecialchars(addslashes($task['task_title'] ?? $task['task_description'])); ?>')">  
                                            <i data-lucide="skip-forward"></i> Skip  
                                        </button>  
                                    <?php elseif ($task['skipped']): ?>  
                                        <button class="task-button skip disabled">  
                                            <i data-lucide="skip-forward"></i> Skipped  
                                        </button>  
                                    <?php else: ?>  
                                        <button class="task-button complete disabled">  
                                            <i data-lucide="check"></i> Completed  
                                        </button>  
                                    <?php endif; ?>  
                                </div>  
                            </div>  
                        <?php endforeach; ?>  
                    <?php endif; ?>  
                </div>  
            </section>  

            <!-- Smoke Incident Button -->  
            <div class="smoke-button-container">  
                <button class="btn-danger" id="smokeBtn">  
                    <i data-lucide="smoking"></i>  
                    I smoked a cigarette today  
                </button>  
            </div>  
        </div>  
    </main>  

    <!-- Glass Footer Navigation -->  
    <footer>  
        <div class="container">  
            <nav class="footer-nav">  
                <a href="home.php" class="icon-button active">  
                    <i data-lucide="home"></i>  
                </a>  
                <a href="status.php" class="icon-button">  
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

    <!-- Smoke Incident Modal -->  
    <div class="modal-overlay" id="smokeModal">  
        <div class="modal">  
            <div class="modal-header">  
                <h2><i data-lucide="alert-triangle" style="color: var(--danger);"></i> Smoking Incident</h2>  
                <button class="icon-button" id="closeSmokeModal">  
                    <i data-lucide="x"></i>  
                </button>  
            </div>  
            <div class="modal-content">  
                <p><strong>Warning:</strong> Logging a smoking incident will reset your current streak to Day 0.</p>  
                <p>This is part of your journey. Don't give up - just get back on track!</p>  

                <form id="smokeForm" method="POST" action="">  
                    <input type="hidden" name="smoke_incident" value="1">  

                    <label for="cigarettes_count">How many cigarettes did you smoke?</label>  
                    <select id="cigarettes_count" name="cigarettes_count" required>  
                        <option value="1">1</option>  
                        <option value="2">2</option>  
                        <option value="3">3</option>  
                        <option value="4">4</option>  
                        <option value="5">5</option>  
                        <option value="6">6+</option>  
                    </select>  

                    <label for="reason">What triggered you to smoke? (Optional)</label>  
                    <textarea id="reason" name="reason" rows="3" placeholder="Stress, social situation, craving, etc."></textarea>  
                </form>  
            </div>  
            <div class="modal-actions">  
                <button class="modal-button secondary" id="cancelSmoke">Cancel</button>  
                <button type="submit" form="smokeForm" class="modal-button danger">Confirm & Reset Streak</button>  
            </div>  
        </div>  
    </div>  

    <!-- Skip Task Modal -->  
    <div class="modal-overlay" id="skipTaskModal">  
        <div class="modal">  
            <div class="modal-header">  
                <h2><i data-lucide="alert-circle" style="color: var(--warning);"></i> Skip Task</h2>  
                <button class="icon-button" id="closeSkipModal">  
                    <i data-lucide="x"></i>  
                </button>  
            </div>  
            <div class="modal-content">  
                <p><strong>Important:</strong> Are you sure you want to skip this task?</p>  
                <p id="skipTaskDescription"></p>  
                <p><strong>Note:</strong> If you skip this task, today's challenge will be paused. You'll see the same task again tomorrow.</p>  
                <form id="skipTaskForm" method="POST" action="">  
                    <input type="hidden" name="skip_task" value="1">  
                    <input type="hidden" id="skipTaskId" name="task_id" value="">  
                </form>  
            </div>  
            <div class="modal-actions">  
                <button class="modal-button secondary" id="cancelSkip">Cancel</button>  
                <button class="modal-button warning" id="confirmSkipBtn">Yes, Skip Task</button>  
            </div>  
        </div>  
    </div>  

    <!-- Game Pad Modal - EXACT SQUARE BOXES -->  
    <div class="modal-overlay" id="gamePadModal">  
        <div class="modal" style="max-width: 420px; padding: 24px;">  
            <div class="modal-header" style="margin-bottom: 20px;">  
                <h2 style="font-size: 20px; display: flex; align-items: center; gap: 8px;">
                    <i data-lucide="gamepad-2"></i> Games
                </h2>  
                <button class="icon-button" id="closeGamePadModal" style="width: 40px; height: 40px;">  
                    <i data-lucide="x"></i>  
                </button>  
            </div>  
            
            <div class="modal-content" style="margin-bottom: 16px;">  
                <?php  
                // Load games from JSON  
                $games_data = [];  
                $games_file = 'data/games.json';  
                
                if (file_exists($games_file)) {  
                    $games_json = file_get_contents($games_file);  
                    $games_data = json_decode($games_json, true);  
                    if (!is_array($games_data)) {  
                        $games_data = [];  
                    }  
                }  
                ?>  
                
                <div id="gameGridContainer">
                    <?php if (!empty($games_data)): ?>
                        <?php foreach ($games_data as $game): ?>
                            <?php if ($game['link'] !== '#'): ?>
                                <a href="games/<?php echo htmlspecialchars($game['link']); ?>" 
                                   class="game-card-small">
                                    <i data-lucide="<?php echo htmlspecialchars($game['icon']); ?>"></i>  
                                    <span><?php echo htmlspecialchars($game['name']); ?></span>  
                                </a>  
                            <?php else: ?>
                                <div class="game-card-small coming-soon"
                                     onclick="showComingSoon('<?php echo htmlspecialchars(addslashes($game['name'])); ?>')">
                                    <i data-lucide="<?php echo htmlspecialchars($game['icon']); ?>"></i>  
                                    <span><?php echo htmlspecialchars($game['name']); ?></span>
                                    <div class="soon-badge">SOON</div>
                                </div>  
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php else: ?>  
                        <div style="text-align: center; padding: 40px 20px;">  
                            <i data-lucide="construction" style="width: 48px; height: 48px; color: var(--warning); margin-bottom: 16px;"></i>  
                            <h3 style="font-size: 16px; color: var(--gray-900); margin-bottom: 8px;">Coming Soon</h3>  
                            <p style="color: var(--gray-700); font-size: 14px;">Games are being developed!</p>  
                        </div>  
                    <?php endif; ?>  
                </div>
                
                <?php if (!empty($games_data) && count($games_data) > 9): ?>
                    <div style="text-align: center; margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--glass-border);">
                        <p style="font-size: 12px; color: var(--gray-600);">
                            Scroll down to see more games
                        </p>
                    </div>
                <?php endif; ?>
            </div>  
            
            <div style="text-align: center; padding-top: 16px; border-top: 1px solid var(--glass-border);">
                <button class="modal-button secondary" id="closeGamePadBtn" style="margin-top: 0; padding: 10px 24px;">Close</button>  
            </div>  
        </div>  
    </div>

    <!-- Support Modal - Fixed Button Alignment -->  
    <div class="modal-overlay" id="supportModal">  
        <div class="modal">  
            <div class="modal-header">  
                <h2>Support & Resources</h2>  
                <button class="icon-button" id="closeSupportModal">  
                    <i class="bi bi-x-lg"></i>  
                </button>  
            </div>  

            <div class="modal-content">  
                <p>If you're struggling with cravings or need someone to talk to:</p>  

                <div class="social-buttons">  
                    <button class="social-button chat"
         onclick="window.location.href='chat.php'"> 
                  <i class="bi bi-chat-dots"></i>
                       Support Chat  
                    </button>  

<button class="social-button cosmobot"
    onclick="window.location.href='cosmobot.php'">
    <i class="bi bi-robot"></i> Chat with CosmoBot
</button>
                </div>  

                <div class="social-buttons">  
                    <button class="social-button community"
                        onclick="window.open('https://t.me/+vuQsUJwf-NhlMDM1', '_blank')"
                        style="width: 100%;">  
                        <i class="bi bi-people-fill"></i> Join Telegram Community  
                    </button>  
                </div>  
            </div>  

            <div class="modal-actions">  
                <button class="modal-button secondary" id="closeSupportBtn">Close</button>  
                <button class="modal-button primary"
                    onclick="window.location.href='tel:+919751191709'">  
                    <i class="bi bi-telephone-fill"></i> Call Support  
                </button>  
            </div>  
        </div>  
    </div>

    <!-- Quote Popup Modal - No Edge Line -->  
    <?php if ($popup_content): ?>  
    <div class="quote-modal-overlay active" id="quoteModal">  
        <div class="quote-modal">  
            <div class="quote-modal-header">  
                <h3>  
                    <i data-lucide="sparkles"></i>  
                    <?php   
                    if ($popup_content['type'] == 'quote') echo 'Daily Inspiration';  
                    elseif ($popup_content['type'] == 'advice') echo 'Helpful Tip';  
                    else echo 'Health Fact';  
                    ?>  
                </h3>  
                <button class="icon-button" id="closeQuoteModal">  
                    <i data-lucide="x"></i>  
                </button>  
            </div>  
            <div class="quote-modal-content">  
                "<?php echo htmlspecialchars($popup_content['content']); ?>"  
            </div>  
            <?php if (!empty($popup_content['author'])): ?>  
            <div class="quote-modal-author">  
                â€” <?php echo htmlspecialchars($popup_content['author']); ?>  
            </div>  
            <?php endif; ?>  
            <div class="quote-modal-actions">  
                <button class="modal-button secondary" id="closeQuoteBtn">Close</button>  
            </div>  
        </div>  
    </div>  
    <?php endif; ?>  

    <script>  
        lucide.createIcons();  

        // Modal controls  
        const smokeModal = document.getElementById('smokeModal');  
        const supportModal = document.getElementById('supportModal');  
        const gamePadModal = document.getElementById('gamePadModal');  
        const skipTaskModal = document.getElementById('skipTaskModal');  
        const quoteModal = document.getElementById('quoteModal');  
        const smokeBtn = document.getElementById('smokeBtn');  
        const supportBtn = document.getElementById('supportBtn');  
        const gamePadBtn = document.getElementById('gamePadBtn');  

        // Smoke incident modal  
        if (smokeBtn) {  
            smokeBtn.addEventListener('click', () => {  
                if (smokeModal) smokeModal.classList.add('active');  
            });  
        }  

        // Support modal  
        if (supportBtn) {  
            supportBtn.addEventListener('click', () => {  
                if (supportModal) supportModal.classList.add('active');  
            });  
        }  

        // Game Pad modal  
        if (gamePadBtn) {  
            gamePadBtn.addEventListener('click', () => {  
                if (gamePadModal) gamePadModal.classList.add('active');  
            });  
        }  

        // Close smoke modal  
        document.getElementById('closeSmokeModal')?.addEventListener('click', () => {  
            smokeModal.classList.remove('active');  
        });  

        document.getElementById('cancelSmoke')?.addEventListener('click', () => {  
            smokeModal.classList.remove('active');  
        });  

        // Close support modal  
        document.getElementById('closeSupportModal')?.addEventListener('click', () => {  
            supportModal.classList.remove('active');  
        });  

        document.getElementById('closeSupportBtn')?.addEventListener('click', () => {  
            supportModal.classList.remove('active');  
        });  

        // Close game pad modal  
        document.getElementById('closeGamePadModal')?.addEventListener('click', () => {  
            gamePadModal.classList.remove('active');  
        });  

        document.getElementById('closeGamePadBtn')?.addEventListener('click', () => {  
            gamePadModal.classList.remove('active');  
        });  

        // Close quote modal  
        document.getElementById('closeQuoteModal')?.addEventListener('click', () => {  
            if (quoteModal) quoteModal.classList.remove('active');  
        });  

        document.getElementById('closeQuoteBtn')?.addEventListener('click', () => {  
            if (quoteModal) quoteModal.classList.remove('active');  
        });  

        // Skip task modal functions
        let currentSkipTaskId = null;

        function showSkipModal(taskId, taskDescription) {
            currentSkipTaskId = taskId;
            
            document.getElementById('skipTaskId').value = taskId;
            document.getElementById('skipTaskDescription').textContent = `Task: ${taskDescription}`;
            
            if (skipTaskModal) {
                // Reset the button state
                const confirmBtn = document.getElementById('confirmSkipBtn');
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = 'Yes, Skip Task';
                confirmBtn.classList.remove('disabled');
                
                skipTaskModal.classList.add('active');
            }
        }

        // Handle skip confirmation
        document.getElementById('confirmSkipBtn')?.addEventListener('click', function() {
            const taskId = document.getElementById('skipTaskId').value;
            const taskItem = document.querySelector(`[data-task-id="${taskId}"]`);
            
            if (!taskId) return;
            
            // Disable button to prevent double click
            this.disabled = true;
            this.innerHTML = 'Skipping...';
            this.classList.add('disabled');
            
            if (taskItem) {
                // Update UI immediately for better UX
                const statusBar = document.createElement('div');
                statusBar.className = 'task-status';
                statusBar.innerHTML = `
                    <div class="status-left">
                        <i data-lucide="skip-forward" width="14" height="14"></i>
                        <span>Skipped</span>
                    </div>
                    <div class="status-right">
                        ${new Date().toLocaleTimeString('en-US', {hour: '2-digit', minute:'2-digit'})}
                    </div>
                `;
                
                // Insert before buttons
                const actions = taskItem.querySelector('.task-actions');
                taskItem.insertBefore(statusBar, actions);
                
                // Update task item
                taskItem.classList.add('skipped');
                taskItem.classList.remove('completed');
                
                // Update buttons
                actions.innerHTML = `
                    <button class="task-button skip disabled">
                        <i data-lucide="skip-forward"></i> Skipped
                    </button>
                `;
                
                // Refresh icons
                lucide.createIcons();
            }
            
            // Submit the form
            const form = document.getElementById('skipTaskForm');
            if (form) {
                form.submit();
            }
            
            // Close modal after a short delay
            setTimeout(() => {
                if (skipTaskModal) skipTaskModal.classList.remove('active');
            }, 500);
        });

        // Close skip modal
        document.getElementById('closeSkipModal')?.addEventListener('click', () => {
            skipTaskModal.classList.remove('active');
        });

        document.getElementById('cancelSkip')?.addEventListener('click', () => {
            skipTaskModal.classList.remove('active');
        });

        // Auto-hide quote modal after 10 seconds  
        if (quoteModal && quoteModal.classList.contains('active')) {  
            setTimeout(() => {  
                quoteModal.classList.remove('active');  
            }, 10000);  
        }  

        // Auto-show quote modal on page load after 3 seconds (only once)  
        setTimeout(() => {  
            // Check if user recently closed a popup  
            const lastClosed = localStorage.getItem('quoteModalLastClosed');  
            if (lastClosed) {  
                const timeSinceClose = Date.now() - parseInt(lastClosed);  
                // Don't show if closed less than 5 minutes ago  
                if (timeSinceClose < 300000) {  
                    return;  
                }  
            }  

            if (quoteModal && !quoteModal.classList.contains('active')) {  
                quoteModal.classList.add('active');  

                // Add close event listener  
                const closeBtn = document.getElementById('closeQuoteModal');  
                if (closeBtn) {  
                    closeBtn.addEventListener('click', () => {  
                        quoteModal.classList.remove('active');  
                        localStorage.setItem('quoteModalLastClosed', Date.now().toString());  
                    });  
                }  

                // Auto-hide after 15 seconds  
                setTimeout(() => {  
                    if (quoteModal.classList.contains('active')) {  
                        quoteModal.classList.remove('active');  
                        localStorage.setItem('quoteModalLastClosed', Date.now().toString());  
                    }  
                }, 15000);  
            }  
        }, 3000);  

        // Store last closed time when modal is closed  
        if (quoteModal) {  
            quoteModal.addEventListener('click', (e) => {  
                if (e.target === quoteModal || e.target.closest('#closeQuoteBtn') || e.target.closest('#closeQuoteModal')) {  
                    localStorage.setItem('quoteModalLastClosed', Date.now().toString());  
                }  
            });  
        }  

        // Coming soon function for games
        function showComingSoon(gameName) {
            alert(gameName + " is coming soon! Stay tuned for updates.");
        }

        // Add hover effects when modal opens
        document.getElementById('gamePadBtn')?.addEventListener('click', () => {
            setTimeout(() => {
                lucide.createIcons();
            }, 100);
        });

        // Close modal when clicking outside
        document.getElementById('gamePadModal')?.addEventListener('click', (e) => {
            if (e.target === document.getElementById('gamePadModal')) {
                document.getElementById('gamePadModal').classList.remove('active');
            }
        });

    </script>  
</body>  
</html>