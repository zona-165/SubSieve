<?php
// 预读取设置，用于表单字段服务端填充
$_preSg = [];
if (file_exists(SETTINGS_JSON)) {
    $_d = @json_decode(@file_get_contents(SETTINGS_JSON), true);
    if (is_array($_d)) $_preSg = $_d;
}
// 从 protect.conf 提取上游配置（若 settings.json 无记录）
if ((empty($_preSg['upstream_url']) || empty($_preSg['subscribe_path'])) && file_exists(PROTECT_CONF)) {
    $_pc = @file_get_contents(PROTECT_CONF);
    if ($_pc !== false) {
        if (empty($_preSg['upstream_url'])) {
            if (preg_match('/set\s+\$upstream_backend\s+(\S+);/m', $_pc, $_m)) {
                $_preSg['upstream_url'] = rtrim($_m[1], ';');
            } elseif (preg_match('/proxy_pass\s+(\S+);/m', $_pc, $_m)) {
                $_v = rtrim($_m[1], ';');
                if (!str_starts_with($_v, '$')) $_preSg['upstream_url'] = $_v;
            }
        }
        if (empty($_preSg['subscribe_path']) && preg_match('/^location\s+\^~\s+(\S+)/m', $_pc, $_m))
            $_preSg['subscribe_path'] = $_m[1];
    }
}
// 若 upstream_url 无显式端口，从 protect.conf 的 set $upstream_backend 行补全端口
if (!empty($_preSg['upstream_url']) && !parse_url($_preSg['upstream_url'], PHP_URL_PORT) && file_exists(PROTECT_CONF)) {
    $_cr2 = @file_get_contents(PROTECT_CONF);
    if ($_cr2 && preg_match('/set\s+\$upstream_backend\s+(\S+);/m', $_cr2, $_cm2)) {
        $_cp2 = parse_url(rtrim($_cm2[1], ';'), PHP_URL_PORT);
        if ($_cp2) {
            $_sp2 = parse_url($_preSg['upstream_url']);
            $_preSg['upstream_url'] = ($_sp2['scheme'] ?? 'https') . '://' . ($_sp2['host'] ?? '') . ':' . $_cp2;
        }
    }
}
// 分离 upstream_url 中的端口，用于端口输入框单独显示
$_preSgPort = 443;
$_preSgUrlClean = $_preSg['upstream_url'] ?? '';
if (!empty($_preSg['upstream_url'])) {
    $_p = parse_url($_preSg['upstream_url']);
    $_scheme = $_p['scheme'] ?? 'https';
    if (isset($_p['port'])) {
        $_preSgPort = $_p['port'];
        $_preSgUrlClean = $_scheme . '://' . ($_p['host'] ?? '');
    } else {
        $_preSgPort = ($_scheme === 'http') ? 80 : 443;
    }
}
function _val(string $v): string { return htmlspecialchars($v, ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars(PAGE_TITLE, ENT_QUOTES) ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0f1117;--bg2:#13161f;--bg3:#1a1d2e;--bg-input:#0f1117;
  --border:#1e2236;--border2:#2d3144;
  --text:#e2e8f0;--text2:#94a3b8;--text3:#64748b;
  --accent:#6366f1;
  --motion-fast:140ms;--motion-med:240ms;
}
[data-theme="light"]{
  --bg:#f0f2f5;--bg2:#ffffff;--bg3:#ffffff;--bg-input:#f8fafc;
  --border:#e2e8f0;--border2:#cbd5e1;
  --text:#1e293b;--text2:#475569;--text3:#94a3b8;
  --accent:#6366f1;
}
[data-theme="dark"] .stats-card{box-shadow:0 12px 30px rgba(0,0,0,.22)}
[data-theme="dark"] .tone-blue{--tone-bg:rgba(37,99,235,.16);--tone-border:rgba(37,99,235,.28)}
[data-theme="dark"] .tone-violet{--tone-bg:rgba(124,58,237,.16);--tone-border:rgba(124,58,237,.28)}
[data-theme="dark"] .tone-amber{--tone-bg:rgba(217,119,6,.16);--tone-border:rgba(217,119,6,.30)}
[data-theme="dark"] .tone-rose{--tone-bg:rgba(225,29,72,.16);--tone-border:rgba(225,29,72,.30)}
[data-theme="dark"] .tone-cyan{--tone-bg:rgba(8,145,178,.16);--tone-border:rgba(8,145,178,.30)}
[data-theme="dark"] .tone-emerald{--tone-bg:rgba(5,150,105,.16);--tone-border:rgba(5,150,105,.30)}
[data-theme="dark"] .tone-sky{--tone-bg:rgba(2,132,199,.16);--tone-border:rgba(2,132,199,.30)}
body{background:var(--bg);color:var(--text);font:14px/1.5 system-ui,sans-serif;display:flex;min-height:100vh}

/* Sidebar */
.sidebar{width:200px;background:linear-gradient(180deg,var(--bg2),color-mix(in srgb,var(--bg2) 88%,var(--accent) 12%));border-right:1px solid var(--border);flex-shrink:0;display:flex;flex-direction:column;padding:20px 12px;box-shadow:8px 0 30px rgba(15,23,42,.04)}
.logo{font-size:15px;font-weight:600;color:var(--text);padding:8px 10px 24px}
.logo span{color:var(--accent)}
.nav-item{position:relative;display:flex;align-items:center;gap:10px;padding:10px 11px;border-radius:10px;cursor:pointer;color:var(--text3);font-size:13px;font-weight:600;transition:all .15s;border:1px solid transparent;background:transparent;width:100%;text-align:left;overflow:hidden}
.nav-item::before{content:"";position:absolute;inset:9px auto 9px 0;width:3px;border-radius:0 999px 999px 0;background:var(--accent);opacity:0;transition:opacity .15s}
.nav-item:hover{background:rgba(99,102,241,.08);border-color:rgba(99,102,241,.10);color:var(--text)}
.nav-item.active{background:linear-gradient(135deg,rgba(99,102,241,.14),rgba(8,145,178,.08));border-color:rgba(99,102,241,.18);color:var(--accent);box-shadow:0 10px 22px rgba(99,102,241,.10)}
.nav-item.active::before{opacity:1}
.nav-item:active,.mode-btn:active,.refresh-btn:active,.theme-btn:active,.btn-primary:active,.add-btn-sm:active,.copy-btn:active{transform:scale(.97)}
.nav-icon{font-size:15px;width:26px;height:26px;border-radius:8px;display:grid;place-items:center;background:rgba(100,116,139,.10);text-align:center;transition:all .15s}
.nav-item:hover .nav-icon,.nav-item.active .nav-icon{background:rgba(99,102,241,.13);color:var(--accent)}
.sidebar-bottom{margin-top:auto}
.logout{color:#ef4444!important}
.logout:hover{background:rgba(239,68,68,.1)!important}

/* Main */
.main{flex:1;display:flex;flex-direction:column;min-width:0}
.topbar{background:var(--bg2);border-bottom:1px solid var(--border);padding:14px 24px;display:flex;align-items:center;justify-content:space-between}
.topbar-title{font-size:15px;font-weight:600}
.topbar-right{display:flex;align-items:center;gap:12px}
.status-dot{width:8px;height:8px;background:#22c55e;border-radius:50%;display:inline-block}
.status-text{color:var(--text3);font-size:12px}
.refresh-btn{background:var(--border);border:1px solid var(--border2);color:var(--text2);padding:6px 14px;border-radius:8px;cursor:pointer;font-size:12px;transition:all .15s}
.refresh-btn:hover{border-color:var(--accent);color:var(--accent)}
/* 主题切换按钮 */
.theme-btn{background:var(--border);border:1px solid var(--border2);color:var(--text2);padding:6px 12px;border-radius:8px;cursor:pointer;font-size:12px;transition:all .15s;white-space:nowrap}
.theme-btn:hover{border-color:var(--accent);color:var(--accent)}

/* Content */
.content{padding:24px;flex:1;overflow:auto;background:linear-gradient(135deg,rgba(99,102,241,.035),transparent 28%),linear-gradient(315deg,rgba(8,145,178,.035),transparent 32%)}
.tab-panel{display:none}
.tab-panel{min-width:0}
.tab-panel.active{display:block}
.tab-panel.active{animation:panelIn var(--motion-med) ease both}

/* Cards */
.card{position:relative;max-width:100%;background:linear-gradient(180deg,var(--bg3),color-mix(in srgb,var(--bg3) 94%,var(--accent) 6%));border:1px solid color-mix(in srgb,var(--border) 82%,var(--accent) 18%);border-radius:12px;padding:20px;margin-bottom:16px;box-shadow:0 14px 34px rgba(15,23,42,.06);overflow:hidden}
.card{animation:itemIn var(--motion-med) ease both}
.card::before{content:"";position:absolute;inset:0 0 auto 0;height:3px;background:linear-gradient(90deg,rgba(99,102,241,.85),rgba(8,145,178,.65),transparent)}
.card-title{position:relative;display:flex;align-items:center;gap:8px;font-size:13px;font-weight:800;color:var(--text);margin-bottom:14px;text-transform:none;letter-spacing:0}
.card-title::before{content:"";width:8px;height:8px;border-radius:999px;background:linear-gradient(135deg,#6366f1,#0891b2);box-shadow:0 0 0 4px rgba(99,102,241,.10);flex-shrink:0}

/* Log panel */
.log-controls{display:flex;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap;padding:10px;border:1px solid var(--border);border-radius:12px;background:rgba(100,116,139,.06)}
.alert-history-search{display:flex;gap:8px;align-items:center;margin-bottom:4px}
.alert-history-search .ip-input{flex:1;min-width:0;height:34px;font-size:12px}
.alert-history-search .mode-btn{height:34px;padding:0 10px;font-size:12px}
.alert-history-row{display:grid;grid-template-columns:auto 1fr auto auto;gap:8px;padding:8px 0;border-top:1px solid var(--border)}
.alert-history-action{align-self:start}
.alert-history-filters{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 8px}
.alert-history-chip{display:inline-flex;align-items:center;max-width:100%;padding:3px 8px;border:1px solid rgba(99,102,241,.18);border-radius:999px;background:rgba(99,102,241,.08);color:var(--text2);font-size:11px;font-weight:700;line-height:1.4}
.alert-history-chip-btn{cursor:pointer;font:inherit}
.alert-history-chip-btn:hover{border-color:var(--accent);color:var(--accent);background:rgba(99,102,241,.13)}
.alert-history-chip span{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.log-filter{background:var(--bg-input);border:1px solid var(--border2);color:var(--text);padding:8px 12px;border-radius:9px;font-size:12px;outline:none;width:160px;transition:all .15s}
.log-filter:focus{border-color:var(--accent)}
.log-filter:focus,.ip-input:focus,.comment-input:focus{box-shadow:0 0 0 3px rgba(99,102,241,.12)}
.log-mode-btns{display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap;align-items:center}
.mode-btn{background:linear-gradient(180deg,var(--border),color-mix(in srgb,var(--border) 82%,var(--bg3) 18%));border:1px solid var(--border2);color:var(--text2);padding:6px 14px;border-radius:9px;cursor:pointer;font-size:12px;font-weight:700;transition:all .15s}
.mode-btn:hover{border-color:var(--accent);color:var(--accent)}
.mode-btn.active{background:linear-gradient(135deg,#6366f1,#4f46e5);border-color:rgba(99,102,241,.85);color:#fff;box-shadow:0 9px 20px rgba(99,102,241,.22)}
.mode-btn.danger{border-color:rgba(239,68,68,.3);color:#ef4444}
.mode-btn.danger:hover{background:rgba(239,68,68,.15)}
.mode-btn.import-btn{border-color:rgba(99,102,241,.3);color:var(--accent)}
.mode-btn.import-btn:hover{background:rgba(99,102,241,.15)}
.radio-group{display:flex;align-items:center;gap:14px;margin-left:auto}
.radio-group label{display:flex;align-items:center;gap:5px;color:var(--text2);font-size:12px;cursor:pointer;white-space:nowrap}
.radio-group input[type=radio]{accent-color:var(--accent)}
.badge{display:inline-flex;align-items:center;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:800}
.badge-200{background:rgba(34,197,94,.12);color:#22c55e}
.badge-403{background:rgba(239,68,68,.12);color:#ef4444}
.badge-429{background:rgba(234,179,8,.12);color:#eab308}
.badge-444{background:rgba(100,116,139,.12);color:#64748b}
.badge-other{background:rgba(99,102,241,.12);color:#6366f1}
.log-table-wrap,.table-wrap{width:100%;max-width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch;border:1px solid var(--border);border-radius:12px;background:rgba(100,116,139,.045);padding:0 10px}
.table-wrap{margin-top:10px}
.table-wrap table{min-width:680px}
table{width:100%;border-collapse:collapse;font-size:12px}
th{text-align:left;padding:10px;color:var(--text3);border-bottom:1px solid var(--border);position:sticky;top:0;background:color-mix(in srgb,var(--bg3) 92%,var(--accent) 8%);white-space:nowrap;z-index:1}
td{padding:7px 10px;border-bottom:1px solid var(--bg);vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:hover td{background:rgba(99,102,241,.055)}
.ip-cell{font-family:monospace;font-size:11px;white-space:nowrap}
.ua-cell{max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text3);font-size:11px}
.req-cell{max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:11px;color:var(--text2)}
.token-cell{font-family:monospace;font-size:11px;color:#818cf8;display:flex;align-items:center;gap:6px;min-width:0}
.token-text{word-break:break-all;flex:1}
.auto-timer{color:var(--text3);font-size:11px}
.copy-btn{background:none;border:1px solid var(--border2);color:var(--text3);padding:1px 6px;border-radius:4px;cursor:pointer;font-size:10px;flex-shrink:0;transition:all .15s}
.copy-btn:hover{border-color:var(--accent);color:var(--accent)}

/* Stats */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px}
.stats-overview{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}
.stats-card{position:relative;overflow:hidden;background:linear-gradient(135deg,var(--tone-bg),var(--bg3) 58%);border:1px solid var(--tone-border);border-radius:10px;padding:16px;cursor:pointer;transition:all .15s;min-height:128px;text-align:left;color:var(--text);font:inherit;box-shadow:0 10px 28px rgba(15,23,42,.06)}
.stats-card::before{content:"";position:absolute;inset:0 auto 0 0;width:4px;background:var(--tone);opacity:.9}
.stats-card::after{content:"";position:absolute;right:-28px;top:-28px;width:96px;height:96px;border-radius:50%;background:var(--tone);opacity:.10;pointer-events:none}
.stats-card:hover{border-color:var(--tone);transform:translateY(-2px);box-shadow:0 14px 34px rgba(15,23,42,.14)}
.stats-card:active{transform:translateY(0) scale(.99)}
.stats-card-title{display:flex;align-items:center;justify-content:space-between;color:var(--text2);font-size:12px;font-weight:700;margin-bottom:10px}
.stats-card-kicker{display:flex;align-items:center;gap:8px}
.stats-card-icon{width:28px;height:28px;border-radius:8px;display:grid;place-items:center;background:var(--tone-soft);color:var(--tone);font-size:15px}
.stats-card-action{color:var(--tone);font-weight:700}
.stats-card-main{font-size:24px;font-weight:700;color:var(--text);line-height:1.2}
.stats-card-sub{margin-top:8px;color:var(--text3);font-size:12px;line-height:1.45}
.tone-blue{--tone:#2563eb;--tone-soft:rgba(37,99,235,.12);--tone-bg:rgba(37,99,235,.08);--tone-border:rgba(37,99,235,.20)}
.tone-violet{--tone:#7c3aed;--tone-soft:rgba(124,58,237,.12);--tone-bg:rgba(124,58,237,.08);--tone-border:rgba(124,58,237,.20)}
.tone-amber{--tone:#d97706;--tone-soft:rgba(217,119,6,.13);--tone-bg:rgba(217,119,6,.08);--tone-border:rgba(217,119,6,.22)}
.tone-rose{--tone:#e11d48;--tone-soft:rgba(225,29,72,.12);--tone-bg:rgba(225,29,72,.08);--tone-border:rgba(225,29,72,.22)}
.tone-cyan{--tone:#0891b2;--tone-soft:rgba(8,145,178,.13);--tone-bg:rgba(8,145,178,.08);--tone-border:rgba(8,145,178,.22)}
.tone-emerald{--tone:#059669;--tone-soft:rgba(5,150,105,.13);--tone-bg:rgba(5,150,105,.08);--tone-border:rgba(5,150,105,.22)}
.tone-sky{--tone:#0284c7;--tone-soft:rgba(2,132,199,.13);--tone-bg:rgba(2,132,199,.08);--tone-border:rgba(2,132,199,.22)}
.stats-detail-head{position:relative;display:none;align-items:center;gap:12px;margin-bottom:14px;padding:12px 14px;border:1px solid var(--tone-border,var(--border));border-radius:12px;background:linear-gradient(135deg,var(--tone-bg,rgba(99,102,241,.08)),var(--bg3) 68%);overflow:hidden;box-shadow:0 10px 26px rgba(15,23,42,.06)}
.stats-detail-head::before{content:"";position:absolute;inset:0 auto 0 0;width:4px;background:var(--tone,var(--accent))}
.stats-detail-title{font-size:16px;font-weight:800;color:var(--text);line-height:1.25}
.stats-back-btn{background:var(--tone-soft,rgba(99,102,241,.12));border-color:var(--tone-border,rgba(99,102,241,.24));color:var(--tone,var(--accent));font-weight:700}
.stats-back-btn:hover{background:var(--tone-soft,rgba(99,102,241,.12));border-color:var(--tone,var(--accent));color:var(--tone,var(--accent))}
.stats-detail-grid{display:none}
.stats-detail-grid.active{display:grid;grid-template-columns:1fr}
.stats-detail-grid.active{animation:slideIn var(--motion-med) ease both}
.stats-detail-card{display:none}
.stats-detail-card.active{position:relative;display:block;border-color:var(--tone-border,var(--border));background:linear-gradient(180deg,var(--bg3),color-mix(in srgb,var(--bg3) 92%,var(--tone,var(--accent)) 8%));box-shadow:0 16px 36px rgba(15,23,42,.08);overflow:hidden}
.stats-detail-card.active::before{content:"";position:absolute;inset:0 0 auto 0;height:3px;background:linear-gradient(90deg,var(--tone,var(--accent)),transparent)}
.stats-detail-card.active{animation:itemIn var(--motion-med) ease both}
.stats-detail-card > div:first-child{gap:10px;flex-wrap:wrap}
.stats-detail-card .card-title{font-size:14px;color:var(--text);letter-spacing:0;text-transform:none}
.stats-detail-card .mode-btn.active{background:var(--tone,var(--accent));border-color:var(--tone,var(--accent));box-shadow:0 8px 18px color-mix(in srgb,var(--tone,var(--accent)) 22%,transparent)}
.top-row{display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid #13161f}
.top-row{animation:listIn var(--motion-med) ease both}
.top-row:last-child{border-bottom:none}
.top-rank{color:#64748b;font-size:11px;width:18px}
.top-val{font-family:monospace;font-size:12px;flex:1;padding:0 10px;word-break:break-all}
.top-count{color:#6366f1;font-size:12px;font-weight:600;white-space:nowrap}
.top-sub{color:#64748b;font-size:11px}
.stats-detail-card .top-row{position:relative;margin-bottom:8px;padding:11px 12px;border:1px solid var(--border);border-radius:10px;background:linear-gradient(135deg,rgba(100,116,139,.08),var(--bg3));transition:all .15s}
.stats-detail-card .top-row:hover{border-color:var(--tone-border,var(--border2));box-shadow:0 10px 22px rgba(15,23,42,.08);transform:translateY(-1px)}
.stats-detail-card .top-row:last-child{border-bottom:1px solid var(--border)}
.stats-detail-card .top-rank{width:26px;height:26px;border-radius:999px;display:grid;place-items:center;background:var(--tone-soft,rgba(99,102,241,.12));color:var(--tone,var(--accent));font-weight:700;flex-shrink:0}
.stats-detail-card .top-val{font-size:12px;color:var(--text);line-height:1.45}
.stats-detail-card .top-count{display:inline-flex;align-items:center;justify-content:center;min-height:24px;padding:2px 9px;border-radius:999px;background:var(--tone-soft,rgba(99,102,241,.12));color:var(--tone,var(--accent))}
.stats-detail-card .page-controls{align-items:center;gap:8px;flex-wrap:wrap}
.stats-detail-card .page-controls .mode-btn{border-radius:9px;font-weight:700}
.add-btn-sm{background:#6366f1;color:#fff;border:none;padding:3px 10px;border-radius:5px;cursor:pointer;font-size:11px;margin-left:8px;transition:opacity .15s;flex-shrink:0}
.add-btn-sm:hover{opacity:.8}
.risk-row{display:block;padding:9px 0}
.risk-main{display:grid;grid-template-columns:minmax(92px,1fr) auto;gap:8px;align-items:start}
.risk-ip{font-family:monospace;font-size:12px;font-weight:600;color:var(--text);word-break:normal;overflow-wrap:anywhere;line-height:1.35}
.risk-meta{display:flex;align-items:center;gap:6px;flex-wrap:wrap;justify-content:flex-end}
.risk-badge{font-size:11px;font-weight:700;white-space:nowrap}
.risk-actions{display:flex;gap:5px;justify-content:flex-end}
.risk-actions .add-btn-sm{margin-left:0;padding:3px 9px}
.risk-detail{margin-top:7px;border:1px solid var(--border);border-radius:8px;background:rgba(100,116,139,.10);padding:0 9px}
.risk-detail summary{cursor:pointer;color:var(--text3);font-size:11px;padding:7px 0;list-style:none}
.risk-detail summary::-webkit-details-marker{display:none}
.risk-evidence{font-size:11px;color:var(--text2);line-height:1.65;padding:0 0 8px}
.risk-samples{margin-top:5px;color:var(--text3);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.risk-samples code{font-size:10px;color:#93c5fd}
.scanner-report{border:1px solid var(--border);border-radius:8px;padding:10px;margin-bottom:10px;background:rgba(100,116,139,.08)}
.scanner-report{animation:listIn var(--motion-med) ease both}
.scanner-report pre{white-space:pre-wrap;word-break:break-word;color:var(--text2);font:11px/1.55 ui-monospace,SFMono-Regular,Menlo,monospace;margin-top:8px}
.scanner-actions{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.profile-segment{border:1px solid var(--border);border-radius:12px;background:rgba(100,116,139,.07);padding:14px;margin-bottom:12px;overflow:hidden}
.profile-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:12px;flex-wrap:wrap}
.profile-range{font-weight:800;color:var(--text);font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
.profile-meta{color:var(--text3);font-size:12px}
.profile-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(22px,1fr));gap:7px;max-width:760px}
.profile-cell{height:22px;border-radius:6px;display:grid;place-items:center;color:#fff;font-size:11px;font-weight:800;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;box-shadow:inset 0 -1px 0 rgba(0,0,0,.15)}
.profile-n{background:#10b981}
.profile-p{background:#f97316}
.profile-b{background:#ef4444}
.profile-o{background:#f43f5e}
.profile-v{background:#6366f1}
.profile-t{background:#a855f7}
.profile-legend{display:flex;gap:14px;flex-wrap:wrap;margin-top:12px;padding-top:10px;border-top:1px solid var(--border);color:var(--text2);font-size:12px}
.profile-dot{display:inline-block;width:9px;height:9px;border-radius:999px;margin-right:5px;vertical-align:-1px}

/* Whitelist / Blacklist / UA */
.ip-form{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;padding:10px;border:1px solid var(--border);border-radius:12px;background:rgba(100,116,139,.055)}
.ip-input{background:var(--bg-input);border:1px solid var(--border2);color:var(--text);padding:10px 12px;border-radius:9px;font-size:13px;font-family:monospace;outline:none;flex:1;min-width:160px;transition:all .15s}
.ip-input:focus{border-color:var(--accent)}
.comment-input{background:var(--bg-input);border:1px solid var(--border2);color:var(--text);padding:10px 12px;border-radius:9px;font-size:13px;outline:none;flex:2;min-width:140px;transition:all .15s}
.comment-input:focus{border-color:var(--accent)}
.btn-primary{background:linear-gradient(135deg,#6366f1,#0891b2);color:#fff;border:none;padding:10px 18px;border-radius:9px;cursor:pointer;font-size:13px;font-weight:800;transition:all .15s;white-space:nowrap;box-shadow:0 10px 22px rgba(99,102,241,.18)}
.btn-primary:hover{filter:saturate(1.08);transform:translateY(-1px)}
.btn-danger{background:rgba(239,68,68,.15);color:#ef4444;border:1px solid rgba(239,68,68,.2);padding:5px 12px;border-radius:6px;cursor:pointer;font-size:12px;transition:all .15s}
.btn-danger:hover{background:rgba(239,68,68,.25)}
.btn-apply{background:rgba(34,197,94,.12);color:#22c55e;border:1px solid rgba(34,197,94,.2);padding:7px 16px;border-radius:8px;cursor:pointer;font-size:13px;transition:all .15s}
.btn-apply:hover{background:rgba(34,197,94,.2)}
.apply-row{display:flex;align-items:center;gap:12px;margin-bottom:14px;padding:10px 12px;border:1px solid var(--border);border-radius:12px;background:rgba(100,116,139,.055)}
.apply-hint{display:block;padding:9px 11px;border:1px solid var(--border);border-radius:10px;background:rgba(100,116,139,.07);line-height:1.55}
.apply-hint{color:var(--text3);font-size:12px}

/* Toast */
#toast{position:fixed;bottom:28px;right:28px;background:var(--bg3);border:1px solid var(--border2);padding:12px 20px;border-radius:10px;font-size:13px;z-index:999;opacity:0;transform:translateY(10px);transition:all .25s;pointer-events:none}
#toast.show{opacity:1;transform:none}
#toast.ok{border-color:#22c55e;color:#22c55e}
#toast.err{border-color:#ef4444;color:#ef4444}

.empty{color:var(--text3);font-size:13px;padding:20px 0}
.loading{color:var(--text3);font-size:13px}

/* 黑名单标签按钮 */
.bl-badge-btn{background:rgba(239,68,68,.15);color:#ef4444;border:1px solid rgba(239,68,68,.3);padding:2px 7px;border-radius:5px;cursor:pointer;font-size:10px;transition:all .15s;flex-shrink:0}
.bl-badge-btn:hover{background:rgba(239,68,68,.3)}
/* 白名单标签按钮 */
.wl-badge-btn{background:rgba(34,197,94,.15);color:#22c55e;border:1px solid rgba(34,197,94,.3);padding:2px 7px;border-radius:5px;cursor:pointer;font-size:10px;transition:all .15s;flex-shrink:0}
.wl-badge-btn:hover{background:rgba(34,197,94,.3)}
/* 请求/UA 单元格（带复制按钮） */
.req-cell-wrap{display:flex;align-items:center;gap:4px;max-width:260px}
.ua-cell-wrap{display:flex;align-items:center;gap:4px;max-width:220px}
/* 分页控件 */
.page-controls{display:flex;align-items:center;gap:8px;margin-top:10px;flex-wrap:wrap}
/* 批量操作行 */
.batch-row{display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap}
.batch-row label{color:var(--text2);font-size:12px;display:flex;align-items:center;gap:5px;cursor:pointer}
/* IDC 汇总区域 */
.idc-section{margin-top:20px;padding-top:16px;border-top:1px solid var(--border)}
.idc-section .card-title{margin-bottom:10px}

@keyframes panelIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
@keyframes itemIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
@keyframes listIn{from{opacity:0;transform:translateX(6px)}to{opacity:1;transform:none}}
@keyframes slideIn{from{opacity:0;transform:translateX(10px)}to{opacity:1;transform:none}}
tbody tr{animation:listIn var(--motion-med) ease both}
tbody tr:nth-child(2),.top-row:nth-child(2),.scanner-report:nth-child(2),.stats-card:nth-child(2){animation-delay:25ms}
tbody tr:nth-child(3),.top-row:nth-child(3),.scanner-report:nth-child(3),.stats-card:nth-child(3){animation-delay:50ms}
tbody tr:nth-child(4),.top-row:nth-child(4),.scanner-report:nth-child(4),.stats-card:nth-child(4){animation-delay:75ms}
tbody tr:nth-child(5),.top-row:nth-child(5),.scanner-report:nth-child(5),.stats-card:nth-child(5){animation-delay:100ms}
tbody tr:nth-child(n+6),.top-row:nth-child(n+6),.scanner-report:nth-child(n+6),.stats-card:nth-child(n+6){animation-delay:120ms}

@media (prefers-reduced-motion: reduce) {
  *,*::before,*::after{animation:none!important;transition:none!important;scroll-behavior:auto!important}
}

@media (max-width: 760px) {
  body{display:block;min-height:100vh;overflow-x:hidden}
  .sidebar{position:sticky;top:0;z-index:20;width:100%;height:auto;border-right:none;border-bottom:1px solid var(--border);padding:8px;background:var(--bg2);display:flex;flex-direction:row;align-items:center;gap:6px;overflow-x:auto;-webkit-overflow-scrolling:touch}
  .logo{flex:0 0 auto;padding:7px 10px;font-size:13px;white-space:nowrap}
  .nav-item{flex:0 0 auto;width:auto;padding:7px 10px;gap:5px;font-size:12px;white-space:nowrap}
  .nav-icon{width:24px;height:24px;font-size:14px;flex-shrink:0}
  .sidebar-bottom{margin-top:0;margin-left:auto;display:flex}
  .main{min-width:0}
  .topbar{position:sticky;top:49px;z-index:15;padding:10px 12px;align-items:flex-start;gap:8px;flex-wrap:wrap}
  .topbar-title{font-size:14px;line-height:30px}
  .topbar-right{margin-left:auto;gap:6px;flex-wrap:wrap;justify-content:flex-end}
  .status-text{font-size:11px}
  .refresh-btn,.theme-btn{padding:5px 9px;font-size:11px}
  .content{padding:10px;overflow:visible}
  .card{padding:12px;border-radius:8px;margin-bottom:10px}
  .card-title{font-size:12px;margin-bottom:10px}
  .log-mode-btns,.log-controls,.page-controls,.batch-row{gap:6px}
  .log-mode-btns .mode-btn,.log-controls .mode-btn{flex:1 1 calc(50% - 6px);padding:7px 8px}
  .alert-history-search{display:grid;grid-template-columns:1fr 1fr;gap:6px}
  .alert-history-search .ip-input{grid-column:1 / -1;height:36px}
  .alert-history-search .mode-btn{height:34px;width:100%;padding:0 8px}
  .alert-history-row{grid-template-columns:auto 1fr;gap:7px 8px;padding:10px 0}
  .alert-history-row .copy-btn{width:100%;min-height:30px}
  .alert-history-action{grid-column:auto;align-self:stretch}
  .alert-history-chip{max-width:100%}
  .log-filter{width:auto;flex:1 1 calc(50% - 6px);min-width:138px}
  .radio-group{width:100%;margin-left:0;gap:10px;align-items:flex-start;flex-wrap:wrap}
  #active-subscribe-path{width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .log-table-wrap,.table-wrap{width:100%;max-width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch;border:1px solid var(--border);border-radius:8px;padding:0 8px}
  .log-table-wrap table{min-width:920px}
  .table-wrap table{min-width:640px}
  th,td{padding:7px 8px}
  .req-cell-wrap{max-width:220px}
  .ua-cell-wrap{max-width:190px}
  .stats-grid{grid-template-columns:1fr!important;gap:10px}
  .stats-overview{grid-template-columns:1fr;gap:10px}
  .stats-card{min-height:auto;padding:14px}
  .stats-detail-head{flex-wrap:wrap;padding:11px 12px}
  .stats-detail-title{font-size:15px}
  .stats-grid .card > div:first-child{gap:8px;align-items:flex-start!important;flex-wrap:wrap}
  .stats-grid .card > div:first-child > div:last-child{display:flex;gap:4px;flex-wrap:wrap}
  .mode-btn{padding:6px 10px}
  .top-row{align-items:flex-start;gap:6px}
  .top-rank{flex:0 0 16px}
  .top-val{padding:0 4px;min-width:0}
  .token-cell{min-width:0}
  .token-text{overflow:hidden;text-overflow:ellipsis;display:block}
  .risk-main{grid-template-columns:1fr;gap:5px}
  .risk-meta,.risk-actions{justify-content:flex-start}
  .risk-detail{padding:0 8px}
  .ip-form{display:grid;grid-template-columns:1fr;gap:8px}
  .ip-input,.comment-input,.btn-primary{width:100%;min-width:0}
  .apply-row{align-items:flex-start;flex-wrap:wrap}
  #panel-settings .stats-grid{grid-template-columns:1fr!important}
  #panel-settings .card [style*="display:flex;gap:8px;align-items:flex-end"]{display:grid!important;grid-template-columns:1fr!important;gap:8px!important}
  #toast{left:10px;right:10px;bottom:12px;text-align:center}
}
</style>
</head>
<body>

<nav class="sidebar">
  <div class="logo"><?= htmlspecialchars(SITE_TITLE, ENT_QUOTES) ?></div>
  <button class="nav-item active" onclick="switchTab('logs',this)">
    <span class="nav-icon">📋</span>日志
  </button>
  <button class="nav-item" onclick="switchTab('stats',this)">
    <span class="nav-icon">📊</span>分析
  </button>
  <button class="nav-item" onclick="switchTab('ua_blacklist',this)">
    <span class="nav-icon">🛡</span>UA
  </button>
  <button class="nav-item" onclick="switchTab('whitelist',this)">
    <span class="nav-icon">✅</span>IP白名单
  </button>
  <button class="nav-item" onclick="switchTab('blacklist',this)">
    <span class="nav-icon">🚫</span>IP黑名单
  </button>
  <button class="nav-item" onclick="switchTab('token_blacklist',this)">
    <span class="nav-icon">🔑</span>Token黑名单
  </button>
  <button class="nav-item" onclick="switchTab('settings',this)">
    <span class="nav-icon">⚙</span>系统设置
  </button>
  <div class="sidebar-bottom">
    <a href="<?= ADMIN_SECRET_PATH !== '' ? '/' . ADMIN_SECRET_PATH . '/logout' : '/logout' ?>" style="text-decoration:none">
      <button class="nav-item logout"><span class="nav-icon">↩</span>退出</button>
    </a>
  </div>
</nav>

<div class="main">
  <div class="topbar">
    <div class="topbar-title" id="tab-title">日志</div>
    <div class="topbar-right">
      <span class="status-dot"></span>
      <span class="status-text">运行中</span>
      <span class="status-text auto-timer" id="auto-timer"></span>
      <button class="refresh-btn" onclick="manualRefresh()">手动刷新</button>
      <button class="theme-btn" id="theme-btn" onclick="cycleTheme()" title="切换主题">🌙 深色</button>
    </div>
  </div>

  <div class="content">

    <!-- ─── 日志 ─────────────────────────────────────────── -->
    <div class="tab-panel active" id="panel-logs">
      <div class="card">
        <!-- 日志模式切换 -->
        <div class="log-mode-btns">
          <button class="mode-btn active" id="btn-today" onclick="setLogMode('today')">仅显示今日日志</button>
          <button class="mode-btn" id="btn-all" onclick="setLogMode('all')">显示全部日志</button>
          <button class="mode-btn danger" onclick="deleteLogs()">删除7日前的日志</button>
          <button class="mode-btn danger" onclick="deleteAllLogs()">删除当前所有日志</button>
          <button class="mode-btn import-btn" onclick="document.getElementById('log-import-file').click()">导入日志</button>
          <button class="mode-btn import-btn" onclick="exportLogs()">导出日志</button>
          <input type="file" id="log-import-file" accept=".log,.txt" style="display:none" onchange="importLogs(this)">
        </div>
        <!-- 过滤器 -->
        <div class="log-controls">
          <input class="log-filter" id="filter-ip" placeholder="过滤 IP" oninput="logPage=1;renderLogs()">
          <input class="log-filter" id="filter-status" placeholder="状态码 如 403" oninput="logPage=1;renderLogs()">
          <input class="log-filter" id="filter-token" placeholder="过滤 Token（自动去重）" oninput="logPage=1;renderLogs()">
          <input class="log-filter" id="filter-ua" placeholder="过滤 UA（不分大小写）" oninput="logPage=1;renderLogs()">
          <span class="auto-timer" id="log-count">—</span>
          <div class="radio-group">
            <label><input type="radio" name="sub-filter" value="subscribe" checked onchange="logPage=1;renderLogs()"> 仅订阅相关</label>
            <label><input type="radio" name="sub-filter" value="all" onchange="logPage=1;renderLogs()"> 显示全部</label>
            <span id="active-subscribe-path" class="top-sub"></span>
          </div>
          <div style="display:flex;gap:4px;margin-left:8px">
            <button class="mode-btn active" id="limit-btn-50"  onclick="setLogLimit(50)">50条</button>
            <button class="mode-btn" id="limit-btn-100" onclick="setLogLimit(100)">100条</button>
            <button class="mode-btn" id="limit-btn-500" onclick="setLogLimit(500)">500条</button>
            <button class="mode-btn" id="limit-btn-inf" onclick="setLogLimit(0)">瀑布流</button>
          </div>
        </div>
        <div class="log-table-wrap">
          <table>
            <thead>
              <tr>
                <th>时间</th><th>IP</th><th style="color:#64748b;font-weight:400;font-size:11px" title="显示该IP在白/黑名单中的备注，如需修改请前往对应管理页">备注 <span style="opacity:.6">（只读）</span></th><th>状态</th><th>Token</th>
                <th>请求</th><th>UA</th>
              </tr>
            </thead>
            <tbody id="log-tbody"><tr><td colspan="7" class="loading">加载中…</td></tr></tbody>
          </table>
        </div>
        <!-- 分页控件（瀑布流模式下隐藏） -->
        <div id="log-pagination" class="page-controls" style="display:none;margin-top:10px;align-items:center;gap:8px;flex-wrap:wrap">
          <button class="mode-btn" id="page-prev" onclick="changePage(-1)">上一页</button>
          <span id="page-info" style="color:var(--text2);font-size:12px;white-space:nowrap"></span>
          <button class="mode-btn" id="page-next" onclick="changePage(1)">下一页</button>
          <span style="color:var(--text3);font-size:12px;margin-left:8px">跳至</span>
          <input id="page-jump" type="number" min="1" style="width:52px;background:var(--bg-input);border:1px solid var(--border2);color:var(--text);padding:4px 6px;border-radius:6px;font-size:12px;outline:none" onkeydown="if(event.key==='Enter')jumpPage()">
          <button class="mode-btn" onclick="jumpPage()">页</button>
        </div>
      </div><!-- .card -->
    </div><!-- .tab-panel #panel-logs -->

    <!-- ─── 分析 ─────────────────────────────────────────── -->
    <div class="tab-panel" id="panel-stats">
      <div id="stats-overview" class="stats-overview">
        <div class="loading">加载中…</div>
      </div>
      <div id="stats-detail-head" class="stats-detail-head">
        <button class="mode-btn stats-back-btn" onclick="showStatsOverview()">返回分类</button>
        <div class="stats-detail-title" id="stats-detail-title"></div>
      </div>
      <div id="stats-detail-grid" class="stats-grid stats-detail-grid">
        <div class="card stats-detail-card" data-stats-detail="ips">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
            <div class="card-title" style="margin-bottom:0">今日 Top IP</div>
            <div style="display:flex;gap:4px">
              <button class="mode-btn active" id="stats-ips-10" onclick="setStatsLimit('ips',10)">10</button>
              <button class="mode-btn" id="stats-ips-25" onclick="setStatsLimit('ips',25)">25</button>
              <button class="mode-btn" id="stats-ips-50" onclick="setStatsLimit('ips',50)">50</button>
              <button class="mode-btn" id="stats-ips-0" onclick="setStatsLimit('ips',0)">全部</button>
            </div>
          </div>
          <div style="font-size:10px;color:var(--text3);margin-bottom:10px">
            右侧计数：<span style="color:#22c55e">●</span>成功 / <span style="color:#ef4444">●</span>拦截403 / <span style="color:#eab308">●</span>限速429 / <span style="color:#64748b">●</span>断连444
          </div>
          <div id="top-ips"><div class="loading">加载中…</div></div>
          <div id="stats-ips-pg" class="page-controls" style="display:none;margin-top:10px"></div>
        </div>
        <div class="card stats-detail-card" data-stats-detail="tokens">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
            <div class="card-title" style="margin-bottom:0">今日 Top Token</div>
            <div style="display:flex;gap:4px">
              <button class="mode-btn active" id="stats-tokens-10" onclick="setStatsLimit('tokens',10)">10</button>
              <button class="mode-btn" id="stats-tokens-25" onclick="setStatsLimit('tokens',25)">25</button>
              <button class="mode-btn" id="stats-tokens-50" onclick="setStatsLimit('tokens',50)">50</button>
              <button class="mode-btn" id="stats-tokens-0" onclick="setStatsLimit('tokens',0)">全部</button>
            </div>
          </div>
          <div id="top-tokens"><div class="loading">加载中…</div></div>
          <div id="stats-tokens-pg" class="page-controls" style="display:none;margin-top:10px"></div>
        </div>
        <div class="card stats-detail-card" data-stats-detail="suspTokens">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
            <div class="card-title" style="margin-bottom:0">可疑 Token（被多IP拉取）</div>
            <div style="display:flex;gap:4px">
              <button class="mode-btn active" id="stats-suspTokens-10" onclick="setStatsLimit('suspTokens',10)">10</button>
              <button class="mode-btn" id="stats-suspTokens-25" onclick="setStatsLimit('suspTokens',25)">25</button>
              <button class="mode-btn" id="stats-suspTokens-50" onclick="setStatsLimit('suspTokens',50)">50</button>
              <button class="mode-btn" id="stats-suspTokens-0" onclick="setStatsLimit('suspTokens',0)">全部</button>
            </div>
          </div>
          <div id="susp-tokens"><div class="loading">加载中…</div></div>
          <div id="stats-suspTokens-pg" class="page-controls" style="display:none;margin-top:10px"></div>
        </div>
        <div class="card stats-detail-card" data-stats-detail="suspIps">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
            <div class="card-title" style="margin-bottom:0">可疑 IP（拉取多Token）</div>
            <div style="display:flex;gap:4px">
              <button class="mode-btn active" id="stats-suspIps-10" onclick="setStatsLimit('suspIps',10)">10</button>
              <button class="mode-btn" id="stats-suspIps-25" onclick="setStatsLimit('suspIps',25)">25</button>
              <button class="mode-btn" id="stats-suspIps-50" onclick="setStatsLimit('suspIps',50)">50</button>
              <button class="mode-btn" id="stats-suspIps-0" onclick="setStatsLimit('suspIps',0)">全部</button>
            </div>
          </div>
          <div id="susp-ips"><div class="loading">加载中…</div></div>
          <div id="stats-suspIps-pg" class="page-controls" style="display:none;margin-top:10px"></div>
        </div>
        <div class="card stats-detail-card" data-stats-detail="scanners">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
            <div class="card-title" style="margin-bottom:0">脚本/扫描器拉取订阅</div>
            <div style="display:flex;gap:4px">
              <button class="mode-btn active" id="stats-scanners-10" onclick="setStatsLimit('scanners',10)">10</button>
              <button class="mode-btn" id="stats-scanners-25" onclick="setStatsLimit('scanners',25)">25</button>
              <button class="mode-btn" id="stats-scanners-50" onclick="setStatsLimit('scanners',50)">50</button>
              <button class="mode-btn" id="stats-scanners-0" onclick="setStatsLimit('scanners',0)">全部</button>
            </div>
          </div>
          <div id="scanner-reports"><div class="loading">加载中…</div></div>
          <div id="stats-scanners-pg" class="page-controls" style="display:none;margin-top:10px"></div>
        </div>
        <div class="card stats-detail-card" data-stats-detail="profiles">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
            <div class="card-title" style="margin-bottom:0">用户画像</div>
            <div style="display:flex;gap:4px">
              <button class="mode-btn active" id="stats-profiles-10" onclick="setStatsLimit('profiles',10)">10</button>
              <button class="mode-btn" id="stats-profiles-25" onclick="setStatsLimit('profiles',25)">25</button>
              <button class="mode-btn" id="stats-profiles-50" onclick="setStatsLimit('profiles',50)">50</button>
              <button class="mode-btn" id="stats-profiles-0" onclick="setStatsLimit('profiles',0)">全部</button>
            </div>
          </div>
          <div id="user-profiles"><div class="loading">加载中…</div></div>
          <div id="stats-profiles-pg" class="page-controls" style="display:none;margin-top:10px"></div>
        </div>
        <div class="card stats-detail-card" data-stats-detail="uas">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
            <div class="card-title" style="margin-bottom:0">UA TOP</div>
            <div style="display:flex;gap:4px">
              <button class="mode-btn active" id="stats-uas-10" onclick="setStatsLimit('uas',10)">10</button>
              <button class="mode-btn" id="stats-uas-25" onclick="setStatsLimit('uas',25)">25</button>
              <button class="mode-btn" id="stats-uas-50" onclick="setStatsLimit('uas',50)">50</button>
              <button class="mode-btn" id="stats-uas-0" onclick="setStatsLimit('uas',0)">全部</button>
            </div>
          </div>
          <div id="bad-uas"><div class="loading">加载中…</div></div>
          <div id="stats-uas-pg" class="page-controls" style="display:none;margin-top:10px"></div>
        </div>
      </div>
    </div>

    <!-- ─── UA ─────────────────────────────────────────── -->
    <div class="tab-panel" id="panel-ua_blacklist">
      <div class="card">
        <div class="card-title">添加封禁 UA</div>
        <div class="ip-form">
          <input class="ip-input" id="ua-keyword" placeholder="UA 关键词（如 python-requests、clash）">
          <input class="comment-input" id="ua-comment" placeholder="备注（可选）">
          <button class="btn-primary" onclick="uaAdd()">添加并立即生效</button>
        </div>
        <div class="apply-hint" style="margin-bottom:14px;color:#eab308">
          ⚡ 封禁 UA 后立即 reload nginx 生效，大小写不敏感，支持关键词匹配
        </div>
        <div style="display:flex;align-items:center;gap:4px;margin-bottom:10px">
          <span style="color:var(--text3);font-size:12px">显示：</span>
          <button class="mode-btn active" id="ua-bl-limit-50" onclick="setUaBlLimit(50)">50条</button>
          <button class="mode-btn" id="ua-bl-limit-100" onclick="setUaBlLimit(100)">100条</button>
          <button class="mode-btn" id="ua-bl-limit-500" onclick="setUaBlLimit(500)">500条</button>
          <button class="mode-btn" id="ua-bl-limit-0" onclick="setUaBlLimit(0)">全部</button>
        </div>
        <div id="ua-list"><div class="loading">加载中…</div></div>
      </div>
      <div class="card" style="margin-top:16px">
        <div class="card-title">UA 白名单</div>
        <div class="apply-hint" style="margin-bottom:14px;color:#22c55e">
          ✅ 白名单UA不受封禁UA规则影响，可保护自己的客户端UA不被误封
        </div>
        <div class="ip-form">
          <input class="ip-input" id="ua-wl-keyword" placeholder="UA 关键词（如 Surge、Clash.Meta）">
          <input class="comment-input" id="ua-wl-comment" placeholder="备注（可选）">
          <button class="btn-primary" onclick="uaWlAdd()">添加并立即生效</button>
        </div>
        <div style="display:flex;align-items:center;gap:4px;margin-bottom:10px">
          <span style="color:var(--text3);font-size:12px">显示：</span>
          <button class="mode-btn active" id="ua-wl-limit-50" onclick="setUaWlLimit(50)">50条</button>
          <button class="mode-btn" id="ua-wl-limit-100" onclick="setUaWlLimit(100)">100条</button>
          <button class="mode-btn" id="ua-wl-limit-500" onclick="setUaWlLimit(500)">500条</button>
          <button class="mode-btn" id="ua-wl-limit-0" onclick="setUaWlLimit(0)">全部</button>
        </div>
        <div id="ua-wl-list"><div class="loading">加载中…</div></div>
      </div>
    </div>

    <!-- ─── 白名单 ─────────────────────────────────────────── -->
    <div class="tab-panel" id="panel-whitelist">
      <div class="card">
        <div class="card-title">添加白名单 IP</div>
        <div class="ip-form">
          <input class="ip-input" id="wl-ip" placeholder="支持批量，逗号分隔：1.1.1.1,2.2.2.0/24">
          <input class="comment-input" id="wl-comment" placeholder="备注（可选）">
          <button class="btn-primary" onclick="wlAdd()">添加</button>
        </div>
        <div class="apply-row">
          <span class="apply-hint">⚡ 添加、删除、导入后立即生效，无需额外操作</span>
          <button class="mode-btn import-btn" onclick="exportWhitelist()" style="margin-left:auto">导出</button>
          <button class="mode-btn import-btn" onclick="document.getElementById('wl-import-file').click()">导入</button>
          <input type="file" id="wl-import-file" accept=".txt,.conf" style="display:none" onchange="importWhitelist(this)">
        </div>
        <div id="wl-list"><div class="loading">加载中…</div></div>
      </div>
    </div>

    <!-- ─── 黑名单 ─────────────────────────────────────────── -->
    <div class="tab-panel" id="panel-blacklist">
      <div class="card">
        <div class="card-title">添加黑名单 IP</div>
        <div class="ip-form">
          <input class="ip-input" id="bl-ip" placeholder="1.2.3.4 或 1.2.3.0/24">
          <input class="comment-input" id="bl-comment" placeholder="备注（可选）">
          <button class="btn-primary" onclick="blAdd()">添加并立即生效</button>
          <button class="mode-btn import-btn" onclick="exportBlacklist()">导出</button>
          <button class="mode-btn import-btn" onclick="document.getElementById('bl-import-file').click()">导入</button>
          <input type="file" id="bl-import-file" accept=".txt,.conf" style="display:none" onchange="importBlacklist(this)">
        </div>
        <div class="apply-hint" style="margin-bottom:14px;color:#eab308">
          ⚡ 黑名单添加后立即 reload nginx 生效，无需额外操作。导入支持 IP/CIDR 格式（每行一条），自动去重。
        </div>
        <div id="bl-list"><div class="loading">加载中…</div></div>
      </div>
    </div>

    <!-- ─── Token黑名单 ──────────────────────────────────────── -->
    <div class="tab-panel" id="panel-token_blacklist">
      <div class="card">
        <div class="card-title">添加 Token 黑名单</div>
        <div class="ip-form">
          <input class="ip-input" id="tb-token" placeholder="完整 Token">
          <input class="comment-input" id="tb-comment" placeholder="备注（可选）">
          <button class="btn-primary" onclick="tbAdd()">添加</button>
        </div>
        <div class="apply-hint" style="margin-bottom:14px;color:#eab308">
          ⚡ Token 黑名单<strong>不会直接拦截请求</strong>，仅用于监控追踪——黑名单内的 Token 不计入分析统计，此处显示今日各 IP 的拉取记录。如需真正阻断访问，请通过 IP 黑名单或 UA 封禁实现。
        </div>
        <div id="tb-list"><div class="loading">加载中…</div></div>
      </div>
    </div>

    <!-- ─── 系统设置 ───────────────────────────────────────── -->
    <div class="tab-panel" id="panel-settings">
      <div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(340px,1fr))">

        <!-- 界面设置 -->
        <div class="card">
          <div class="card-title">界面设置</div>
          <div style="display:flex;flex-direction:column;gap:12px">
            <div>
              <label style="display:block;color:var(--text2);font-size:12px;margin-bottom:5px">网站标题（左上角 Logo）</label>
              <input class="ip-input" id="cfg-site-title" placeholder="SubSieve" value="<?= _val($_preSg['site_title'] ?? SITE_TITLE) ?>" style="width:100%">
            </div>
            <div>
              <label style="display:block;color:var(--text2);font-size:12px;margin-bottom:5px">网页标题（浏览器 Tab）</label>
              <input class="ip-input" id="cfg-page-title" placeholder="SubSieve Admin" value="<?= _val($_preSg['page_title'] ?? PAGE_TITLE) ?>" style="width:100%">
            </div>
            <button class="btn-primary" onclick="saveTitleSettings()">保存标题设置</button>
          </div>
        </div>

        <!-- 管理员凭证 -->
        <div class="card">
          <div class="card-title">登录凭证</div>
          <div style="display:flex;flex-direction:column;gap:12px">
            <div>
              <label style="display:block;color:var(--text2);font-size:12px;margin-bottom:5px">用户名</label>
              <input class="ip-input" id="cfg-admin-user" placeholder="admin" value="<?= _val($_preSg['admin_user'] ?? ADMIN_USER) ?>" style="width:100%">
            </div>
            <div>
              <label style="display:block;color:var(--text2);font-size:12px;margin-bottom:5px">新密码</label>
              <input class="ip-input" id="cfg-new-pass" type="password" placeholder="留空则不修改" style="width:100%">
            </div>
            <div>
              <label style="display:block;color:var(--text2);font-size:12px;margin-bottom:5px">确认新密码</label>
              <input class="ip-input" id="cfg-confirm-pass" type="password" placeholder="再次输入新密码" style="width:100%">
            </div>
            <div class="apply-hint" style="color:#eab308">⚠️ 修改后需重新登录，请牢记新密码</div>
            <div class="apply-hint" style="color:#64748b;font-size:11px;line-height:1.5">如忘记密码，请在宿主机 SSH 执行：<br><code style="background:rgba(0,0,0,.3);padding:2px 6px;border-radius:4px;font-size:11px;user-select:all">docker exec subscribe-admin cat /etc/nginx/subscribe/admin_settings.json</code></div>
            <button class="btn-primary" onclick="saveCredSettings()">保存凭证设置</button>
          </div>
        </div>

        <!-- 机场（上游）配置 -->
        <div class="card">
          <div class="card-title">机场（反代目标）</div>
          <div style="display:flex;flex-direction:column;gap:12px">
            <div style="display:flex;gap:8px;align-items:flex-end;overflow:hidden">
              <div style="flex:1;min-width:0">
                <label style="display:block;color:var(--text2);font-size:12px;margin-bottom:5px">机场地址</label>
                <input class="ip-input" id="cfg-upstream-url" placeholder="https://panel.yourdomain.com" value="<?= _val($_preSgUrlClean) ?>" style="width:100%;box-sizing:border-box">
              </div>
              <div style="flex:0 0 80px;min-width:0">
                <label style="display:block;color:var(--text2);font-size:12px;margin-bottom:5px">端口</label>
                <input class="ip-input" id="cfg-upstream-port" type="number" min="1" max="65535" placeholder="443" value="<?= _val((string)$_preSgPort) ?>" style="width:100%;box-sizing:border-box;min-width:0">
              </div>
            </div>
            <div>
              <label style="display:block;color:var(--text2);font-size:12px;margin-bottom:5px">订阅路径</label>
              <input class="ip-input" id="cfg-subscribe-path" placeholder="/api/v1/client/subscribe" value="<?= _val($_preSg['subscribe_path'] ?? '') ?>" style="width:100%">
            </div>
            <div class="apply-hint" style="color:#eab308">⚡ 保存后立即更新 nginx 配置并 reload</div>
            <button class="btn-primary" onclick="saveUpstreamSettings()">保存并立即生效</button>
          </div>
        </div>

        <!-- 网关端口配置 -->
        <div class="card">
          <div class="card-title">订阅网关</div>
          <div style="display:flex;flex-direction:column;gap:12px">
            <div>
              <label style="display:block;color:var(--text2);font-size:12px;margin-bottom:5px">网关监听端口（客户端订阅用的端口）</label>
              <input class="ip-input" id="cfg-gateway-port" type="number" min="1" max="65535"
                value="<?= _val((string)($_preSg['gateway_port'] ?? GATEWAY_PORT)) ?>"
                style="width:100%;box-sizing:border-box">
            </div>
            <div class="apply-hint" style="color:#eab308">⚠️ 修改后需在宿主机执行 <code style="background:rgba(0,0,0,.3);padding:1px 5px;border-radius:3px">bash update.sh</code> 重启容器方可生效</div>
            <button class="btn-primary" onclick="saveGatewayPort()">保存网关端口</button>
          </div>
        </div>

        <!-- SSL 证书信息 -->
        <div class="card">
          <div class="card-title">SSL 证书</div>
          <div id="cert-info"><div class="loading">加载中…</div></div>
          <div class="apply-hint" style="margin-top:12px;color:var(--text3)">
            证书文件位置：<code style="font-size:11px;background:var(--bg);padding:2px 5px;border-radius:3px">/etc/nginx/ssl/cert.pem</code><br>
            如需更换证书，请替换宿主机 <code style="font-size:11px;background:var(--bg);padding:2px 5px;border-radius:3px">sgw/ssl/</code> 目录下的文件后重启容器
          </div>
        </div>

        <!-- 分析统计缓存 -->
        <div class="card">
          <div class="card-title">分析统计缓存</div>
          <div id="stats-cache-info"><div class="loading">加载中…</div></div>
          <div class="apply-hint" style="margin-top:12px;color:var(--text3)">
            admin 容器会每分钟后台预热统计缓存，分析页优先读取缓存，减少大日志场景下的等待。
          </div>
        </div>

        <!-- 告警推送 -->
        <div class="card">
          <div class="card-title">告警推送</div>
          <div style="display:flex;flex-direction:column;gap:12px">
            <label style="display:flex;align-items:center;gap:10px;color:var(--text2);font-size:13px">
              <input id="cfg-alert-enabled" type="checkbox" style="width:18px;height:18px">
              开启高危事件推送
            </label>
            <div>
              <label style="display:block;color:var(--text2);font-size:12px;margin-bottom:5px">渠道</label>
              <select class="ip-input" id="cfg-alert-channel" style="width:100%">
                <option value="webhook">Webhook</option>
                <option value="telegram">Telegram</option>
              </select>
            </div>
            <div>
              <label style="display:block;color:var(--text2);font-size:12px;margin-bottom:5px">Webhook URL</label>
              <input class="ip-input" id="cfg-alert-webhook-url" placeholder="https://example.com/webhook" style="width:100%">
            </div>
            <div>
              <label style="display:block;color:var(--text2);font-size:12px;margin-bottom:5px">Telegram Bot Token</label>
              <input class="ip-input" id="cfg-alert-telegram-token" placeholder="123456:ABC..." style="width:100%">
            </div>
            <div>
              <label style="display:block;color:var(--text2);font-size:12px;margin-bottom:5px">Telegram Chat ID</label>
              <input class="ip-input" id="cfg-alert-telegram-chat" placeholder="-1001234567890" style="width:100%">
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px">
              <div>
                <label style="display:block;color:var(--text2);font-size:12px;margin-bottom:5px">扫描器评分 ≥</label>
                <input class="ip-input" id="cfg-alert-scanner-score" type="number" min="1" max="100" placeholder="80" style="width:100%">
              </div>
              <div>
                <label style="display:block;color:var(--text2);font-size:12px;margin-bottom:5px">可疑 IP 评分 ≥</label>
                <input class="ip-input" id="cfg-alert-susp-ip-score" type="number" min="1" max="100" placeholder="90" style="width:100%">
              </div>
              <div>
                <label style="display:block;color:var(--text2);font-size:12px;margin-bottom:5px">Token IP 数 ≥</label>
                <input class="ip-input" id="cfg-alert-susp-token-ips" type="number" min="2" max="50" placeholder="3" style="width:100%">
              </div>
              <div>
                <label style="display:block;color:var(--text2);font-size:12px;margin-bottom:5px">去重分钟</label>
                <input class="ip-input" id="cfg-alert-dedupe-minutes" type="number" min="1" max="1440" placeholder="60" style="width:100%">
              </div>
              <div>
                <label style="display:block;color:var(--text2);font-size:12px;margin-bottom:5px">历史保留条数</label>
                <input class="ip-input" id="cfg-alert-history-max" type="number" min="50" max="1000" placeholder="200" style="width:100%">
              </div>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(90px,1fr));gap:8px">
              <button class="mode-btn" onclick="applyAlertPreset('strict')">严格</button>
              <button class="mode-btn" onclick="applyAlertPreset('balanced')">均衡</button>
              <button class="mode-btn" onclick="applyAlertPreset('quiet')">安静</button>
            </div>
            <label style="display:flex;align-items:center;gap:10px;color:var(--text2);font-size:13px">
              <input id="cfg-alert-quiet-enabled" type="checkbox" style="width:18px;height:18px">
              开启静默时段
            </label>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px">
              <div>
                <label style="display:block;color:var(--text2);font-size:12px;margin-bottom:5px">静默开始</label>
                <input class="ip-input" id="cfg-alert-quiet-start" type="time" value="23:00" style="width:100%">
              </div>
              <div>
                <label style="display:block;color:var(--text2);font-size:12px;margin-bottom:5px">静默结束</label>
                <input class="ip-input" id="cfg-alert-quiet-end" type="time" value="08:00" style="width:100%">
              </div>
            </div>
            <div class="apply-hint" style="color:var(--text3)">每分钟检查统计缓存；阈值越低越敏感。</div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(90px,1fr));gap:8px">
              <button class="btn-primary" onclick="saveAlertSettings()">保存告警设置</button>
              <button class="mode-btn" onclick="testAlertSettings()">测试推送</button>
              <button class="mode-btn" onclick="runAlertCheckNow()">立即检查</button>
            </div>
            <div id="alert-history-info" style="border-top:1px solid var(--border);padding-top:12px">
              <div class="loading">加载中…</div>
            </div>
            <input type="file" id="alert-history-import-file" accept=".json,application/json" style="display:none" onchange="importAlertHistory(this)">
          </div>
        </div>


      </div>
    </div>

  </div><!-- .content -->
</div><!-- .main -->

<div id="toast"></div>

<script>
// ── 状态 ─────────────────────────────────────────────────────
const BASE = <?= json_encode(ADMIN_SECRET_PATH !== '' ? '/' . ADMIN_SECRET_PATH : '') ?>;
let activeSubscribePath = <?= json_encode($_preSg['subscribe_path'] ?? '/api/v1/client/subscribe') ?>;
let allLogs = [];
let logMode = 'today';   // 'today' | 'all'
let logLimit = 50;       // 0=瀑布流（无限制）
let logPage = 1;         // 当前页（分页模式）
let blacklistIpSet = new Set();
let whitelistIpSet = new Set();
let cloudCidrs = [];     // 云服务商CIDR列表，用于检测云IP
let allStatsData = null; // 完整统计数据缓存
let statsLimits = {ips: 10, tokens: 10, uas: 10, suspTokens: 10, suspIps: 10, scanners: 10, profiles: 10};
let statsPages  = {ips:  1, tokens:  1, uas:  1, suspTokens:  1, suspIps:  1, scanners: 1, profiles: 1};
let activeStatsDetail = '';
let allBlEntries = [];   // 黑名单完整数据缓存
let allWlEntries = [];   // 白名单完整数据缓存
let wlCommentMap = {};   // ip → 白名单备注（供日志列显示）
let blCommentMap = {};   // ip → 黑名单备注（供日志列显示）
let uaBlLimit = 50;      // UA封禁列表显示数量
let uaWlLimit = 50;      // UA白名单显示数量
let allUaBlEntries = []; // UA封禁列表完整数据缓存
let allUaWlEntries = []; // UA白名单完整数据缓存
let autoTimer, countdown = 300;
let tabLoaded = {};
let tabLoading = {};
let preloadStarted = false;
let suppressToasts = 0;
let alertHistoryFilter = 'all';
let alertHistoryQuery = '';
let alertHistoryLimit = 10;
let alertHistoryPage = 1;
let alertHistoryRange = 'all';
let lastAlertHistory = null;
let alertHistoryQueryTimer = null;

// ── 主题 ──────────────────────────────────────────────────────
const THEMES = ['dark','light','auto'];
const THEME_LABELS = {dark:'🌙 深色', light:'☀️ 浅色', auto:'💻 跟随系统'};
let themeMode = localStorage.getItem('theme') || 'dark';

function applyTheme() {
  const html = document.documentElement;
  if (themeMode === 'auto') {
    const sys = window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
    html.setAttribute('data-theme', sys);
  } else {
    html.setAttribute('data-theme', themeMode);
  }
  const btn = document.getElementById('theme-btn');
  if (btn) btn.textContent = THEME_LABELS[themeMode];
}

function cycleTheme() {
  const idx = THEMES.indexOf(themeMode);
  themeMode = THEMES[(idx + 1) % THEMES.length];
  localStorage.setItem('theme', themeMode);
  applyTheme();
}

// 系统主题变化时自动更新
window.matchMedia('(prefers-color-scheme: light)').addEventListener('change', () => {
  if (themeMode === 'auto') applyTheme();
});
applyTheme();
const TABS = {
  logs:            {title:'日志',        loader:loadLogs},
  stats:           {title:'分析',        loader:loadStats},
  ua_blacklist:    {title:'UA',          loader:loadUaBlacklist},
  whitelist:       {title:'IP白名单',    loader:loadWhitelist},
  blacklist:       {title:'IP黑名单',    loader:loadBlacklist},
  token_blacklist: {title:'Token黑名单', loader:loadTokenBlacklist},
  settings:        {title:'系统设置',    loader:loadSettings},
};
let currentTab = 'logs';

async function loadTab(name, opts={}) {
  const {force=false, silent=false} = opts;
  if (!TABS[name]) return;
  if (!force && tabLoaded[name]) return;
  if (tabLoading[name]) return tabLoading[name];
  if (silent) suppressToasts++;
  tabLoading[name] = (async () => {
    try {
      await TABS[name].loader({force});
      if (name === 'stats' && !allStatsData) return;
      tabLoaded[name] = true;
    } catch (e) {
      if (!silent) toast('加载失败：' + (e.message || '未知错误'), 'err');
      throw e;
    } finally {
      delete tabLoading[name];
      if (silent) suppressToasts = Math.max(0, suppressToasts - 1);
    }
  })();
  return tabLoading[name];
}

function scheduleBackgroundPreload() {
  if (preloadStarted) return;
  preloadStarted = true;
  const run = async () => {
    const names = Object.keys(TABS).filter(name => name !== 'logs');
    for (const name of names) {
      await loadTab(name, {silent:true}).catch(() => {});
      await new Promise(resolve => setTimeout(resolve, 120));
    }
  };
  if ('requestIdleCallback' in window) {
    requestIdleCallback(run, {timeout: 1500});
  } else {
    setTimeout(run, 600);
  }
}

// ── Tab 切换 ──────────────────────────────────────────────────
function switchTab(name, el) {
  currentTab = name;
  document.querySelectorAll('.nav-item').forEach(b => b.classList.remove('active'));
  el.classList.add('active');
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  const panel = document.getElementById('panel-' + name);
  panel.classList.add('active');
  restartAnimation(panel);
  document.getElementById('tab-title').textContent = TABS[name].title;
  resetCountdown();
  loadTab(name);
}

function restartAnimation(el) {
  if (!el || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  el.style.animation = 'none';
  void el.offsetHeight;
  el.style.animation = '';
}

// ── 自动刷新倒计时 ─────────────────────────────────────────────
function resetCountdown() {
  clearInterval(autoTimer);
  countdown = 300;
  updateTimerLabel();
  autoTimer = setInterval(() => {
    countdown--;
    updateTimerLabel();
    if (countdown <= 0) {
      resetCountdown();
      loadTab(currentTab, {force:true});
    }
  }, 1000);
}

function updateTimerLabel() {
  const m = String(Math.floor(countdown/60)).padStart(2,'0');
  const s = String(countdown % 60).padStart(2,'0');
  document.getElementById('auto-timer').textContent = `自动刷新 ${m}:${s}`;
}

function manualRefresh() {
  resetCountdown();
  loadTab(currentTab, {force:true});
}

// ── 工具 ──────────────────────────────────────────────────────
async function apiFetch(url, opts={}) {
  try {
    const r = await fetch(BASE + url, {headers:{'X-Requested-With':'XMLHttpRequest'}, ...opts});
    const ct = r.headers.get('Content-Type') || '';
    if (!ct.includes('application/json')) {
      return {ok: false, error: `HTTP ${r.status}：服务器未返回 JSON`};
    }
    try {
      return await r.json();
    } catch(e) {
      return {ok: false, error: `HTTP ${r.status}：JSON 解析失败`};
    }
  } catch(e) {
    return {ok: false, error: `请求失败：${e.message || '网络连接被中断'}`};
  }
}

function toast(msg, type='ok') {
  if (suppressToasts > 0) return;
  const el = document.getElementById('toast');
  el.textContent = msg;
  el.className = 'show ' + type;
  setTimeout(() => el.className = '', 2500);
}

function statusBadge(code) {
  const cls = code == 200 ? 'badge-200' : code == 403 ? 'badge-403' :
              code == 429 ? 'badge-429' : code == 444 ? 'badge-444' : 'badge-other';
  return `<span class="badge ${cls}">${code}</span>`;
}

function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// 将外部数据（IP/UA/Token 等）安全地转为行内事件（onclick 等）中的 JS 字符串字面量。
// JSON.stringify 生成带引号且完整转义的 JS 字符串（含单引号、反斜杠、控制字符），
// 再用 esc 转义 HTML 特殊字符，使其可安全放入双引号属性中（浏览器解析属性时会还原实体）。
// 用法：onclick="fn(${jsArg(x)})"  —— 注意不要再手动加引号。
function jsArg(v) {
  return esc(JSON.stringify(String(v ?? '')));
}

function copyText(text) {
  navigator.clipboard.writeText(text)
    .then(() => toast('已复制'))
    .catch(() => toast('复制失败，请手动复制','err'));
}

// ── 日志模式切换 ───────────────────────────────────────────────
function setLogMode(mode) {
  logMode = mode;
  document.getElementById('btn-today').classList.toggle('active', mode === 'today');
  document.getElementById('btn-all').classList.toggle('active', mode === 'all');
  loadLogs();
}

// ── 日志显示数量切换 ────────────────────────────────────────────
function setLogLimit(n) {
  logLimit = n;
  logPage = 1;
  ['50','100','500','inf'].forEach(k => {
    const btn = document.getElementById('limit-btn-' + k);
    if (btn) btn.classList.remove('active');
  });
  const key = n === 0 ? 'inf' : String(n);
  const btn = document.getElementById('limit-btn-' + key);
  if (btn) btn.classList.add('active');
  renderLogs();
}

// ── 分页控制 ──────────────────────────────────────────────────
function changePage(delta) {
  logPage += delta;
  renderLogs();
}

function jumpPage() {
  const v = parseInt(document.getElementById('page-jump').value);
  if (!isNaN(v) && v >= 1) { logPage = v; renderLogs(); }
}


// ── 云IP检测辅助函数 ───────────────────────────────────────────
function ipToInt(ip) {
  const parts = ip.split('.');
  if (parts.length !== 4) return null;
  return parts.reduce((acc, p) => (acc * 256 + parseInt(p, 10)), 0);
}

function ipInCidr(ipInt, cidr) {
  const slash = cidr.indexOf('/');
  const base = slash >= 0 ? cidr.slice(0, slash) : cidr;
  const bits = slash >= 0 ? parseInt(cidr.slice(slash + 1)) : 32;
  const baseParts = base.split('.');
  if (baseParts.length !== 4) return false;
  const baseInt = baseParts.reduce((acc, p) => (acc * 256 + parseInt(p, 10)), 0);
  const mask = bits === 0 ? 0 : (0xFFFFFFFF << (32 - bits)) >>> 0;
  return ((ipInt >>> 0) & mask) === ((baseInt >>> 0) & mask);
}

function isCloudIp(ip) {
  const ipInt = ipToInt(ip);
  if (ipInt === null) return false;
  return cloudCidrs.some(cidr => ipInCidr(ipInt, cidr));
}

// ── 日志 ──────────────────────────────────────────────────────
async function loadLogs() {
  document.getElementById('log-tbody').innerHTML = '<tr><td colspan="7" class="loading">加载中…</td></tr>';
  const [logsData, blData, cloudData, wlData] = await Promise.all([
    apiFetch('/api/logs.php?mode=' + logMode),
    apiFetch('/api/blacklist.php?no_idc=1'),
    apiFetch('/api/blacklist.php?cloud_cidrs=1'),
    apiFetch('/api/whitelist.php'),
  ]);
  blacklistIpSet = new Set((blData.entries || []).map(e => e.ip));
  whitelistIpSet = new Set((wlData.entries || []).map(e => e.ip));
  cloudCidrs = cloudData.cidrs || [];
  wlCommentMap = {}; (wlData.entries || []).forEach(e => wlCommentMap[e.ip] = e.comment || '');
  blCommentMap = {}; (blData.entries || []).forEach(e => blCommentMap[e.ip] = e.comment || '');
  if (!logsData.ok) {
    document.getElementById('log-tbody').innerHTML = '<tr><td colspan="7" class="empty">加载失败：' + esc(logsData.error||'未知错误') + '</td></tr>';
    toast('加载日志失败: ' + (logsData.error||''), 'err'); return;
  }
  allLogs = logsData.logs || [];
  renderLogs();
}

function renderLogs() {
  updateSubscribePathLabel();
  const fIp     = document.getElementById('filter-ip').value.trim().toLowerCase();
  const fStatus = document.getElementById('filter-status').value.trim();
  const fToken  = document.getElementById('filter-token').value.trim().toLowerCase();
  const fUa     = document.getElementById('filter-ua').value.trim().toLowerCase();
  const subOnly = document.querySelector('input[name="sub-filter"][value="subscribe"]').checked;

  let rows = allLogs.filter(l => {
    if (subOnly && activeSubscribePath && !l.request.includes(activeSubscribePath)) return false;
    if (fIp     && !l.ip.toLowerCase().includes(fIp)) return false;
    if (fStatus && String(l.status) !== fStatus) return false;
    if (fToken  && !l.token.toLowerCase().includes(fToken)) return false;
    if (fUa     && !(l.ua || '').toLowerCase().includes(fUa)) return false;
    return true;
  });

  // 最新的在最上面
  rows = rows.slice().reverse();

  // Token过滤时按IP去重（每个IP只保留最新一条）
  if (fToken) {
    const seen = new Set();
    rows = rows.filter(l => {
      if (seen.has(l.ip)) return false;
      seen.add(l.ip);
      return true;
    });
  }

  const total = rows.length;

  // ── 分页 ──────────────────────────────────────────────────────
  const pg = document.getElementById('log-pagination');
  if (logLimit > 0 && total > 0) {
    const totalPages = Math.ceil(total / logLimit);
    logPage = Math.max(1, Math.min(logPage, totalPages));
    const start = (logPage - 1) * logLimit;
    const displayRows = rows.slice(start, start + logLimit);
    document.getElementById('log-count').textContent =
      `${total} 条（第${logPage}/${totalPages}页，每页${logLimit}条）`;
    document.getElementById('page-info').textContent =
      `第 ${logPage} / ${totalPages} 页`;
    document.getElementById('page-prev').disabled = logPage <= 1;
    document.getElementById('page-next').disabled = logPage >= totalPages;
    pg.style.display = 'flex';

    if (!displayRows.length) {
      document.getElementById('log-tbody').innerHTML =
        '<tr><td colspan="7" class="empty">暂无匹配记录</td></tr>';
      return;
    }
    renderLogRows(displayRows);
  } else {
    // 瀑布流：显示全部，隐藏分页
    pg.style.display = 'none';
    document.getElementById('log-count').textContent = `${total} / ${allLogs.length} 条`;
    if (!total) {
      document.getElementById('log-tbody').innerHTML =
        '<tr><td colspan="7" class="empty">暂无匹配记录</td></tr>';
      return;
    }
    renderLogRows(rows);
  }
}

function updateSubscribePathLabel() {
  const el = document.getElementById('active-subscribe-path');
  if (el) el.textContent = activeSubscribePath ? `路径：${activeSubscribePath}` : '';
}

// ── 行内备注编辑（通用）──────────────────────────────────────
function makeCommentCell(apiPath, keyField, keyValue, comment) {
  const d = esc(comment || '');
  const display = d ? d : '<span style="opacity:.35">—</span>';
  return `<td class="comment-cell" data-api="${esc(apiPath)}" data-keyf="${esc(keyField)}" data-keyv="${esc(keyValue)}" data-comment="${d}" style="color:#64748b;cursor:pointer;min-width:60px;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${d ? d + '（点击编辑）' : '点击添加备注'}">${display}</td>`;
}

function attachCommentCells(container) {
  (container || document).querySelectorAll('.comment-cell').forEach(td => {
    td.onclick = () => startEditComment(td);
  });
}

async function startEditComment(td) {
  if (td.querySelector('input')) return;
  const apiPath  = td.dataset.api;
  const keyField = td.dataset.keyf;
  const keyValue = td.dataset.keyv;
  const current  = td.dataset.comment || '';

  const input = document.createElement('input');
  input.type  = 'text';
  input.value = current;
  input.placeholder = '备注…';
  input.style.cssText = 'width:100%;min-width:60px;background:var(--bg-input);color:var(--text);border:1px solid var(--border2);border-radius:4px;padding:2px 6px;font-size:12px;outline:none;box-sizing:border-box';
  td.innerHTML = '';
  td.appendChild(input);
  input.focus(); input.select();

  let saved = false;
  async function doSave() {
    if (saved) return; saved = true;
    const newComment = input.value.trim();
    if (newComment === current) { doRestore(current); return; }
    const body = {comment: newComment};
    body[keyField] = keyValue;
    const d = await apiFetch(apiPath, {
      method: 'PATCH',
      body: JSON.stringify(body),
      headers: {'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
    });
    if (d.ok) {
      td.dataset.comment = newComment;
      // 同步更新全局缓存
      if (apiPath === '/api/whitelist.php') {
        wlCommentMap[keyValue] = newComment;
        const e = allWlEntries.find(e => e.ip === keyValue); if (e) e.comment = newComment;
      } else if (apiPath === '/api/blacklist.php') {
        blCommentMap[keyValue] = newComment;
        const e = allBlEntries.find(e => e.ip === keyValue); if (e) e.comment = newComment;
      } else if (apiPath === '/api/ua_blacklist.php') {
        const e = allUaBlEntries.find(e => e.ua === keyValue); if (e) e.comment = newComment;
      } else if (apiPath === '/api/ua_whitelist.php') {
        const e = allUaWlEntries.find(e => e.ua === keyValue); if (e) e.comment = newComment;
      }
      doRestore(newComment);
      toast('✅ 备注已更新');
    } else {
      toast(d.error || '更新失败', 'err');
      doRestore(current);
    }
  }
  function doRestore(c) {
    const d2 = esc(c);
    td.innerHTML = d2 ? d2 : '<span style="opacity:.35">—</span>';
    td.title = d2 ? d2 + '（点击编辑）' : '点击添加备注';
    td.style.cursor = 'pointer';
    td.onclick = () => startEditComment(td);
  }
  input.addEventListener('blur', doSave);
  input.addEventListener('keydown', e => {
    if (e.key === 'Enter')  { e.preventDefault(); input.blur(); }
    if (e.key === 'Escape') { saved = true; doRestore(current); }
  });
}

function renderLogRows(rows) {
  const tbody = document.getElementById('log-tbody');
  tbody.innerHTML = rows.map(l => {
    const isBlacklisted = blacklistIpSet.has(l.ip);
    const isWhitelisted = !isBlacklisted && whitelistIpSet.has(l.ip);
    const isCloud = !isBlacklisted && !isWhitelisted && isCloudIp(l.ip);
    const ipBtn = isBlacklisted
      ? `<button class="bl-badge-btn" onclick="quickWhitelist(${jsArg(l.ip)})">黑名单</button>`
      : isWhitelisted
        ? `<button class="wl-badge-btn" onclick="quickRemoveWhitelist(${jsArg(l.ip)})">白名单</button>`
        : isCloud
          ? `<span class="bl-badge-btn" style="cursor:default;background:rgba(234,179,8,.15);color:#eab308;border-color:rgba(234,179,8,.3)">黑名单</span>`
          : `<button class="add-btn-sm" onclick="quickBlacklist(${jsArg(l.ip)})">封</button><button class="add-btn-sm" style="background:rgba(34,197,94,.2);color:#22c55e;border-color:rgba(34,197,94,.4)" onclick="quickAddWhitelistFromLog(${jsArg(l.ip)})">白</button>`;
    const tokenHtml = l.token
      ? `<div style="display:inline-flex;align-items:center;gap:3px;font-family:monospace;font-size:11px;color:#818cf8"><span title="${esc(l.token)}">${esc(l.token)}</span><button class="copy-btn" data-val="${esc(l.token)}" onclick="copyText(this.dataset.val)">复制</button></div>`
      : '—';
    // 备注列：从白名单/黑名单备注映射获取，支持行内编辑
    const commentCell = isWhitelisted
      ? makeCommentCell('/api/whitelist.php', 'ip', l.ip, wlCommentMap[l.ip] || '')
      : isBlacklisted
        ? makeCommentCell('/api/blacklist.php', 'ip', l.ip, blCommentMap[l.ip] || '')
        : `<td style="color:#475569;opacity:.4;font-size:11px">—</td>`;
    return `
    <tr>
      <td style="white-space:nowrap;color:#64748b;font-size:11px">${esc(l.time)}</td>
      <td class="ip-cell"><div style="display:inline-flex;align-items:center;gap:4px;flex-wrap:nowrap"><span>${esc(l.ip)}</span><button class="copy-btn" data-val="${esc(l.ip)}" onclick="copyText(this.dataset.val)">复制</button><span style="display:inline-block;width:2px"></span>${ipBtn}</div></td>
      ${commentCell}
      <td>${statusBadge(l.status)}</td>
      <td style="min-width:100px;max-width:200px">${tokenHtml}</td>
      <td><div class="req-cell-wrap"><span class="req-cell" title="${esc(l.request)}">${esc(l.request)}</span><button class="copy-btn" data-val="${esc(l.request)}" onclick="copyText(this.dataset.val)">复制</button></div></td>
      <td><div class="ua-cell-wrap"><span class="ua-cell" title="${esc(l.ua)}">${esc(l.ua)||'—'}</span>${l.ua ? `<button class="copy-btn" data-val="${esc(l.ua)}" onclick="copyText(this.dataset.val)">复制</button>` : ''}</div></td>
    </tr>`;
  }).join('');
  attachCommentCells(tbody);
}

async function deleteLogs() {
  if (!confirm('确定要删除7天前的所有日志行吗？\n此操作不可撤销。')) return;
  const d = await apiFetch('/api/logs.php', {
    method: 'DELETE',
    headers: {'X-Requested-With':'XMLHttpRequest'},
  });
  if (d.ok) {
    toast(`✅ 已删除 ${d.deleted} 行，保留 ${d.kept} 行`);
    loadLogs();
    if (allStatsData) loadStats();
  } else {
    toast(d.error || '删除失败', 'err');
  }
}

async function deleteAllLogs() {
  if (!confirm('确定要删除当前所有日志吗？\n此操作不可撤销！')) return;
  const d = await apiFetch('/api/logs.php', {
    method: 'DELETE',
    headers: {'X-Requested-With':'XMLHttpRequest', 'X-Delete-All':'1'},
  });
  if (d.ok) {
    toast('✅ 所有日志已清空');
    loadLogs();
    if (allStatsData) loadStats();
  } else {
    toast(d.error || '删除失败', 'err');
  }
}

// ── 从日志解封（点击"黑名单"徽章：仅移除黑名单）────────────────
async function quickWhitelist(ip) {
  if (!confirm(`是否解封 ${ip}？`)) return;
  const d = await apiFetch('/api/blacklist.php', {method:'DELETE', body:JSON.stringify({ip}),
    headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'}});
  if (d.ok) {
    toast(`✅ ${ip} 已解封`);
    blacklistIpSet.delete(ip);
    renderLogs();
  } else {
    toast(d.error || '解封失败', 'err');
  }
}

// ── 从日志移出白名单 ───────────────────────────────────────────
async function quickRemoveWhitelist(ip) {
  if (!confirm(`是否移出白名单？`)) return;
  const d = await apiFetch('/api/whitelist.php', {method:'DELETE', body:JSON.stringify({ip}),
    headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'}});
  if (!d.ok) { toast(d.error || '移除失败', 'err'); return; }
  toast(`✅ ${ip} 已移出白名单并生效`);
  whitelistIpSet.delete(ip);
  renderLogs();
}

// ── 从日志加入白名单（"白"按钮）──────────────────────────────────
async function quickAddWhitelistFromLog(ip) {
  if (!confirm(`是否将 ${ip} 加入白名单？`)) return;
  const d = await apiFetch('/api/whitelist.php', {method:'POST', body:JSON.stringify({ip, comment:'从日志加入白名单'}),
    headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'}});
  if (!d.ok && !(d.error && d.error.includes('已在白名单'))) {
    toast(d.error || '加入白名单失败', 'err'); return;
  }
  toast(`✅ ${ip} 已加入白名单并生效`);
  whitelistIpSet.add(ip);
  renderLogs();
}

// ── 分析 ──────────────────────────────────────────────────────
async function loadStats(opts={}) {
  const data = await apiFetch('/api/stats.php' + (opts.force ? '?refresh=1' : ''));
  if (!data.ok) {
    ['stats-overview','top-ips','top-tokens','bad-uas','susp-tokens','susp-ips','scanner-reports','user-profiles'].forEach(id => {
      document.getElementById(id).innerHTML = '<div class="empty">加载失败：' + esc(data.error||'未知错误') + '</div>';
    });
    toast('加载统计失败: ' + (data.error||''), 'err'); return;
  }
  allStatsData = data;
  renderStats();
}

function setStatsLimit(key, n) {
  statsLimits[key] = n;
  statsPages[key] = 1;  // 切换每页数量时重置到第1页
  [10, 25, 50, 0].forEach(v => {
    const btn = document.getElementById(`stats-${key}-${v}`);
    if (btn) btn.classList.toggle('active', v === n);
  });
  renderStats();
}

function changeStatsPage(key, delta) {
  statsPages[key] += delta;
  renderStats();
}

function renderStatsPagination(key, total, pageSize) {
  const el = document.getElementById(`stats-${key}-pg`);
  if (!el) return;
  if (pageSize === 0 || total <= pageSize) { el.style.display = 'none'; return; }
  const totalPages = Math.ceil(total / pageSize);
  statsPages[key] = Math.max(1, Math.min(statsPages[key], totalPages));
  const p = statsPages[key];
  el.style.display = 'flex';
  el.innerHTML = `
    <button class="mode-btn" onclick="changeStatsPage('${key}',-1)" ${p<=1?'disabled':''}>上一页</button>
    <span style="color:var(--text2);font-size:12px;white-space:nowrap;padding:0 6px">第 ${p} / ${totalPages} 页（共 ${total} 条）</span>
    <button class="mode-btn" onclick="changeStatsPage('${key}',1)" ${p>=totalPages?'disabled':''}>下一页</button>`;
}

function renderStats() {
  if (!allStatsData) return;
  const data = allStatsData;
  renderStatsOverview(data);
  updateStatsView();

  // Top IP
  const allIps = data.top_ips || [];
  const ipsLimit = statsLimits.ips;
  const ipsPage  = statsPages.ips;
  const ips = ipsLimit > 0 ? allIps.slice((ipsPage-1)*ipsLimit, ipsPage*ipsLimit) : allIps;
  const ipsStart = ipsLimit > 0 ? (ipsPage-1)*ipsLimit : 0;
  renderStatsPagination('ips', allIps.length, ipsLimit);
  document.getElementById('top-ips').innerHTML = ips.length ? ips.map((r,i) => {
    const ipBanned = blacklistIpSet.has(r.ip);
    const ipWhitelisted = !ipBanned && whitelistIpSet.has(r.ip);
    const ipBtn = ipBanned
      ? `<button class="bl-badge-btn" onclick="quickUnblockIp(${jsArg(r.ip)})">黑名单</button>`
      : ipWhitelisted
        ? `<button class="wl-badge-btn" onclick="quickRemoveWhitelist(${jsArg(r.ip)})">白名单</button>`
        : `<button class="add-btn-sm" onclick="quickBlacklist(${jsArg(r.ip)})">封</button><button class="add-btn-sm" style="background:rgba(34,197,94,.2);color:#22c55e;border-color:rgba(34,197,94,.4)" onclick="quickWhitelistIp(${jsArg(r.ip)})">白</button>`;
    return `
    <div class="top-row">
      <span class="top-rank">${ipsStart+i+1}</span>
      <span class="top-val">
        ${esc(r.ip)}
        ${ipBtn}
      </span>
      <span class="top-count">${r.total}次</span>
      <span class="top-sub" style="margin-left:8px;font-size:11px" title="成功200 / 拦截403 / 限速429 / 断连444（非订阅路径或HTTP明文）">
        <span style="color:#22c55e">${r.s200}</span>/<span style="color:#ef4444">${r.s403}</span>/<span style="color:#eab308">${r.s429}</span>/<span style="color:#64748b">${r.s444}</span>
      </span>
    </div>`;
  }).join('') : '<div class="empty">暂无数据</div>';

  // Top Token
  const allToks = data.top_tokens || [];
  const toksLimit = statsLimits.tokens;
  const toksPage  = statsPages.tokens;
  const toks = toksLimit > 0 ? allToks.slice((toksPage-1)*toksLimit, toksPage*toksLimit) : allToks;
  const toksStart = toksLimit > 0 ? (toksPage-1)*toksLimit : 0;
  renderStatsPagination('tokens', allToks.length, toksLimit);
  document.getElementById('top-tokens').innerHTML = toks.length ? toks.map((r,i) => `
    <div class="top-row">
      <span class="top-rank">${toksStart+i+1}</span>
      <span class="top-val token-cell" style="display:flex;align-items:center;gap:6px">
        <span class="token-text" title="${esc(r.token_full)}">${esc(r.token_full)}</span>
        <button class="copy-btn" data-val="${esc(r.token_full)}" onclick="copyText(this.dataset.val)">复制</button>
      </span>
      <span class="top-count" style="white-space:nowrap;margin-left:6px">${r.count}次</span>
      <span class="top-sub" style="margin-left:8px">${esc(r.last_time)}</span>
    </div>`).join('') : '<div class="empty">暂无数据</div>';

  // UA TOP
  const allUas = data.bad_uas || [];
  const uasLimit = statsLimits.uas;
  const uasPage  = statsPages.uas;
  const uas = uasLimit > 0 ? allUas.slice((uasPage-1)*uasLimit, uasPage*uasLimit) : allUas;
  renderStatsPagination('uas', allUas.length, uasLimit);
  document.getElementById('bad-uas').innerHTML = uas.length ? `
    <table style="table-layout:fixed;width:100%"><thead><tr>
      <th style="overflow:hidden">UA</th>
      <th style="width:64px;white-space:nowrap">403次数</th>
      <th style="width:72px;white-space:nowrap">操作</th>
    </tr></thead>
    <tbody>${uas.map(r => `
      <tr>
        <td class="ua-cell" style="padding:3px 8px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(r.ua)}">${esc(r.ua)||'（空UA）'}</td>
        <td style="color:#ef4444;font-weight:600;padding:3px 8px">${r.count}</td>
        <td style="padding:3px 8px;white-space:nowrap"><button class="add-btn-sm" onclick="quickBanUA(${jsArg(r.ua)})">封禁UA</button></td>
      </tr>`).join('')}
    </tbody></table>` : '<div class="empty">今日暂无可疑UA</div>';

  // 可疑 Token
  const allSuspToks = data.susp_tokens || [];
  const suspToksLimit = statsLimits.suspTokens;
  const suspToksPage  = statsPages.suspTokens;
  const suspToks = suspToksLimit > 0 ? allSuspToks.slice((suspToksPage-1)*suspToksLimit, suspToksPage*suspToksLimit) : allSuspToks;
  renderStatsPagination('suspTokens', allSuspToks.length, suspToksLimit);
  document.getElementById('susp-tokens').innerHTML = suspToks.length ? suspToks.map(r => `
    <div class="top-row">
      <span class="top-val token-cell" style="display:flex;align-items:center;gap:6px">
        <span class="token-text" title="${esc(r.token)}">${esc(r.token)}</span>
        <button class="copy-btn" data-val="${esc(r.token)}" onclick="copyText(this.dataset.val)">复制</button>
      </span>
      <span class="top-count" style="white-space:nowrap">${r.ip_count} 个不同IP</span>
      <button class="add-btn-sm" style="margin-left:8px" onclick="quickBanToken(${jsArg(r.token)})">拉黑</button>
    </div>`).join('') : '<div class="empty">暂无可疑Token（阈值：3个以上不同IP）</div>';

  // 可疑 IP
  const allSuspIps = data.susp_ips || [];
  const suspIpsLimit = statsLimits.suspIps;
  const suspIpsPage  = statsPages.suspIps;
  const suspIps = suspIpsLimit > 0 ? allSuspIps.slice((suspIpsPage-1)*suspIpsLimit, suspIpsPage*suspIpsLimit) : allSuspIps;
  renderStatsPagination('suspIps', allSuspIps.length, suspIpsLimit);
  document.getElementById('susp-ips').innerHTML = suspIps.length ? suspIps.map(r => {
    const suspBanned = blacklistIpSet.has(r.ip);
    const suspBtn = suspBanned
      ? `<button class="bl-badge-btn" onclick="quickUnblockIp(${jsArg(r.ip)})">黑名单</button>`
      : `<button class="add-btn-sm" onclick="quickBlacklist(${jsArg(r.ip)})">封</button>`;
    const riskColor = (r.score || 0) >= 90 ? '#ef4444' : ((r.score || 0) >= 75 ? '#eab308' : '#38bdf8');
    const paths = (r.paths || []).map(p => `<code>${esc(p)}</code>`).join(' ');
    const uas = (r.uas || []).map(ua => `<div title="${esc(ua)}">${esc(ua)}</div>`).join('');
    const tokens = (r.tokens || []).map(t => `<code title="${esc(t)}">${esc(t)}</code>`).join(' ');
    const reasons = (r.reasons || []).map((reason, idx) => `<div>${idx + 1}. ${esc(reason)}</div>`).join('');
    const reqSummary = r.request_count ? `${r.token_count} 个Token / ${r.request_count} 次` : `${r.token_count} 个Token`;
    return `
    <div class="top-row risk-row">
      <div class="risk-main">
        <div class="risk-ip">${esc(r.ip)}</div>
        <div>
          <div class="risk-meta">
            <span class="risk-badge" style="color:${riskColor}">${esc(r.risk || '可疑')} ${r.score || 0}</span>
            <span class="top-count">${reqSummary}</span>
          </div>
          <div class="risk-actions">
            ${suspBtn}
            <button class="add-btn-sm" style="background:rgba(34,197,94,.2);color:#22c55e;border-color:rgba(34,197,94,.4)" onclick="quickWhitelistIp(${jsArg(r.ip)})">白</button>
          </div>
        </div>
      </div>
      <details class="risk-detail">
        <summary>高危成立依据</summary>
        <div class="risk-evidence">${reasons || '暂无详细依据'}</div>
        ${paths ? `<div class="risk-samples">路径：${paths}</div>` : ''}
        ${tokens ? `<div class="risk-samples">Token样本：${tokens}</div>` : ''}
        ${uas ? `<div style="margin-top:6px">${uas}</div>` : ''}
      </details>
    </div>`;
  }).join('') : '<div class="empty">暂无可疑IP（阈值：拉取3个以上不同Token）</div>';

  // 脚本/扫描器拉取订阅
  const allScanners = data.scanner_reports || [];
  const scannersLimit = statsLimits.scanners;
  const scannersPage  = statsPages.scanners;
  const scanners = scannersLimit > 0 ? allScanners.slice((scannersPage-1)*scannersLimit, scannersPage*scannersLimit) : allScanners;
  renderStatsPagination('scanners', allScanners.length, scannersLimit);
  document.getElementById('scanner-reports').innerHTML = scanners.length ? scanners.map(r => {
    const report = scannerReportText(r);
    return `
    <div class="scanner-report">
      <div class="scanner-actions">
        <span class="risk-badge" style="color:#ef4444">${esc(r.risk || '高危')} ${r.score || 90}</span>
        <span class="top-val" style="padding:0">${esc(r.ip)}</span>
        <button class="copy-btn" data-val="${esc(report)}" onclick="copyText(this.dataset.val)">复制报告</button>
        <button class="add-btn-sm" onclick="banScannerIp(${jsArg(r.ip)}, ${jsArg(r.token)})">封禁IP</button>
      </div>
      <pre>${esc(report)}</pre>
    </div>`;
  }).join('') : '<div class="empty">暂无脚本/扫描器拉取订阅记录</div>';

  // 用户画像
  const allProfiles = data.user_profiles || [];
  const profilesLimit = statsLimits.profiles;
  const profilesPage  = statsPages.profiles;
  const profiles = profilesLimit > 0 ? allProfiles.slice((profilesPage-1)*profilesLimit, profilesPage*profilesLimit) : allProfiles;
  renderStatsPagination('profiles', allProfiles.length, profilesLimit);
  document.getElementById('user-profiles').innerHTML = profiles.length ? profiles.map(renderUserProfileSegment).join('') : '<div class="empty">暂无用户画像数据</div>';
}

function renderUserProfileSegment(seg) {
  const summary = seg.summary || {};
  const cells = seg.cells || [];
  const cellHtml = cells.map(c => {
    const kind = String(c.kind || 'N').toLowerCase();
    const title = `${c.ip || ''}｜请求 ${c.count || 0} 次｜Token ${c.token_count || 0} 个｜${c.location || '未查询'}｜${c.network_type || '本地日志'}`;
    return `<div class="profile-cell profile-${esc(kind)}" title="${esc(title)}">${esc(c.label || c.kind || '·')}</div>`;
  }).join('');
  return `
    <div class="profile-segment">
      <div class="profile-head">
        <div>
          <div class="profile-range">${esc(seg.range || '')}</div>
          <div class="profile-meta">日志画像｜${esc(seg.ip_count || 0)} 个IP｜${esc(seg.total || 0)} 次请求｜最后 ${esc(seg.last_time || '-')}</div>
        </div>
        <span class="top-count">${esc(seg.ip_count || 0)} IP</span>
      </div>
      <div class="profile-grid">${cellHtml}</div>
      <div class="profile-legend">
        <span><i class="profile-dot profile-v"></i>VPN(V): ${esc(summary.V || 0)}</span>
        <span><i class="profile-dot profile-o"></i>公共代理(O): ${esc(summary.O || 0)}</span>
        <span><i class="profile-dot profile-t"></i>Tor(T): ${esc(summary.T || 0)}</span>
        <span><i class="profile-dot profile-p"></i>代理(P): ${esc(summary.P || 0)}</span>
        <span><i class="profile-dot profile-b"></i>恶意/滥用IP(B): ${esc(summary.B || 0)}</span>
      </div>
    </div>`;
}

function renderStatsOverview(data) {
  const cards = [
    {
      key: 'ips',
      tone: 'blue',
      icon: '⌁',
      title: '今日 Top IP',
      main: `${(data.top_ips || []).length} 个IP`,
      sub: topSummary(data.top_ips, 'ip', 'total', '暂无请求记录'),
    },
    {
      key: 'tokens',
      tone: 'violet',
      icon: '#',
      title: '今日 Top Token',
      main: `${(data.top_tokens || []).length} 个Token`,
      sub: topSummary(data.top_tokens, 'token_full', 'count', '暂无 Token 拉取'),
    },
    {
      key: 'suspTokens',
      tone: 'amber',
      icon: '!',
      title: '可疑 Token',
      main: `${(data.susp_tokens || []).length} 个`,
      sub: (data.susp_tokens || [])[0] ? `${esc((data.susp_tokens || [])[0].ip_count)} 个不同IP拉取` : '暂无多 IP 拉取',
    },
    {
      key: 'suspIps',
      tone: 'rose',
      icon: '!',
      title: '可疑 IP',
      main: `${(data.susp_ips || []).length} 个`,
      sub: (data.susp_ips || [])[0] ? `${esc((data.susp_ips || [])[0].risk || '可疑')} ${esc((data.susp_ips || [])[0].score || '')}` : '暂无多 Token 拉取',
    },
    {
      key: 'scanners',
      tone: 'cyan',
      icon: '⌘',
      title: '脚本/扫描器',
      main: `${(data.scanner_reports || []).length} 条`,
      sub: (data.scanner_reports || [])[0] ? `${esc((data.scanner_reports || [])[0].ip)}｜${esc((data.scanner_reports || [])[0].ua || '空UA')}` : '暂无脚本拉取记录',
    },
    {
      key: 'profiles',
      tone: 'sky',
      icon: '▦',
      title: '用户画像',
      main: `${(data.user_profiles || []).length} 个IP段`,
      sub: (data.user_profiles || [])[0] ? `${esc((data.user_profiles || [])[0].range)}｜最近 ${esc(data.scan_limit || 30000)} 行` : '暂无画像数据',
    },
    {
      key: 'uas',
      tone: 'emerald',
      icon: 'A',
      title: 'UA TOP',
      main: `${(data.bad_uas || []).length} 个UA`,
      sub: topSummary(data.bad_uas, 'ua', 'count', '今日暂无可疑UA'),
    },
  ];
  const el = document.getElementById('stats-overview');
  if (!el) return;
  el.innerHTML = cards.map(c => `
    <button class="stats-card tone-${esc(c.tone)}" onclick="showStatsDetail('${c.key}')">
      <div class="stats-card-title">
        <span class="stats-card-kicker"><span class="stats-card-icon">${esc(c.icon)}</span>${esc(c.title)}</span>
        <span class="stats-card-action">查看</span>
      </div>
      <div class="stats-card-main">${esc(c.main)}</div>
      <div class="stats-card-sub">${c.sub}</div>
    </button>`).join('');
}

function topSummary(rows, labelKey, countKey, emptyText) {
  const first = (rows || [])[0];
  if (!first) return esc(emptyText);
  const label = String(first[labelKey] || '').slice(0, 28);
  return `${esc(label)} · ${esc(first[countKey] || 0)} 次`;
}

function showStatsDetail(key) {
  activeStatsDetail = key;
  updateStatsView();
  restartAnimation(document.getElementById('stats-detail-grid'));
}

function showStatsOverview() {
  activeStatsDetail = '';
  updateStatsView();
  restartAnimation(document.getElementById('stats-overview'));
}

function updateStatsView() {
  const overview = document.getElementById('stats-overview');
  const detailHead = document.getElementById('stats-detail-head');
  const detailGrid = document.getElementById('stats-detail-grid');
  if (!overview || !detailHead || !detailGrid) return;
  const titles = {
    ips: '今日 Top IP',
    tokens: '今日 Top Token',
    suspTokens: '可疑 Token',
    suspIps: '可疑 IP',
    scanners: '脚本/扫描器拉取订阅',
    profiles: '用户画像',
    uas: 'UA TOP',
  };
  const tones = {
    ips: 'tone-blue',
    tokens: 'tone-violet',
    suspTokens: 'tone-amber',
    suspIps: 'tone-rose',
    scanners: 'tone-cyan',
    profiles: 'tone-sky',
    uas: 'tone-emerald',
  };
  const inDetail = !!activeStatsDetail;
  overview.style.display = inDetail ? 'none' : 'grid';
  detailHead.style.display = inDetail ? 'flex' : 'none';
  detailGrid.classList.toggle('active', inDetail);
  const toneClasses = Object.values(tones);
  detailHead.classList.remove(...toneClasses);
  detailGrid.classList.remove(...toneClasses);
  if (inDetail && tones[activeStatsDetail]) {
    detailHead.classList.add(tones[activeStatsDetail]);
    detailGrid.classList.add(tones[activeStatsDetail]);
  }
  document.getElementById('stats-detail-title').textContent = titles[activeStatsDetail] || '';
  document.querySelectorAll('.stats-detail-card').forEach(card => {
    card.classList.toggle('active', card.dataset.statsDetail === activeStatsDetail);
  });
}

function scannerReportText(r) {
  return `脚本/扫描器拉取订阅
━━━━━━━━━━━━━━
结论：已读取到订阅Token。
建议：必要时复制 Token 到机场后台手动核对用户。
风险：${r.risk || '高危'}｜评分 ${r.score || 90}
Token：${r.token || ''}
来源：${r.ip || ''}｜${r.location || '未查询'}
ASN：${r.asn || '未查询'}
网络：${r.network_type || '未知网络'}${(r.intel_tags || []).length ? '｜' + (r.intel_tags || []).join('、') : ''}
查询：${r.query_source || '本地日志'}
路径 ${r.path || ''}
UA：${r.ua || '（空UA）'}
证据：原因 ${r.reason || 'unknown'}
时间：${r.time || ''}

操作建议：确认后可封禁 IP ${r.ip || ''}`;
}

async function banScannerIp(ip, token) {
  if (!confirm(`是否封禁脚本/扫描器 IP ${ip}？`)) return;
  const d = await apiFetch('/api/blacklist.php', {
    method:'POST',
    body:JSON.stringify({ip, comment:`脚本/扫描器拉取订阅 token=${token || ''}`}),
    headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
  });
  if (d.ok || (d.error && d.error.includes('已在黑名单'))) {
    toast(`✅ 已封禁 IP ${ip}`);
    blacklistIpSet.add(ip);
    loadStats();
  } else {
    toast(d.error || '封禁失败', 'err');
  }
}

// ── 从分析页加入白名单（不要求先在黑名单）──────────────────────
async function quickWhitelistIp(ip) {
  if (!confirm(`是否将 ${ip} 加入白名单？`)) return;
  const d = await apiFetch('/api/whitelist.php', {
    method: 'POST', body: JSON.stringify({ip, comment: '从分析页加入白名单'}),
    headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
  });
  if (d.ok || (d.error && d.error.includes('已在白名单'))) {
    toast(`✅ ${ip} 已加入白名单并生效`);
    if (allStatsData) {
      allStatsData.susp_ips = (allStatsData.susp_ips || []).filter(r => r.ip !== ip);
      renderStats();
    }
  } else {
    toast(d.error || '加入白名单失败', 'err');
  }
}

// ── 从分析页解封 IP（仅移除黑名单，不加白名单）────────────────────
async function quickUnblockIp(ip) {
  if (!confirm(`是否解封 ${ip}？`)) return;
  const d = await apiFetch('/api/blacklist.php', {
    method:'DELETE', body:JSON.stringify({ip}),
    headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
  });
  if (d.ok) {
    toast(`✅ ${ip} 已解封`);
    blacklistIpSet.delete(ip);
    renderStats();
  } else {
    toast(d.error || '解封失败', 'err');
  }
}

// ── UA 管理 ─────────────────────────────────────────────────────
async function loadUaBlacklist() {
  const [blData, wlData] = await Promise.all([
    apiFetch('/api/ua_blacklist.php'),
    apiFetch('/api/ua_whitelist.php'),
  ]);
  if (!blData.ok) {
    document.getElementById('ua-list').innerHTML = '<div class="empty">加载失败：' + esc(blData.error||'未知错误') + '</div>';
    toast('加载失败: ' + (blData.error||''), 'err');
  } else {
    allUaBlEntries = blData.entries || [];
    renderUaBlacklist();
  }
  if (!wlData.ok) {
    document.getElementById('ua-wl-list').innerHTML = '<div class="empty">加载失败：' + esc(wlData.error||'未知错误') + '</div>';
  } else {
    allUaWlEntries = wlData.entries || [];
    renderUaWhitelist();
  }
}

function setUaBlLimit(n) {
  uaBlLimit = n;
  ['50','100','500','0'].forEach(k => {
    const btn = document.getElementById('ua-bl-limit-' + k);
    if (btn) btn.classList.remove('active');
  });
  const btn = document.getElementById('ua-bl-limit-' + n);
  if (btn) btn.classList.add('active');
  renderUaBlacklist();
}

function setUaWlLimit(n) {
  uaWlLimit = n;
  ['50','100','500','0'].forEach(k => {
    const btn = document.getElementById('ua-wl-limit-' + k);
    if (btn) btn.classList.remove('active');
  });
  const btn = document.getElementById('ua-wl-limit-' + n);
  if (btn) btn.classList.add('active');
  renderUaWhitelist();
}

function renderUaBlacklist() {
  const entries = uaBlLimit > 0 ? allUaBlEntries.slice(0, uaBlLimit) : allUaBlEntries;
  if (!allUaBlEntries.length) {
    document.getElementById('ua-list').innerHTML = '<div class="empty">封禁列表为空</div>';
    return;
  }
  const uaListEl = document.getElementById('ua-list');
  uaListEl.innerHTML = `
    <div class="table-wrap">
    <table><thead><tr><th>UA 关键词</th><th>备注</th><th>添加时间</th><th>操作</th></tr></thead>
    <tbody>${entries.map(e => `
      <tr>
        <td class="ip-cell">${esc(e.ua)}</td>
        ${makeCommentCell('/api/ua_blacklist.php', 'ua', e.ua, e.comment||'')}
        <td style="color:#64748b;font-size:11px">${esc(e.added_at||'')}</td>
        <td><button class="btn-danger" onclick="uaDel(${jsArg(e.ua)})">移除</button></td>
      </tr>`).join('')}
    </tbody></table>
    </div>`;
  attachCommentCells(uaListEl);
}

function renderUaWhitelist() {
  const entries = uaWlLimit > 0 ? allUaWlEntries.slice(0, uaWlLimit) : allUaWlEntries;
  if (!allUaWlEntries.length) {
    document.getElementById('ua-wl-list').innerHTML = '<div class="empty">白名单为空</div>';
    return;
  }
  const uaWlListEl = document.getElementById('ua-wl-list');
  uaWlListEl.innerHTML = `
    <div class="table-wrap">
    <table><thead><tr><th>UA 关键词</th><th>备注</th><th>添加时间</th><th>操作</th></tr></thead>
    <tbody>${entries.map(e => `
      <tr>
        <td class="ip-cell">${esc(e.ua)}</td>
        ${makeCommentCell('/api/ua_whitelist.php', 'ua', e.ua, e.comment||'')}
        <td style="color:#64748b;font-size:11px">${esc(e.added_at||'')}</td>
        <td><button class="btn-danger" onclick="uaWlDel(${jsArg(e.ua)})">移除</button></td>
      </tr>`).join('')}
    </tbody></table>
    </div>`;
  attachCommentCells(uaWlListEl);
}

async function uaAdd() {
  const ua  = document.getElementById('ua-keyword').value.trim();
  const cmt = document.getElementById('ua-comment').value.trim();
  if (!ua) { toast('请输入 UA 关键词','err'); return; }
  const d = await apiFetch('/api/ua_blacklist.php', {
    method:'POST', body:JSON.stringify({ua, comment:cmt}),
    headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
  });
  if (d.ok) {
    document.getElementById('ua-keyword').value = '';
    document.getElementById('ua-comment').value = '';
    toast('✅ 已封禁并立即生效');
    loadUaBlacklist();
  } else {
    toast(d.error||'添加失败','err');
  }
}

async function uaDel(ua) {
  const d = await apiFetch('/api/ua_blacklist.php', {
    method:'DELETE', body:JSON.stringify({ua}),
    headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
  });
  if (d.ok) { toast('✅ 已移除并立即生效'); loadUaBlacklist(); }
  else toast(d.error||'移除失败','err');
}

async function quickBanUA(ua) {
  const cmt = prompt(`封禁 UA "${ua}"，备注（可留空）：`);
  if (cmt === null) return;
  const d = await apiFetch('/api/ua_blacklist.php', {
    method:'POST', body:JSON.stringify({ua, comment:cmt}),
    headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
  });
  if (d.ok) toast(`✅ UA 已封禁`);
  else toast(d.error||'封禁失败','err');
}

// ── UA 白名单 ──────────────────────────────────────────────────
async function uaWlAdd() {
  const ua  = document.getElementById('ua-wl-keyword').value.trim();
  const cmt = document.getElementById('ua-wl-comment').value.trim();
  if (!ua) { toast('请输入 UA 关键词','err'); return; }
  const d = await apiFetch('/api/ua_whitelist.php', {
    method:'POST', body:JSON.stringify({ua, comment:cmt}),
    headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
  });
  if (d.ok) {
    document.getElementById('ua-wl-keyword').value = '';
    document.getElementById('ua-wl-comment').value = '';
    toast('✅ UA 已加入白名单并立即生效');
    loadUaBlacklist();
  } else {
    toast(d.error||'添加失败','err');
  }
}

async function uaWlDel(ua) {
  const d = await apiFetch('/api/ua_whitelist.php', {
    method:'DELETE', body:JSON.stringify({ua}),
    headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
  });
  if (d.ok) { toast('✅ 已从白名单移除并立即生效'); loadUaBlacklist(); }
  else toast(d.error||'移除失败','err');
}

// ── 白名单 ────────────────────────────────────────────────────
async function loadWhitelist() {
  const data = await apiFetch('/api/whitelist.php');
  if (!data.ok) {
    document.getElementById('wl-list').innerHTML = '<div class="empty">加载失败：' + esc(data.error||'未知错误') + '</div>';
    toast('加载失败: ' + (data.error||''), 'err'); return;
  }
  allWlEntries = data.entries || [];
  if (!allWlEntries.length) {
    document.getElementById('wl-list').innerHTML = '<div class="empty">白名单为空</div>';
    return;
  }
  document.getElementById('wl-list').innerHTML = `
    <div class="batch-row">
      <label><input type="checkbox" id="wl-check-all" onchange="toggleAllWl(this)"> 全选</label>
      <button class="btn-danger" onclick="wlBatchDel()">批量删除选中</button>
    </div>
    <div class="table-wrap">
    <table><thead><tr><th style="width:30px"></th><th>IP / CIDR</th><th>备注</th><th>操作</th></tr></thead>
    <tbody>${allWlEntries.map(e => `
      <tr>
        <td><input type="checkbox" class="wl-check" value="${esc(e.ip)}"></td>
        <td class="ip-cell">${esc(e.ip)}</td>
        ${makeCommentCell('/api/whitelist.php', 'ip', e.ip, e.comment||'')}
        <td><button class="btn-danger" onclick="wlDel(${jsArg(e.ip)})">删除</button></td>
      </tr>`).join('')}
    </tbody></table>
    </div>`;
  attachCommentCells(document.getElementById('wl-list'));
}

function exportWhitelist() {
  if (!allWlEntries.length) { toast('白名单为空，无需导出', 'err'); return; }
  const lines = allWlEntries.map(e => e.comment ? `${e.ip}  # ${e.comment}` : e.ip);
  const blob = new Blob([lines.join('\n') + '\n'], {type: 'text/plain'});
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'whitelist_' + new Date().toISOString().slice(0,10) + '.txt';
  a.click();
  URL.revokeObjectURL(a.href);
}

async function importWhitelist(input) {
  const file = input.files[0];
  if (!file) return;
  input.value = '';
  toast('解析中…');
  const text = await file.text();
  const ips = [];
  for (const line of text.split('\n')) {
    const t = line.trim();
    if (!t || t.startsWith('#')) continue;
    const ip = t.split(/[\s#]/)[0].trim();
    if (ip) ips.push(ip);
  }
  if (!ips.length) { toast('文件中未找到有效IP/CIDR', 'err'); return; }
  const d = await apiFetch('/api/whitelist.php', {
    method: 'POST', body: JSON.stringify({import_ips: ips}),
    headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
  });
  if (d.ok) {
    toast(`✅ 导入完成：新增 ${d.added} 个，跳过 ${d.skipped} 个重复${d.invalid ? `，${d.invalid} 个格式错误` : ''}，已立即生效`);
    loadWhitelist();
  } else {
    toast(d.error || '导入失败', 'err');
  }
}

function toggleAllWl(cb) {
  document.querySelectorAll('.wl-check').forEach(c => c.checked = cb.checked);
}

async function wlAdd() {
  const raw = document.getElementById('wl-ip').value.trim();
  const cmt = document.getElementById('wl-comment').value.trim();
  if (!raw) { toast('请输入IP','err'); return; }
  const ips = raw.split(',').map(s => s.trim()).filter(Boolean);
  let ok = 0, errs = [];
  for (const ip of ips) {
    const d = await apiFetch('/api/whitelist.php', {
      method:'POST', body:JSON.stringify({ip, comment:cmt}),
      headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
    });
    if (d.ok) ok++; else errs.push(`${ip}: ${d.error}`);
  }
  document.getElementById('wl-ip').value = '';
  document.getElementById('wl-comment').value = '';
  if (!errs.length) {
    toast(`✅ 已添加 ${ok} 个并生效`);
  } else if (ok) {
    toast(`添加 ${ok} 个成功并生效，${errs.length} 个失败`, 'err');
  } else {
    toast(errs[0]||'添加失败', 'err');
  }
  loadWhitelist();
}

async function wlDel(ip) {
  const d = await apiFetch('/api/whitelist.php', {
    method:'DELETE', body:JSON.stringify({ip}),
    headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
  });
  if (d.ok) { toast('✅ 已删除并生效'); loadWhitelist(); }
  else toast(d.error||'删除失败','err');
}

async function wlBatchDel() {
  const ips = Array.from(document.querySelectorAll('.wl-check:checked')).map(c => c.value);
  if (!ips.length) { toast('请先勾选要删除的条目','err'); return; }
  if (!confirm(`确定删除选中的 ${ips.length} 个IP/CIDR？`)) return;
  const d = await apiFetch('/api/whitelist.php', {
    method:'DELETE', body:JSON.stringify({ips}),
    headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
  });
  if (d.ok) { toast(`✅ 已删除 ${ips.length} 个并生效`); loadWhitelist(); }
  else toast(d.error||'批量删除失败','err');
}

// ── 黑名单 ────────────────────────────────────────────────────
async function loadBlacklist() {
  const data = await apiFetch('/api/blacklist.php');
  if (!data.ok) {
    document.getElementById('bl-list').innerHTML = '<div class="empty">加载失败：' + esc(data.error||'未知错误') + '</div>';
    toast('加载失败: ' + (data.error||''), 'err'); return;
  }
  allBlEntries = data.entries || [];
  const entries = allBlEntries;
  const idcSummary = data.idc_summary || [];

  let html = '';
  if (entries.length) {
    html += `
    <div class="batch-row">
      <label><input type="checkbox" id="bl-check-all" onchange="toggleAllBl(this)"> 全选</label>
      <button class="btn-danger" onclick="blBatchDel()">批量解封选中</button>
    </div>
    <div class="table-wrap">
    <table><thead><tr><th style="width:30px"></th><th>IP / CIDR</th><th>备注</th><th>添加时间</th><th>操作</th></tr></thead>
    <tbody>${entries.map(e => `
      <tr>
        <td><input type="checkbox" class="bl-check" value="${esc(e.ip)}"></td>
        <td class="ip-cell">${esc(e.ip)}</td>
        ${makeCommentCell('/api/blacklist.php', 'ip', e.ip, e.comment||'')}
        <td style="color:#64748b;font-size:11px">${esc(e.added_at||'')}</td>
        <td><button class="btn-danger" onclick="blDel(${jsArg(e.ip)})">解封</button></td>
      </tr>`).join('')}
    </tbody></table>
    </div>`;
  } else {
    html += '<div class="empty">手动黑名单为空</div>';
  }

  if (idcSummary.length) {
    html += `<div class="idc-section">
      <div class="card-title">系统内置IDC封禁（自动拦截，共 ${idcSummary.reduce((s,r)=>s+r.count,0)} 条CIDR）</div>
      <div class="table-wrap">
      <table><thead><tr><th>云服务商 / IDC</th><th>CIDR数量</th></tr></thead>
      <tbody>${idcSummary.map(s => `
        <tr>
          <td class="ip-cell">${esc(s.name)}</td>
          <td style="color:#6366f1;font-weight:600">${s.count} 条</td>
        </tr>`).join('')}
      </tbody></table>
      </div>
    </div>`;
  }

  document.getElementById('bl-list').innerHTML = html;
  attachCommentCells(document.getElementById('bl-list'));
}

function toggleAllBl(cb) {
  document.querySelectorAll('.bl-check').forEach(c => c.checked = cb.checked);
}

async function blBatchDel() {
  const ips = Array.from(document.querySelectorAll('.bl-check:checked')).map(c => c.value);
  if (!ips.length) { toast('请先勾选要解封的条目','err'); return; }
  if (!confirm(`确定解封选中的 ${ips.length} 个IP/CIDR？`)) return;
  const d = await apiFetch('/api/blacklist.php', {
    method:'DELETE', body:JSON.stringify({ips}),
    headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
  });
  if (d.ok) { toast(`✅ 已解封 ${ips.length} 个并立即生效`); loadBlacklist(); }
  else toast(d.error||'批量解封失败','err');
}

async function blAdd() {
  const ip  = document.getElementById('bl-ip').value.trim();
  const cmt = document.getElementById('bl-comment').value.trim();
  if (!ip) { toast('请输入IP','err'); return; }
  const d = await apiFetch('/api/blacklist.php', {
    method:'POST', body:JSON.stringify({ip,comment:cmt}),
    headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
  });
  if (d.ok) {
    document.getElementById('bl-ip').value = '';
    document.getElementById('bl-comment').value = '';
    toast('✅ 已封禁并立即生效');
    loadBlacklist();
  } else {
    toast(d.error||'添加失败','err');
  }
}

async function blDel(ip) {
  const d = await apiFetch('/api/blacklist.php', {
    method:'DELETE', body:JSON.stringify({ip}),
    headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
  });
  if (d.ok) { toast('✅ 已解封并立即生效'); loadBlacklist(); }
  else toast(d.error||'解封失败','err');
}

// ── 黑名单导入/导出 ────────────────────────────────────────────
function exportBlacklist() {
  if (!allBlEntries.length) { toast('黑名单为空，无需导出', 'err'); return; }
  const lines = allBlEntries.map(e => {
    const cmt = e.comment ? `  # ${e.comment} (${e.added_at||''})` : (e.added_at ? `  # ${e.added_at}` : '');
    return e.ip + cmt;
  });
  const blob = new Blob([lines.join('\n') + '\n'], {type: 'text/plain'});
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'blacklist_' + new Date().toISOString().slice(0,10) + '.txt';
  a.click();
  URL.revokeObjectURL(a.href);
}

async function importBlacklist(input) {
  const file = input.files[0];
  if (!file) return;
  input.value = '';
  toast('解析中…');
  const text = await file.text();
  const ips = [];
  for (const line of text.split('\n')) {
    const t = line.trim();
    if (!t || t.startsWith('#')) continue;
    // 跳过 nginx deny 语法行
    const m = t.match(/^(?:deny\s+)?(\d{1,3}(?:\.\d{1,3}){3}(?:\/\d+)?)/);
    if (m) ips.push(m[1]);
  }
  if (!ips.length) { toast('文件中未找到有效IP/CIDR', 'err'); return; }
  const d = await apiFetch('/api/blacklist.php', {
    method: 'POST', body: JSON.stringify({import_ips: ips}),
    headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
  });
  if (d.ok) {
    toast(`✅ 导入完成：新增 ${d.added} 个，跳过 ${d.skipped} 个重复${d.invalid ? `，${d.invalid} 个格式错误` : ''}，nginx 已重载`);
    loadBlacklist();
  } else {
    toast(d.error || '导入失败', 'err');
  }
}

// ── Token黑名单 ────────────────────────────────────────────────
async function loadTokenBlacklist() {
  const data = await apiFetch('/api/token_blacklist.php');
  if (!data.ok) {
    document.getElementById('tb-list').innerHTML = '<div class="empty">加载失败：' + esc(data.error||'未知错误') + '</div>';
    toast('加载失败: ' + (data.error||''), 'err'); return;
  }
  const entries = data.entries || [];
  if (!entries.length) {
    document.getElementById('tb-list').innerHTML = '<div class="empty">Token黑名单为空</div>';
    return;
  }
  document.getElementById('tb-list').innerHTML = `
    <div class="table-wrap">
    <table><thead><tr><th>Token</th><th>今日拉取</th><th>备注</th><th>添加时间</th><th>操作</th></tr></thead>
    <tbody>${entries.map(e => {
      const pullsHtml = e.today_pulls && e.today_pulls.length
        ? e.today_pulls.map(p => `<span style="font-size:11px;color:#94a3b8">${esc(p.ip)}<span style="color:#ef4444;margin-left:3px">${p.count}次</span></span>`).join('&ensp;')
        : '<span style="color:#475569;font-size:11px">今日无拉取</span>';
      const tok = e.token || '';
      const tokDisplay = tok.length > 16 ? tok.substr(0,8)+'…'+tok.slice(-4) : tok;
      return `<tr>
        <td style="font-family:monospace;font-size:12px" title="${esc(tok)}">${esc(tokDisplay)}<button class="copy-btn" data-val="${esc(tok)}" onclick="copyText(this.dataset.val)" style="margin-left:4px">复制</button></td>
        <td>${pullsHtml}</td>
        ${makeCommentCell('/api/token_blacklist.php', 'token', tok, e.comment||'')}
        <td style="color:#64748b;font-size:11px;white-space:nowrap">${esc(e.added_at||'')}</td>
        <td><button class="btn-danger" style="font-size:12px;padding:2px 8px" onclick="tbDel(${jsArg(tok)})">移除</button></td>
      </tr>`;
    }).join('')}
    </tbody></table>
    </div>`;
  attachCommentCells(document.getElementById('tb-list'));
}

async function tbAdd() {
  const tok = document.getElementById('tb-token').value.trim();
  const cmt = document.getElementById('tb-comment').value.trim();
  if (!tok) { toast('请输入 Token', 'err'); return; }
  const d = await apiFetch('/api/token_blacklist.php', {
    method:'POST', body:JSON.stringify({token:tok, comment:cmt}),
    headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
  });
  if (d.ok) {
    document.getElementById('tb-token').value = '';
    document.getElementById('tb-comment').value = '';
    toast('✅ Token 已加入黑名单');
    loadTokenBlacklist();
  } else toast(d.error||'添加失败','err');
}

async function quickBanToken(token) {
  if (!confirm(`将该 Token 加入黑名单？\n${token.substr(0,20)}…`)) return;
  const d = await apiFetch('/api/token_blacklist.php', {
    method:'POST', body:JSON.stringify({token, comment:'从分析页拉黑'}),
    headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
  });
  if (d.ok || (d.error && d.error.includes('已在黑名单'))) {
    toast('✅ Token 已加入黑名单');
    if (allStatsData) {
      allStatsData.susp_tokens = (allStatsData.susp_tokens||[]).filter(r => r.token !== token);
      renderStats();
    }
  } else toast(d.error||'操作失败','err');
}

async function tbDel(token) {
  if (!confirm(`确定移除该 Token？`)) return;
  const d = await apiFetch('/api/token_blacklist.php', {
    method:'DELETE', body:JSON.stringify({token}),
    headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
  });
  if (d.ok) { toast('✅ 已移除'); loadTokenBlacklist(); }
  else toast(d.error||'移除失败','err');
}

// ── 系统设置 ───────────────────────────────────────────────────
let currentSettings = {};

async function loadSettings() {
  const params = new URLSearchParams({
    alert_history_limit: alertHistoryLimit,
    alert_history_page: alertHistoryPage,
    alert_history_filter: alertHistoryFilter,
    alert_history_query: alertHistoryQuery,
    alert_history_range: alertHistoryRange,
  });
  const data = await apiFetch(`/api/settings.php?${params.toString()}`);
  if (!data.ok) { toast('加载设置失败: ' + (data.error||''), 'err'); return; }
  currentSettings = data.settings || {};
  activeSubscribePath = currentSettings.subscribe_path || activeSubscribePath || '/api/v1/client/subscribe';
  updateSubscribePathLabel();
  // 填充界面设置
  document.getElementById('cfg-site-title').value   = currentSettings.site_title || '';
  document.getElementById('cfg-page-title').value   = currentSettings.page_title || '';
  // 填充凭证设置
  document.getElementById('cfg-admin-user').value   = currentSettings.admin_user || '';
  document.getElementById('cfg-new-pass').value     = '';
  document.getElementById('cfg-confirm-pass').value = '';
  // 填充上游设置（分离 URL 和端口）
  const _rawUrl = currentSettings.upstream_url || '';
  let _displayUrl = _rawUrl, _displayPort = 443;
  if (_rawUrl) {
    try {
      const _u = new URL(_rawUrl.match(/^https?:\/\//) ? _rawUrl : 'https://' + _rawUrl);
      _displayPort = _u.port ? parseInt(_u.port, 10) : (_u.protocol === 'https:' ? 443 : 80);
      _displayUrl  = _u.protocol + '//' + _u.hostname;
    } catch(e) {}
  }
  document.getElementById('cfg-upstream-url').value    = _displayUrl;
  document.getElementById('cfg-upstream-port').value   = _displayPort;
  document.getElementById('cfg-subscribe-path').value  = currentSettings.subscribe_path || '';
  // 填充网关端口
  if (currentSettings.gateway_port) {
    document.getElementById('cfg-gateway-port').value = currentSettings.gateway_port;
  }
  const alertEnabled = document.getElementById('cfg-alert-enabled');
  if (alertEnabled) alertEnabled.checked = !!parseInt(currentSettings.alert_enabled || 0, 10);
  const alertChannel = document.getElementById('cfg-alert-channel');
  if (alertChannel) alertChannel.value = currentSettings.alert_channel || 'webhook';
  const alertWebhook = document.getElementById('cfg-alert-webhook-url');
  if (alertWebhook) alertWebhook.value = currentSettings.alert_webhook_url || '';
  const alertTelegramToken = document.getElementById('cfg-alert-telegram-token');
  if (alertTelegramToken) alertTelegramToken.value = currentSettings.alert_telegram_bot_token || '';
  const alertTelegramChat = document.getElementById('cfg-alert-telegram-chat');
  if (alertTelegramChat) alertTelegramChat.value = currentSettings.alert_telegram_chat_id || '';
  const alertScannerScore = document.getElementById('cfg-alert-scanner-score');
  if (alertScannerScore) alertScannerScore.value = currentSettings.alert_scanner_score || 80;
  const alertSuspIpScore = document.getElementById('cfg-alert-susp-ip-score');
  if (alertSuspIpScore) alertSuspIpScore.value = currentSettings.alert_susp_ip_score || 90;
  const alertSuspTokenIps = document.getElementById('cfg-alert-susp-token-ips');
  if (alertSuspTokenIps) alertSuspTokenIps.value = currentSettings.alert_susp_token_ips || 3;
  const alertDedupeMinutes = document.getElementById('cfg-alert-dedupe-minutes');
  if (alertDedupeMinutes) alertDedupeMinutes.value = currentSettings.alert_dedupe_minutes || 60;
  const alertHistoryMax = document.getElementById('cfg-alert-history-max');
  if (alertHistoryMax) alertHistoryMax.value = currentSettings.alert_history_max || 200;
  const alertQuietEnabled = document.getElementById('cfg-alert-quiet-enabled');
  if (alertQuietEnabled) alertQuietEnabled.checked = !!parseInt(currentSettings.alert_quiet_enabled || 0, 10);
  const alertQuietStart = document.getElementById('cfg-alert-quiet-start');
  if (alertQuietStart) alertQuietStart.value = currentSettings.alert_quiet_start || '23:00';
  const alertQuietEnd = document.getElementById('cfg-alert-quiet-end');
  if (alertQuietEnd) alertQuietEnd.value = currentSettings.alert_quiet_end || '08:00';
  // 显示证书信息
  const cert = data.cert || {};
  const certEl = document.getElementById('cert-info');
  if (!cert.exists) {
    certEl.innerHTML = '<div class="empty" style="color:#ef4444">⚠️ 未找到证书文件</div>';
  } else if (cert.subject) {
    const color = cert.days_left > 30 ? '#22c55e' : cert.days_left > 7 ? '#eab308' : '#ef4444';
    certEl.innerHTML = `
      <table style="font-size:12px;width:100%">
        <tr><td style="color:var(--text3);padding:4px 0;white-space:nowrap">域名</td><td style="color:var(--text);padding:4px 0 4px 10px">${esc(cert.subject)}</td></tr>
        <tr><td style="color:var(--text3);padding:4px 0;white-space:nowrap">颁发机构</td><td style="color:var(--text2);padding:4px 0 4px 10px">${esc(cert.issuer)}</td></tr>
        <tr><td style="color:var(--text3);padding:4px 0;white-space:nowrap">有效期</td><td style="padding:4px 0 4px 10px">${esc(cert.valid_from)} ~ ${esc(cert.valid_to)}</td></tr>
        <tr><td style="color:var(--text3);padding:4px 0;white-space:nowrap">剩余天数</td><td style="color:${color};font-weight:600;padding:4px 0 4px 10px">${cert.days_left} 天</td></tr>
        ${cert.san ? `<tr><td style="color:var(--text3);padding:4px 0;white-space:nowrap">SAN</td><td style="color:var(--text3);font-size:11px;padding:4px 0 4px 10px;word-break:break-all">${esc(cert.san)}</td></tr>` : ''}
      </table>`;
  } else {
    certEl.innerHTML = '<div class="empty" style="color:#eab308">证书存在但无法解析（可能是非标准格式）</div>';
  }
  renderStatsCacheInfo(data.stats_cache || {});
  renderAlertHistory(data.alert_history || {});
}

function renderStatsCacheInfo(cache) {
  const el = document.getElementById('stats-cache-info');
  if (!el) return;
  if (!cache.exists) {
    el.innerHTML = '<div class="empty" style="color:#eab308">统计缓存尚未生成，后台预热完成后会自动出现</div>';
    return;
  }
  const age = cache.age_seconds == null ? '未知' : formatDuration(cache.age_seconds);
  const color = cache.fresh ? '#22c55e' : '#eab308';
  const status = cache.fresh ? '正常' : '待更新';
  el.innerHTML = `
    <table style="font-size:12px;width:100%">
      <tr><td style="color:var(--text3);padding:4px 0;white-space:nowrap">状态</td><td style="color:${color};font-weight:700;padding:4px 0 4px 10px">${status}</td></tr>
      <tr><td style="color:var(--text3);padding:4px 0;white-space:nowrap">更新时间</td><td style="color:var(--text);padding:4px 0 4px 10px">${esc(cache.mtime || '-')}</td></tr>
      <tr><td style="color:var(--text3);padding:4px 0;white-space:nowrap">距今</td><td style="padding:4px 0 4px 10px">${esc(age)}</td></tr>
      <tr><td style="color:var(--text3);padding:4px 0;white-space:nowrap">缓存大小</td><td style="padding:4px 0 4px 10px">${esc(cache.size_text || '-')}</td></tr>
      <tr><td style="color:var(--text3);padding:4px 0;white-space:nowrap">扫描范围</td><td style="padding:4px 0 4px 10px">最近 ${esc(cache.scan_limit || 30000)} 行日志</td></tr>
    </table>`;
}

function formatDuration(seconds) {
  seconds = Math.max(0, parseInt(seconds || 0, 10));
  if (seconds < 60) return `${seconds} 秒前`;
  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) return `${minutes} 分钟前`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours} 小时前`;
  return `${Math.floor(hours / 24)} 天前`;
}

function renderAlertHistory(history) {
  const el = document.getElementById('alert-history-info');
  if (!el) return;
  lastAlertHistory = history || {};
  const status = history.status || {};
  const entries = history.entries || [];
  const filteredEntries = entries;
  const page = Math.max(1, parseInt(history.page || alertHistoryPage || 1, 10));
  const totalPages = Math.max(1, parseInt(history.total_pages || 1, 10));
  const filteredTotal = parseInt(history.filtered_total ?? entries.length, 10);
  const pageStart = filteredEntries.length ? ((page - 1) * alertHistoryLimit + 1) : 0;
  const pageEnd = filteredEntries.length ? Math.min(pageStart + filteredEntries.length - 1, filteredTotal) : 0;
  const pageRangeText = filteredEntries.length ? `第 ${pageStart}-${pageEnd} 条` : '暂无记录';
  alertHistoryPage = page;
  const historySummary = history.summary || {};
  const quietSummary = history.quiet_summary || {};
  const historyRangeText = historySummary.first_time && historySummary.last_time
    ? `${formatAlertHistoryTime(historySummary.first_time)} ~ ${formatAlertHistoryTime(historySummary.last_time)}`
    : '';
  const enabled = !!status.enabled;
  const ok = !status.errors || status.errors.length === 0;
  const badgeColor = enabled ? (ok ? '#22c55e' : '#ef4444') : '#94a3b8';
  const stateText = enabled ? (ok ? '运行中' : '有错误') : '未开启';
  const lastCheck = status.last_check || '尚未检查';
  const dedupeText = status.dedupe_seconds ? `${Math.round(status.dedupe_seconds / 60)} 分钟` : '按设置';
  const quietText = status.quiet_active ? `静默中 ${status.quiet_window || ''}` : (status.quiet_window ? `未静默 ${status.quiet_window}` : '未设置');
  const noteMap = {
    disabled: '告警未开启',
    missing_cache: '统计缓存尚未生成',
    empty_cache: '统计缓存为空',
    history_cleared: '告警记录已清空',
    reset: '告警记录和去重状态已重置',
  };
  const note = status.note ? `<div style="color:var(--text3);font-size:12px;margin-top:4px">${esc(noteMap[status.note] || status.note)}</div>` : '';
  const quietSummaryHtml = quietSummary.count ? `
    <div style="background:rgba(234,179,8,.10);border:1px solid rgba(234,179,8,.28);border-radius:8px;padding:9px 10px;margin-bottom:10px">
      <div style="color:#eab308;font-weight:800;font-size:12px">静默摘要 · ${esc(quietSummary.count)} 条</div>
      <div style="color:var(--text);font-weight:700;font-size:12px;margin-top:4px;word-break:break-word">${esc(quietSummary.latest_title || '静默事件')}</div>
      <div style="color:var(--text3);font-size:11px;line-height:1.45;margin-top:3px;word-break:break-all">${esc(quietSummary.latest_summary || '')}</div>
      <div style="color:var(--text3);font-size:11px;margin-top:3px">${esc(quietSummary.latest_time || '')}</div>
    </div>` : '';
  const filterItems = [
    ['all', '全部'],
    ['sent', '已推送'],
    ['muted', '静默'],
    ['error', '失败'],
  ];
  const rangeItems = [
    ['all', '全部时间'],
    ['today', '今天'],
    ['24h', '近24小时'],
    ['7d', '近7天'],
  ];
  const filterOptions = filterItems.map(([value, label]) => `<option value="${value}"${alertHistoryFilter === value ? ' selected' : ''}>${label}</option>`).join('');
  const rangeOptions = rangeItems.map(([value, label]) => `<option value="${value}"${alertHistoryRange === value ? ' selected' : ''}>${label}</option>`).join('');
  const limitOptions = [10, 25, 50].map(n => `<option value="${n}"${alertHistoryLimit === n ? ' selected' : ''}>${n}条</option>`).join('');
  const filterLabel = (filterItems.find(([value]) => value === alertHistoryFilter) || filterItems[0])[1];
  const rangeLabel = (rangeItems.find(([value]) => value === alertHistoryRange) || rangeItems[0])[1];
  const queryChip = alertHistoryQuery ? `<button type="button" class="alert-history-chip alert-history-chip-btn" onclick="clearAlertHistoryQuery()" title="清空关键词"><span>关键词：${esc(alertHistoryQuery)} ×</span></button>` : '';
  const activeFilterChips = `
    <div class="alert-history-filters">
      <button type="button" class="alert-history-chip alert-history-chip-btn" onclick="setAlertHistoryFilter('all')" title="恢复全部状态"><span>状态：${esc(filterLabel)}${alertHistoryFilter !== 'all' ? ' ×' : ''}</span></button>
      <button type="button" class="alert-history-chip alert-history-chip-btn" onclick="setAlertHistoryRange('all')" title="恢复全部时间"><span>时间：${esc(rangeLabel)}${alertHistoryRange !== 'all' ? ' ×' : ''}</span></button>
      <button type="button" class="alert-history-chip alert-history-chip-btn" onclick="setAlertHistoryLimit(10)" title="恢复每页 10 条"><span>每页：${esc(alertHistoryLimit)} 条${alertHistoryLimit !== 10 ? ' ×' : ''}</span></button>
      ${queryChip}
    </div>`;
  const rows = filteredEntries.length ? filteredEntries.map(e => {
    const label = alertEntryStatusLabel(e);
    const color = alertEntryStatusColor(e);
    const report = formatAlertEntryText(e);
    const timeText = formatAlertHistoryTime(e.time || '');
    return `
      <div class="alert-history-row">
        <span style="color:${color};font-weight:800;font-size:12px;white-space:nowrap">${label}</span>
        <div style="min-width:0">
          <div style="font-weight:700;color:var(--text);font-size:12px;word-break:break-word">${esc(e.title || '告警')}</div>
          <div style="color:var(--text3);font-size:11px;line-height:1.45;word-break:break-all">${esc(e.summary || '')}</div>
          <div style="color:var(--text3);font-size:11px;margin-top:3px">${esc(timeText)} · ${esc(e.channel || '-')}</div>
        </div>
        <button class="copy-btn alert-history-action" data-val="${esc(report)}" onclick="copyText(this.dataset.val)">复制</button>
        <button class="copy-btn alert-history-action" data-key="${esc(e.key || '')}" data-time="${esc(e.time || '')}" data-status="${esc(e.status || '')}" onclick="deleteAlertHistoryEntry(this.dataset.key,this.dataset.time,this.dataset.status)" style="color:#ef4444">删除</button>
      </div>`;
  }).join('') : `<div class="empty" style="font-size:12px;color:var(--text3);padding-top:8px">${filteredTotal ? '当前页暂无记录' : '当前条件暂无记录'}</div>`;
  const pager = totalPages > 1 ? `
    <div style="display:flex;align-items:center;gap:8px;justify-content:center;flex-wrap:wrap;margin-top:10px">
      <button class="mode-btn" onclick="setAlertHistoryPage(1)" ${page <= 1 ? 'disabled' : ''}>首页</button>
      <button class="mode-btn" onclick="setAlertHistoryPage(${page - 1})" ${page <= 1 ? 'disabled' : ''}>上一页</button>
      <span style="color:var(--text2);font-weight:800;font-size:12px">第 ${esc(page)} / ${esc(totalPages)} 页</span>
      <button class="mode-btn" onclick="setAlertHistoryPage(${page + 1})" ${page >= totalPages ? 'disabled' : ''}>下一页</button>
      <button class="mode-btn" onclick="setAlertHistoryPage(${totalPages})" ${page >= totalPages ? 'disabled' : ''}>末页</button>
      <input class="ip-input" id="alert-history-page-jump" type="number" min="1" max="${esc(totalPages)}" placeholder="页码" style="width:72px;height:32px;padding:4px 8px;font-size:12px" onkeydown="if(event.key==='Enter') jumpAlertHistoryPage(${totalPages})">
      <button class="mode-btn" onclick="jumpAlertHistoryPage(${totalPages})" style="height:32px;padding:0 10px;font-size:12px">跳转</button>
    </div>` : '';
  el.innerHTML = `
    <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px">
      <div>
        <div style="font-weight:800;color:var(--text)">告警状态</div>
        <div style="color:var(--text3);font-size:12px;margin-top:3px">最近检查：${esc(lastCheck)}</div>
        <div style="color:var(--text3);font-size:12px;margin-top:3px">去重窗口：${esc(dedupeText)}</div>
        <div style="color:var(--text3);font-size:12px;margin-top:3px">静默状态：${esc(quietText)}</div>
        ${note}
      </div>
      <span style="color:${badgeColor};font-weight:900;white-space:nowrap">${stateText}</span>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(70px,1fr));gap:8px;margin-bottom:10px">
      <div style="background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:8px"><div style="color:var(--text3);font-size:11px">事件</div><div style="font-weight:900">${esc(status.events ?? 0)}</div></div>
      <div style="background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:8px"><div style="color:var(--text3);font-size:11px">推送</div><div style="font-weight:900">${esc(status.sent ?? 0)}</div></div>
      <div style="background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:8px"><div style="color:var(--text3);font-size:11px">去重</div><div style="font-weight:900">${esc(status.skipped ?? 0)}</div></div>
      <div style="background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:8px"><div style="color:var(--text3);font-size:11px">静默</div><div style="font-weight:900">${esc(status.muted ?? 0)}</div></div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(70px,1fr));gap:8px;margin-bottom:10px">
      <div style="background:rgba(99,102,241,.08);border:1px solid rgba(99,102,241,.18);border-radius:8px;padding:8px"><div style="color:var(--text3);font-size:11px">历史</div><div style="font-weight:900">${esc(historySummary.total ?? history.total ?? 0)}</div></div>
      <div style="background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.18);border-radius:8px;padding:8px"><div style="color:var(--text3);font-size:11px">已推送</div><div style="font-weight:900">${esc(historySummary.sent ?? 0)}</div></div>
      <div style="background:rgba(234,179,8,.08);border:1px solid rgba(234,179,8,.18);border-radius:8px;padding:8px"><div style="color:var(--text3);font-size:11px">静默</div><div style="font-weight:900">${esc(historySummary.muted ?? 0)}</div></div>
      <div style="background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.18);border-radius:8px;padding:8px"><div style="color:var(--text3);font-size:11px">失败</div><div style="font-weight:900">${esc(historySummary.error ?? 0)}</div></div>
      <div style="background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:8px"><div style="color:var(--text3);font-size:11px">上限</div><div style="font-weight:900">${esc(historySummary.history_max ?? currentSettings.alert_history_max ?? 200)}</div></div>
    </div>
    ${historyRangeText ? `<div style="color:var(--text3);font-size:11px;line-height:1.5;margin:-4px 0 10px">历史范围：${esc(historyRangeText)}</div>` : ''}
    ${quietSummaryHtml}
    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:6px">
      <div style="font-weight:800;color:var(--text);font-size:12px;flex:1 1 140px">最近记录 · ${esc(pageRangeText)} · 共 ${esc(filteredTotal)} 条</div>
      <select class="ip-input" style="width:auto;min-width:82px;height:32px;padding:4px 8px;font-size:12px" onchange="setAlertHistoryLimit(this.value)">
        ${limitOptions}
      </select>
      <select class="ip-input" style="width:auto;min-width:92px;height:32px;padding:4px 8px;font-size:12px" onchange="setAlertHistoryFilter(this.value)">
        ${filterOptions}
      </select>
      <select class="ip-input" style="width:auto;min-width:104px;height:32px;padding:4px 8px;font-size:12px" onchange="setAlertHistoryRange(this.value)">
        ${rangeOptions}
      </select>
      <button class="mode-btn" onclick="resetAlertHistoryFilters()" style="height:32px;padding:0 10px;font-size:12px">重置</button>
    </div>
    <div class="alert-history-search">
      <input class="ip-input" id="alert-history-query" value="${esc(alertHistoryQuery)}" placeholder="搜索 IP / Token / 错误原因" oninput="setAlertHistoryQuery(this.value)" onkeydown="if(event.key==='Escape') clearAlertHistoryQuery(); if(event.key==='Enter') submitAlertHistoryQuery()">
      <button class="mode-btn" onclick="submitAlertHistoryQuery()">搜索</button>
      <button class="mode-btn" onclick="clearAlertHistoryQuery()" ${alertHistoryQuery ? '' : 'disabled'}>清空</button>
    </div>
    <div id="alert-history-query-state" style="min-height:16px;color:var(--text3);font-size:11px;margin-bottom:2px"></div>
    ${activeFilterChips}
    ${rows}
    ${pager}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:8px;margin-top:10px">
      <button class="mode-btn" onclick="copyAlertHistorySummary()">复制摘要</button>
      <button class="mode-btn" onclick="copyFilteredAlertHistory()">复制当前页</button>
      <button class="mode-btn" onclick="exportCurrentAlertHistoryPage()">导出当前页</button>
      <button class="mode-btn" onclick="exportAlertHistory()">导出全部</button>
      <button class="mode-btn" onclick="document.getElementById('alert-history-import-file').click()">导入记录</button>
      <button class="mode-btn" onclick="clearAlertHistory(false)">清空记录</button>
      <button class="mode-btn" onclick="clearAlertHistory(true)">重置去重</button>
    </div>`;
}

function setAlertHistoryFilter(value) {
  alertHistoryFilter = ['all', 'sent', 'muted', 'error'].includes(value) ? value : 'all';
  alertHistoryPage = 1;
  loadSettings();
}

function setAlertHistoryQuery(value) {
  alertHistoryQuery = value || '';
  alertHistoryPage = 1;
  clearTimeout(alertHistoryQueryTimer);
  setAlertHistoryQueryState(alertHistoryQuery ? '等待输入停止…' : '');
  alertHistoryQueryTimer = setTimeout(() => {
    setAlertHistoryQueryState(alertHistoryQuery ? '正在搜索…' : '');
    loadSettings();
  }, 350);
}

function setAlertHistoryQueryState(text) {
  const el = document.getElementById('alert-history-query-state');
  if (el) el.textContent = text || '';
}

function submitAlertHistoryQuery() {
  const input = document.getElementById('alert-history-query');
  alertHistoryQuery = input ? input.value : alertHistoryQuery;
  alertHistoryPage = 1;
  clearTimeout(alertHistoryQueryTimer);
  setAlertHistoryQueryState(alertHistoryQuery ? '正在搜索…' : '');
  loadSettings();
}

function clearAlertHistoryQuery() {
  alertHistoryQuery = '';
  alertHistoryPage = 1;
  clearTimeout(alertHistoryQueryTimer);
  setAlertHistoryQueryState('');
  const input = document.getElementById('alert-history-query');
  if (input) input.value = '';
  loadSettings();
}

function setAlertHistoryRange(value) {
  alertHistoryRange = ['all', 'today', '24h', '7d'].includes(value) ? value : 'all';
  alertHistoryPage = 1;
  loadSettings();
}

function resetAlertHistoryFilters() {
  alertHistoryFilter = 'all';
  alertHistoryRange = 'all';
  alertHistoryQuery = '';
  alertHistoryPage = 1;
  clearTimeout(alertHistoryQueryTimer);
  setAlertHistoryQueryState('');
  loadSettings();
}

function setAlertHistoryLimit(value) {
  const n = parseInt(value, 10);
  alertHistoryLimit = [10, 25, 50].includes(n) ? n : 10;
  alertHistoryPage = 1;
  loadSettings();
}

function setAlertHistoryPage(page) {
  alertHistoryPage = Math.max(1, parseInt(page, 10) || 1);
  loadSettings();
}

function jumpAlertHistoryPage(totalPages) {
  const input = document.getElementById('alert-history-page-jump');
  const maxPage = Math.max(1, parseInt(totalPages, 10) || 1);
  const page = Math.min(maxPage, Math.max(1, parseInt(input?.value || '1', 10) || 1));
  setAlertHistoryPage(page);
}

function alertEntryStatusLabel(e) {
  return e.status === 'error' ? '失败' : (e.status === 'muted' ? '静默' : '已推送');
}

function alertEntryStatusColor(e) {
  return e.status === 'error' ? '#ef4444' : (e.status === 'muted' ? '#eab308' : '#22c55e');
}

function formatAlertEntryText(e) {
  return [
    `状态：${alertEntryStatusLabel(e)}`,
    `标题：${e.title || '告警'}`,
    `摘要：${e.summary || '-'}`,
    `时间：${formatAlertHistoryTime(e.time || '')}`,
    `渠道：${e.channel || '-'}`,
    `Key：${e.key || '-'}`,
  ].join('\n');
}

function formatAlertHistoryTime(value) {
  if (!value) return '-';
  const normalized = String(value).replace(' ', 'T');
  const ts = Date.parse(normalized);
  if (!Number.isFinite(ts)) return value;
  const seconds = Math.max(0, Math.floor((Date.now() - ts) / 1000));
  return `${value} · ${formatDuration(seconds)}`;
}

function currentFilteredAlertEntries() {
  const entries = (lastAlertHistory && lastAlertHistory.entries) || [];
  return entries;
}

function alertHistoryContextData(rows) {
  const statusMap = {all: '全部', sent: '已推送', muted: '静默', error: '失败'};
  const rangeMap = {all: '全部时间', today: '今天', '24h': '近24小时', '7d': '近7天'};
  const total = parseInt((lastAlertHistory && lastAlertHistory.filtered_total) ?? rows.length, 10);
  const start = rows.length ? ((alertHistoryPage - 1) * alertHistoryLimit + 1) : 0;
  const end = rows.length ? Math.min(start + rows.length - 1, total) : 0;
  return {
    status: alertHistoryFilter,
    status_label: statusMap[alertHistoryFilter] || alertHistoryFilter,
    range: alertHistoryRange,
    range_label: rangeMap[alertHistoryRange] || alertHistoryRange,
    query: alertHistoryQuery,
    page: alertHistoryPage,
    limit: alertHistoryLimit,
    start,
    end,
    total,
    range_label_text: rows.length ? `第 ${start}-${end} 条` : '暂无记录',
  };
}

function alertHistoryContextText(rows) {
  const ctx = alertHistoryContextData(rows);
  return [
    `筛选：状态 ${ctx.status_label}｜时间 ${ctx.range_label}｜关键词 ${ctx.query || '-'}`,
    `页码：第 ${ctx.page} 页｜每页 ${ctx.limit} 条｜范围 ${ctx.range_label_text}｜共 ${ctx.total} 条`,
  ];
}

function alertExportSlug(value) {
  return String(value || 'all').replace(/[^a-zA-Z0-9_-]+/g, '-').replace(/^-+|-+$/g, '') || 'all';
}

function copyFilteredAlertHistory() {
  const rows = currentFilteredAlertEntries();
  if (!rows.length) {
    toast('当前没有可复制的告警记录', 'err');
    return;
  }
  const text = [
    'SubSieve 告警历史当前页',
    ...alertHistoryContextText(rows),
    '',
    ...rows.map((e, idx) => `#${idx + 1}\n${formatAlertEntryText(e)}`),
  ].join('\n\n');
  copyText(text);
}

function copyAlertHistorySummary() {
  const rows = currentFilteredAlertEntries();
  if (!rows.length) {
    toast('当前页没有可复制的摘要', 'err');
    return;
  }
  const summary = (lastAlertHistory && lastAlertHistory.summary) || {};
  const statusCounts = rows.reduce((acc, e) => {
    const key = e.status || 'sent';
    acc[key] = (acc[key] || 0) + 1;
    return acc;
  }, {});
  const lines = [
    'SubSieve 告警历史摘要',
    ...alertHistoryContextText(rows),
    `全量历史：${summary.total ?? '-'} 条｜已推送 ${summary.sent ?? 0}｜静默 ${summary.muted ?? 0}｜失败 ${summary.error ?? 0}`,
    `当前页分布：已推送 ${statusCounts.sent || 0}｜静默 ${statusCounts.muted || 0}｜失败 ${statusCounts.error || 0}`,
    '',
    '当前页前 5 条：',
    ...rows.slice(0, 5).map((e, idx) => `${idx + 1}. [${alertEntryStatusLabel(e)}] ${e.title || '告警'}｜${e.summary || '-'}｜${formatAlertHistoryTime(e.time || '')}`),
  ];
  copyText(lines.join('\n'));
}

async function deleteAlertHistoryEntry(key, time, status) {
  if (!confirm('确定删除这条告警记录？不会影响去重状态。')) return;
  const d = await apiFetch('/api/settings.php', {
    method: 'POST',
    body: JSON.stringify({_delete_alert_history_entry: 1, key, time, status}),
    headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
  });
  if (d.ok) {
    toast('✅ 已删除告警记录');
    await loadSettings();
  } else {
    toast(d.error || '删除失败', 'err');
  }
}


async function saveTitleSettings() {
  const d = await apiFetch('/api/settings.php', {
    method: 'POST',
    body: JSON.stringify({
      site_title: document.getElementById('cfg-site-title').value.trim(),
      page_title: document.getElementById('cfg-page-title').value.trim(),
    }),
    headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
  });
  if (d.ok) { toast('✅ 标题设置已保存，刷新页面生效'); }
  else toast(d.error || '保存失败', 'err');
}

async function saveCredSettings() {
  const user    = document.getElementById('cfg-admin-user').value.trim();
  const newPass = document.getElementById('cfg-new-pass').value;
  const confPass= document.getElementById('cfg-confirm-pass').value;
  if (!user) { toast('用户名不能为空', 'err'); return; }
  const body = {admin_user: user};
  if (newPass) { body.new_pass = newPass; body.confirm_pass = confPass; }
  const d = await apiFetch('/api/settings.php', {
    method: 'POST', body: JSON.stringify(body),
    headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
  });
  if (d.ok) {
    toast('✅ 凭证已更新，请重新登录');
    document.getElementById('cfg-new-pass').value = '';
    document.getElementById('cfg-confirm-pass').value = '';
    if (newPass) setTimeout(() => location.reload(), 2000);
  } else {
    toast(d.error || '保存失败', 'err');
  }
}

async function saveGatewayPort() {
  const portStr = document.getElementById('cfg-gateway-port').value.trim();
  const port = parseInt(portStr, 10);
  if (isNaN(port) || port < 1 || port > 65535) { toast('端口号无效（1-65535）', 'err'); return; }
  const d = await apiFetch('/api/settings.php', {
    method: 'POST', body: JSON.stringify({gateway_port: port}),
    headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
  });
  if (d.ok) {
    toast('✅ ' + (d.msg || '网关端口已保存'));
    loadSettings();
  } else {
    toast(d.error || '保存失败', 'err');
  }
}

async function saveUpstreamSettings() {
  let urlRaw   = document.getElementById('cfg-upstream-url').value.trim();
  const path   = document.getElementById('cfg-subscribe-path').value.trim();
  const portStr= document.getElementById('cfg-upstream-port').value.trim();
  if (!urlRaw && !path) { toast('请填写机场地址或订阅路径', 'err'); return; }
  const body = {};
  if (urlRaw) {
    let url = urlRaw.match(/^https?:\/\//) ? urlRaw : 'https://' + urlRaw;
    if (portStr !== '') {
      const port = parseInt(portStr, 10);
      if (isNaN(port) || port < 1 || port > 65535) { toast('端口号无效（1-65535）', 'err'); return; }
      try {
        const u = new URL(url);
        const defaultPort = u.protocol === 'https:' ? 443 : 80;
        u.port = (port !== defaultPort) ? String(port) : '';
        body.upstream_url = u.protocol + '//' + u.host;
      } catch(e) { body.upstream_url = url; }
    } else {
      body.upstream_url = url;
    }
  }
  if (path) body.subscribe_path = path;
  const d = await apiFetch('/api/settings.php', {
    method: 'POST', body: JSON.stringify(body),
    headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
  });
  if (d.ok) {
    toast('✅ ' + (d.msg || '上游配置已更新'));
    if (path) activeSubscribePath = path;
    await loadSettings();
    renderLogs();
  } else {
    toast(d.error || '保存失败', 'err');
  }
}

function getAlertSettingsPayload() {
  return {
    alert_enabled: document.getElementById('cfg-alert-enabled').checked ? 1 : 0,
    alert_channel: document.getElementById('cfg-alert-channel').value || 'webhook',
    alert_webhook_url: document.getElementById('cfg-alert-webhook-url').value.trim(),
    alert_telegram_bot_token: document.getElementById('cfg-alert-telegram-token').value.trim(),
    alert_telegram_chat_id: document.getElementById('cfg-alert-telegram-chat').value.trim(),
    alert_scanner_score: parseInt(document.getElementById('cfg-alert-scanner-score').value || '80', 10),
    alert_susp_ip_score: parseInt(document.getElementById('cfg-alert-susp-ip-score').value || '90', 10),
    alert_susp_token_ips: parseInt(document.getElementById('cfg-alert-susp-token-ips').value || '3', 10),
    alert_dedupe_minutes: parseInt(document.getElementById('cfg-alert-dedupe-minutes').value || '60', 10),
    alert_history_max: parseInt(document.getElementById('cfg-alert-history-max').value || '200', 10),
    alert_quiet_enabled: document.getElementById('cfg-alert-quiet-enabled').checked ? 1 : 0,
    alert_quiet_start: document.getElementById('cfg-alert-quiet-start').value || '23:00',
    alert_quiet_end: document.getElementById('cfg-alert-quiet-end').value || '08:00',
  };
}

function applyAlertPreset(name) {
  const presets = {
    strict: {scanner: 75, ip: 80, tokenIps: 2, dedupe: 30, label: '严格'},
    balanced: {scanner: 80, ip: 90, tokenIps: 3, dedupe: 60, label: '均衡'},
    quiet: {scanner: 95, ip: 100, tokenIps: 5, dedupe: 180, label: '安静'},
  };
  const p = presets[name] || presets.balanced;
  document.getElementById('cfg-alert-scanner-score').value = p.scanner;
  document.getElementById('cfg-alert-susp-ip-score').value = p.ip;
  document.getElementById('cfg-alert-susp-token-ips').value = p.tokenIps;
  document.getElementById('cfg-alert-dedupe-minutes').value = p.dedupe;
  toast(`已套用${p.label}预设，保存后生效`);
}

function validateAlertPayload(body, forTest = false) {
  const checks = [
    ['扫描器评分阈值', body.alert_scanner_score, 1, 100],
    ['可疑 IP 评分阈值', body.alert_susp_ip_score, 1, 100],
    ['Token IP 数阈值', body.alert_susp_token_ips, 2, 50],
    ['去重分钟', body.alert_dedupe_minutes, 1, 1440],
    ['历史保留条数', body.alert_history_max, 50, 1000],
  ];
  for (const [name, value, min, max] of checks) {
    if (!Number.isFinite(value) || value < min || value > max) {
      toast(`${name}需在 ${min}-${max} 之间`, 'err');
      return false;
    }
  }
  if (body.alert_quiet_enabled && (!/^\d{2}:\d{2}$/.test(body.alert_quiet_start) || !/^\d{2}:\d{2}$/.test(body.alert_quiet_end))) {
    toast('静默时段格式无效', 'err');
    return false;
  }
  if (!body.alert_enabled && forTest) body.alert_enabled = 1;
  if (!body.alert_enabled && !forTest) return true;
  if (body.alert_channel === 'telegram') {
    if (!body.alert_telegram_bot_token || !body.alert_telegram_chat_id) {
      toast('请填写 Telegram Bot Token 和 Chat ID', 'err');
      return false;
    }
    return true;
  }
  if (!body.alert_webhook_url) {
    toast('请填写 Webhook URL', 'err');
    return false;
  }
  return true;
}

async function saveAlertSettings() {
  const body = getAlertSettingsPayload();
  if (!validateAlertPayload(body, false)) return;
  const d = await apiFetch('/api/settings.php', {
    method: 'POST', body: JSON.stringify(body),
    headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
  });
  if (d.ok) {
    toast('✅ 告警设置已保存');
    await loadSettings();
  } else {
    toast(d.error || '保存失败', 'err');
  }
}

async function testAlertSettings() {
  const body = getAlertSettingsPayload();
  body._test_alert = 1;
  body.alert_enabled = 1;
  if (!validateAlertPayload(body, true)) return;
  const d = await apiFetch('/api/settings.php', {
    method: 'POST', body: JSON.stringify(body),
    headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
  });
  if (d.ok) toast('✅ 测试推送已发送');
  else toast(d.error || '测试失败', 'err');
}

async function runAlertCheckNow() {
  toast('正在检查告警…');
  const d = await apiFetch('/api/settings.php', {
    method: 'POST',
    body: JSON.stringify({_run_alert_check: 1}),
    headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
  });
  if (d.ok) {
    const r = d.result || {};
    toast(`✅ 检查完成：事件 ${r.events || 0}，推送 ${r.sent || 0}，去重 ${r.skipped || 0}`);
    await loadSettings();
  } else {
    toast(d.error || '检查失败', 'err');
    await loadSettings();
  }
}

async function clearAlertHistory(resetState) {
  const msg = resetState
    ? '重置去重后，同一高危事件可以再次推送。确定继续？'
    : '确定清空告警展示记录？';
  if (!confirm(msg)) return;
  const d = await apiFetch('/api/settings.php', {
    method: 'POST',
    body: JSON.stringify({_clear_alert_history: 1, reset_state: resetState ? 1 : 0}),
    headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
  });
  if (d.ok) {
    toast('✅ ' + (d.msg || '已处理'));
    await loadSettings();
  } else {
    toast(d.error || '操作失败', 'err');
  }
}

function exportAlertHistory() {
  const a = document.createElement('a');
  a.href = BASE + '/api/settings.php?export_alert_history=1';
  a.download = '';
  a.click();
}

function exportCurrentAlertHistoryPage() {
  const entries = currentFilteredAlertEntries();
  if (!entries.length) {
    toast('当前页没有可导出的告警记录', 'err');
    return;
  }
  const context = alertHistoryContextData(entries);
  const payload = {
    exported_at: new Date().toISOString(),
    scope: 'current_page',
    context,
    entries,
  };
  const blob = new Blob([JSON.stringify(payload, null, 2)], {type: 'application/json;charset=utf-8'});
  const a = document.createElement('a');
  const stamp = new Date().toISOString().replace(/[:.]/g, '-');
  const status = alertExportSlug(context.status);
  const range = alertExportSlug(context.range);
  a.href = URL.createObjectURL(blob);
  a.download = `subsieve-alert-${status}-${range}-p${context.page}-${stamp}.json`;
  a.click();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
  toast(`✅ 已导出当前页 ${entries.length} 条`);
}

async function importAlertHistory(input) {
  const file = input.files[0];
  input.value = '';
  if (!file) return;
  const previewFd = new FormData();
  previewFd.append('history', file);
  try {
    const previewRes = await fetch(BASE + '/api/settings.php?preview_alert_history=1', {
      method: 'POST',
      body: previewFd,
      headers: {'X-Requested-With': 'XMLHttpRequest'},
      credentials: 'same-origin',
    });
    const previewData = await previewRes.json();
    if (!previewData.ok) {
      toast(previewData.error || '预览失败', 'err');
      return;
    }
    const p = previewData.preview || {};
    const lines = [
      '即将导入告警展示记录：',
      `总数：${p.total || 0} 条${p.truncated ? `（原文件 ${p.original_total || 0} 条，仅保留最近 ${p.history_max || p.total || 0} 条）` : ''}`,
      `已推送：${p.sent || 0} / 静默：${p.muted || 0} / 失败：${p.error || 0}`,
      `时间范围：${p.first_time || '-'} ~ ${p.last_time || '-'}`,
      '',
      '导入后会替换当前告警展示记录，但不会修改告警配置和去重状态。继续？',
    ];
    if (!confirm(lines.join('\n'))) return;
    const fd = new FormData();
    fd.append('history', file);
    const r = await fetch(BASE + '/api/settings.php?import_alert_history=1', {
      method: 'POST',
      body: fd,
      headers: {'X-Requested-With': 'XMLHttpRequest'},
      credentials: 'same-origin',
    });
    const d = await r.json();
    if (d.ok) {
      toast(`✅ 已导入 ${d.imported || 0} 条告警记录`);
      await loadSettings();
    } else {
      toast(d.error || '导入失败', 'err');
    }
  } catch(e) {
    toast('导入失败: ' + e.message, 'err');
  }
}


// ── 快捷封禁 IP（从日志/分析页直接封） ──────────────────────────
async function quickBlacklist(ip) {
  const cmt = prompt(`封禁 ${ip}，备注（可留空）：`);
  if (cmt === null) return;
  const d = await apiFetch('/api/blacklist.php', {
    method:'POST', body:JSON.stringify({ip, comment: cmt}),
    headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
  });
  if (d.ok) {
    toast(`✅ ${ip} 已封禁`);
    blacklistIpSet.add(ip);
    if (currentTab === 'stats') renderStats();
    else TABS[currentTab].loader();
  } else toast(d.error||'封禁失败','err');
}

// ── 导出日志 ──────────────────────────────────────────────────
function exportLogs() {
  const a = document.createElement('a');
  a.href = BASE + '/api/logs.php?export=1';
  a.download = '';
  a.click();
}

// ── 导入日志（multipart 上传，绕过 post_max_size 限制）──────────
async function importLogs(input) {
  const file = input.files[0];
  if (!file) return;
  input.value = '';   // 重置，允许再次选同一文件
  toast('导入中…');
  try {
    const fd = new FormData();
    fd.append('log', file);
    const r = await fetch(BASE + '/api/logs.php', {
      method: 'POST',
      headers: {'X-Requested-With': 'XMLHttpRequest'},
      body: fd,
    });
    if (r.status === 413) {
      toast('导入失败：文件过大，超出服务器上传限制', 'err');
      return;
    }
    const ct = r.headers.get('Content-Type') || '';
    if (!ct.includes('application/json')) {
      toast(`导入失败：服务器错误 (HTTP ${r.status})`, 'err');
      return;
    }
    const d = await r.json();
    if (d.ok) {
      toast(`✅ 导入成功：新增 ${d.imported} 行，共 ${d.total} 行`);
      loadLogs();
      if (allStatsData) loadStats();
    } else {
      toast(d.error || '导入失败', 'err');
    }
  } catch(e) {
    toast('导入失败：网络错误', 'err');
  }
}

// ── 初始化 ────────────────────────────────────────────────────
async function initDashboard() {
  resetCountdown();
  await loadTab('logs', {force:true}).catch(() => {});
  scheduleBackgroundPreload();
}
initDashboard();
</script>
</body>
</html>
