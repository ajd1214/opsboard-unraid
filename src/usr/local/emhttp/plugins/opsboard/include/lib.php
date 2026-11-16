<?php
// Opsboard shared helpers: settings, the list of jobs the page may start, job state.
//
// Everything specific to a server lives in one settings file on the flash drive (OB_CFG), written by
// root (for example by your own scripts' installer). The page can only start what that file lists:
// nothing from a request is ever put into a command line, only a job key and a mode, both checked
// against the settings.

const OB_CFG = '/boot/config/plugins/opsboard/opsboard.json';
const OB_US  = '/boot/config/plugins/user.scripts/scripts';
const OB_RUN = '/tmp/opsboard';                     // job logs, pid files, finished markers (RAM)
const OB_MODES = ['dry', 'go', 'rollback'];

function opb_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

// The settings, or null when the file is missing or not valid JSON (the page then says so).
function opb_cfg() {
    static $c = false;
    if ($c === false) {
        $c = @json_decode((string)@file_get_contents(OB_CFG), true);
        if (!is_array($c)) $c = null;
    }
    return $c;
}
function opb_title()  { return (string)(opb_cfg()['title'] ?? 'Opsboard'); }
function opb_window() { return 60 * max(1, (int)(opb_cfg()['go_window_minutes'] ?? 30)); }   // GO needs a dry run this recent

// Jobs keyed by their key. Each: label, desc, and per mode a spec: "us:<User Scripts folder>" or
// "cmd:<fixed command>". A mode may name a file in "<mode>_if_file": the mode only exists when it does.
function opb_jobs() {
    $out = [];
    foreach ((opb_cfg()['jobs'] ?? []) as $j) {
        $k = (string)($j['key'] ?? '');
        if (!preg_match('/^[a-z0-9_]{1,40}$/', $k)) continue;
        $out[$k] = $j;
    }
    return $out;
}

// The command for a job's mode, or null if that mode doesn't exist on this server.
function opb_command($job, $mode) {
    $j = opb_jobs()[$job] ?? null;
    if (!$j || !in_array($mode, OB_MODES, true)) return null;
    $spec = (string)($j[$mode] ?? '');
    if ($spec === '') return null;
    $need = (string)($j[$mode . '_if_file'] ?? '');
    if ($need !== '' && !is_file($need)) return null;
    if (strncmp($spec, 'us:', 3) === 0) {
        $folder = substr($spec, 3);
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $folder)) return null;
        $f = OB_US . '/' . $folder . '/script';
        return is_file($f) ? 'bash ' . escapeshellarg($f) : null;
    }
    if (strncmp($spec, 'cmd:', 4) === 0) return substr($spec, 4);   // fixed text from the settings, never from the request
    return null;
}

function opb_paths($job, $mode) {
    @mkdir(OB_RUN, 0755, true);
    $b = OB_RUN . "/$job.$mode";
    return ['log' => "$b.log", 'pid' => "$b.pid", 'done' => "$b.done"];
}
function opb_running($job, $mode) {
    $pid = (int)@file_get_contents(opb_paths($job, $mode)['pid']);
    return $pid > 0 && is_dir("/proc/$pid");
}
function opb_any_running($job) {
    foreach (OB_MODES as $m) if (opb_running($job, $m)) return $m;
    return null;
}
// Seconds since this job's dry run finished, or null if it never finished.
function opb_dry_age($job) {
    $p = opb_paths($job, 'dry');
    return is_file($p['done']) ? time() - (int)file_get_contents($p['done']) : null;
}

// Start a job detached. Output goes to its log; a finished marker is written at the end.
function opb_start($job, $mode, $cmd) {
    $p = opb_paths($job, $mode);
    @unlink($p['done']);
    $sh = 'cd /tmp; echo "== started $(date \'+%F %T\')"; ' . $cmd . '; rc=$?; echo; echo "== finished $(date \'+%F %T\'), exit $rc"; date +%s > ' . escapeshellarg($p['done']);
    $full = 'nohup setsid bash -c ' . escapeshellarg($sh) . ' > ' . escapeshellarg($p['log']) . ' 2>&1 < /dev/null & echo $!';
    $pid = (int)trim((string)shell_exec($full));
    file_put_contents($p['pid'], (string)$pid);
    return $pid;
}

function opb_tail($file, $lines = 300) {
    if (!is_file($file)) return '';
    $a = @file($file, FILE_IGNORE_NEW_LINES) ?: [];
    return implode("\n", array_slice($a, -$lines));
}

// ---- the self-check (optional): a job that writes a JSON result the page and Dashboard tile show.
// Result file: {"result": "...", "at": "<ISO time>", "checks": [{"item": 1, "name": "...", "status": "PASS|WARN|FAIL|MANUAL", "detail": "..."}]}
function opb_check()      { $c = opb_cfg()['check'] ?? null; return is_array($c) && !empty($c['run']) ? $c : null; }
function opb_last() {
    $c = opb_check(); if (!$c || empty($c['result_file'])) return null;
    $j = @json_decode((string)@file_get_contents($c['result_file']), true);
    return is_array($j) ? $j : null;
}
function opb_level($last) {   // fail if any FAIL, warn if any WARN, else ok; none if no result
    if (!$last) return 'none';
    $st = array_column($last['checks'] ?? [], 'status');
    if (in_array('FAIL', $st, true)) return 'fail';
    if (in_array('WARN', $st, true)) return 'warn';
    return 'ok';
}
function opb_check_command() {
    $c = opb_check(); if (!$c) return null;
    $spec = (string)$c['run'];
    if (strncmp($spec, 'us:', 3) === 0) {
        $folder = substr($spec, 3);
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $folder)) return null;
        $f = OB_US . '/' . $folder . '/script';
        return is_file($f) ? 'bash ' . escapeshellarg($f) : null;
    }
    return strncmp($spec, 'cmd:', 4) === 0 ? substr($spec, 4) : null;
}
function opb_ago($iso) {
    $t = strtotime((string)$iso); if (!$t) return 'never';
    $s = time() - $t;
    if ($s < 120) return 'just now';
    if ($s < 7200) return intdiv($s, 60) . ' min ago';
    if ($s < 172800) return intdiv($s, 3600) . ' h ago';
    return intdiv($s, 86400) . ' days ago';
}
