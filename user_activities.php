<?php
require_once 'config.inc';
require_once 'sql.inc';

// 관리자 권한 확인 - 로그인되지 않았거나 admin이 아닌 경우 index.php로 리다이렉트
if (!isset($_SESSION['user_id']) || $_SESSION['usergroup'] !== 'admin') {
    header('Location: index.php');
    exit;
}

// 페이지네이션 설정
$records_per_page = 100; // 페이지당 100개 고정
$current_page = max(1, safe_int($_GET['page'] ?? 1, 1));
$offset = ($current_page - 1) * $records_per_page;

// 필터 파라미터 수집 (SQL 인젝션 방지 적용)
$filters = [
    'user_id' => safe_int($_GET['user_id'] ?? '', 0),
    'contract_id' => safe_int($_GET['contract_id'] ?? '', 0),
    'property_id' => safe_int($_GET['property_id'] ?? '', 0),
    'activity_type' => safe_string($_GET['activity_type'] ?? '', 50),
    'ip_address' => safe_string($_GET['ip_address'] ?? '', 45),
    'description' => safe_string($_GET['description'] ?? '', 200),
    'date_from' => safe_date($_GET['date_from'] ?? ''),
    'date_to' => safe_date($_GET['date_to'] ?? '')
];

// 전체 레코드 수 조회 함수
function get_total_activities_count($filters) {
    $pdo = get_pdo();
    if (is_string($pdo)) {
        return 0;
    }

    try {
        $sql = "SELECT COUNT(*) as total
                FROM user_activities ua 
                LEFT JOIN users u ON ua.user_id = u.id 
                LEFT JOIN contracts c ON ua.contract_id = c.id
                WHERE 1=1";
        
        $params = [];
        
        // 필터 조건들 (get_filtered_activities와 동일)
        if (!empty($filters['user_id'])) {
            $sql .= " AND ua.user_id = ?";
            $params[] = $filters['user_id'];
        }
        
        if (!empty($filters['contract_id'])) {
            $sql .= " AND ua.contract_id = ?";
            $params[] = $filters['contract_id'];
        }
        
        if (!empty($filters['activity_type'])) {
            $sql .= " AND ua.activity_type = ?";
            $params[] = $filters['activity_type'];
        }
        
        if (!empty($filters['ip_address'])) {
            $sql .= " AND ua.ip_address LIKE ?";
            $params[] = safe_like_pattern($filters['ip_address']);
        }
        
        if (!empty($filters['description'])) {
            $sql .= " AND ua.description LIKE ?";
            $params[] = safe_like_pattern($filters['description']);
        }
        
        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(ua.created_at) >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(ua.created_at) <= ?";
            $params[] = $filters['date_to'];
        }

        if (!empty($filters['property_id'])) {
            $sql .= " AND ua.property_id = ?";
            $params[] = $filters['property_id'];
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return intval($result['total']);
    } catch (PDOException $e) {
        error_log('전체 활동 수 조회 실패: ' . $e->getMessage());
        return 0;
    }
}

// 페이지별 활동 조회 함수 (고급 필터링 지원)
function get_filtered_activities($filters, $offset, $limit) {
    $pdo = get_pdo();
    if (is_string($pdo)) {
        return [];
    }

    try {
        $sql = "SELECT ua.*, u.nickname, c.id as contract_ref_id 
                FROM user_activities ua 
                LEFT JOIN users u ON ua.user_id = u.id 
                LEFT JOIN contracts c ON ua.contract_id = c.id
                WHERE 1=1";
        
        $params = [];
        
        // 사용자 ID 필터
        if (!empty($filters['user_id'])) {
            $sql .= " AND ua.user_id = ?";
            $params[] = $filters['user_id'];
        }
        
        // 계약 ID 필터
        if (!empty($filters['contract_id'])) {
            $sql .= " AND ua.contract_id = ?";
            $params[] = $filters['contract_id'];
        }
        
        // 활동 유형 필터
        if (!empty($filters['activity_type'])) {
            $sql .= " AND ua.activity_type = ?";
            $params[] = $filters['activity_type'];
        }
        
        // IP 주소 필터
        if (!empty($filters['ip_address'])) {
            $sql .= " AND ua.ip_address LIKE ?";
            $params[] = safe_like_pattern($filters['ip_address']);
        }
        
        // 설명 검색
        if (!empty($filters['description'])) {
            $sql .= " AND ua.description LIKE ?";
            $params[] = safe_like_pattern($filters['description']);
        }
        
        // 날짜 범위 필터
        if (!empty($filters['date_from'])) {
            $sql .= " AND DATE(ua.created_at) >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND DATE(ua.created_at) <= ?";
            $params[] = $filters['date_to'];
        }

        if (!empty($filters['property_id'])) {
            $sql .= " AND ua.property_id = ?";
            $params[] = $filters['property_id'];
        }
        
        $sql .= " ORDER BY ua.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('필터링된 활동 조회 실패: ' . $e->getMessage());
        return [];
    }
}

// 전체 레코드 수와 페이지별 데이터 조회
$total_records = get_total_activities_count($filters);
$total_pages = ceil($total_records / $records_per_page);
$activities = get_filtered_activities($filters, $offset, $records_per_page);

// 사용자 목록 조회 (필터용)
$pdo = get_pdo();
$users = [];
if (!is_string($pdo)) {
    try {
        $user_sql = "SELECT id, nickname, email, usergroup FROM users ORDER BY nickname";
        $user_stmt = $pdo->query($user_sql);
        $users = $user_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // 에러 무시
    }
}

// 계약 목록 조회 (필터용)
$contracts = [];
if (!is_string($pdo)) {
    try {
        $contract_sql = "SELECT c.id, p.address, u.nickname as creator_name 
                        FROM contracts c 
                        LEFT JOIN properties p ON c.property_id = p.id 
                        LEFT JOIN users u ON c.created_by = u.id 
                        ORDER BY c.id DESC LIMIT 100";
        $contract_stmt = $pdo->query($contract_sql);
        $contracts = $contract_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // 에러 무시
    }
}

// 활동 유형 목록
$activity_types = [
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
    'view_property' => '임대물 조회',
    'view_contract' => '계약 조회',
    'other' => '기타'
];

// 페이지네이션 URL 생성 함수
function build_pagination_url($page, $filters) {
    $params = array_merge($filters, ['page' => $page]);
    $params = array_filter($params, function($value) {
        return $value !== '' && $value !== null;
    });
    return 'user_activities.php?' . http_build_query($params);
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
            max-width: 1400px;
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
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
        }
        .filter-label {
            font-size: 0.9rem;
            font-weight: 600;
            color: #333;
        }
        .filter-select, .filter-input {
            padding: 0.6rem;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.9rem;
            width: 100%;
            box-sizing: border-box;
        }
        .filter-buttons {
            display: flex;
            gap: 0.5rem;
            align-items: end;
        }
        .filter-btn {
            background: #0064FF;
            color: #fff;
            border: none;
            padding: 0.6rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            white-space: nowrap;
        }
        .filter-btn.secondary {
            background: #6c757d;
        }
        .filter-stats {
            background: #f8f9fa;
            padding: 0.8rem 1rem;
            border-radius: 6px;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            color: #666;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin: 1.5rem 0;
            flex-wrap: wrap;
        }
        .pagination a, .pagination span {
            padding: 0.5rem 0.8rem;
            border: 1px solid #ddd;
            border-radius: 6px;
            text-decoration: none;
            color: #333;
            font-size: 0.9rem;
            min-width: 40px;
            text-align: center;
        }
        .pagination a:hover {
            background: #f8f9fa;
        }
        .pagination .current {
            background: #0064FF;
            color: #fff;
            border-color: #0064FF;
        }
        .pagination .disabled {
            color: #999;
            cursor: not-allowed;
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
            font-size: 0.9rem;
        }
        .activity-table th, .activity-table td {
            padding: 0.6rem 0.8rem;
            text-align: left;
            border-bottom: 1px solid #eee;
            vertical-align: top;
        }
        .activity-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .activity-type {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 500;
            white-space: nowrap;
        }
        .activity-type.login { background: #d4edda; color: #155724; }
        .activity-type.logout { background: #f8d7da; color: #721c24; }
        .activity-type.create_property { background: #d1ecf1; color: #0c5460; }
        .activity-type.create_contract { background: #fff3cd; color: #856404; }
        .activity-type.upload_photo { background: #e7e3ff; color: #5a54d6; }
        .activity-type.create_signature { background: #ffe6cc; color: #cc7a00; }
        .activity-type.view_property { background: #e2f3ff; color: #0066cc; }
        .activity-type.view_contract { background: #e2f3ff; color: #0066cc; }
        .activity-type.other { background: #e2e3e5; color: #383d41; }
        .ip-address {
            font-family: monospace;
            font-size: 0.85rem;
            color: #666;
        }
        .timestamp {
            font-size: 0.85rem;
            color: #888;
            white-space: nowrap;
        }
        .description {
            max-width: 300px;
            word-break: break-word;
            font-size: 0.85rem;
        }
        .no-data {
            text-align: center;
            padding: 3rem;
            color: #666;
        }
        @media (max-width: 768px) {
            .filter-form {
                grid-template-columns: 1fr;
            }
            .filter-stats {
                flex-direction: column;
                align-items: flex-start;
            }
            .activity-table {
                overflow-x: auto;
            }
            .activity-table table {
                min-width: 800px;
            }
            .pagination {
                justify-content: flex-start;
                overflow-x: auto;
                padding-bottom: 0.5rem;
            }
        }
    </style>
</head>
<body style="background:#f7fafd;">
<?php include 'header.inc'; ?>

<div class="activity-container">
    <h1 style="color: #333; margin-bottom: 1.5rem;">사용자 활동 기록</h1>
    
    <div class="activity-filters">
        <form class="filter-form" method="get">
            <div class="filter-group">
                <label class="filter-label">사용자</label>
                <select name="user_id" class="filter-select">
                    <option value="">모든 사용자</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?php echo $user['id']; ?>" <?php echo $filters['user_id'] == $user['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($user['nickname']); ?> (<?php echo $user['usergroup']; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label class="filter-label">계약 ID</label>
                <select name="contract_id" class="filter-select">
                    <option value="">모든 계약</option>
                    <?php foreach ($contracts as $contract): ?>
                        <option value="<?php echo $contract['id']; ?>" <?php echo $filters['contract_id'] == $contract['id'] ? 'selected' : ''; ?>>
                            #<?php echo $contract['id']; ?> - <?php echo htmlspecialchars(mb_substr($contract['address'] ?? '', 0, 20)); ?>...
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label class="filter-label">활동 유형</label>
                <select name="activity_type" class="filter-select">
                    <option value="">모든 활동</option>
                    <?php foreach ($activity_types as $type => $label): ?>
                        <option value="<?php echo $type; ?>" <?php echo $filters['activity_type'] === $type ? 'selected' : ''; ?>>
                            <?php echo $label; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label class="filter-label">IP 주소</label>
                <input type="text" name="ip_address" placeholder="예: 127.0.0.1" value="<?php echo htmlspecialchars($filters['ip_address']); ?>" class="filter-input">
            </div>
            
            <div class="filter-group">
                <label class="filter-label">설명 검색</label>
                <input type="text" name="description" placeholder="설명에서 검색..." value="<?php echo htmlspecialchars($filters['description']); ?>" class="filter-input">
            </div>

            <div class="filter-group">
                <label class="filter-label">임대물 ID</label>
                <input type="number" name="property_id" class="filter-select" value="<?php echo htmlspecialchars($filters['property_id'] ?: ''); ?>" placeholder="ID">
            </div>
            
            <div class="filter-group">
                <label class="filter-label">시작 날짜</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($filters['date_from']); ?>" class="filter-input">
            </div>
            
            <div class="filter-group">
                <label class="filter-label">종료 날짜</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($filters['date_to']); ?>" class="filter-input">
            </div>
            
            <div class="filter-buttons">
                <button type="submit" class="filter-btn">필터 적용</button>
                <a href="user_activities.php" class="filter-btn secondary">초기화</a>
            </div>
        </form>
    </div>
    
    <div class="filter-stats">
        <div>
            총 <?php echo number_format($total_records); ?>개의 활동 기록
            <?php if (array_filter($filters)): ?>
                (필터 적용됨)
            <?php endif; ?>
        </div>
        <div>
            <?php if ($total_pages > 1): ?>
                페이지 <?php echo $current_page; ?> / <?php echo number_format($total_pages); ?>
            <?php endif; ?>
        </div>
    </div>
    
    <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($current_page > 1): ?>
                <a href="<?php echo build_pagination_url(1, $filters); ?>">&laquo; 처음</a>
                <a href="<?php echo build_pagination_url($current_page - 1, $filters); ?>">&lsaquo; 이전</a>
            <?php else: ?>
                <span class="disabled">&laquo; 처음</span>
                <span class="disabled">&lsaquo; 이전</span>
            <?php endif; ?>
            
            <?php
            // 페이지 번호 표시 로직 (현재 페이지 기준으로 앞뒤 5개씩)
            $start_page = max(1, $current_page - 5);
            $end_page = min($total_pages, $current_page + 5);
            
            if ($start_page > 1): ?>
                <a href="<?php echo build_pagination_url(1, $filters); ?>">1</a>
                <?php if ($start_page > 2): ?>
                    <span>...</span>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                <?php if ($i == $current_page): ?>
                    <span class="current"><?php echo $i; ?></span>
                <?php else: ?>
                    <a href="<?php echo build_pagination_url($i, $filters); ?>"><?php echo $i; ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            
            <?php if ($end_page < $total_pages): ?>
                <?php if ($end_page < $total_pages - 1): ?>
                    <span>...</span>
                <?php endif; ?>
                <a href="<?php echo build_pagination_url($total_pages, $filters); ?>"><?php echo number_format($total_pages); ?></a>
            <?php endif; ?>
            
            <?php if ($current_page < $total_pages): ?>
                <a href="<?php echo build_pagination_url($current_page + 1, $filters); ?>">다음 &rsaquo;</a>
                <a href="<?php echo build_pagination_url($total_pages, $filters); ?>">마지막 &raquo;</a>
            <?php else: ?>
                <span class="disabled">다음 &rsaquo;</span>
                <span class="disabled">마지막 &raquo;</span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <div class="activity-table">
        <?php if (empty($activities)): ?>
            <div class="no-data">조건에 맞는 활동 기록이 없습니다.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>시간</th>
                        <th>사용자</th>
                        <th>활동</th>
                        <th>임대물 ID</th>
                        <th>계약 ID</th>
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
                                <?php if ($activity['login_id']): ?>
                                    <br><small style="color: #888;">(<?php echo htmlspecialchars($activity['login_id']); ?>)</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="activity-type <?php echo $activity['activity_type']; ?>">
                                    <?php echo $activity_types[$activity['activity_type']] ?? $activity['activity_type']; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($activity['property_id']): ?>
                                    <a href="properties.php?id=<?php echo $activity['property_id']; ?>" style="color: #1976d2; text-decoration: none;">
                                        #<?php echo $activity['property_id']; ?>
                                    </a>
                                <?php else: ?>
                                    <span style="color: #999;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($activity['contract_id']): ?>
                                    <a href="view_contract.php?id=<?php echo $activity['contract_id']; ?>" style="color: #0064FF; text-decoration: none;">
                                        #<?php echo $activity['contract_id']; ?>
                                    </a>
                                <?php else: ?>
                                    <span style="color: #999;">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="description"><?php echo htmlspecialchars($activity['description']); ?></td>
                            <td class="ip-address"><?php echo htmlspecialchars($activity['ip_address']); ?></td>
                            <td style="font-size: 0.8rem; color: #999;">
                                <?php echo $activity['session_id'] ? substr($activity['session_id'], 0, 8) . '...' : '-'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    
    <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($current_page > 1): ?>
                <a href="<?php echo build_pagination_url(1, $filters); ?>">&laquo; 처음</a>
                <a href="<?php echo build_pagination_url($current_page - 1, $filters); ?>">&lsaquo; 이전</a>
            <?php else: ?>
                <span class="disabled">&laquo; 처음</span>
                <span class="disabled">&lsaquo; 이전</span>
            <?php endif; ?>
            
            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                <?php if ($i == $current_page): ?>
                    <span class="current"><?php echo $i; ?></span>
                <?php else: ?>
                    <a href="<?php echo build_pagination_url($i, $filters); ?>"><?php echo $i; ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            
            <?php if ($current_page < $total_pages): ?>
                <a href="<?php echo build_pagination_url($current_page + 1, $filters); ?>">다음 &rsaquo;</a>
                <a href="<?php echo build_pagination_url($total_pages, $filters); ?>">마지막 &raquo;</a>
            <?php else: ?>
                <span class="disabled">다음 &rsaquo;</span>
                <span class="disabled">마지막 &raquo;</span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php include 'footer.inc'; ?>
</body>
</html> 