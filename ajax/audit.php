<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_admin();

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    case 'list': {
        $from = $_GET['from'] ?? date('Y-m-01');
        $to = $_GET['to'] ?? date('Y-m-d');
        $aAction = $_GET['action_filter'] ?? '';
        $q = trim($_GET['q'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $pageSize = min(500, max(1, (int)($_GET['page_size'] ?? 50)));
        $isSuper = is_superadmin();

        $filter = "DATE(al.created_at) BETWEEN ? AND ?";
        $params = [$from, $to];

        // Admins (non-superadmin) cannot see superadmin actions. The role is
        // read from the snapshot stored on the log row (audit_logs.user_role),
        // so it stays correct even after the user is deleted — the old
        // join-based check turned NULL for deleted superadmins and leaked
        // their actions to admins.
        if (!$isSuper) {
            $filter .= " AND (al.user_role IS NULL OR al.user_role <> 'superadmin')";
        }
        if ($aAction !== '') { $filter .= " AND al.action = ?"; $params[] = $aAction; }
        if ($q !== '') {
            $filter .= " AND (al.user_name LIKE ? OR u.full_name LIKE ? OR al.detail LIKE ?)";
            $like = "%{$q}%";
            array_push($params, $like, $like, $like);
        }

        $total = (int)db_value("
            SELECT COUNT(*) FROM audit_logs al
            LEFT JOIN users u ON u.id = al.user_id
            WHERE $filter
        ", $params);

        $offset = ($page - 1) * $pageSize;
        $rows = db_all("
            SELECT al.id, al.action, al.detail, al.created_at,
                   COALESCE(al.user_name, u.username, 'System') AS username,
                   COALESCE(u.full_name, al.user_name, 'System') AS full_name,
                   COALESCE(al.user_role, u.role, 'system') AS role
            FROM audit_logs al
            LEFT JOIN users u ON u.id = al.user_id
            WHERE $filter
            ORDER BY al.id DESC
            LIMIT {$pageSize} OFFSET {$offset}
        ", $params);

        $stats = db_row("
            SELECT COUNT(*) AS total,
                   COALESCE(SUM(al.action IN ('login','logout')), 0) AS logins,
                   COALESCE(SUM(al.action = 'sale_void'), 0) AS voids,
                   COALESCE(SUM(al.action NOT IN ('login','logout','sale_void')), 0) AS changes
            FROM audit_logs al
            LEFT JOIN users u ON u.id = al.user_id
            WHERE DATE(al.created_at) BETWEEN ? AND ?
            " . ($isSuper ? '' : " AND (al.user_role IS NULL OR al.user_role <> 'superadmin')") . "
        ", [$from, $to]);

        json_response(200, [
            'ok' => true,
            'logs' => $rows,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'range_stats' => [
                'total' => (int)$stats['total'],
                'logins' => (int)$stats['logins'],
                'voids' => (int)$stats['voids'],
                'changes' => (int)$stats['changes'],
            ],
        ]);
    }
    break;

    case 'actions': {
        $rows = db_all("SELECT DISTINCT action FROM audit_logs ORDER BY action");
        json_response(200, ['ok' => true, 'actions' => array_map(static fn($r) => $r['action'], $rows)]);
    }
    break;

    default:
        json_response(400, ['ok' => false, 'message' => 'Unknown action.']);
}
