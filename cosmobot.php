<?php
session_start();
require_once 'includes/session.php';
require_once 'includes/config.php';
require_once 'includes/Database.php';
require_once 'includes/auth.php';

checkAuth();

/* ---------------------------------------------------
   LOAD USER + PROGRESS (SAME AS home.php)
--------------------------------------------------- */
$db = new Database();
$conn = $db->getConnection();
$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT u.name,
           up.current_day,
           up.current_streak,
           up.longest_streak,
           up.total_days_smoke_free,
           up.total_money_saved,
           up.lung_capacity_percentage,
           up.health_score
    FROM users u
    LEFT JOIN user_progress up ON u.id = up.user_id
    WHERE u.id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

$name        = $user['name'] ?? 'User';
$current_day = (int)($user['current_day'] ?? 0);

/* ---------------------------------------------------
   LOAD QNA DATA
--------------------------------------------------- */
$qnaPath = __DIR__ . '/data/qna.json';
$qnaData = json_decode(file_get_contents($qnaPath), true);

if (!isset($_SESSION['cosmobot_history'])) {
    $_SESSION['cosmobot_history'] = [];
}

/* ---------------------------------------------------
   QUICK QUESTIONS
--------------------------------------------------- */
$quick_questions = [];
foreach ($qnaData['responses'] as $cat) {
    if (!empty($cat['potential_questions'])) {
        $quick_questions = array_merge(
            $quick_questions,
            array_slice($cat['potential_questions'], 0, 2)
        );
    }
}
shuffle($quick_questions);
$quick_questions = array_slice($quick_questions, 0, 6);

/* ---------------------------------------------------
   HANDLE MESSAGE
--------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['user_message'])) {

    $msg = trim($_POST['user_message']);
    $_SESSION['cosmobot_history'][] = ['type' => 'user', 'text' => $msg];

    $lower = strtolower($msg);
    $found = false;
    $emergency = false;

    /* ---- PROGRESS / STATS INTENT ---- */
    if (preg_match('/(progress|status|stats|money|streak|lungs|health|days)/i', $lower)) {

        $reply =
            "📊 Your Current Progress\n\n" .
            "🗓 Day: {$current_day} / 30\n" .
            "🔥 Current Streak: {$user['current_streak']} days\n" .
            "🏆 Longest Streak: {$user['longest_streak']} days\n" .
            "🚭 Smoke-Free Days: {$user['total_days_smoke_free']}\n" .
            "💰 Money Saved: ₹" . number_format($user['total_money_saved'], 2) . "\n" .
            "🫁 Lung Capacity: {$user['lung_capacity_percentage']}%\n" .
            "❤️ Health Score: {$user['health_score']} / 100";

        $found = true;
    }

    /* ---- NORMAL QNA ---- */
    if (!$found) {
        foreach ($qnaData['responses'] as $cat) {
            foreach ($cat['keywords'] ?? [] as $k) {
                if (strpos($lower, $k) !== false) {
                    $reply = $cat['answers'][array_rand($cat['answers'])];
                    $emergency = $cat['emergency'] ?? false;
                    $found = true;
                    break 2;
                }
            }
        }
    }

    /* ---- GREETINGS ---- */
    if (!$found && preg_match('/^(hi|hello|hey|start)/i', $lower)) {
        $reply = $qnaData['greetings'][array_rand($qnaData['greetings'])];
        $found = true;
    }

    /* ---- FALLBACK ---- */
    if (!$found) {
        $reply = $qnaData['unknown_responses'][array_rand($qnaData['unknown_responses'])];
    }

    $_SESSION['cosmobot_history'][] = [
        'type' => 'bot',
        'text' => $reply,
        'emergency' => $emergency
    ];

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>Cosmo Bot</title>
<script src="https://unpkg.com/lucide@latest"></script>

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
    box-sizing: border-box;
    font-family: 'Inter', system-ui, sans-serif;
}

body {
    margin: 0;
    background-color: var(--main-bg);
    background-image: url("image/svg+xml,%3Csvg width='100' height='100' viewBox='0 0 100 100' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M11 18c3.866 0 7-3.134 7-7s-3.134-7-7-7-7 3.134-7 7 3.134 7 7 7zm48 25c3.866 0 7-3.134 7-7s-3.134-7-7-7-7 3.134-7 7 3.134 7 7 7zm-43-7c1.657 0 3-1.343 3-3s-1.343-3-3-3-3 1.343-3 3 1.343 3 3 3zm63 31c1.657 0 3-1.343 3-3s-1.343-3-3-3-3 1.343-3 3 1.343 3 3 3zM34 90c1.657 0 3-1.343 3-3s-1.343-3-3-3-3 1.343-3 3 1.343 3 3 3zm56-76c1.657 0 3-1.343 3-3s-1.343-3-3-3-3 1.343-3 3 1.343 3 3 3zM12 86c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm28-65c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm23-11c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5zm-6 60c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm29 22c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5zM32 63c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5zm57-13c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5zm-9-21c1.105 0 2-.895 2-2s-.895-2-2-2-2 .895-2 2 .895 2 2 2zM60 91c1.105 0 2-.895 2-2s-.895-2-2-2-2 .895-2 2 .895 2 2 2zM35 41c1.105 0 2-.895 2-2s-.895-2-2-2-2 .895-2 2 .895 2 2 2zM12 60c1.105 0 2-.895 2-2s-.895-2-2-2-2 .895-2 2 .895 2 2 2z' fill='rgba(255,255,255,0.05)' fill-opacity='0.05' fill-rule='evenodd'/%3E%3C/svg%3E");
    color: var(--text-primary);
    height: 100vh;
    overflow: hidden;
}

.chat-container {
    display: flex;
    flex-direction: column;
    height: 100vh;
}

/* HEADER */
.chat-header {
    position: sticky;
    top: 0;
    z-index: 200;
    background: var(--glass-bg);
    backdrop-filter: var(--glass-backdrop);
    -webkit-backdrop-filter: var(--glass-backdrop);
    border-bottom: 1px solid var(--glass-border);
    padding: 12px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    color: var(--text-primary);
}

.chat-header .info {
    display: flex;
    gap: 10px;
    align-items: center;
}

.chat-header img {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    border: 2px solid var(--primary);
}

/* WELCOME SECTION */
.welcome-section {
    background: rgba(30, 27, 36, 0.6);
    backdrop-filter: blur(12px);
    border: 1px solid var(--glass-border);
    border-radius: 16px;
    padding: 16px;
    margin: 16px 0;
    color: var(--text-primary);
    font-size: 14px;
    line-height: 1.5;
}

.welcome-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 12px;
}

.welcome-header h1 {
    font-size: 18px;
    margin: 0;
    color: var(--text-primary);
}

.welcome-header p {
    margin: 4px 0 0;
    color: var(--text-muted);
    font-size: 13px;
}

.current-day {
    background: var(--primary-soft);
    color: var(--text-primary);
    padding: 4px 10px;
    border-radius: 20px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 6px;
}

.motivation-quote {
    font-style: italic;
    color: var(--text-light);
    margin-top: 8px;
}

.quote-author {
    text-align: right;
    margin-top: 6px;
    font-style: normal;
    color: var(--text-muted);
    font-size: 13px;
}

/* MESSAGES */
.messages {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
    padding-bottom: 180px; /* space for quick + input */
    display: flex;
    flex-direction: column;
    /* Hide scrollbar while keeping functionality */
    scrollbar-width: none; /* Firefox */
}
.messages::-webkit-scrollbar {
    display: none; /* Chrome, Safari, Edge */
}

.message {
    max-width: 75%;
    padding: 12px 16px;
    margin-bottom: 12px;
    border-radius: 18px;
    line-height: 1.45;
    word-wrap: break-word;
    position: relative;
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
}

.user {
    background: var(--primary);
    color: white;
    margin-left: auto;
    border-bottom-right-radius: 6px;
}

.bot {
    background: var(--glass-bg);
    color: var(--text-primary);
    border: 1px solid var(--glass-border);
    border-bottom-left-radius: 6px;
}

.emergency {
    background: var(--danger) !important;
    color: white !important;
    border: none !important;
}

/* QUICK ACTIONS */
.quick-actions {
    position: fixed;
    bottom: 80px;
    left: 0;
    right: 0;
    display: flex;
    gap: 8px;
    padding: 8px 12px;
    overflow-x: auto;
    background: rgba(15, 11, 22, 0.7);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    border-top: 1px solid var(--glass-border);
    z-index: 50;
    /* Hide scrollbar */
    scrollbar-width: none;
}
.quick-actions::-webkit-scrollbar {
    display: none;
}

.quick-action {
    background: var(--primary-soft);
    color: var(--text-primary);
    padding: 8px 14px;
    border-radius: 20px;
    font-size: 13px;
    white-space: nowrap;
    cursor: pointer;
    transition: background 0.2s;
    border: 1px solid transparent;
}

.quick-action:hover {
    background: var(--primary);
    color: white;
}

/* INPUT AREA */
.input-area {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    padding: 12px;
    background: var(--glass-bg);
    backdrop-filter: var(--glass-backdrop);
    -webkit-backdrop-filter: var(--glass-backdrop);
    border-top: 1px solid var(--glass-border);
    z-index: 100;
}

.chat-form {
    display: flex;
    align-items: center;
    gap: 10px;
}

.input-box {
    flex: 1;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 30px;
    padding: 12px 16px;
    border: 1px solid var(--glass-border);
}

.input-box input {
    width: 100%;
    border: none;
    background: none;
    outline: none;
    font-size: 16px;
    color: var(--text-primary);
}

.send-btn {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    border: none;
    background: var(--success);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background 0.2s;
}

.send-btn:hover {
    background: #0da271;
}
</style>


</head>

<body>
<div class="chat-container">

<div class="chat-header">
    <div class="info">
        <img src="https://img.icons8.com/stickers/500/message-bot.png">
        <div>
            <strong>Cosmo Bot</strong><br>
            <small style="color: var(--success)">Online</small>
        </div>
    </div>
    <a href="home.php" style="color: var(--text-primary); text-decoration: none;">
        <i data-lucide="arrow-left"></i>
    </a>
</div>

<div class="messages" id="messages">

<?php if (empty($_SESSION['cosmobot_history'])): ?>
    <!-- CLEAN WELCOME (NO QUOTES, NOTHING EXTRA) -->
    <section class="welcome-section">
        <div class="welcome-header">
            <div>
                <h1>Welcome, <?= htmlspecialchars($name) ?>!</h1>
                <p>You’re on day <?= $current_day ?> of your 30-day smoke-free journey.</p>
            </div>
            <div class="current-day">
                <i data-lucide="calendar"></i>
                Day <?= $current_day ?> of 30
            </div>
        </div>
    </section>
<?php endif; ?>

<?php foreach ($_SESSION['cosmobot_history'] as $m): ?>
<div class="message <?= $m['type'] ?> <?= !empty($m['emergency']) ? 'emergency' : '' ?>">
    <?= nl2br(htmlspecialchars($m['text'])) ?>
</div>
<?php endforeach; ?>

</div>

<div class="quick-actions">
<?php foreach ($quick_questions as $q): ?>
    <div class="quick-action" onclick="quickSend('<?= addslashes($q) ?>')">
        <?= htmlspecialchars($q) ?>
    </div>
<?php endforeach; ?>
</div>

<div class="input-area">
<form method="post" class="chat-form">
    <div class="input-box">
        <input type="text" name="user_message" placeholder="Type a message…" autocomplete="off" required>
    </div>
    <button type="submit" class="send-btn">
        <i data-lucide="send"></i>
    </button>
</form>
</div>

</div>

<script>
lucide.createIcons();
const m = document.getElementById("messages");
m.scrollTop = m.scrollHeight;

function quickSend(t) {
    document.querySelector('input[name="user_message"]').value = t;
    document.forms[0].submit();
}
</script>
</body>
</html>