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
.log-status-summary{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:12px;padding:10px 12px;border:1px solid var(--border);border-radius:8px;background:var(--bg2)}
.log-status-heading{display:flex;align-items:baseline;gap:8px;min-width:0}
.log-status-title{color:var(--text);font-size:12px;font-weight:820;white-space:nowrap}
.log-status-caption{min-width:0;color:var(--text3);font-size:10px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.log-status-legend{display:flex;align-items:center;justify-content:flex-end;gap:7px;flex-wrap:wrap}
.log-status-item{display:inline-flex;align-items:center;gap:5px;padding:4px 7px;border:1px solid var(--border);border-radius:6px;background:var(--bg3);color:var(--text3);font-size:10px;white-space:nowrap}
.log-status-item::before{content:"";width:7px;height:7px;border-radius:999px;background:var(--status-color);box-shadow:0 0 0 3px color-mix(in srgb,var(--status-color) 14%,transparent)}
.log-status-item strong{color:var(--status-color);font-size:11px}
.log-status-success{--status-color:#22c55e}.log-status-403{--status-color:#ef4444}.log-status-429{--status-color:#eab308}.log-status-444{--status-color:#64748b}
.log-ip-count{display:flex;align-items:baseline;gap:8px;min-width:142px;white-space:nowrap}
.log-ip-count>strong{color:var(--accent);font-size:12px}
.log-ip-breakdown{color:var(--text3);font-size:10px;font-variant-numeric:tabular-nums}
.log-ip-breakdown .s200{color:#22c55e}.log-ip-breakdown .s403{color:#ef4444}.log-ip-breakdown .s429{color:#eab308}.log-ip-breakdown .s444{color:#64748b}
.log-intel-cell{min-width:260px;max-width:320px}
.log-intel{display:grid;gap:4px;padding:2px 0;color:var(--text2);font-size:10px;line-height:1.35}
.log-intel-primary{display:flex;align-items:center;gap:6px;min-width:0}
.log-intel-location{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text);font-weight:760}
.log-intel-risk{flex:0 0 auto;padding:2px 5px;border-radius:5px;font-size:9px;font-weight:850}
.log-intel-risk.high{background:rgba(239,68,68,.13);color:#ef4444}.log-intel-risk.review{background:rgba(234,179,8,.14);color:#d97706}.log-intel-risk.low{background:rgba(34,197,94,.12);color:#16a34a}.log-intel-risk.unknown{background:rgba(100,116,139,.12);color:#64748b}
.log-intel-detail{display:flex;align-items:center;gap:5px;min-width:0;color:var(--text3)}
.log-intel-detail span{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.log-intel-asn{flex:0 0 auto;color:var(--accent);font-weight:760}
.log-intel-meta{color:var(--text3);font-size:9px}
.log-intel-links{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.log-intel-links a{color:var(--accent);font-size:9px;font-weight:760;text-decoration:none}
.log-intel-links a:hover{text-decoration:underline}
.log-intel-pending{display:flex;align-items:center;gap:7px;color:var(--text3);font-size:10px}
.log-intel-pending::before{content:"";width:7px;height:7px;border-radius:999px;background:#eab308;box-shadow:0 0 0 3px rgba(234,179,8,.12)}
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
.stats-card:hover{border-color:var(--tone);transform:translateY(-2px);box-shadow:0 14px 34px rgba(15,23,42,.14)}
.stats-card:active{transform:translateY(0) scale(.99)}
.stats-card.active{border-color:var(--tone);box-shadow:0 0 0 2px var(--tone-soft)}
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

/* Security operations */
.nav-section-label{padding:15px 11px 6px;color:var(--text3);font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.08em}
.security-hero{position:relative;overflow:hidden;display:flex;align-items:center;justify-content:space-between;gap:20px;padding:24px;border:1px solid var(--border);border-radius:12px;background:linear-gradient(130deg,rgba(16,185,129,.12),var(--bg3) 45%,rgba(14,165,233,.10));margin-bottom:14px}
.security-hero::after{content:"";position:absolute;right:-50px;top:-70px;width:180px;height:180px;border-radius:50%;background:rgba(14,165,233,.12);pointer-events:none}
.security-hero.attention{background:linear-gradient(130deg,rgba(245,158,11,.13),var(--bg3) 46%,rgba(239,68,68,.08))}
.security-hero.degraded{background:linear-gradient(130deg,rgba(239,68,68,.16),var(--bg3) 48%,rgba(245,158,11,.08))}
.security-state{display:flex;align-items:center;gap:12px;position:relative;z-index:1}
.security-state-icon{width:44px;height:44px;border-radius:10px;display:grid;place-items:center;background:rgba(16,185,129,.14);color:#10b981;font-size:22px;font-weight:900}
.attention .security-state-icon{background:rgba(245,158,11,.14);color:#f59e0b}
.degraded .security-state-icon{background:rgba(239,68,68,.14);color:#ef4444}
.security-state-title{font-size:20px;font-weight:850;color:var(--text)}
.security-state-meta{color:var(--text2);font-size:12px;margin-top:3px}
.security-hero-actions{position:relative;z-index:1;display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:flex-end}
.security-metrics{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:14px}
.security-metric{position:relative;overflow:hidden;min-height:104px;padding:14px;border:1px solid var(--border);border-radius:10px;background:var(--bg3)}
.security-metric::before{content:"";position:absolute;inset:0 0 auto;height:3px;background:var(--metric,#6366f1)}
.security-metric-label{color:var(--text3);font-size:11px;font-weight:800}
.security-metric-value{font-size:25px;line-height:1.2;font-weight:900;color:var(--text);margin:8px 0 4px}
.security-metric-note{color:var(--text2);font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.security-layout{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(300px,.85fr);gap:14px;align-items:start}
.security-layout>div{display:contents}
#security-mechanisms-section{grid-column:1;grid-row:1}
#security-health-section{grid-column:2;grid-row:1}
#security-actions-section{grid-column:1/-1;grid-row:2}
#security-actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 20px}
.security-section{border:1px solid var(--border);border-radius:12px;background:var(--bg3);padding:18px;margin-bottom:14px;min-width:0}
.security-section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px}
.security-section-title{font-size:14px;font-weight:850;color:var(--text)}
.security-section-sub{color:var(--text3);font-size:11px;margin-top:3px}
.mechanism-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.mechanism-item{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:9px;padding:11px;border:1px solid var(--border);border-radius:9px;background:rgba(100,116,139,.045)}
.mechanism-config{align-self:center;border:0;background:transparent;color:var(--accent);font-size:11px;font-weight:800;cursor:pointer;padding:5px 2px}
.mechanism-config:hover{text-decoration:underline}
.mechanism-dot{width:9px;height:9px;margin-top:5px;border-radius:50%;background:#22c55e;box-shadow:0 0 0 4px rgba(34,197,94,.10)}
.mechanism-dot.warn{background:#f59e0b;box-shadow:0 0 0 4px rgba(245,158,11,.10)}
.mechanism-dot.error{background:#ef4444;box-shadow:0 0 0 4px rgba(239,68,68,.10)}
.mechanism-dot.paused,.mechanism-dot.optional{background:#94a3b8;box-shadow:0 0 0 4px rgba(148,163,184,.10)}
.mechanism-title{font-size:12px;font-weight:800;color:var(--text)}
.mechanism-detail{font-size:11px;color:var(--text3);margin-top:2px;overflow-wrap:anywhere}
.health-list,.action-list{display:flex;flex-direction:column}
.health-row,.action-row{display:grid;grid-template-columns:minmax(105px,.7fr) minmax(0,1.3fr);gap:12px;padding:9px 0;border-bottom:1px solid var(--border)}
.health-row:last-child,.action-row:last-child{border-bottom:none}
.health-label,.action-time{color:var(--text3);font-size:11px}
.health-value,.action-main{color:var(--text2);font-size:12px;min-width:0;overflow-wrap:anywhere}
.risk-list{display:flex;flex-direction:column;border-top:1px solid var(--border)}
.risk-item{padding:14px 0;border-bottom:1px solid var(--border);animation:listIn var(--motion-med) ease both}
.risk-item:last-child{border-bottom:none}
.risk-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
.risk-title{font-size:13px;font-weight:850;color:var(--text)}
.risk-subject{font:12px/1.45 ui-monospace,SFMono-Regular,Menlo,monospace;color:#818cf8;overflow-wrap:anywhere;margin-top:3px}
.risk-score{flex:0 0 auto;padding:4px 8px;border-radius:999px;background:rgba(239,68,68,.11);color:#ef4444;font-size:11px;font-weight:850}
.risk-evidence{display:flex;gap:8px;flex-wrap:wrap;margin:9px 0 6px;color:var(--text2);font-size:11px}
.risk-evidence span{padding:3px 7px;border:1px solid var(--border);border-radius:6px;background:rgba(100,116,139,.05)}
.risk-reason{color:var(--text3);font-size:11px;line-height:1.6}
.risk-actions{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-top:10px}
.ai-analysis-panel{display:none;margin:0 0 14px;padding:14px;border:1px solid rgba(14,165,233,.24);border-left:3px solid #0ea5e9;border-radius:9px;background:rgba(14,165,233,.06)}
.ai-analysis-panel.visible{display:block;animation:itemIn var(--motion-med) ease both}
.ai-analysis-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:10px}
.ai-analysis-kicker{font-size:10px;font-weight:850;color:#0ea5e9;text-transform:uppercase}
.ai-analysis-title{font-size:14px;font-weight:900;color:var(--text);margin-top:2px}
.ai-analysis-meta{font-size:10px;color:var(--text3);text-align:right}
.ai-analysis-summary{font-size:12px;line-height:1.7;color:var(--text2)}
.ai-analysis-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:12px}
.ai-analysis-block{padding:10px;border:1px solid var(--border);border-radius:8px;background:var(--bg3);min-width:0}
.ai-analysis-block h4{font-size:11px;color:var(--text);margin-bottom:6px}
.ai-analysis-block ul{padding-left:17px;color:var(--text3);font-size:11px;line-height:1.65}
.ai-risk-badge{padding:4px 8px;border-radius:999px;font-size:10px;font-weight:850;white-space:nowrap;background:rgba(245,158,11,.12);color:#f59e0b}
.ai-risk-badge.low{background:rgba(34,197,94,.12);color:#16a34a}.ai-risk-badge.high,.ai-risk-badge.critical{background:rgba(239,68,68,.12);color:#ef4444}
.ai-advisory{margin-top:10px;color:var(--text3);font-size:10px}
.ai-secret-status{display:inline-flex;align-items:center;gap:6px;color:var(--text3);font-size:11px}
.ai-secret-status::before{content:"";width:7px;height:7px;border-radius:50%;background:#94a3b8}.ai-secret-status.ready::before{background:#22c55e}
.review-select{min-width:112px;background:var(--bg-input);border:1px solid var(--border2);color:var(--text);padding:6px 8px;border-radius:8px;font-size:11px;outline:none}
.rule-grid{display:grid;grid-template-columns:repeat(3,minmax(120px,1fr));gap:10px}
.rule-field label{display:block;color:var(--text2);font-size:11px;margin-bottom:5px}
.rule-field .ip-input{width:100%;min-width:0}
.rule-hint{display:block;margin-top:5px;color:var(--text3);font-size:10px;line-height:1.45}
.rule-hint.warn{color:#dc2626;font-weight:700}
.rule-hint.ok{color:#059669}
.rule-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:12px;padding-top:12px;border-top:1px solid var(--border)}
.scope-note{display:flex;gap:8px;align-items:flex-start;padding:10px 12px;border:1px solid rgba(14,165,233,.18);border-radius:9px;background:rgba(14,165,233,.07);color:var(--text2);font-size:11px;line-height:1.55;margin-bottom:14px}
.security-empty{padding:24px 0;color:var(--text3);font-size:12px;text-align:center}
.pull-limit-panel{margin-bottom:14px;padding:18px 0;border-top:1px solid var(--border);border-bottom:1px solid var(--border)}
.pull-limit-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px}
.limit-mode-badge{padding:5px 9px;border-radius:999px;border:1px solid rgba(14,165,233,.22);background:rgba(14,165,233,.09);color:#38bdf8;font-size:11px;font-weight:850;white-space:nowrap}
.limit-mode-badge.enforce{border-color:rgba(245,158,11,.25);background:rgba(245,158,11,.10);color:#f59e0b}
.limit-mode-badge.paused{border-color:var(--border);background:rgba(100,116,139,.07);color:var(--text3)}
.pull-limit-rule-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.pull-limit-rule{display:grid;grid-template-columns:38px minmax(0,1fr);gap:10px;align-items:center;min-height:78px;padding:13px;border:1px solid var(--border);border-radius:9px;background:var(--bg3)}
.pull-limit-icon{width:38px;height:38px;border-radius:9px;display:grid;place-items:center;background:rgba(14,165,233,.10);color:#38bdf8;font-size:17px;font-weight:900}
.pull-limit-rule:nth-child(2) .pull-limit-icon{background:rgba(245,158,11,.11);color:#f59e0b}
.pull-limit-rule:nth-child(3) .pull-limit-icon{background:rgba(244,63,94,.10);color:#fb7185}
.pull-limit-rule-label{font-size:11px;color:var(--text3)}
.pull-limit-rule-value{font-size:16px;font-weight:900;color:var(--text);margin-top:2px}
.pull-limit-subhead{margin:16px 0 9px;color:var(--text2);font-size:12px;font-weight:850}
.pull-limit-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.pull-limit-summary-item{padding:12px 13px;border-left:3px solid var(--limit-color,#6366f1);background:rgba(100,116,139,.055);border-radius:7px;min-width:0}
.pull-limit-summary-value{font-size:20px;font-weight:900;color:var(--text)}
.pull-limit-summary-label{font-size:10px;color:var(--text3);margin-top:3px}
.pull-limit-usage{margin-top:10px;border-top:1px solid var(--border)}
.pull-limit-row{display:grid;grid-template-columns:minmax(150px,1.25fr) minmax(100px,.8fr) minmax(100px,.8fr) minmax(95px,.7fr) auto;gap:12px;align-items:center;padding:10px 2px;border-bottom:1px solid var(--border);font-size:11px}
.pull-limit-token{font:11px/1.4 ui-monospace,SFMono-Regular,Menlo,monospace;color:#818cf8;overflow-wrap:anywhere}
.pull-limit-meter{height:5px;border-radius:999px;background:rgba(100,116,139,.14);overflow:hidden;margin-top:5px}
.pull-limit-meter span{display:block;height:100%;border-radius:inherit;background:linear-gradient(90deg,#0ea5e9,#6366f1)}
.pull-limit-meter.warn span{background:linear-gradient(90deg,#f59e0b,#ef4444)}
.pull-limit-status{font-weight:800;color:#10b981;white-space:nowrap}
.pull-limit-status.warn{color:#f59e0b}.pull-limit-status.blocked{color:#ef4444}
.pull-limit-row-actions{display:flex;align-items:center;justify-content:flex-end;gap:6px;flex-wrap:wrap}
.pull-limit-row-actions .mode-btn{padding:5px 9px}
.pull-limit-controls{display:grid;grid-template-columns:repeat(3,minmax(120px,1fr));gap:10px;margin-top:14px;padding-top:14px;border-top:1px solid var(--border)}
.pull-limit-switches{grid-column:1/-1;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));border:1px solid var(--border);border-radius:8px;background:rgba(100,116,139,.035);overflow:hidden}
.pull-limit-switch{display:flex;align-items:center;justify-content:space-between;gap:14px;min-height:66px;padding:12px 14px;cursor:pointer}
.pull-limit-switch+ .pull-limit-switch{border-left:1px solid var(--border)}
.pull-limit-switch-copy{display:grid;gap:3px;min-width:0}
.pull-limit-switch-copy strong{color:var(--text);font-size:12px}
.pull-limit-switch-copy small{color:var(--text3);font-size:10px;line-height:1.45}
.switch-control{position:relative;flex:0 0 auto;width:42px;height:24px}
.switch-control input{position:absolute;inline-size:1px;block-size:1px;opacity:0}
.switch-track{position:absolute;inset:0;border-radius:999px;background:#94a3b8;transition:background var(--motion-fast),opacity var(--motion-fast)}
.switch-track::after{content:"";position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 4px rgba(15,23,42,.25);transition:transform var(--motion-fast)}
.switch-control input:checked+ .switch-track{background:var(--accent)}
.switch-control input:checked+ .switch-track::after{transform:translateX(18px)}
.switch-control input:focus-visible+ .switch-track{outline:2px solid var(--accent);outline-offset:2px}
.pull-limit-switch.disabled{cursor:not-allowed;opacity:.55}
.pull-limit-switch.disabled .switch-track{opacity:.65}
.pull-limit-actions{grid-column:1/-1;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.pull-limit-note{color:var(--text3);font-size:11px;line-height:1.65;flex:1;min-width:240px}
.investigation-dialog{width:min(980px,calc(100vw - 28px));max-width:none;max-height:calc(100dvh - 28px);margin:auto;padding:0;border:1px solid var(--border2);border-radius:8px;background:var(--bg2);color:var(--text);box-shadow:0 28px 80px rgba(0,0,0,.42);overflow:hidden}
.investigation-dialog::backdrop{background:rgba(2,6,12,.66);backdrop-filter:blur(3px)}
.investigation-shell{display:flex;flex-direction:column;max-height:calc(100dvh - 30px)}
.investigation-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;padding:17px 18px;border-bottom:1px solid var(--border);background:var(--bg3)}
.investigation-kicker{color:var(--accent);font-size:10px;font-weight:850}
.investigation-title{font-size:17px;font-weight:900;margin-top:3px;overflow-wrap:anywhere}
.investigation-close{width:34px;height:34px;display:grid;place-items:center;padding:0;border-radius:7px;font-size:20px}
.investigation-body{flex:1;min-height:0;overflow:auto;padding:16px 18px;overscroll-behavior:contain}
.investigation-metrics{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;margin-bottom:16px}
.investigation-metric{padding:11px;border-left:3px solid var(--metric-color,var(--accent));border-radius:6px;background:rgba(100,116,139,.07);min-width:0}
.investigation-metric strong{display:block;font-size:18px;line-height:1.2;color:var(--text);overflow-wrap:anywhere}
.investigation-metric span{display:block;margin-top:4px;color:var(--text3);font-size:10px}
.investigation-grid{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,.9fr);gap:16px}
.investigation-section{min-width:0;padding:13px 0;border-top:1px solid var(--border)}
.investigation-section h3{font-size:12px;margin:0 0 9px;color:var(--text)}
.investigation-evidence{display:grid;gap:7px}
.investigation-evidence div{padding:8px 10px;border-left:3px solid #f59e0b;background:rgba(245,158,11,.07);border-radius:5px;color:var(--text2);font-size:11px;line-height:1.5}
.investigation-table-wrap{width:100%;max-width:100%;overflow-x:auto;border:1px solid var(--border);border-radius:7px}
.investigation-table{min-width:620px}
.investigation-table th,.investigation-table td{padding:8px;font-size:10px}
.investigation-risk{display:inline-flex;padding:3px 7px;border-radius:999px;font-size:10px;font-weight:850}
.investigation-risk.high{background:rgba(239,68,68,.13);color:#ef4444}.investigation-risk.review{background:rgba(245,158,11,.13);color:#f59e0b}.investigation-risk.low{background:rgba(34,197,94,.12);color:#22c55e}
.investigation-ua-list{display:grid;gap:6px}
.investigation-ua{display:grid;grid-template-columns:92px minmax(0,1fr) auto;gap:8px;align-items:center;padding:8px 0;border-bottom:1px solid var(--border);font-size:10px}
.investigation-ua-family{font-weight:850;color:var(--accent)}
.investigation-ua-value{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text2)}
.investigation-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:12px 18px;border-top:1px solid var(--border);background:var(--bg3)}
.investigation-actions .investigation-note{flex:1;min-width:220px;color:var(--text3);font-size:10px}

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
.idc-note{margin:-2px 0 10px;color:var(--text3);font-size:11px;line-height:1.55}
.idc-section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:12px}
.idc-section-actions{display:flex;align-items:center;gap:7px;flex-wrap:wrap;justify-content:flex-end}
.idc-policy-status{padding:5px 8px;border:1px solid var(--border);border-radius:7px;background:rgba(100,116,139,.07);color:var(--text2);font-size:11px;white-space:nowrap}
.idc-policy-status.updating{border-color:rgba(245,158,11,.28);background:rgba(245,158,11,.09);color:#d97706}
.idc-policy-status.error{border-color:rgba(239,68,68,.28);background:rgba(239,68,68,.08);color:#ef4444}
.idc-provider-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}
.idc-provider-card{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;padding:12px;border:1px solid var(--border);border-radius:8px;background:rgba(100,116,139,.045);transition:border-color .15s,background .15s,transform .15s}
.idc-provider-card:hover{border-color:var(--border2);transform:translateY(-1px)}
.idc-provider-card.enabled{border-color:rgba(15,118,110,.28);background:rgba(15,118,110,.055)}
.idc-provider-card.pending{border-color:rgba(245,158,11,.34)}
.idc-provider-card.unavailable{opacity:.62}
.idc-provider-name{font-size:12px;font-weight:800;color:var(--text)}
.idc-provider-meta{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:5px;color:var(--text3);font-size:10px}
.idc-provider-meta span{padding:2px 5px;border-radius:5px;background:rgba(100,116,139,.08)}
.idc-provider-keywords{margin-top:6px;color:var(--text3);font-size:10px;line-height:1.45;overflow-wrap:anywhere}
.provider-switch{position:relative;width:38px;height:22px;flex:0 0 auto;cursor:pointer}
.provider-switch input{position:absolute;opacity:0;pointer-events:none}
.provider-switch-track{position:absolute;inset:0;border-radius:999px;background:#94a3b8;transition:background .18s}
.provider-switch-track::after{content:"";position:absolute;left:3px;top:3px;width:16px;height:16px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(15,23,42,.25);transition:transform .18s}
.provider-switch input:checked+.provider-switch-track{background:var(--accent)}
.provider-switch input:checked+.provider-switch-track::after{transform:translateX(16px)}
.provider-switch input:disabled+.provider-switch-track{opacity:.42;cursor:not-allowed}
.idc-policy-footer{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:12px;padding-top:12px;border-top:1px solid var(--border);color:var(--text3);font-size:11px}
.risk-trigger-detail{margin-top:9px;border:1px solid var(--border);border-radius:8px;background:rgba(100,116,139,.055);overflow:hidden}
.risk-trigger-detail summary{cursor:pointer;padding:8px 10px;color:var(--text2);font-size:11px;font-weight:750;list-style:none}
.risk-trigger-detail summary::-webkit-details-marker{display:none}
.risk-trigger-detail summary::after{content:"+";float:right;color:var(--text3)}
.risk-trigger-detail[open] summary::after{content:"−"}
.risk-trigger-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 14px;padding:0 10px 9px}
.risk-trigger-row{display:grid;grid-template-columns:70px minmax(0,1fr);gap:8px;padding:6px 0;border-top:1px solid var(--border);font-size:11px}
.risk-trigger-label{color:var(--text3)}
.risk-trigger-value{color:var(--text2);overflow-wrap:anywhere}

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
  .nav-section-label{display:none}
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
  .log-status-summary{align-items:flex-start;flex-direction:column;gap:8px}
  .log-status-legend{width:100%;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));justify-content:stretch}
  .log-status-item{justify-content:flex-start}
  .radio-group{width:100%;margin-left:0;gap:10px;align-items:flex-start;flex-wrap:wrap}
  #active-subscribe-path{width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .log-table-wrap,.table-wrap{width:100%;max-width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch;border:1px solid var(--border);border-radius:8px;padding:0 8px}
  .log-table-wrap table{min-width:1360px}
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
  .idc-section-head,.idc-policy-footer{align-items:flex-start;flex-direction:column}
  .idc-section-actions{width:100%;justify-content:flex-start}
  .idc-provider-grid{grid-template-columns:1fr}
  .idc-provider-card{padding:11px}
  .idc-policy-footer .btn-primary{width:100%}
  .risk-trigger-grid{grid-template-columns:1fr}
  .ip-form{display:grid;grid-template-columns:1fr;gap:8px}
  .ip-input,.comment-input,.btn-primary{width:100%;min-width:0}
  .apply-row{align-items:flex-start;flex-wrap:wrap}
  #panel-settings .stats-grid{grid-template-columns:1fr!important}
  #panel-settings .card [style*="display:flex;gap:8px;align-items:flex-end"]{display:grid!important;grid-template-columns:1fr!important;gap:8px!important}
  .security-hero{padding:15px;align-items:flex-start;flex-direction:column}
  .security-state-title{font-size:17px}
  .security-hero-actions{width:100%;justify-content:flex-start}
  .security-metrics{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
  .security-metric{min-height:92px;padding:12px}
  .security-metric-value{font-size:21px}
  .security-layout{grid-template-columns:1fr;gap:0}
  .security-section{padding:13px;border-radius:9px;margin-bottom:10px}
  .pull-limit-panel{padding:14px 0}
  .pull-limit-rule-grid{grid-template-columns:1fr}
  .pull-limit-summary{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
  .pull-limit-row{grid-template-columns:minmax(0,1fr) auto;gap:7px;padding:12px 2px}
  .pull-limit-row>div:nth-child(2),.pull-limit-row>div:nth-child(3){grid-column:1/-1}
  .pull-limit-row-actions{grid-column:1/-1;justify-content:flex-start}
  .pull-limit-controls{grid-template-columns:1fr}
  .investigation-dialog{width:calc(100vw - 16px);max-height:calc(100dvh - 16px)}
  .investigation-shell{max-height:calc(100dvh - 18px)}
  .investigation-head,.investigation-body,.investigation-actions{padding-left:12px;padding-right:12px}
  .investigation-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}
  .investigation-grid{grid-template-columns:1fr;gap:0}
  .investigation-actions .mode-btn,.investigation-actions .btn-primary,.investigation-actions .btn-danger{flex:1 1 calc(50% - 6px);min-height:36px}
  .mechanism-grid{grid-template-columns:1fr}
  .rule-grid{grid-template-columns:1fr 1fr;gap:8px}
  .risk-head{align-items:flex-start}
  .risk-actions .mode-btn,.risk-actions .review-select{flex:1 1 calc(50% - 7px);min-height:34px}
  .ai-analysis-grid{grid-template-columns:1fr}
  .ai-analysis-head{flex-direction:column}.ai-analysis-meta{text-align:left}
  .health-row,.action-row{grid-template-columns:90px minmax(0,1fr);gap:8px}
  #toast{left:10px;right:10px;bottom:12px;text-align:center}
}

/* 2026 workspace information architecture and layout refresh */
:root{
  --bg:#101214;--bg2:#171a1d;--bg3:#1d2125;--bg-input:#121517;
  --border:#2a2f34;--border2:#3a4249;
  --text:#f1f5f4;--text2:#b2bcb9;--text3:#7f8b87;
  --accent:#14b8a6;--accent-strong:#0f766e;--accent-soft:rgba(20,184,166,.11);
}
[data-theme="light"]{
  --bg:#f3f5f4;--bg2:#ffffff;--bg3:#ffffff;--bg-input:#f8faf9;
  --border:#dfe5e2;--border2:#c8d2ce;
  --text:#17201d;--text2:#4f5f59;--text3:#7f918a;
  --accent:#0f766e;--accent-strong:#115e59;--accent-soft:rgba(15,118,110,.09);
}
body{background:var(--bg);font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}
.sidebar{width:224px;padding:18px 12px 14px;background:var(--bg2);border-color:var(--border);box-shadow:none}
.logo{display:flex;align-items:center;gap:10px;padding:2px 8px 20px;font-size:14px;font-weight:800}
.brand-mark{width:34px;height:34px;display:grid;place-items:center;border-radius:8px;background:var(--accent);color:#fff;font-size:15px;box-shadow:0 8px 18px rgba(15,118,110,.20)}
.brand-copy{display:flex;flex-direction:column;line-height:1.2;min-width:0}
.brand-copy small{margin-top:4px;color:var(--text3);font-size:9px;font-weight:650}
.nav-scroll{display:flex;flex:1;min-height:0;flex-direction:column;gap:3px;overflow-y:auto;scrollbar-width:thin}
.nav-section-label{padding:18px 11px 6px;color:var(--text3);font-size:10px;font-weight:850;text-transform:uppercase}
.nav-section-label:first-child{padding-top:2px}
.nav-item{min-height:40px;padding:7px 9px;border-radius:7px;font-size:12px;font-weight:720;color:var(--text2)}
.nav-item::before{inset:7px auto 7px 0;width:2px;background:var(--accent)}
.nav-item:hover{background:rgba(127,145,138,.08);border-color:var(--border);color:var(--text)}
.nav-item.active{background:var(--accent-soft);border-color:color-mix(in srgb,var(--accent) 24%,var(--border));color:var(--accent);box-shadow:none}
.nav-icon{width:27px;height:27px;border-radius:7px;background:rgba(127,145,138,.09);font-size:14px;font-weight:850}
.nav-item:hover .nav-icon,.nav-item.active .nav-icon{background:color-mix(in srgb,var(--accent) 15%,transparent);color:var(--accent)}
.sidebar-bottom{padding-top:10px;border-top:1px solid var(--border)}
.topbar{position:sticky;top:0;z-index:12;min-height:70px;padding:12px 24px;background:color-mix(in srgb,var(--bg2) 94%,transparent);backdrop-filter:blur(16px);border-color:var(--border)}
.topbar-copy{min-width:0}
.topbar-title{font-size:17px;font-weight:820}
.topbar-subtitle{margin-top:2px;color:var(--text3);font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.topbar-right{gap:9px}
.refresh-btn,.theme-btn,.mode-btn{border-radius:7px;background:var(--bg3);border-color:var(--border2)}
.content{padding:22px 24px 32px;background:var(--bg);overflow-x:hidden}
.tab-panel{width:100%;max-width:1680px;margin:0 auto}
.card{padding:18px;border:1px solid var(--border);border-radius:8px;background:var(--bg3);box-shadow:0 8px 22px rgba(0,0,0,.06)}
.card::before{display:none}
.card-title{font-size:13px;font-weight:820}
.card-title::before{width:3px;height:16px;border-radius:2px;background:var(--accent);box-shadow:none}
.log-controls,.log-table-wrap,.table-wrap{border-radius:8px;background:var(--bg3)}
.mode-btn.active,.btn-primary{background:var(--accent);border-color:var(--accent);box-shadow:none}
.mode-btn.active:hover,.btn-primary:hover{background:var(--accent-strong);border-color:var(--accent-strong)}
.security-hero{padding:20px;border-radius:8px;background:var(--bg3);border-left:4px solid #10b981;box-shadow:none}
.security-hero::after,.stats-card::after{display:none}
.security-metric{border-radius:8px;background:var(--bg3)}
.security-metric::before{inset:0 auto 0 0;width:3px;height:auto}
.security-section{border-radius:8px;background:var(--bg3)}
.stats-card{min-height:118px;border-radius:8px;background:var(--bg3);box-shadow:none}
.stats-card:hover{transform:translateY(-1px);box-shadow:0 10px 24px rgba(0,0,0,.09)}
.stats-detail-head,.stats-detail-card .top-row,.profile-segment,.scanner-report{border-radius:8px;background:var(--bg3)}
.scope-note{border-radius:7px}

.stats-overview{display:block;max-width:1180px;margin:0 auto}
.analysis-overview-head{display:flex;align-items:flex-end;justify-content:space-between;gap:18px;margin-bottom:14px;padding:4px 2px 15px;border-bottom:1px solid var(--border)}
.analysis-overview-title{font-size:20px;font-weight:850;color:var(--text)}
.analysis-overview-meta{margin-top:5px;color:var(--text3);font-size:11px}
.analysis-window{flex:0 0 auto;display:flex;align-items:center;gap:8px;padding:7px 10px;border:1px solid var(--border);border-radius:7px;background:var(--bg2);color:var(--text3);font-size:10px}
.analysis-window strong{color:var(--text2);font-size:11px}
.analysis-core-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.analysis-core-grid .stats-card{display:flex;min-height:174px;flex-direction:column;padding:19px 20px;border-width:1px;background:var(--bg3);box-shadow:none}
.analysis-core-grid .stats-card:hover{border-color:var(--tone);box-shadow:0 12px 28px rgba(0,0,0,.10)}
.analysis-core-grid .stats-card-title{margin-bottom:18px}
.stats-card-count-row{display:flex;align-items:flex-end;justify-content:space-between;gap:12px}
.stats-card-main{font-size:30px;font-weight:850;letter-spacing:0}
.stats-card-unit{margin-left:5px;color:var(--text2);font-size:12px;font-weight:750}
.stats-card-status{padding:4px 8px;border-radius:6px;background:var(--tone-soft);color:var(--tone);font-size:10px;font-weight:850;white-space:nowrap}
.stats-card-sub{min-height:34px;margin-top:9px;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.stats-card-foot{display:flex;align-items:center;justify-content:space-between;margin-top:auto;padding-top:14px;border-top:1px solid var(--border);color:var(--text3);font-size:10px;font-weight:750}
.stats-card-foot strong{color:var(--tone);font-size:15px;line-height:1}

.review-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px}
.review-toolbar .log-mode-btns{margin:0!important}
.review-size-control{display:flex;align-items:center;gap:4px;flex:0 0 auto;color:var(--text3);font-size:10px}
.review-size-control .mode-btn{min-width:32px;padding:5px 8px}
.review-pagination{display:none;align-items:center;justify-content:space-between;gap:12px;margin-top:12px;padding-top:12px;border-top:1px solid var(--border)}
.review-page-summary{color:var(--text3);font-size:11px;white-space:nowrap}
.review-page-actions{display:flex;align-items:center;gap:6px}
.review-page-jump{width:48px;background:var(--bg-input);border:1px solid var(--border2);color:var(--text);padding:5px 6px;border-radius:6px;font-size:11px;outline:none;text-align:center}
.review-page-jump:focus{border-color:var(--accent)}
.risk-workbench{max-width:1180px;margin:14px auto 0}
.risk-kind-toolbar{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:12px;padding:5px;border:1px solid var(--border);border-radius:8px;background:var(--bg2)}
.risk-kind-toolbar .mode-btn{flex:0 0 auto}
.risk-kind-toolbar .mode-btn.active{background:var(--accent);border-color:var(--accent);color:#fff}
.risk-intel{display:flex;gap:6px;flex-wrap:wrap;margin-top:7px;color:var(--text3);font-size:10px}
.risk-intel span{padding:3px 7px;border-radius:5px;background:rgba(100,116,139,.07)}
.risk-status-counts{display:inline-flex;gap:3px;font-variant-numeric:tabular-nums}

.workspace-intro{display:flex;align-items:flex-end;justify-content:space-between;gap:18px;margin-bottom:16px;padding:4px 2px 16px;border-bottom:1px solid var(--border)}
.workspace-intro h1{font-size:22px;line-height:1.2;font-weight:850}
.workspace-intro p{max-width:760px;margin-top:7px;color:var(--text2);font-size:12px;line-height:1.65}
.workspace-kicker{margin-bottom:5px;color:var(--accent);font-size:10px;font-weight:900;text-transform:uppercase}
.protection-stack{display:flex;flex-direction:column;gap:14px}
.protection-stack>.pull-limit-panel,.protection-stack>.security-section{margin:0;padding:18px;border:1px solid var(--border);border-radius:8px;background:var(--bg3)}
.protection-stack>#guard-threshold-section .rule-grid{grid-template-columns:repeat(4,minmax(0,1fr))}
.protection-stack>#guard-threshold-section .rule-actions{justify-content:flex-end}
.pull-limit-controls{border-top-color:var(--border)}

.control-tabs{display:flex;gap:4px;margin-bottom:14px;padding:4px;border:1px solid var(--border);border-radius:8px;background:var(--bg2);overflow-x:auto;scrollbar-width:none}
.control-tabs::-webkit-scrollbar{display:none}
.control-tab{flex:0 0 auto;min-height:36px;padding:7px 14px;border:0;border-radius:6px;background:transparent;color:var(--text2);font:700 12px/1 system-ui;cursor:pointer;transition:background var(--motion-fast),color var(--motion-fast)}
.control-tab:hover{color:var(--text);background:rgba(127,145,138,.08)}
.control-tab.active{background:var(--accent);color:#fff}
.access-stage{min-width:0}
.access-pane{display:none;min-width:0}
.access-pane.active{display:block;animation:panelIn var(--motion-med) ease both}
.access-pane>.card{margin-bottom:12px}

.settings-groups{display:flex;flex-direction:column;gap:24px}
.settings-cluster{min-width:0}
.settings-cluster-head{display:flex;align-items:flex-end;justify-content:space-between;gap:14px;margin-bottom:10px;padding:0 2px}
.settings-cluster-title{font-size:14px;font-weight:850}
.settings-cluster-sub{margin-top:3px;color:var(--text3);font-size:11px}
.settings-cluster-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;align-items:start}
.settings-cluster[data-settings-group="gateway"] .settings-cluster-grid{grid-template-columns:repeat(3,minmax(0,1fr))}
.settings-cluster[data-settings-group="operations"] .settings-cluster-grid{grid-template-columns:minmax(0,1fr)}
.settings-cluster-grid>.card{height:auto;margin:0}

/* Pull records stay dense on desktop, then become real record cards on phones. */
#panel-logs>.card{overflow:hidden}
#panel-logs .log-controls{display:grid;grid-template-columns:repeat(4,minmax(130px,1fr)) auto;gap:8px}
#panel-logs .log-controls .log-filter{width:100%;min-width:0}
#panel-logs .log-controls .radio-group{grid-column:1/4;margin-left:0}
.log-page-size{grid-column:4/6;display:flex;gap:4px;justify-self:end}
#panel-logs .log-table-wrap{padding:0;overflow:auto}
#panel-logs .log-table-wrap table{width:100%;min-width:1280px}
#panel-logs .log-table-wrap th,#panel-logs .log-table-wrap td{padding:10px 9px;vertical-align:middle}
#panel-logs .log-table-wrap th{position:sticky;top:0;z-index:2;background:var(--bg2);font-size:10px;letter-spacing:0}
#panel-logs .log-table-wrap td{font-size:11px}
#panel-logs .copy-btn{width:24px;height:24px;display:inline-grid;place-items:center;padding:0;font-size:13px;line-height:1}

@media (max-width: 1120px){
  .protection-stack>#guard-threshold-section .rule-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
  .settings-cluster[data-settings-group="gateway"] .settings-cluster-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
  #panel-logs .log-controls{grid-template-columns:repeat(2,minmax(0,1fr))}
  #panel-logs .log-controls #log-count,#panel-logs .log-controls .radio-group,.log-page-size{grid-column:1/-1}
  .log-page-size{justify-self:start}
}

@media (max-width:760px){
  body{padding:0;overflow-x:hidden}
  .sidebar{position:sticky;top:0;z-index:30;display:grid;grid-template-columns:auto minmax(0,1fr);gap:8px;width:100%;height:64px;padding:8px 10px;border-right:0;border-bottom:1px solid var(--border);overflow:hidden}
  .logo{padding:0;gap:7px}
  .brand-mark{width:32px;height:32px}
  .brand-copy{font-size:12px}
  .brand-copy small{display:none}
  .nav-scroll{display:flex;min-width:0;flex-direction:row;align-items:center;gap:4px;overflow-x:auto;overflow-y:hidden;scrollbar-width:none}
  .nav-scroll::-webkit-scrollbar{display:none}
  .nav-section-label,.sidebar-bottom{display:none}
  .nav-item{min-height:42px;padding:6px 9px;gap:5px;border-radius:7px}
  .nav-item::before{display:none}
  .nav-icon{width:24px;height:24px;font-size:12px}
  .nav-label{font-size:11px}
  .topbar{top:64px;display:grid;grid-template-columns:minmax(0,1fr) auto;min-height:58px;padding:8px 10px;align-items:center}
  .topbar-title{font-size:14px;line-height:1.25}
  .topbar-subtitle{max-width:50vw;font-size:9px}
  .topbar-right{margin:0;gap:5px;flex-wrap:nowrap}
  .topbar-right>.status-text:not(.auto-timer){display:none}
  .status-dot{width:7px;height:7px}
  .auto-timer{max-width:74px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .refresh-btn,.theme-btn{padding:6px 8px;font-size:10px}
  .content{width:100%;padding:12px 10px 24px;overflow-x:hidden}
  .tab-panel{max-width:100%;min-width:0}
  .workspace-intro{align-items:flex-start;flex-direction:column;padding-bottom:12px;margin-bottom:12px}
  .workspace-intro h1{font-size:18px}
  .workspace-intro p{font-size:11px}
  .protection-stack{display:flex}
  .protection-stack>.pull-limit-panel,.protection-stack>.security-section{padding:13px;margin-bottom:10px}
  .protection-stack>#guard-threshold-section .rule-grid{grid-template-columns:minmax(0,1fr)}
  .pull-limit-switches{grid-template-columns:minmax(0,1fr)}
  .pull-limit-switch+ .pull-limit-switch{border-left:0;border-top:1px solid var(--border)}
  .control-tabs{width:100%;margin-bottom:10px}
  .control-tab{padding:7px 11px}
  .settings-cluster-head{align-items:flex-start;flex-direction:column;gap:3px}
  .settings-cluster-grid,.settings-cluster[data-settings-group="gateway"] .settings-cluster-grid{grid-template-columns:minmax(0,1fr)}
  .settings-groups{gap:18px}
  .table-wrap{max-width:100%;padding:0;overflow-x:auto}
  #panel-logs .log-controls{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));padding:8px}
  .log-filter{width:100%;min-width:0}
  .radio-group,.log-page-size{grid-column:1/-1;margin-left:0!important}
  .security-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}
  .security-layout{display:block}
  #security-mechanisms-section,#security-health-section,#security-actions-section{grid-column:auto;grid-row:auto}
  .analysis-overview-head{align-items:flex-start;flex-direction:column;gap:9px}
  .analysis-core-grid{grid-template-columns:minmax(0,1fr);gap:10px}
  .analysis-core-grid .stats-card{min-height:156px;padding:15px}
  .review-toolbar{align-items:flex-start;flex-direction:column}
  .review-size-control{width:100%;justify-content:flex-start}
  .review-pagination{align-items:flex-start;flex-direction:column}
  .review-page-actions{width:100%}
  .review-page-actions .mode-btn{flex:1}

  #panel-logs>.card{padding:10px;background:transparent;border:0;box-shadow:none}
  #panel-logs .log-mode-btns{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px}
  #panel-logs .log-mode-btns .mode-btn{width:100%;min-height:36px;padding:7px 8px}
  #panel-logs .log-controls #log-count{grid-column:1/-1}
  #panel-logs .log-controls .radio-group{grid-column:1/-1;width:100%;display:flex;gap:12px}
  #panel-logs .log-page-size{width:100%;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));justify-self:stretch}
  #panel-logs .log-page-size .mode-btn{min-width:0;padding:7px 4px}
  #panel-logs .log-table-wrap{max-width:100%;overflow:visible;border:0;background:transparent}
  #panel-logs .log-table-wrap table{display:block;width:100%;min-width:0}
  #panel-logs .log-table-wrap thead{display:none}
  #panel-logs .log-table-wrap tbody{display:grid;gap:10px}
  #panel-logs .log-table-wrap tbody tr{display:grid;width:100%;grid-template-columns:minmax(0,1fr);padding:5px 12px;border:1px solid var(--border);border-radius:8px;background:var(--bg3);box-shadow:0 6px 16px rgba(0,0,0,.045)}
  #panel-logs .log-table-wrap tbody td{display:grid;width:100%;max-width:none;min-width:0;grid-template-columns:76px minmax(0,1fr);gap:9px;align-items:start;padding:8px 0;border-bottom:1px solid var(--border);white-space:normal!important;overflow:visible!important}
  #panel-logs .log-table-wrap tbody td:last-child{border-bottom:0}
  #panel-logs .log-table-wrap tbody td::before{content:attr(data-label);color:var(--text3);font-size:10px;font-weight:800;line-height:24px}
  #panel-logs .log-table-wrap tbody td[colspan]{display:block;grid-column:1/-1;padding:22px 8px;text-align:center;border-bottom:0}
  #panel-logs .log-table-wrap tbody td[colspan]::before{display:none}
  #panel-logs .log-table-wrap .log-ip-source{order:1}
  #panel-logs .log-table-wrap .log-status-cell{order:2}
  #panel-logs .log-table-wrap .log-count-cell{order:3}
  #panel-logs .log-table-wrap .log-intel-cell{order:4}
  #panel-logs .log-table-wrap .log-token-cell{order:5}
  #panel-logs .log-table-wrap .log-request-cell{order:6}
  #panel-logs .log-table-wrap .log-ua-source{order:7}
  #panel-logs .log-table-wrap .log-time-cell{order:8}
  #panel-logs .log-table-wrap .comment-cell,#panel-logs .log-table-wrap .log-muted-cell{order:9;max-width:none!important;white-space:normal!important}
  #panel-logs .log-table-wrap .ip-cell>div{display:flex!important;flex-wrap:wrap!important;gap:6px!important}
  #panel-logs .log-table-wrap .log-ip-count{min-width:0;flex-wrap:wrap}
  #panel-logs .log-table-wrap .log-token-cell>div{display:grid!important;width:100%;grid-template-columns:minmax(0,1fr) auto;gap:6px!important}
  #panel-logs .log-table-wrap .log-token-cell span{overflow-wrap:anywhere}
  #panel-logs .log-table-wrap .req-cell-wrap,#panel-logs .log-table-wrap .ua-cell-wrap{width:100%;max-width:none;align-items:flex-start}
  #panel-logs .log-table-wrap .req-cell,#panel-logs .log-table-wrap .ua-cell{display:block;max-width:none;white-space:normal;overflow-wrap:anywhere;line-height:1.55}
  #panel-logs .page-controls{justify-content:space-between;padding:8px 2px}
  #panel-logs #page-info{order:-1;width:100%}

  #security-actions{grid-template-columns:minmax(0,1fr);gap:0}
  .nav-item{scroll-snap-align:center}
  .nav-scroll{scroll-snap-type:x proximity}
}

@media (max-width:420px){
  .brand-copy{display:none}
  .sidebar{grid-template-columns:34px minmax(0,1fr);gap:5px;padding-inline:7px}
  .nav-item{padding-inline:8px}
  .topbar-subtitle,.auto-timer{display:none}
  .security-metrics,.pull-limit-summary,.rule-grid{grid-template-columns:minmax(0,1fr)}
  .log-mode-btns .mode-btn,.log-controls .mode-btn{flex-basis:100%}
}
</style>
</head>
<body>

<nav class="sidebar" aria-label="主导航">
  <div class="logo"><span class="brand-mark">S</span><span class="brand-copy"><?= htmlspecialchars(SITE_TITLE, ENT_QUOTES) ?><small>订阅网关控制台</small></span></div>
  <div class="nav-scroll">
    <div class="nav-section-label">工作台</div>
    <button class="nav-item active" data-tab="security" onclick="switchTab('security',this)">
      <span class="nav-icon">⌂</span><span class="nav-label">运行总览</span>
    </button>
    <button class="nav-item" data-tab="logs" onclick="switchTab('logs',this)">
      <span class="nav-icon">≡</span><span class="nav-label">拉取记录</span>
    </button>
    <button class="nav-item" data-tab="stats" onclick="switchTab('stats',this)">
      <span class="nav-icon">▥</span><span class="nav-label">风险分析</span>
    </button>
    <div class="nav-section-label">防护</div>
    <button class="nav-item" data-tab="protection" onclick="switchTab('protection',this)">
      <span class="nav-icon">◇</span><span class="nav-label">防护策略</span>
    </button>
    <button class="nav-item" data-tab="access" onclick="switchTab('access',this)">
      <span class="nav-icon">✓</span><span class="nav-label">访问控制</span>
    </button>
    <div class="nav-section-label">系统</div>
    <button class="nav-item" data-tab="settings" onclick="switchTab('settings',this)">
      <span class="nav-icon">⚙</span><span class="nav-label">系统设置</span>
    </button>
  </div>
  <div class="sidebar-bottom">
    <a href="<?= ADMIN_SECRET_PATH !== '' ? '/' . ADMIN_SECRET_PATH . '/logout' : '/logout' ?>" style="text-decoration:none">
      <button class="nav-item logout"><span class="nav-icon">↩</span><span class="nav-label">退出登录</span></button>
    </a>
  </div>
</nav>

<div class="main">
  <div class="topbar">
    <div class="topbar-copy">
      <div class="topbar-title" id="tab-title">运行总览</div>
      <div class="topbar-subtitle" id="tab-subtitle">网关健康、防护状态与待处理风险</div>
    </div>
    <div class="topbar-right">
      <span class="status-dot"></span>
      <span class="status-text">运行中</span>
      <span class="status-text auto-timer" id="auto-timer"></span>
      <button class="refresh-btn" onclick="manualRefresh()">手动刷新</button>
      <button class="theme-btn" id="theme-btn" onclick="cycleTheme()" title="切换主题">🌙 深色</button>
    </div>
  </div>

  <div class="content">

    <!-- ─── 安全状态 ───────────────────────────────────────── -->
    <div class="tab-panel active" id="panel-security">
      <div id="security-hero" class="security-hero">
        <div class="security-state">
          <div class="security-state-icon">✓</div>
          <div>
            <div class="security-state-title" id="security-state-title">正在读取安全状态</div>
            <div class="security-state-meta" id="security-state-meta">汇总网关、日志、规则和缓存状态</div>
          </div>
        </div>
        <div class="security-hero-actions">
          <button class="mode-btn" onclick="openPanelTab('logs')">查看拉取记录</button>
          <button class="btn-primary" onclick="loadSecurity({force:true})">刷新诊断</button>
        </div>
      </div>

      <div class="scope-note"><strong>独立边界</strong><span id="security-scope">只分析订阅网关日志，不连接机场用户、邮箱、订单或套餐数据库。</span></div>
      <div id="security-metrics" class="security-metrics"><div class="loading">加载安全指标…</div></div>

      <section class="pull-limit-panel" id="pull-limit-section">
        <div class="pull-limit-head">
          <div><div class="security-section-title">自动执行规则</div><div class="security-section-sub">达到硬阈值后由网关限速或临时暂停 Token</div></div>
          <span id="pull-limit-mode" class="limit-mode-badge paused">读取中</span>
        </div>
        <div id="pull-limit-rules" class="pull-limit-rule-grid"><div class="loading">加载限制规则…</div></div>
        <div class="pull-limit-subhead">当前使用（24 小时）</div>
        <div id="pull-limit-summary" class="pull-limit-summary"><div class="loading">加载用量…</div></div>
        <div id="pull-limit-usage" class="pull-limit-usage"></div>
        <div class="pull-limit-controls">
          <div class="pull-limit-switches">
            <label class="pull-limit-switch" id="pull-limit-monitor-control">
              <span class="pull-limit-switch-copy"><strong>用量监控</strong><small id="pull-limit-monitor-status">统计 Token 拉取用量与超限证据</small></span>
              <span class="switch-control"><input id="pull-limit-enabled" type="checkbox" onchange="handlePullLimitSwitchChange('monitor')"><span class="switch-track"></span></span>
            </label>
            <label class="pull-limit-switch" id="pull-limit-enforce-control">
              <span class="pull-limit-switch-copy"><strong>超限后自动执行</strong><small id="pull-limit-enforce-status">达到硬阈值后返回 429 并临时暂停</small></span>
              <span class="switch-control"><input id="pull-limit-enforce" type="checkbox" onchange="handlePullLimitSwitchChange('enforce')"><span class="switch-track"></span></span>
            </label>
          </div>
          <div class="rule-field"><label>24 小时不同 IP 上限</label><input class="ip-input" id="pull-limit-ips" type="number" min="2" max="200"></div>
          <div class="rule-field"><label>每分钟拉取硬上限</label><input class="ip-input" id="pull-limit-minute" type="number" min="3" max="300" oninput="updateGuardThresholdHint()"></div>
          <div class="rule-field"><label>超限暂停时长（小时）</label><input class="ip-input" id="pull-limit-hours" type="number" min="1" max="168"></div>
          <div class="pull-limit-actions">
            <div class="pull-limit-note">IP 白名单不受该规则影响。监控模式只记录超限证据；开启自动暂停后，频率限制即时生效，跨 IP 规则由后台巡检执行。</div>
            <button class="btn-primary" onclick="savePullLimitSettings()">保存并应用规则</button>
          </div>
        </div>
      </section>

      <div class="security-layout">
        <div>
          <section class="security-section" id="security-mechanisms-section">
            <div class="security-section-head">
              <div><div class="security-section-title">防护机制</div><div class="security-section-sub">显示实际运行状态，不以配置项存在代替健康</div></div>
              <button class="mode-btn" onclick="openPanelTab('settings')">系统设置</button>
            </div>
            <div id="security-mechanisms" class="mechanism-grid"><div class="loading">加载中…</div></div>
          </section>

        </div>

        <div>
          <section class="security-section" id="security-health-section">
            <div class="security-section-head">
              <div><div class="security-section-title">运行健康</div><div class="security-section-sub">缓存、规则、日志和清理任务</div></div>
            </div>
            <div id="security-health" class="health-list"><div class="loading">加载中…</div></div>
          </section>

          <section class="security-section" id="guard-threshold-section">
            <div class="security-section-head">
              <div><div class="security-section-title">风险预警规则</div><div class="security-section-sub">只生成风险证据，不会自动封禁；预警应早于自动执行</div></div>
            </div>
            <label style="display:flex;align-items:center;gap:9px;color:var(--text2);font-size:12px;margin-bottom:12px">
              <input id="guard-observe-enabled" type="checkbox" style="width:18px;height:18px" onchange="updateGuardThresholdHint()"> 开启风险预警
            </label>
            <div class="rule-grid">
              <div class="rule-field"><label>单 IP / 分钟</label><input class="ip-input" id="guard-ip-minute" type="number" min="5" max="5000"></div>
              <div class="rule-field"><label>单 IP / 今日拉取</label><input class="ip-input" id="guard-ip-daily" type="number" min="20" max="100000"></div>
              <div class="rule-field"><label>单 Token / 分钟预警</label><input class="ip-input" id="guard-token-minute" type="number" min="2" max="5000" oninput="updateGuardThresholdHint()"><span id="guard-token-minute-hint" class="rule-hint">读取自动执行阈值…</span></div>
              <div class="rule-field"><label>Token / 小时不同 IP</label><input class="ip-input" id="guard-token-hour-ips" type="number" min="2" max="500"></div>
              <div class="rule-field"><label>IP / 小时不同 Token</label><input class="ip-input" id="guard-ip-hour-tokens" type="number" min="2" max="1000"></div>
              <div class="rule-field"><label>单 IP 五分钟 404</label><input class="ip-input" id="guard-ip-404" type="number" min="5" max="5000"></div>
              <div class="rule-field"><label>扫描日志行数</label><input class="ip-input" id="guard-scan-lines" type="number" min="1000" max="100000" step="1000"></div>
            </div>
            <div class="rule-actions">
              <button class="mode-btn" onclick="applyGuardPreset('strict')">严格</button>
              <button class="mode-btn" onclick="applyGuardPreset('balanced')">均衡</button>
              <button class="mode-btn" onclick="applyGuardPreset('quiet')">宽松</button>
              <button class="btn-primary" onclick="saveGuardSettings()">保存预警规则</button>
            </div>
          </section>

          <section class="security-section" id="security-actions-section">
            <div class="security-section-head">
              <div><div class="security-section-title">最近处理</div><div class="security-section-sub">名单操作与告警审计</div></div>
            </div>
            <div id="security-actions" class="action-list"><div class="loading">加载中…</div></div>
          </section>
        </div>
      </div>
    </div>

    <!-- ─── 防护策略 ─────────────────────────────────────── -->
    <div class="tab-panel" id="panel-protection">
      <div class="workspace-intro">
        <div><div class="workspace-kicker">策略中心</div><h1>防护策略</h1><p>风险预警负责提前发现异常，自动执行负责限速和暂停，两层规则按先预警后拦截运行。</p></div>
        <button class="mode-btn" onclick="openPanelTab('security')">查看运行状态</button>
      </div>
      <div id="protection-content" class="protection-stack"></div>
    </div>

    <!-- ─── 访问控制 ─────────────────────────────────────── -->
    <div class="tab-panel" id="panel-access">
      <div class="workspace-intro access-intro">
        <div><div class="workspace-kicker">规则中心</div><h1>访问控制</h1><p id="access-description">维护受信任 IP、拦截 IP、Token 与 UA 规则，所有修改沿用原有即时生效机制。</p></div>
      </div>
      <div class="control-tabs" role="tablist" aria-label="访问控制分类">
        <button class="control-tab active" data-access="whitelist" onclick="showAccessSection('whitelist')">IP 白名单</button>
        <button class="control-tab" data-access="blacklist" onclick="showAccessSection('blacklist')">IP 黑名单</button>
        <button class="control-tab" data-access="token_blacklist" onclick="showAccessSection('token_blacklist')">Token 黑名单</button>
        <button class="control-tab" data-access="ua_blacklist" onclick="showAccessSection('ua_blacklist')">UA 规则</button>
      </div>
      <div id="access-stage" class="access-stage"></div>
    </div>

    <!-- ─── 日志 ─────────────────────────────────────────── -->
    <div class="tab-panel" id="panel-logs">
      <div class="workspace-intro">
        <div><div class="workspace-kicker">审计中心</div><h1>拉取记录</h1><p>检索订阅请求、状态分布、来源情报与客户端特征；手机端按记录逐条展示。</p></div>
        <button class="mode-btn" onclick="loadTab('logs',{force:true})">刷新记录</button>
      </div>
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
          <div class="log-page-size" aria-label="每页记录数">
            <button class="mode-btn active" id="limit-btn-50"  onclick="setLogLimit(50)">50条</button>
            <button class="mode-btn" id="limit-btn-100" onclick="setLogLimit(100)">100条</button>
            <button class="mode-btn" id="limit-btn-500" onclick="setLogLimit(500)">500条</button>
            <button class="mode-btn" id="limit-btn-inf" onclick="setLogLimit(0)">瀑布流</button>
          </div>
        </div>
        <div id="log-status-summary" class="log-status-summary" aria-live="polite">
          <div class="log-status-heading"><span class="log-status-title">当前筛选</span><span class="log-status-caption">统计加载中…</span></div>
        </div>
        <div class="log-table-wrap">
          <table>
            <thead>
              <tr>
                <th>时间</th><th>IP</th><th style="color:#64748b;font-weight:400;font-size:11px" title="显示该IP在白/黑名单中的备注，如需修改请前往对应管理页">备注 <span style="opacity:.6">（只读）</span></th><th>状态</th><th title="当前筛选范围内：总次数与成功 / 403 / 429 / 444 分布">该 IP 计数</th><th>IP 情报</th><th>Token</th>
                <th>请求</th><th>UA</th>
              </tr>
            </thead>
            <tbody id="log-tbody"><tr><td colspan="9" class="loading">加载中…</td></tr></tbody>
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
      <div class="workspace-intro">
        <div><div class="workspace-kicker">处置中心</div><h1>风险工作台</h1><p>集中处理高频拉取、Token 共享、异常来源与脚本扫描事件。</p></div>
        <button class="mode-btn" onclick="loadTab('stats',{force:true})">刷新分析</button>
      </div>
      <div id="risk-analysis-summary" class="analysis-core-grid"><div class="loading">加载风险分类…</div></div>
      <section class="security-section risk-workbench">
        <div class="security-section-head">
          <div><div class="security-section-title">风险复核</div><div class="security-section-sub">命中阈值后进入复核；人工确认后再执行封禁</div></div>
          <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end"><span class="auto-timer" id="guard-review-summary"></span><button class="mode-btn ai-run-btn" onclick="runAiAnalysis('')">AI 研判队列</button></div>
        </div>
        <div class="risk-kind-toolbar" aria-label="风险分类">
          <button class="mode-btn active" id="guard-kind-all" onclick="setGuardRiskKind('all')">全部风险</button>
          <button class="mode-btn" id="guard-kind-volume" onclick="setGuardRiskKind('volume')">高频拉取</button>
          <button class="mode-btn" id="guard-kind-token" onclick="setGuardRiskKind('token')">Token 异常</button>
          <button class="mode-btn" id="guard-kind-source" onclick="setGuardRiskKind('source')">来源异常</button>
          <button class="mode-btn" id="guard-kind-scanner" onclick="setGuardRiskKind('scanner')">脚本扫描</button>
        </div>
        <div class="review-toolbar">
          <div class="log-mode-btns">
            <button class="mode-btn active" id="guard-filter-active" onclick="setGuardFilter('active')">待处理</button>
            <button class="mode-btn" id="guard-filter-all" onclick="setGuardFilter('all')">全部</button>
            <button class="mode-btn" id="guard-filter-trusted" onclick="setGuardFilter('trusted')">已判可信</button>
          </div>
          <div class="review-size-control" aria-label="每页显示数量">
            <span>每页</span>
            <button class="mode-btn active" id="guard-size-5" onclick="setGuardPageSize(5)">5</button>
            <button class="mode-btn" id="guard-size-10" onclick="setGuardPageSize(10)">10</button>
            <button class="mode-btn" id="guard-size-20" onclick="setGuardPageSize(20)">20</button>
          </div>
        </div>
        <div id="ai-analysis-panel" class="ai-analysis-panel"></div>
        <div id="security-findings" class="risk-list"><div class="loading">加载风险队列…</div></div>
        <div id="guard-pagination" class="review-pagination"></div>
      </section>
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
          ⚡ Token 黑名单会在订阅入口进行精确匹配并立即返回 403；黑名单内 Token 同时从风险统计中排除，避免重复告警。
        </div>
        <div id="tb-list"><div class="loading">加载中…</div></div>
      </div>
    </div>

    <!-- ─── 系统设置 ───────────────────────────────────────── -->
    <div class="tab-panel" id="panel-settings">
      <div class="workspace-intro">
        <div><div class="workspace-kicker">配置中心</div><h1>系统设置</h1><p>按账户、网关和运维边界分组管理，配置卡片按内容自然排列。</p></div>
      </div>
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
            <div class="apply-hint" id="credential-security-status">密码状态加载中…</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              <button class="mode-btn" id="totp-setup-btn" onclick="prepareTotp()">配置两步验证</button>
              <button class="mode-btn" id="totp-disable-btn" onclick="showTotpDisable()" style="display:none">关闭两步验证</button>
            </div>
            <div id="totp-setup-panel" style="display:none;padding:12px;border:1px solid var(--border2);border-radius:7px;background:var(--bg-input)">
              <div class="apply-hint" style="margin-bottom:8px">在验证器应用中选择“手动输入密钥”，然后输入当前 6 位验证码。密钥仅在本次配置中显示。</div>
              <label style="display:block;color:var(--text2);font-size:12px;margin-bottom:5px">手动密钥</label>
              <code id="totp-secret" style="display:block;overflow-wrap:anywhere;user-select:all;color:var(--text);margin-bottom:10px"></code>
              <input class="ip-input" id="totp-enable-code" inputmode="numeric" maxlength="6" placeholder="6 位验证码" style="width:100%;margin-bottom:8px">
              <div style="display:flex;gap:8px">
                <button class="btn-primary" onclick="enableTotp()">确认启用</button>
                <button class="mode-btn" onclick="cancelTotpSetup()">取消</button>
              </div>
            </div>
            <div id="totp-disable-panel" style="display:none;padding:12px;border:1px solid var(--border2);border-radius:7px;background:var(--bg-input)">
              <div class="apply-hint" style="margin-bottom:8px">输入验证器中的当前验证码后关闭两步验证。</div>
              <input class="ip-input" id="totp-disable-code" inputmode="numeric" maxlength="6" placeholder="6 位验证码" style="width:100%;margin-bottom:8px">
              <div style="display:flex;gap:8px">
                <button class="btn-danger" onclick="disableTotp()">确认关闭</button>
                <button class="mode-btn" onclick="hideTotpDisable()">取消</button>
              </div>
            </div>
            <div class="apply-hint" style="color:#eab308">修改用户名、密码或启用两步验证后会立即退出当前会话。</div>
            <div class="apply-hint" style="color:#64748b;font-size:11px;line-height:1.5">密码只保存不可逆哈希，后台无法查看原密码。忘记凭证时请使用 README 中的宿主机恢复命令。</div>
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

        <!-- AI 风险研判 -->
        <div class="card">
          <div class="card-title">AI 风险研判</div>
          <div style="display:flex;flex-direction:column;gap:12px">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
              <label style="display:flex;align-items:center;gap:9px;color:var(--text2);font-size:13px"><input id="cfg-ai-enabled" type="checkbox" style="width:18px;height:18px">开启 AI 研判</label>
              <span id="cfg-ai-key-status" class="ai-secret-status">未保存 Token</span>
            </div>
            <label style="display:flex;align-items:center;gap:9px;color:var(--text2);font-size:13px"><input id="cfg-ai-auto" type="checkbox" style="width:18px;height:18px">后台定时分析风险队列</label>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:9px">
              <div><label style="display:block;color:var(--text2);font-size:12px;margin-bottom:5px">AI 厂商</label><select class="ip-input" id="cfg-ai-provider" onchange="aiProviderChanged()" style="width:100%"></select></div>
              <div id="cfg-ai-adapter-wrap" style="display:none"><label style="display:block;color:var(--text2);font-size:12px;margin-bottom:5px">接口协议</label><select class="ip-input" id="cfg-ai-adapter" style="width:100%"><option value="openai_compatible">OpenAI 兼容</option><option value="anthropic">Anthropic Messages</option><option value="gemini">Gemini</option></select></div>
              <div><label style="display:block;color:var(--text2);font-size:12px;margin-bottom:5px">模型</label><input class="ip-input" id="cfg-ai-model" placeholder="模型名称" style="width:100%"></div>
            </div>
            <div><label style="display:block;color:var(--text2);font-size:12px;margin-bottom:5px">API 地址</label><input class="ip-input" id="cfg-ai-base-url" placeholder="https://api.example.com/v1" style="width:100%"></div>
            <div><label style="display:block;color:var(--text2);font-size:12px;margin-bottom:5px">API Token</label><input class="ip-input" id="cfg-ai-api-key" type="password" autocomplete="new-password" placeholder="留空则保留服务器中已保存的 Token" style="width:100%"></div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:9px">
              <div><label style="display:block;color:var(--text2);font-size:12px;margin-bottom:5px">自动分析间隔（分钟）</label><input class="ip-input" id="cfg-ai-interval" type="number" min="5" max="1440" value="30" style="width:100%"></div>
              <div><label style="display:block;color:var(--text2);font-size:12px;margin-bottom:5px">每次最多风险数</label><input class="ip-input" id="cfg-ai-max-findings" type="number" min="1" max="30" value="10" style="width:100%"></div>
            </div>
            <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
              <label style="display:flex;align-items:center;gap:7px;color:var(--text2);font-size:12px"><input id="cfg-ai-include-ip" type="checkbox">发送来源 IP</label>
              <label style="display:flex;align-items:center;gap:7px;color:var(--text2);font-size:12px"><input id="cfg-ai-include-ua" type="checkbox">发送 UA</label>
              <label style="display:flex;align-items:center;gap:7px;color:var(--text2);font-size:12px"><input id="cfg-ai-include-path" type="checkbox" checked>发送请求路径</label>
            </div>
            <div class="apply-hint" style="color:var(--text3)">Token 仅保存在服务器数据卷，页面和 API 不回显；原始订阅 Token 永不发送。AI 仅提供复核建议，不自动执行封禁。</div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(100px,1fr));gap:8px">
              <button class="btn-primary" onclick="saveAiSettings()">保存设置</button>
              <button class="mode-btn" onclick="testAiSettings()">测试连接</button>
              <button class="mode-btn danger" onclick="clearAiToken()">清除 Token</button>
            </div>
          </div>
        </div>


      </div>
    </div>

  </div><!-- .content -->
</div><!-- .main -->

<div id="toast"></div>

<dialog id="token-investigation-dialog" class="investigation-dialog" onclick="if(event.target===this) closeTokenInvestigation()">
  <div class="investigation-shell">
    <header class="investigation-head">
      <div><div class="investigation-kicker">TOKEN 调查档案</div><div class="investigation-title" id="investigation-title">正在读取证据</div></div>
      <button class="mode-btn investigation-close" onclick="closeTokenInvestigation()" title="关闭" aria-label="关闭">×</button>
    </header>
    <div class="investigation-body" id="investigation-body"><div class="loading">正在聚合最近 24 小时证据…</div></div>
    <footer class="investigation-actions">
      <span class="investigation-note">只分析网关日志和缓存情报，不连接机场用户数据库。</span>
      <button class="mode-btn" id="investigation-copy" onclick="copyInvestigationToken()" disabled>复制 Token</button>
      <button class="mode-btn" id="investigation-logs" onclick="openInvestigationLogs()" disabled>查看拉取记录</button>
      <button class="btn-danger" id="investigation-ban" onclick="blacklistInvestigationToken()" disabled>拉黑 Token</button>
    </footer>
  </div>
</dialog>

<script>
// ── 状态 ─────────────────────────────────────────────────────
const BASE = <?= json_encode(ADMIN_SECRET_PATH !== '' ? '/' . ADMIN_SECRET_PATH : '') ?>;
const CSRF_TOKEN = <?= json_encode(admin_csrf_token()) ?>;
let activeSubscribePath = <?= json_encode($_preSg['subscribe_path'] ?? '/api/v1/client/subscribe') ?>;
let allLogs = [];
let logMode = 'today';   // 'today' | 'all'
let logLimit = 50;       // 0=瀑布流（无限制）
let logPage = 1;         // 当前页（分页模式）
let logIpIntel = new Map();
let blacklistIpSet = new Set();
let whitelistIpSet = new Set();
let cloudCidrs = [];     // 云服务商CIDR列表，用于检测云IP
let allStatsData = null; // 完整统计数据缓存
let securityData = null;
let aiModuleData = null;
let aiAnalyzing = false;
let tokenInvestigationData = null;
let guardReviewFilter = 'active';
let guardRiskKindFilter = 'all';
let guardReviewPage = 1;
let guardReviewPageSize = 5;
let allBlEntries = [];   // 黑名单完整数据缓存
let allWlEntries = [];   // 白名单完整数据缓存
let wlCommentMap = {};   // ip → 白名单备注（供日志列显示）
let blCommentMap = {};   // ip → 黑名单备注（供日志列显示）
let cloudProviderDraft = {};
let cloudProviderRows = [];
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
let activeAccessSection = 'whitelist';
let workspaceMounted = false;

const ACCESS_SECTIONS = {
  whitelist: {
    title: 'IP 白名单',
    description: '受信任 IP 与 CIDR 将跳过 IP、云服务商和 Token 拉取限制。',
    loader: loadWhitelist,
  },
  blacklist: {
    title: 'IP 黑名单',
    description: '拦截恶意 IP 与 CIDR，规则保存后立即进入网关配置。',
    loader: loadBlacklist,
  },
  token_blacklist: {
    title: 'Token 黑名单',
    description: '精确停用指定订阅 Token，并从重复风险统计中排除。',
    loader: loadTokenBlacklist,
  },
  ua_blacklist: {
    title: 'UA 规则',
    description: '集中维护 UA 放行和拦截关键词，降低扫描器与异常客户端干扰。',
    loader: loadUaBlacklist,
  },
};

function mountWorkspaceLayout() {
  if (workspaceMounted) return;
  workspaceMounted = true;

  const protectionTarget = document.getElementById('protection-content');
  ['pull-limit-section', 'guard-threshold-section'].forEach(id => {
    const section = document.getElementById(id);
    if (section && protectionTarget) protectionTarget.appendChild(section);
  });

  const accessStage = document.getElementById('access-stage');
  Object.keys(ACCESS_SECTIONS).forEach(name => {
    const source = document.getElementById('panel-' + name);
    if (!source || !accessStage) return;
    const pane = document.createElement('section');
    pane.className = 'access-pane' + (name === activeAccessSection ? ' active' : '');
    pane.dataset.accessPane = name;
    while (source.firstChild) pane.appendChild(source.firstChild);
    accessStage.appendChild(pane);
    source.remove();
  });

  const settingsGrid = document.querySelector('#panel-settings > .stats-grid');
  if (!settingsGrid) return;
  const cards = Array.from(settingsGrid.children).filter(el => el.classList.contains('card'));
  const byTitle = new Map(cards.map(card => [card.querySelector('.card-title')?.textContent.trim(), card]));
  const groups = [
    {key:'identity', title:'界面与账户', sub:'管理控制台名称和管理员登录凭证', cards:['界面设置','登录凭证']},
    {key:'gateway', title:'网关与上游', sub:'管理机场反代、监听端口和 TLS 证书', cards:['机场（反代目标）','订阅网关','SSL 证书']},
    {key:'operations', title:'运维与通知', sub:'管理统计预热、告警推送和 AI 辅助研判', cards:['分析统计缓存','告警推送','AI 风险研判']},
  ];
  settingsGrid.className = 'settings-groups';
  settingsGrid.removeAttribute('style');
  settingsGrid.replaceChildren();
  groups.forEach(group => {
    const section = document.createElement('section');
    section.className = 'settings-cluster';
    section.dataset.settingsGroup = group.key;
    section.innerHTML = `<div class="settings-cluster-head"><div><div class="settings-cluster-title">${esc(group.title)}</div><div class="settings-cluster-sub">${esc(group.sub)}</div></div></div><div class="settings-cluster-grid"></div>`;
    const grid = section.querySelector('.settings-cluster-grid');
    group.cards.forEach(title => {
      const card = byTitle.get(title);
      if (card) grid.appendChild(card);
    });
    settingsGrid.appendChild(section);
  });
}

async function loadProtection(opts={}) {
  if (!securityData || opts.force) await loadSecurity({force:!!opts.force});
  else {
    renderPullLimits();
    renderGuardRules();
  }
}

async function loadAccessControl() {
  await Promise.all(Object.values(ACCESS_SECTIONS).map(section => section.loader()));
}

function showAccessSection(name) {
  if (!ACCESS_SECTIONS[name]) return;
  activeAccessSection = name;
  document.querySelectorAll('.control-tab').forEach(button => button.classList.toggle('active', button.dataset.access === name));
  document.querySelectorAll('.access-pane').forEach(pane => pane.classList.toggle('active', pane.dataset.accessPane === name));
  const description = document.getElementById('access-description');
  if (description) description.textContent = ACCESS_SECTIONS[name].description;
}

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
  security:   {title:'运行总览', subtitle:'网关健康、防护状态与待处理风险', loader:loadSecurity},
  logs:       {title:'拉取记录', subtitle:'检索订阅请求、状态码、Token 与客户端特征', loader:loadLogs},
  stats:      {title:'风险分析', subtitle:'高频拉取、Token 异常、来源异常与脚本扫描处置', loader:loadStats},
  protection: {title:'防护策略', subtitle:'风险预警与自动执行规则', loader:loadProtection},
  access:     {title:'访问控制', subtitle:'统一维护 IP、Token 与 UA 放行和拦截规则', loader:loadAccessControl},
  settings:   {title:'系统设置', subtitle:'界面账户、网关上游、证书与告警运维', loader:loadSettings},
};
let currentTab = 'security';

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
    const names = Object.keys(TABS).filter(name => name !== 'security');
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
  if (!TABS[name]) return;
  currentTab = name;
  document.querySelectorAll('.nav-item').forEach(b => b.classList.remove('active'));
  if (el) el.classList.add('active');
  if (el && window.matchMedia('(max-width: 760px)').matches) {
    el.scrollIntoView({behavior:'smooth', block:'nearest', inline:'center'});
  }
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  const panel = document.getElementById('panel-' + name);
  if (!panel) return;
  panel.classList.add('active');
  restartAnimation(panel);
  document.getElementById('tab-title').textContent = TABS[name].title;
  document.getElementById('tab-subtitle').textContent = TABS[name].subtitle || '';
  if (name === 'access') showAccessSection(activeAccessSection);
  resetCountdown();
  loadTab(name);
}

function openPanelTab(name) {
  if (ACCESS_SECTIONS[name]) {
    activeAccessSection = name;
    switchTab('access', document.querySelector('.nav-item[data-tab="access"]'));
    showAccessSection(name);
    return;
  }
  switchTab(name, document.querySelector(`.nav-item[data-tab="${name}"]`));
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
    const method = String(opts.method || 'GET').toUpperCase();
    const headers = {'X-Requested-With':'XMLHttpRequest', ...(opts.headers || {})};
    if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) headers['X-CSRF-Token'] = CSRF_TOKEN;
    const r = await fetch(BASE + url, {...opts, headers, credentials:'same-origin'});
    const ct = r.headers.get('Content-Type') || '';
    if (!ct.includes('application/json')) {
      return {ok: false, error: `HTTP ${r.status}：服务器未返回 JSON`};
    }
    try {
      const data = await r.json();
      if (r.status === 401) setTimeout(() => { location.href = BASE + '/'; }, 150);
      return data;
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
  document.getElementById('log-tbody').innerHTML = '<tr><td colspan="9" class="loading">加载中…</td></tr>';
  document.getElementById('log-status-summary').innerHTML = '<div class="log-status-heading"><span class="log-status-title">当前筛选</span><span class="log-status-caption">统计加载中…</span></div>';
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
    document.getElementById('log-tbody').innerHTML = '<tr><td colspan="9" class="empty">加载失败：' + esc(logsData.error||'未知错误') + '</td></tr>';
    document.getElementById('log-status-summary').innerHTML = '<div class="log-status-heading"><span class="log-status-title">当前筛选</span><span class="log-status-caption">暂时无法统计</span></div>';
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

  const ipStatusStats = buildLogIpStatusStats(rows);
  renderLogStatusSummary(rows, ipStatusStats);

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
        '<tr><td colspan="9" class="empty">暂无匹配记录</td></tr>';
      return;
    }
    renderLogRows(displayRows, ipStatusStats);
  } else {
    // 瀑布流：显示全部，隐藏分页
    pg.style.display = 'none';
    document.getElementById('log-count').textContent = `${total} / ${allLogs.length} 条`;
    if (!total) {
      document.getElementById('log-tbody').innerHTML =
        '<tr><td colspan="9" class="empty">暂无匹配记录</td></tr>';
      return;
    }
    renderLogRows(rows, ipStatusStats);
  }
}

function buildLogIpStatusStats(rows) {
  const stats = new Map();
  rows.forEach(l => {
    if (!stats.has(l.ip)) stats.set(l.ip, {total:0,s200:0,s403:0,s429:0,s444:0});
    const item = stats.get(l.ip);
    item.total++;
    if (l.status == 200) item.s200++;
    else if (l.status == 403) item.s403++;
    else if (l.status == 429) item.s429++;
    else if (l.status == 444) item.s444++;
  });
  return stats;
}

function renderLogStatusSummary(rows, ipStats) {
  const totals = {s200:0,s403:0,s429:0,s444:0};
  ipStats.forEach(item => {
    totals.s200 += item.s200;
    totals.s403 += item.s403;
    totals.s429 += item.s429;
    totals.s444 += item.s444;
  });
  document.getElementById('log-status-summary').innerHTML = `
    <div class="log-status-heading">
      <span class="log-status-title">当前筛选</span>
      <span class="log-status-caption">${rows.length} 条请求 · ${ipStats.size} 个 IP</span>
    </div>
    <div class="log-status-legend" title="表格右侧计数顺序与此处一致">
      <span class="log-status-item log-status-success">成功 <strong>${totals.s200}</strong></span>
      <span class="log-status-item log-status-403">拦截 403 <strong>${totals.s403}</strong></span>
      <span class="log-status-item log-status-429">限速 429 <strong>${totals.s429}</strong></span>
      <span class="log-status-item log-status-444">断连 444 <strong>${totals.s444}</strong></span>
    </div>`;
}

function renderExternalIpLinks(ip) {
  const encoded = encodeURIComponent(ip);
  return `<div class="log-intel-links" aria-label="外部 IP 情报源">
    <a href="https://ipwho.is/${encoded}" target="_blank" rel="noopener noreferrer">ipwho.is</a>
    <a href="https://stat.ripe.net/app/launchpad/${encoded}" target="_blank" rel="noopener noreferrer">RIPEstat</a>
    <a href="https://ip.ipyard.com/" target="_blank" rel="noopener noreferrer" data-ip="${esc(ip)}" onclick="copyText(this.dataset.ip)" title="打开 IPYard，并已复制该 IP">IPYard</a>
  </div>`;
}

function renderLogIpIntel(ip) {
  const intel = logIpIntel.get(ip);
  const links = renderExternalIpLinks(ip);
  if (!intel || intel.status === 'loading' || intel.status === 'pending') {
    const label = intel?.status === 'pending' ? '后台查询中，稍后刷新' : '正在读取 IP 情报';
    return `<div class="log-intel"><div class="log-intel-pending">${label}</div>${links}</div>`;
  }
  if (intel.status !== 'ready') {
    return `<div class="log-intel"><div class="log-intel-pending">情报暂不可用</div>${links}</div>`;
  }
  const level = ['high','review','low'].includes(intel.risk_level) ? intel.risk_level : 'unknown';
  const stale = intel.fresh === false ? ' · 待更新' : '';
  return `<div class="log-intel">
    <div class="log-intel-primary"><span class="log-intel-location" title="${esc(intel.location || '')}">${esc(intel.location || '未知地区')}</span><span class="log-intel-risk ${level}" title="${esc(intel.risk_reason || '')}">${esc(intel.risk_label || '未评估')}</span></div>
    <div class="log-intel-detail"><span class="log-intel-asn">${esc(intel.asn || '未知 ASN')}</span><span title="${esc(intel.operator || '')}">${esc(intel.operator || '未知运营商')}</span></div>
    <div class="log-intel-meta" title="${esc(intel.source || '')}">${esc(intel.network_type || '未知网络')} · ${Number(intel.source_count || 0)} 源 · 置信度 ${esc(intel.confidence || '未评估')}${stale}</div>
    ${links}
  </div>`;
}

function updateVisibleLogIntelCells() {
  document.querySelectorAll('[data-intel-ip]').forEach(cell => {
    cell.innerHTML = renderLogIpIntel(cell.dataset.intelIp || '');
  });
}

async function requestLogIpIntel(rows) {
  const now = Date.now();
  const ips = [...new Set(rows.map(row => row.ip).filter(Boolean))];
  const pending = ips.filter(ip => {
    const current = logIpIntel.get(ip);
    if (!current) return true;
    if (current.status === 'loading') return false;
    return current.status === 'pending' && now - Number(current.requested_at || 0) > 60000;
  });
  if (!pending.length) return;
  pending.forEach(ip => logIpIntel.set(ip, {status:'loading', requested_at:now}));
  updateVisibleLogIntelCells();
  const data = await apiFetch('/api/ip_intel.php?ips=' + encodeURIComponent(pending.join(',')));
  if (!data.ok) {
    pending.forEach(ip => logIpIntel.set(ip, {status:'error', requested_at:now}));
    updateVisibleLogIntelCells();
    return;
  }
  const received = new Set();
  (data.entries || []).forEach(item => {
    if (!item.ip) return;
    received.add(item.ip);
    logIpIntel.set(item.ip, {...item, requested_at:now});
  });
  pending.filter(ip => !received.has(ip)).forEach(ip => logIpIntel.set(ip, {status:'pending', requested_at:now}));
  updateVisibleLogIntelCells();
}

function updateSubscribePathLabel() {
  const el = document.getElementById('active-subscribe-path');
  if (el) el.textContent = activeSubscribePath ? `路径：${activeSubscribePath}` : '';
}

// ── 行内备注编辑（通用）──────────────────────────────────────
function makeCommentCell(apiPath, keyField, keyValue, comment) {
  const d = esc(comment || '');
  const display = d ? d : '<span style="opacity:.35">—</span>';
  return `<td class="comment-cell" data-label="备注" data-api="${esc(apiPath)}" data-keyf="${esc(keyField)}" data-keyv="${esc(keyValue)}" data-comment="${d}" style="color:#64748b;cursor:pointer;min-width:60px;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${d ? d + '（点击编辑）' : '点击添加备注'}">${display}</td>`;
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

function renderLogRows(rows, ipStatusStats) {
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
      ? `<div style="display:inline-flex;align-items:center;gap:3px;font-family:monospace;font-size:11px;color:#818cf8"><span title="${esc(l.token)}">${esc(l.token)}</span><button class="copy-btn" title="复制 Token" aria-label="复制 Token" data-val="${esc(l.token)}" onclick="copyText(this.dataset.val)">⧉</button></div>`
      : '—';
    const ipStats = ipStatusStats.get(l.ip) || {total:0,s200:0,s403:0,s429:0,s444:0};
    // 备注列：从白名单/黑名单备注映射获取，支持行内编辑
    const commentCell = isWhitelisted
      ? makeCommentCell('/api/whitelist.php', 'ip', l.ip, wlCommentMap[l.ip] || '')
      : isBlacklisted
        ? makeCommentCell('/api/blacklist.php', 'ip', l.ip, blCommentMap[l.ip] || '')
        : `<td class="log-muted-cell" data-label="备注" style="color:#475569;opacity:.55;font-size:11px">—</td>`;
    return `
    <tr>
      <td class="log-time-cell" data-label="时间" style="white-space:nowrap;color:#64748b;font-size:11px">${esc(l.time)}</td>
      <td class="ip-cell log-ip-source" data-label="来源 IP"><div style="display:inline-flex;align-items:center;gap:4px;flex-wrap:nowrap"><span>${esc(l.ip)}</span><button class="copy-btn" title="复制 IP" aria-label="复制 IP" data-val="${esc(l.ip)}" onclick="copyText(this.dataset.val)">⧉</button><span style="display:inline-block;width:2px"></span>${ipBtn}</div></td>
      ${commentCell}
      <td class="log-status-cell" data-label="状态">${statusBadge(l.status)}</td>
      <td class="log-count-cell" data-label="请求统计"><div class="log-ip-count"><strong>${ipStats.total}次</strong><span class="log-ip-breakdown" title="成功 / 拦截403 / 限速429 / 断连444"><span class="s200">${ipStats.s200}</span>/<span class="s403">${ipStats.s403}</span>/<span class="s429">${ipStats.s429}</span>/<span class="s444">${ipStats.s444}</span></span></div></td>
      <td class="log-intel-cell" data-label="IP 情报" data-intel-ip="${esc(l.ip)}">${renderLogIpIntel(l.ip)}</td>
      <td class="log-token-cell" data-label="Token" style="min-width:100px;max-width:200px">${tokenHtml}</td>
      <td class="log-request-cell" data-label="请求"><div class="req-cell-wrap"><span class="req-cell" title="${esc(l.request)}">${esc(l.request)}</span><button class="copy-btn" title="复制请求" aria-label="复制请求" data-val="${esc(l.request)}" onclick="copyText(this.dataset.val)">⧉</button></div></td>
      <td class="log-ua-source" data-label="客户端"><div class="ua-cell-wrap"><span class="ua-cell" title="${esc(l.ua)}">${esc(l.ua)||'—'}</span>${l.ua ? `<button class="copy-btn" title="复制 UA" aria-label="复制 UA" data-val="${esc(l.ua)}" onclick="copyText(this.dataset.val)">⧉</button>` : ''}</div></td>
    </tr>`;
  }).join('');
  attachCommentCells(tbody);
  requestLogIpIntel(rows);
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
  let data = await apiFetch('/api/stats.php' + (opts.force ? '?refresh=1' : ''));
  if (data.ok && !Array.isArray(data.pull_ips)) data = await apiFetch('/api/stats.php?refresh=1');
  if (!data.ok) {
    const target = document.getElementById('risk-analysis-summary');
    if (target) target.innerHTML = '<div class="empty">加载失败：' + esc(data.error||'未知错误') + '</div>';
    toast('加载统计失败: ' + (data.error||''), 'err'); return;
  }
  allStatsData = data;
  await Promise.all([loadSecurity({force:true}), loadAiModule()]);
  renderStats();
}

function renderStats() {
  if (!allStatsData || !securityData) return;
  renderRiskAnalysis();
  renderGuardFindings();
}

function guardFindingGroup(row) {
  const kind = String(row?.kind || '');
  if (['daily_ip_volume','ip_rate','token_rate'].includes(kind)) return 'volume';
  if (['token_multi_ip','history_token_ips'].includes(kind)) return 'token';
  if (['ip_multi_token','history_ip_tokens','ip_404_flood','idc_provider_block'].includes(kind)) return 'source';
  if (kind === 'scanner') return 'scanner';
  return 'source';
}

function renderRiskAnalysis() {
  const target = document.getElementById('risk-analysis-summary');
  if (!target || !securityData) return;
  const findings = securityData.findings || [];
  const active = findings.filter(row => ['pending','watch'].includes(row.review?.status || 'pending'));
  const groups = [
    {key:'volume',tone:'amber',icon:'↻',title:'高频拉取',unit:'条待处理',kinds:'今日累计与分钟频率'},
    {key:'token',tone:'violet',icon:'#',title:'Token 异常',unit:'条待处理',kinds:'多 IP 共享与高频调用'},
    {key:'source',tone:'rose',icon:'!',title:'来源异常',unit:'条待处理',kinds:'多 Token、404 与异常来源'},
    {key:'scanner',tone:'cyan',icon:'⌘',title:'脚本扫描',unit:'条待处理',kinds:'自动化客户端与扫描特征'},
  ];
  target.innerHTML = groups.map(group => {
    const rows = active.filter(row => guardFindingGroup(row) === group.key);
    const top = rows.slice().sort((a,b) => Number(b.score || 0) - Number(a.score || 0))[0];
    return `<button class="stats-card tone-${group.tone} ${guardRiskKindFilter === group.key ? 'active' : ''}" onclick="setGuardRiskKind('${group.key}')">
      <div class="stats-card-title"><span class="stats-card-kicker"><span class="stats-card-icon">${esc(group.icon)}</span>${esc(group.title)}</span></div>
      <div class="stats-card-count-row"><div class="stats-card-main">${rows.length}<span class="stats-card-unit">${esc(group.unit)}</span></div><span class="stats-card-status">${top ? esc((top.risk || '关注') + ' ' + Number(top.score || 0)) : '暂无异常'}</span></div>
      <div class="stats-card-sub">${top ? `${esc(top.title || '')} · ${esc(top.subject || '')}` : esc(group.kinds)}</div>
      <div class="stats-card-foot"><span>${guardRiskKindFilter === group.key ? '当前筛选' : '筛选此类'}</span><strong>→</strong></div>
    </button>`;
  }).join('');
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
  const cloudStatus = data.cloud_provider_status || {};
  cloudProviderRows = idcSummary.filter(row => row && row.id);
  cloudProviderDraft = Object.fromEntries(cloudProviderRows.map(row => [row.id, Boolean(row.enabled)]));

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

  if (cloudProviderRows.length) {
    const activeCidrs = cloudProviderRows.reduce((sum, row) => sum + Number(row.active_count || 0), 0);
    const enabledCount = cloudProviderRows.filter(row => row.enabled).length;
    const statusClass = ['updating','error'].includes(cloudStatus.status) ? cloudStatus.status : '';
    const statusText = cloudStatus.message || (cloudStatus.status === 'ready' ? '策略已生效' : '等待网关状态');
    html += `<div class="idc-section">
      <div class="idc-section-head">
        <div><div class="card-title">云服务商 / IDC 自动拦截</div><div class="idc-note">按厂商选择生效范围；命中后返回 403，并携带厂商与触发规则进入风险分析。显式 IP 白名单仍保持最高优先级。</div></div>
        <div class="idc-section-actions">
          <span class="idc-policy-status ${statusClass}">${esc(statusText)}</span>
          <button class="mode-btn" onclick="setAllCloudProviders(true)">全部开启</button>
          <button class="mode-btn" onclick="setAllCloudProviders(false)">全部关闭</button>
          <button class="mode-btn" onclick="resetCloudProviderDefaults()">恢复默认</button>
        </div>
      </div>
      <div class="idc-provider-grid">
        ${cloudProviderRows.map(row => {
          const available = row.available !== false;
          const hasCidrs = Number(row.count || 0) > 0;
          const pending = hasCidrs && Boolean(row.enabled) !== Boolean(row.active);
          const asns = Array.isArray(row.asns) && row.asns.length ? row.asns.join(' · ') : 'ASN 未配置';
          const keywords = Array.isArray(row.keywords) ? row.keywords.join('、') : '';
          const state = !available ? '暂无数据源' : (!hasCidrs ? '暂无可用 CIDR' : (row.enabled ? (row.active ? '拦截中' : '待应用') : '未拦截'));
          return `<div class="idc-provider-card ${row.enabled ? 'enabled' : ''} ${pending ? 'pending' : ''} ${available ? '' : 'unavailable'}" data-provider-card="${esc(row.id)}">
            <div>
              <div class="idc-provider-name">${esc(row.name)}</div>
              <div class="idc-provider-meta"><span>${Number(row.count || 0)} 条 CIDR</span><span>${esc(asns)}</span><span data-provider-state="${esc(row.id)}">${esc(state)}</span></div>
              ${keywords ? `<div class="idc-provider-keywords">识别关键词：${esc(keywords)}</div>` : ''}
            </div>
            <label class="provider-switch" title="${available ? '切换该厂商的自动拦截' : 'ASN/CIDR 尚未配置'}">
              <input type="checkbox" data-provider-toggle="${esc(row.id)}" ${row.enabled ? 'checked' : ''} ${available ? '' : 'disabled'} onchange="setCloudProviderDraft('${esc(row.id)}',this.checked)">
              <span class="provider-switch-track"></span>
            </label>
          </div>`;
        }).join('')}
      </div>
      <div class="idc-policy-footer"><span id="cloud-provider-selection">已选择 ${enabledCount} 家 · 当前生效 ${activeCidrs} 条 CIDR</span><button class="btn-primary" id="save-cloud-providers" onclick="saveCloudProviders()">保存并应用</button></div>
    </div>`;
  } else if (idcSummary.length) {
    html += `<div class="idc-section"><div class="card-title">系统内置 IDC 封禁</div><div class="table-wrap"><table><thead><tr><th>云服务商 / IDC</th><th>CIDR 数量</th></tr></thead><tbody>${idcSummary.map(row => `<tr><td>${esc(row.name)}</td><td>${Number(row.count || 0)} 条</td></tr>`).join('')}</tbody></table></div></div>`;
  }

  document.getElementById('bl-list').innerHTML = html;
  attachCommentCells(document.getElementById('bl-list'));
}

function updateCloudProviderSelection() {
  const enabledCount = Object.values(cloudProviderDraft).filter(Boolean).length;
  const label = document.getElementById('cloud-provider-selection');
  if (label) label.textContent = `已选择 ${enabledCount} 家 · 保存后由网关校验并应用`;
}

function setCloudProviderDraft(id, enabled) {
  if (!Object.prototype.hasOwnProperty.call(cloudProviderDraft, id)) return;
  cloudProviderDraft[id] = Boolean(enabled);
  const card = document.querySelector(`[data-provider-card="${id}"]`);
  card?.classList.toggle('enabled', Boolean(enabled));
  card?.classList.add('pending');
  const state = document.querySelector(`[data-provider-state="${id}"]`);
  if (state) state.textContent = enabled ? '待开启' : '待关闭';
  updateCloudProviderSelection();
}

function setAllCloudProviders(enabled) {
  cloudProviderRows.forEach(row => {
    if (row.available === false) return;
    cloudProviderDraft[row.id] = Boolean(enabled);
    const toggle = document.querySelector(`[data-provider-toggle="${row.id}"]`);
    if (toggle) toggle.checked = Boolean(enabled);
    setCloudProviderDraft(row.id, enabled);
  });
}

function resetCloudProviderDefaults() {
  cloudProviderRows.forEach(row => {
    const enabled = row.available !== false && Boolean(row.default_enabled);
    cloudProviderDraft[row.id] = enabled;
    const toggle = document.querySelector(`[data-provider-toggle="${row.id}"]`);
    if (toggle) toggle.checked = enabled;
    setCloudProviderDraft(row.id, enabled);
  });
}

async function saveCloudProviders() {
  const button = document.getElementById('save-cloud-providers');
  if (button) { button.disabled = true; button.textContent = '正在提交…'; }
  const result = await apiFetch('/api/blacklist.php', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
    body:JSON.stringify({action:'save_cloud_providers',enabled:cloudProviderDraft}),
  });
  if (!result.ok) {
    if (button) { button.disabled = false; button.textContent = '保存并应用'; }
    toast(result.error || '厂商策略保存失败', 'err');
    return;
  }
  toast(`已提交 ${Number(result.enabled_count || 0)} 家厂商，网关正在校验应用`);
  await waitForCloudProviderApply();
}

async function waitForCloudProviderApply() {
  for (let attempt = 0; attempt < 12; attempt++) {
    await new Promise(resolve => setTimeout(resolve, 2000));
    const data = await apiFetch('/api/blacklist.php');
    const status = data.cloud_provider_status || {};
    if (status.status === 'updating') continue;
    if (status.status === 'error') toast(status.message || '网关应用失败，已保留上一版策略', 'err');
    else toast(status.message || '云厂商拦截策略已生效');
    await loadBlacklist();
    return;
  }
  await loadBlacklist();
  toast('规则仍在后台更新，可稍后刷新查看状态');
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
  renderCredentialSecurity();
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
  if (alertWebhook) {
    alertWebhook.value = '';
    alertWebhook.placeholder = currentSettings.alert_webhook_configured ? '已安全保存，留空保持不变' : 'https://example.com/webhook';
  }
  const alertTelegramToken = document.getElementById('cfg-alert-telegram-token');
  if (alertTelegramToken) {
    alertTelegramToken.value = '';
    alertTelegramToken.placeholder = currentSettings.alert_telegram_token_configured ? '已安全保存，留空保持不变' : '123456:ABC…';
  }
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
  await loadAiModule();
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

function formatFileSize(bytes) {
  const units = ['B', 'KB', 'MB'];
  let value = Math.max(0, Number(bytes) || 0);
  let unit = 0;
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit++;
  }
  return `${unit === 0 ? value.toFixed(0) : value.toFixed(1)} ${units[unit]}`;
}

function alertImportAgeNote(value) {
  if (!value) return '';
  const ts = Date.parse(String(value).replace(' ', 'T'));
  if (!Number.isFinite(ts)) return '';
  const diff = Date.now() - ts;
  if (diff < -5 * 60 * 1000) return '提示：导出时间晚于当前时间，请确认服务器或本机时间是否一致。';
  if (diff > 7 * 24 * 3600 * 1000) return `提示：这份备份约 ${formatDuration(Math.floor(diff / 1000))}，导入前请确认不会覆盖较新的展示记录。`;
  return '';
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
  if (newPass) {
    if (newPass.length < 10) { toast('密码至少需要 10 位', 'err'); return; }
    body.new_pass = newPass;
    body.confirm_pass = confPass;
  }
  const d = await apiFetch('/api/settings.php', {
    method: 'POST', body: JSON.stringify(body),
    headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
  });
  if (d.ok) {
    toast('✅ ' + (d.msg || '凭证设置已保存'));
    document.getElementById('cfg-new-pass').value = '';
    document.getElementById('cfg-confirm-pass').value = '';
    if (d.reauth_required) setTimeout(() => { location.href = BASE + '/logout'; }, 900);
  } else {
    toast(d.error || '保存失败', 'err');
  }
}

function renderCredentialSecurity() {
  const hashed = !!currentSettings.admin_password_hashed;
  const totpEnabled = !!currentSettings.admin_totp_enabled;
  const status = document.getElementById('credential-security-status');
  if (status) {
    status.textContent = `${hashed ? '密码已使用安全哈希保存' : '旧密码将在下次成功登录后自动迁移'} · 两步验证${totpEnabled ? '已启用' : '未启用'}`;
    status.style.color = hashed && totpEnabled ? '#22c55e' : '#eab308';
  }
  const setupBtn = document.getElementById('totp-setup-btn');
  const disableBtn = document.getElementById('totp-disable-btn');
  if (setupBtn) setupBtn.style.display = totpEnabled ? 'none' : '';
  if (disableBtn) disableBtn.style.display = totpEnabled ? '' : 'none';
  if (totpEnabled) cancelTotpSetup();
  else hideTotpDisable();
}

async function prepareTotp() {
  const d = await apiFetch('/api/settings.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({_prepare_totp:1}),
  });
  if (!d.ok) { toast(d.error || '生成密钥失败', 'err'); return; }
  document.getElementById('totp-secret').textContent = d.secret || '';
  document.getElementById('totp-enable-code').value = '';
  document.getElementById('totp-setup-panel').style.display = 'block';
  document.getElementById('totp-enable-code').focus();
}

function cancelTotpSetup() {
  const panel = document.getElementById('totp-setup-panel');
  if (panel) panel.style.display = 'none';
  const secret = document.getElementById('totp-secret');
  if (secret) secret.textContent = '';
}

async function enableTotp() {
  const code = document.getElementById('totp-enable-code').value.trim();
  if (!/^\d{6}$/.test(code)) { toast('请输入 6 位验证码', 'err'); return; }
  const d = await apiFetch('/api/settings.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({_enable_totp:1, code}),
  });
  if (!d.ok) { toast(d.error || '启用失败', 'err'); return; }
  toast('✅ 两步验证已启用，请重新登录');
  setTimeout(() => { location.href = BASE + '/logout'; }, 900);
}

function showTotpDisable() {
  document.getElementById('totp-disable-panel').style.display = 'block';
  document.getElementById('totp-disable-code').value = '';
  document.getElementById('totp-disable-code').focus();
}

function hideTotpDisable() {
  const panel = document.getElementById('totp-disable-panel');
  if (panel) panel.style.display = 'none';
}

async function disableTotp() {
  const code = document.getElementById('totp-disable-code').value.trim();
  if (!/^\d{6}$/.test(code)) { toast('请输入 6 位验证码', 'err'); return; }
  const d = await apiFetch('/api/settings.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({_disable_totp:1, code}),
  });
  if (!d.ok) { toast(d.error || '关闭失败', 'err'); return; }
  toast('✅ 两步验证已关闭，请重新登录');
  if (d.reauth_required) setTimeout(() => { location.href = BASE + '/logout'; }, 900);
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
  const payload = {
    alert_enabled: document.getElementById('cfg-alert-enabled').checked ? 1 : 0,
    alert_channel: document.getElementById('cfg-alert-channel').value || 'webhook',
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
  const webhook = document.getElementById('cfg-alert-webhook-url').value.trim();
  const telegramToken = document.getElementById('cfg-alert-telegram-token').value.trim();
  if (webhook) payload.alert_webhook_url = webhook;
  if (telegramToken) payload.alert_telegram_bot_token = telegramToken;
  return payload;
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
    const tokenReady = !!body.alert_telegram_bot_token || !!currentSettings.alert_telegram_token_configured;
    if (!tokenReady || !body.alert_telegram_chat_id) {
      toast('请填写 Telegram Bot Token 和 Chat ID', 'err');
      return false;
    }
    return true;
  }
  if (!body.alert_webhook_url && !currentSettings.alert_webhook_configured) {
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
      headers: {'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': CSRF_TOKEN},
      credentials: 'same-origin',
    });
    const previewData = await previewRes.json();
    if (!previewData.ok) {
      toast(previewData.error || '预览失败', 'err');
      return;
    }
    const p = previewData.preview || {};
    const ctx = p.context || {};
    const ageNote = alertImportAgeNote(p.exported_at || '');
    const emptyNote = (p.total || 0) === 0 ? '提示：该文件没有可导入的告警记录，继续会清空当前展示历史。' : '';
    const contextLine = ctx.status_label || ctx.range_label || ctx.page || ctx.query
      ? `来源筛选：状态 ${ctx.status_label || ctx.status || '-'} / 时间 ${ctx.range_label || ctx.range || '-'} / 关键词 ${ctx.query || '-'} / 页码 ${ctx.page || '-'} / 范围 ${ctx.range_label_text || '-'}`
      : '';
    const lines = [
      '即将导入告警展示记录：',
      `文件：${file.name || '-'}（${formatFileSize(file.size)}）`,
      `导出时间：${p.exported_at || '-'}`,
      `总数：${p.total || 0} 条${p.truncated ? `（原文件 ${p.original_total || 0} 条，仅保留最近 ${p.history_max || p.total || 0} 条）` : ''}`,
      `已推送：${p.sent || 0} / 静默：${p.muted || 0} / 失败：${p.error || 0}`,
      `时间范围：${p.first_time || '-'} ~ ${p.last_time || '-'}`,
      ...(contextLine ? [contextLine] : []),
      ...(ageNote ? [ageNote] : []),
      ...(emptyNote ? [emptyNote] : []),
      '',
      '导入后会替换当前告警展示记录，但不会修改告警配置和去重状态。继续？',
    ];
    if (!confirm(lines.join('\n'))) return;
    const fd = new FormData();
    fd.append('history', file);
    const r = await fetch(BASE + '/api/settings.php?import_alert_history=1', {
      method: 'POST',
      body: fd,
      headers: {'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': CSRF_TOKEN},
      credentials: 'same-origin',
    });
    const d = await r.json();
    if (d.ok) {
      toast(`✅ 已导入 ${d.imported || 0} 条告警记录`);
      alertHistoryFilter = 'all';
      alertHistoryRange = 'all';
      alertHistoryQuery = '';
      alertHistoryPage = 1;
      clearTimeout(alertHistoryQueryTimer);
      await loadSettings();
    } else {
      toast(d.error || '导入失败', 'err');
    }
  } catch(e) {
    toast('导入失败: ' + e.message, 'err');
  }
}


// ── 安全状态 ──────────────────────────────────────────────────
async function loadSecurity(opts={}) {
  const query = opts.force ? '?refresh=1' : '';
  const d = await apiFetch('/api/security.php' + query);
  if (!d.ok) {
    document.getElementById('security-state-title').textContent = '安全状态加载失败';
    document.getElementById('security-state-meta').textContent = d.error || '服务器未返回有效数据';
    throw new Error(d.error || '安全状态加载失败');
  }
  securityData = d;
  renderSecurity();
}

function renderSecurity() {
  if (!securityData) return;
  const health = securityData.health || {};
  const hero = document.getElementById('security-hero');
  hero.classList.remove('attention', 'degraded');
  if (health.state === 'attention') hero.classList.add('attention');
  if (health.state === 'degraded') hero.classList.add('degraded');
  hero.querySelector('.security-state-icon').textContent = health.state === 'healthy' ? '✓' : (health.state === 'attention' ? '!' : '×');
  document.getElementById('security-state-title').textContent = health.label || '状态未知';
  document.getElementById('security-state-meta').textContent = `${securityData.mode === 'observe' ? '观察模式' : '观察已暂停'} · 更新于 ${securityData.generated_at || '-'}${securityData.cached ? ' · 缓存' : ''}`;
  document.getElementById('security-scope').textContent = securityData.scope || '独立网关日志分析';

  renderSecurityMetrics();
  renderPullLimits();
  renderSecurityMechanisms();
  renderSecurityHealth();
  renderGuardFindings();
  renderRiskAnalysis();
  renderGuardRules();
  renderSecurityActions();
}

function renderSecurityMetrics() {
  const m = securityData.metrics || {};
  const c = securityData.policy_counts || {};
  const rows = [
    {label:'今日订阅请求', value:m.today_requests || 0, note:`成功 ${m.today_success || 0} 次`, color:'#0ea5e9'},
    {label:'今日来源 IP', value:m.today_ips || 0, note:`扫描 ${m.observed_lines || 0} 行日志`, color:'#6366f1'},
    {label:'今日 Token 指纹', value:m.today_tokens || 0, note:'不在总览返回明文', color:'#8b5cf6'},
    {label:'风险复核队列', value:m.risk_findings || 0, note:`待处理 ${(securityData.review_summary || {}).pending || 0} 条`, color:'#f59e0b'},
    {label:'今日网关拦截', value:m.today_blocked || 0, note:'403 / 429 / 444', color:'#ef4444'},
    {label:'生效名单策略', value:(c.ip_blacklist || 0) + (c.token_blacklist || 0), note:`IP ${c.ip_blacklist || 0} · Token ${c.token_blacklist || 0}`, color:'#10b981'},
  ];
  document.getElementById('security-metrics').innerHTML = rows.map(row => `
    <div class="security-metric" style="--metric:${row.color}">
      <div class="security-metric-label">${esc(row.label)}</div>
      <div class="security-metric-value">${Number(row.value).toLocaleString()}</div>
      <div class="security-metric-note">${esc(row.note)}</div>
    </div>`).join('');
}

function renderPullLimits() {
  const data = securityData.pull_limits || {};
  const rules = data.settings || {};
  const summary = data.summary || {};
  const usage = Array.isArray(data.usage) ? data.usage : [];
  const mode = document.getElementById('pull-limit-mode');
  mode.className = 'limit-mode-badge ' + (!rules.enabled ? 'paused' : (rules.enforce ? 'enforce' : ''));
  mode.textContent = !rules.enabled ? '已关闭' : (rules.enforce ? '自动执行已启用' : '仅统计');

  const ruleRows = [
    {icon:'▣', label:'24 小时独立 IP 上限', value:`${rules.max_ips_24h || 10} 个不同 IP`},
    {icon:'ϟ', label:'拉取频率限制', value:`${rules.max_per_minute || 10} 次/分钟`},
    {icon:'◷', label:'超限暂停时长', value:`${rules.suspend_hours || 24} 小时`},
  ];
  document.getElementById('pull-limit-rules').innerHTML = ruleRows.map(row => `
    <div class="pull-limit-rule">
      <span class="pull-limit-icon">${esc(row.icon)}</span>
      <div><div class="pull-limit-rule-label">${esc(row.label)}</div><div class="pull-limit-rule-value">${esc(row.value)}</div></div>
    </div>`).join('');

  const summaryRows = [
    {value:summary.active_tokens || 0,label:'活跃 Token',color:'#0ea5e9'},
    {value:`${summary.max_rule_unique_ips || 0}/${rules.max_ips_24h || 10}`,label:'当前规则周期最高 IP',color:'#6366f1'},
    {value:`${summary.max_rule_per_minute || 0}/${rules.max_per_minute || 10}`,label:'当前规则周期最高频率',color:'#f59e0b'},
    {value:summary.suspended_tokens || 0,label:rules.enforce ? '当前暂停' : '模拟超限',color:'#ef4444'},
  ];
  if (!rules.enforce) summaryRows[3].value = summary.pending_violations || 0;
  document.getElementById('pull-limit-summary').innerHTML = summaryRows.map(row => `
    <div class="pull-limit-summary-item" style="--limit-color:${row.color}">
      <div class="pull-limit-summary-value">${esc(String(row.value))}</div>
      <div class="pull-limit-summary-label">${esc(row.label)}</div>
    </div>`).join('');

  const target = document.getElementById('pull-limit-usage');
  if (!usage.length) {
    target.innerHTML = '<div class="security-empty">24 小时内暂无可统计 Token</div>';
  } else {
    target.innerHTML = usage.slice(0, 8).map(row => {
      const ruleIps = Number(row.rule_unique_ips ?? row.unique_ips_24h ?? 0);
      const ruleMinute = Number(row.rule_peak_per_minute ?? row.peak_per_minute ?? 0);
      const ipRatio = Math.min(100, Math.round((ruleIps / Math.max(1, Number(rules.max_ips_24h || 10))) * 100));
      const minuteRatio = Math.min(100, Math.round((ruleMinute / Math.max(1, Number(rules.max_per_minute || 10))) * 100));
      const flagged = row.suspended || row.would_suspend;
      const statusClass = row.suspended ? 'blocked' : (row.would_suspend ? 'warn' : '');
      const status = row.suspended ? `暂停至 ${row.suspended_until || '-'}` : (row.would_suspend ? (rules.enforce ? '等待巡检' : '模拟超限') : '正常');
      return `<div class="pull-limit-row">
        <div><button class="pull-limit-token" style="padding:0;border:0;background:none;cursor:pointer;text-align:left" onclick="openTokenInvestigation(${jsArg(row.fingerprint)})" title="打开 Token 调查档案">${esc(row.fingerprint || '-')}</button><div style="color:var(--text3);margin-top:3px">24h ${Number(row.requests_24h || 0).toLocaleString()} 次 · ${row.unique_ips_24h || 0} IP · 最后 ${esc(row.last_seen || '-')}</div></div>
        <div title="24 小时总量 ${row.unique_ips_24h || 0}；规则周期从 ${esc(row.rule_since || '24 小时前')} 开始"><span style="color:var(--text2)">周期 IP ${ruleIps}/${rules.max_ips_24h || 10}</span><div class="pull-limit-meter ${flagged ? 'warn' : ''}"><span style="width:${ipRatio}%"></span></div></div>
        <div title="24 小时峰值 ${row.peak_per_minute || 0}；当前规则周期峰值 ${ruleMinute}"><span style="color:var(--text2)">周期峰值 ${ruleMinute}/${rules.max_per_minute || 10}</span><div class="pull-limit-meter ${minuteRatio >= 100 ? 'warn' : ''}"><span style="width:${minuteRatio}%"></span></div></div>
        <div class="pull-limit-status ${statusClass}">${esc(status)}</div>
        <div class="pull-limit-row-actions"><button class="mode-btn" onclick="openTokenInvestigation(${jsArg(row.fingerprint)})">调查</button>${row.suspended ? `<button class="mode-btn danger" onclick="releasePullLimit(${jsArg(row.fingerprint)})">解除</button>` : ''}</div>
      </div>`;
    }).join('');
  }

  document.getElementById('pull-limit-enabled').checked = !!rules.enabled;
  document.getElementById('pull-limit-enforce').checked = !!rules.enforce;
  document.getElementById('pull-limit-ips').value = rules.max_ips_24h || 10;
  document.getElementById('pull-limit-minute').value = rules.max_per_minute || 10;
  document.getElementById('pull-limit-hours').value = rules.suspend_hours || 24;
  syncPullLimitSwitches();
}

async function openTokenInvestigation(fingerprint='', token='') {
  const dialog = document.getElementById('token-investigation-dialog');
  tokenInvestigationData = null;
  document.getElementById('investigation-title').textContent = fingerprint || 'Token 调查档案';
  document.getElementById('investigation-body').innerHTML = '<div class="loading">正在聚合最近 24 小时证据…</div>';
  ['investigation-copy','investigation-logs','investigation-ban'].forEach(id => document.getElementById(id).disabled = true);
  if (!dialog.open) dialog.showModal();
  const d = await apiFetch('/api/token_investigation.php', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
    body:JSON.stringify(token ? {token} : {fingerprint}),
  });
  if (!d.ok) {
    document.getElementById('investigation-body').innerHTML = `<div class="empty">加载失败：${esc(d.error || '未知错误')}</div>`;
    return;
  }
  tokenInvestigationData = d.profile || null;
  renderTokenInvestigation();
}

function renderTokenInvestigation() {
  const p = tokenInvestigationData;
  if (!p) return;
  const s = p.summary || {};
  const level = Number(s.score || 0) >= 70 ? 'high' : (Number(s.score || 0) >= 40 ? 'review' : 'low');
  document.getElementById('investigation-title').textContent = p.fingerprint || 'Token 调查档案';
  const metrics = [
    [Number(s.score || 0), s.risk || '未评估', '#ef4444'],
    [Number(s.requests_24h || 0).toLocaleString(), '24 小时请求', '#0ea5e9'],
    [Number(s.unique_ips || 0), '独立 IP', '#6366f1'],
    [Number(s.unique_asns || 0), 'ASN', '#f59e0b'],
    [Number(s.ua_families || 0), '客户端类型', '#10b981'],
  ];
  const evidence = (p.evidence || []).map(item => `<div>${esc(item)}</div>`).join('');
  const ipRows = (p.ips || []).map(row => `<tr>
    <td><strong>${esc(row.ip || '-')}</strong><div style="color:var(--text3);margin-top:2px">${Number(row.count || 0)} 次 · ${esc(row.last_seen || '-')}</div></td>
    <td>${esc(row.location || '等待情报')}</td><td>${esc(row.asn || '未查询')}</td><td>${esc(row.operator || '未查询')}</td>
    <td><span class="investigation-risk ${row.high_risk ? 'high' : (row.intel_pending ? 'review' : 'low')}">${row.high_risk ? '高风险' : (row.intel_pending ? '待查询' : esc(row.network_type || '低风险'))}</span></td>
  </tr>`).join('');
  const uaRows = (p.uas || []).map(row => `<div class="investigation-ua"><span class="investigation-ua-family">${esc(row.family || '-')}</span><span class="investigation-ua-value" title="${esc(row.ua || '')}">${esc(row.ua || '-')}</span><strong>${Number(row.count || 0)} 次</strong></div>`).join('');
  const eventRows = (p.events || []).slice(0, 20).map(row => `<tr><td>${esc(row.time || '-')}</td><td>${esc(row.ip || '-')}</td><td>${statusBadge(row.status)}</td><td>${esc(row.ua_family || '-')}</td><td>${esc(row.location || '-')}</td></tr>`).join('');
  document.getElementById('investigation-body').innerHTML = `
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;flex-wrap:wrap"><span class="investigation-risk ${level}">${esc(s.risk || '未评估')} ${Number(s.score || 0)}</span><span style="color:var(--text3);font-size:10px">${esc(s.first_seen || '-')} 至 ${esc(s.last_seen || '-')}</span>${p.suspended ? `<span class="investigation-risk high">暂停至 ${esc(p.suspended_until || '-')}</span>` : ''}${p.blacklisted ? '<span class="investigation-risk high">已永久拉黑</span>' : ''}</div>
    <div class="investigation-metrics">${metrics.map(([value,label,color]) => `<div class="investigation-metric" style="--metric-color:${color}"><strong>${esc(String(value))}</strong><span>${esc(label)}</span></div>`).join('')}</div>
    <div class="investigation-grid">
      <div><section class="investigation-section"><h3>异常证据</h3><div class="investigation-evidence">${evidence}</div></section><section class="investigation-section"><h3>来源 IP 与外部情报</h3><div class="investigation-table-wrap"><table class="investigation-table"><thead><tr><th>IP</th><th>地区</th><th>ASN</th><th>运营商</th><th>风险</th></tr></thead><tbody>${ipRows || '<tr><td colspan="5">暂无数据</td></tr>'}</tbody></table></div></section></div>
      <div><section class="investigation-section"><h3>客户端分布</h3><div class="investigation-ua-list">${uaRows || '<div class="empty">暂无数据</div>'}</div></section><section class="investigation-section"><h3>最近事件</h3><div class="investigation-table-wrap"><table class="investigation-table"><thead><tr><th>时间</th><th>IP</th><th>状态</th><th>客户端</th><th>地区</th></tr></thead><tbody>${eventRows || '<tr><td colspan="5">暂无数据</td></tr>'}</tbody></table></div></section></div>
    </div>`;
  document.getElementById('investigation-copy').disabled = !p.raw_token;
  document.getElementById('investigation-logs').disabled = !p.raw_token;
  document.getElementById('investigation-ban').disabled = !p.raw_token || !!p.blacklisted;
}

function closeTokenInvestigation() {
  const dialog = document.getElementById('token-investigation-dialog');
  if (dialog.open) dialog.close();
}

function copyInvestigationToken() {
  if (tokenInvestigationData?.raw_token) copyText(tokenInvestigationData.raw_token);
}

function openInvestigationLogs() {
  const token = tokenInvestigationData?.raw_token || '';
  if (!token) return;
  document.getElementById('filter-token').value = token;
  logPage = 1;
  closeTokenInvestigation();
  openPanelTab('logs');
  loadTab('logs', {force:true}).catch(() => {});
}

async function blacklistInvestigationToken() {
  const token = tokenInvestigationData?.raw_token || '';
  if (!token) return;
  closeTokenInvestigation();
  await quickBanToken(token);
}

function guardThresholdRelationshipError() {
  const observe = document.getElementById('guard-observe-enabled')?.checked;
  const monitor = document.getElementById('pull-limit-enabled')?.checked;
  const enforce = document.getElementById('pull-limit-enforce')?.checked;
  if (!observe || !monitor || !enforce) return '';

  const warning = Number(document.getElementById('guard-token-minute')?.value);
  const limit = Number(document.getElementById('pull-limit-minute')?.value);
  if (!Number.isFinite(warning) || !Number.isFinite(limit) || warning < limit) return '';
  return `单 Token 分钟预警必须低于自动限速：当前预警 ${warning} 次/分钟，硬上限 ${limit} 次/分钟`;
}

function updateGuardThresholdHint() {
  const hint = document.getElementById('guard-token-minute-hint');
  if (!hint) return;
  const observe = document.getElementById('guard-observe-enabled')?.checked;
  const monitor = document.getElementById('pull-limit-enabled')?.checked;
  const enforce = document.getElementById('pull-limit-enforce')?.checked;
  const warning = Number(document.getElementById('guard-token-minute')?.value);
  const limit = Number(document.getElementById('pull-limit-minute')?.value);
  hint.className = 'rule-hint';
  if (!observe) {
    hint.textContent = '风险预警当前已关闭。';
    return;
  }
  if (!monitor || !enforce) {
    hint.textContent = '自动执行未开启，当前阈值只用于风险分析。';
    return;
  }
  if (!Number.isFinite(limit) || limit < 3) {
    hint.textContent = '自动限速硬上限至少为 3 次/分钟。';
    hint.classList.add('warn');
    return;
  }
  if (!Number.isFinite(warning) || warning >= limit) {
    hint.textContent = `应设置为 2–${limit - 1} 次/分钟，确保先预警、后限速。`;
    hint.classList.add('warn');
    return;
  }
  hint.textContent = `预警 ${warning} 次/分钟，自动限速 ${limit} 次/分钟，顺序正常。`;
  hint.classList.add('ok');
}

function syncPullLimitSwitches() {
  const monitor = document.getElementById('pull-limit-enabled');
  const enforce = document.getElementById('pull-limit-enforce');
  if (!monitor || !enforce) return;
  if (!monitor.checked) enforce.checked = false;
  enforce.disabled = !monitor.checked;
  document.getElementById('pull-limit-enforce-control')?.classList.toggle('disabled', enforce.disabled);
  const monitorStatus = document.getElementById('pull-limit-monitor-status');
  const enforceStatus = document.getElementById('pull-limit-enforce-status');
  if (monitorStatus) monitorStatus.textContent = monitor.checked ? '已记录 Token 拉取用量与超限证据' : '已关闭，不统计 Token 用量';
  if (enforceStatus) enforceStatus.textContent = !monitor.checked
    ? '请先开启用量监控'
    : (enforce.checked ? '已启用，超限将返回 429 并临时暂停' : '仅观察，不会自动限速或暂停');
  updateGuardThresholdHint();
}

function handlePullLimitSwitchChange(kind) {
  const monitor = document.getElementById('pull-limit-enabled');
  const enforce = document.getElementById('pull-limit-enforce');
  if (kind === 'enforce' && enforce?.checked && !monitor?.checked) monitor.checked = true;
  syncPullLimitSwitches();
}

async function savePullLimitSettings() {
  const enforce = document.getElementById('pull-limit-enforce').checked;
  const wasEnforced = !!securityData?.pull_limits?.settings?.enforce;
  const conflict = guardThresholdRelationshipError();
  if (conflict) {
    toast(conflict, 'err');
    document.getElementById('guard-token-minute')?.focus();
    return;
  }
  if (enforce && !wasEnforced && !confirm('开启后，超过硬阈值的 Token 将返回 429 并临时暂停拉取。确认启用自动执行？')) {
    document.getElementById('pull-limit-enforce').checked = false;
    syncPullLimitSwitches();
    return;
  }
  const body = {
    guard_pull_limit_enabled: document.getElementById('pull-limit-enabled').checked ? 1 : 0,
    guard_pull_limit_enforce: enforce ? 1 : 0,
    guard_pull_limit_24h_ips: Number(document.getElementById('pull-limit-ips').value),
    guard_pull_limit_per_minute: Number(document.getElementById('pull-limit-minute').value),
    guard_pull_limit_suspend_hours: Number(document.getElementById('pull-limit-hours').value),
  };
  const d = await apiFetch('/api/settings.php', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
    body:JSON.stringify(body),
  });
  if (!d.ok) { toast(d.error || '保存失败', 'err'); return; }
  toast(enforce ? '限制规则已保存，自动暂停已启用' : '限制规则已保存，当前为监控模式');
  await loadSecurity({force:true});
}

async function releasePullLimit(fingerprint) {
  if (!confirm(`解除 ${fingerprint} 的临时暂停？`)) return;
  const d = await apiFetch('/api/security.php', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
    body:JSON.stringify({action:'release_pull_limit', fingerprint}),
  });
  if (!d.ok) { toast(d.error || '解除失败', 'err'); return; }
  toast('临时暂停已解除');
  await loadSecurity({force:true});
}

function renderSecurityMechanisms() {
  const rows = securityData.mechanisms || [];
  document.getElementById('security-mechanisms').innerHTML = rows.length ? rows.map(row => `
    <div class="mechanism-item">
      <span class="mechanism-dot ${esc(row.state || '')}"></span>
      <div><div class="mechanism-title">${esc(row.title || '-')}</div><div class="mechanism-detail">${esc(row.detail || '-')}</div></div>
      ${row.key === 'pull_limit' ? '<button class="mechanism-config" onclick="openProtectionRuleSettings()">配置</button>' : ''}
    </div>`).join('') : '<div class="security-empty">暂无机制状态</div>';
}

function openProtectionRuleSettings() {
  openPanelTab('protection');
  requestAnimationFrame(() => document.getElementById('pull-limit-section')?.scrollIntoView({behavior:'smooth', block:'start'}));
}

function renderSecurityHealth() {
  const h = securityData.health || {};
  const age = value => value === null || value === undefined ? '不存在' : formatDuration(Number(value) || 0);
  const issues = Array.isArray(h.issues) && h.issues.length ? h.issues.join('；') : '未发现异常';
  const rows = [
    ['统计缓存', h.stats_cache_age == null ? '不存在' : `${age(h.stats_cache_age)}更新`],
    ['Token 限制状态', h.token_limit_state_age == null ? '等待首次巡检' : `${age(h.token_limit_state_age)}更新`],
    ['IDC 规则库', h.cloud_rules_age == null ? '不存在' : `${age(h.cloud_rules_age)}更新`],
    ['访问日志', `${formatFileSize(h.log_size || 0)} · ${h.log_writable ? '可读写' : '权限异常'}`],
    ['日志清理', h.retention_days > 0 ? `保留 ${h.retention_days} 天` : '已关闭'],
    ['告警巡检', h.alert_enabled ? (h.last_alert_check || '等待首次检查') : '未开启（可选）'],
    ['诊断结果', issues],
  ];
  document.getElementById('security-health').innerHTML = rows.map(([label, value]) => `
    <div class="health-row"><div class="health-label">${esc(label)}</div><div class="health-value">${esc(value)}</div></div>`).join('');
}

function setGuardFilter(filter) {
  guardReviewFilter = filter;
  guardReviewPage = 1;
  ['active','all','trusted'].forEach(name => document.getElementById('guard-filter-' + name)?.classList.toggle('active', name === filter));
  renderGuardFindings();
}

function setGuardRiskKind(kind) {
  guardRiskKindFilter = ['all','volume','token','source','scanner'].includes(kind) ? kind : 'all';
  guardReviewPage = 1;
  ['all','volume','token','source','scanner'].forEach(name => document.getElementById('guard-kind-' + name)?.classList.toggle('active', name === guardRiskKindFilter));
  renderRiskAnalysis();
  renderGuardFindings();
}

function setGuardPageSize(size) {
  guardReviewPageSize = [5,10,20].includes(Number(size)) ? Number(size) : 5;
  guardReviewPage = 1;
  [5,10,20].forEach(value => document.getElementById('guard-size-' + value)?.classList.toggle('active', value === guardReviewPageSize));
  renderGuardFindings();
}

function changeGuardPage(delta) {
  guardReviewPage += Number(delta) || 0;
  renderGuardFindings();
}

function jumpGuardPage() {
  const input = document.getElementById('guard-page-jump');
  guardReviewPage = Math.max(1, Number(input?.value) || 1);
  renderGuardFindings();
}

function renderGuardPagination(total) {
  const target = document.getElementById('guard-pagination');
  if (!target || total <= 0) {
    if (target) target.style.display = 'none';
    return;
  }
  const totalPages = Math.max(1, Math.ceil(total / guardReviewPageSize));
  guardReviewPage = Math.max(1, Math.min(guardReviewPage, totalPages));
  const start = (guardReviewPage - 1) * guardReviewPageSize + 1;
  const end = Math.min(total, guardReviewPage * guardReviewPageSize);
  target.style.display = 'flex';
  target.innerHTML = `
    <div class="review-page-summary">${start}-${end} / ${total} 条 · 第 ${guardReviewPage}/${totalPages} 页</div>
    <div class="review-page-actions">
      <button class="mode-btn" onclick="changeGuardPage(-1)" ${guardReviewPage <= 1 ? 'disabled' : ''}>上一页</button>
      <input class="review-page-jump" id="guard-page-jump" type="number" min="1" max="${totalPages}" value="${guardReviewPage}" aria-label="跳转页码" onkeydown="if(event.key==='Enter')jumpGuardPage()">
      <button class="mode-btn" onclick="jumpGuardPage()">跳转</button>
      <button class="mode-btn" onclick="changeGuardPage(1)" ${guardReviewPage >= totalPages ? 'disabled' : ''}>下一页</button>
    </div>`;
}

function renderGuardFindings() {
  if (!securityData) return;
  const summary = securityData.review_summary || {};
  document.getElementById('guard-review-summary').textContent = `待复核 ${summary.pending || 0} · 观察 ${summary.watch || 0} · 可信 ${summary.trusted || 0} · 异常 ${summary.confirmed || 0}`;
  let rows = securityData.findings || [];
  if (guardReviewFilter === 'active') rows = rows.filter(row => ['pending','watch'].includes(row.review?.status || 'pending'));
  if (guardReviewFilter === 'trusted') rows = rows.filter(row => row.review?.status === 'trusted');
  if (guardRiskKindFilter !== 'all') rows = rows.filter(row => guardFindingGroup(row) === guardRiskKindFilter);
  const target = document.getElementById('security-findings');
  if (!rows.length) {
    target.innerHTML = `<div class="security-empty">${guardReviewFilter === 'active' ? '当前没有待处理风险' : '当前筛选没有记录'}</div>`;
    renderGuardPagination(0);
    return;
  }
  renderGuardPagination(rows.length);
  const pageStart = (guardReviewPage - 1) * guardReviewPageSize;
  rows = rows.slice(pageStart, pageStart + guardReviewPageSize);
  target.innerHTML = rows.map((row, index) => {
    const review = row.review || {status:'pending',note:''};
    const subject = row.subject || '-';
    const sampleIps = Array.isArray(row.sample_ips) && row.sample_ips.length ? `来源样本：${row.sample_ips.join('、')}` : '';
    const meta = [
      row.automatic_block ? `${row.count || 0} 次自动拦截` : `${row.count || 0} / 阈值 ${row.threshold || 0}`,
      row.window || '',
      row.source || '',
      row.last_seen ? `最后 ${row.last_seen}` : '',
    ].filter(Boolean);
    const canBlockIp = /^(?:\d{1,3}\.){3}\d{1,3}$/.test(subject) && !blacklistIpSet.has(subject);
    const tokenFingerprint = row.token_fingerprint || (/^TKN-[A-F0-9]{16}$/.test(subject) ? subject : '');
    const status = row.status_counts || {};
    const statusSummary = Object.values(status).some(value => Number(value || 0) > 0)
      ? `<span class="risk-status-counts" title="成功 / 403 / 429 / 444"><b style="color:#22c55e">${Number(status['200'] || 0)}</b>/<b style="color:#ef4444">${Number(status['403'] || 0)}</b>/<b style="color:#eab308">${Number(status['429'] || 0)}</b>/<b style="color:#64748b">${Number(status['444'] || 0)}</b></span>`
      : '';
    const intel = [row.location, row.asn, row.operator, row.network_type].filter(value => value && !['未查询','未知地区','未知网络'].includes(value));
    const triggerRows = Object.entries(row.trigger_details || {});
    if (row.provider_asns?.length) triggerRows.push(['厂商 ASN', row.provider_asns.join(' · ')]);
    if (row.provider_keywords?.length) triggerRows.push(['识别关键词', row.provider_keywords.join('、')]);
    if (row.sample_paths?.length) triggerRows.push(['请求路径', row.sample_paths.join('、')]);
    if (row.sample_uas?.length) triggerRows.push(['UA 样本', row.sample_uas.join('、')]);
    const triggerDetail = triggerRows.length ? `<details class="risk-trigger-detail">
      <summary>查看触发详情</summary>
      <div class="risk-trigger-grid">${triggerRows.map(([label,value]) => `<div class="risk-trigger-row"><span class="risk-trigger-label">${esc(label)}</span><span class="risk-trigger-value">${esc(value)}</span></div>`).join('')}</div>
    </details>` : '';
    return `
      <div class="risk-item">
        <div class="risk-head">
          <div><div class="risk-title">${esc(row.title || '风险事件')}</div><div class="risk-subject">${esc(subject)}</div></div>
          <span class="risk-score">${esc(row.risk || '关注')} ${Number(row.score || 0)}</span>
        </div>
        <div class="risk-evidence">${meta.map(item => `<span>${esc(item)}</span>`).join('')}${statusSummary}${row.token_count ? `<span>${Number(row.token_count)} 个 Token</span>` : ''}</div>
        <div class="risk-reason">${esc(row.reason || '-')}${sampleIps ? `<br>${esc(sampleIps)}` : ''}${row.ua ? `<br>UA：${esc(row.ua)}` : ''}</div>
        ${intel.length ? `<div class="risk-intel">${intel.map(item => `<span>${esc(item)}</span>`).join('')}</div>` : ''}
        ${triggerDetail}
        <div class="risk-actions">
          <select class="review-select" id="guard-review-${index}">${guardReviewOptions(review.status)}</select>
          <input class="comment-input" id="guard-note-${index}" value="${esc(review.note || '')}" placeholder="复核备注（可选）" style="min-width:160px;padding:7px 9px;font-size:11px">
          <button class="mode-btn" onclick="saveGuardReview(${jsArg(row.key)},${index})">保存判断</button>
          <button class="mode-btn ai-run-btn" onclick="runAiAnalysis(${jsArg(row.key)})">AI 研判</button>
          ${canBlockIp ? `<button class="mode-btn" onclick="openRiskLogs(${jsArg(subject)})">查看拉取记录</button>` : ''}
          ${tokenFingerprint ? `<button class="mode-btn" onclick="openTokenInvestigation(${jsArg(tokenFingerprint)})">调查 Token</button>` : ''}
          ${row.automatic_block ? `<span class="idc-policy-status">已由厂商策略拦截</span>` : (canBlockIp ? `<button class="mode-btn danger" onclick="quickBlacklist(${jsArg(subject)})">封禁 IP</button>` : '')}
        </div>
      </div>`;
  }).join('');
}

function guardReviewOptions(selected) {
  const options = {pending:'待复核', watch:'持续观察', trusted:'判定可信', confirmed:'确认异常'};
  return Object.entries(options).map(([value,label]) => `<option value="${value}" ${selected === value ? 'selected' : ''}>${label}</option>`).join('');
}

async function saveGuardReview(key, index) {
  const status = document.getElementById('guard-review-' + index)?.value || 'pending';
  const note = document.getElementById('guard-note-' + index)?.value.trim() || '';
  const d = await apiFetch('/api/security.php', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
    body:JSON.stringify({action:'review', key, status, note}),
  });
  if (!d.ok) { toast(d.error || '保存失败', 'err'); return; }
  const row = (securityData.findings || []).find(item => item.key === key);
  if (row) row.review = d.review;
  const summary = {pending:0,watch:0,trusted:0,confirmed:0};
  (securityData.findings || []).forEach(item => summary[item.review?.status || 'pending']++);
  securityData.review_summary = summary;
  renderGuardFindings();
  renderRiskAnalysis();
  renderSecurityMetrics();
  toast('复核状态已保存');
}

async function loadAiModule() {
  const data = await apiFetch('/api/ai.php');
  if (!data.ok) {
    aiModuleData = {settings:{enabled:0,has_api_key:false,providers:{}},analysis:{latest:null,last_error:data.error || ''}};
  } else {
    aiModuleData = data;
  }
  renderAiSettings();
  renderAiAnalysis();
  return aiModuleData;
}

function renderAiSettings() {
  const providerEl = document.getElementById('cfg-ai-provider');
  if (!providerEl || !aiModuleData) return;
  const settings = aiModuleData.settings || {};
  const providers = settings.providers || {};
  providerEl.innerHTML = Object.entries(providers).map(([key,row]) => `<option value="${esc(key)}">${esc(row.name || key)}</option>`).join('');
  providerEl.value = settings.provider || 'openai';
  document.getElementById('cfg-ai-enabled').checked = !!Number(settings.enabled || 0);
  document.getElementById('cfg-ai-auto').checked = !!Number(settings.auto_analyze || 0);
  document.getElementById('cfg-ai-adapter').value = settings.adapter || 'openai_compatible';
  document.getElementById('cfg-ai-base-url').value = settings.base_url || '';
  document.getElementById('cfg-ai-model').value = settings.model || '';
  document.getElementById('cfg-ai-api-key').value = '';
  document.getElementById('cfg-ai-interval').value = Number(settings.auto_interval_minutes || 30);
  document.getElementById('cfg-ai-max-findings').value = Number(settings.max_findings || 10);
  document.getElementById('cfg-ai-include-ip').checked = !!Number(settings.include_ip || 0);
  document.getElementById('cfg-ai-include-ua').checked = !!Number(settings.include_ua || 0);
  document.getElementById('cfg-ai-include-path').checked = !!Number(settings.include_path ?? 1);
  const keyStatus = document.getElementById('cfg-ai-key-status');
  keyStatus.classList.toggle('ready', !!settings.has_api_key);
  keyStatus.textContent = settings.has_api_key ? 'Token 已安全保存' : '未保存 Token';
  document.getElementById('cfg-ai-adapter-wrap').style.display = settings.provider === 'custom' ? 'block' : 'none';
}

function aiProviderChanged() {
  const provider = document.getElementById('cfg-ai-provider').value;
  const preset = aiModuleData?.settings?.providers?.[provider] || {};
  document.getElementById('cfg-ai-adapter-wrap').style.display = provider === 'custom' ? 'block' : 'none';
  if (provider !== 'custom') document.getElementById('cfg-ai-adapter').value = preset.adapter || 'openai_compatible';
  document.getElementById('cfg-ai-base-url').value = preset.base_url || '';
  document.getElementById('cfg-ai-model').value = preset.model || '';
}

function getAiSettingsPayload(action='save') {
  return {
    action,
    enabled: document.getElementById('cfg-ai-enabled').checked ? 1 : 0,
    auto_analyze: document.getElementById('cfg-ai-auto').checked ? 1 : 0,
    provider: document.getElementById('cfg-ai-provider').value || 'openai',
    adapter: document.getElementById('cfg-ai-adapter').value || 'openai_compatible',
    base_url: document.getElementById('cfg-ai-base-url').value.trim(),
    model: document.getElementById('cfg-ai-model').value.trim(),
    api_key: document.getElementById('cfg-ai-api-key').value.trim(),
    auto_interval_minutes: parseInt(document.getElementById('cfg-ai-interval').value || '30', 10),
    max_findings: parseInt(document.getElementById('cfg-ai-max-findings').value || '10', 10),
    include_ip: document.getElementById('cfg-ai-include-ip').checked ? 1 : 0,
    include_ua: document.getElementById('cfg-ai-include-ua').checked ? 1 : 0,
    include_path: document.getElementById('cfg-ai-include-path').checked ? 1 : 0,
  };
}

function validateAiPayload(body) {
  if (!body.base_url || !/^https:\/\//i.test(body.base_url)) { toast('AI API 地址必须使用 HTTPS', 'err'); return false; }
  if (!body.model) { toast('请填写模型名称', 'err'); return false; }
  if (body.auto_interval_minutes < 5 || body.auto_interval_minutes > 1440) { toast('自动分析间隔需在 5-1440 分钟之间', 'err'); return false; }
  if (body.max_findings < 1 || body.max_findings > 30) { toast('每次风险数需在 1-30 之间', 'err'); return false; }
  if (body.enabled && !body.api_key && !aiModuleData?.settings?.has_api_key) { toast('开启前请填写 API Token', 'err'); return false; }
  return true;
}

async function submitAiSettings(action) {
  const body = getAiSettingsPayload(action);
  if (!validateAiPayload(body)) return;
  const data = await apiFetch('/api/ai.php', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
    body:JSON.stringify(body),
  });
  if (!data.ok) { toast(data.error || (action === 'test' ? '连接测试失败' : '保存失败'), 'err'); return; }
  toast(action === 'test' ? 'AI 接口连接正常，点击保存后生效' : 'AI 研判设置已保存');
  if (action === 'test') return;
  await loadAiModule();
  if (securityData) { await loadSecurity({force:true}); renderStats(); }
}

function saveAiSettings() { return submitAiSettings('save'); }
function testAiSettings() { return submitAiSettings('test'); }

async function clearAiToken() {
  if (!confirm('确定清除服务器中保存的 AI Token？AI 研判会同时关闭。')) return;
  const body = getAiSettingsPayload('save');
  body.clear_api_key = 1;
  body.enabled = 0;
  body.auto_analyze = 0;
  body.api_key = '';
  const data = await apiFetch('/api/ai.php', {
    method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'}, body:JSON.stringify(body),
  });
  if (!data.ok) { toast(data.error || '清除失败', 'err'); return; }
  toast('AI Token 已清除');
  await loadAiModule();
}

async function runAiAnalysis(findingKey='') {
  if (aiAnalyzing) return;
  aiAnalyzing = true;
  document.querySelectorAll('.ai-run-btn').forEach(button => { button.disabled = true; button.dataset.oldText = button.textContent; button.textContent = '研判中…'; });
  try {
    const data = await apiFetch('/api/ai.php', {
      method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'}, body:JSON.stringify({action:'analyze', finding_key:findingKey}),
    });
    if (!data.ok) { toast(data.error || 'AI 研判失败', 'err'); return; }
    if (data.skipped) toast('AI 研判刚执行过，请稍后再试');
    await loadAiModule();
    toast(data.skipped ? '已显示最近研判结果' : 'AI 研判完成');
    document.getElementById('ai-analysis-panel')?.scrollIntoView({behavior:'smooth',block:'nearest'});
  } finally {
    aiAnalyzing = false;
    document.querySelectorAll('.ai-run-btn').forEach(button => { button.disabled = false; button.textContent = button.dataset.oldText || 'AI 研判'; });
  }
}

function renderAiAnalysis() {
  const panel = document.getElementById('ai-analysis-panel');
  if (!panel || !aiModuleData) return;
  const state = aiModuleData.analysis || {};
  const latest = state.latest;
  if (!latest) {
    panel.classList.remove('visible');
    panel.innerHTML = '';
    return;
  }
  const decision = latest.decision || {};
  const level = ['low','medium','high','critical'].includes(decision.risk_level) ? decision.risk_level : 'medium';
  const labels = {low:'低风险',medium:'中风险',high:'高风险',critical:'极高风险'};
  const list = (title, items) => `<div class="ai-analysis-block"><h4>${esc(title)}</h4>${Array.isArray(items) && items.length ? `<ul>${items.map(item => `<li>${esc(item)}</li>`).join('')}</ul>` : '<div style="color:var(--text3);font-size:11px">暂无</div>'}</div>`;
  panel.classList.add('visible');
  panel.innerHTML = `<div class="ai-analysis-head"><div><div class="ai-analysis-kicker">AI 辅助研判 · 仅供复核</div><div class="ai-analysis-title">${esc(decision.verdict || '需要人工复核')}</div></div><div><span class="ai-risk-badge ${level}">${esc(labels[level])} · 置信度 ${Number(decision.confidence || 0)}%</span><div class="ai-analysis-meta">${esc(latest.provider_name || latest.provider || '')} · ${esc(latest.model || '')}<br>${esc(latest.generated_at || '')} · ${Number(latest.finding_count || 0)} 条证据</div></div></div><div class="ai-analysis-summary">${esc(decision.summary || '未提供摘要')}</div><div class="ai-analysis-grid">${list('关键证据',decision.evidence)}${list('可能误报',decision.false_positive_factors)}${list('复核建议',decision.recommendations)}${list('拟议动作',decision.proposed_actions)}</div><div class="ai-advisory">AI 没有执行任何封禁、限速或配置变更。最终判断和处置必须由管理员确认。</div>`;
}

function openRiskLogs(ip='') {
  document.getElementById('filter-ip').value = ip;
  document.getElementById('filter-token').value = '';
  logPage = 1;
  openPanelTab('logs');
  loadTab('logs', {force:true}).catch(() => {});
}

function renderGuardRules() {
  const r = securityData.rules || {};
  document.getElementById('guard-observe-enabled').checked = !!Number(r.guard_observe_enabled ?? 1);
  document.getElementById('guard-ip-minute').value = r.guard_ip_per_minute ?? 30;
  document.getElementById('guard-ip-daily').value = r.guard_ip_daily_requests ?? 100;
  document.getElementById('guard-token-minute').value = r.guard_token_per_minute ?? 20;
  document.getElementById('guard-token-hour-ips').value = r.guard_token_hour_ips ?? 8;
  document.getElementById('guard-ip-hour-tokens').value = r.guard_ip_hour_tokens ?? 20;
  document.getElementById('guard-ip-404').value = r.guard_ip_404_5m ?? 40;
  document.getElementById('guard-scan-lines').value = r.guard_scan_lines ?? 30000;
  updateGuardThresholdHint();
}

function applyGuardPreset(name) {
  const presets = {
    strict:[20,80,15,5,12,25,30000],
    balanced:[30,100,20,8,20,40,30000],
    quiet:[60,200,45,15,40,80,50000],
  };
  const values = presets[name] || presets.balanced;
  if (document.getElementById('pull-limit-enabled')?.checked && document.getElementById('pull-limit-enforce')?.checked) {
    const limit = Number(document.getElementById('pull-limit-minute')?.value || 10);
    const warningRatio = {strict:.6, balanced:.75, quiet:.9}[name] || .75;
    values[2] = Math.min(values[2], Math.max(2, Math.min(limit - 1, Math.floor(limit * warningRatio))));
  }
  ['guard-ip-minute','guard-ip-daily','guard-token-minute','guard-token-hour-ips','guard-ip-hour-tokens','guard-ip-404','guard-scan-lines']
    .forEach((id, index) => document.getElementById(id).value = values[index]);
  updateGuardThresholdHint();
}

async function saveGuardSettings() {
  const conflict = guardThresholdRelationshipError();
  if (conflict) {
    toast(conflict, 'err');
    document.getElementById('guard-token-minute')?.focus();
    return;
  }
  const body = {
    guard_observe_enabled: document.getElementById('guard-observe-enabled').checked ? 1 : 0,
    guard_ip_per_minute: Number(document.getElementById('guard-ip-minute').value),
    guard_ip_daily_requests: Number(document.getElementById('guard-ip-daily').value),
    guard_token_per_minute: Number(document.getElementById('guard-token-minute').value),
    guard_token_hour_ips: Number(document.getElementById('guard-token-hour-ips').value),
    guard_ip_hour_tokens: Number(document.getElementById('guard-ip-hour-tokens').value),
    guard_ip_404_5m: Number(document.getElementById('guard-ip-404').value),
    guard_scan_lines: Number(document.getElementById('guard-scan-lines').value),
  };
  const d = await apiFetch('/api/settings.php', {
    method:'POST',
    headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},
    body:JSON.stringify(body),
  });
  if (!d.ok) { toast(d.error || '保存失败', 'err'); return; }
  toast('风险预警规则已保存');
  await loadSecurity({force:true});
}

function renderSecurityActions() {
  const rows = securityData.recent_actions || [];
  document.getElementById('security-actions').innerHTML = rows.length ? rows.map(row => `
    <div class="action-row">
      <div class="action-time">${esc(row.time || '-')}</div>
      <div class="action-main"><strong>${esc(row.type || '-')}</strong>${row.subject ? ` · ${esc(row.subject)}` : ''}<br><span style="color:var(--text3)">${esc(row.detail || '-')}</span></div>
    </div>`).join('') : '<div class="security-empty">暂无处理记录</div>';
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
      headers: {'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': CSRF_TOKEN},
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
  mountWorkspaceLayout();
  resetCountdown();
  await loadTab('security', {force:true}).catch(() => {});
  scheduleBackgroundPreload();
}
initDashboard();
</script>
</body>
</html>
