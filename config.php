<?php
// /opt/emby-panel/config.php
// 统一配置文件 - 所有配置信息集中管理

return [
    // ========== Emby 服务器配置 ==========
    'emby' => [
        'host' => 'http://ip:8096',                    // Emby 服务器地址
        'api_key' => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxx',  // Emby API Key
        'server_name' => 'Emby',                       // 服务器名称
    ],
    
    // ========== 用户系统配置 ==========
    'user' => [
        'preset_userid' => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',  // 预设用户ID（模板用户）
         // 网页登录Emby，点进用户资料，浏览器地址userId=后面的ID
        'admin_username' => 'admin',                            // 管理员账户
        'admin_password' => 'admin123',                         // 管理员密码
        'invite_file' => '/data/invite_codes.json',
        'default_password_min_length' => 4,
        'default_username_pattern' => '/^[a-zA-Z0-9]{4,}$/',
        'emby_users_file' => '/data/emby_users.json',
    ],

    // ========== 账号清理配置 ==========
    'cleanup' => [
        'inactive_days' => 60,               // 多少天没有登录自动删除
        'enable_inactive_cleanup' => true,
        'skip_never_logged_in' => false,
        'skip_admins' => true,
        'log_file' => '/logs/inactive_cleanup_log.txt',
        'enable_expiry_cleanup' => true,
        'expiry_cleanup_log' => '/logs/expiry_cleanup_log.txt',
    ],

    // ========== 网站前端配置 ==========
    'site' => [
        'name' => 'Embydada',                                   // 网站名称
        'title' => 'Embydada Signup',                           // 页面标题
        'emby_login_url' => 'https://emby.com',                 // Emby 登录地址
        'custom_image' => 'https://www.loliapi.com/acg/pe/',    // 背景图片API
        'favicon' => 'https://emby.media/favicon-96x96.png',    // 网站图标
        'theme' => [                                           
            'primary_gradient' => 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
            'success_color' => '#10b981',
            'error_color' => '#ef4444',
            'warning_color' => '#f59e0b',
        ],
    ],
    
    // ========== 媒体库管理配置 ==========
    'media' => [
        'default_mode' => 'HIDE',
        'skip_admin' => true,
        'api_timeout' => 10,
        'max_retries' => 2,
    ],
    
    // ========== 邀请码系统配置 ==========
    'invite' => [
        'code_length' => 8,
        'allowed_chars' => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789',
        'auto_generate_link' => true,
        'default_valid_days' => 30,                             // 默认账号有效期天数
        'max_valid_days' => 360,                                // 最大账号有效期天数
        'min_valid_days' => 1,                                  // 最小账号有效期天数
    ],
    
    // ========== 注册模式配置 ==========
    'registration' => [
        'mode' => 'invite',
        
        // 开放注册配置
        'open_registration' => [
            'enabled' => false,
            'max_users' => 100,
            'current_count' => 0,
            'account_valid_days' => 30,
            'is_permanent' => false,
            'enabled_at' => null,
            'closed_at' => null,
            'auto_close_on_full' => true,
        ],
        
        // 注册模式数据文件
        'data_file' => '/data/registration_status.json',
    ],
    
    // ========== 账号有效期配置 ==========（新增）
    'account_expiry' => [
        'enable_expiry_check' => true,              // 是否启用账号有效期检查
        'cron_check_interval' => 3600,              // 定时检查间隔（秒）
        'grace_period_days' => 3,                   // 宽限期（过期后多少天再删除）
        'notify_before_days' => 7,                  // 提前多少天通知（如果实现通知功能）
        'permanent_flag_days' => 9999,              // 永久账号的标志天数
    ],
    
    // ========== 系统设置 ==========
    'system' => [
        'debug_mode' => false,                                  // 调试模式
        'session_timeout' => 3600,                              // 会话超时时间（秒）
        'enable_error_logging' => true,                         // 是否启用错误日志
        'timezone' => 'Asia/Shanghai',                          // 时区设置
        'data_dir' => '/data/',                                  // 数据目录
    ],
    
    // ========== 安全配置 ==========
    'security' => [
        'rate_limit' => [              // 频率限制
            'register' => 5,           // 每分钟最多注册次数
            'admin_login' => 3,        // 每分钟最多管理员登录次数
            'open_registration' => 10, // 开放注册频率限制
        ],
        'password_requirements' => [
            'min_length' => 4,
            'require_numbers' => false,
            'require_special_chars' => false,
        ],
        'csrf_protection' => true,
    ],
];
?>
