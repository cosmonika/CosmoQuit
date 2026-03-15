<?php  
require_once 'includes/session.php';  
require_once 'includes/config.php';  
require_once 'includes/Database.php';  
require_once 'includes/auth.php';  

checkAuth();  

// -----------------------------------------------------------------------------  
// LOAD BLOGS FROM JSON  
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

// Sort blogs by date (newest first)  
if (!empty($blogs_data) && is_array($blogs_data)) {  
    usort($blogs_data, function($a, $b) {  
        return strtotime($b['date']) - strtotime($a['date']);  
    });  
}  
?>  

<!DOCTYPE html>  
<html lang="en">  
<head>  
    <meta charset="UTF-8">  
    <meta name="viewport" content="width=device-width, initial-scale=1.0">  
    <title>Health Blogs - CosmoQuit</title>  
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
            
            /* Status Colors - Keep original */
            --success: #10B981;
            --warning: #f59e0b;
            --danger: #ef4444;
            
            /* Glass Effects */
            --glass-bg: rgba(30, 27, 36, 0.8);
            --glass-border: rgba(139, 92, 246, 0.12);
            --glass-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
            --glass-shadow-heavy: 0 8px 30px rgba(0, 0, 0, 0.35);
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
        }  

        .container {  
            max-width: 1200px;  
            margin: 0 auto;  
            padding: 0 16px;  
        }  

        /* Header Styles - Updated with Glass Effect */
        header {  
            background: var(--glass-bg);
            backdrop-filter: blur(20px) saturate(160%);
            -webkit-backdrop-filter: blur(20px) saturate(160%);
            border-bottom: 1px solid var(--glass-border);
            box-shadow: var(--glass-shadow);
            position: sticky;  
            top: 0;  
            z-index: 100;  
            height: 70px;  
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
            gap: 12px;  
            flex-shrink: 0;  
            text-decoration: none;  
        }  

        .logo-png {  
            width: 40px;  
            height: 40px;  
            object-fit: contain;  
            display: inline-block;
            filter: drop-shadow(0 2px 4px rgba(139, 92, 246, 0.3));
        }  

        .logo-text {  
            font-size: 28px;  
            font-weight: 800;  
            color: var(--primary);  
            letter-spacing: -0.5px;  
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);  
            -webkit-background-clip: text;  
            -webkit-text-fill-color: transparent;  
            background-clip: text;  
        }  

        /* Main Content */  
        .main-content {  
            padding: 40px 0;  
            min-height: calc(100vh - 140px);  
        }  

        .page-header {  
            text-align: center;  
            margin-bottom: 40px;  
        }  

        .page-header h1 {  
            font-size: 36px;  
            font-weight: 800;  
            color: var(--primary);  
            margin-bottom: 16px;  
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            text-shadow: 0 2px 10px rgba(139, 92, 246, 0.2);
        }  

        .page-header p {  
            font-size: 18px;  
            color: var(--text-muted);  
            max-width: 600px;  
            margin: 0 auto;  
        }  

        /* Blogs Container - Updated with Glass Effect */
        .blogs-container {  
            background: var(--card-bg);
            backdrop-filter: blur(20px) saturate(160%);
            -webkit-backdrop-filter: blur(20px) saturate(160%);
            border-radius: 16px;  
            padding: 40px;  
            box-shadow: var(--glass-shadow-heavy);
            border: 1px solid var(--card-border);
        }  

        .blogs-grid {  
            display: grid;  
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));  
            gap: 32px;  
        }  

        /* Blog Card - Updated with Glass Effect */
        .blog-card {  
            background: var(--card-bg);
            backdrop-filter: blur(10px);
            border: 1px solid var(--card-border);  
            border-radius: 12px;  
            overflow: hidden;  
            transition: all 0.3s ease;  
            cursor: pointer;  
            height: 100%;  
            display: flex;  
            flex-direction: column;  
        }  

        .blog-card:hover {  
            transform: translateY(-8px);  
            box-shadow: 0 20px 40px rgba(139, 92, 246, 0.2);  
            border-color: var(--primary-light);  
        }  

        .blog-thumbnail {  
            width: 100%;  
            height: 200px;  
            object-fit: cover;  
        }  

        .blog-content {  
            padding: 24px;  
            flex-grow: 1;  
            display: flex;  
            flex-direction: column;  
        }  

        /* Blog Category - Updated with Purple Theme */
        .blog-category {  
            display: inline-block;  
            background-color: var(--primary-soft);  
            color: var(--primary-light);  
            padding: 6px 16px;  
            border-radius: 20px;  
            font-size: 14px;  
            font-weight: 600;  
            margin-bottom: 12px;  
            border: 1px solid var(--card-border);
        }  

        .blog-title {  
            font-size: 20px;  
            font-weight: 700;  
            color: var(--text-primary);  
            margin-bottom: 12px;  
            line-height: 1.3;  
        }  

        .blog-description {  
            font-size: 15px;  
            color: var(--text-muted);  
            margin-bottom: 20px;  
            line-height: 1.5;  
            flex-grow: 1;  
        }  

        .blog-meta {  
            display: flex;  
            justify-content: space-between;  
            align-items: center;  
            margin-top: auto;  
            padding-top: 16px;  
            border-top: 1px solid var(--card-border);  
        }  

        .blog-date, .blog-author {  
            display: flex;  
            align-items: center;  
            gap: 6px;  
            font-size: 14px;  
            color: var(--text-muted);  
        }  

        /* No Blogs Section - Updated */
        .no-blogs {  
            text-align: center;  
            padding: 60px;  
            background: var(--card-bg);
            border-radius: 12px;  
            border: 2px dashed var(--card-border);  
            backdrop-filter: blur(10px);
        }  

        .no-blogs i {  
            color: var(--warning);  
            margin-bottom: 20px;  
        }  

        .no-blogs h3 {  
            font-size: 24px;  
            color: var(--text-primary);  
            margin-bottom: 12px;  
        }  

        .no-blogs p {  
            color: var(--text-muted);  
            max-width: 500px;  
            margin: 0 auto;  
        }  

        /* Footer - Updated with Glass Effect */
        footer {  
            background: var(--glass-bg);
            backdrop-filter: blur(20px) saturate(160%);
            -webkit-backdrop-filter: blur(20px) saturate(160%);
            border-top: 1px solid var(--glass-border);
            box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.25);
            padding: 16px 0;  
            position: sticky;  
            bottom: 0;  
        }  

        .footer-nav {  
            display: flex;  
            justify-content: center;  
            align-items: center;  
        }  

        .icon-button {  
            background: var(--card-bg);
            border: 1px solid var(--card-border);  
            cursor: pointer;  
            color: var(--text-muted);  
            padding: 12px;  
            border-radius: 12px;  
            display: flex;  
            align-items: center;  
            justify-content: center;  
            transition: all 0.3s ease;  
            text-decoration: none;  
            width: 56px;  
            height: 56px;  
            backdrop-filter: blur(10px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }  

        .icon-button:hover {  
            background: var(--primary-soft);  
            color: var(--primary);  
            transform: translateY(-2px);  
            box-shadow: 0 8px 16px rgba(139, 92, 246, 0.15);
            border-color: var(--primary-light);
        }  

        .icon-button i {  
            width: 28px;  
            height: 28px;  
        }  

        /* Responsive */  
        @media (max-width: 768px) {  
            .blogs-grid {  
                grid-template-columns: 1fr;  
            }  

            .blogs-container {  
                padding: 24px;  
            }  

            .page-header h1 {  
                font-size: 28px;  
            }  

            .page-header p {  
                font-size: 16px;  
            }  

            .blog-thumbnail {  
                height: 180px;  
            }  

            .blog-title {  
                font-size: 18px;  
            }  
        }  

        @media (max-width: 480px) {  
            .blogs-grid {  
                gap: 24px;  
            }  

            .blog-content {  
                padding: 20px;  
            }  

            .blog-meta {  
                flex-direction: column;  
                gap: 12px;  
                align-items: flex-start;  
            }  
        }  
    </style>  
</head>  
<body>  
    <!-- Header -->  
    <header>  
        <div class="header-container">  
            <a href="home.php" class="logo-section">  
                <img src="assets/images/logo.png" alt="CosmoQuit Logo" class="logo-png">  
                <span class="logo-text">CosmoQuit</span>  
            </a>  
        </div>  
    </header>  

    <!-- Main Content -->  
    <main class="main-content">  
        <div class="container">  
            <!-- Page Header -->  
            <div class="page-header">  
                <h1>Health & Wellness Blogs</h1>  
                <p>Explore our collection of articles to help you on your smoke-free journey. Newest articles appear first.</p>  
            </div>  

            <!-- Blogs Container -->  
            <div class="blogs-container">  
                <?php if (!empty($blogs_data)): ?>  
                    <div class="blogs-grid">  
                        <?php foreach ($blogs_data as $blog): ?>  
                            <div class="blog-card" onclick="window.open('<?php echo htmlspecialchars($blog['article_link'] ?? '#'); ?>', '_blank')">  
                                <?php if (!empty($blog['thumbnail'])): ?>  
                                    <img src="<?php echo htmlspecialchars($blog['thumbnail']); ?>" alt="<?php echo htmlspecialchars($blog['title']); ?>" class="blog-thumbnail">  
                                <?php else: ?>  
                                    <div style="height: 200px; background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary) 100%); display: flex; align-items: center; justify-content: center;">  
                                        <i data-lucide="book-open" style="color: white; width: 64px; height: 64px;"></i>  
                                    </div>  
                                <?php endif; ?>  
                                <div class="blog-content">  
                                    <span class="blog-category">Health & Wellness</span>  
                                    <h3 class="blog-title"><?php echo htmlspecialchars($blog['title']); ?></h3>  
                                    <p class="blog-description"><?php echo htmlspecialchars($blog['description']); ?></p>  
                                    <div class="blog-meta">  
                                        <div class="blog-date">  
                                            <i data-lucide="calendar"></i>  
                                            <?php echo date('F j, Y', strtotime($blog['date'])); ?>  
                                        </div>  
                                        <div class="blog-author">  
                                            <i data-lucide="user"></i>  
                                            <?php echo htmlspecialchars($blog['author']); ?>  
                                        </div>  
                                    </div>  
                                </div>  
                            </div>  
                        <?php endforeach; ?>  
                    </div>  
                <?php else: ?>  
                    <div class="no-blogs">  
                        <i data-lucide="book-open" style="width: 80px; height: 80px;"></i>  
                        <h3>No Blogs Available</h3>  
                        <p>We're currently working on creating valuable content for you. Check back soon!</p>  
                    </div>  
                <?php endif; ?>  
            </div>  
        </div>  
    </main>  

    <!-- Footer -->  
    <footer>  
        <div class="container">  
            <nav class="footer-nav">  
                <a href="home.php" class="icon-button">  
                    <i data-lucide="arrow-left"></i>  
                </a>  
            </nav>  
        </div>  
    </footer>  

    <script>  
        lucide.createIcons();  
    </script>  
</body>  
</html>