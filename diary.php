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
// 2. HANDLE NEW DIARY ENTRY SUBMISSION    
// -----------------------------------------------------------------------------    
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_diary_entry'])) {    
    $entry_date = date('Y-m-d');    
    $smoked_today = ($_POST['smoked_today'] === 'yes') ? 1 : 0;    
    $cigarettes_count = $smoked_today ? (int)$_POST['cigarettes_count'] : 0;    
    $cravings_level = (int)$_POST['cravings_level'];    
    $entry_text = trim($_POST['entry_text'] ?? '');    
      
    // Get cravings label  
    $cravings_label = getCravingsLabel($cravings_level);  
      
    $stmt = $conn->prepare("    
        INSERT INTO diary_entries     
        (user_id, entry_date, smoked_today, cigarettes_count, cravings_level, cravings_label, entry_text)     
        VALUES (?, ?, ?, ?, ?, ?, ?)    
        ON DUPLICATE KEY UPDATE    
        smoked_today = VALUES(smoked_today),    
        cigarettes_count = VALUES(cigarettes_count),    
        cravings_level = VALUES(cravings_level),    
        cravings_label = VALUES(cravings_label),    
        entry_text = VALUES(entry_text),    
        updated_at = CURRENT_TIMESTAMP    
    ");    
    $stmt->bind_param("isiiiss", $user_id, $entry_date, $smoked_today, $cigarettes_count, $cravings_level, $cravings_label, $entry_text);    
    $stmt->execute();    
      
    header("Location: diary.php");    
    exit();    
}    

// -----------------------------------------------------------------------------    
// 3. GET CRAVINGS FUNCTIONS    
// -----------------------------------------------------------------------------    
function getCravingsLabel($level) {    
    if ($level >= 1 && $level <= 3) return "Small cravings";    
    if ($level >= 4 && $level <= 6) return "Mild cravings";    
    if ($level >= 7 && $level <= 9) return "Strong cravings";    
    if ($level == 10) return "Heavy cravings";    
    return "No cravings";    
}    

function getCravingsColor($level) {    
    $colors = [    
        1 => '#10B981', 2 => '#22C55E', 3 => '#84CC16',    
        4 => '#EAB308', 5 => '#F59E0B', 6 => '#F97316',    
        7 => '#FB923C', 8 => '#F87171', 9 => '#EF4444',    
        10 => '#DC2626'    
    ];    
    return $colors[$level] ?? '#10B981';    
}    

// -----------------------------------------------------------------------------    
// 4. LOAD DIARY ENTRIES    
// -----------------------------------------------------------------------------    
$entries_stmt = $conn->prepare("    
    SELECT *,    
           DATE_FORMAT(entry_date, '%b %d') as short_date,    
           DATE_FORMAT(entry_date, '%W') as weekday    
    FROM diary_entries     
    WHERE user_id = ?     
    ORDER BY entry_date DESC    
    LIMIT 30    
");    
$entries_stmt->bind_param("i", $user_id);    
$entries_stmt->execute();    
$diary_entries = $entries_stmt->get_result()->fetch_all(MYSQLI_ASSOC);    

// Check if today's entry exists  
$today = date('Y-m-d');    
$today_entry = null;    
foreach ($diary_entries as $entry) {    
    if ($entry['entry_date'] == $today) {    
        $today_entry = $entry;    
        break;    
    }    
}    
?>    
<!DOCTYPE html>    
<html lang="en">    
<head>    
    <meta charset="UTF-8">    
    <meta name="viewport" content="width=device-width, initial-scale=1.0">    
    <title>My Diary - CosmoQuit</title>    
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
          
        /* Cravings colors */  
        --craving-1: #10B981; --craving-2: #22C55E; --craving-3: #84CC16;  
        --craving-4: #EAB308; --craving-5: #F59E0B; --craving-6: #F97316;  
        --craving-7: #FB923C; --craving-8: #F87171; --craving-9: #EF4444;  
        --craving-10: #DC2626;  
          
        --glass-bg: rgba(30, 27, 36, 0.7);  
        --glass-border: rgba(139, 92, 246, 0.12);  
        --glass-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);  
        --glass-backdrop: blur(20px) saturate(180%);  
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
    
.main-content {
    padding: 24px 0;
    min-height: calc(100vh - 120px); /* 60px header + 60px footer */
}

    .container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 16px;
    }  

    /* Header - Updated to match status.php */  
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

    /* Hero Section */  
    .hero-section {  
        padding: 24px 0;  
        text-align: center;  
    }  

    .hero-icon {  
        width: 60px;  
        height: 60px;  
        margin: 0 auto 16px;  
        background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);  
        border-radius: 16px;  
        display: flex;  
        align-items: center;  
        justify-content: center;  
        box-shadow: 0 8px 24px rgba(139, 92, 246, 0.3);  
    }  

    .hero-icon i {  
        width: 28px;  
        height: 28px;  
        color: white;  
    }  

    .hero-section h1 {  
        font-size: 24px;  
        font-weight: 700;  
        margin-bottom: 8px;  
        color: var(--text-primary);  
    }  

    .hero-section p {  
        color: var(--text-muted);  
        font-size: 14px;  
        max-width: 400px;  
        margin: 0 auto;  
    }  

    /* Action Card */  
    .action-card {  
        background: var(--card-bg);  
        backdrop-filter: var(--glass-backdrop);  
        border-radius: 16px;  
        padding: 20px;  
        margin: 20px 0;  
        border: 1px solid var(--card-border);  
        box-shadow: var(--glass-shadow);  
        text-align: center;  
    }  

    .new-entry-btn {  
        background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);  
        color: white;  
        border: none;  
        padding: 14px 28px;  
        border-radius: 12px;  
        font-size: 15px;  
        font-weight: 600;  
        cursor: pointer;  
        transition: all 0.3s ease;  
        display: flex;  
        align-items: center;  
        gap: 10px;  
        margin: 0 auto;  
        box-shadow: 0 6px 20px rgba(139, 92, 246, 0.3);  
        border: 1px solid rgba(255, 255, 255, 0.1);  
    }  

    .new-entry-btn:hover:not(:disabled) {  
        transform: translateY(-2px);  
        box-shadow: 0 10px 28px rgba(139, 92, 246, 0.4);  
    }  

    .new-entry-btn:disabled {  
        opacity: 0.6;  
        cursor: not-allowed;  
        transform: none;  
    }  

    .new-entry-btn i {  
        width: 18px;  
        height: 18px;  
    }  

    /* Entries Section */  
    .entries-section {  
        margin-top: 24px;  
    }  

    .section-title {  
        font-size: 18px;  
        font-weight: 600;  
        margin-bottom: 16px;  
        color: var(--text-primary);  
        display: flex;  
        align-items: center;  
        gap: 10px;  
    }  

    .entries-list {  
        display: flex;  
        flex-direction: column;  
        gap: 12px;  
    }  

    .entry-card {  
        background: var(--card-bg);  
        backdrop-filter: var(--glass-backdrop);  
        border-radius: 14px;  
        padding: 16px;  
        border: 1px solid var(--card-border);  
        transition: all 0.3s ease;  
        cursor: pointer;  
        position: relative;  
    }  

    .entry-card:hover {  
        transform: translateY(-2px);  
        box-shadow: 0 8px 24px rgba(139, 92, 246, 0.15);  
        border-color: rgba(139, 92, 246, 0.25);  
    }  

    .entry-card.smoked {  
        border-left: 4px solid var(--danger);  
    }  

    .entry-card.not-smoked {  
        border-left: 4px solid var(--success);  
    }  

    .entry-header {  
        display: flex;  
        justify-content: space-between;  
        align-items: center;  
        margin-bottom: 12px;  
    }  

    .entry-date {  
        font-size: 15px;  
        font-weight: 600;  
        color: var(--text-primary);  
    }  

    .entry-status {  
        display: flex;  
        align-items: center;  
        gap: 6px;  
        padding: 4px 10px;  
        border-radius: 20px;  
        font-size: 11px;  
        font-weight: 600;  
        text-transform: uppercase;  
        letter-spacing: 0.5px;  
    }  

    .entry-status.smoked {  
        background: rgba(239, 68, 68, 0.15);  
        color: var(--danger);  
    }  

    .entry-status.not-smoked {  
        background: rgba(16, 185, 129, 0.15);  
        color: var(--success);  
    }  

    .entry-preview {  
        color: var(--text-muted);  
        font-size: 13px;  
        line-height: 1.5;  
        margin-bottom: 12px;  
        display: -webkit-box;  
        -webkit-line-clamp: 2;  
        -webkit-box-orient: vertical;  
        overflow: hidden;  
    }  

    .entry-footer {  
        display: flex;  
        justify-content: space-between;  
        align-items: center;  
    }  

    .cravings-badge {  
        display: flex;  
        align-items: center;  
        gap: 8px;  
        font-size: 12px;  
    }  

    .cravings-level {  
        width: 24px;  
        height: 24px;  
        border-radius: 8px;  
        display: flex;  
        align-items: center;  
        justify-content: center;  
        font-weight: 700;  
        font-size: 11px;  
        color: white;  
    }  

    .entry-actions {  
        display: flex;  
        gap: 8px;  
    }  

    .view-btn {  
        background: rgba(139, 92, 246, 0.1);  
        border: 1px solid rgba(139, 92, 246, 0.2);  
        color: var(--text-muted);  
        padding: 6px 12px;  
        border-radius: 8px;  
        font-size: 12px;  
        font-weight: 500;  
        cursor: pointer;  
        transition: all 0.2s ease;  
    }  

    .view-btn:hover {  
        background: rgba(139, 92, 246, 0.2);  
        color: var(--primary);  
    }  

    /* Modal */  
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
        animation: fadeIn 0.3s ease;  
    }  

    @keyframes fadeIn {  
        from { opacity: 0; }  
        to { opacity: 1; }  
    }  

    .modal {  
        background: var(--card-bg);  
        backdrop-filter: var(--glass-backdrop);  
        border-radius: 20px;  
        padding: 24px;  
        width: 100%;  
        max-width: 500px;  
        border: 1px solid var(--card-border);  
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4);  
        animation: slideUp 0.4s ease;  
        max-height: 90vh;  
        overflow-y: auto;  
    }  

    @keyframes slideUp {  
        from {  
            opacity: 0;  
            transform: translateY(30px);  
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
        margin-bottom: 20px;  
    }  

    .modal-header h2 {  
        font-size: 20px;  
        font-weight: 700;  
        color: var(--primary);  
        display: flex;  
        align-items: center;  
        gap: 10px;  
    }  

    .close-modal {  
        background: transparent;  
        border: 1px solid var(--card-border);  
        color: var(--text-muted);  
        padding: 8px;  
        border-radius: 10px;  
        cursor: pointer;  
        transition: all 0.3s ease;  
        width: 36px;  
        height: 36px;  
        display: flex;  
        align-items: center;  
        justify-content: center;  
    }  

    .close-modal:hover {  
        background: rgba(139, 92, 246, 0.1);  
        color: var(--primary);  
        border-color: var(--primary);  
    }  

    /* Smoking Choice */  
    .smoking-choice {  
        display: flex;  
        gap: 12px;  
        margin-bottom: 20px;  
    }  

    .choice-btn {  
        flex: 1;  
        padding: 16px;  
        border: 2px solid var(--card-border);  
        background: rgba(30, 27, 36, 0.6);  
        border-radius: 12px;  
        cursor: pointer;  
        transition: all 0.3s ease;  
        display: flex;  
        flex-direction: column;  
        align-items: center;  
        gap: 10px;  
    }  

    .choice-btn:hover {  
        background: rgba(139, 92, 246, 0.1);  
        border-color: rgba(139, 92, 246, 0.3);  
    }  

    .choice-btn.active {  
        background: rgba(139, 92, 246, 0.2);  
        border-color: var(--primary);  
        box-shadow: 0 4px 16px rgba(139, 92, 246, 0.2);  
    }  

    .choice-btn.smoke {  
        border-color: rgba(239, 68, 68, 0.3);  
    }  

    .choice-btn.smoke.active {  
        background: rgba(239, 68, 68, 0.15);  
        border-color: var(--danger);  
    }  

    .choice-btn.no-smoke {  
        border-color: rgba(16, 185, 129, 0.3);  
    }  

    .choice-btn.no-smoke.active {  
        background: rgba(16, 185, 129, 0.15);  
        border-color: var(--success);  
    }  

    .choice-btn i {  
        width: 24px;  
        height: 24px;  
    }  

    .choice-btn.smoke i {  
        color: var(--danger);  
    }  

    .choice-btn.no-smoke i {  
        color: var(--success);  
    }  

    .choice-btn span {  
        font-weight: 600;  
        font-size: 14px;  
        color: var(--text-primary);  
    }  

    /* Cigarettes Select */  
    .cigarettes-select {  
        display: none;  
        margin-bottom: 20px;  
    }  

    .cigarettes-select.active {  
        display: block;  
        animation: fadeIn 0.3s ease;  
    }  

    .cigarettes-options {  
        display: flex;  
        flex-wrap: wrap;  
        gap: 8px;  
        margin-top: 8px;  
    }  

    .cigarette-option {  
        padding: 10px 16px;  
        background: rgba(30, 27, 36, 0.6);  
        border: 1px solid var(--card-border);  
        border-radius: 10px;  
        cursor: pointer;  
        transition: all 0.2s ease;  
        font-weight: 500;  
        font-size: 14px;  
    }  

    .cigarette-option:hover {  
        background: rgba(139, 92, 246, 0.1);  
        border-color: rgba(139, 92, 246, 0.3);  
    }  

    .cigarette-option.active {  
        background: rgba(239, 68, 68, 0.2);  
        color: var(--danger);  
        border-color: var(--danger);  
    }  

    /* Cravings Slider */  
    .cravings-slider-container {  
        background: rgba(139, 92, 246, 0.05);  
        border: 1px solid rgba(139, 92, 246, 0.1);  
        border-radius: 14px;  
        padding: 20px;  
        margin: 20px 0;  
    }  

    .slider-header {  
        display: flex;  
        justify-content: space-between;  
        align-items: center;  
        margin-bottom: 16px;  
    }  

    .slider-header h3 {  
        font-size: 14px;  
        font-weight: 600;  
        color: var(--text-primary);  
    }  

    .cravings-value {  
        font-size: 20px;  
        font-weight: 700;  
        color: var(--primary);  
    }  

    .cravings-slider {  
        width: 100%;  
        height: 6px;  
        -webkit-appearance: none;  
        appearance: none;  
        background: linear-gradient(to right,   
            var(--craving-1) 0%, var(--craving-2) 10%, var(--craving-3) 20%,   
            var(--craving-4) 30%, var(--craving-5) 40%, var(--craving-6) 50%,   
            var(--craving-7) 60%, var(--craving-8) 70%, var(--craving-9) 80%,   
            var(--craving-10) 100%);  
        border-radius: 3px;  
        outline: none;  
        margin: 16px 0;  
    }  

    .cravings-slider::-webkit-slider-thumb {  
        -webkit-appearance: none;  
        appearance: none;  
        width: 24px;  
        height: 24px;  
        border-radius: 50%;  
        background: white;  
        border: 3px solid var(--primary);  
        cursor: pointer;  
        box-shadow: 0 4px 12px rgba(139, 92, 246, 0.4);  
        transition: all 0.2s ease;  
    }  

    .cravings-slider::-webkit-slider-thumb:hover {  
        transform: scale(1.1);  
        box-shadow: 0 6px 18px rgba(139, 92, 246, 0.5);  
    }  

    .cravings-level-info {  
        text-align: center;  
        margin-top: 12px;  
        padding: 10px;  
        border-radius: 10px;  
        font-weight: 500;  
        font-size: 13px;  
        transition: all 0.3s ease;  
    }  

    /* Cravings level colors */  
    .cravings-level-1 { background: rgba(16, 185, 129, 0.1); color: var(--craving-1); }  
    .cravings-level-2 { background: rgba(34, 197, 94, 0.1); color: var(--craving-2); }  
    .cravings-level-3 { background: rgba(132, 204, 22, 0.1); color: var(--craving-3); }  
    .cravings-level-4 { background: rgba(234, 179, 8, 0.1); color: var(--craving-4); }  
    .cravings-level-5 { background: rgba(245, 158, 11, 0.1); color: var(--craving-5); }  
    .cravings-level-6 { background: rgba(249, 115, 22, 0.1); color: var(--craving-6); }  
    .cravings-level-7 { background: rgba(251, 146, 60, 0.1); color: var(--craving-7); }  
    .cravings-level-8 { background: rgba(248, 113, 113, 0.1); color: var(--craving-8); }  
    .cravings-level-9 { background: rgba(239, 68, 68, 0.1); color: var(--craving-9); }  
    .cravings-level-10 { background: rgba(220, 38, 38, 0.1); color: var(--craving-10); }  

    /* Diary Text */  
    .diary-textarea {  
        width: 100%;  
        min-height: 120px;  
        padding: 16px;  
        border: 1px solid var(--card-border);  
        background: rgba(30, 27, 36, 0.6);  
        border-radius: 12px;  
        font-family: 'Inter', sans-serif;  
        color: var(--text-primary);  
        font-size: 14px;  
        line-height: 1.5;  
        resize: vertical;  
        transition: all 0.3s ease;  
    }  

    .diary-textarea:focus {  
        outline: none;  
        border-color: var(--primary);  
        box-shadow: 0 0 0 2px rgba(139, 92, 246, 0.1);  
    }  

    .char-count {  
        text-align: right;  
        font-size: 11px;  
        color: var(--text-muted);  
        margin-top: 6px;  
    }  

    /* Modal Actions */  
    .modal-actions {  
        display: flex;  
        justify-content: flex-end;  
        gap: 12px;  
        margin-top: 24px;  
    }  

    .modal-btn {  
        padding: 12px 24px;  
        border-radius: 10px;  
        font-weight: 600;  
        cursor: pointer;  
        border: none;  
        transition: all 0.3s ease;  
        font-size: 14px;  
        display: flex;  
        align-items: center;  
        gap: 8px;  
    }  

    .modal-btn.primary {  
        background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);  
        color: white;  
        box-shadow: 0 6px 20px rgba(139, 92, 246, 0.3);  
    }  

    .modal-btn.primary:hover {  
        transform: translateY(-2px);  
        box-shadow: 0 10px 25px rgba(139, 92, 246, 0.4);  
    }  

    .modal-btn.secondary {  
        background: rgba(139, 92, 246, 0.1);  
        color: var(--text-muted);  
        border: 1px solid rgba(139, 92, 246, 0.3);  
    }  

    .modal-btn.secondary:hover {  
        background: rgba(139, 92, 246, 0.2);  
        color: var(--primary);  
    }  

    /* Congrats Message */  
    .congrats-message {  
        background: linear-gradient(135deg, rgba(16, 185, 129, 0.9) 0%, rgba(5, 150, 105, 0.9) 100%);  
        border-radius: 12px;  
        padding: 16px;  
        text-align: center;  
        margin: 16px 0;  
        color: white;  
        border: 1px solid rgba(255, 255, 255, 0.2);  
        display: none;  
    }  

    .congrats-message.active {  
        display: block;  
        animation: fadeIn 0.5s ease;  
    }  

    .congrats-message h3 {  
        font-size: 16px;  
        margin-bottom: 8px;  
        display: flex;  
        align-items: center;  
        justify-content: center;  
        gap: 10px;  
    }  

    .congrats-message p {  
        font-size: 13px;  
        opacity: 0.9;  
    }  

    /* Entry Detail */  
    .entry-detail {  
        background: rgba(139, 92, 246, 0.05);  
        border: 1px solid rgba(139, 92, 246, 0.1);  
        border-radius: 14px;  
        padding: 20px;  
        margin: 16px 0;  
    }  

    .detail-item {  
        margin-bottom: 16px;  
    }  

    .detail-label {  
        font-size: 11px;  
        font-weight: 600;  
        color: var(--text-muted);  
        text-transform: uppercase;  
        letter-spacing: 0.5px;  
        margin-bottom: 6px;  
    }  

    .detail-value {  
        font-size: 14px;  
        color: var(--text-primary);  
        line-height: 1.5;  
    }  

    .detail-note {  
        white-space: pre-wrap;  
        line-height: 1.6;  
        padding: 12px;  
        background: rgba(30, 27, 36, 0.5);  
        border-radius: 10px;  
        border: 1px solid var(--card-border);  
    }  

    /* Today Entry Warning */  
    .today-warning {  
        text-align: center;  
        padding: 20px;  
        background: rgba(245, 158, 11, 0.1);  
        border: 1px solid rgba(245, 158, 11, 0.3);  
        border-radius: 12px;  
        margin-top: 12px;  
        animation: fadeIn 0.3s ease;  
    }  

    .today-warning h3 {  
        font-size: 16px;  
        color: var(--warning);  
        margin-bottom: 8px;  
        display: flex;  
        align-items: center;  
        justify-content: center;  
        gap: 10px;  
    }  

    .today-warning p {  
        font-size: 13px;  
        color: var(--text-muted);  
    }  

    /* Footer - Updated to match status.php */  
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

    /* Responsive */  
    @media (max-width: 768px) {  
        .container {  
            padding: 0 12px;  
              
        }  
          
        .modal {  
            padding: 20px;  
        }  
          
        .smoking-choice {  
            flex-direction: column;  
        }  
          
        .choice-btn {  
            flex-direction: row;  
            justify-content: center;  
        }  
          
        .modal-actions {  
            flex-direction: column-reverse;  
        }  
          
        .modal-btn {  
            width: 100%;  
            justify-content: center;  
        }  
          
        .back-button span {  
            display: none;  
        }  
          
        .back-button i {  
            margin-right: 0;  
        }  
    }  

    @media (max-width: 480px) {  
        .hero-section h1 {  
            font-size: 20px;  
        }  
          
        .new-entry-btn {  
            padding: 12px 20px;  
            font-size: 14px;  
        }  
          
        .entry-header {  
            flex-direction: column;  
            align-items: flex-start;  
            gap: 8px;  
        }  
          
        .entry-status {  
            align-self: flex-start;  
        }  
    }  
</style>

</head>    
<body>    
    <!-- Header - Updated to match status.php -->    
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

    <!-- Main Content -->    
    <main class="main-content">
        <div class="container">    
            <!-- Hero Section -->    
            <section class="hero-section">    
                <div class="hero-icon">    
                    <i data-lucide="book-marked"></i>    
                </div>    
                <h1>My Smoke-Free Diary</h1>    
                <p>Track your cravings, record your journey</p>    
            </section>    

            <!-- Action Card -->    
            <section class="action-card">    
                <button class="new-entry-btn" id="newEntryBtn" <?php echo $today_entry ? 'disabled' : ''; ?>>    
                    <i data-lucide="plus-circle"></i>    
                    <?php echo $today_entry ? 'Today\'s Entry Complete' : 'Create Today\'s Entry'; ?>    
                </button>    
                  
                <?php if ($today_entry): ?>    
                    <div class="today-warning">    
                        <h3>    
                            <i data-lucide="check-circle"></i>    
                            Entry Already Created    
                        </h3>    
                        <p>You can view today's entry below</p>    
                    </div>    
                <?php endif; ?>    
            </section>    

            <!-- Previous Entries -->    
            <section class="entries-section">    
                <h2 class="section-title">    
                    <i data-lucide="history"></i>    
                    Recent Entries    
                </h2>    
                  
                <?php if (empty($diary_entries)): ?>    
                    <div class="action-card">    
                        <div style="text-align: center; padding: 20px;">    
                            <i data-lucide="book-open" style="width: 40px; height: 40px; color: var(--primary); margin-bottom: 12px;"></i>    
                            <h3 style="font-size: 16px; color: var(--text-primary); margin-bottom: 8px;">No entries yet</h3>    
                            <p style="color: var(--text-muted); font-size: 13px;">Create your first diary entry to start tracking</p>    
                        </div>    
                    </div>    
                <?php else: ?>    
                    <div class="entries-list">    
                        <?php foreach ($diary_entries as $entry): ?>    
                            <?php   
                            $cravingColor = getCravingsColor($entry['cravings_level']);  
                            $isToday = ($entry['entry_date'] == $today);  
                            ?>  
                            <div class="entry-card <?php echo $entry['smoked_today'] ? 'smoked' : 'not-smoked'; ?>"   
                                 onclick="viewEntryDetail(<?php echo htmlspecialchars(json_encode($entry)); ?>)">    
                                <div class="entry-header">    
                                    <div class="entry-date">    
                                        <?php if ($isToday): ?>    
                                            <strong>Today</strong> • <?php echo $entry['weekday']; ?>    
                                        <?php else: ?>    
                                            <?php echo $entry['short_date']; ?> • <?php echo $entry['weekday']; ?>    
                                        <?php endif; ?>    
                                    </div>    
                                    <div class="entry-status <?php echo $entry['smoked_today'] ? 'smoked' : 'not-smoked'; ?>">    
                                        <i data-lucide="<?php echo $entry['smoked_today'] ? 'cigarette-off' : 'check-circle'; ?>"></i>    
                                        <?php echo $entry['smoked_today'] ? 'Smoked' : 'Clean'; ?>    
                                    </div>    
                                </div>    
                                  
                                <?php if (!empty($entry['entry_text'])): ?>    
                                <div class="entry-preview">    
                                    <?php echo htmlspecialchars(substr($entry['entry_text'], 0, 100)); ?>    
                                    <?php if (strlen($entry['entry_text']) > 100): ?>...<?php endif; ?>    
                                </div>    
                                <?php endif; ?>    
                                  
                                <div class="entry-footer">    
                                    <div class="cravings-badge">    
                                        <div class="cravings-level" style="background: <?php echo $cravingColor; ?>;">    
                                            <?php echo $entry['cravings_level']; ?>    
                                        </div>    
                                        <span style="color: <?php echo $cravingColor; ?>; font-weight: 500;">    
                                            <?php echo getCravingsLabel($entry['cravings_level']); ?>    
                                        </span>    
                                    </div>    
                                    <div class="entry-actions">    
                                        <button class="view-btn" onclick="event.stopPropagation(); viewEntryDetail(<?php echo htmlspecialchars(json_encode($entry)); ?>)">    
                                            <i data-lucide="eye" style="width: 12px; height: 12px;"></i>    
                                            View    
                                        </button>    
                                    </div>    
                                </div>    
                            </div>    
                        <?php endforeach; ?>    
                    </div>    
                <?php endif; ?>    
            </section>    
        </div>    
    </main>    

    <!-- New Entry Modal -->    
    <div class="modal-overlay" id="entryModal">    
        <div class="modal">    
            <div class="modal-header">    
                <h2>    
                    <i data-lucide="book-marked"></i>    
                    Today's Diary Entry    
                </h2>    
                <button class="close-modal" id="closeEntryModal">    
                    <i data-lucide="x"></i>    
                </button>    
            </div>    
              
            <div class="modal-content">    
                <form id="diaryForm" method="POST" action="">    
                    <input type="hidden" name="add_diary_entry" value="1">    
                      
                    <div style="text-align: center; margin-bottom: 20px; padding: 12px; background: rgba(139, 92, 246, 0.1); border-radius: 10px; font-weight: 500; color: var(--primary);">    
                        <?php echo date('F j, Y'); ?>    
                    </div>    
                      
                    <!-- Smoking Choice -->    
                    <div class="form-group">    
                        <label style="display: block; margin-bottom: 12px; font-weight: 600; color: var(--text-primary);">Did you smoke today?</label>    
                        <div class="smoking-choice">    
                            <div class="choice-btn smoke" data-choice="yes">    
                                <i data-lucide="cigarette-off"></i>    
                                <span>Yes</span>    
                            </div>    
                            <div class="choice-btn no-smoke active" data-choice="no">    
                                <i data-lucide="check-circle"></i>    
                                <span>No</span>    
                            </div>    
                        </div>    
                        <input type="hidden" name="smoked_today" id="smokedToday" value="no">    
                    </div>    
                      
                    <!-- Cigarettes Count -->    
                    <div class="cigarettes-select" id="cigarettesSelect">    
                        <label style="display: block; margin-bottom: 8px; font-weight: 600; color: var(--text-primary);">How many cigarettes?</label>    
                        <div class="cigarettes-options">    
                            <div class="cigarette-option active" data-count="1">1</div>    
                            <div class="cigarette-option" data-count="2">2</div>    
                            <div class="cigarette-option" data-count="3">3</div>    
                            <div class="cigarette-option" data-count="4">4</div>    
                            <div class="cigarette-option" data-count="5">5</div>    
                            <div class="cigarette-option" data-count="6">5+</div>    
                        </div>    
                        <input type="hidden" name="cigarettes_count" id="cigarettesCount" value="1">    
                    </div>    
                      
                    <!-- Congrats Message -->    
                    <div class="congrats-message" id="congratsMessage">    
                        <h3>    
                            <i data-lucide="party-popper"></i>    
                            Great Job!    
                        </h3>    
                        <p>You're staying strong on your smoke-free journey!</p>    
                    </div>    
                      
                    <!-- Cravings Slider -->    
                    <div class="cravings-slider-container">    
                        <div class="slider-header">    
                            <h3>Cravings Level</h3>    
                            <div class="cravings-value" id="cravingsValue">5</div>    
                        </div>    
                          
                        <input type="range" min="1" max="10" value="5" class="cravings-slider" id="cravingsSlider">    
                          
                        <div style="display: flex; justify-content: space-between; font-size: 11px; color: var(--text-muted); margin-top: 8px;">    
                            <span>Small</span>    
                            <span>Heavy</span>    
                        </div>    
                          
                        <div class="cravings-level-info" id="cravingsLevelInfo">    
                            Mild cravings    
                        </div>    
                        <input type="hidden" name="cravings_level" id="cravingsLevel" value="5">    
                    </div>    
                      
                    <!-- Diary Text -->    
                    <div class="form-group">    
                        <label for="entryText" style="display: block; margin-bottom: 8px; font-weight: 600; color: var(--text-primary);">    
                            Diary Notes (Optional)    
                        </label>    
                        <textarea name="entry_text" id="entryText" class="diary-textarea"   
                                  placeholder="How are you feeling? What helped you stay smoke-free? Any triggers?"></textarea>    
                        <div class="char-count">    
                            <span id="charCount">0</span> characters    
                        </div>    
                    </div>    
                </form>    
            </div>    
              
            <div class="modal-actions">    
                <button type="button" class="modal-btn secondary" id="cancelEntry">    
                    Cancel    
                </button>    
                <button type="submit" form="diaryForm" class="modal-btn primary">    
                    <i data-lucide="save"></i>    
                    Save Entry    
                </button>    
            </div>    
        </div>    
    </div>    

    <!-- Entry Detail Modal -->    
    <div class="modal-overlay" id="detailModal">    
        <div class="modal">    
            <div class="modal-header">    
                <h2>    
                    <i data-lucide="file-text"></i>    
                    Diary Entry    
                </h2>    
                <button class="close-modal" id="closeDetailModal">    
                    <i data-lucide="x"></i>    
                </button>    
            </div>    
              
            <div class="modal-content">    
                <div id="detailContent"></div>    
            </div>    
              
            <div class="modal-actions">    
                <button type="button" class="modal-btn secondary" id="closeDetailBtn">    
                    Close    
                </button>    
            </div>    
        </div>    
    </div>    

    <!-- Footer - Updated to match status.php -->    
<footer>  
        <div class="container">  
            <nav class="footer-nav">  
                <a href="home.php" class="icon-button">  
                    <i data-lucide="home"></i>  
                </a>  
                <a href="status.php" class="icon-button">  
                    <i data-lucide="bar-chart-3"></i>  
                </a>  
                <a href="diary.php" class="icon-button active">  
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
          
        // Helper functions  
        function getCravingsColor(level) {  
            const colors = [  
                '#10B981', '#22C55E', '#84CC16', '#EAB308', '#F59E0B',  
                '#F97316', '#FB923C', '#F87171', '#EF4444', '#DC2626'  
            ];  
            return colors[level - 1] || '#10B981';  
        }  
          
        function getCravingsLabel(level) {  
            if (level <= 3) return "Small cravings";  
            if (level <= 6) return "Mild cravings";  
            if (level <= 9) return "Strong cravings";  
            return "Heavy cravings";  
        }  
          
        // Modal elements  
        const entryModal = document.getElementById('entryModal');  
        const detailModal = document.getElementById('detailModal');  
        const newEntryBtn = document.getElementById('newEntryBtn');  
          
        // Today's entry warning  
        <?php if ($today_entry): ?>  
            newEntryBtn.addEventListener('click', function(e) {  
                e.preventDefault();  
                  
                // Show popup message  
                const warning = document.createElement('div');  
                warning.className = 'today-warning';  
                warning.innerHTML = `  
                    <h3>  
                        <i data-lucide="clock"></i>  
                        Today's Entry Already Created  
                    </h3>  
                    <p>You can only create one entry per day. Please wait until tomorrow.</p>  
                `;  
                  
                // Remove any existing warning  
                document.querySelectorAll('.today-warning').forEach(w => w.remove());  
                  
                // Insert after button  
                newEntryBtn.parentNode.insertBefore(warning, newEntryBtn.nextSibling);  
                  
                // Auto remove after 5 seconds  
                setTimeout(() => {  
                    if (warning.parentNode) {  
                        warning.remove();  
                    }  
                }, 5000);  
            });  
        <?php else: ?>  
            // Open entry modal  
            newEntryBtn.addEventListener('click', () => {  
                entryModal.classList.add('active');  
            });  
        <?php endif; ?>  
          
        // Close entry modal  
        document.getElementById('closeEntryModal')?.addEventListener('click', () => {  
            entryModal.classList.remove('active');  
        });  
          
        document.getElementById('cancelEntry')?.addEventListener('click', () => {  
            entryModal.classList.remove('active');  
        });  
          
        // Close detail modal  
        document.getElementById('closeDetailModal')?.addEventListener('click', () => {  
            detailModal.classList.remove('active');  
        });  
          
        document.getElementById('closeDetailBtn')?.addEventListener('click', () => {  
            detailModal.classList.remove('active');  
        });  
          
        // Close modals when clicking outside  
        document.querySelectorAll('.modal-overlay').forEach(modal => {  
            modal.addEventListener('click', (e) => {  
                if (e.target === modal) {  
                    modal.classList.remove('active');  
                }  
            });  
        });  
          
        // Smoking choice toggle  
        document.querySelectorAll('.choice-btn').forEach(btn => {  
            btn.addEventListener('click', function() {  
                document.querySelectorAll('.choice-btn').forEach(b => b.classList.remove('active'));  
                this.classList.add('active');  
                  
                const choice = this.dataset.choice;  
                const cigarettesSelect = document.getElementById('cigarettesSelect');  
                const congratsMessage = document.getElementById('congratsMessage');  
                const smokedTodayInput = document.getElementById('smokedToday');  
                  
                smokedTodayInput.value = choice;  
                  
                if (choice === 'yes') {  
                    cigarettesSelect.classList.add('active');  
                    congratsMessage.classList.remove('active');  
                } else {  
                    cigarettesSelect.classList.remove('active');  
                    congratsMessage.classList.add('active');  
                }  
            });  
        });  
          
        // Cigarettes count selection  
        document.querySelectorAll('.cigarette-option').forEach(option => {  
            option.addEventListener('click', function() {  
                document.querySelectorAll('.cigarette-option').forEach(opt => opt.classList.remove('active'));  
                this.classList.add('active');  
                document.getElementById('cigarettesCount').value = this.dataset.count;  
            });  
        });  
          
        // Cravings slider  
        const cravingsSlider = document.getElementById('cravingsSlider');  
        const cravingsValue = document.getElementById('cravingsValue');  
        const cravingsLevel = document.getElementById('cravingsLevel');  
        const cravingsLevelInfo = document.getElementById('cravingsLevelInfo');  
          
        if (cravingsSlider) {  
            cravingsSlider.addEventListener('input', function() {  
                const value = parseInt(this.value);  
                cravingsValue.textContent = value;  
                cravingsLevel.value = value;  
                  
                const color = getCravingsColor(value);  
                cravingsValue.style.color = color;  
                cravingsLevelInfo.textContent = getCravingsLabel(value);  
                cravingsLevelInfo.className = 'cravings-level-info cravings-level-' + value;  
            });  
              
            cravingsSlider.dispatchEvent(new Event('input'));  
        }  
          
        // Character count  
        const entryText = document.getElementById('entryText');  
        const charCount = document.getElementById('charCount');  
          
        if (entryText && charCount) {  
            entryText.addEventListener('input', function() {  
                charCount.textContent = this.value.length;  
            });  
            charCount.textContent = entryText.value.length;  
        }  
          
        // View entry detail  
        function viewEntryDetail(entry) {  
            const detailContent = document.getElementById('detailContent');  
            const cravingColor = getCravingsColor(entry.cravings_level);  
            const cravingsLabel = getCravingsLabel(entry.cravings_level);  
              
            let cigarettesText = '';  
            if (entry.smoked_today) {  
                cigarettesText = entry.cigarettes_count > 0   
                    ? `${entry.cigarettes_count} cigarette${entry.cigarettes_count > 1 ? 's' : ''}`  
                    : 'Smoked';  
            } else {  
                cigarettesText = 'No cigarettes smoked';  
            }  
              
            const entryDate = new Date(entry.entry_date);  
            const today = new Date();  
            const isToday = entryDate.toDateString() === today.toDateString();  
              
            detailContent.innerHTML = `  
                <div class="entry-detail">  
                    <div class="detail-item">  
                        <div class="detail-label">Date</div>  
                        <div class="detail-value">  
                            ${isToday ? 'Today' : entry.entry_date} • ${entry.weekday}  
                        </div>  
                    </div>  
                      
                    <div class="detail-item">  
                        <div class="detail-label">Status</div>  
                        <div class="detail-value">  
                            <div style="display: flex; align-items: center; gap: 10px;">  
                                <div style="display: flex; align-items: center; gap: 6px; padding: 6px 12px;   
                                     background: ${entry.smoked_today ? 'rgba(239, 68, 68, 0.1)' : 'rgba(16, 185, 129, 0.1)'};   
                                     color: ${entry.smoked_today ? 'var(--danger)' : 'var(--success)'};   
                                     border-radius: 8px; border: 1px solid ${entry.smoked_today ? 'rgba(239, 68, 68, 0.3)' : 'rgba(16, 185, 129, 0.3)'};">  
                                    <i data-lucide="${entry.smoked_today ? 'cigarette-off' : 'check-circle'}"   
                                       style="width: 14px; height: 14px;"></i>  
                                    ${entry.smoked_today ? 'Smoked today' : 'Stayed smoke-free'}  
                                </div>  
                            </div>  
                        </div>  
                    </div>  
                      
                    <div class="detail-item">  
                        <div class="detail-label">Cigarettes</div>  
                        <div class="detail-value">${cigarettesText}</div>  
                    </div>  
                      
                    <div class="detail-item">  
                        <div class="detail-label">Cravings Level</div>  
                        <div class="detail-value">  
                            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 8px;">  
                                <div style="width: 32px; height: 32px; border-radius: 8px; background: ${cravingColor};   
                                     color: white; display: flex; align-items: center; justify-content: center;   
                                     font-weight: 700; font-size: 14px;">  
                                    ${entry.cravings_level}  
                                </div>  
                                <span style="color: ${cravingColor}; font-weight: 500;">  
                                    ${cravingsLabel}  
                                </span>  
                            </div>  
                            <div style="display: flex; gap: 4px;">  
                                ${Array.from({length: 10}, (_, i) => `  
                                    <div style="flex: 1; height: 6px; border-radius: 3px;   
                                         background: ${i < entry.cravings_level ? cravingColor : 'rgba(196, 181, 253, 0.2)'};">  
                                    </div>  
                                `).join('')}  
                            </div>  
                        </div>  
                    </div>  
                      
                    <div class="detail-item">  
                        <div class="detail-label">Notes</div>  
                        <div class="detail-note">  
                            ${entry.entry_text ? entry.entry_text.replace(/\n/g, '<br>') : '<em>No notes for this entry</em>'}  
                        </div>  
                    </div>  
                      
                    <div class="detail-item">  
                        <div class="detail-label">Entry Time</div>  
                        <div class="detail-value" style="font-size: 12px; color: var(--text-muted);">  
                            Created: ${new Date(entry.created_at).toLocaleDateString()} at ${new Date(entry.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}  
                        </div>  
                    </div>  
                </div>  
            `;  
              
            lucide.createIcons();  
            detailModal.classList.add('active');  
        }  
          
        // Initialize congrats message  
        document.getElementById('congratsMessage').classList.add('active');  
    </script>

</body>    
</html>