<?php
declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/admin/src/views/dashboard.php');
if ($source === false) {
    fwrite(STDERR, "FAIL: dashboard source could not be read\n");
    exit(1);
}

function ui_check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

ui_check(str_contains($source, 'pendingMutationRequests'), 'mutation request deduplication is missing');
ui_check(str_contains($source, 'beginButtonRequest(button)'), 'request-lifetime button lock is missing');
ui_check(str_contains($source, 'button-request-busy'), 'busy button feedback style is missing');
ui_check(!str_contains($source, '<select class="guard-batch-status"'), 'batch review reverted to a select menu');
ui_check(str_contains($source, 'guard-batch-choice-group'), 'batch review direct choices are missing');
ui_check(str_contains($source, 'async function setGuardReviewStatus'), 'review status does not auto-save');
ui_check(str_contains($source, "body:JSON.stringify({action:'review', key, status, note})"), 'auto-save request payload is missing');
ui_check(str_contains($source, "setReviewSaveState(index, 'saving', '保存中…')"), 'review saving feedback is missing');
ui_check(str_contains($source, "setReviewSaveState(index, 'saved', '已保存')"), 'review saved feedback is missing');
ui_check(str_contains($source, 'button-request-success'), 'successful mutation feedback is missing');
ui_check(str_contains($source, 'button-request-error'), 'failed mutation feedback is missing');
ui_check(str_contains($source, 'buttonPendingLabel(button)'), 'mutation progress labels are missing');
ui_check(str_contains($source, 'actionButtonCandidate === button'), 'clicked mutation button tracking is missing');
ui_check(!str_contains($source, 'queueMicrotask(() => {'), 'mutation button tracking clears before inline handlers run');
ui_check(str_contains($source, 'oninput="markGuardNoteDirty(${index},this)"'), 'review note dirty tracking is missing');
ui_check(str_contains($source, 'disabled>备注已保存</button>'), 'saved review note state is missing');
ui_check(str_contains($source, '.guard-batch-choice-group{grid-template-columns:repeat(2,minmax(0,1fr));grid-column:auto}'), 'narrow mobile batch layout is missing');
ui_check(str_contains($source, 'const dirtyConfigScopes = new Set()'), 'unsaved configuration tracking is missing');
ui_check(str_contains($source, "window.addEventListener('beforeunload'"), 'unsaved changes do not protect page exit');
ui_check(str_contains($source, '自动刷新已暂停'), 'automatic refresh does not pause for unsaved settings');
ui_check(str_contains($source, 'captureDirtyConfigValues()'), 'background refresh cannot preserve unsaved field values');
ui_check(str_contains($source, "markConfigSaved('pull_limit')"), 'pull limit save does not clear its dirty state');
ui_check(str_contains($source, "markConfigSaved('guard')"), 'guard threshold save does not clear its dirty state');
ui_check(str_contains($source, "markConfigSaved('cloud')"), 'cloud provider save does not clear its dirty state');
ui_check(str_contains($source, 'button.config-dirty'), 'unsaved save-button indicator is missing');

echo "dashboard UI contract tests passed\n";
