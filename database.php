<?php
/**
 * SQLite 数据库操作类
 */
class Database {
    private static $instance = null;
    private $db;
    private $dbPath;

    private function __construct() {
        $this->dbPath = __DIR__ . '/data.db';
        $this->connect();
        $this->initTables();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function connect() {
        try {
            $this->db = new PDO('sqlite:' . $this->dbPath);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            die('数据库连接失败: ' . $e->getMessage());
        }
    }

    private function initTables() {
        // 创建管理员表
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS admin (
                id INTEGER PRIMARY KEY,
                username TEXT NOT NULL UNIQUE,
                password TEXT NOT NULL
            )
        ");

        // 创建设置表
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS settings (
                id INTEGER PRIMARY KEY,
                key TEXT NOT NULL UNIQUE,
                value TEXT NOT NULL
            )
        ");

        // 创建分组表
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS groups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                sort_order INTEGER DEFAULT 0
            )
        ");

        // 创建测速源表
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS sources (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                url TEXT NOT NULL,
                group_id INTEGER DEFAULT 1,
                enabled INTEGER DEFAULT 1,
                FOREIGN KEY (group_id) REFERENCES groups(id)
            )
        ");

        // 初始化默认数据
        $this->initDefaultData();
    }

    private function initDefaultData() {
        // 检查是否有管理员
        $stmt = $this->db->query("SELECT COUNT(*) FROM admin");
        if ($stmt->fetchColumn() == 0) {
            // 添加默认管理员 (密码使用 password_hash 加密)
            $hash = password_hash('admin123', PASSWORD_DEFAULT);
            $stmt = $this->db->prepare("INSERT INTO admin (username, password) VALUES (?, ?)");
            $stmt->execute(['admin', $hash]);
        }

        // 检查是否有设置
        $stmt = $this->db->query("SELECT COUNT(*) FROM settings");
        if ($stmt->fetchColumn() == 0) {
            $defaultSettings = [
                'max_threads' => '32',
                'default_threads' => '1',
                'site_title' => '流量消耗器'
            ];
            $stmt = $this->db->prepare("INSERT INTO settings (key, value) VALUES (?, ?)");
            foreach ($defaultSettings as $key => $value) {
                $stmt->execute([$key, $value]);
            }
        }

        // 检查是否有分组
        $stmt = $this->db->query("SELECT COUNT(*) FROM groups");
        if ($stmt->fetchColumn() == 0) {
            $defaultGroups = [
                ['国内组', 1],
                ['国际组', 2]
            ];
            $stmt = $this->db->prepare("INSERT INTO groups (name, sort_order) VALUES (?, ?)");
            foreach ($defaultGroups as $group) {
                $stmt->execute($group);
            }
        }
    }

    // 获取所有设置
    public function getSettings() {
        $stmt = $this->db->query("SELECT key, value FROM settings");
        $settings = [];
        while ($row = $stmt->fetch()) {
            $settings[$row['key']] = $row['value'];
        }
        return $settings;
    }

    // 更新设置
    public function updateSetting($key, $value) {
        $stmt = $this->db->prepare("UPDATE settings SET value = ? WHERE key = ?");
        return $stmt->execute([$value, $key]);
    }

    // ========== 分组管理 ==========

    // 获取所有分组
    public function getGroups() {
        $stmt = $this->db->query("SELECT id, name, sort_order FROM groups ORDER BY sort_order ASC, id ASC");
        return $stmt->fetchAll();
    }

    // 获取单个分组
    public function getGroup($id) {
        $stmt = $this->db->prepare("SELECT id, name, sort_order FROM groups WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    // 添加分组
    public function addGroup($name, $sortOrder = 0) {
        $stmt = $this->db->prepare("INSERT INTO groups (name, sort_order) VALUES (?, ?)");
        return $stmt->execute([$name, $sortOrder]);
    }

    // 更新分组
    public function updateGroup($id, $name, $sortOrder = 0) {
        $stmt = $this->db->prepare("UPDATE groups SET name = ?, sort_order = ? WHERE id = ?");
        return $stmt->execute([$name, $sortOrder, $id]);
    }

    // 删除分组
    public function deleteGroup($id) {
        // 检查是否有测速源使用此分组
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM sources WHERE group_id = ?");
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() > 0) {
            return ['success' => false, 'message' => '该分组下有测速源，无法删除'];
        }

        $stmt = $this->db->prepare("DELETE FROM groups WHERE id = ?");
        $result = $stmt->execute([$id]);
        return ['success' => $result, 'message' => $result ? '删除成功' : '删除失败'];
    }

    // ========== 测速源管理 ==========

    // 获取所有测速源
    public function getSources($enabledOnly = false) {
        $sql = "SELECT s.id, s.name, s.url, s.group_id, g.name as group_name, s.enabled
                FROM sources s
                LEFT JOIN groups g ON s.group_id = g.id";
        if ($enabledOnly) {
            $sql .= " WHERE s.enabled = 1";
        }
        $sql .= " ORDER BY g.sort_order ASC, s.id ASC";
        $stmt = $this->db->query($sql);
        $sources = $stmt->fetchAll();
        foreach ($sources as &$source) {
            $source['enabled'] = (bool)$source['enabled'];
            $source['group'] = $source['group_name'] ?? '未分组';
        }
        return $sources;
    }

    // 获取单个测速源
    public function getSource($id) {
        $stmt = $this->db->prepare("SELECT s.id, s.name, s.url, s.group_id, g.name as group_name, s.enabled
                                    FROM sources s
                                    LEFT JOIN groups g ON s.group_id = g.id
                                    WHERE s.id = ?");
        $stmt->execute([$id]);
        $source = $stmt->fetch();
        if ($source) {
            $source['enabled'] = (bool)$source['enabled'];
            $source['group'] = $source['group_name'] ?? '未分组';
        }
        return $source;
    }

    // 添加测速源
    public function addSource($name, $url, $groupId) {
        $stmt = $this->db->prepare("INSERT INTO sources (name, url, group_id, enabled) VALUES (?, ?, ?, 1)");
        return $stmt->execute([$name, $url, $groupId]);
    }

    // 更新测速源
    public function updateSource($id, $name, $url, $groupId, $enabled) {
        $stmt = $this->db->prepare("UPDATE sources SET name = ?, url = ?, group_id = ?, enabled = ? WHERE id = ?");
        return $stmt->execute([$name, $url, $groupId, $enabled ? 1 : 0, $id]);
    }

    // 删除测速源
    public function deleteSource($id) {
        $stmt = $this->db->prepare("DELETE FROM sources WHERE id = ?");
        return $stmt->execute([$id]);
    }

    // 切换测速源状态
    public function toggleSource($id) {
        $stmt = $this->db->prepare("UPDATE sources SET enabled = NOT enabled WHERE id = ?");
        return $stmt->execute([$id]);
    }

    // 验证管理员登录
    public function verifyAdmin($username, $password) {
        $stmt = $this->db->prepare("SELECT password FROM admin WHERE username = ?");
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        if ($row && password_verify($password, $row['password'])) {
            return true;
        }
        return false;
    }

    // 修改管理员密码
    public function changePassword($username, $oldPassword, $newPassword) {
        if (!$this->verifyAdmin($username, $oldPassword)) {
            return false;
        }
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $this->db->prepare("UPDATE admin SET password = ? WHERE username = ?");
        return $stmt->execute([$hash, $username]);
    }

    // 修改管理员账号信息（用户名和密码）
    public function updateAdminAccount($currentUsername, $oldPassword, $newUsername, $newPassword = null) {
        if (!$this->verifyAdmin($currentUsername, $oldPassword)) {
            return ['success' => false, 'message' => '原密码错误'];
        }

        // 检查新用户名是否已存在（如果用户名有变化）
        if ($newUsername !== $currentUsername) {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM admin WHERE username = ?");
            $stmt->execute([$newUsername]);
            if ($stmt->fetchColumn() > 0) {
                return ['success' => false, 'message' => '用户名已存在'];
            }
        }

        // 更新用户名和密码
        if ($newPassword && strlen($newPassword) >= 6) {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $this->db->prepare("UPDATE admin SET username = ?, password = ? WHERE username = ?");
            $result = $stmt->execute([$newUsername, $hash, $currentUsername]);
        } else {
            // 只更新用户名
            $stmt = $this->db->prepare("UPDATE admin SET username = ? WHERE username = ?");
            $result = $stmt->execute([$newUsername, $currentUsername]);
        }

        return ['success' => $result, 'message' => $result ? '修改成功' : '修改失败'];
    }

    // 获取管理员用户名
    public function getAdminUsername() {
        $stmt = $this->db->query("SELECT username FROM admin LIMIT 1");
        $row = $stmt->fetch();
        return $row ? $row['username'] : 'admin';
    }
}
