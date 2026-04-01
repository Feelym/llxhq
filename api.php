<?php
/**
 * API 接口
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/database.php';

$db = Database::getInstance();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    // 获取设置
    case 'get_settings':
        $settings = $db->getSettings();
        echo json_encode([
            'success' => true,
            'data' => $settings
        ]);
        break;

    // 获取测速源（按分组）
    case 'get_sources':
        $sources = $db->getSources(true); // 只获取启用的

        // 按分组整理
        $groupedSources = [];
        foreach ($sources as $source) {
            $group = $source['group'] ?? '其他';
            if (!isset($groupedSources[$group])) {
                $groupedSources[$group] = [];
            }
            $groupedSources[$group][] = [
                'id' => $source['id'],
                'name' => $source['name'],
                'url' => $source['url']
            ];
        }

        echo json_encode([
            'success' => true,
            'data' => $groupedSources
        ]);
        break;

    // 获取初始化数据（设置 + 测速源）
    case 'init':
        $settings = $db->getSettings();
        $sources = $db->getSources(true);

        // 按分组整理
        $groupedSources = [];
        foreach ($sources as $source) {
            $group = $source['group'] ?? '其他';
            if (!isset($groupedSources[$group])) {
                $groupedSources[$group] = [];
            }
            $groupedSources[$group][] = [
                'id' => $source['id'],
                'name' => $source['name'],
                'url' => $source['url']
            ];
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'settings' => $settings,
                'sources' => $groupedSources
            ]
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => '未知的操作'
        ]);
        break;
}
