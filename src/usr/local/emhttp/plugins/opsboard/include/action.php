<?php
// Opsboard actions (POST from the page; Unraid checks the csrf_token before this runs).
// Only jobs and modes listed in the settings can be started. A GO or Roll back needs a finished dry
// run of the same job within go_window_minutes, checked here on the server, not just in the browser.
require_once __DIR__ . '/lib.php';
header('Content-Type: application/json');
function out($ok, $msg, $extra = []) { echo json_encode(['ok' => $ok, 'msg' => $msg] + $extra); exit; }

$a = $_POST['action'] ?? '';
$job = (string)($_POST['job'] ?? '');
$mode = (string)($_POST['mode'] ?? '');

switch ($a) {
    case 'check': {   // run the self-check
        if (opb_running('_check', 'dry')) out(true, 'already running');
        $cmd = opb_check_command();
        if ($cmd === null) out(false, 'the self-check in the settings was not found on this server');
        opb_start('_check', 'dry', $cmd);
        out(true, 'self-check started');
    }
    case 'check_status': {
        out(true, '', ['running' => opb_running('_check', 'dry'), 'log' => opb_tail(opb_paths('_check', 'dry')['log'], 80)]);
    }
    case 'run': {
        if (!isset(opb_jobs()[$job]) || !in_array($mode, OB_MODES, true)) out(false, 'unknown job');
        if ($m = opb_any_running($job)) out(false, "already running ($m)");
        $cmd = opb_command($job, $mode);
        if ($cmd === null) out(false, 'not available on this server');
        if ($mode !== 'dry') {
            $age = opb_dry_age($job);
            if ($age === null || $age > opb_window()) out(false, 'run the dry run / check first (needed shortly before GO)');
            @unlink(opb_paths($job, 'dry')['done']);   // each GO uses up its dry run
        }
        opb_start($job, $mode, $cmd);
        out(true, "$mode started");
    }
    case 'status': {
        if (!isset(opb_jobs()[$job])) out(false, 'unknown job');
        $r = [];
        foreach (OB_MODES as $m) {
            $p = opb_paths($job, $m);
            $r[$m] = ['running' => opb_running($job, $m), 'finished' => is_file($p['done']) ? (int)file_get_contents($p['done']) : null, 'log' => opb_tail($p['log'])];
        }
        $age = opb_dry_age($job);
        out(true, '', ['modes' => $r, 'go_ready' => $age !== null && $age <= opb_window() && !opb_any_running($job)]);
    }
}
out(false, 'unknown action');
