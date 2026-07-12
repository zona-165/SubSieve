<?php
require_once __DIR__ . '/_auth.php';

$today  = date('d/M/Y');
$ips    = [];   // ip => [total,200,403,429,444]  (today only)
$tokens = [];   // token => [count, last_time]     (today only)
$badUas = [];   // ua => count (403 only, today)

// 全量日志用于可疑分析
$suspTokenIps = [];  // token => {ip => true}
$suspIpTokens = [];  // ip    => {token => true}
$suspIpDetail = [];  // ip    => detailed evidence for manual review
$DETAIL_TOKEN_LIMIT = 20;
$DETAIL_PATH_LIMIT = 20;
$DETAIL_UA_LIMIT = 10;
$DETAIL_SECOND_LIMIT = 120;

// 读取Token黑名单（用于从统计中排除）
$tokenBlacklist = [];
if (file_exists(TOKEN_BLACKLIST_JSON)) {
    $tbData = json_decode(file_get_contents(TOKEN_BLACKLIST_JSON), true);
    if (is_array($tbData)) {
        foreach ($tbData as $e) {
            if (!empty($e['token'])) $tokenBlacklist[$e['token']] = true;
        }
    }
}

// 读取白名单（用于排除）
$whitelistIps = [];
if (file_exists(WHITELIST_IPS)) {
    foreach (file(WHITELIST_IPS, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $wl) {
        $wl = trim($wl);
        if ($wl === '' || str_starts_with($wl, '#')) continue;
        $ip = strtok($wl, " \t#");
        if ($ip) $whitelistIps[$ip] = true;
    }
}

if (file_exists(LOG_FILE)) {
    $handle = fopen(LOG_FILE, 'r');
    if ($handle) {
        while (($line = fgets($handle)) !== false) {
            $line = rtrim($line);
            if ($line === '') continue;

            $pat = '/^(\S+) \[([^\]]+)\] "([^"]*)" (\d+) (\S+) "([^"]*)"$/';
            if (!preg_match($pat, $line, $m)) continue;

            [, $ip, $time, $request, $status, , $ua] = $m;
            $status = (int)$status;

            // ── 今日统计 ──────────────────────────────────────────
            if (str_contains($line, "[$today:")) {
                if (!isset($ips[$ip])) $ips[$ip] = ['total'=>0,'s200'=>0,'s403'=>0,'s429'=>0,'s444'=>0];
                $ips[$ip]['total']++;
                if ($status === 200) $ips[$ip]['s200']++;
                elseif ($status === 403) $ips[$ip]['s403']++;
                elseif ($status === 429) $ips[$ip]['s429']++;
                elseif ($status === 444) $ips[$ip]['s444']++;

                if (preg_match('/[?&]token=([^&\s]+)/i', $request, $tm)) {
                    $tok = $tm[1];
                    if (!isset($tokenBlacklist[$tok])) {
                        if (!isset($tokens[$tok])) $tokens[$tok] = ['count'=>0,'last_time'=>''];
                        $tokens[$tok]['count']++;
                        $tokens[$tok]['last_time'] = trim(preg_replace('/^\d+\/\w+\/\d+:/', '', preg_replace('/ \+\d+$/', '', $time)));
                    }
                }

                if ($status === 403 && $ua !== '') {
                    if (!isset($badUas[$ua])) $badUas[$ua] = 0;
                    $badUas[$ua]++;
                }
            }

            // ── 全量可疑分析（200 状态的订阅请求，排除白名单IP和Token黑名单）──
            if ($status === 200
                && !isset($whitelistIps[$ip])
                && preg_match('/[?&]token=([^&\s]+)/i', $request, $tm)
            ) {
                $tok = $tm[1];
                if (!isset($tokenBlacklist[$tok])) {
                    $suspTokenIps[$tok][$ip] = true;
                    $suspIpTokens[$ip][$tok]  = true;
                    if (!isset($suspIpDetail[$ip])) {
                        $suspIpDetail[$ip] = [
                            'requests' => 0,
                            'tokens' => [],
                            'paths' => [],
                            'uas' => [],
                            'seconds' => [],
                            'last_time' => '',
                        ];
                    }
                    $path = extract_request_path($request);
                    $second = preg_replace('/ \+\d+$/', '', $time);

                    $suspIpDetail[$ip]['requests']++;
                    if (isset($suspIpDetail[$ip]['tokens'][$tok]) || count($suspIpDetail[$ip]['tokens']) < $DETAIL_TOKEN_LIMIT) {
                        $suspIpDetail[$ip]['tokens'][$tok] = true;
                    }
                    if (isset($suspIpDetail[$ip]['paths'][$path]) || count($suspIpDetail[$ip]['paths']) < $DETAIL_PATH_LIMIT) {
                        $suspIpDetail[$ip]['paths'][$path] = true;
                    }
                    if ($ua !== '' && (isset($suspIpDetail[$ip]['uas'][$ua]) || count($suspIpDetail[$ip]['uas']) < $DETAIL_UA_LIMIT)) {
                        $suspIpDetail[$ip]['uas'][$ua] = true;
                    }
                    if (isset($suspIpDetail[$ip]['seconds'][$second]) || count($suspIpDetail[$ip]['seconds']) < $DETAIL_SECOND_LIMIT) {
                        if (!isset($suspIpDetail[$ip]['seconds'][$second])) $suspIpDetail[$ip]['seconds'][$second] = 0;
                        $suspIpDetail[$ip]['seconds'][$second]++;
                    }
                    $suspIpDetail[$ip]['last_time'] = trim(preg_replace('/^\d+\/\w+\/\d+:/', '', preg_replace('/ \+\d+$/', '', $time)));
                }
            }
        }
        fclose($handle);
    }
}

// Top IP（今日，最多返回500条，前端负责显示限制）
uasort($ips, fn($a,$b) => $b['total'] - $a['total']);
$topIps = [];
foreach (array_slice($ips, 0, 500, true) as $ip => $v) {
    $topIps[] = array_merge(['ip' => $ip], $v);
}

// Top Token（今日，最多返回500条，前端负责显示限制）
uasort($tokens, fn($a,$b) => $b['count'] - $a['count']);
$topTokens = [];
foreach (array_slice($tokens, 0, 500, true) as $tok => $v) {
    $topTokens[] = [
        'token'      => substr($tok, 0, 8) . '…',
        'token_full' => $tok,
        'count'      => $v['count'],
        'last_time'  => $v['last_time'],
    ];
}

// UA TOP（最多返回500条，前端负责显示限制）
arsort($badUas);
$badUaList = [];
foreach (array_slice($badUas, 0, 500, true) as $ua => $cnt) {
    $badUaList[] = ['ua' => $ua, 'count' => $cnt];
}

// 可疑 Token（日志周期内被 3+ 个不同IP拉取）
$SUSP_TOKEN_THRESHOLD = 3;
$suspTokenList = [];
foreach ($suspTokenIps as $tok => $ipSet) {
    $cnt = count($ipSet);
    if ($cnt >= $SUSP_TOKEN_THRESHOLD) {
        $suspTokenList[] = ['token' => $tok, 'ip_count' => $cnt, 'ips' => array_keys($ipSet)];
    }
}
usort($suspTokenList, fn($a,$b) => $b['ip_count'] - $a['ip_count']);

// 可疑 IP（日志周期内拉取了 3+ 个不同Token）
$SUSP_IP_THRESHOLD = 3;
$suspIpList = [];
foreach ($suspIpTokens as $ip => $tokSet) {
    $cnt = count($tokSet);
    if ($cnt >= $SUSP_IP_THRESHOLD) {
        $detail = $suspIpDetail[$ip] ?? [
            'requests' => 0,
            'tokens' => [],
            'paths' => [],
            'uas' => [],
            'seconds' => [],
            'last_time' => '',
        ];
        $maxPerSecond = $detail['seconds'] ? max($detail['seconds']) : 0;
        $score = min(100, 45 + ($cnt * 10) + max(0, $maxPerSecond - 2) * 8 + max(0, $detail['requests'] - 3) * 4);
        $risk = $score >= 90 ? '极高危' : ($score >= 75 ? '高危' : '可疑');
        $reasons = [
            "触发 IP {$ip} 在日志周期内拉取了 {$cnt} 个不同 Token，超过阈值 {$SUSP_IP_THRESHOLD}。",
            "1 秒内最高命中 {$maxPerSecond} 次订阅请求。",
            "日志周期内订阅成功请求共 {$detail['requests']} 次。",
        ];
        if ($detail['paths']) {
            $reasons[] = '命中路径：' . implode('、', array_slice(array_keys($detail['paths']), 0, 5)) . (count($detail['paths']) > 5 ? ' 等' : '') . '。';
        }
        if ($detail['uas']) {
            $reasons[] = '请求 UA：' . implode(' | ', array_slice(array_keys($detail['uas']), 0, 3)) . (count($detail['uas']) > 3 ? ' 等' : '') . '。';
        }
        $suspIpList[] = [
            'ip' => $ip,
            'token_count' => $cnt,
            'request_count' => $detail['requests'],
            'max_per_second' => $maxPerSecond,
            'score' => $score,
            'risk' => $risk,
            'paths' => array_slice(array_keys($detail['paths']), 0, 8),
            'uas' => array_slice(array_keys($detail['uas']), 0, 5),
            'tokens' => array_slice(array_keys($detail['tokens']), 0, 8),
            'last_time' => $detail['last_time'],
            'reasons' => $reasons,
        ];
    }
}
usort($suspIpList, fn($a,$b) => ($b['score'] <=> $a['score']) ?: ($b['token_count'] <=> $a['token_count']));
$suspIpList = array_slice($suspIpList, 0, 500);

json_out([
    'ok'          => true,
    'top_ips'     => $topIps,
    'top_tokens'  => $topTokens,
    'bad_uas'     => $badUaList,
    'susp_tokens' => $suspTokenList,
    'susp_ips'    => $suspIpList,
]);

function extract_request_path(string $request): string {
    $parts = explode(' ', trim($request));
    $target = $parts[1] ?? $request;
    $path = parse_url($target, PHP_URL_PATH);
    return $path ?: $target;
}
