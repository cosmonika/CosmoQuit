<?php
require_once 'includes/session.php';  
require_once 'includes/config.php';
require_once 'includes/Database.php';
require_once 'includes/auth.php';

checkAuth();

$db = new Database();
$conn = $db->getConnection();
$user_id = $_SESSION['user_id'];

// Check if user is blocked from chat
$user_stmt = $conn->prepare("SELECT chat_enabled, name FROM users WHERE id = ?");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_result = $user_stmt->get_result()->fetch_assoc();

if (!$user_result['chat_enabled']) {
    die("Your chat access has been suspended due to policy violation.");
}

$user_name = $user_result['name'];

// Get or create chat session
$chat_stmt = $conn->prepare("
    SELECT c.*, e.full_name as executive_name 
    FROM user_chats c 
    LEFT JOIN support_executives e ON c.executive_id = e.id 
    WHERE c.user_id = ? AND c.status IN ('open', 'assigned')
    ORDER BY c.last_message_at DESC 
    LIMIT 1
");
$chat_stmt->bind_param("i", $user_id);
$chat_stmt->execute();
$chat = $chat_stmt->get_result()->fetch_assoc();

if (!$chat) {
    // Create new chat session
    $create_stmt = $conn->prepare("
        INSERT INTO user_chats (user_id, status) 
        VALUES (?, 'open')
    ");
    $create_stmt->bind_param("i", $user_id);
    $create_stmt->execute();
    $chat_id = $conn->insert_id;
    
    // Reload chat
    $chat_stmt->execute();
    $chat = $chat_stmt->get_result()->fetch_assoc();
}

$chat_id = $chat['id'];
$current_executive = $chat['executive_name'] ?? 'Not assigned yet';
$chat_status = $chat['status'];

// Check if chat is closed
$is_chat_closed = ($chat['status'] === 'closed' || $chat['status'] === 'abuse_reported');

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

// Mark user's messages as read
if (!empty($messages)) {
    $mark_read_stmt = $conn->prepare("
        UPDATE chat_messages 
        SET is_read = 1, read_at = NOW() 
        WHERE chat_id = ? AND sender_type = 'executive' AND is_read = 0
    ");
    $mark_read_stmt->bind_param("i", $chat_id);
    $mark_read_stmt->execute();
}

// Handle new message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_message'])) {
    $message = trim($_POST['user_message']);
    
    if (!empty($message) && !$is_chat_closed) {
        $insert_msg = $conn->prepare("
            INSERT INTO chat_messages (chat_id, sender_type, sender_id, message) 
            VALUES (?, 'user', ?, ?)
        ");
        $insert_msg->bind_param("iis", $chat_id, $user_id, $message);
        $insert_msg->execute();
        
        // Update chat last message time
        $update_chat = $conn->prepare("
            UPDATE user_chats 
            SET last_message_at = NOW() 
            WHERE id = ?
        ");
        $update_chat->bind_param("i", $chat_id);
        $update_chat->execute();
        
        header("Location: chat.php");
        exit();
    }
}

// Add closed notification if chat is closed
if ($is_chat_closed && empty($messages)) {
    $closed_message = $chat['status'] === 'abuse_reported' 
        ? "This chat has been closed due to policy violation. You are no longer able to send messages." 
        : "This chat has been closed by the support executive.";
    
    $messages[] = [
        'sender_type' => 'bot',
        'sender_name' => 'System',
        'message' => $closed_message,
        'created_at' => date('Y-m-d H:i:s')
    ];
}

// Quick questions for support chat
$quick_questions = [
    "I need help with cravings",
    "How does the 30-day challenge work?",
    "Can I reset my progress?",
    "What should I do when stressed?",
    "How to handle social smoking?",
    "Tell me about health benefits"
];
shuffle($quick_questions);
$quick_questions = array_slice($quick_questions, 0, 6);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>Support Chat</title>
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

/* WELCOME SECTION */
.welcome-section {
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

.chat-status {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    margin-top: 8px;
}

.status-open {
    background: var(--warning-soft);
    color: var(--warning);
    border: 1px solid rgba(245, 158, 11, 0.3);
}

.status-assigned {
    background: var(--success-soft);
    color: var(--success);
    border: 1px solid rgba(16, 185, 129, 0.3);
}

.status-closed {
    background: var(--danger-soft);
    color: var(--danger);
    border: 1px solid rgba(239, 68, 68, 0.3);
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
    background: var(--primary);
    color: white;
    margin-left: auto;
    border-bottom-right-radius: 6px;
}

.executive {
    background: var(--glass-bg);
    color: var(--text-primary);
    border: 1px solid var(--glass-border);
    border-bottom-left-radius: 6px;
    margin-right: auto;
}

.bot {
    background: var(--danger-soft);
    color: var(--danger);
    border: 1px solid rgba(239, 68, 68, 0.3);
    margin: 0 auto;
    max-width: 85%;
    text-align: center;
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

.input-box input:disabled {
    opacity: 0.5;
    cursor: not-allowed;
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

.send-btn:hover:not(:disabled) {
    background: #0da271;
}

.send-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* NO MESSAGES */
.no-messages {
    text-align: center;
    padding: 40px 20px;
    color: var(--text-muted);
}

.no-messages i {
    font-size: 48px;
    margin-bottom: 16px;
    opacity: 0.5;
}

/* RESPONSIVE */
@media (max-width: 480px) {
    .message {
        max-width: 85%;
    }
    
    .welcome-section {
        margin: 12px;
        padding: 12px;
    }
    
    .welcome-header {
        flex-direction: column;
        gap: 8px;
    }
    
    .current-day {
        align-self: flex-start;
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
            <small style="color: <?php echo $is_chat_closed ? 'var(--danger)' : ($chat_status === 'assigned' ? 'var(--success)' : 'var(--warning)'); ?>">
                <?php 
                    if ($is_chat_closed) {
                        echo $chat['status'] === 'abuse_reported' ? 'Blocked' : 'Closed';
                    } else {
                        echo $chat_status === 'assigned' ? 'Live Support' : 'Waiting for executive';
                    }
                ?>
            </small>
        </div>
    </div>
    <a href="home.php" class="back-btn">
        <i data-lucide="arrow-left"></i>
        Back
    </a>
</div>

<div class="messages" id="messages">

<?php if (empty($messages)): ?>
    <section class="welcome-section">
        <div class="welcome-header">
            <div>
                <h1>Support Chat</h1>
                <p>Get help from our support team</p>
            </div>
        </div>
        <p>You're connected to <strong><?php echo htmlspecialchars($current_executive); ?></strong></p>
        <div class="chat-status <?php 
            if ($is_chat_closed) echo 'status-closed';
            elseif ($chat_status === 'assigned') echo 'status-assigned';
            else echo 'status-open';
        ?>">
            <i data-lucide="<?php 
                if ($is_chat_closed) echo 'x-circle';
                elseif ($chat_status === 'assigned') echo 'check-circle';
                else echo 'clock';
            ?>" width="12" height="12"></i>
            <?php 
                if ($is_chat_closed) {
                    echo $chat['status'] === 'abuse_reported' ? 'Chat Blocked' : 'Chat Closed';
                } elseif ($chat_status === 'assigned') {
                    echo 'Live Support';
                } else {
                    echo 'Waiting for executive';
                }
            ?>
        </div>
    </section>
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

<?php if (!$is_chat_closed): ?>
<div class="quick-actions">
<?php foreach ($quick_questions as $q): ?>
    <div class="quick-action" onclick="quickSend('<?php echo addslashes($q); ?>')">
        <?php echo htmlspecialchars($q); ?>
    </div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<div class="input-area">
<form method="post" class="chat-form" id="chatForm">
    <div class="input-box">
        <input type="text" name="user_message" placeholder="<?php echo $is_chat_closed ? 'Chat is closed' : 'Type your message...'; ?>" autocomplete="off" <?php echo $is_chat_closed ? 'disabled' : 'required'; ?>>
    </div>
    <button type="submit" class="send-btn" <?php echo $is_chat_closed ? 'disabled' : ''; ?>>
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
    document.getElementById('chatForm').submit();
}

// Auto-refresh messages every 5 seconds
setInterval(() => {
    fetch('api/get_chat_messages.php?chat_id=<?php echo $chat_id; ?>')
        .then(response => response.json())
        .then(data => {
            if (data.new_messages) {
                location.reload();
            }
        });
}, 5000);

// Form submission
document.getElementById('chatForm')?.addEventListener('submit', function(e) {
    <?php if ($is_chat_closed): ?>
    e.preventDefault();
    alert('This chat has been closed. You cannot send messages.');
    <?php else: ?>
    const button = this.querySelector('button[type="submit"]');
    button.disabled = true;
    button.innerHTML = '<i data-lucide="loader"></i>';
    
    setTimeout(() => {
        button.disabled = false;
        button.innerHTML = '<i data-lucide="send"></i>';
    }, 2000);
    <?php endif; ?>
});
</script>
</body>
</html>