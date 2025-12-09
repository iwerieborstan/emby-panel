<?php
// /opt/emby_signup/index.php
header("Content-Type: text/html; charset=utf-8");
session_start();

// 加载配置
$config = include 'config.php';

// 设置时区
if (isset($config['system']['timezone'])) {
    date_default_timezone_set($config['system']['timezone']);
}

// 导入函数文件
require_once 'emby_functions.php';
require_once 'invite_functions.php';

// ========== 错误处理函数 ==========
function checkEmbyConnection($config) {
    $emby = $config['emby'];
    $system = $config['system'];
    
    // 1. 检查服务器URL格式
    $url_parts = parse_url($emby['host']);
    if (!$url_parts || !isset($url_parts['host'])) {
        if ($system['debug_mode']) {
            error_log('[Emby检查] 服务器URL格式无效: ' . $emby['host']);
        }
        return false;
    }
    
    // 2. 检查API Token长度
    if (strlen($emby['api_key']) < 10) {
        if ($system['debug_mode']) {
            error_log('[Emby检查] API Token过短，可能无效');
        }
        return false;
    }
    
    // 3. 尝试简单的HTTP连接检查
    $test_url = rtrim($emby['host'], '/') . '/system/info/public';
    $context = stream_context_create([
        'http' => [
            'timeout' => 3,
            'header' => "X-Emby-Token: " . $emby['api_key'] . "\r\n"
        ]
    ]);
    
    $response = @file_get_contents($test_url, false, $context);
    if ($response === false) {
        if ($system['debug_mode']) {
            error_log('[Emby检查] 无法连接到Emby服务器: ' . $emby['host']);
        }
    }
    
    return true;
}

// ========== 处理管理员登录 ==========
$is_admin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

// 登出处理
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $_SESSION['admin_logged_in'] = false;
    session_destroy();
    header('Location: index.php');
    exit;
}

// 管理员登录验证
if (isset($_POST['admin_login'])) {
    $user_config = $config['user'];
    if ($_POST['admin_password'] === $user_config['admin_password']) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_login_time'] = time();
        $is_admin = true;
    } else {
        $message = '管理员密码错误！';
    }
}

// ========== 处理管理操作 ==========
$new_code = '';
$invite_link = '';
$batch_result = null;

if ($is_admin && isset($_GET['action'])) {
    if ($_GET['action'] === 'generate') {
        // 处理单个邀请码生成
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $note = $_POST['note'] ?? '';
            $new_code = createInviteCode($note, $config);
            $invite_link = generateRegisterLink($new_code, $config);
            $message = "新邀请码生成成功：<strong>{$new_code}</strong>";
            
            header('Location: ?admin=1&generated=' . urlencode($new_code));
            exit;
        } elseif (isset($_GET['generated'])) {
            $new_code = $_GET['generated'];
            $invite_link = generateRegisterLink($new_code, $config);
            $message = "新邀请码生成成功：<strong>{$new_code}</strong>";
        }
    } 
    // ========== 新增：处理批量生成邀请码 ==========
    elseif ($_GET['action'] === 'batch_generate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $batch_count = isset($_POST['batch_count']) ? intval($_POST['batch_count']) : 10;
        $batch_note = $_POST['batch_note'] ?? '';
        
        // 限制生成数量在合理范围
        $batch_count = max(1, min(50, $batch_count));
        
        $batch_results = [];
        $batch_links = [];
        
        for ($i = 0; $i < $batch_count; $i++) {
            $code = createInviteCode($batch_note, $config);
            $link = generateRegisterLink($code, $config);
            $batch_results[] = [
                'code' => $code,
                'link' => $link,
                'note' => $batch_note
            ];
            $batch_links[] = $link;
        }
        
        // 将批量结果存储到session，以便在页面上显示
        $_SESSION['batch_generation_result'] = [
            'count' => $batch_count,
            'note' => $batch_note,
            'results' => $batch_results,
            'all_links' => $batch_links,
            'generated_at' => date('Y-m-d H:i:s')
        ];
        
        // 重定向到同一页面，避免重复提交
        header('Location: ?admin=1&batch_generated=1');
        exit;
    }
    // ========== 批量生成结束 ==========
    elseif ($_GET['action'] === 'delete' && isset($_GET['code'])) {
        if (deleteInviteCode($_GET['code'], $config)) {
            $message = "邀请码删除成功";
        } else {
            $message = "邀请码不存在";
        }
    }
}

// 如果有批量生成的结果，从session中读取
if (isset($_SESSION['batch_generation_result']) && isset($_GET['batch_generated'])) {
    $batch_result = $_SESSION['batch_generation_result'];
}

// ========== 处理用户注册 ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    $invite_code = $_POST['invite_code'];
    $username = htmlspecialchars($_POST['username']);
    $passwd = $_POST['passwd'];
    $confirm_passwd = $_POST['confirm_passwd'];
    $user_config = $config['user'];
    $security_config = $config['security'];
    
    // 1. 验证邀请码
    if (!validateInviteCode($invite_code, $config)) {
        $message = '邀请码无效或已被使用！';
    }
    // 2. 验证用户名
    elseif (!preg_match($user_config['default_username_pattern'], $username)) {
        $message = '用户名只允许包含数字和字母且至少需要4位！';
    }
    // 3. 验证密码
    elseif ($passwd !== $confirm_passwd) {
        $message = '两次输入的密码不一致！';
    }
    elseif (strlen($passwd) < $user_config['default_password_min_length']) {
        $message = "密码至少需要{$user_config['default_password_min_length']}位！";
    } else {
        // 所有验证通过后，检查Emby连接
        if (!checkEmbyConnection($config)) {
            $message = '系统维护中，请稍后重试';
        } else {
            // 标记邀请码为已使用
            markInviteCodeUsed($invite_code, $config);
            
            // 调用Emby API创建用户
            $result = createEmbyUser($username, $passwd, $config);
            
            if ($result['success']) {
                $site_config = $config['site'];
                $message = '注册完成，<a href="' . $site_config['emby_login_url'] . 
                          '" style="color: #065f46; text-decoration: underline; font-weight: bold;">点击此处登录Emby</a>！';
                if (isset($result['warning'])) {
                    $message .= '<br><small style="color: #f59e0b;">' . $result['warning'] . '</small>';
                }
            } else {
                $message = $result['error'];
                // API调用失败，恢复邀请码状态
                restoreInviteCode($invite_code, $config);
            }
        }
    }
}

// 加载邀请码列表
$invite_codes = loadInviteCodes($config);

// 如果是管理员模式且未登录，显示登录页面
if (isset($_GET['admin']) && $_GET['admin'] == '1' && !$is_admin) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>管理员登录</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <style>
            body { 
                font-family: 'Inter', Arial; 
                display: flex; 
                justify-content: center; 
                align-items: center; 
                height: 100vh; 
                background: <?php echo $config['site']['theme']['primary_gradient']; ?>;
                margin: 0;
            }
            .login-container {
                background: white;
                padding: 40px;
                border-radius: 20px;
                box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
                text-align: center;
                width: 100%;
                max-width: 400px;
            }
            h3 { 
                margin-bottom: 30px; 
                color: #374151;
            }
            input { 
                margin: 10px 0; 
                padding: 16px; 
                width: 100%; 
                border: 2px solid #e5e7eb;
                border-radius: 12px;
                font-size: 16px;
                box-sizing: border-box;
            }
            button { 
                padding: 16px; 
                background: <?php echo $config['site']['theme']['primary_gradient']; ?>; 
                color: white; 
                border: none; 
                border-radius: 12px; 
                width: 100%;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                margin-top: 10px;
            }
            button:hover {
                transform: translateY(-2px);
                box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
            }
            .back-link {
                margin-top: 20px;
            }
            .back-link a {
                color: #667eea;
                text-decoration: none;
            }
            .error {
                color: <?php echo $config['site']['theme']['error_color']; ?>;
                margin-bottom: 15px;
            }
        </style>
    </head>
    <body>
        <div class="login-container">
            <h3>管理员登录</h3>
            <?php if (isset($message)): ?>
                <div class="error"><?php echo $message; ?></div>
            <?php endif; ?>
            <form method="post">
                <input type="password" name="admin_password" placeholder="请输入管理员密码" required>
                <br>
                <button type="submit" name="admin_login">登录</button>
            </form>
            <div class="back-link">
                <a href="index.php">← 返回注册页面</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ========== 管理员模式已登录 ==========
if (isset($_GET['admin']) && $_GET['admin'] == '1' && $is_admin) {
    // 判断是显示邀请码管理还是管理面板
    if (isset($_GET['page']) && $_GET['page'] === 'dashboard') {
        // 显示综合管理面板
        include 'templates/admin_panel.php';
    } else {
        // 显示邀请码管理界面（默认）
        ?>
        <!DOCTYPE html>
        <html lang="zh-CN">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>邀请码管理</title>
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
                    background: linear-gradient(rgba(0, 0, 0, 0.2), rgba(0, 0, 0, 0.2)), 
                    url('<?php echo $config['site']['custom_image']; ?>') center/cover no-repeat fixed;
                    min-height: 100vh;
                    padding: 20px;
                    color: #333;
                }

                .container {
                    max-width: 800px;
                    margin: 0 auto;
                }

                .header {
                    text-align: center;
                    margin-bottom: 30px;
                    color: white;
                }

                .header h1 {
                    font-size: 32px;
                    margin-bottom: 10px;
                }

                .header p {
                    opacity: 0.8;
                }

                .admin-panel {
                    background: white;
                    border-radius: 20px;
                    padding: 40px;
                    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
                    margin-bottom: 20px;
                }

                .admin-section {
                    margin-bottom: 40px;
                }

                .admin-section h3 {
                    margin-bottom: 20px;
                    color: #374151;
                    padding-bottom: 10px;
                    border-bottom: 2px solid #e5e7eb;
                }

                .form-group {
                    margin-bottom: 20px;
                }

                .form-group label {
                    display: block;
                    margin-bottom: 8px;
                    font-weight: 500;
                    color: #374151;
                }

                .form-group input, .form-group textarea {
                    width: 100%;
                    padding: 12px;
                    border: 2px solid #e5e7eb;
                    border-radius: 8px;
                    font-size: 16px;
                    background: #f9fafb;
                    font-family: inherit;
                }

                .btn {
                    background: <?php echo $config['site']['theme']['primary_gradient']; ?>;
                    color: white;
                    border: none;
                    padding: 12px 24px;
                    border-radius: 8px;
                    cursor: pointer;
                    font-weight: 600;
                    text-decoration: none;
                    display: inline-block;
                }

                .btn:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
                }

                .invite-codes {
                    max-height: 400px;
                    overflow-y: auto;
                }

                .invite-code-item {
                    display: flex;
                    align-items: center;
                    padding: 15px;
                    border-bottom: 1px solid #e5e7eb;
                    background: #f9fafb;
                    margin-bottom: 10px;
                    border-radius: 8px;
                }

                .invite-code-item:last-child {
                    border-bottom: none;
                }

                .code {
                    font-weight: bold;
                    color: #667eea;
                    font-size: 18px;
                    min-width: 100px;
                }

                .status {
                    font-size: 12px;
                    padding: 4px 12px;
                    border-radius: 12px;
                    margin-left: 15px;
                }

                .status.used {
                    background: #fee2e2;
                    color: <?php echo $config['site']['theme']['error_color']; ?>;
                }

                .status.unused {
                    background: #d1fae5;
                    color: <?php echo $config['site']['theme']['success_color']; ?>;
                }

                .copy-btn {
                    background: #3b82f6;
                    color: white;
                    border: none;
                    padding: 6px 12px;
                    border-radius: 6px;
                    cursor: pointer;
                    margin-left: 10px;
                    text-decoration: none;
                    font-size: 12px;
                }

                .copy-btn:hover {
                    background: #2563eb;
                }

                .delete-btn {
                    background: <?php echo $config['site']['theme']['error_color']; ?>;
                    color: white;
                    border: none;
                    padding: 6px 12px;
                    border-radius: 6px;
                    cursor: pointer;
                    margin-left: 10px;
                    text-decoration: none;
                    font-size: 12px;
                }

                .delete-btn:hover {
                    background: #dc2626;
                }

                .code-info {
                    margin-left: 20px;
                    flex-grow: 1;
                }

                .code-info small {
                    color: #6b7280;
                    display: block;
                }

                .back-link {
                    text-align: center;
                    margin-top: 20px;
                }

                .back-link a {
                    color: white;
                    text-decoration: none;
                    background: rgba(255,255,255,0.2);
                    padding: 10px 20px;
                    border-radius: 20px;
                    transition: all 0.3s ease;
                }

                .back-link a:hover {
                    background: rgba(255,255,255,0.3);
                }

                .message {
                    background: #d1fae5;
                    border: 1px solid #a7f3d0;
                    color: <?php echo $config['site']['theme']['success_color']; ?>;
                    padding: 15px;
                    border-radius: 8px;
                    margin-bottom: 20px;
                }

                .invite-link-section {
                    margin-top: 20px;
                    padding: 20px;
                    background: #f8f9fa;
                    border-radius: 12px;
                    border: 1px solid #e5e7eb;
                }

                .link-container {
                    display: flex;
                    gap: 10px;
                    margin: 15px 0;
                }

                .link-container input {
                    flex: 1;
                    padding: 12px;
                    border: 2px solid #e5e7eb;
                    border-radius: 8px;
                    background: white;
                    font-size: 14px;
                }

                .dashboard-link {
                    text-align: center;
                    margin-top: 30px;
                    padding: 20px;
                    background: #f8f9fa;
                    border-radius: 12px;
                }

                .dashboard-link a {
                    display: inline-block;
                    padding: 12px 24px;
                    background: <?php echo $config['site']['theme']['primary_gradient']; ?>;
                    color: white;
                    text-decoration: none;
                    border-radius: 8px;
                    font-weight: 600;
                    transition: all 0.3s ease;
                }

                .dashboard-link a:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
                }
                
                /* 批量生成相关样式 */
                .batch-section {
                    margin-top: 30px;
                    border-top: 2px solid #e5e7eb;
                    padding-top: 25px;
                }
                
                .batch-result {
                    margin-top: 30px;
                    background: #fef3c7;
                    border: 2px solid #f59e0b;
                    border-radius: 12px;
                    padding: 25px;
                }
                
                .batch-textarea {
                    width: 100%;
                    font-family: monospace;
                    font-size: 14px;
                    padding: 12px;
                    border: 2px solid #d97706;
                    border-radius: 8px;
                    background: #fffbeb;
                    resize: vertical;
                }
                
                .batch-actions {
                    margin-top: 10px;
                    display: flex;
                    gap: 10px;
                    flex-wrap: wrap;
                }
                
                .batch-btn {
                    padding: 10px 20px;
                    border-radius: 8px;
                    border: none;
                    cursor: pointer;
                    font-weight: 600;
                    color: white;
                }
                
                .batch-copy {
                    background: #10b981;
                }
                
                .batch-download {
                    background: #3b82f6;
                }
                
                .batch-clear {
                    background: #6b7280;
                }
                
                .batch-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 20px;
                }
                
                .batch-table th {
                    background: #fde68a;
                    padding: 10px;
                    text-align: left;
                    border: 1px solid #f59e0b;
                }
                
                .batch-table td {
                    padding: 10px;
                    border: 1px solid #fde68a;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>邀请码管理系统</h1>
                    <p>生成和管理Emby注册邀请码</p>
                </div>

                <?php if (isset($message)): ?>
                    <div class="message">
                        <?php echo $message; ?>
                    </div>
                <?php endif; ?>

                <div class="admin-panel">
                    <!-- 生成单个邀请码 -->
                    <div class="admin-section">
                        <h3>生成新邀请码</h3>
                        <form method="post" action="?admin=1&action=generate">
                            <div class="form-group">
                                <label for="note">备注（可选）</label>
                                <input type="text" id="note" name="note" placeholder="为这个邀请码添加备注">
                            </div>
                            <button type="submit" class="btn">生成邀请码</button>
                        </form>
                        
                        <?php if (!empty($new_code)): ?>
                        <div class="invite-link-section">
                            <h4>邀请链接</h4>
                            <p>复制以下链接发送给用户，打开后邀请码会自动填入：</p>
                            <div class="link-container">
                                <input type="text" id="inviteLink" value="<?php echo $invite_link; ?>" readonly>
                                <button onclick="copyInviteLink()" class="btn" style="width: auto; padding: 12px 20px;">复制链接</button>
                            </div>
                            <small style="color: #6b7280;">用户打开链接后，邀请码字段会自动填充</small>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- 批量生成邀请码 -->
                    <div class="admin-section batch-section">
                        <h3>📦 批量生成邀请码</h3>
                        <form method="post" action="?admin=1&action=batch_generate" id="batchGenerateForm">
                            <div class="form-group">
                                <label for="batch_count">生成数量</label>
                                <input type="number" id="batch_count" name="batch_count" min="1" max="50" value="10" style="width: 100px;">
                                <small style="color: #6b7280; margin-left: 10px;">（1-50个）</small>
                            </div>
                            <div class="form-group">
                                <label for="batch_note">统一备注（可选）</label>
                                <input type="text" id="batch_note" name="batch_note" placeholder="为这批邀请码添加统一备注" style="width: 300px;">
                            </div>
                            <button type="submit" class="btn" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">🚀 批量生成</button>
                            <small style="color: #6b7280; display: block; margin-top: 8px;">将一次性生成指定数量的邀请码并显示所有链接</small>
                        </form>
                    </div>

                    <!-- 批量生成结果展示区 -->
                    <?php if ($batch_result): 
                        $all_links_text = implode("\n", $batch_result['all_links']);
                    ?>
                    <div class="admin-section batch-result">
                        <h3 style="color: #92400e; margin-bottom: 20px;">✅ 批量生成成功</h3>
                        
                        <div style="margin-bottom: 20px; padding: 15px; background: white; border-radius: 8px;">
                            <p><strong>生成统计：</strong> 成功生成 <span style="color: #d97706; font-weight: bold;"><?php echo $batch_result['count']; ?></span> 个邀请码 
                            <?php if (!empty($batch_result['note'])): ?>
                                | 备注：<em>"<?php echo htmlspecialchars($batch_result['note']); ?>"</em>
                            <?php endif; ?>
                            | 时间：<?php echo $batch_result['generated_at']; ?></p>
                        </div>
                        
                        <div class="form-group">
                            <label><strong>所有邀请链接（一键复制区域）</strong></label>
                            <textarea id="batchLinksTextarea" rows="6" class="batch-textarea" readonly><?php echo htmlspecialchars($all_links_text); ?></textarea>
                            <div class="batch-actions">
                                <button onclick="copyBatchLinks()" class="batch-btn batch-copy">📋 复制所有链接</button>
                                <button onclick="downloadBatchLinks()" class="batch-btn batch-download">⬇️ 下载为TXT文件</button>
                                <button onclick="clearBatchResult()" class="batch-btn batch-clear">🗑️ 清除显示</button>
                            </div>
                        </div>
                        
                        <div style="margin-top: 25px;">
                            <h4 style="color: #92400e; margin-bottom: 15px;">邀请码明细列表</h4>
                            <div style="max-height: 300px; overflow-y: auto;">
                                <table class="batch-table">
                                    <thead>
                                        <tr>
                                            <th>序号</th>
                                            <th>邀请码</th>
                                            <th>注册链接</th>
                                            <th>操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($batch_result['results'] as $index => $item): ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td style="font-family: monospace; font-weight: bold; color: #065f46;"><?php echo $item['code']; ?></td>
                                            <td style="font-family: monospace; font-size: 13px;">
                                                <input type="text" value="<?php echo htmlspecialchars($item['link']); ?>" readonly style="width: 100%; border: 1px solid #d1fae5; padding: 6px; border-radius: 4px; background: #f0fdf4;">
                                            </td>
                                            <td>
                                                <button onclick="copySingleLink('<?php echo $item['code']; ?>', '<?php echo htmlspecialchars($item['link']); ?>')" style="padding: 4px 8px; background: #a7f3d0; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">复制</button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- 邀请码列表 -->
                    <div class="admin-section">
                        <h3>邀请码列表</h3>
                        <div class="invite-codes">
                            <?php if (empty($invite_codes)): ?>
                                <p style="text-align: center; color: #6b7280; padding: 20px;">暂无邀请码</p>
                            <?php else: ?>
                                <?php foreach ($invite_codes as $code => $info): ?>
                                    <div class="invite-code-item">
                                        <span class="code"><?php echo $code; ?></span>
                                        <span class="status <?php echo $info['used'] ? 'used' : 'unused'; ?>">
                                            <?php echo $info['used'] ? '已使用' : '未使用'; ?>
                                        </span>
                                        <div class="code-info">
                                            <small>创建时间: <?php echo $info['created_at']; ?></small>
                                            <?php if ($info['used'] && $info['used_at']): ?>
                                                <small>使用时间: <?php echo $info['used_at']; ?></small>
                                            <?php endif; ?>
                                            <?php if ($info['note']): ?>
                                                <small>备注: <?php echo htmlspecialchars($info['note']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                        <div style="display: flex; gap: 5px;">
                                            <?php if (!$info['used']): ?>
                                                <button class="copy-btn" onclick="copyInviteCodeLink('<?php echo $code; ?>')">复制链接</button>
                                                <a href="?admin=1&action=delete&code=<?php echo $code; ?>" class="delete-btn" onclick="return confirm('确定删除邀请码 <?php echo $code; ?>？')">删除</a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- 其他管理功能链接 -->
                    <div class="dashboard-link">
                        <h4>更多管理功能</h4>
                        <div style="display: flex; gap: 15px; margin-top: 20px; justify-content: center;">
                            <a href="?admin=1&page=dashboard" class="btn" style="width: auto; background: #10b981;">
                                🏠 返回管理面板
                            </a>
                            <a href="media_manager.php" class="btn" style="width: auto; background: #3b82f6;">
                                📁 媒体库权限管理
                            </a>
                            <a href="index.php" class="btn" style="width: auto; background: #8b5cf6;">
                                👥 用户注册页面
                            </a>
                        </div>
                    </div>
                </div>

                <!-- 底部导航 -->
                <div class="back-link">
                    <a href="index.php?action=logout">退出管理</a>
                </div>
            </div>

            <script>
            // 单个链接复制函数
            function copyInviteLink() {
                var copyText = document.getElementById("inviteLink");
                copyText.select();
                copyText.setSelectionRange(0, 99999);
                document.execCommand("copy");
                alert("邀请链接已复制到剪贴板！");
            }

            function copyInviteCodeLink(code) {
                var baseUrl = window.location.href.split('?')[0];
                var inviteLink = baseUrl + "?invite_code=" + code;
                
                var tempInput = document.createElement("input");
                tempInput.value = inviteLink;
                document.body.appendChild(tempInput);
                tempInput.select();
                tempInput.setSelectionRange(0, 99999);
                document.execCommand("copy");
                document.body.removeChild(tempInput);
                
                alert("邀请码 " + code + " 的注册链接已复制到剪贴板！");
            }

            // 批量链接复制函数
            function copyBatchLinks() {
                const textarea = document.getElementById('batchLinksTextarea');
                textarea.select();
                textarea.setSelectionRange(0, 99999);
                document.execCommand('copy');
                
                const btn = event.target;
                const originalText = btn.textContent;
                btn.textContent = '✅ 已复制！';
                btn.style.background = '#065f46';
                setTimeout(() => {
                    btn.textContent = originalText;
                    btn.style.background = '#10b981';
                }, 2000);
            }

            function copySingleLink(code, link) {
                var tempInput = document.createElement("input");
                tempInput.value = link;
                document.body.appendChild(tempInput);
                tempInput.select();
                tempInput.setSelectionRange(0, 99999);
                document.execCommand("copy");
                document.body.removeChild(tempInput);
                
                alert("邀请码 " + code + " 的链接已复制！");
            }

            function downloadBatchLinks() {
                const content = document.getElementById('batchLinksTextarea').value;
                const blob = new Blob([content], { type: 'text/plain' });
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = '邀请码链接-' + new Date().toISOString().split('T')[0] + '.txt';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                window.URL.revokeObjectURL(url);
            }

            function clearBatchResult() {
                if (confirm('确定要清除批量生成结果吗？')) {
                    window.location.href = '?admin=1';
                }
            }
            </script>
        </body>
        </html>
        <?php
    }
    exit;
}

// ========== 普通用户注册页面 ==========
include 'templates/register.php';
?>
