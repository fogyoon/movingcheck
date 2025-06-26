<?php
require_once 'config.inc';
require_once 'sql.inc';

// 관리자 권한 확인 - 로그인되지 않았거나 admin이 아닌 경우 index.php로 리다이렉트
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

$activities = [];
$filter_user_id = $_GET['user_id'] ?? null;
$filter_ip = $_GET['ip'] ?? null;

if ($filter_user_id) {
    $activities = get_user_activities($filter_user_id, 100);
} elseif ($filter_ip) {
    $activities = get_activities_by_ip($filter_ip, 100);
} else {
    $activities = get_user_activities(null, 100);
}

// 사용자 목록 조회 (필터용)
$pdo = get_pdo();
$users = [];
if (!is_string($pdo)) {
    try {
        $user_sql = "SELECT id, nickname, email, role FROM users ORDER BY nickname";
        $user_stmt = $pdo->query($user_sql);
        $users = $user_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // 에러 무시
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>사용자 활동 기록 - <?php echo SITE_TITLE; ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .activity-container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        .activity-filters {
            background: #fff;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .filter-form {
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }
        .filter-select, .filter-input {
            padding: 0.5rem;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.9rem;
        }
        .filter-btn {
            background: #0064FF;
            color: #fff;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            cursor: pointer;
        }
        .activity-table {
            background: #fff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .activity-table table {
            width: 100%;
            border-collapse: collapse;
        }
        .activity-table th, .activity-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        .activity-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }
        .activity-type {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        .activity-type.login { background: #d4edda; color: #155724; }
        .activity-type.logout { background: #f8d7da; color: #721c24; }
        .activity-type.create_property { background: #d1ecf1; color: #0c5460; }
        .activity-type.create_contract { background: #fff3cd; color: #856404; }
        .activity-type.other { background: #e2e3e5; color: #383d41; }
        .ip-address {
            font-family: monospace;
            font-size: 0.9rem;
            color: #666;
        }
        .timestamp {
            font-size: 0.9rem;
            color: #888;
        }
        .no-data {
            text-align: center;
            padding: 2rem;
            color: #666;
        }
    </style>
</head>
<body style="background:#f7fafd;">
<?php include 'header.inc'; ?>

<div class="activity-container">
    <h1 style="color: #333; margin-bottom: 1.5rem;">사용자 활동 기록</h1>
    
    <div class="activity-filters">
        <form class="filter-form" method="get">
            <select name="user_id" class="filter-select">
                <option value="">모든 사용자</option>
                <?php foreach ($users as $user): ?>
                    <option value="<?php echo $user['id']; ?>" <?php echo $filter_user_id == $user['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($user['nickname']); ?> (<?php echo $user['role']; ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            
            <input type="text" name="ip" placeholder="IP 주소" value="<?php echo htmlspecialchars($filter_ip); ?>" class="filter-input">
            
            <button type="submit" class="filter-btn">필터 적용</button>
            <a href="user_activities.php" class="filter-btn" style="text-decoration: none; background: #6c757d;">초기화</a>
        </form>
    </div>
    
    <div class="activity-table">
        <?php if (empty($activities)): ?>
            <div class="no-data">활동 기록이 없습니다.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>시간</th>
                        <th>사용자</th>
                        <th>활동</th>
                        <th>설명</th>
                        <th>IP 주소</th>
                        <th>세션 ID</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activities as $activity): ?>
                        <tr>
                            <td class="timestamp">
                                <?php echo date('Y-m-d H:i:s', strtotime($activity['created_at'])); ?>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($activity['nickname'] ?? '알 수 없음'); ?>
                            </td>
                            <td>
                                <span class="activity-type <?php echo $activity['activity_type']; ?>">
                                    <?php 
                                    $type_labels = [
                                        'login' => '로그인',
                                        'logout' => '로그아웃',
                                        'create_property' => '임대물 등록',
                                        'update_property' => '임대물 수정',
                                        'delete_property' => '임대물 삭제',
                                        'create_contract' => '계약 생성',
                                        'update_contract' => '계약 수정',
                                        'delete_contract' => '계약 삭제',
                                        'upload_photo' => '사진 업로드',
                                        'delete_photo' => '사진 삭제',
                                        'create_signature' => '서명 생성',
                                        'create_damage_report' => '손상 신고',
                                        'update_damage_report' => '손상 신고 수정',
                                        'view_property' => '임대물 조회',
                                        'view_contract' => '계약 조회',
                                        'other' => '기타'
                                    ];
                                    echo $type_labels[$activity['activity_type']] ?? $activity['activity_type'];
                                    ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($activity['description']); ?></td>
                            <td class="ip-address"><?php echo htmlspecialchars($activity['ip_address']); ?></td>
                            <td style="font-size: 0.8rem; color: #999;">
                                <?php echo substr($activity['session_id'], 0, 8) . '...'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.inc'; ?>
</body>
</html> 