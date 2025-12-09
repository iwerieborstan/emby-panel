<?php
// /opt/emby_signup/user_manager.php
// 用户管理系统 - 带搜索功能完整版

session_start();
require_once 'config.php';
require_once 'emby_functions.php';

$config = include 'config.php';

// 检查管理员登录
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header('Location: index.php?admin=1');
    exit;
}

// ========== 搜索功能处理 ==========
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$search_type = isset($_GET['search_type']) ? $_GET['search_type'] : 'username';
$search_active = !empty($search_query) || ($search_type === 'recent' && empty($search_query));

// 获取所有用户
$all_users = get_all_users();

// 按用户名排序（默认）
usort($all_users, function($a, $b) {
    return strcmp($a['Name'], $b['Name']);
});

// 如果启用搜索，则进行过滤
if ($search_active) {
    $filtered_users = [];
    
    foreach ($all_users as $user) {
        $match = false;
        
        switch ($search_type) {
            case 'username':
                // 用户名搜索（模糊匹配）
                if (stripos($user['Name'], $search_query) !== false) {
                    $match = true;
                }
                break;
                
            case 'userid':
                // 用户ID搜索（精确匹配）
                if (stripos($user['Id'], $search_query) !== false) {
                    $match = true;
                }
                break;
                
            case 'status':
                // 状态搜索：active（完整权限）/limited（受限权限）
                $user_details = getUserDetails($user['Id'], $config);
                $policy = $user_details['policy'] ?? [];
                $access_mode = $policy['EnableAllFolders'] ?? true ? '所有媒体库' : '指定媒体库';
                
                if (strtolower($search_query) === 'active' && $access_mode === '所有媒体库') {
                    $match = true;
                } elseif (strtolower($search_query) === 'limited' && $access_mode === '指定媒体库') {
                    $match = true;
                } elseif (empty($search_query)) {
                    $match = true; // 搜索框为空时显示所有
                }
                break;
                
            case 'recent':
                // 最近活动用户（24小时内）
                $user_details = getUserDetails($user['Id'], $config);
                $last_activity = $user_details['last_activity_date'] ?? '从未活动';
                
                if ($last_activity !== '从未活动' && $last_activity !== '获取失败') {
                    $activity_time = strtotime($last_activity);
                    $time_diff = time() - $activity_time;
                    
                    // 24小时内的用户
                    if ($time_diff <= 86400) {
                        $match = true;
                    }
                }
                break;
        }
        
        if ($match) {
            $filtered_users[] = $user;
        }
    }
    
    $all_users = $filtered_users;
}

// ========== 分页处理 ==========
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 10; // 每页10个用户

$total_users = count($all_users);
$total_pages = ceil($total_users / $per_page);
$current_page = min($current_page, $total_pages);
$start_index = ($current_page - 1) * $per_page;
$current_users = array_slice($all_users, $start_index, $per_page);

// ========== 处理用户操作 ==========
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $user_id = $_POST['user_id'] ?? '';
    $username = $_POST['username'] ?? '';
    
    switch ($action) {
        case 'reset_password':
            $new_password = $_POST['new_password'] ?? '';
            if (empty($new_password)) {
                $message = '错误：请输入新密码';
            } else {
                $result = resetUserPassword($user_id, $new_password, $config);
                $message = $result['success'] ? "用户 {$username} 密码重置成功！" : "错误：" . $result['error'];
            }
            break;
            
        case 'show_libraries':
            $selected_libraries = $_POST['selected_libraries'] ?? [];
            if (empty($selected_libraries)) {
                $message = '错误：请选择要显示的媒体库';
            } else {
                $result = showLibrariesForUser($user_id, $selected_libraries, $config);
                $message = $result['success'] ? "已为用户 {$username} 设置媒体库显示权限" : "错误：" . $result['error'];
            }
            break;
            
        case 'hide_libraries':
            $selected_libraries = $_POST['selected_libraries'] ?? [];
            if (empty($selected_libraries)) {
                $message = '错误：请选择要隐藏的媒体库';
            } else {
                $result = hideLibrariesForUser($user_id, $selected_libraries, $config);
                $message = $result['success'] ? "已为用户 {$username} 设置媒体库隐藏权限" : "错误：" . $result['error'];
            }
            break;
            
        case 'restore_access':
            $result = restoreUserAccess($user_id, $config);
            $message = $result['success'] ? "已为用户 {$username} 恢复完整访问权限" : "错误：" . $result['error'];
            break;
    }
}

// 获取所有媒体库
list($library_map) = get_all_libraries();

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>用户管理系统</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(rgba(0, 0, 0, 0.7), rgba(0, 0, 0, 0.7)), 
                        url('<?php echo $config['site']['custom_image']; ?>') center/cover no-repeat fixed;
            min-height: 100vh;
            padding: 20px;
            color: #fff;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
            color: white;
        }

        .header h1 {
            font-size: 36px;
            margin-bottom: 10px;
        }

        .header p {
            opacity: 0.8;
            font-size: 18px;
        }

        /* 搜索栏样式 */
        .search-bar {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .search-form {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }

        .search-type-selector {
            flex: 0 0 180px;
        }

        .search-select {
            width: 100%;
            padding: 12px 15px;
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 10px;
            color: white;
            font-size: 14px;
            cursor: pointer;
            backdrop-filter: blur(10px);
        }

        .search-select option {
            background: #2d3748;
            color: white;
        }

        .search-field-container {
            flex: 1;
            min-width: 300px;
        }

        .search-input {
            width: 100%;
            padding: 12px 20px;
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 10px;
            color: white;
            font-size: 16px;
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }

        .search-input:focus {
            outline: none;
            border-color: #667eea;
            background: rgba(255, 255, 255, 0.2);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .search-input::placeholder {
            color: rgba(255, 255, 255, 0.6);
        }

        .search-hint {
            margin-top: 8px;
            color: rgba(255, 255, 255, 0.7);
            font-size: 12px;
            padding-left: 5px;
        }

        .search-actions {
            display: flex;
            gap: 10px;
        }

        .search-btn {
            padding: 12px 24px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .search-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }

        .search-icon {
            width: 18px;
            height: 18px;
        }

        .clear-search-btn {
            padding: 12px 20px;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 10px;
            font-size: 14px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .clear-search-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }

        .clear-icon {
            width: 16px;
            height: 16px;
        }

        .search-results-info {
            margin-top: 15px;
            padding: 12px 15px;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 8px;
            font-size: 14px;
            color: rgba(255, 255, 255, 0.9);
        }

        .search-results-info strong {
            color: #667eea;
        }

        .stats-bar {
            display: flex;
            justify-content: space-between;
            background: rgba(255, 255, 255, 0.1);
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 30px;
            backdrop-filter: blur(10px);
        }

        .stat-item {
            text-align: center;
            flex: 1;
        }

        .stat-number {
            font-size: 32px;
            font-weight: 700;
            color: #667eea;
        }

        .stat-label {
            font-size: 14px;
            color: #a0aec0;
            margin-top: 5px;
        }

        .message {
            background: rgba(45, 206, 137, 0.2);
            border: 1px solid rgba(45, 206, 137, 0.3);
            color: #2dce89;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 25px;
            backdrop-filter: blur(10px);
        }

        .message.error {
            background: rgba(245, 101, 101, 0.2);
            border-color: rgba(245, 101, 101, 0.3);
            color: #f56565;
        }

        .user-table-container {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            margin-bottom: 40px;
            backdrop-filter: blur(10px);
            color: #333;
        }

        .user-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .user-table th {
            background: #f7fafc;
            padding: 15px;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
            color: #4a5568;
            font-weight: 600;
        }

        .user-table td {
            padding: 15px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }

        .user-table tr:hover {
            background: #f8f9fa;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            margin-right: 10px;
        }

        .user-info {
            display: flex;
            align-items: center;
        }

        .username {
            font-weight: 600;
            color: #2d3748;
        }

        .user-id {
            font-size: 12px;
            color: #a0aec0;
            margin-top: 2px;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 8px 16px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }

        .btn-small {
            padding: 6px 12px;
            font-size: 12px;
        }

        .btn-reset {
            background: #4299e1;
            color: white;
        }

        .btn-reset:hover {
            background: #3182ce;
        }

        .btn-show {
            background: #48bb78;
            color: white;
        }

        .btn-show:hover {
            background: #38a169;
        }

        .btn-hide {
            background: #ed8936;
            color: white;
        }

        .btn-hide:hover {
            background: #dd6b20;
        }

        .btn-restore {
            background: #9f7aea;
            color: white;
        }

        .btn-restore:hover {
            background: #805ad5;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            padding: 40px;
            width: 90%;
            max-width: 500px;
            color: #333;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
        }

        .modal-header {
            margin-bottom: 25px;
        }

        .modal-header h3 {
            font-size: 24px;
            color: #2d3748;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #4a5568;
        }

        .form-group input, 
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 16px;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus, 
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
        }

        .library-checkboxes {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 10px;
            max-height: 300px;
            overflow-y: auto;
            padding: 10px;
            background: #f7fafc;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
        }

        .library-checkbox {
            display: flex;
            align-items: center;
            padding: 10px;
            background: white;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            cursor: pointer;
        }

        .library-checkbox:hover {
            border-color: #667eea;
        }

        .library-checkbox input {
            margin-right: 10px;
            width: 16px;
            height: 16px;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 30px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 24px;
            border-radius: 10px;
            border: none;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }

        .btn-secondary {
            background: #e2e8f0;
            color: #4a5568;
            padding: 12px 24px;
            border-radius: 10px;
            border: none;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-secondary:hover {
            background: #cbd5e0;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 30px;
            padding: 20px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            backdrop-filter: blur(10px);
        }

        .page-link {
            padding: 10px 16px;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .page-link:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .page-link.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .page-info {
            color: white;
            font-size: 14px;
        }

        .nav-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 30px;
        }

        .nav-btn {
            padding: 12px 24px;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            text-decoration: none;
            border-radius: 10px;
            transition: all 0.3s ease;
        }

        .nav-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }

        .nav-btn.primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-active {
            background: #c6f6d5;
            color: #22543d;
        }

        .status-inactive {
            background: #fed7d7;
            color: #742a2a;
        }

        .access-info {
            font-size: 12px;
            color: #718096;
        }

        @media (max-width: 768px) {
            .search-form {
                flex-direction: column;
                align-items: stretch;
            }
            
            .search-type-selector,
            .search-field-container {
                flex: none;
                width: 100%;
                min-width: unset;
            }
            
            .search-actions {
                width: 100%;
                justify-content: center;
            }
            
            .user-table {
                display: block;
                overflow-x: auto;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .stats-bar {
                flex-direction: column;
                gap: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Emby 用户管理系统</h1>
            <p>管理所有用户账户和权限 - 支持搜索功能</p>
        </div>

        <!-- 搜索栏 -->
        <div class="search-bar">
            <form method="get" action="user_manager.php" class="search-form">
                <div class="search-type-selector">
                    <select name="search_type" id="search_type" class="search-select">
                        <option value="username" <?php echo ($search_type === 'username') ? 'selected' : ''; ?>>按用户名</option>
                        <option value="userid" <?php echo ($search_type === 'userid') ? 'selected' : ''; ?>>按用户ID</option>
                        <option value="status" <?php echo ($search_type === 'status') ? 'selected' : ''; ?>>按权限状态</option>
                        <option value="recent" <?php echo ($search_type === 'recent') ? 'selected' : ''; ?>>最近活动用户</option>
                    </select>
                </div>
                
                <div class="search-field-container" id="search_input_container">
                    <input type="text" 
                           name="search" 
                           id="search_input" 
                           class="search-input" 
                           placeholder="输入关键词搜索用户..." 
                           value="<?php echo htmlspecialchars($search_query); ?>"
                           autocomplete="off">
                    
                    <?php if ($search_type === 'status'): ?>
                    <div class="search-hint">
                        <small>提示：输入 "active" 查找完整权限用户，输入 "limited" 查找受限用户</small>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($search_type === 'recent'): ?>
                    <div class="search-hint">
                        <small>提示：查找24小时内有活动的用户（无需输入关键词）</small>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="search-actions">
                    <button type="submit" class="search-btn">
                        <svg class="search-icon" viewBox="0 0 24 24" width="20" height="20">
                            <path fill="currentColor" d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/>
                        </svg>
                        搜索
                    </button>
                    
                    <?php if ($search_active): ?>
                    <a href="user_manager.php" class="clear-search-btn">
                        <svg class="clear-icon" viewBox="0 0 24 24" width="18" height="18">
                            <path fill="currentColor" d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12 19 6.41z"/>
                        </svg>
                        清除搜索
                    </a>
                    <?php endif; ?>
                </div>
            </form>
            
            <?php if ($search_active): ?>
            <div class="search-results-info">
                搜索模式：<strong>
                <?php 
                $search_type_names = [
                    'username' => '按用户名',
                    'userid' => '按用户ID',
                    'status' => '按权限状态',
                    'recent' => '最近活动用户'
                ];
                echo $search_type_names[$search_type] ?? '未知模式';
                ?>
                </strong>
                <?php if ($search_type !== 'recent' || !empty($search_query)): ?>
                | 搜索关键词：<strong><?php echo htmlspecialchars($search_query); ?></strong>
                <?php endif; ?>
                | 找到 <strong><?php echo $total_users; ?></strong> 个用户
            </div>
            <?php endif; ?>
        </div>

        <!-- 统计信息 -->
        <div class="stats-bar">
            <div class="stat-item">
                <div class="stat-number"><?php echo $total_users; ?></div>
                <div class="stat-label">总用户数</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo $current_page; ?> / <?php echo $total_pages; ?></div>
                <div class="stat-label">当前页/总页数</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo $per_page; ?></div>
                <div class="stat-label">每页显示</div>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="message <?php echo strpos($message, '错误') !== false ? 'error' : ''; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- 用户表格 -->
        <div class="user-table-container">
            <table class="user-table">
                <thead>
                    <tr>
                        <th width="200">用户信息</th>
                        <th width="150">最后登录时间</th>
                        <th width="150">最后活动时间</th>
                        <th width="150">访问权限</th>
                        <th width="300">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($current_users as $user): 
                        $user_details = getUserDetails($user['Id'], $config);
                        $last_login = $user_details['last_login_date'];
                        $last_activity = $user_details['last_activity_date'];
                        $policy = $user_details['policy'];
                        
                        // 格式化时间
                        if ($last_login !== '从未登录' && $last_login !== '获取失败') {
                            $last_login = date('Y-m-d H:i:s', strtotime($last_login));
                        }
                        if ($last_activity !== '从未活动' && $last_activity !== '获取失败') {
                            $last_activity = date('Y-m-d H:i:s', strtotime($last_activity));
                        }
                        
                        // 判断访问权限
                        $access_mode = $policy['EnableAllFolders'] ?? true ? '所有媒体库' : '指定媒体库';
                        $enabled_count = count($policy['EnabledFolders'] ?? []);
                    ?>
                    <tr>
                        <td>
                            <div class="user-info">
                                <div class="user-avatar">
                                    <?php echo strtoupper(substr($user['Name'], 0, 1)); ?>
                                </div>
                                <div>
                                    <div class="username"><?php echo htmlspecialchars($user['Name']); ?></div>
                                    <div class="user-id">ID: <?php echo substr($user['Id'], 0, 8); ?>...</div>
                                </div>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($last_login); ?></td>
                        <td><?php echo htmlspecialchars($last_activity); ?></td>
                        <td>
                            <span class="status-badge <?php echo $access_mode === '所有媒体库' ? 'status-active' : 'status-inactive'; ?>">
                                <?php echo $access_mode; ?>
                            </span>
                            <?php if ($access_mode === '指定媒体库'): ?>
                                <div class="access-info">(<?php echo $enabled_count; ?>个媒体库)</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <!-- 重置密码按钮 -->
                                <button class="btn btn-reset btn-small" 
                                        onclick="openResetPasswordModal('<?php echo $user['Id']; ?>', '<?php echo htmlspecialchars($user['Name']); ?>')">
                                    重置密码
                                </button>
                                
                                <!-- 显示媒体库按钮 -->
                                <button class="btn btn-show btn-small" 
                                        onclick="openShowLibrariesModal('<?php echo $user['Id']; ?>', '<?php echo htmlspecialchars($user['Name']); ?>')">
                                    显示媒体库
                                </button>
                                
                                <!-- 隐藏媒体库按钮 -->
                                <button class="btn btn-hide btn-small" 
                                        onclick="openHideLibrariesModal('<?php echo $user['Id']; ?>', '<?php echo htmlspecialchars($user['Name']); ?>')">
                                    隐藏媒体库
                                </button>
                                
                                <!-- 恢复权限按钮 -->
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="action" value="restore_access">
                                    <input type="hidden" name="user_id" value="<?php echo $user['Id']; ?>">
                                    <input type="hidden" name="username" value="<?php echo htmlspecialchars($user['Name']); ?>">
                                    <button type="submit" class="btn btn-restore btn-small" 
                                            onclick="return confirm('确认为用户 <?php echo htmlspecialchars($user['Name']); ?> 恢复完整访问权限？')">
                                        恢复权限
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($current_users)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 40px; color: #a0aec0;">
                            <?php if ($search_active): ?>
                                没有找到匹配的用户，请尝试其他搜索条件
                            <?php else: ?>
                                暂无用户数据
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- 分页导航 -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($current_page > 1): ?>
                <a href="?page=<?php echo $current_page - 1; ?><?php echo $search_active ? '&search=' . urlencode($search_query) . '&search_type=' . urlencode($search_type) : ''; ?>" 
                   class="page-link">上一页</a>
            <?php endif; ?>
            
            <span class="page-info">
                第 <?php echo $current_page; ?> 页 / 共 <?php echo $total_pages; ?> 页
                (共 <?php echo $total_users; ?> 个用户)
            </span>
            
            <?php if ($current_page < $total_pages): ?>
                <a href="?page=<?php echo $current_page + 1; ?><?php echo $search_active ? '&search=' . urlencode($search_query) . '&search_type=' . urlencode($search_type) : ''; ?>" 
                   class="page-link">下一页</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- 导航按钮 -->
        <div class="nav-buttons">
            <a href="index.php?admin=1&page=dashboard" class="nav-btn">返回管理面板</a>
            <a href="index.php" class="nav-btn">返回注册页面</a>
            <a href="media_manager.php" class="nav-btn">媒体库管理</a>
            <a href="index.php?action=logout" class="nav-btn">退出管理</a>
        </div>
    </div>

    <!-- 模态框部分保持不变 -->
    <div id="resetPasswordModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>重置用户密码</h3>
            </div>
            <form id="resetPasswordForm" method="post">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" id="reset_user_id" name="user_id">
                <input type="hidden" id="reset_username" name="username">
                
                <div class="form-group">
                    <label for="new_password">新密码</label>
                    <input type="password" id="new_password" name="new_password" 
                           placeholder="输入新密码（至少4位）" required minlength="4">
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">确认密码</label>
                    <input type="password" id="confirm_password" name="confirm_password" 
                           placeholder="再次输入新密码" required minlength="4">
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeModal('resetPasswordModal')">取消</button>
                    <button type="submit" class="btn-primary">确认重置</button>
                </div>
            </form>
        </div>
    </div>

    <div id="showLibrariesModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>设置显示媒体库</h3>
                <p style="color: #718096; margin-top: 5px;">用户将只能访问选中的媒体库</p>
            </div>
            <form id="showLibrariesForm" method="post">
                <input type="hidden" name="action" value="show_libraries">
                <input type="hidden" id="show_user_id" name="user_id">
                <input type="hidden" id="show_username" name="username">
                
                <div class="form-group">
                    <label>选择要显示的媒体库（可多选）</label>
                    <div class="library-checkboxes">
                        <?php foreach ($library_map as $name => $id): ?>
                        <label class="library-checkbox">
                            <input type="checkbox" name="selected_libraries[]" value="<?php echo htmlspecialchars($name); ?>">
                            <span><?php echo htmlspecialchars($name); ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeModal('showLibrariesModal')">取消</button>
                    <button type="submit" class="btn-primary">确认设置</button>
                </div>
            </form>
        </div>
    </div>

    <div id="hideLibrariesModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>设置隐藏媒体库</h3>
                <p style="color: #718096; margin-top: 5px;">用户将无法访问选中的媒体库</p>
            </div>
            <form id="hideLibrariesForm" method="post">
                <input type="hidden" name="action" value="hide_libraries">
                <input type="hidden" id="hide_user_id" name="user_id">
                <input type="hidden" id="hide_username" name="username">
                
                <div class="form-group">
                    <label>选择要隐藏的媒体库（可多选）</label>
                    <div class="library-checkboxes">
                        <?php foreach ($library_map as $name => $id): ?>
                        <label class="library-checkbox">
                            <input type="checkbox" name="selected_libraries[]" value="<?php echo htmlspecialchars($name); ?>">
                            <span><?php echo htmlspecialchars($name); ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeModal('hideLibrariesModal')">取消</button>
                    <button type="submit" class="btn-primary">确认设置</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // 模态框操作函数
        function openResetPasswordModal(userId, username) {
            document.getElementById('reset_user_id').value = userId;
            document.getElementById('reset_username').value = username;
            document.getElementById('resetPasswordModal').style.display = 'flex';
        }
        
        function openShowLibrariesModal(userId, username) {
            document.getElementById('show_user_id').value = userId;
            document.getElementById('show_username').value = username;
            document.getElementById('showLibrariesModal').style.display = 'flex';
        }
        
        function openHideLibrariesModal(userId, username) {
            document.getElementById('hide_user_id').value = userId;
            document.getElementById('hide_username').value = username;
            document.getElementById('hideLibrariesModal').style.display = 'flex';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        // 点击模态框外部关闭
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
        
        // 搜索类型切换时的提示更新
        document.getElementById('search_type').addEventListener('change', function() {
            const searchType = this.value;
            const searchInput = document.getElementById('search_input');
            const searchHint = document.querySelector('.search-hint');
            
            switch(searchType) {
                case 'username':
                    searchInput.placeholder = '输入用户名关键词（支持模糊匹配）';
                    break;
                case 'userid':
                    searchInput.placeholder = '输入用户ID（支持部分匹配）';
                    break;
                case 'status':
                    searchInput.placeholder = '输入 active 或 limited';
                    break;
                case 'recent':
                    searchInput.placeholder = '查找24小时内活动的用户（无需输入）';
                    break;
            }
        });
        
        // 表单验证
        document.getElementById('resetPasswordForm').onsubmit = function(e) {
            const password = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            
            if (password !== confirmPassword) {
                alert('两次输入的密码不一致！');
                e.preventDefault();
                return false;
            }
            
            if (password.length < 4) {
                alert('密码长度至少需要4位！');
                e.preventDefault();
                return false;
            }
            
            return true;
        };
        
        document.getElementById('showLibrariesForm').onsubmit = function(e) {
            const checkboxes = this.querySelectorAll('input[name="selected_libraries[]"]:checked');
            if (checkboxes.length === 0) {
                alert('请至少选择一个媒体库！');
                e.preventDefault();
                return false;
            }
            return true;
        };
        
        document.getElementById('hideLibrariesForm').onsubmit = function(e) {
            const checkboxes = this.querySelectorAll('input[name="selected_libraries[]"]:checked');
            if (checkboxes.length === 0) {
                alert('请至少选择一个媒体库！');
                e.preventDefault();
                return false;
            }
            return true;
        };
        
        // 快捷搜索：按回车键提交搜索
        document.getElementById('search_input').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.closest('form').submit();
            }
        });
    </script>
</body>
</html>
