<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/Database.php';

// Check executive authentication
if (!isset($_SESSION['executive_id'])) {
    header("Location: support_executive.php");
    exit();
}

$db = new Database();
$conn = $db->getConnection();
$executive_id = $_SESSION['executive_id'];
$chat_id = $_GET['chat_id'] ?? 0;

// Verify chat belongs to executive
$chat_stmt = $conn->prepare("
    SELECT 
        c.*,
        u.name as user_name,
        u.mobile,
        u.email
    FROM user_chats c
    JOIN users u ON c.user_id = u.id
    WHERE c.id = ? AND c.executive_id = ? AND c.status = 'assigned'
");
$chat_stmt->bind_param("ii", $chat_id, $executive_id);
$chat_stmt->execute();
$chat = $chat_stmt->get_result()->fetch_assoc();

if (!$chat) {
    header("Location: executive_dashboard.php");
    exit();
}

// Load chat messages
$messages_stmt = $conn->prepare("
    SELECT 
        cm.*,
        CASE 
            WHEN cm.sender_type = 'user' THEN u.name
            WHEN cm.sender_type = 'executive' THEN e.full_name
        END as sender_name
    FROM chat_messages cm
    LEFT JOIN users u ON cm.sender_type = 'user' AND cm.sender_id = u.id
    LEFT JOIN support_executives e ON cm.sender_type = 'executive' AND cm.sender_id = e.id
    WHERE cm.chat_id = ?
    ORDER BY cm.created_at ASC
");
$messages_stmt->bind_param("i", $chat_id);
$messages_stmt->execute();
$messages = $messages_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Mark executive's messages as read
if (!empty($messages)) {
    $mark_read_stmt = $conn->prepare("
        UPDATE chat_messages 
        SET is_read = 1, read_at = NOW() 
        WHERE chat_id = ? AND sender_type = 'user' AND is_read = 0
    ");
    $mark_read_stmt->bind_param("i", $chat_id);
    $mark_read_stmt->execute();
}

// Handle new message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_message'])) {
    $message = trim($_POST['user_message']);
    
    if (!empty($message)) {
        $insert_msg = $conn->prepare("
            INSERT INTO chat_messages (chat_id, sender_type, sender_id, message) 
            VALUES (?, 'executive', ?, ?)
        ");
        $insert_msg->bind_param("iis", $chat_id, $executive_id, $message);
        $insert_msg->execute();
        
        // Update chat last message time
        $update_chat = $conn->prepare("
            UPDATE user_chats 
            SET last_message_at = NOW() 
            WHERE id = ?
        ");
        $update_chat->bind_param("i", $chat_id);
        $update_chat->execute();
        
        header("Location: executive_chat.php?chat_id=" . $chat_id);
        exit();
    }
}

// Quick responses for support
$quick_responses = [
    "Hello! How can I help you today?",
    "I understand, please tell me more.",
    "That's a great question!",
    "Let me help you with that.",
    "Thank you for sharing.",
    "Is there anything else I can help with?"
];
shuffle($quick_responses);
$quick_responses = array_slice($quick_responses, 0, 6);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>Chat with <?php echo htmlspecialchars($chat['user_name']); ?></title>
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

.back-btn {
    background: var(--primary-soft);
    color: var(--text-primary);
    border: 1px solid rgba(139, 92, 246, 0.3);
    padding: 8px 12px;
    border-radius: 20px;
    text-decoration: none;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
}

.back-btn:hover {
    background: rgba(139, 92, 246, 0.3);
    transform: translateY(-2px);
}

/* USER INFO */
.user-info {
    background: rgba(30, 27, 36, 0.6);
    backdrop-filter: blur(12px);
    border: 1px solid var(--glass-border);
    border-radius: 16px;
    padding: 16px;
    margin: 16px;
    color: var(--text-primary);
    font-size: 14px;
    line-height: 1.5;
}

.user-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
}

.user-avatar {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, var(--primary), var(--primary-light));
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    font-weight: 700;
    color: white;
}

.user-details h2 {
    font-size: 18px;
    margin: 0;
    color: var(--text-primary);
}

.user-details p {
    margin: 4px 0 0;
    color: var(--text-muted);
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 8px;
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
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.user {
    background: var(--glass-bg);
    color: var(--text-primary);
    border: 1px solid var(--glass-border);
    border-bottom-left-radius: 6px;
    margin-right: auto;
}

.executive {
    background: var(--primary);
    color: white;
    margin-left: auto;
    border-bottom-right-radius: 6px;
}

.message-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 4px;
}

.message-sender {
    font-weight: 600;
    font-size: 13px;
    opacity: 0.9;
}

.message-time {
    font-size: 11px;
    opacity: 0.7;
}

.message-content {
    font-size: 14px;
    line-height: 1.4;
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

/* RESPONSIVE */
@media (max-width: 480px) {
    .message {
        max-width: 85%;
    }
    
    .user-info {
        margin: 12px;
        padding: 12px;
    }
    
    .user-header {
        flex-direction: column;
        text-align: center;
        gap: 8px;
    }
    
    .user-details p {
        flex-direction: column;
        gap: 4px;
        align-items: center;
    }
    
    .quick-action {
        padding: 6px 12px;
        font-size: 12px;
    }
    
    .chat-header img {
        width: 36px;
        height: 36px;
    }
}
</style>
</head>

<body>
<div class="chat-container">

<div class="chat-header">
    <div class="info">
        <img src="https://img.icons8.com/stickers/500/customer-support.png" alt="Support">
        <div>
            <strong>Support Chat</strong><br>
            <small style="color: var(--success)">Live Support</small>
        </div>
    </div>
    <a href="executive_dashboard.php" class="back-btn">
        <i data-lucide="arrow-left"></i>
        Back
    </a>
</div>

<div class="user-info">
    <div class="user-header">
        <div class="user-avatar">
            <?php echo strtoupper(substr($chat['user_name'], 0, 1)); ?>
        </div>
        <div class="user-details">
            <h2><?php echo htmlspecialchars($chat['user_name']); ?></h2>
            <p>
                <i data-lucide="phone" width="14" height="14"></i>
                <?php echo htmlspecialchars($chat['mobile']); ?>
                <?php if ($chat['email']): ?>
                    <span>•</span>
                    <i data-lucide="mail" width="14" height="14"></i>
                    <?php echo htmlspecialchars($chat['email']); ?>
                <?php endif; ?>
            </p>
        </div>
    </div>
</div>

<div class="messages" id="messages">

<?php if (empty($messages)): ?>
    <div class="message executive">
        <div class="message-header">
            <span class="message-sender">Support</span>
            <span class="message-time"><?php echo date('h:i A'); ?></span>
        </div>
        <div class="message-content">
            Hello! How can I help you today?
        </div>
    </div>
<?php else: ?>
    <?php foreach ($messages as $msg): ?>
    <div class="message <?php echo $msg['sender_type']; ?>">
        <div class="message-header">
            <span class="message-sender">
                <?php echo htmlspecialchars($msg['sender_name']); ?>
            </span>
            <span class="message-time">
                <?php echo date('h:i A', strtotime($msg['created_at'])); ?>
            </span>
        </div>
        <div class="message-content">
            <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

</div>

<div class="quick-actions">
<?php foreach ($quick_responses as $response): ?>
    <div class="quick-action" onclick="quickSend('<?php echo addslashes($response); ?>')">
        <?php echo htmlspecialchars($response); ?>
    </div>
<?php endforeach; ?>
</div>

<div class="input-area">
<form method="post" class="chat-form">
    <div class="input-box">
        <input type="text" name="user_message" placeholder="Type your response..." autocomplete="off" required>
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

// Auto-refresh messages every 3 seconds
setInterval(() => {
    fetch('api/get_chat_messages_executive.php?chat_id=<?php echo $chat_id; ?>')
        .then(response => response.json())
        .then(data => {
            if (data.new_messages) {
                location.reload();
            }
        });
}, 3000);

// Form submission
document.querySelector('form')?.addEventListener('submit', function(e) {
    const button = this.querySelector('button[type="submit"]');
    button.disabled = true;
    button.innerHTML = '<i data-lucide="loader"></i>';
    
    setTimeout(() => {
        button.disabled = false;
        button.innerHTML = '<i data-lucide="send"></i>';
    }, 2000);
});
</script>
</body>
</html>