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

// Get executive info
$executive_stmt = $conn->prepare("
    SELECT * FROM support_executives 
    WHERE id = ?
");
$executive_stmt->bind_param("i", $executive_id);
$executive_stmt->execute();
$executive = $executive_stmt->get_result()->fetch_assoc();

// Get assigned chats
$assigned_chats_stmt = $conn->prepare("
    SELECT 
        c.*,
        u.name as user_name,
        u.mobile,
        COUNT(cm.id) as message_count,
        SUM(CASE WHEN cm.sender_type = 'user' AND cm.is_read = 0 THEN 1 ELSE 0 END) as unread_count
    FROM user_chats c
    JOIN users u ON c.user_id = u.id
    LEFT JOIN chat_messages cm ON c.id = cm.chat_id
    WHERE c.executive_id = ? AND c.status = 'assigned'
    GROUP BY c.id
    ORDER BY c.last_message_at DESC
");
$assigned_chats_stmt->bind_param("i", $executive_id);
$assigned_chats_stmt->execute();
$assigned_chats = $assigned_chats_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get available chats
$available_chats_stmt = $conn->prepare("
    SELECT 
        c.*,
        u.name as user_name,
        u.mobile,
        COUNT(cm.id) as message_count
    FROM user_chats c
    JOIN users u ON c.user_id = u.id
    LEFT JOIN chat_messages cm ON c.id = cm.chat_id
    WHERE c.status = 'open' AND c.executive_id IS NULL
    GROUP BY c.id
    ORDER BY c.last_message_at DESC
    LIMIT 10
");
$available_chats_stmt->execute();
$available_chats = $available_chats_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get statistics
$stats_stmt = $conn->prepare("
    SELECT 
        COUNT(CASE WHEN status = 'assigned' AND executive_id = ? THEN 1 END) as my_chats,
        COUNT(CASE WHEN status = 'open' AND executive_id IS NULL THEN 1 END) as available_chats,
        COUNT(CASE WHEN status = 'closed' AND executive_id = ? AND DATE(closed_at) = CURDATE() THEN 1 END) as closed_today
    FROM user_chats 
");
$stats_stmt->bind_param("ii", $executive_id, $executive_id);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();

// Handle chat assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['assign_chat'])) {
        $chat_id = $_POST['chat_id'];
        
        $assign_stmt = $conn->prepare("
            UPDATE user_chats 
            SET executive_id = ?, status = 'assigned' 
            WHERE id = ? AND status = 'open'
        ");
        $assign_stmt->bind_param("ii", $executive_id, $chat_id);
        $assign_stmt->execute();
        
        if ($assign_stmt->affected_rows > 0) {
            header("Location: executive_chat.php?chat_id=" . $chat_id);
            exit();
        }
    }
    
    // Handle close chat
    if (isset($_POST['close_chat'])) {
        $chat_id = $_POST['chat_id'];
        $close_reason = $_POST['close_reason'] ?? '';
        
        // Update chat status to closed
        $close_stmt = $conn->prepare("
            UPDATE user_chats 
            SET status = 'closed', 
                closed_by = ?, 
                closed_at = NOW()
            WHERE id = ? AND executive_id = ?
        ");
        $close_stmt->bind_param("iii", $executive_id, $chat_id, $executive_id);
        $close_stmt->execute();
        
        // Add system message about chat closure
        $system_msg = "This chat has been closed by the support executive.";
        if (!empty($close_reason)) {
            $system_msg .= " Reason: " . $close_reason;
        }
        
        $insert_msg = $conn->prepare("
            INSERT INTO chat_messages (chat_id, sender_type, sender_id, message) 
            VALUES (?, 'executive', ?, ?)
        ");
        $insert_msg->bind_param("iis", $chat_id, $executive_id, $system_msg);
        $insert_msg->execute();
        
        header("Location: executive_dashboard.php");
        exit();
    }
    
    // Handle abuse report
    if (isset($_POST['report_abuse'])) {
        $chat_id = $_POST['chat_id'];
        $abuse_reason = $_POST['abuse_reason'] ?? '';
        
        // Update chat status to abuse_reported
        $abuse_stmt = $conn->prepare("
            UPDATE user_chats 
            SET status = 'abuse_reported', 
                abuse_reported = TRUE,
                abuse_reason = ?,
                abuse_reported_at = NOW(),
                closed_by = ?,
                closed_at = NOW()
            WHERE id = ? AND executive_id = ?
        ");
        $abuse_stmt->bind_param("siii", $abuse_reason, $executive_id, $chat_id, $executive_id);
        $abuse_stmt->execute();
        
        // Block user from chat
        $block_stmt = $conn->prepare("
            UPDATE users u
            JOIN user_chats c ON u.id = c.user_id
            SET u.chat_enabled = FALSE
            WHERE c.id = ?
        ");
        $block_stmt->bind_param("i", $chat_id);
        $block_stmt->execute();
        
        // Add system message about abuse report
        $abuse_msg = "This chat has been closed due to policy violation. The user has been blocked from chat.";
        
        $insert_abuse_msg = $conn->prepare("
            INSERT INTO chat_messages (chat_id, sender_type, sender_id, message) 
            VALUES (?, 'executive', ?, ?)
        ");
        $insert_abuse_msg->bind_param("iis", $chat_id, $executive_id, $abuse_msg);
        $insert_abuse_msg->execute();
        
        header("Location: executive_dashboard.php");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>Support Dashboard</title>
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
    margin: 0;
    padding: 0;
}

body {
    background-color: var(--main-bg);
    color: var(--text-primary);
    min-height: 100vh;
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

.logout-btn {
    background: var(--danger-soft);
    color: var(--danger);
    border: 1px solid rgba(239, 68, 68, 0.3);
    padding: 8px 12px;
    border-radius: 20px;
    text-decoration: none;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
}

.logout-btn:hover {
    background: var(--danger);
    color: white;
}

/* MAIN CONTENT */
.main-content {
    padding: 16px;
}

/* STATS GRID */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 12px;
    margin-bottom: 24px;
}

.stat-card {
    background: var(--glass-bg);
    backdrop-filter: blur(12px);
    border: 1px solid var(--glass-border);
    border-radius: 16px;
    padding: 16px;
    text-align: center;
}

.stat-value {
    font-size: 28px;
    font-weight: 700;
    color: var(--primary);
    margin-bottom: 4px;
}

.stat-label {
    font-size: 12px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* SECTION TABS */
.section-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 20px;
    border-bottom: 1px solid var(--glass-border);
    padding-bottom: 12px;
    overflow-x: auto;
    scrollbar-width: none;
}

.section-tabs::-webkit-scrollbar {
    display: none;
}

.tab-btn {
    background: var(--primary-soft);
    color: var(--text-primary);
    padding: 10px 16px;
    border-radius: 12px;
    border: 1px solid transparent;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    white-space: nowrap;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 6px;
}

.tab-btn.active {
    background: var(--primary);
    color: white;
}

/* CHAT LISTS */
.chat-section {
    display: none;
}

.chat-section.active {
    display: block;
}

.chat-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.chat-item {
    background: var(--glass-bg);
    backdrop-filter: blur(12px);
    border: 1px solid var(--glass-border);
    border-radius: 16px;
    padding: 16px;
    transition: all 0.2s;
}

.chat-item:hover {
    border-color: var(--primary);
    transform: translateY(-2px);
}

.chat-header-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.chat-user {
    font-weight: 600;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 8px;
}

.chat-status {
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
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

.chat-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    color: var(--text-muted);
    font-size: 13px;
    margin-bottom: 16px;
}

.chat-meta span {
    display: flex;
    align-items: center;
    gap: 4px;
}

.unread-badge {
    background: var(--danger);
    color: white;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 600;
}

.chat-actions {
    display: flex;
    gap: 8px;
}

.action-btn {
    flex: 1;
    padding: 10px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    border: none;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}

.primary-btn {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: white;
}

.primary-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
}

.warning-btn {
    background: var(--warning-soft);
    color: var(--warning);
    border: 1px solid rgba(245, 158, 11, 0.3);
}

.warning-btn:hover {
    background: rgba(245, 158, 11, 0.3);
}

.danger-btn {
    background: var(--danger-soft);
    color: var(--danger);
    border: 1px solid rgba(239, 68, 68, 0.3);
}

.danger-btn:hover {
    background: rgba(239, 68, 68, 0.3);
}

/* EMPTY STATE */
.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: var(--text-muted);
}

.empty-state i {
    font-size: 48px;
    margin-bottom: 16px;
    opacity: 0.5;
}

.empty-state h3 {
    font-size: 18px;
    margin-bottom: 8px;
    color: var(--text-primary);
}

.empty-state p {
    font-size: 14px;
}

/* MODALS */
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
    padding: 16px;
}

.modal-overlay.active {
    display: flex;
}

.modal {
    background: var(--glass-bg);
    backdrop-filter: blur(20px);
    border-radius: 20px;
    padding: 24px;
    width: 100%;
    max-width: 400px;
    border: 1px solid var(--glass-border);
    animation: modalFadeIn 0.3s ease;
}

@keyframes modalFadeIn {
    from { opacity: 0; transform: translateY(-20px); }
    to { opacity: 1; transform: translateY(0); }
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
}

.modal-header h2 {
    font-size: 18px;
    font-weight: 700;
    color: var(--primary);
    display: flex;
    align-items: center;
    gap: 8px;
}

.modal-content {
    margin-bottom: 20px;
}

.modal-content p {
    color: var(--text-muted);
    margin-bottom: 12px;
    font-size: 14px;
}

.modal-content textarea {
    width: 100%;
    padding: 12px;
    background: rgba(30, 27, 36, 0.8);
    border: 1px solid var(--glass-border);
    border-radius: 10px;
    color: var(--text-primary);
    font-family: 'Inter', sans-serif;
    font-size: 14px;
    min-height: 100px;
    resize: vertical;
}

.modal-actions {
    display: flex;
    gap: 8px;
}

.modal-btn {
    flex: 1;
    padding: 12px;
    border-radius: 10px;
    font-weight: 500;
    cursor: pointer;
    border: none;
    font-size: 14px;
    transition: all 0.2s;
}

.secondary-btn {
    background: var(--primary-soft);
    color: var(--text-muted);
    border: 1px solid rgba(139, 92, 246, 0.3);
}

.secondary-btn:hover {
    background: rgba(139, 92, 246, 0.3);
    color: var(--primary);
}

.warning-btn-modal {
    background: var(--warning);
    color: white;
}

.warning-btn-modal:hover {
    background: #D97706;
}

.danger-btn-modal {
    background: var(--danger);
    color: white;
}

.danger-btn-modal:hover {
    background: #DC2626;
}

/* MOBILE OPTIMIZATIONS */
@media (max-width: 480px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .chat-actions {
        flex-direction: column;
    }
    
    .chat-meta {
        flex-direction: column;
        gap: 6px;
    }
    
    .modal {
        padding: 20px;
    }
    
    .modal-actions {
        flex-direction: column;
    }
}

/* LOADING */
.loading {
    display: none;
    text-align: center;
    padding: 20px;
    color: var(--text-muted);
}

.loading.active {
    display: block;
}

.loading i {
    animation: spin 1s linear infinite;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
</style>
</head>

<body>
<div class="chat-container">
    <!-- HEADER -->
    <div class="chat-header">
        <div class="info">
            <img src="https://img.icons8.com/stickers/500/administrator-male.png" alt="Executive">
            <div>
                <strong>Support Dashboard</strong><br>
                <small style="color: var(--success)">Online</small>
            </div>
        </div>
        <a href="executive_logout.php" class="logout-btn">
            <i data-lucide="log-out" width="16" height="16"></i>
            Logout
        </a>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main-content">
        <!-- STATS -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo count($assigned_chats); ?></div>
                <div class="stat-label">My Chats</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo count($available_chats); ?></div>
                <div class="stat-label">Available</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">
                    <?php 
                        $unread = 0;
                        foreach ($assigned_chats as $chat) {
                            $unread += $chat['unread_count'];
                        }
                        echo $unread;
                    ?>
                </div>
                <div class="stat-label">Unread</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['closed_today'] ?? 0; ?></div>
                <div class="stat-label">Closed Today</div>
            </div>
        </div>

        <!-- TABS -->
        <div class="section-tabs" id="tabs">
            <button class="tab-btn active" onclick="showSection('assigned')">
                <i data-lucide="headphones"></i>
                My Chats (<?php echo count($assigned_chats); ?>)
            </button>
            <button class="tab-btn" onclick="showSection('available')">
                <i data-lucide="message-square"></i>
                Available (<?php echo count($available_chats); ?>)
            </button>
        </div>

        <!-- ASSIGNED CHATS -->
        <div id="assigned-section" class="chat-section active">
            <?php if (empty($assigned_chats)): ?>
                <div class="empty-state">
                    <i data-lucide="messages-square"></i>
                    <h3>No assigned chats</h3>
                    <p>Assign yourself a chat from available chats</p>
                </div>
            <?php else: ?>
                <div class="chat-list">
                    <?php foreach ($assigned_chats as $chat): ?>
                        <div class="chat-item">
                            <div class="chat-header-row">
                                <div class="chat-user">
                                    <i data-lucide="user" width="14" height="14"></i>
                                    <?php echo htmlspecialchars($chat['user_name']); ?>
                                    <?php if ($chat['unread_count'] > 0): ?>
                                        <span class="unread-badge"><?php echo $chat['unread_count']; ?> new</span>
                                    <?php endif; ?>
                                </div>
                                <div class="chat-status status-assigned">
                                    <i data-lucide="check-circle" width="10" height="10"></i>
                                    Assigned
                                </div>
                            </div>
                            <div class="chat-meta">
                                <span>
                                    <i data-lucide="phone" width="12" height="12"></i>
                                    <?php echo htmlspecialchars($chat['mobile']); ?>
                                </span>
                                <span>
                                    <i data-lucide="message-square" width="12" height="12"></i>
                                    <?php echo $chat['message_count']; ?> messages
                                </span>
                                <span>
                                    <i data-lucide="clock" width="12" height="12"></i>
                                    <?php echo date('h:i A', strtotime($chat['last_message_at'])); ?>
                                </span>
                            </div>
                            <div class="chat-actions">
                                <a href="executive_chat.php?chat_id=<?php echo $chat['id']; ?>" 
                                   class="action-btn primary-btn">
                                    <i data-lucide="message-circle"></i>
                                    Open Chat
                                </a>
                                <button class="action-btn warning-btn" 
                                        onclick="showCloseModal(<?php echo $chat['id']; ?>, '<?php echo addslashes($chat['user_name']); ?>')">
                                    <i data-lucide="x-circle"></i>
                                    Close
                                </button>
                                <button class="action-btn danger-btn" 
                                        onclick="showAbuseModal(<?php echo $chat['id']; ?>, '<?php echo addslashes($chat['user_name']); ?>')">
                                    <i data-lucide="flag"></i>
                                    Report
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- AVAILABLE CHATS -->
        <div id="available-section" class="chat-section">
            <?php if (empty($available_chats)): ?>
                <div class="empty-state">
                    <i data-lucide="messages-square"></i>
                    <h3>No available chats</h3>
                    <p>All chats are currently assigned</p>
                </div>
            <?php else: ?>
                <div class="chat-list">
                    <?php foreach ($available_chats as $chat): ?>
                        <div class="chat-item">
                            <form method="POST" class="chat-actions-form" style="width: 100%;">
                                <div class="chat-header-row">
                                    <div class="chat-user">
                                        <i data-lucide="user" width="14" height="14"></i>
                                        <?php echo htmlspecialchars($chat['user_name']); ?>
                                    </div>
                                    <div class="chat-status status-open">
                                        <i data-lucide="clock" width="10" height="10"></i>
                                        Waiting
                                    </div>
                                </div>
                                <div class="chat-meta">
                                    <span>
                                        <i data-lucide="phone" width="12" height="12"></i>
                                        <?php echo htmlspecialchars($chat['mobile']); ?>
                                    </span>
                                    <span>
                                        <i data-lucide="message-square" width="12" height="12"></i>
                                        <?php echo $chat['message_count']; ?> messages
                                    </span>
                                    <span>
                                        <i data-lucide="clock" width="12" height="12"></i>
                                        <?php echo date('h:i A', strtotime($chat['last_message_at'])); ?>
                                    </span>
                                </div>
                                <div class="chat-actions">
                                    <input type="hidden" name="chat_id" value="<?php echo $chat['id']; ?>">
                                    <button type="submit" name="assign_chat" class="action-btn primary-btn">
                                        <i data-lucide="message-circle"></i>
                                        Take Chat
                                    </button>
                                </div>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- CLOSE MODAL -->
<div class="modal-overlay" id="closeModal">
    <div class="modal">
        <div class="modal-header">
            <h2><i data-lucide="x-circle"></i> Close Chat</h2>
            <button class="logout-btn" style="padding: 4px 8px;" onclick="closeCloseModal()">
                <i data-lucide="x"></i>
            </button>
        </div>
        <form method="POST" id="closeForm">
            <div class="modal-content">
                <p id="closeModalText">Are you sure you want to close this chat?</p>
                <input type="hidden" name="chat_id" id="closeChatId">
                <label style="display: block; margin-bottom: 8px; font-size: 14px; color: var(--text-muted);">
                    Closing reason (optional):
                </label>
                <textarea name="close_reason" id="close_reason" placeholder="Optional reason for closing..."></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" class="modal-btn secondary-btn" onclick="closeCloseModal()">Cancel</button>
                <button type="submit" name="close_chat" class="modal-btn warning-btn-modal">Close Chat</button>
            </div>
        </form>
    </div>
</div>

<!-- ABUSE MODAL -->
<div class="modal-overlay" id="abuseModal">
    <div class="modal">
        <div class="modal-header">
            <h2><i data-lucide="flag"></i> Report Abuse</h2>
            <button class="logout-btn" style="padding: 4px 8px;" onclick="closeAbuseModal()">
                <i data-lucide="x"></i>
            </button>
        </div>
        <form method="POST" id="abuseForm">
            <div class="modal-content">
                <p><strong style="color: var(--danger);">Warning:</strong> Reporting abuse will block the user from chat permanently.</p>
                <input type="hidden" name="chat_id" id="abuseChatId">
                <label style="display: block; margin-bottom: 8px; font-size: 14px; color: var(--text-muted);">
                    Abuse reason (required):
                </label>
                <textarea name="abuse_reason" id="abuse_reason" 
                          placeholder="Describe the abusive behavior..." 
                          required></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" class="modal-btn secondary-btn" onclick="closeAbuseModal()">Cancel</button>
                <button type="submit" name="report_abuse" class="modal-btn danger-btn-modal">Report & Block</button>
            </div>
        </form>
    </div>
</div>

<!-- LOADING -->
<div class="loading" id="loading">
    <i data-lucide="loader" width="24" height="24"></i>
    <p>Loading...</p>
</div>

<script>
lucide.createIcons();

// Tab switching
function showSection(sectionId) {
    // Update tabs
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    event.currentTarget.classList.add('active');
    
    // Show section
    document.querySelectorAll('.chat-section').forEach(section => {
        section.classList.remove('active');
    });
    document.getElementById(sectionId + '-section').classList.add('active');
}

// Modal functions
function showCloseModal(chatId, userName) {
    document.getElementById('closeModalText').textContent = `Close chat with ${userName}?`;
    document.getElementById('closeChatId').value = chatId;
    document.getElementById('closeModal').classList.add('active');
}

function closeCloseModal() {
    document.getElementById('closeModal').classList.remove('active');
}

function showAbuseModal(chatId, userName) {
    document.getElementById('abuseChatId').value = chatId;
    document.getElementById('abuseModal').classList.add('active');
}

function closeAbuseModal() {
    document.getElementById('abuseModal').classList.remove('active');
}

// Close modal when clicking outside
document.querySelectorAll('.modal-overlay').forEach(modal => {
    modal.addEventListener('click', (e) => {
        if (e.target === modal) {
            modal.classList.remove('active');
        }
    });
});

// Auto-refresh every 30 seconds
setInterval(() => {
    document.getElementById('loading').classList.add('active');
    setTimeout(() => {
        location.reload();
    }, 500);
}, 30000);
</script>
</body>
</html>