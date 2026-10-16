<?php
/**
 * Green AVIC Content Studio — System Logging & Audit API
 * 
 * Provides authenticated inspection, summary, and download of redacted system logs.
 * Endpoints:
 *   GET /api/admin/logs (or api/logs.php?action=logs)
 *   GET /api/admin/logs/summary (or api/logs.php?action=summary)
 *   GET /api/admin/logs/download (or api/logs.php?action=download)
 */

require_once dirname(__DIR__, 2) . '/cms-core/common.php';

$pdo = get_db();
ensure_tables_exist($pdo);

// Handle client-side error and diagnostic logging via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $action = $input['action'] ?? '';
    if ($action === 'client_log') {
        $level = in_array(strtolower($input['level'] ?? ''), ['error', 'warning', 'info'], true) ? strtolower($input['level']) : 'error';
        $message = substr(trim($input['message'] ?? 'Client Script Error'), 0, 500);
        $context = is_array($input['context'] ?? null) ? $input['context'] : ['detail' => substr(strval($input['context'] ?? ''), 0, 1000)];
        AppLogger::log($level, 'client', $message, $context);
        json_response(['success' => true]);
    }
    json_response(['error' => 'Invalid action.'], 400);
}

// Require authenticated administrator for inspecting or downloading logs
$user = require_auth($pdo, true);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed.'], 405);
}

$action = $_GET['action'] ?? 'logs';

// ----------------------------------------------------------
// 1. Download Redacted Log File
// ----------------------------------------------------------
if ($action === 'download') {
    $filename = 'greenavic-system-logs-' . date('Y-m-d-His') . '.txt';
    
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "=====================================================================\n";
    echo " GREEN AVIC NEWSLETTER & CONTENT STUDIO — SYSTEM AUDIT LOG\n";
    echo " Exported by: " . AppLogger::maskEmail($user['email']) . "\n";
    echo " Exported at: " . date('Y-m-d H:i:s T') . "\n";
    echo " Security: Sensitive credentials, passwords, tokens & emails redacted\n";
    echo "=====================================================================\n\n";

    try {
        $stmt = $pdo->query("SELECT level, channel, message, context, ip_address, created_at FROM system_logs ORDER BY id DESC LIMIT 1000");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($rows)) {
            foreach ($rows as $r) {
                $ctx = !empty($r['context']) ? ' ' . $r['context'] : '';
                printf("[%s] [%s.%s] [ip:%s] %s%s\n",
                    $r['created_at'],
                    strtoupper($r['channel']),
                    strtoupper($r['level']),
                    $r['ip_address'] ?? '-',
                    $r['message'],
                    $ctx
                );
            }
        } else {
            // Read fallback file
            $logFile = AppLogger::getLogDir() . '/app.log';
            if (file_exists($logFile)) {
                readfile($logFile);
            } else {
                echo "No system logs recorded yet.\n";
            }
        }
    } catch (Exception $e) {
        echo "Error retrieving database logs: " . $e->getMessage() . "\n";
    }
    exit;
}

// ----------------------------------------------------------
// 2. Log Summary Metrics (Past 24h & Top Errors)
// ----------------------------------------------------------
if ($action === 'summary') {
    try {
        $nowMinus24 = date('Y-m-d H:i:s', time() - 86400);

        // Counts by level last 24h
        $stmt = $pdo->prepare("
            SELECT level, COUNT(*) as count 
            FROM system_logs 
            WHERE created_at >= ? 
            GROUP BY level
        ");
        $stmt->execute([$nowMinus24]);
        $counts = [
            'error'   => 0,
            'warning' => 0,
            'info'    => 0,
            'debug'   => 0
        ];
        while ($row = $stmt->fetch()) {
            $lvl = strtolower($row['level']);
            if (isset($counts[$lvl])) {
                $counts[$lvl] = (int)$row['count'];
            }
        }

        // Top 5 error messages in the past 7 days
        $nowMinus7d = date('Y-m-d H:i:s', time() - 604800);
        $topErrStmt = $pdo->prepare("
            SELECT channel, message, COUNT(*) as occurrences, MAX(created_at) as last_seen
            FROM system_logs
            WHERE level = 'error' AND created_at >= ?
            GROUP BY channel, message
            ORDER BY occurrences DESC
            LIMIT 5
        ");
        $topErrStmt->execute([$nowMinus7d]);
        $topErrors = $topErrStmt->fetchAll(PDO::FETCH_ASSOC);

        // Last error timestamp
        $lastErrStmt = $pdo->query("SELECT created_at, message FROM system_logs WHERE level = 'error' ORDER BY id DESC LIMIT 1");
        $lastError = $lastErrStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        json_response([
            'success'     => true,
            'last_24h'    => $counts,
            'top_errors'  => $topErrors,
            'last_error'  => $lastError,
            'generated_at'=> date('Y-m-d H:i:s')
        ]);
    } catch (Exception $e) {
        AppLogger::error('logs', 'Log summary failed: ' . $e->getMessage());
        json_response([
            'success' => false,
            'error'   => 'Could not generate log summary: ' . $e->getMessage()
        ], 500);
    }
}

// ----------------------------------------------------------
// 3. Paginated & Filterable Log Entries
// ----------------------------------------------------------
if ($action === 'logs') {
    try {
        $level  = strtolower(trim($_GET['level'] ?? 'all'));
        $search = trim($_GET['search'] ?? '');
        $limit  = min(100, max(10, (int)($_GET['limit'] ?? 50)));
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $offset = ($page - 1) * $limit;

        $where = [];
        $params = [];

        if ($level !== 'all' && in_array($level, ['error', 'warning', 'info', 'debug'], true)) {
            $where[] = "level = ?";
            $params[] = $level;
        }

        if (!empty($search)) {
            $where[] = "(message LIKE ? OR channel LIKE ?)";
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Total count
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM system_logs $whereClause");
        $countStmt->execute($params);
        $totalLogs = (int)$countStmt->fetchColumn();

        // Paginated rows
        $sql = "SELECT id, level, channel, message, context, ip_address, user_agent, created_at 
                FROM system_logs 
                $whereClause 
                ORDER BY id DESC 
                LIMIT $limit OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $parsedLogs = [];
        foreach ($rows as $r) {
            $parsedContext = null;
            if (!empty($r['context'])) {
                $parsedContext = json_decode($r['context'], true) ?: $r['context'];
            }
            $parsedLogs[] = [
                'id'         => (int)$r['id'],
                'level'      => $r['level'],
                'channel'    => $r['channel'],
                'message'    => $r['message'],
                'context'    => $parsedContext,
                'ip_address' => $r['ip_address'],
                'created_at' => $r['created_at']
            ];
        }

        json_response([
            'success'    => true,
            'total'      => $totalLogs,
            'page'       => $page,
            'limit'      => $limit,
            'total_pages'=> ceil($totalLogs / $limit),
            'logs'       => $parsedLogs
        ]);
    } catch (Exception $e) {
        AppLogger::error('logs', 'Log listing failed: ' . $e->getMessage());
        json_response([
            'success' => false,
            'error'   => 'Could not fetch system logs: ' . $e->getMessage(),
            'logs'    => []
        ], 500);
    }
}

json_response(['error' => 'Invalid action.'], 400);
