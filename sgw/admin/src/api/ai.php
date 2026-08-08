<?php

require_once __DIR__ . '/_auth.php';
require_once dirname(__DIR__) . '/lib/ai_guard.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    json_out([
        'ok' => true,
        'settings' => ai_public_settings(),
        'analysis' => ai_analysis_state(),
    ]);
}
if ($method !== 'POST') json_err('不支持的请求方式', 405);

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$action = trim((string)($body['action'] ?? ''));
try {
    if ($action === 'save') {
        $settings = ai_save_settings($body);
        json_out(['ok' => true, 'settings' => ai_public_settings($settings), 'msg' => 'AI 研判设置已保存']);
    }
    if ($action === 'test') {
        $settings = ai_save_settings($body, false);
        $result = ai_test_connection($settings);
        if (empty($result['ok'])) json_err((string)($result['error'] ?? '连接测试失败'));
        json_out(['ok' => true, 'msg' => 'AI 接口连接正常，点击保存后持久化配置']);
    }
    if ($action === 'analyze') {
        $result = ai_run_analysis(true, trim((string)($body['finding_key'] ?? '')));
        if (empty($result['ok'])) json_err((string)($result['error'] ?? 'AI 研判失败'));
        json_out($result);
    }
    json_err('不支持的操作');
} catch (InvalidArgumentException $e) {
    json_err($e->getMessage());
} catch (Throwable $e) {
    json_err('AI 模块处理失败');
}
