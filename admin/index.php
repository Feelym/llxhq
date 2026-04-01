<?php
session_start();

// 使用 SQLite 数据库
require_once dirname(__DIR__) . '/database.php';
$db = Database::getInstance();

// 验证登录
function isLoggedIn() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

// 处理 API 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'login') {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        if ($db->verifyAdmin($username, $password)) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_username'] = $username;
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => '用户名或密码错误']);
        }
        exit;
    }

    // 以下操作需要登录
    if (!isLoggedIn()) {
        echo json_encode(['success' => false, 'message' => '未登录']);
        exit;
    }

    switch ($_POST['action']) {
        case 'logout':
            session_destroy();
            echo json_encode(['success' => true]);
            break;

        case 'get_sources':
            echo json_encode(['success' => true, 'data' => $db->getSources()]);
            break;

        case 'get_settings':
            echo json_encode(['success' => true, 'data' => $db->getSettings()]);
            break;

        case 'save_settings':
            $maxThreads = max(1, min(32, intval($_POST['max_threads'] ?? 32)));
            $defaultThreads = max(1, min($maxThreads, intval($_POST['default_threads'] ?? 1)));
            $siteTitle = trim($_POST['site_title'] ?? '流量消耗器');

            $db->updateSetting('max_threads', $maxThreads);
            $db->updateSetting('default_threads', $defaultThreads);
            $db->updateSetting('site_title', $siteTitle);

            echo json_encode(['success' => true]);
            break;

        case 'get_admin_info':
            $username = $_SESSION['admin_username'] ?? $db->getAdminUsername();
            echo json_encode(['success' => true, 'data' => ['username' => $username]]);
            break;

        case 'change_password':
            $oldPassword = $_POST['old_password'] ?? '';
            $newUsername = trim($_POST['new_username'] ?? '');
            $newPassword = $_POST['new_password'] ?? '';
            $currentUsername = $_SESSION['admin_username'] ?? 'admin';

            // 验证用户名
            if (empty($newUsername)) {
                echo json_encode(['success' => false, 'message' => '用户名不能为空']);
                break;
            }

            if (strlen($newUsername) < 3) {
                echo json_encode(['success' => false, 'message' => '用户名至少3个字符']);
                break;
            }

            // 如果有新密码，验证长度
            if (!empty($newPassword) && strlen($newPassword) < 6) {
                echo json_encode(['success' => false, 'message' => '新密码至少6位']);
                break;
            }

            // 调用更新方法
            $result = $db->updateAdminAccount($currentUsername, $oldPassword, $newUsername, $newPassword ?: null);

            if ($result['success']) {
                // 更新 session 中的用户名
                $_SESSION['admin_username'] = $newUsername;
            }

            echo json_encode($result);
            break;

        // ========== 分组管理 ==========
        case 'get_groups':
            echo json_encode(['success' => true, 'data' => $db->getGroups()]);
            break;

        case 'add_group':
            $name = trim($_POST['name'] ?? '');
            $sortOrder = intval($_POST['sort_order'] ?? 0);

            if (empty($name)) {
                echo json_encode(['success' => false, 'message' => '分组名称不能为空']);
            } elseif ($db->addGroup($name, $sortOrder)) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => '添加失败，分组名可能已存在']);
            }
            break;

        case 'update_group':
            $id = intval($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $sortOrder = intval($_POST['sort_order'] ?? 0);

            if (empty($name)) {
                echo json_encode(['success' => false, 'message' => '分组名称不能为空']);
            } elseif ($db->updateGroup($id, $name, $sortOrder)) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => '更新失败']);
            }
            break;

        case 'delete_group':
            $id = intval($_POST['id'] ?? 0);
            $result = $db->deleteGroup($id);
            echo json_encode($result);
            break;

        // ========== 测速源管理 ==========
        case 'add_source':
            $name = trim($_POST['name'] ?? '');
            $url = trim($_POST['url'] ?? '');
            $groupId = intval($_POST['group_id'] ?? 1);

            if (empty($name) || empty($url)) {
                echo json_encode(['success' => false, 'message' => '名称和链接不能为空']);
            } elseif ($db->addSource($name, $url, $groupId)) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => '添加失败']);
            }
            break;

        case 'update_source':
            $id = intval($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $url = trim($_POST['url'] ?? '');
            $groupId = intval($_POST['group_id'] ?? 1);
            $enabled = $_POST['enabled'] === 'true' || $_POST['enabled'] === '1';

            if (empty($name) || empty($url)) {
                echo json_encode(['success' => false, 'message' => '名称和链接不能为空']);
            } elseif ($db->updateSource($id, $name, $url, $groupId, $enabled)) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => '更新失败']);
            }
            break;

        case 'delete_source':
            $id = intval($_POST['id'] ?? 0);
            if ($db->deleteSource($id)) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => '删除失败']);
            }
            break;

        case 'toggle_source':
            $id = intval($_POST['id'] ?? 0);
            if ($db->toggleSource($id)) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => '操作失败']);
            }
            break;

        default:
            echo json_encode(['success' => false, 'message' => '未知操作']);
    }
    exit;
}

$loggedIn = isLoggedIn();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理后台 - 流量消耗器</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --bg-color: #f0f2f5;
            --card-bg: #ffffff;
            --text-color: #333333;
            --text-secondary: #666666;
            --primary-color: #4a7dff;
            --primary-hover: #3a6ae8;
            --danger-color: #ff4757;
            --success-color: #2ed573;
            --warning-color: #ffa502;
            --border-color: #e1e5eb;
            --sidebar-bg: #1a1a2e;
            --sidebar-text: #a0a0a0;
            --sidebar-active: #4a7dff;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            min-height: 100vh;
        }

        /* 登录页面 */
        .login-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }

        .login-box {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 40px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.1);
        }

        .login-title {
            text-align: center;
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 0.9rem;
            font-weight: 500;
            margin-bottom: 8px;
            color: var(--text-secondary);
        }

        .form-input, .form-select {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .form-input:focus, .form-select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(74, 125, 255, 0.2);
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-hover);
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-block {
            width: 100%;
        }

        .btn-sm {
            padding: 8px 16px;
            font-size: 0.85rem;
        }

        /* 后台布局 */
        .admin-layout {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 240px;
            background: var(--sidebar-bg);
            color: var(--sidebar-text);
            padding: 20px 0;
            flex-shrink: 0;
        }

        .sidebar-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 20px;
        }

        .sidebar-title {
            color: white;
            font-size: 1.2rem;
            font-weight: 600;
        }

        .sidebar-nav {
            list-style: none;
        }

        .sidebar-nav li {
            margin-bottom: 4px;
        }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: var(--sidebar-text);
            text-decoration: none;
            transition: all 0.3s ease;
            gap: 10px;
        }

        .sidebar-nav a:hover,
        .sidebar-nav a.active {
            background: rgba(74, 125, 255, 0.2);
            color: white;
        }

        .sidebar-nav a.active {
            border-left: 3px solid var(--sidebar-active);
        }

        .main-content {
            flex: 1;
            padding: 30px;
            overflow-y: auto;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .page-title {
            font-size: 1.5rem;
            font-weight: 600;
        }

        .card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .card-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border-color);
        }

        /* 表格 */
        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th,
        .table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .table th {
            font-weight: 600;
            color: var(--text-secondary);
            font-size: 0.85rem;
            text-transform: uppercase;
        }

        .table tr:hover {
            background: rgba(0, 0, 0, 0.02);
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .badge-success {
            background: rgba(46, 213, 115, 0.15);
            color: var(--success-color);
        }

        .badge-danger {
            background: rgba(255, 71, 87, 0.15);
            color: var(--danger-color);
        }

        .action-btns {
            display: flex;
            gap: 8px;
        }

        .url-cell {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        /* Toast */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 10000;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .toast {
            background: var(--card-bg);
            border-radius: 10px;
            padding: 14px 20px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease;
            min-width: 260px;
            border-left: 4px solid var(--primary-color);
        }

        .toast.success { border-left-color: var(--success-color); }
        .toast.error { border-left-color: var(--danger-color); }
        .toast.warning { border-left-color: var(--warning-color); }

        .toast.hide {
            animation: slideOut 0.3s ease forwards;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateX(50px); }
            to { opacity: 1; transform: translateX(0); }
        }

        @keyframes slideOut {
            from { opacity: 1; transform: translateX(0); }
            to { opacity: 0; transform: translateX(50px); }
        }

        /* Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }

        .modal-overlay.show {
            display: flex;
        }

        .modal {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 24px;
            width: 100%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalIn 0.3s ease;
        }

        @keyframes modalIn {
            from { opacity: 0; transform: scale(0.9); }
            to { opacity: 1; transform: scale(1); }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-title {
            font-size: 1.2rem;
            font-weight: 600;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-secondary);
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 24px;
            padding-top: 16px;
            border-top: 1px solid var(--border-color);
        }

        .form-row {
            display: flex;
            gap: 16px;
        }

        .form-row .form-group {
            flex: 1;
        }

        /* 响应式 */
        @media (max-width: 768px) {
            .sidebar {
                display: none;
            }

            .main-content {
                padding: 16px;
            }

            .table {
                display: block;
                overflow-x: auto;
            }
        }

        .hidden { display: none !important; }
    </style>
</head>
<body>
    <div class="toast-container" id="toastContainer"></div>

    <!-- 登录页面 -->
    <div class="login-container" id="loginPage" <?= $loggedIn ? 'style="display:none"' : '' ?>>
        <div class="login-box">
            <h1 class="login-title">管理后台</h1>
            <form id="loginForm">
                <div class="form-group">
                    <label class="form-label">用户名</label>
                    <input type="text" name="username" class="form-input" required autocomplete="username">
                </div>
                <div class="form-group">
                    <label class="form-label">密码</label>
                    <input type="password" name="password" class="form-input" required autocomplete="current-password">
                </div>
                <button type="submit" class="btn btn-primary btn-block">登录</button>
            </form>
        </div>
    </div>

    <!-- 后台主界面 -->
    <div class="admin-layout" id="adminPage" <?= !$loggedIn ? 'style="display:none"' : '' ?>>
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-title">流量消耗器</div>
            </div>
            <ul class="sidebar-nav">
                <li><a href="#" class="active" data-page="groups">测速分组管理</a></li>
                <li><a href="#" data-page="sources">测速源管理</a></li>
                <li><a href="#" data-page="settings">系统设置</a></li>
                <li><a href="#" data-page="password">账号设置</a></li>
                <li><a href="#" onclick="logout()">退出登录</a></li>
            </ul>
        </aside>

        <main class="main-content">
            <!-- 分组管理 -->
            <div class="page" id="page-groups">
                <div class="page-header">
                    <h2 class="page-title">测速分组管理</h2>
                    <button class="btn btn-primary" onclick="showAddGroupModal()">+ 添加分组</button>
                </div>

                <div class="card">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>分组名称</th>
                                <th>排序</th>
                                <th>测速源数量</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody id="groupsList"></tbody>
                    </table>
                </div>
            </div>

            <!-- 测速源管理 -->
            <div class="page hidden" id="page-sources">
                <div class="page-header">
                    <h2 class="page-title">测速源管理</h2>
                    <button class="btn btn-primary" onclick="showAddModal()">+ 添加测速源</button>
                </div>

                <div class="card">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>名称</th>
                                <th>分组</th>
                                <th>链接</th>
                                <th>状态</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody id="sourcesList"></tbody>
                    </table>
                </div>
            </div>

            <!-- 系统设置 -->
            <div class="page hidden" id="page-settings">
                <div class="page-header">
                    <h2 class="page-title">系统设置</h2>
                </div>

                <div class="card">
                    <h3 class="card-title">基本设置</h3>
                    <form id="settingsForm">
                        <div class="form-group">
                            <label class="form-label">网站标题</label>
                            <input type="text" name="site_title" class="form-input" id="siteTitleInput">
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">默认线程数</label>
                                <input type="number" name="default_threads" class="form-input" min="1" max="32" id="defaultThreadsInput">
                            </div>
                            <div class="form-group">
                                <label class="form-label">最大线程数</label>
                                <input type="number" name="max_threads" class="form-input" min="1" max="32" id="maxThreadsInput">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">保存设置</button>
                    </form>
                </div>
            </div>

            <!-- 账号设置 -->
            <div class="page hidden" id="page-password">
                <div class="page-header">
                    <h2 class="page-title">账号设置</h2>
                </div>

                <div class="card">
                    <h3 class="card-title">修改账号信息</h3>
                    <form id="passwordForm">
                        <div class="form-group">
                            <label class="form-label">用户名</label>
                            <input type="text" name="new_username" id="adminUsername" class="form-input" required minlength="3" placeholder="至少3个字符">
                        </div>
                        <div class="form-group">
                            <label class="form-label">原密码 <span style="color:var(--danger-color)">*</span></label>
                            <input type="password" name="old_password" class="form-input" required placeholder="验证身份需要输入原密码">
                        </div>
                        <div class="form-group">
                            <label class="form-label">新密码 <span style="color:var(--text-secondary);font-weight:normal">(不修改请留空)</span></label>
                            <input type="password" name="new_password" class="form-input" minlength="6" placeholder="至少6位，不修改请留空">
                        </div>
                        <div class="form-group">
                            <label class="form-label">确认新密码</label>
                            <input type="password" name="confirm_password" class="form-input" placeholder="再次输入新密码">
                        </div>
                        <button type="submit" class="btn btn-primary">保存修改</button>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <!-- 分组模态框 -->
    <div class="modal-overlay" id="groupModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title" id="groupModalTitle">添加分组</h3>
                <button class="modal-close" onclick="closeGroupModal()">&times;</button>
            </div>
            <form id="groupForm">
                <input type="hidden" name="id" id="groupId">
                <div class="form-group">
                    <label class="form-label">分组名称</label>
                    <input type="text" name="name" class="form-input" id="groupName" required placeholder="例如：国内组、国际组">
                </div>
                <div class="form-group">
                    <label class="form-label">排序 <span style="color:var(--text-secondary);font-weight:normal">(数字越小越靠前)</span></label>
                    <input type="number" name="sort_order" class="form-input" id="groupSortOrder" value="0" min="0">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeGroupModal()">取消</button>
                    <button type="submit" class="btn btn-primary">保存</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 测速源模态框 -->
    <div class="modal-overlay" id="sourceModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle">添加测速源</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <form id="sourceForm">
                <input type="hidden" name="id" id="sourceId">
                <div class="form-group">
                    <label class="form-label">名称</label>
                    <input type="text" name="name" class="form-input" id="sourceName" required>
                </div>
                <div class="form-group">
                    <label class="form-label">分组</label>
                    <select name="group_id" class="form-select" id="sourceGroupId">
                        <!-- 动态加载 -->
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">链接</label>
                    <input type="url" name="url" class="form-input" id="sourceUrl" required>
                </div>
                <div class="form-group" id="enabledGroup" style="display:none">
                    <label class="form-label">
                        <input type="checkbox" name="enabled" id="sourceEnabled" checked> 启用
                    </label>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal()">取消</button>
                    <button type="submit" class="btn btn-primary">保存</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Toast 提示
        function showToast(type, message, duration = 3000) {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            const icons = { success: '✓', error: '✗', warning: '!', info: 'i' };
            toast.innerHTML = `<span>${icons[type] || icons.info}</span><span>${message}</span>`;
            container.appendChild(toast);
            setTimeout(() => {
                toast.classList.add('hide');
                setTimeout(() => toast.remove(), 300);
            }, duration);
        }

        // API 请求
        async function api(action, data = {}) {
            const formData = new FormData();
            formData.append('action', action);
            for (const key in data) {
                formData.append(key, data[key]);
            }
            const res = await fetch('', { method: 'POST', body: formData });
            return res.json();
        }

        // 登录
        document.getElementById('loginForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const form = e.target;
            const result = await api('login', {
                username: form.username.value,
                password: form.password.value
            });
            if (result.success) {
                showToast('success', '登录成功');
                document.getElementById('loginPage').style.display = 'none';
                document.getElementById('adminPage').style.display = 'flex';
                loadGroups();
                loadSources();
                loadSettings();
                loadAdminInfo();
            } else {
                showToast('error', result.message || '登录失败');
            }
        });

        // 退出
        async function logout() {
            await api('logout');
            location.reload();
        }

        // 页面切换
        document.querySelectorAll('.sidebar-nav a[data-page]').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const page = e.target.dataset.page;
                document.querySelectorAll('.sidebar-nav a').forEach(a => a.classList.remove('active'));
                e.target.classList.add('active');
                document.querySelectorAll('.page').forEach(p => p.classList.add('hidden'));
                document.getElementById('page-' + page).classList.remove('hidden');
            });
        });

        // ========== 分组管理 ==========
        let groupsCache = [];

        // 加载分组
        async function loadGroups() {
            const result = await api('get_groups');
            if (result.success) {
                groupsCache = result.data;
                renderGroups(result.data);
                updateGroupSelect();
            }
        }

        // 渲染分组列表
        function renderGroups(groups) {
            const tbody = document.getElementById('groupsList');
            tbody.innerHTML = groups.map(g => `
                <tr>
                    <td><strong>${escapeHtml(g.name)}</strong></td>
                    <td>${g.sort_order}</td>
                    <td><span class="badge badge-success" id="group-count-${g.id}">-</span></td>
                    <td>
                        <div class="action-btns">
                            <button class="btn btn-sm btn-primary" onclick="editGroup(${g.id})">编辑</button>
                            <button class="btn btn-sm btn-danger" onclick="deleteGroup(${g.id})">删除</button>
                        </div>
                    </td>
                </tr>
            `).join('');

            // 更新每个分组的测速源数量
            loadSourceCounts();
        }

        // 加载每个分组的测速源数量
        async function loadSourceCounts() {
            const result = await api('get_sources');
            if (result.success) {
                const counts = {};
                result.data.forEach(s => {
                    counts[s.group_id] = (counts[s.group_id] || 0) + 1;
                });
                groupsCache.forEach(g => {
                    const el = document.getElementById('group-count-' + g.id);
                    if (el) {
                        el.textContent = counts[g.id] || 0;
                    }
                });
            }
        }

        // 更新分组下拉选择
        function updateGroupSelect() {
            const select = document.getElementById('sourceGroupId');
            select.innerHTML = groupsCache.map(g =>
                `<option value="${g.id}">${escapeHtml(g.name)}</option>`
            ).join('');
        }

        // 显示添加分组模态框
        function showAddGroupModal() {
            document.getElementById('groupModalTitle').textContent = '添加分组';
            document.getElementById('groupId').value = '';
            document.getElementById('groupName').value = '';
            document.getElementById('groupSortOrder').value = '0';
            document.getElementById('groupModal').classList.add('show');
        }

        // 编辑分组
        function editGroup(id) {
            const group = groupsCache.find(g => g.id === id);
            if (group) {
                document.getElementById('groupModalTitle').textContent = '编辑分组';
                document.getElementById('groupId').value = group.id;
                document.getElementById('groupName').value = group.name;
                document.getElementById('groupSortOrder').value = group.sort_order;
                document.getElementById('groupModal').classList.add('show');
            }
        }

        // 关闭分组模态框
        function closeGroupModal() {
            document.getElementById('groupModal').classList.remove('show');
        }

        // 保存分组
        document.getElementById('groupForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const form = e.target;
            const id = document.getElementById('groupId').value;
            const action = id ? 'update_group' : 'add_group';
            const data = {
                name: document.getElementById('groupName').value,
                sort_order: document.getElementById('groupSortOrder').value
            };
            if (id) {
                data.id = id;
            }
            const result = await api(action, data);
            if (result.success) {
                showToast('success', id ? '更新成功' : '添加成功');
                closeGroupModal();
                loadGroups();
            } else {
                showToast('error', result.message || '操作失败');
            }
        });

        // 删除分组
        async function deleteGroup(id) {
            if (!confirm('确定要删除这个分组吗？')) return;
            const result = await api('delete_group', { id });
            if (result.success) {
                showToast('success', '删除成功');
                loadGroups();
            } else {
                showToast('error', result.message || '删除失败');
            }
        }

        // ========== 测速源管理 ==========

        // 加载测速源
        async function loadSources() {
            const result = await api('get_sources');
            if (result.success) {
                renderSources(result.data);
            }
        }

        // 渲染测速源列表
        function renderSources(sources) {
            const tbody = document.getElementById('sourcesList');
            tbody.innerHTML = sources.map(s => `
                <tr>
                    <td><strong>${escapeHtml(s.name)}</strong></td>
                    <td>${escapeHtml(s.group)}</td>
                    <td class="url-cell" title="${escapeHtml(s.url)}">${escapeHtml(s.url)}</td>
                    <td>
                        <span class="badge ${s.enabled ? 'badge-success' : 'badge-danger'}">
                            ${s.enabled ? '启用' : '禁用'}
                        </span>
                    </td>
                    <td>
                        <div class="action-btns">
                            <button class="btn btn-sm btn-primary" onclick="editSource(${s.id})">编辑</button>
                            <button class="btn btn-sm ${s.enabled ? 'btn-danger' : 'btn-success'}"
                                onclick="toggleSource(${s.id})">${s.enabled ? '禁用' : '启用'}</button>
                            <button class="btn btn-sm btn-danger" onclick="deleteSource(${s.id})">删除</button>
                        </div>
                    </td>
                </tr>
            `).join('');
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // 显示添加模态框
        async function showAddModal() {
            await loadGroups(); // 确保分组列表是最新的
            document.getElementById('modalTitle').textContent = '添加测速源';
            document.getElementById('sourceId').value = '';
            document.getElementById('sourceName').value = '';
            document.getElementById('sourceUrl').value = '';
            if (groupsCache.length > 0) {
                document.getElementById('sourceGroupId').value = groupsCache[0].id;
            }
            document.getElementById('enabledGroup').style.display = 'none';
            document.getElementById('sourceModal').classList.add('show');
        }

        // 编辑测速源
        let sourcesCache = [];
        async function editSource(id) {
            await loadGroups(); // 确保分组列表是最新的
            const result = await api('get_sources');
            if (result.success) {
                sourcesCache = result.data;
                const source = sourcesCache.find(s => s.id === id);
                if (source) {
                    document.getElementById('modalTitle').textContent = '编辑测速源';
                    document.getElementById('sourceId').value = source.id;
                    document.getElementById('sourceName').value = source.name;
                    document.getElementById('sourceUrl').value = source.url;
                    document.getElementById('sourceGroupId').value = source.group_id;
                    document.getElementById('sourceEnabled').checked = source.enabled;
                    document.getElementById('enabledGroup').style.display = 'block';
                    document.getElementById('sourceModal').classList.add('show');
                }
            }
        }

        // 关闭模态框
        function closeModal() {
            document.getElementById('sourceModal').classList.remove('show');
        }

        // 保存测速源
        document.getElementById('sourceForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const form = e.target;
            const id = document.getElementById('sourceId').value;
            const action = id ? 'update_source' : 'add_source';
            const data = {
                name: document.getElementById('sourceName').value,
                url: document.getElementById('sourceUrl').value,
                group_id: document.getElementById('sourceGroupId').value
            };
            if (id) {
                data.id = id;
                data.enabled = document.getElementById('sourceEnabled').checked;
            }
            const result = await api(action, data);
            if (result.success) {
                showToast('success', id ? '更新成功' : '添加成功');
                closeModal();
                loadSources();
                loadGroups(); // 更新分组数量
            } else {
                showToast('error', result.message || '操作失败');
            }
        });

        // 切换状态
        async function toggleSource(id) {
            const result = await api('toggle_source', { id });
            if (result.success) {
                showToast('success', '状态已更新');
                loadSources();
            }
        }

        // 删除测速源
        async function deleteSource(id) {
            if (!confirm('确定要删除这个测速源吗？')) return;
            const result = await api('delete_source', { id });
            if (result.success) {
                showToast('success', '删除成功');
                loadSources();
                loadGroups(); // 更新分组数量
            }
        }

        // 加载设置
        async function loadSettings() {
            const result = await api('get_settings');
            if (result.success) {
                document.getElementById('siteTitleInput').value = result.data.site_title;
                document.getElementById('defaultThreadsInput').value = result.data.default_threads;
                document.getElementById('maxThreadsInput').value = result.data.max_threads;
            }
        }

        // 保存设置
        document.getElementById('settingsForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const form = e.target;
            const result = await api('save_settings', {
                site_title: form.site_title.value,
                default_threads: form.default_threads.value,
                max_threads: form.max_threads.value
            });
            if (result.success) {
                showToast('success', '设置已保存');
            } else {
                showToast('error', result.message || '保存失败');
            }
        });

        // 加载管理员信息
        async function loadAdminInfo() {
            const result = await api('get_admin_info');
            if (result.success) {
                document.getElementById('adminUsername').value = result.data.username;
            }
        }

        // 修改账号信息
        document.getElementById('passwordForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const form = e.target;

            // 如果填了新密码，验证两次密码是否一致
            if (form.new_password.value || form.confirm_password.value) {
                if (form.new_password.value !== form.confirm_password.value) {
                    showToast('error', '两次密码不一致');
                    return;
                }
            }

            const result = await api('change_password', {
                new_username: form.new_username.value,
                old_password: form.old_password.value,
                new_password: form.new_password.value
            });

            if (result.success) {
                showToast('success', result.message || '修改成功');
                form.old_password.value = '';
                form.new_password.value = '';
                form.confirm_password.value = '';
            } else {
                showToast('error', result.message || '修改失败');
            }
        });

        // 初始化
        <?php if ($loggedIn): ?>
        loadGroups();
        loadSources();
        loadSettings();
        loadAdminInfo();
        <?php endif; ?>
    </script>
</body>
</html>
