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
// 1. LOAD USER DATA    
// -----------------------------------------------------------------------------    
$user_stmt = $conn->prepare("    
    SELECT name FROM users WHERE id = ?    
");    
$user_stmt->bind_param("i", $user_id);    
$user_stmt->execute();    
$user = $user_stmt->get_result()->fetch_assoc();    

if (!$user) {    
    header("Location: logout.php");    
    exit();    
}    

// -----------------------------------------------------------------------------    
// 2. LOAD USER PROGRESS DATA FOR ALL ACHIEVEMENT CALCULATIONS    
// -----------------------------------------------------------------------------    

// Get comprehensive user progress data
$progress_stmt = $conn->prepare("    
    SELECT 
        up.current_day,
        up.total_days_smoke_free,
        up.current_streak,
        up.longest_streak,
        up.total_money_saved,
        up.lung_capacity_percentage,
        up.health_score,
        up.total_cigarettes_avoided,
        up.last_reset_date,
        (SELECT COUNT(DISTINCT DATE(completed_at)) 
         FROM daily_tasks 
         WHERE user_id = up.user_id AND is_completed = 1) as days_with_completed_tasks,
        (SELECT COUNT(*) 
         FROM daily_tasks 
         WHERE user_id = up.user_id AND is_completed = 1) as total_tasks_completed,
        (SELECT COUNT(*) 
         FROM daily_tasks 
         WHERE user_id = up.user_id AND skipped = 1) as tasks_skipped,
        (SELECT COUNT(*) 
         FROM smoking_incidents 
         WHERE user_id = up.user_id) as total_relapses,
        (SELECT MAX(incident_date) 
         FROM smoking_incidents 
         WHERE user_id = up.user_id) as last_relapse_date,
        (SELECT COUNT(DISTINCT DATE(created_at)) 
         FROM diary_entries 
         WHERE user_id = up.user_id) as diary_days_count
    FROM user_progress up
    WHERE up.user_id = ?
");    
$progress_stmt->bind_param("i", $user_id);    
$progress_stmt->execute();    
$progress = $progress_stmt->get_result()->fetch_assoc();    

// Get time-based stats
$time_stmt = $conn->prepare("
    SELECT 
        (SELECT COUNT(*) FROM daily_tasks 
         WHERE user_id = ? AND is_completed = 1 AND HOUR(completed_at) < 12) as morning_tasks,
        (SELECT COUNT(*) FROM daily_tasks 
         WHERE user_id = ? AND is_completed = 1 AND HOUR(completed_at) >= 22) as night_tasks,
        (SELECT COUNT(*) FROM daily_tasks 
         WHERE user_id = ? AND is_completed = 1 
         AND TIME(completed_at) < '12:00:00') as before_noon_tasks
");
$time_stmt->bind_param("iii", $user_id, $user_id, $user_id);
$time_stmt->execute();
$time_stats = $time_stmt->get_result()->fetch_assoc();

// Load diary stats
$diary_stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_entries,
        COUNT(DISTINCT DATE(entry_date)) as unique_diary_days,
        SUM(CASE WHEN smoked_today = 0 THEN 1 ELSE 0 END) as smoke_free_diary_days,
        MAX(cravings_level) as max_cravings,
        MIN(cravings_level) as min_cravings,
        AVG(cravings_level) as avg_cravings,
        SUM(LENGTH(entry_text)) as total_chars,
        MAX(entry_date) as last_entry_date,
        MIN(entry_date) as first_entry_date,
        COUNT(DISTINCT DATE(entry_date)) as diary_streak_days
    FROM diary_entries 
    WHERE user_id = ?
");
$diary_stmt->bind_param("i", $user_id);
$diary_stmt->execute();
$diary_stats = $diary_stmt->get_result()->fetch_assoc();

// Calculate consecutive diary streak
$consecutive_stmt = $conn->prepare("
    SELECT 
        MAX(streak) as max_consecutive_diary
    FROM (
        SELECT 
            COUNT(*) as streak
        FROM (
            SELECT 
                entry_date,
                DATE_SUB(entry_date, INTERVAL ROW_NUMBER() OVER (ORDER BY entry_date) DAY) as grp
            FROM diary_entries 
            WHERE user_id = ?
            GROUP BY entry_date
        ) t
        GROUP BY grp
    ) t2
");
$consecutive_stmt->bind_param("i", $user_id);
$consecutive_stmt->execute();
$consecutive_diary = $consecutive_stmt->get_result()->fetch_assoc();

// Load task type stats
$task_stats_stmt = $conn->prepare("
    SELECT 
        task_type,
        COUNT(*) as count
    FROM daily_tasks 
    WHERE user_id = ? AND is_completed = 1
    GROUP BY task_type
");
$task_stats_stmt->bind_param("i", $user_id);
$task_stats_stmt->execute();
$task_stats_result = $task_stats_stmt->get_result();
$task_stats = [];
while ($row = $task_stats_result->fetch_assoc()) {
    $task_stats[$row['task_type']] = $row['count'];
}

// Load days with perfect completion (all tasks completed)
$perfect_days_stmt = $conn->prepare("
    SELECT COUNT(*) as perfect_days FROM (
        SELECT 
            task_date,
            COUNT(*) as total_tasks,
            SUM(is_completed) as completed_tasks
        FROM daily_tasks 
        WHERE user_id = ? 
        GROUP BY task_date
        HAVING total_tasks > 0 AND completed_tasks = total_tasks
    ) as perfect_days
");
$perfect_days_stmt->bind_param("i", $user_id);
$perfect_days_stmt->execute();
$perfect_days = $perfect_days_stmt->get_result()->fetch_assoc();

// Load weekends smoke-free
$weekends_stmt = $conn->prepare("
    SELECT COUNT(DISTINCT entry_date) as smoke_free_weekends
    FROM diary_entries 
    WHERE user_id = ? 
    AND smoked_today = 0
    AND DAYOFWEEK(entry_date) IN (1, 7) -- Sunday = 1, Saturday = 7
");
$weekends_stmt->bind_param("i", $user_id);
$weekends_stmt->execute();
$weekends_stats = $weekends_stmt->get_result()->fetch_assoc();

// Load app usage stats (days app opened)
$app_usage_stmt = $conn->prepare("
    SELECT COUNT(DISTINCT DATE(created_at)) as app_days_used
    FROM (
        SELECT created_at FROM diary_entries WHERE user_id = ?
        UNION ALL
        SELECT completed_at FROM daily_tasks WHERE user_id = ? AND is_completed = 1
        UNION ALL
        SELECT created_at FROM smoking_incidents WHERE user_id = ?
    ) as all_activities
");
$app_usage_stmt->bind_param("iii", $user_id, $user_id, $user_id);
$app_usage_stmt->execute();
$app_usage = $app_usage_stmt->get_result()->fetch_assoc();

// Load cravings control stats
$cravings_control_stmt = $conn->prepare("
    SELECT 
        COUNT(*) as high_cravings_resisted
    FROM diary_entries 
    WHERE user_id = ? 
    AND cravings_level >= 8
    AND smoked_today = 0
");
$cravings_control_stmt->bind_param("i", $user_id);
$cravings_control_stmt->execute();
$cravings_control = $cravings_control_stmt->get_result()->fetch_assoc();

// Load relapse recovery stats
$relapse_recovery_stmt = $conn->prepare("
    SELECT 
        COUNT(*) as relapses_recovered,
        MAX(DATEDIFF(CURDATE(), incident_date)) as days_since_last_relapse
    FROM smoking_incidents 
    WHERE user_id = ?
");
$relapse_recovery_stmt->bind_param("i", $user_id);
$relapse_recovery_stmt->execute();
$relapse_recovery = $relapse_recovery_stmt->get_result()->fetch_assoc();

// -----------------------------------------------------------------------------    


// 3. COMPLETE ACHIEVEMENT DATA WITH ALL 100 ACHIEVEMENTS AND ICONS
// -----------------------------------------------------------------------------    
$achievementData = [
    // Common Achievements (1-40)
    [
        'id' => 1,
        'name' => 'First Step',
        'desc' => 'Complete Day 1',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/doodle/96/baby-footprints-path--v1.png',
        'check' => function() use ($progress) {
            return ($progress['current_day'] ?? 0) >= 1;
        }
    ],
    [
        'id' => 2,
        'name' => 'Fresh Start',
        'desc' => 'Log in for 3 consecutive days',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/doodle/96/sun--v1.png',
        'check' => function() use ($progress) {
            return ($progress['current_streak'] ?? 0) >= 3;
        }
    ],
    [
        'id' => 3,
        'name' => 'Task Finisher',
        'desc' => 'Complete all tasks in one day',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/dusk/96/checklist.png',
        'check' => function() use ($perfect_days) {
            return ($perfect_days['perfect_days'] ?? 0) >= 1;
        }
    ],
    [
        'id' => 4,
        'name' => 'Early Riser',
        'desc' => 'Open the app before 8 AM',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/stickers/100/morning.png',
        'check' => function() use ($time_stats) {
            return ($time_stats['morning_tasks'] ?? 0) > 0;
        }
    ],
    [
        'id' => 5,
        'name' => 'Night Owl',
        'desc' => 'Open the app after 10 PM',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/stickers/100/owl.png',
        'check' => function() use ($time_stats) {
            return ($time_stats['night_tasks'] ?? 0) > 0;
        }
    ],
    [
        'id' => 6,
        'name' => 'Diary Debut',
        'desc' => 'Write your first diary entry',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/3d-fluency/94/journal.png',
        'check' => function() use ($diary_stats) {
            return ($diary_stats['total_entries'] ?? 0) >= 1;
        }
    ],
    [
        'id' => 7,
        'name' => 'Honest Day',
        'desc' => 'Log a diary entry truthfully',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/stickers/100/verified-badge.png',
        'check' => function() use ($diary_stats) {
            return ($diary_stats['total_entries'] ?? 0) >= 1;
        }
    ],
    [
        'id' => 8,
        'name' => 'Hydration Hero',
        'desc' => 'Complete a water task',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/dusk/96/water-bottle.png',
        'check' => function() use ($task_stats) {
            return ($task_stats['water'] ?? 0) >= 1;
        }
    ],
    [
        'id' => 9,
        'name' => 'Walking Start',
        'desc' => 'Complete a walking task',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/bubbles/100/walking.png',
        'check' => function() use ($task_stats) {
            return ($task_stats['walk'] ?? 0) >= 1;
        }
    ],
    [
        'id' => 10,
        'name' => 'Calm Breather',
        'desc' => 'Complete a breathing task',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/officel/96/lungs.png',
        'check' => function() use ($task_stats) {
            return ($task_stats['breathing'] ?? 0) >= 1;
        }
    ],
    [
        'id' => 11,
        'name' => 'Mindful Moment',
        'desc' => 'Finish a mindfulness task',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/plasticine/100/lotus.png',
        'check' => function() use ($task_stats) {
            return ($task_stats['mind'] ?? 0) >= 1;
        }
    ],
    [
        'id' => 12,
        'name' => 'Clean Day',
        'desc' => 'Stay smoke-free for 24 hours',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/cotton/96/shield--v1.png',
        'check' => function() use ($progress) {
            return ($progress['current_streak'] ?? 0) >= 1;
        }
    ],
    [
        'id' => 13,
        'name' => '₹ Saver',
        'desc' => 'Save your first ₹50',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/stickers/100/rupee.png',
        'check' => function() use ($progress) {
            return ($progress['total_money_saved'] ?? 0) >= 50;
        }
    ],
    [
        'id' => 14,
        'name' => 'Consistent',
        'desc' => 'Use the app 3 days in a row',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/stickers/100/calendar.png',
        'check' => function() use ($app_usage) {
            return ($app_usage['app_days_used'] ?? 0) >= 3;
        }
    ],
    [
        'id' => 15,
        'name' => 'Task Tracker',
        'desc' => 'Complete 10 tasks total',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/office/96/positive-dynamic.png',
        'check' => function() use ($progress) {
            return ($progress['total_tasks_completed'] ?? 0) >= 10;
        }
    ],
    [
        'id' => 16,
        'name' => 'Self-Aware',
        'desc' => 'Log cravings level 5+',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/stickers/100/brain.png',
        'check' => function() use ($diary_stats) {
            return ($diary_stats['max_cravings'] ?? 0) >= 5;
        }
    ],
    [
        'id' => 17,
        'name' => 'Recovery Mode',
        'desc' => 'Come back after skipping a task',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/cute-clipart/96/restart.png',
        'check' => function() use ($progress) {
            return ($progress['tasks_skipped'] ?? 0) > 0;
        }
    ],
    [
        'id' => 18,
        'name' => 'No Excuses',
        'desc' => 'Complete tasks without skipping',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/stickers/100/fire-element.png',
        'check' => function() use ($progress, $perfect_days) {
            return ($progress['tasks_skipped'] ?? 0) == 0 && ($perfect_days['perfect_days'] ?? 0) >= 1;
        }
    ],
    [
        'id' => 19,
        'name' => 'Healthy Choice',
        'desc' => 'Finish a nutrition task',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/3d-fluency/94/apple.png',
        'check' => function() use ($task_stats) {
            return ($task_stats['nutrition'] ?? 0) >= 1;
        }
    ],
    [
        'id' => 20,
        'name' => 'Home Reset',
        'desc' => 'Complete an environment task',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/officel/96/broom.png',
        'check' => function() use ($task_stats) {
            return ($task_stats['environment'] ?? 0) >= 1;
        }
    ],
    [
        'id' => 21,
        'name' => 'Sleep Well',
        'desc' => 'Complete a sleep task',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/bubbles/100/moon-satellite.png',
        'check' => function() use ($task_stats) {
            return ($task_stats['sleep'] ?? 0) >= 1;
        }
    ],
    [
        'id' => 22,
        'name' => 'Social Strength',
        'desc' => 'Complete a social task',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/stickers/100/group-foreground-selected.png',
        'check' => function() use ($task_stats) {
            return ($task_stats['social'] ?? 0) >= 1;
        }
    ],
    [
        'id' => 23,
        'name' => 'Health Focus',
        'desc' => 'Complete a health task',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/stickers/100/like--v3.png',
        'check' => function() use ($task_stats) {
            return ($task_stats['health'] ?? 0) >= 1;
        }
    ],
    [
        'id' => 24,
        'name' => 'Reward Yourself',
        'desc' => 'Complete a reward task',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/office/96/gift--v1.png',
        'check' => function() use ($task_stats) {
            return ($task_stats['reward'] ?? 0) >= 1;
        }
    ],
    [
        'id' => 25,
        'name' => 'Quote Reader',
        'desc' => 'Open a daily quote popup',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/deco-color/96/quote.png',
        'check' => function() use ($progress) {
            return true; // Assuming they've seen quotes
        }
    ],
    [
        'id' => 26,
        'name' => 'Motivation Boost',
        'desc' => 'Read a quote fully',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/cotton/96/rocket.png',
        'check' => function() use ($progress) {
            return ($progress['current_day'] ?? 0) >= 1;
        }
    ],
    [
        'id' => 27,
        'name' => 'Progress Checker',
        'desc' => 'Open status page',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/stickers/100/marketing.png',
        'check' => function() {
            return true; // They're viewing achievements page
        }
    ],
    [
        'id' => 28,
        'name' => 'Diary Streak',
        'desc' => 'Write diary 3 days straight',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/officel/96/fire-element.png',
        'check' => function() use ($consecutive_diary) {
            return ($consecutive_diary['max_consecutive_diary'] ?? 0) >= 3;
        }
    ],
    [
        'id' => 29,
        'name' => 'Positive Day',
        'desc' => 'No skipped tasks today',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/arcade/96/happy.png',
        'check' => function() use ($progress) {
            return ($progress['tasks_skipped'] ?? 0) == 0;
        }
    ],
    [
        'id' => 30,
        'name' => 'Check-In',
        'desc' => 'Open app 5 days total',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/color/96/checked-checkbox.png',
        'check' => function() use ($app_usage) {
            return ($app_usage['app_days_used'] ?? 0) >= 5;
        }
    ],
    [
        'id' => 31,
        'name' => 'Smoke-Free Morning',
        'desc' => 'No smoking before noon',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/stickers/100/no-smoking.png',
        'check' => function() use ($diary_stats) {
            return ($diary_stats['smoke_free_diary_days'] ?? 0) >= 1;
        }
    ],
    [
        'id' => 32,
        'name' => 'Smoke-Free Evening',
        'desc' => 'No smoking after sunset',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/officel/96/partly-cloudy-night.png',
        'check' => function() use ($diary_stats) {
            return ($diary_stats['smoke_free_diary_days'] ?? 0) >= 1;
        }
    ],
    [
        'id' => 33,
        'name' => 'Small Win',
        'desc' => 'Reduce cravings below level 3',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/arcade/96/trophy.png',
        'check' => function() use ($diary_stats) {
            return ($diary_stats['min_cravings'] ?? 10) <= 3;
        }
    ],
    [
        'id' => 34,
        'name' => 'Restart Strong',
        'desc' => 'Reset streak and return',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/stickers/100/refresh.png',
        'check' => function() use ($relapse_recovery) {
            return ($relapse_recovery['relapses_recovered'] ?? 0) >= 1;
        }
    ],
    [
        'id' => 35,
        'name' => 'Money Watcher',
        'desc' => 'Check money saved page',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/cotton/96/wallet--v1.png',
        'check' => function() {
            return true;
        }
    ],
    [
        'id' => 36,
        'name' => 'Clean Choice',
        'desc' => 'Choose "No" on smoking diary',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/office/96/checked-2--v1.png',
        'check' => function() use ($diary_stats) {
            return ($diary_stats['smoke_free_diary_days'] ?? 0) >= 1;
        }
    ],
    [
        'id' => 37,
        'name' => 'Focus Mode',
        'desc' => 'Complete all tasks within 6 hours',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/papercut/96/define-location.png',
        'check' => function() use ($time_stats, $perfect_days) {
            return ($perfect_days['perfect_days'] ?? 0) >= 1;
        }
    ],
    [
        'id' => 38,
        'name' => 'Steady Hand',
        'desc' => 'Skip zero tasks for 2 days',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/stickers/100/hand.png',
        'check' => function() use ($progress) {
            return ($progress['tasks_skipped'] ?? 0) == 0 && ($progress['total_days_smoke_free'] ?? 0) >= 2;
        }
    ],
    [
        'id' => 39,
        'name' => 'Growth Mindset',
        'desc' => 'Increase health score',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/color/96/bullish.png',
        'check' => function() use ($progress) {
            return ($progress['health_score'] ?? 0) > 0;
        }
    ],
    [
        'id' => 40,
        'name' => 'Beginner Champion',
        'desc' => 'Finish first week setup',
        'rarity' => 'common',
        'icon' => 'https://img.icons8.com/cute-clipart/96/medal.png',
        'check' => function() use ($progress) {
            return ($progress['current_day'] ?? 0) >= 7;
        }
    ],
    // Rare Achievements (41-70)
    [
        'id' => 41,
        'name' => 'One-Week Warrior',
        'desc' => '7 smoke-free days',
        'rarity' => 'rare',
        'icon' => 'https://img.icons8.com/cute-clipart/96/calendar-7.png',
        'check' => function() use ($progress) {
            return ($progress['current_streak'] ?? 0) >= 7;
        }
    ],
    [
        'id' => 42,
        'name' => 'Routine Builder',
        'desc' => '7 days without skipping tasks',
        'rarity' => 'rare',
        'icon' => 'https://img.icons8.com/external-flaticons-flat-flat-icons/96/external-routine-comfort-flaticons-flat-flat-icons.png',
        'check' => function() use ($progress) {
            return ($progress['tasks_skipped'] ?? 0) == 0 && ($progress['total_days_smoke_free'] ?? 0) >= 7;
        }
    ],
    [
        'id' => 43,
        'name' => 'Diary Loyalist',
        'desc' => '7 diary entries',
        'rarity' => 'rare',
        'icon' => 'https://img.icons8.com/stickers/100/journal.png',
        'check' => function() use ($diary_stats) {
            return ($diary_stats['total_entries'] ?? 0) >= 7;
        }
    ],
    [
        'id' => 44,
        'name' => 'Craving Crusher',
        'desc' => 'Keep cravings ≤3 for 5 days',
        'rarity' => 'rare',
        'icon' => 'https://img.icons8.com/sci-fi/96/hammer.png',
        'check' => function() use ($diary_stats) {
            return ($diary_stats['min_cravings'] ?? 10) <= 3 && ($diary_stats['unique_diary_days'] ?? 0) >= 5;
        }
    ],
    [
        'id' => 45,
        'name' => 'Health Improver',
        'desc' => 'Health score above 50',
        'rarity' => 'rare',
        'icon' => 'https://img.icons8.com/external-flaticons-lineal-color-flat-icons/96/external-health-report-new-normal-flaticons-lineal-color-flat-icons.png',
        'check' => function() use ($progress) {
            return ($progress['health_score'] ?? 0) >= 50;
        }
    ],
    [
        'id' => 46,
        'name' => 'Lung Revival',
        'desc' => 'Lung capacity above 40%',
        'rarity' => 'rare',
        'icon' => 'https://img.icons8.com/officel/96/lungs.png',
        'check' => function() use ($progress) {
            return ($progress['lung_capacity_percentage'] ?? 0) >= 40;
        }
    ],
    [
        'id' => 47,
        'name' => '₹500 Club',
        'desc' => 'Save ₹500',
        'rarity' => 'rare',
        'icon' => 'https://img.icons8.com/stickers/100/rupee.png',
        'check' => function() use ($progress) {
            return ($progress['total_money_saved'] ?? 0) >= 500;
        }
    ],
    [
        'id' => 48,
        'name' => 'No Reset',
        'desc' => '10 days without relapse',
        'rarity' => 'rare',
        'icon' => 'https://img.icons8.com/stickers/100/lock--v1.png',
        'check' => function() use ($progress, $relapse_recovery) {
            $days_since_relapse = $relapse_recovery['days_since_last_relapse'] ?? 999;
            return $days_since_relapse >= 10 || ($progress['current_streak'] ?? 0) >= 10;
        }
    ],
    [
        'id' => 49,
        'name' => 'Perfect Day',
        'desc' => 'All tasks + diary + no smoking',
        'rarity' => 'rare',
        'icon' => 'https://img.icons8.com/stickers/100/star.png',
        'check' => function() use ($perfect_days, $diary_stats) {
            return ($perfect_days['perfect_days'] ?? 0) >= 1 && ($diary_stats['total_entries'] ?? 0) >= 1;
        }
    ],
    [
        'id' => 50,
        'name' => 'Double Digits',
        'desc' => 'Reach Day 10',
        'rarity' => 'rare',
        'icon' => 'https://img.icons8.com/stickers/400/logic-data-types.png',
        'check' => function() use ($progress) {
            return ($progress['current_day'] ?? 0) >= 10;
        }
    ],
    [
        'id' => 51,
        'name' => 'Early Phase Master',
        'desc' => 'Finish Easy phase',
        'rarity' => 'rare',
        'icon' => 'https://img.icons8.com/stickers/100/graduation-cap.png',
        'check' => function() use ($progress) {
            return ($progress['current_day'] ?? 0) >= 10;
        }
    ],
    [
        'id' => 52,
        'name' => 'Comeback Kid',
        'desc' => 'Recover after relapse',
        'rarity' => 'rare',
        'icon' => 'https://img.icons8.com/stickers/100/boomerang.png',
        'check' => function() use ($relapse_recovery) {
            return ($relapse_recovery['relapses_recovered'] ?? 0) >= 1;
        }
    ],
    [
        'id' => 53,
        'name' => 'Mind Over Smoke',
        'desc' => 'Finish 10 mind tasks',
        'rarity' => 'rare',
        'icon' => 'https://img.icons8.com/stickers/100/brain.png',
        'check' => function() use ($task_stats) {
            return ($task_stats['mind'] ?? 0) >= 10;
        }
    ],
    [
        'id' => 54,
        'name' => 'Body Builder',
        'desc' => 'Finish 10 physical tasks',
        'rarity' => 'rare',
        'icon' => 'https://img.icons8.com/external-microdots-premium-microdot-graphic/512/external-body-sport-fitness-vol4-microdots-premium-microdot-graphic-2.png',
        'check' => function() use ($task_stats) {
            $physical_tasks = ($task_stats['walk'] ?? 0) + ($task_stats['water'] ?? 0) + ($task_stats['breathing'] ?? 0);
            return $physical_tasks >= 10;
        }
    ],
    [
        'id' => 55,
        'name' => 'Calm Streak',
        'desc' => '5 days low cravings',
        'rarity' => 'rare',
        'icon' => 'https://img.icons8.com/plasticine/100/lotus.png',
        'check' => function() use ($diary_stats) {
            return ($diary_stats['min_cravings'] ?? 10) <= 3 && ($diary_stats['unique_diary_days'] ?? 0) >= 5;
        }
    ],
    [
        'id' => 56,
        'name' => 'Focused Mind',
        'desc' => 'Finish tasks without delay',
        'rarity' => 'rare',
        'icon' => 'https://img.icons8.com/dusk/512/brainstorm-skill.png',
        'check' => function() use ($time_stats) {
            return ($time_stats['before_noon_tasks'] ?? 0) >= 5;
        }
    ],
    [
        'id' => 57,
        'name' => 'Healthy Routine',
        'desc' => 'Balanced tasks for 7 days',
        'rarity' => 'rare',
        'icon' => 'https://img.icons8.com/color/480/heart-health.png',
        'check' => function() use ($progress) {
            return ($progress['days_with_completed_tasks'] ?? 0) >= 7;
        }
    ],
    [
        'id' => 58,
        'name' => 'Determined',
        'desc' => 'Open app daily for 14 days',
        'rarity' => 'rare',
        'icon' => 'https://img.icons8.com/stickers/100/decision.png',
        'check' => function() use ($app_usage) {
            return ($app_usage['app_days_used'] ?? 0) >= 14;
        }
    ],
    [
        'id' => 59,
        'name' => 'Habit Builder',
        'desc' => 'Complete same task type 5 days',
        'rarity' => 'rare',
        'icon' => 'https://img.icons8.com/stickers/100/habit.png',
        'check' => function() use ($task_stats) {
            foreach ($task_stats as $count) {
                if ($count >= 5) return true;
            }
            return false;
        }
    ],
    [
        'id' => 60,
        'name' => 'Smoke-Free Weekend',
        'desc' => 'No smoking for 2 days',
        'rarity' => 'rare',
        'icon' => 'https://img.icons8.com/stickers/100/weekend.png',
        'check' => function() use ($weekends_stats) {
            return ($weekends_stats['smoke_free_weekends'] ?? 0) >= 2;
        }
    ],
    [
        'id' => 61,
        'name' => 'Savings Mindset',
        'desc' => 'Check savings 10 times',
        'rarity' => 'rare',
        'icon' => 'https://img.icons8.com/stickers/100/piggy-bank.png',
        'check' => function() use ($progress) {
            return ($progress['total_days_smoke_free'] ?? 0) >= 10;
        }
    ],
    [
        'id' => 62,
        'name' => 'Breath Control',
        'desc' => '10 breathing tasks',
        'rarity' => 'rare',
        'icon' => 'https://img.icons8.com/officel/96/breathing.png',
        'check' => function() use ($task_stats) {
            return ($task_stats['breathing'] ?? 0) >= 10;
        }
    ],
    [
        'id' => 63,
        'name' => 'Sleep Reset',
        'desc' => '7 sleep tasks',
        'rarity' => 'rare',
        'icon' => 'https://img.icons8.com/stickers/100/sleeping.png',
        'check' => function() use ($task_stats) {
            return ($task_stats['sleep'] ?? 0) >= 7;
        }
    ],
    [
        'id' => 64,
        'name' => 'Social Supporter',
        'desc' => '5 social tasks',
        'rarity' => 'rare',
        'icon' => 'https://img.icons8.com/stickers/100/helping-hand.png',
        'check' => function() use ($task_stats) {
            return ($task_stats['social'] ?? 0) >= 5;
        }
    ],
    [
        'id' => 65,
        'name' => 'Reflection Master',
        'desc' => 'Write 300+ diary words',
        'rarity' => 'rare',
        'icon' => 'https://img.icons8.com/stickers/100/thinking.png',
        'check' => function() use ($diary_stats) {
            return ($diary_stats['total_chars'] ?? 0) >= 300;
        }
    ],
    [
        'id' => 66,
        'name' => 'Clean Choices',
        'desc' => '10 diary entries with no smoking',
        'rarity' => 'rare',
        'icon' => 'https://img.icons8.com/color/96/checkmark.png',
        'check' => function() use ($diary_stats) {
            return ($diary_stats['smoke_free_diary_days'] ?? 0) >= 10;
        }
    ],
    [
        'id' => 67,
        'name' => 'Inner Strength',
        'desc' => 'Ignore cravings ≥7 once',
        'rarity' => 'rare',
        'icon' => 'https://img.icons8.com/stickers/100/shield-heart.png',
        'check' => function() use ($cravings_control) {
            return ($cravings_control['high_cravings_resisted'] ?? 0) >= 1;
        }
    ],
    [
        'id' => 68,
        'name' => 'Consistency Badge',
        'desc' => 'No skips for 10 days',
        'rarity' => 'rare',
        'icon' => 'https://img.icons8.com/stickers/100/badge.png',
        'check' => function() use ($progress) {
            return ($progress['tasks_skipped'] ?? 0) == 0 && ($progress['total_days_smoke_free'] ?? 0) >= 10;
        }
    ],
    [
        'id' => 69,
        'name' => 'Health Watcher',
        'desc' => 'Track stats daily for a week',
        'rarity' => 'rare',
        'icon' => 'https://img.icons8.com/stickers/100/heart-monitor.png',
        'check' => function() use ($app_usage) {
            return ($app_usage['app_days_used'] ?? 0) >= 7;
        }
    ],
    [
        'id' => 70,
        'name' => 'Strong Will',
        'desc' => 'Skip zero cigarettes for 14 days',
        'rarity' => 'rare',
        'icon' => 'https://img.icons8.com/stickers/100/fist.png',
        'check' => function() use ($progress) {
            return ($progress['current_streak'] ?? 0) >= 14;
        }
    ],
    // Ultra-Rare Achievements (71-90)
    [
        'id' => 71,
        'name' => 'Two-Week Titan',
        'desc' => '14 smoke-free days',
        'rarity' => 'ultra-rare',
        'icon' => 'https://img.icons8.com/stickers/100/titanium.png',
        'check' => function() use ($progress) {
            return ($progress['current_streak'] ?? 0) >= 14;
        }
    ],
    [
        'id' => 72,
        'name' => 'Medium Phase Conqueror',
        'desc' => 'Reach Day 20',
        'rarity' => 'ultra-rare',
        'icon' => 'https://img.icons8.com/stickers/100/mountain.png',
        'check' => function() use ($progress) {
            return ($progress['current_day'] ?? 0) >= 20;
        }
    ],
    [
        'id' => 73,
        'name' => 'Lung Reclaimer',
        'desc' => 'Lung capacity above 70%',
        'rarity' => 'ultra-rare',
        'icon' => 'https://img.icons8.com/stickers/100/lungs-heart.png',
        'check' => function() use ($progress) {
            return ($progress['lung_capacity_percentage'] ?? 0) >= 70;
        }
    ],
    [
        'id' => 74,
        'name' => '₹2000 Saver',
        'desc' => 'Save ₹2,000',
        'rarity' => 'ultra-rare',
        'icon' => 'https://img.icons8.com/stickers/100/safe.png',
        'check' => function() use ($progress) {
            return ($progress['total_money_saved'] ?? 0) >= 2000;
        }
    ],
    [
        'id' => 75,
        'name' => 'Relapse Survivor',
        'desc' => 'Relapse only once in 20 days',
        'rarity' => 'ultra-rare',
        'icon' => 'https://img.icons8.com/stickers/100/phoenix.png',
        'check' => function() use ($relapse_recovery, $progress) {
            return ($relapse_recovery['relapses_recovered'] ?? 0) <= 1 && ($progress['total_days_smoke_free'] ?? 0) >= 20;
        }
    ],
    [
        'id' => 76,
        'name' => 'Streak Master',
        'desc' => '15-day streak',
        'rarity' => 'ultra-rare',
        'icon' => 'https://img.icons8.com/stickers/100/fire.png',
        'check' => function() use ($progress) {
            return ($progress['longest_streak'] ?? 0) >= 15;
        }
    ],
    [
        'id' => 77,
        'name' => 'Discipline King',
        'desc' => 'No skipped tasks for 14 days',
        'rarity' => 'ultra-rare',
        'icon' => 'https://img.icons8.com/stickers/100/crown.png',
        'check' => function() use ($progress) {
            return ($progress['tasks_skipped'] ?? 0) == 0 && ($progress['total_days_smoke_free'] ?? 0) >= 14;
        }
    ],
    [
        'id' => 78,
        'name' => 'Mental Fortress',
        'desc' => '7 days cravings ≤2',
        'rarity' => 'ultra-rare',
        'icon' => 'https://img.icons8.com/stickers/100/castle.png',
        'check' => function() use ($diary_stats) {
            return ($diary_stats['min_cravings'] ?? 10) <= 2 && ($diary_stats['unique_diary_days'] ?? 0) >= 7;
        }
    ],
    [
        'id' => 79,
        'name' => 'Health Focused',
        'desc' => 'Health score above 75',
        'rarity' => 'ultra-rare',
        'icon' => 'https://img.icons8.com/stickers/100/heart-shield.png',
        'check' => function() use ($progress) {
            return ($progress['health_score'] ?? 0) >= 75;
        }
    ],
    [
        'id' => 80,
        'name' => 'Perfect Balance',
        'desc' => 'Complete all task categories',
        'rarity' => 'ultra-rare',
        'icon' => 'https://img.icons8.com/stickers/100/balance-scale.png',
        'check' => function() use ($task_stats) {
            $required_categories = ['walk', 'water', 'breathing', 'mind', 'nutrition', 'environment', 'sleep', 'social', 'health', 'reward'];
            foreach ($required_categories as $category) {
                if (($task_stats[$category] ?? 0) == 0) return false;
            }
            return true;
        }
    ],
    [
        'id' => 81,
        'name' => 'No Excuses Pro',
        'desc' => 'Zero skips for 20 days',
        'rarity' => 'ultra-rare',
        'icon' => 'https://img.icons8.com/stickers/100/no-entry.png',
        'check' => function() use ($progress) {
            return ($progress['tasks_skipped'] ?? 0) == 0 && ($progress['total_days_smoke_free'] ?? 0) >= 20;
        }
    ],
    [
        'id' => 82,
        'name' => 'Diary Monk',
        'desc' => '14 consecutive diary entries',
        'rarity' => 'ultra-rare',
        'icon' => 'https://img.icons8.com/stickers/100/monk.png',
        'check' => function() use ($consecutive_diary) {
            return ($consecutive_diary['max_consecutive_diary'] ?? 0) >= 14;
        }
    ],
    [
        'id' => 83,
        'name' => 'Weekend Warrior',
        'desc' => 'Two smoke-free weekends',
        'rarity' => 'ultra-rare',
        'icon' => 'https://img.icons8.com/stickers/100/warrior.png',
        'check' => function() use ($weekends_stats) {
            return ($weekends_stats['smoke_free_weekends'] ?? 0) >= 4;
        }
    ],
    [
        'id' => 84,
        'name' => 'Calm Under Fire',
        'desc' => 'Handle cravings ≥8 without smoking',
        'rarity' => 'ultra-rare',
        'icon' => 'https://img.icons8.com/stickers/100/fire-shield.png',
        'check' => function() use ($cravings_control) {
            return ($cravings_control['high_cravings_resisted'] ?? 0) >= 3;
        }
    ],
    [
        'id' => 85,
        'name' => 'Focus Champion',
        'desc' => 'Tasks completed before noon',
        'rarity' => 'ultra-rare',
        'icon' => 'https://img.icons8.com/stickers/100/bullseye.png',
        'check' => function() use ($time_stats, $perfect_days) {
            return ($time_stats['before_noon_tasks'] ?? 0) >= 10 && ($perfect_days['perfect_days'] ?? 0) >= 5;
        }
    ],
    [
        'id' => 86,
        'name' => 'Recovery Legend',
        'desc' => 'Improve after relapse fully',
        'rarity' => 'ultra-rare',
        'icon' => 'https://img.icons8.com/stickers/100/legend.png',
        'check' => function() use ($relapse_recovery, $progress) {
            return ($relapse_recovery['relapses_recovered'] ?? 0) >= 1 && ($progress['current_streak'] ?? 0) >= 7;
        }
    ],
    [
        'id' => 87,
        'name' => 'Habit Transformer',
        'desc' => 'Replace smoking habit',
        'rarity' => 'ultra-rare',
        'icon' => 'https://img.icons8.com/stickers/100/transform.png',
        'check' => function() use ($progress, $task_stats) {
            return ($progress['current_streak'] ?? 0) >= 21 && ($task_stats['walk'] ?? 0) + ($task_stats['breathing'] ?? 0) >= 15;
        }
    ],
    [
        'id' => 88,
        'name' => 'Inner Peace',
        'desc' => '10 breathing tasks in 10 days',
        'rarity' => 'ultra-rare',
        'icon' => 'https://img.icons8.com/stickers/100/zen.png',
        'check' => function() use ($task_stats) {
            return ($task_stats['breathing'] ?? 0) >= 10;
        }
    ],
    [
        'id' => 89,
        'name' => 'Resilience Badge',
        'desc' => 'Never quit after failure',
        'rarity' => 'ultra-rare',
        'icon' => 'https://img.icons8.com/stickers/100/resilience.png',
        'check' => function() use ($relapse_recovery, $progress) {
            return ($relapse_recovery['relapses_recovered'] ?? 0) <= 2 && ($progress['current_streak'] ?? 0) >= 14;
        }
    ],
    [
        'id' => 90,
        'name' => 'Growth Arc',
        'desc' => 'Major health stat improvement',
        'rarity' => 'ultra-rare',
        'icon' => 'https://img.icons8.com/stickers/100/combo-chart.png',
        'check' => function() use ($progress) {
            return ($progress['health_score'] ?? 0) >= 80 && ($progress['lung_capacity_percentage'] ?? 0) >= 60;
        }
    ],
    // Legendary Achievements (91-100)
    [
        'id' => 91,
        'name' => 'Smoke-Free Champion',
        'desc' => '30 smoke-free days',
        'rarity' => 'legendary',
        'icon' => 'https://img.icons8.com/stickers/100/trophy.png',
        'check' => function() use ($progress) {
            return ($progress['current_streak'] ?? 0) >= 30;
        }
    ],
    [
        'id' => 92,
        'name' => 'Hard Phase Victor',
        'desc' => 'Finish entire challenge',
        'rarity' => 'legendary',
        'icon' => 'https://img.icons8.com/stickers/100/victory.png',
        'check' => function() use ($progress) {
            return ($progress['current_day'] ?? 0) >= 30;
        }
    ],
    [
        'id' => 93,
        'name' => 'Iron Will',
        'desc' => 'Zero relapses in 30 days',
        'rarity' => 'legendary',
        'icon' => 'https://img.icons8.com/stickers/100/anvil.png',
        'check' => function() use ($relapse_recovery, $progress) {
            return ($relapse_recovery['relapses_recovered'] ?? 0) == 0 && ($progress['current_streak'] ?? 0) >= 30;
        }
    ],
    [
        'id' => 94,
        'name' => '₹5000 Saver',
        'desc' => 'Save ₹5,000',
        'rarity' => 'legendary',
        'icon' => 'https://img.icons8.com/stickers/100/treasure-chest.png',
        'check' => function() use ($progress) {
            return ($progress['total_money_saved'] ?? 0) >= 5000;
        }
    ],
    [
        'id' => 95,
        'name' => 'Lung Reborn',
        'desc' => 'Lung capacity above 90%',
        'rarity' => 'legendary',
        'icon' => 'https://img.icons8.com/stickers/100/lungs.png',
        'check' => function() use ($progress) {
            return ($progress['lung_capacity_percentage'] ?? 0) >= 90;
        }
    ],
    [
        'id' => 96,
        'name' => 'Perfect Month',
        'desc' => 'No skipped tasks in 30 days',
        'rarity' => 'legendary',
        'icon' => 'https://img.icons8.com/stickers/100/calendar-star.png',
        'check' => function() use ($progress) {
            return ($progress['tasks_skipped'] ?? 0) == 0 && ($progress['total_days_smoke_free'] ?? 0) >= 30;
        }
    ],
    [
        'id' => 97,
        'name' => 'Diary Guardian',
        'desc' => '30 diary entries',
        'rarity' => 'legendary',
        'icon' => 'https://img.icons8.com/stickers/100/book-shield.png',
        'check' => function() use ($diary_stats) {
            return ($diary_stats['total_entries'] ?? 0) >= 30;
        }
    ],
    [
        'id' => 98,
        'name' => 'Health Peak',
        'desc' => 'Health score 100',
        'rarity' => 'legendary',
        'icon' => 'https://img.icons8.com/stickers/100/mountain-top.png',
        'check' => function() use ($progress) {
            return ($progress['health_score'] ?? 0) >= 100;
        }
    ],
    [
        'id' => 99,
        'name' => 'Addiction Breaker',
        'desc' => 'Officially break the habit',
        'rarity' => 'legendary',
        'icon' => 'https://img.icons8.com/stickers/100/broken-chain.png',
        'check' => function() use ($progress, $relapse_recovery) {
            return ($progress['current_streak'] ?? 0) >= 60 || (($relapse_recovery['days_since_last_relapse'] ?? 0) >= 60);
        }
    ],
    [
        'id' => 100,
        'name' => 'CosmoQuit Legend',
        'desc' => 'Complete everything',
        'rarity' => 'legendary',
        'icon' => 'https://img.icons8.com/color/96/fenix.png',
        'check' => function() use ($progress, $diary_stats, $task_stats, $perfect_days) {
            return ($progress['current_day'] ?? 0) >= 30 &&
                   ($progress['health_score'] ?? 0) >= 90 &&
                   ($diary_stats['total_entries'] ?? 0) >= 20 &&
                   ($perfect_days['perfect_days'] ?? 0) >= 15;
        }
    ]
];

// -----------------------------------------------------------------------------    


// 4. CHECK AND UPDATE ACHIEVEMENTS    
// -----------------------------------------------------------------------------    
function checkAndUpdateAchievements($conn, $user_id, $achievementData) {
    $earned_achievements = [];
    
    foreach ($achievementData as $achievement) {
        // Check if achievement is already earned
        $check_stmt = $conn->prepare("SELECT earned FROM achievements WHERE user_id = ? AND achievement_id = ?");
        $check_stmt->bind_param("ii", $user_id, $achievement['id']);
        $check_stmt->execute();
        $existing = $check_stmt->get_result()->fetch_assoc();
        
        // If not earned yet, check criteria
        if (!$existing || !$existing['earned']) {
            try {
                $is_earned = $achievement['check']();
            } catch (Exception $e) {
                $is_earned = false;
                error_log("Error checking achievement {$achievement['id']}: " . $e->getMessage());
            }
            
            if ($is_earned) {
                if ($existing) {
                    // Update existing record
                    $update_stmt = $conn->prepare("
                        UPDATE achievements 
                        SET earned = TRUE, earned_date = NOW() 
                        WHERE user_id = ? AND achievement_id = ?
                    ");
                    $update_stmt->bind_param("ii", $user_id, $achievement['id']);
                    $update_stmt->execute();
                } else {
                    // Insert new achievement
                    $insert_stmt = $conn->prepare("
                        INSERT INTO achievements (user_id, achievement_id, achievement_name, achievement_desc, rarity, earned, earned_date)
                        VALUES (?, ?, ?, ?, ?, TRUE, NOW())
                    ");
                    $insert_stmt->bind_param("iisss", 
                        $user_id, 
                        $achievement['id'],
                        $achievement['name'],
                        $achievement['desc'],
                        $achievement['rarity']
                    );
                    $insert_stmt->execute();
                }
                
                $earned_achievements[] = [
                    'name' => $achievement['name'],
                    'rarity' => $achievement['rarity']
                ];
            } else {
                // Ensure achievement record exists (for tracking)
                if (!$existing) {
                    $insert_stmt = $conn->prepare("
                        INSERT INTO achievements (user_id, achievement_id, achievement_name, achievement_desc, rarity, earned)
                        VALUES (?, ?, ?, ?, ?, FALSE)
                        ON DUPLICATE KEY UPDATE achievement_name = VALUES(achievement_name)
                    ");
                    $insert_stmt->bind_param("iisss", 
                        $user_id, 
                        $achievement['id'],
                        $achievement['name'],
                        $achievement['desc'],
                        $achievement['rarity']
                    );
                    $insert_stmt->execute();
                }
            }
        }
    }
    
    return $earned_achievements;
}

// Run achievement check
$new_achievements = checkAndUpdateAchievements($conn, $user_id, $achievementData);

// -----------------------------------------------------------------------------    
// 5. LOAD USER'S EARNED ACHIEVEMENTS    
// -----------------------------------------------------------------------------    
$achievements_stmt = $conn->prepare("
    SELECT achievement_id, achievement_name, achievement_desc, rarity, earned, earned_date
    FROM achievements 
    WHERE user_id = ?
    ORDER BY 
        CASE rarity 
            WHEN 'legendary' THEN 1
            WHEN 'ultra-rare' THEN 2
            WHEN 'rare' THEN 3
            WHEN 'common' THEN 4
            ELSE 5
        END,
        earned_date DESC
");
$achievements_stmt->bind_param("i", $user_id);
$achievements_stmt->execute();
$user_achievements = $achievements_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Create a map of earned achievements for quick lookup
$earned_map = [];
foreach ($user_achievements as $ach) {
    if ($ach['earned']) {
        $earned_map[$ach['achievement_id']] = true;
    }
}

// Count achievements by rarity
$counts = ['common' => 0, 'rare' => 0, 'ultra-rare' => 0, 'legendary' => 0];
$earned_counts = ['common' => 0, 'rare' => 0, 'ultra-rare' => 0, 'legendary' => 0];

foreach ($achievementData as $ach) {
    $counts[$ach['rarity']]++;
    if (isset($earned_map[$ach['id']])) {
        $earned_counts[$ach['rarity']]++;
    }
}

// Calculate totals
$total_achievements = count($achievementData);
$total_earned = array_sum($earned_counts);
$progress_percent = $total_achievements > 0 ? round(($total_earned / $total_achievements) * 100) : 0;

// Show notification for new achievements
$show_new_achievement_notification = !empty($new_achievements);
?>    
<!DOCTYPE html>    
<html lang="en">    
<head>    
    <meta charset="UTF-8">    
    <meta name="viewport" content="width=device-width, initial-scale=1.0">    
    <title>Achievements - CosmoQuit</title>    
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">    


<style>
  
  :root {
    --primary: #8B5CF6;
    --primary-light: #A78BFA;
    --primary-dark: #7C3AED;
    --primary-soft: rgba(139, 92, 246, 0.1);
    --main-bg: #0F0B16;
    --card-bg: rgba(30, 27, 36, 0.85);
    --card-border: rgba(139, 92, 246, 0.15);
    
    --text-primary: #F3E8FF;
    --text-muted: #C4B5FD;
    --text-light: #A78BFA;
    
    --success: #10B981;
    --danger: #EF4444;
    --warning: #F59E0B;
    
    /* Achievement colors */
    --common: #10B981;
    --rare: #3B82F6;
    --ultra-rare: #8B5CF6;
    --legendary: #F59E0B;
    
    --glass-bg: rgba(30, 27, 36, 0.7);
    --glass-border: rgba(139, 92, 246, 0.12);
    --glass-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
    --glass-blur: blur(20px);
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

/* Header - Updated to match home.php */
header {
    background: var(--glass-bg);
    backdrop-filter: var(--glass-blur);
    -webkit-backdrop-filter: var(--glass-blur);
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
    min-height: calc(100vh - 60px);
}

/* Hero Section - Original Design from home.php */
.welcome-section {
    background: rgba(30, 27, 36, 0.85);
    backdrop-filter: blur(24px);
    -webkit-backdrop-filter: blur(24px);
    border-radius: 20px;
    padding: 32px;
    color: var(--text-primary);
    margin-bottom: 24px;
    border: 1px solid rgba(139, 92, 246, 0.2);
    box-shadow: 0 8px 32px rgba(139, 92, 246, 0.1);
    position: relative;
    overflow: hidden;
    text-align: center;
}

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
    justify-content: center;
    align-items: center;
    margin-bottom: 16px;
    gap: 12px;
}

.welcome-header h1 {
    font-size: 28px;
    font-weight: 700;
    color: var(--text-primary);
}

.motivation-quote {
    font-style: italic;
    margin-top: 16px;
    padding: 20px;
    background: rgba(139, 92, 246, 0.08);
    border-radius: 16px;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(139, 92, 246, 0.15);
    color: var(--text-muted);
    max-width: 600px;
    margin: 16px auto 0;
}

.achievement-system {
    text-align: center;
    margin: 16px 0;
    color: var(--text-muted);
    font-size: 14px;
    font-weight: 500;
}

/* Stats Grid - Updated Design */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}

@media (min-width: 768px) {
    .stats-grid {
        grid-template-columns: repeat(4, 1fr);
    }
}

.stat-card {
    background: rgba(30, 27, 36, 0.85);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border-radius: 16px;
    padding: 20px;
    border: 1px solid rgba(139, 92, 246, 0.15);
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
    transition: all 0.3s ease;
    text-align: center;
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 32px rgba(139, 92, 246, 0.15);
    border-color: rgba(139, 92, 246, 0.25);
}

.stat-card.common { border-top: 3px solid var(--common); }
.stat-card.rare { border-top: 3px solid var(--rare); }
.stat-card.ultra-rare { border-top: 3px solid var(--ultra-rare); }
.stat-card.legendary { border-top: 3px solid var(--legendary); }

.stat-count {
    font-size: 24px;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 4px;
}

.stat-label {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-muted);
    margin-bottom: 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stat-sub {
    font-size: 12px;
    color: var(--text-muted);
}

/* Progress Section - FIXED ANIMATION */
.progress-section {
    background: rgba(30, 27, 36, 0.85);
    border-radius: 16px;
    padding: 24px;
    margin-bottom: 24px;
    border: 1px solid rgba(139, 92, 246, 0.15);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
}

.progress-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.progress-header h3 {
    font-size: 16px;
    font-weight: 600;
    color: var(--text-primary);
}

.progress-percent {
    font-size: 20px;
    font-weight: 700;
    color: var(--primary-light);
}

.progress-bar-container {
    background: rgba(255, 255, 255, 0.05);
    height: 8px;
    border-radius: 4px;
    margin-bottom: 12px;
    overflow: hidden;
    position: relative;
}

.progress-fill {
    height: 100%;
    border-radius: 4px;
    background: linear-gradient(90deg, var(--common), var(--rare), var(--ultra-rare), var(--legendary));
    background-size: 200% 100%;
    animation: gradientShift 3s infinite linear;
    position: relative;
    overflow: hidden;
    width: 0%;
    transition: width 1s ease;
}

@keyframes gradientShift {
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}

.progress-stats {
    display: flex;
    justify-content: space-between;
    font-size: 12px;
    color: var(--text-muted);
}

.progress-stats span {
    font-weight: 500;
}

/* Filter Bar */
.filter-bar {
    display: flex;
    gap: 8px;
    margin-bottom: 24px;
    padding: 0 4px;
    flex-wrap: nowrap;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    padding-bottom: 8px;
}

.filter-bar::-webkit-scrollbar {
    display: none;
}

.filter-btn {
    padding: 12px 16px;
    border: none;
    background: rgba(30, 27, 36, 0.8);
    color: var(--text-primary);
    border-radius: 12px;
    font-size: 14px;
    font-weight: 600;
    white-space: nowrap;
    border: 1px solid rgba(139, 92, 246, 0.15);
    backdrop-filter: blur(10px);
    transition: all 0.2s ease;
    cursor: pointer;
}

.filter-btn:active {
    transform: scale(0.96);
}

.filter-btn.active {
    background: var(--primary);
    color: white;
    border-color: transparent;
}

/* Badges Grid */
.badges-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 16px;
    margin-bottom: 32px;
}

@media (min-width: 640px) {
    .badges-grid {
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
    }
}

@media (min-width: 768px) {
    .badges-grid {
        grid-template-columns: repeat(4, 1fr);
    }
}

/* Badge Card - Fixed positioning */
.badge-card {
    background: var(--glass-bg);
    border-radius: 16px;
    padding: 16px;
    border: 1px solid var(--glass-border);
    backdrop-filter: var(--glass-blur);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    min-height: 240px;
    display: flex;
    flex-direction: column;
    cursor: pointer;
}

.badge-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 32px rgba(139, 92, 246, 0.15);
    border-color: rgba(139, 92, 246, 0.25);
}

.badge-card:not(.earned) {
    filter: grayscale(100%) brightness(0.6);
    opacity: 0.8;
}

/* Badge header - stays at top */
.badge-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    flex-shrink: 0;
}

.badge-status {
    font-size: 10px;
    font-weight: 700;
    padding: 4px 8px;
    border-radius: 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.badge-status.earned {
    background: linear-gradient(90deg, var(--common), var(--primary));
    color: white;
}

.badge-status.locked {
    background: rgba(255, 255, 255, 0.1);
    color: var(--text-muted);
}

.badge-rarity {
    font-size: 10px;
    font-weight: 700;
    padding: 4px 8px;
    border-radius: 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.common .badge-rarity { 
    background: rgba(16, 185, 129, 0.15); 
    color: var(--common); 
}
.rare .badge-rarity { 
    background: rgba(59, 130, 246, 0.15); 
    color: var(--rare); 
}
.ultra-rare .badge-rarity { 
    background: rgba(139, 92, 246, 0.15); 
    color: var(--ultra-rare); 
}
.legendary .badge-rarity { 
    background: rgba(245, 158, 11, 0.15); 
    color: var(--legendary); 
}

/* Badge content - FIXED POSITIONING */
.badge-icon {
    width: 64px;
    height: 64px;
    margin: 24px auto 24px; /* Increased margin to push icon up */
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.badge-icon img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.3));
}

/* Badge name - moved lower */
.badge-name {
    font-size: 13px;
    font-weight: 700;
    color: var(--text-primary);
    text-align: center;
    margin-bottom: 8px;
    line-height: 1.3;
    min-height: 34px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    margin-top: 0; /* Reset margin */
}

/* Badge description - moved lower and visible */
.badge-desc {
    font-size: 11px;
    color: var(--text-muted);
    text-align: center;
    line-height: 1.4;
    margin-top: 0;
    margin-bottom: 0;
    flex-grow: 1;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    text-overflow: ellipsis;
    padding: 0 4px;
}

/* Badge border indicators */
.badge-card.common { border-left: 4px solid var(--common); }
.badge-card.rare { border-left: 4px solid var(--rare); }
.badge-card.ultra-rare { border-left: 4px solid var(--ultra-rare); }
.badge-card.legendary { border-left: 4px solid var(--legendary); }

/* Badge Details Modal - NEW ADDITION */
.badge-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(15, 11, 22, 0.95);
    z-index: 2000;
    align-items: center;
    justify-content: center;
    padding: 16px;
    backdrop-filter: blur(10px);
}

.badge-modal.active {
    display: flex;
}

.modal-content {
    background: var(--glass-bg);
    border-radius: 20px;
    padding: 24px;
    width: 100%;
    max-width: 320px;
    border: 1px solid var(--glass-border);
    backdrop-filter: var(--glass-blur);
    animation: modalAppear 0.3s ease;
    position: relative;
}

@keyframes modalAppear {
    from { opacity: 0; transform: scale(0.9); }
    to { opacity: 1; transform: scale(1); }
}

.close-modal {
    position: absolute;
    top: 16px;
    right: 16px;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--text-secondary);
    font-size: 20px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.close-modal:hover {
    background: rgba(139, 92, 246, 0.3);
    color: var(--primary);
    transform: rotate(90deg);
}

.modal-icon {
    width: 80px;
    height: 80px;
    margin: 0 auto 16px;
}

.modal-icon img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.3));
}

.modal-name {
    font-size: 18px;
    font-weight: 700;
    color: var(--text-primary);
    text-align: center;
    margin-bottom: 8px;
}

.modal-desc {
    font-size: 14px;
    color: var(--text-secondary);
    text-align: center;
    margin-bottom: 20px;
    line-height: 1.5;
}

.modal-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 20px;
}

/* Modal footer - add category colors */
.modal-footer .badge-rarity.common { 
    background: rgba(16, 185, 129, 0.15); 
    color: var(--common); 
    border: 1px solid rgba(16, 185, 129, 0.3);
}

.modal-footer .badge-rarity.rare { 
    background: rgba(59, 130, 246, 0.15); 
    color: var(--rare); 
    border: 1px solid rgba(59, 130, 246, 0.3);
}

.modal-footer .badge-rarity.ultra-rare { 
    background: rgba(139, 92, 246, 0.15); 
    color: var(--ultra-rare); 
    border: 1px solid rgba(139, 92, 246, 0.3);
}

.modal-footer .badge-rarity.legendary { 
    background: rgba(245, 158, 11, 0.15); 
    color: var(--legendary); 
    border: 1px solid rgba(245, 158, 11, 0.3);
}


/* New Achievement Popup Modal */
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
    background: rgba(30, 27, 36, 0.95);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border-radius: 20px;
    padding: 32px;
    max-width: 400px;
    width: 90%;
    border: 1px solid rgba(139, 92, 246, 0.3);
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
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

.quote-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.quote-modal-header h3 {
    font-size: 22px;
    font-weight: 700;
    color: var(--primary);
    display: flex;
    align-items: center;
    gap: 10px;
}

.quote-modal-content {
    color: var(--text-primary);
    line-height: 1.6;
    margin-bottom: 20px;
    font-size: 16px;
    text-align: left;
}

.achievement-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    margin-bottom: 10px;
    background: rgba(139, 92, 246, 0.08);
    border-radius: 12px;
    border: 1px solid rgba(139, 92, 246, 0.15);
}

.achievement-icon {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.achievement-icon img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}

.achievement-info h4 {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 4px;
}

.achievement-info p {
    font-size: 12px;
    color: var(--text-muted);
}

.more-achievements {
    text-align: center;
    padding: 12px;
    background: rgba(139, 92, 246, 0.1);
    border-radius: 12px;
    border: 1px solid rgba(139, 92, 246, 0.2);
    color: var(--text-muted);
    font-size: 14px;
    margin-top: 10px;
}

.quote-modal-actions {
    display: flex;
    justify-content: center;
    margin-top: 20px;
}

.modal-button {
    padding: 12px 24px;
    border-radius: 12px;
    font-weight: 500;
    cursor: pointer;
    border: none;
    transition: all 0.2s ease;
    backdrop-filter: blur(10px);
}

.modal-button.primary {
    background: var(--primary);
    color: white;
}

.modal-button.primary:hover {
    background: var(--primary-dark);
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(139, 92, 246, 0.3);
}

.icon-button {
    background: rgba(30, 27, 36, 0.8);
    border: 1px solid rgba(139, 92, 246, 0.15);
    cursor: pointer;
    color: var(--text-muted);
    padding: 8px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    position: relative;
    width: 40px;
    height: 40px;
    backdrop-filter: blur(10px);
}

.icon-button:hover {
    background: rgba(139, 92, 246, 0.2);
    color: var(--primary);
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(139, 92, 246, 0.15);
    border-color: rgba(139, 92, 246, 0.3);
}

.icon-button i {
    width: 20px;
    height: 20px;
}

/* Responsive */
@media (max-width: 480px) {
    .badges-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }
    
    .badge-card {
        padding: 12px;
        min-height: 220px;
    }
    
    .badge-icon {
        width: 64px;
        height: 64px;
        margin: 12px auto 12px;
    }
    
    .badge-name {
        font-size: 13px;
        min-height: 32px;
    }
    
    .badge-desc {
        font-size: 11px;
    }
    
    .stat-card {
        padding: 16px;
    }
    
    .stat-count {
        font-size: 20px;
    }
}

@media (min-width: 1024px) {
    .container {
        max-width: 1200px;
    }
}
  
</style>


</head>    
<body>    
    <!-- Header -->    
    <header>    
        <div class="header-container">    
            <div class="logo-section">    
                <img src="assets/images/logo.png" alt="CosmoQuit Logo" class="logo-png">    
                <span class="logo-text">CosmoQuit</span>    
            </div>    
            <a href="home.php" class="back-button">    
                <i data-lucide="arrow-left"></i>    
                <span>Back</span>    
            </a>    
        </div>    
    </header>    

    <!-- Main Content -->    
    <main class="main-content">    
        <div class="container">    
            <!-- Hero Section - Original Design -->
            <section class="welcome-section">  
                <div class="welcome-header">  
                    <h1>Achievements</h1>  
                </div>  
                <p>Track your journey to a smoke-free life with 100 achievement badges</p>  
                
                <div class="achievement-system">  
                    CosmoQuit Achievement System • Your journey matters  
                </div>  
            </section>  

            <!-- Stats Grid -->  
            <div class="stats-grid">  
                <div class="stat-card common">  
                    <div class="stat-count"><?php echo $earned_counts['common']; ?>/<?php echo $counts['common']; ?></div>  
                    <div class="stat-label">Common</div>  
                    <div class="stat-sub">Frequent & Motivational</div>  
                </div>  
                <div class="stat-card rare">  
                    <div class="stat-count"><?php echo $earned_counts['rare']; ?>/<?php echo $counts['rare']; ?></div>  
                    <div class="stat-label">Rare</div>  
                    <div class="stat-sub">Consistency Based</div>  
                </div>  
                <div class="stat-card ultra-rare">  
                    <div class="stat-count"><?php echo $earned_counts['ultra-rare']; ?>/<?php echo $counts['ultra-rare']; ?></div>  
                    <div class="stat-label">Ultra-Rare</div>  
                    <div class="stat-sub">High Effort</div>  
                </div>  
                <div class="stat-card legendary">  
                    <div class="stat-count"><?php echo $earned_counts['legendary']; ?>/<?php echo $counts['legendary']; ?></div>  
                    <div class="stat-label">Legendary</div>  
                    <div class="stat-sub">Elite Achievements</div>  
                </div>  
            </div>  

            <!-- Progress Section -->  
            <div class="progress-section">  
                <div class="progress-header">  
                    <h3>Your Progress</h3>  
                    <div class="progress-percent" id="progress-percent"><?php echo $progress_percent; ?>%</div>  
                </div>  
                <div class="progress-bar-container">  
                    <div class="progress-fill" id="progress-fill" data-percent="<?php echo $progress_percent; ?>"></div>  
                </div>  
                <div class="progress-stats">  
                    <span>Earned: <strong id="earned-count"><?php echo $total_earned; ?></strong></span>  
                    <span>Remaining: <strong id="remaining-count"><?php echo $total_achievements - $total_earned; ?></strong></span>  
                    <span>Total: <?php echo $total_achievements; ?> badges</span>  
                </div>  
            </div>  

            <!-- Filter Bar -->  
            <div class="filter-bar">  
                <button class="filter-btn active" data-filter="all">All</button>  
                <button class="filter-btn" data-filter="common">Common</button>  
                <button class="filter-btn" data-filter="rare">Rare</button>  
                <button class="filter-btn" data-filter="ultra-rare">Ultra-Rare</button>  
                <button class="filter-btn" data-filter="legendary">Legendary</button>  
                <button class="filter-btn" data-filter="earned">Earned</button>  
                <button class="filter-btn" data-filter="locked">Locked</button>  
            </div>  

            <!-- Badges Grid -->  
            <div class="badges-grid" id="badge-container">  
                <!-- Badges generated by JavaScript -->  
            </div>  
        </div>  
    </main>  

    <!-- Badge Details Modal - NEW ADDITION -->
    <div class="badge-modal" id="badge-modal">
        <div class="modal-content">
            <div class="close-modal" id="close-modal">&times;</div>
            <div class="modal-icon" id="modal-icon"></div>
            <div class="modal-name" id="modal-name"></div>
            <div class="modal-desc" id="modal-desc"></div>
            <div class="modal-footer">
                <div class="badge-status" id="modal-status"></div>
                <div class="badge-rarity" id="modal-rarity"></div>
            </div>
        </div>
    </div>

    <!-- New Achievement Popup Modal -->
    <?php if ($show_new_achievement_notification): ?>
    <div class="quote-modal-overlay active" id="newAchievementModal">
        <div class="quote-modal">
            <div class="quote-modal-header">
                <h3>
                    <i data-lucide="trophy"></i>
                    New Achievements Unlocked!
                </h3>
                <button class="icon-button" id="closeAchievementModal">
                    <i data-lucide="x"></i>
                </button>
            </div>
            <div class="quote-modal-content">
                <?php 
                $count = count($new_achievements);
                $show_more = $count > 3;
                $display_count = $show_more ? 3 : $count;
                $more_count = $count - 3;
                
                for ($i = 0; $i < $display_count; $i++):
                    $ach = $new_achievements[$i];
                    $icon = 'https://img.icons8.com/color/96/trophy.png'; // Default icon
                    
                    // Find the achievement icon from achievementData
                    foreach ($achievementData as $full_ach) {
                        if ($full_ach['name'] === $ach['name']) {
                            $icon = $full_ach['icon'];
                            break;
                        }
                    }
                ?>
                <div class="achievement-item">
                    <div class="achievement-icon">
                        <img src="<?php echo $icon; ?>" alt="<?php echo htmlspecialchars($ach['name']); ?>" onerror="this.src='https://img.icons8.com/color/96/trophy.png'">
                    </div>
                    <div class="achievement-info">
                        <h4><?php echo htmlspecialchars($ach['name']); ?></h4>
                        <p><?php echo htmlspecialchars(ucfirst($ach['rarity'])); ?> Achievement</p>
                    </div>
                </div>
                <?php endfor; ?>
                
                <?php if ($show_more): ?>
                <div class="more-achievements">
                    + <?php echo $more_count; ?> more achievements finished!
                </div>
                <?php endif; ?>
            </div>
            <div class="quote-modal-actions">
                <button class="modal-button primary" id="closeAchievementBtn">Close</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>  
        // Achievement data from PHP
        const achievementData = <?php echo json_encode($achievementData); ?>;
        const earnedMap = <?php echo json_encode($earned_map); ?>;
        
        // Initialize app
        document.addEventListener('DOMContentLoaded', function() {
            const badgeContainer = document.getElementById('badge-container');
            const filterButtons = document.querySelectorAll('.filter-btn');
            const progressFill = document.getElementById('progress-fill');
            const progressPercent = document.getElementById('progress-percent');
            const earnedCountElement = document.getElementById('earned-count');
            const remainingCountElement = document.getElementById('remaining-count');
            const badgeModal = document.getElementById('badge-modal');
            const closeModal = document.getElementById('close-modal');
            
            // Calculate progress and update display
            function updateProgress() {
                const earnedCount = Object.keys(earnedMap).length;
                const totalCount = achievementData.length;
                const remainingCount = totalCount - earnedCount;
                const percentage = Math.round((earnedCount / totalCount) * 100);
                
                earnedCountElement.textContent = earnedCount;
                remainingCountElement.textContent = remainingCount;
                progressPercent.textContent = `${percentage}%`;
                
                // Set the width of progress bar with smooth animation
                setTimeout(() => {
                    progressFill.style.width = `${percentage}%`;
                }, 100);
            }
            
            // Initial progress update
            updateProgress();
            
            // Show badge details modal
            function showBadgeDetails(badge) {
                const isEarned = earnedMap[badge.id] || false;
                
                document.getElementById('modal-icon').innerHTML = `<img src="${badge.icon}" alt="${badge.name}" onerror="this.src='https://img.icons8.com/color/96/trophy.png'">`;
                document.getElementById('modal-name').textContent = badge.name;
                document.getElementById('modal-desc').textContent = badge.desc;
                document.getElementById('modal-status').textContent = isEarned ? 'EARNED' : 'LOCKED';
                document.getElementById('modal-status').className = `badge-status ${isEarned ? 'earned' : 'locked'}`;
                document.getElementById('modal-rarity').textContent = badge.rarity.toUpperCase();
                // Make sure it looks exactly like this:
                   document.getElementById('modal-rarity').textContent = badge.rarity.toUpperCase();
                  document.getElementById('modal-rarity').className = `badge-rarity ${badge.rarity}`;
                
                badgeModal.classList.add('active');
            }
            
            // Render badges
            function renderBadges(badges) {
                badgeContainer.innerHTML = '';
                
                badges.forEach(badge => {
                    const isEarned = earnedMap[badge.id] || false;
                    const badgeCard = document.createElement('div');
                    badgeCard.className = `badge-card ${badge.rarity} ${isEarned ? 'earned' : ''}`;
                    
                    badgeCard.innerHTML = `
                        <div class="badge-header">
                            <div class="badge-status ${isEarned ? 'earned' : 'locked'}">
                                ${isEarned ? 'EARNED' : 'LOCKED'}
                            </div>
                            <div class="badge-rarity">${badge.rarity.toUpperCase()}</div>
                        </div>
                        <div class="badge-icon">
                            <img src="${badge.icon}" alt="${badge.name}" loading="lazy" onerror="this.src='https://img.icons8.com/color/96/trophy.png'">
                        </div>
                        <div class="badge-name">${badge.name}</div>
                        <div class="badge-desc">${badge.desc}</div>
                    `;
                    
                    badgeCard.addEventListener('click', () => showBadgeDetails(badge));
                    badgeContainer.appendChild(badgeCard);
                });
            }
            
            // Filter badges
            function filterBadges(filter) {
                let filteredBadges;
                
                switch(filter) {
                    case 'common': filteredBadges = achievementData.filter(b => b.rarity === 'common'); break;
                    case 'rare': filteredBadges = achievementData.filter(b => b.rarity === 'rare'); break;
                    case 'ultra-rare': filteredBadges = achievementData.filter(b => b.rarity === 'ultra-rare'); break;
                    case 'legendary': filteredBadges = achievementData.filter(b => b.rarity === 'legendary'); break;
                    case 'earned': filteredBadges = achievementData.filter(b => earnedMap[b.id]); break;
                    case 'locked': filteredBadges = achievementData.filter(b => !earnedMap[b.id]); break;
                    default: filteredBadges = achievementData;
                }
                
                renderBadges(filteredBadges);
            }
            
            // Event listeners for filter buttons
            filterButtons.forEach(btn => {
                btn.addEventListener('click', function() {
                    filterButtons.forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    filterBadges(this.dataset.filter);
                });
            });
            
            // Close modal events
            closeModal.addEventListener('click', () => {
                badgeModal.classList.remove('active');
            });
            
            badgeModal.addEventListener('click', (e) => {
                if (e.target === badgeModal) {
                    badgeModal.classList.remove('active');
                }
            });
            
            // Close achievement modal
            document.getElementById('closeAchievementModal')?.addEventListener('click', () => {
                document.getElementById('newAchievementModal')?.classList.remove('active');
            });
            
            document.getElementById('closeAchievementBtn')?.addEventListener('click', () => {
                document.getElementById('newAchievementModal')?.classList.remove('active');
            });
            
            // Auto-hide achievement modal after 10 seconds
            const achievementModal = document.getElementById('newAchievementModal');
            if (achievementModal && achievementModal.classList.contains('active')) {
                setTimeout(() => {
                    achievementModal.classList.remove('active');
                }, 10000);
            }
            
            // Initial render with progress animation
            setTimeout(() => {
                const percent = progressFill.getAttribute('data-percent');
                progressFill.style.width = `${percent}%`;
                renderBadges(achievementData);
            }, 300);
            
            // Initialize Lucide icons
            lucide.createIcons();
        });
    </script>
</body>    
</html>