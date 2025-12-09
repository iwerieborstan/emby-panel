<?php
// /opt/emby_signup/emby_functions.php
// Emby API 相关函数 - 完整修复版

/**
 * 安全调用 Emby API
 */
function safeEmbyApiCall($url, $method = 'GET', $data = [], $operation = 'API操作', $config = null) {
    if ($config === null) {
        global $config;
    }
    
    $media_config = $config['media'];
    $system_config = $config['system'];
    $emby_config = $config['emby'];
    
    // 记录请求信息（不含敏感数据）
    if ($system_config['debug_mode']) {
        $safe_url = preg_replace('/(X-Emby-Token=)[^&]+/', '$1[REDACTED]', $url);
        error_log("[Emby API] 开始 {$operation}: {$safe_url}");
    }
    
    for ($attempt = 1; $attempt <= $media_config['max_retries']; $attempt++) {
        $options = [
            'http' => [
                'header'  => "X-Emby-Token: " . $emby_config['api_key'] . "\r\n",
                'method'  => $method,
                'timeout' => $media_config['api_timeout'],
            ]
        ];
        
        if (!empty($data) && $method !== 'GET') {
            $options['http']['header'] .= "Content-type: application/json\r\n";
            $options['http']['content'] = json_encode($data);
        }
        
        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);
        
        if ($result !== false) {
            if ($system_config['debug_mode']) {
                error_log("[Emby API] {$operation} 成功 (尝试 {$attempt} 次)");
            }
            return ['success' => true, 'data' => $result, 'attempts' => $attempt];
        }
        
        $last_error = error_get_last();
        
        if ($system_config['debug_mode']) {
            if ($attempt === $media_config['max_retries']) {
                error_log("[Emby API] {$operation} 失败: " . json_encode($last_error));
            } elseif ($attempt < $media_config['max_retries']) {
                error_log("[Emby API] {$operation} 重试中... ({$attempt}/{$media_config['max_retries']})");
            }
        }
        
        if ($attempt < $media_config['max_retries']) {
            usleep(300000 * $attempt); // 等待时间递增
        }
    }
    
    $error_messages = [
        '创建用户' => '用户创建服务暂时不可用',
        '设置密码' => '密码设置服务暂时不可用',
        '获取用户' => '用户信息获取失败',
        '获取媒体库' => '媒体库信息获取失败',
        '更新权限' => '权限更新失败',
        '重置用户密码' => '密码重置失败',
        '获取用户详情' => '用户详情获取失败',
        '设置用户媒体库权限' => '媒体库权限设置失败',
        '恢复用户权限' => '用户权限恢复失败',
        '默认' => '服务器暂时不可用，请稍后重试'
    ];
    
    return [
        'success' => false,
        'error' => $error_messages[$operation] ?? $error_messages['默认']
    ];
}

/**
 * 获取所有用户
 */
function get_all_users() {
    global $config;
    $emby_config = $config['emby'];
    $media_config = $config['media'];
    
    $url = $emby_config['host'] . "/emby/Users";
    $result = safeEmbyApiCall($url, 'GET', [], '获取用户', $config);
    
    if (!$result['success']) {
        error_log("[用户管理] 获取用户列表失败: " . $result['error']);
        return [];
    }
    
    $users = json_decode($result['data'], true);
    
    if (!is_array($users)) {
        error_log("[用户管理] 用户数据格式错误");
        return [];
    }
    
    // 如果需要跳过管理员
    if ($media_config['skip_admin']) {
        $filtered_users = [];
        foreach ($users as $user) {
            if (!isset($user['Policy']['IsAdministrator']) || !$user['Policy']['IsAdministrator']) {
                $filtered_users[] = $user;
            }
        }
        return $filtered_users;
    }
    
    return $users;
}

/**
 * 获取所有媒体库
 */
function get_all_libraries() {
    global $config;
    $emby_config = $config['emby'];
    $system_config = $config['system'];
    
    // 获取用户列表以找到管理员
    $url = $emby_config['host'] . "/emby/Users";
    $result = safeEmbyApiCall($url, 'GET', [], '获取管理员用户', $config);
    
    if (!$result['success']) {
        error_log("[媒体库] 获取用户列表失败: " . ($result['error'] ?? '未知错误'));
        return [null, []];
    }
    
    $users = json_decode($result['data'], true);
    
    if (!is_array($users)) {
        error_log("[媒体库] 用户数据格式错误");
        return [null, []];
    }
    
    // 查找管理员用户ID
    $admin_id = null;
    foreach ($users as $user) {
        if (isset($user['Policy']['IsAdministrator']) && $user['Policy']['IsAdministrator']) {
            $admin_id = $user['Id'];
            break;
        }
    }
    
    if (!$admin_id) {
        error_log("[媒体库] 找不到管理员用户");
        return [null, []];
    }
    
    // 获取管理员视图（媒体库）
    $url = $emby_config['host'] . "/emby/Users/{$admin_id}/Views?Fields=Guid";
    $result = safeEmbyApiCall($url, 'GET', [], '获取媒体库', $config);
    
    if (!$result['success']) {
        error_log("[媒体库] 获取媒体库列表失败: " . ($result['error'] ?? '未知错误'));
        return [null, []];
    }
    
    $data = json_decode($result['data'], true);
    $library_map = [];
    
    if (isset($data['Items']) && is_array($data['Items'])) {
        foreach ($data['Items'] as $item) {
            $lib_name = $item['Name'] ?? '';
            $lib_id = isset($item['Guid']) ? $item['Guid'] : ($item['Id'] ?? '');
            
            if (!empty($lib_name) && !empty($lib_id)) {
                $library_map[$lib_name] = $lib_id;
            }
        }
    }
    
    if ($system_config['debug_mode']) {
        error_log("[媒体库] 获取到的媒体库列表: " . implode(', ', array_keys($library_map)));
    }
    
    return [$library_map];
}

/**
 * 隐藏多个媒体库（批量操作）
 */
function hide_libraries_for_users($library_names, $test_users = []) {
    global $config;
    $emby_config = $config['emby'];
    $media_config = $config['media'];
    
    // 获取所有媒体库
    list($library_map) = get_all_libraries();
    
    if (empty($library_map)) {
        return ['success' => false, 'message' => "无法获取媒体库列表，请检查Emby服务器连接"];
    }
    
    // 查找目标媒体库ID
    $target_ids = [];
    $found_libraries = [];
    $missing_libraries = [];
    
    foreach ($library_names as $name) {
        $name = trim($name);
        if (isset($library_map[$name])) {
            $target_ids[] = $library_map[$name];
            $found_libraries[] = $name;
        } else {
            $missing_libraries[] = $name;
        }
    }
    
    if (empty($target_ids)) {
        return ['success' => false, 'message' => "找不到指定的媒体库。可用媒体库: " . implode(', ', array_keys($library_map))];
    }
    
    // 获取所有用户
    $users = get_all_users();
    
    // 如果需要测试用户，则筛选
    if (!empty($test_users)) {
        $filtered_users = [];
        foreach ($users as $user) {
            if (in_array($user['Name'], $test_users)) {
                $filtered_users[] = $user;
            }
        }
        $users = $filtered_users;
    }
    
    $results = [];
    $success_count = 0;
    $processed_count = 0;
    
    foreach ($users as $user) {
        $username = $user['Name'];
        $user_id = $user['Id'];
        $processed_count++;
        
        // 获取用户当前权限
        $url = $emby_config['host'] . "/emby/Users/{$user_id}";
        $result = safeEmbyApiCall($url, 'GET', [], "获取用户 {$username} 权限", $config);
        
        if (!$result['success']) {
            $results[$username] = ['status' => 'failed', 'reason' => '无法获取用户信息'];
            continue;
        }
        
        $user_detail = json_decode($result['data'], true);
        $policy = $user_detail['Policy'] ?? [];
        
        // 计算新的可访问媒体库列表
        $is_enable_all = $policy['EnableAllFolders'] ?? true;
        $current_enabled = $policy['EnabledFolders'] ?? [];
        
        // 获取所有媒体库ID
        $all_ids = array_values($library_map);
        
        if ($is_enable_all) {
            // 如果原来是"允许访问所有"
            $new_enabled = array_filter($all_ids, function($id) use ($target_ids) {
                return !in_array($id, $target_ids);
            });
        } else {
            // 如果原来就是"指定访问"
            $new_enabled = array_filter($current_enabled, function($id) use ($target_ids) {
                return !in_array($id, $target_ids);
            });
        }
        
        // 更新用户权限
        $policy['EnableAllFolders'] = false;
        $policy['EnabledFolders'] = array_values($new_enabled);
        
        $update_url = $emby_config['host'] . "/emby/Users/{$user_id}/Policy";
        $update_result = safeEmbyApiCall($update_url, 'POST', $policy, "更新用户 {$username} 权限", $config);
        
        if ($update_result['success']) {
            $results[$username] = ['status' => 'success'];
            $success_count++;
        } else {
            $results[$username] = ['status' => 'failed', 'reason' => $update_result['error']];
        }
    }
    
    $libs_str = implode(', ', $found_libraries);
    $missing_str = !empty($missing_libraries) ? "（未找到: " . implode(', ', $missing_libraries) . "）" : "";
    $test_mode_info = !empty($test_users) ? "（测试模式，处理 " . count($test_users) . " 个用户）" : "";
    
    return [
        'success' => true,
        'message' => "已为 {$success_count}/{$processed_count} 个用户隐藏媒体库: {$libs_str} {$missing_str} {$test_mode_info}",
        'results' => $results
    ];
}

/**
 * 只显示指定媒体库（批量操作）
 */
function show_libraries_for_users($library_names, $test_users = []) {
    global $config;
    $emby_config = $config['emby'];
    
    // 获取所有媒体库
    list($library_map) = get_all_libraries();
    
    if (empty($library_map)) {
        return ['success' => false, 'message' => "无法获取媒体库列表"];
    }
    
    // 查找目标媒体库ID
    $target_ids = [];
    $found_libraries = [];
    $missing_libraries = [];
    
    foreach ($library_names as $name) {
        $name = trim($name);
        if (isset($library_map[$name])) {
            $target_ids[] = $library_map[$name];
            $found_libraries[] = $name;
        } else {
            $missing_libraries[] = $name;
        }
    }
    
    if (empty($target_ids)) {
        return ['success' => false, 'message' => "找不到指定的媒体库。可用媒体库: " . implode(', ', array_keys($library_map))];
    }
    
    // 获取所有用户
    $users = get_all_users();
    
    // 如果需要测试用户，则筛选
    if (!empty($test_users)) {
        $filtered_users = [];
        foreach ($users as $user) {
            if (in_array($user['Name'], $test_users)) {
                $filtered_users[] = $user;
            }
        }
        $users = $filtered_users;
    }
    
    $results = [];
    $success_count = 0;
    $processed_count = 0;
    
    foreach ($users as $user) {
        $username = $user['Name'];
        $user_id = $user['Id'];
        $processed_count++;
        
        // 更新用户权限（只显示指定的媒体库）
        $policy = [
            'EnableAllFolders' => false,
            'EnabledFolders' => $target_ids
        ];
        
        $update_url = $emby_config['host'] . "/emby/Users/{$user_id}/Policy";
        $result = safeEmbyApiCall($update_url, 'POST', $policy, "更新用户 {$username} 权限", $config);
        
        if ($result['success']) {
            $results[$username] = ['status' => 'success'];
            $success_count++;
        } else {
            $results[$username] = ['status' => 'failed', 'reason' => $result['error']];
        }
    }
    
    $libs_str = implode(', ', $found_libraries);
    $missing_str = !empty($missing_libraries) ? "（未找到: " . implode(', ', $missing_libraries) . "）" : "";
    $test_mode_info = !empty($test_users) ? "（测试模式，处理 " . count($test_users) . " 个用户）" : "";
    
    return [
        'success' => true,
        'message' => "已为 {$success_count}/{$processed_count} 个用户设置只显示媒体库: {$libs_str} {$missing_str} {$test_mode_info}",
        'results' => $results
    ];
}

/**
 * 恢复用户完整权限（批量操作）
 */
function restore_user_access($test_users = []) {
    global $config;
    $emby_config = $config['emby'];
    
    // 获取所有媒体库
    list($library_map) = get_all_libraries();
    if (empty($library_map)) {
        return ['success' => false, 'message' => "无法获取媒体库列表"];
    }
    
    $all_ids = array_values($library_map);
    
    // 获取所有用户
    $users = get_all_users();
    
    // 如果需要测试用户，则筛选
    if (!empty($test_users)) {
        $filtered_users = [];
        foreach ($users as $user) {
            if (in_array($user['Name'], $test_users)) {
                $filtered_users[] = $user;
            }
        }
        $users = $filtered_users;
    }
    
    $results = [];
    $success_count = 0;
    $processed_count = 0;
    
    foreach ($users as $user) {
        $username = $user['Name'];
        $user_id = $user['Id'];
        $processed_count++;
        
        // 恢复为可以访问所有媒体库
        $policy = [
            'EnableAllFolders' => true,
            'EnabledFolders' => $all_ids
        ];
        
        $update_url = $emby_config['host'] . "/emby/Users/{$user_id}/Policy";
        $result = safeEmbyApiCall($update_url, 'POST', $policy, "恢复用户 {$username} 权限", $config);
        
        if ($result['success']) {
            $results[$username] = ['status' => 'success'];
            $success_count++;
        } else {
            $results[$username] = ['status' => 'failed', 'reason' => $result['error']];
        }
    }
    
    $test_mode_info = !empty($test_users) ? "（测试模式，处理 " . count($test_users) . " 个用户）" : "";
    return [
        'success' => true,
        'message' => "已为 {$success_count}/{$processed_count} 个用户恢复完整权限 {$test_mode_info}",
        'results' => $results
    ];
}

/**
 * 创建 Emby 用户
 */
function createEmbyUser($username, $password, $config) {
    $emby_config = $config['emby'];
    $user_config = $config['user'];
    
    // 第一步：创建用户
    $url1 = "{$emby_config['host']}/emby/Users/New?X-Emby-Token={$emby_config['api_key']}";
    $data1 = array(
        'Name' => $username, 
        'CopyFromUserId' => $user_config['preset_userid'], 
        'UserCopyOptions' => 'UserPolicy,UserConfiguration'
    );
    
    $result1 = safeEmbyApiCall($url1, 'POST', $data1, '创建用户', $config);
    
    if (!$result1['success']) {
        return ['success' => false, 'error' => $result1['error']];
    }
    
    $response1 = json_decode($result1['data'], true);
    
    if (!isset($response1['Id']) || empty($response1['Id'])) {
        return ['success' => false, 'error' => '创建用户失败：未获取到用户ID'];
    }
    
    $userid = $response1['Id'];
    
    // 第二步：设置密码
    $url2 = "{$emby_config['host']}/emby/Users/{$userid}/Password?X-Emby-Token={$emby_config['api_key']}";
    $data2 = array('NewPw' => $password, 'CurrentPw' => '');
    
    $result2 = safeEmbyApiCall($url2, 'POST', $data2, '设置密码', $config);
    
    if (!$result2['success']) {
        return [
            'success' => true, 
            'warning' => '账户创建成功，但设置密码失败，请尝试使用空密码登录后修改。',
            'user_id' => $userid
        ];
    }
    
    return ['success' => true, 'user_id' => $userid];
}

/**
 * 重置用户密码
 */
function resetUserPassword($user_id, $new_password, $config) {
    $emby_config = $config['emby'];
    
    $url = $emby_config['host'] . "/emby/Users/{$user_id}/Password?X-Emby-Token={$emby_config['api_key']}";
    $data = array('NewPw' => $new_password, 'CurrentPw' => '');
    
    $result = safeEmbyApiCall($url, 'POST', $data, '重置用户密码', $config);
    
    if ($result['success']) {
        return ['success' => true, 'message' => '密码重置成功'];
    } else {
        return ['success' => false, 'error' => $result['error']];
    }
}

/**
 * 为单个用户显示指定媒体库
 */
function showLibrariesForUser($user_id, $library_names, $config) {
    $emby_config = $config['emby'];
    
    // 获取所有媒体库
    list($library_map) = get_all_libraries();
    
    if (empty($library_map)) {
        return ['success' => false, 'message' => "无法获取媒体库列表，请检查Emby服务器连接"];
    }
    
    // 记录调试信息
    error_log("[用户管理] 可用的媒体库: " . implode(', ', array_keys($library_map)));
    error_log("[用户管理] 请求设置的媒体库: " . implode(', ', $library_names));
    
    // 查找目标媒体库ID
    $target_ids = [];
    $found_libraries = [];
    $missing_libraries = [];
    
    foreach ($library_names as $name) {
        $name = trim($name);
        if (isset($library_map[$name])) {
            $target_ids[] = $library_map[$name];
            $found_libraries[] = $name;
        } else {
            $missing_libraries[] = $name;
        }
    }
    
    if (empty($target_ids)) {
        return ['success' => false, 'message' => "找不到指定的媒体库。可用媒体库: " . implode(', ', array_keys($library_map))];
    }
    
    // 如果有找不到的媒体库，记录但不停止
    if (!empty($missing_libraries)) {
        error_log("[用户管理] 找不到的媒体库: " . implode(', ', $missing_libraries));
    }
    
    // 更新用户权限（只显示指定的媒体库）
    $policy = [
        'EnableAllFolders' => false,
        'EnabledFolders' => $target_ids
    ];
    
    $update_url = $emby_config['host'] . "/emby/Users/{$user_id}/Policy";
    error_log("[用户管理] 更新URL: {$update_url}");
    error_log("[用户管理] 更新策略: " . json_encode($policy));
    
    $result = safeEmbyApiCall($update_url, 'POST', $policy, "设置用户媒体库权限", $config);
    
    if ($result['success']) {
        $message = "成功为用户设置媒体库权限。已设置: " . implode(', ', $found_libraries);
        if (!empty($missing_libraries)) {
            $message .= "。未找到: " . implode(', ', $missing_libraries);
        }
        return ['success' => true, 'message' => $message];
    } else {
        return ['success' => false, 'error' => $result['error'] . " (URL: {$update_url})"];
    }
}

/**
 * 为单个用户隐藏指定媒体库
 */
function hideLibrariesForUser($user_id, $library_names, $config) {
    $emby_config = $config['emby'];
    
    // 获取所有媒体库
    list($library_map) = get_all_libraries();
    
    if (empty($library_map)) {
        return ['success' => false, 'message' => "无法获取媒体库列表，请检查Emby服务器连接"];
    }
    
    error_log("[用户管理] 可用的媒体库: " . implode(', ', array_keys($library_map)));
    error_log("[用户管理] 请求隐藏的媒体库: " . implode(', ', $library_names));
    
    // 查找目标媒体库ID
    $target_ids = [];
    $found_libraries = [];
    $missing_libraries = [];
    
    foreach ($library_names as $name) {
        $name = trim($name);
        if (isset($library_map[$name])) {
            $target_ids[] = $library_map[$name];
            $found_libraries[] = $name;
        } else {
            $missing_libraries[] = $name;
        }
    }
    
    if (empty($target_ids)) {
        return ['success' => false, 'message' => "找不到指定的媒体库。可用媒体库: " . implode(', ', array_keys($library_map))];
    }
    
    // 获取用户当前权限
    $url = $emby_config['host'] . "/emby/Users/{$user_id}";
    $result = safeEmbyApiCall($url, 'GET', [], "获取用户权限", $config);
    
    if (!$result['success']) {
        return ['success' => false, 'message' => '无法获取用户信息: ' . $result['error']];
    }
    
    $user_detail = json_decode($result['data'], true);
    $policy = $user_detail['Policy'] ?? [];
    
    // 计算新的可访问媒体库列表
    $is_enable_all = $policy['EnableAllFolders'] ?? true;
    $current_enabled = $policy['EnabledFolders'] ?? [];
    $all_ids = array_values($library_map);
    
    if ($is_enable_all) {
        // 如果原来是"允许访问所有"
        $new_enabled = array_filter($all_ids, function($id) use ($target_ids) {
            return !in_array($id, $target_ids);
        });
    } else {
        // 如果原来就是"指定访问"
        $new_enabled = array_filter($current_enabled, function($id) use ($target_ids) {
            return !in_array($id, $target_ids);
        });
    }
    
    // 更新用户权限
    $policy['EnableAllFolders'] = false;
    $policy['EnabledFolders'] = array_values($new_enabled);
    
    $update_url = $emby_config['host'] . "/emby/Users/{$user_id}/Policy";
    error_log("[用户管理] 更新隐藏权限URL: {$update_url}");
    error_log("[用户管理] 更新隐藏策略: " . json_encode($policy));
    
    $update_result = safeEmbyApiCall($update_url, 'POST', $policy, "更新用户权限", $config);
    
    if ($update_result['success']) {
        $message = "成功为用户隐藏媒体库。已隐藏: " . implode(', ', $found_libraries);
        if (!empty($missing_libraries)) {
            $message .= "。未找到: " . implode(', ', $missing_libraries);
        }
        return ['success' => true, 'message' => $message];
    } else {
        return ['success' => false, 'error' => $update_result['error'] . " (URL: {$update_url})"];
    }
}

/**
 * 恢复单个用户完整权限
 */
function restoreUserAccess($user_id, $config) {
    $emby_config = $config['emby'];
    
    // 获取所有媒体库
    list($library_map) = get_all_libraries();
    if (empty($library_map)) {
        return ['success' => false, 'message' => "无法获取媒体库列表"];
    }
    
    $all_ids = array_values($library_map);
    
    // 恢复为可以访问所有媒体库
    $policy = [
        'EnableAllFolders' => true,
        'EnabledFolders' => $all_ids
    ];
    
    $update_url = $emby_config['host'] . "/emby/Users/{$user_id}/Policy";
    $result = safeEmbyApiCall($update_url, 'POST', $policy, "恢复用户权限", $config);
    
    if ($result['success']) {
        return ['success' => true, 'message' => "用户权限恢复成功"];
    } else {
        return ['success' => false, 'error' => $result['error']];
    }
}

/**
 * 获取用户详情（包含最后登录时间）
 */
function getUserDetails($user_id, $config) {
    $emby_config = $config['emby'];
    $url = $emby_config['host'] . "/emby/Users/{$user_id}";
    $result = safeEmbyApiCall($url, 'GET', [], "获取用户详情", $config);
    
    if ($result['success']) {
        $user_detail = json_decode($result['data'], true);
        return [
            'last_login_date' => $user_detail['LastLoginDate'] ?? '从未登录',
            'last_activity_date' => $user_detail['LastActivityDate'] ?? '从未活动',
            'policy' => $user_detail['Policy'] ?? []
        ];
    }
    
    return [
        'last_login_date' => '获取失败',
        'last_activity_date' => '获取失败',
        'policy' => []
    ];
}
?>
