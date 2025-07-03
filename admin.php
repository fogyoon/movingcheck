<?php
require_once 'config.inc';
require_once 'sql.inc';

// 관리자 권한 확인 - 로그인되지 않았거나 admin이 아닌 경우 index.php로 리다이렉트
if (!isset($_SESSION['user_id']) || $_SESSION['usergroup'] !== 'admin') {
    header('Location: index.php');
    exit;
}

$msg = '';
$msg_type = '';

// 비밀번호 변경 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    // 입력 검증 규칙 정의
    $validation_rules = [
        'current_password' => ['type' => 'string', 'required' => true, 'max_length' => 255],
        'new_password' => ['type' => 'string', 'required' => true, 'max_length' => 255],
        'confirm_password' => ['type' => 'string', 'required' => true, 'max_length' => 255]
    ];
    
    // 입력 데이터 검증
    $validation = validate_form_data($_POST, $validation_rules);
    
    if (!$validation['valid']) {
        $msg = '입력 형식이 올바르지 않습니다: ' . implode(', ', $validation['errors']);
        $msg_type = 'error';
    } else {
        $current_password = $validation['data']['current_password'];
        $new_password = $validation['data']['new_password'];
        $confirm_password = $validation['data']['confirm_password'];
    
    if ($new_password === $confirm_password && strlen($new_password) >= 6) {
        $pdo = get_pdo();
        if (!is_string($pdo)) {
            try {
                // 현재 비밀번호 확인
                $sql = "SELECT password FROM users WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$_SESSION['user_id']]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user && password_verify($current_password, $user['password'])) {
                    // 새 비밀번호로 업데이트
                    $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $update_sql = "UPDATE users SET password = ? WHERE id = ?";
                    $update_stmt = $pdo->prepare($update_sql);
                    $update_stmt->execute([$new_password_hash, $_SESSION['user_id']]);
                    
                    $msg = '비밀번호가 성공적으로 변경되었습니다.';
                    $msg_type = 'success';
                    
                    // 활동 기록
                    log_user_activity($_SESSION['user_id'], 'other', '관리자 비밀번호 변경', null);
                } else {
                    $msg = '현재 비밀번호가 올바르지 않습니다.';
                    $msg_type = 'error';
                }
            } catch (Exception $e) {
                $msg = '비밀번호 변경 중 오류가 발생했습니다.';
                $msg_type = 'error';
            }
        } else {
            $msg = '데이터베이스 연결 오류입니다.';
            $msg_type = 'error';
        }
        } else {
            $msg = '새 비밀번호가 일치하지 않거나 6자 이상이어야 합니다.';
            $msg_type = 'error';
        }
    } // else 블록 닫기
}

// 사용자 그룹 변경 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_usergroup'])) {
    // 입력 검증 규칙 정의
    $validation_rules = [
        'user_id' => ['type' => 'int', 'required' => true],
        'new_usergroup' => ['type' => 'string', 'required' => true, 'max_length' => 20]
    ];
    
    // 입력 데이터 검증
    $validation = validate_form_data($_POST, $validation_rules);
    
    if (!$validation['valid']) {
        $msg = '입력 형식이 올바르지 않습니다: ' . implode(', ', $validation['errors']);
        $msg_type = 'error';
    } else {
        $user_id = $validation['data']['user_id'];
        $new_usergroup = $validation['data']['new_usergroup'];
        
        // 허용된 그룹 체크
        if (!in_array($new_usergroup, ['user', 'admin', 'subadmin'])) {
            $msg = '유효하지 않은 사용자 그룹입니다.';
            $msg_type = 'error';
        }
    
    if ($user_id && $new_usergroup && $user_id != $_SESSION['user_id']) {
        $pdo = get_pdo();
        if (!is_string($pdo)) {
            try {
                $sql = "UPDATE users SET usergroup = ? WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$new_usergroup, $user_id]);
                
                $msg = '사용자 권한이 성공적으로 변경되었습니다.';
                $msg_type = 'success';
                
                // 활동 기록
                log_user_activity($_SESSION['user_id'], 'other', "사용자 권한 변경: ID {$user_id} -> {$new_usergroup}", null);
            } catch (Exception $e) {
                $msg = '권한 변경 중 오류가 발생했습니다.';
                $msg_type = 'error';
            }
        } else {
            $msg = '데이터베이스 연결 오류입니다.';
            $msg_type = 'error';
        }
        } else {
            $msg = '자신의 권한은 변경할 수 없습니다.';
            $msg_type = 'error';
        }
    } // else 블록 닫기
}

// 페이지네이션 설정
$records_per_page = 50; // 페이지당 50개
$current_page = max(1, safe_int($_GET['page'] ?? 1, 1));
$offset = ($current_page - 1) * $records_per_page;
$search = safe_string($_GET['search'] ?? '', 100);

// 사용자 목록 조회
$pdo = get_pdo();
$users = [];
$total_users = 0;

if (!is_string($pdo)) {
    try {
        // 전체 사용자 수 조회
        if ($search) {
            $count_sql = "SELECT COUNT(*) as total FROM users WHERE nickname LIKE ? OR email LIKE ? OR login_id LIKE ?";
            $count_stmt = $pdo->prepare($count_sql);
            $search_term = "%{$search}%";
            $count_stmt->execute([$search_term, $search_term, $search_term]);
        } else {
            $count_sql = "SELECT COUNT(*) as total FROM users";
            $count_stmt = $pdo->prepare($count_sql);
            $count_stmt->execute();
        }
        $total_users = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // 페이지별 사용자 목록 조회
        if ($search) {
            $sql = "SELECT id, login_id, email, nickname, phone, usergroup, login_by, created_at 
                    FROM users 
                    WHERE nickname LIKE ? OR email LIKE ? OR login_id LIKE ?
                    ORDER BY created_at DESC
                    LIMIT " . intval($offset) . ", " . intval($records_per_page);
            $stmt = $pdo->prepare($sql);
            $search_term = "%{$search}%";
            $stmt->execute([$search_term, $search_term, $search_term]);
        } else {
            $sql = "SELECT id, login_id, email, nickname, phone, usergroup, login_by, created_at 
                    FROM users 
                    ORDER BY created_at DESC
                    LIMIT " . intval($offset) . ", " . intval($records_per_page);
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
        }
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 각 사용자의 마지막 로그인 시간을 별도로 조회
        foreach ($users as &$user) {
            $login_sql = "SELECT MAX(created_at) as last_login FROM user_activities WHERE user_id = ? AND activity_type = 'login'";
            $login_stmt = $pdo->prepare($login_sql);
            $login_stmt->execute([$user['id']]);
            $login_result = $login_stmt->fetch(PDO::FETCH_ASSOC);
            $user['last_login_at'] = $login_result['last_login'];
        }
        unset($user); // 참조 해제
        
    } catch (PDOException $e) {
        $msg = '사용자 목록 조회 중 오류가 발생했습니다: ' . $e->getMessage();
        $msg_type = 'error';
    }
}

$total_pages = ceil($total_users / $records_per_page);

// 페이지네이션 URL 생성 함수
function build_admin_pagination_url($page, $search = '') {
    $params = ['page' => $page];
    if ($search) {
        $params['search'] = $search;
    }
    return 'admin.php?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>관리자 페이지 - <?php echo SITE_TITLE; ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .admin-container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        .admin-header {
            background: #fff;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .admin-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 0.5rem;
        }
        .admin-subtitle {
            color: #666;
            font-size: 1rem;
        }
        .admin-section {
            background: #fff;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .section-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 1.5rem;
        }
        .admin-form {
            max-width: 400px;
        }
        .form-group {
            margin-bottom: 1.5rem;
        }
        .form-label {
            display: block;
            font-size: 0.95rem;
            font-weight: 600;
            color: #555;
            margin-bottom: 0.5rem;
        }
        .form-input {
            width: 100%;
            padding: 0.8rem;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.2s;
            box-sizing: border-box;
        }
        .form-input:focus {
            outline: none;
            border-color: #0064FF;
        }
        .admin-btn {
            background: #0064FF;
            color: #fff;
            font-size: 1rem;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            padding: 0.8rem 1.5rem;
            cursor: pointer;
            transition: background 0.2s;
        }
        .admin-btn:hover {
            background: #0052cc;
        }
        .admin-btn.danger {
            background: #dc3545;
        }
        .admin-btn.danger:hover {
            background: #c82333;
        }
        .search-form {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .search-input {
            flex: 1;
            padding: 0.8rem;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 1rem;
        }
        .user-stats {
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
        .users-table {
            width: 100%;
            border-collapse: collapse;
        }
        .users-table th, .users-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        .users-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }
        .role-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        .role-admin { background: #dc3545; color: #fff; }
        .role-subadmin { background: #fd7e14; color: #fff; }
        .role-user { background: #6c757d; color: #fff; }
        .last-login {
            font-size: 0.85rem;
            color: #666;
        }
        .last-login.never {
            color: #999;
            font-style: italic;
        }
        .message {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-weight: 500;
        }
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .admin-links {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }
        .admin-link {
            color: #0064FF;
            text-decoration: none;
            font-size: 0.95rem;
        }
        .admin-link:hover {
            text-decoration: underline;
        }
        @media (max-width: 800px) {
          .users-table { display: none; }
          .user-cards { display: block; }
          .user-stats {
              flex-direction: column;
              align-items: flex-start;
          }
          .pagination {
              justify-content: flex-start;
              overflow-x: auto;
              padding-bottom: 0.5rem;
          }
        }
        @media (min-width: 801px) {
          .user-cards { display: none; }
        }
        .user-card {
          background: #fff;
          border-radius: 10px;
          box-shadow: 0 2px 8px rgba(0,0,0,0.07);
          margin-bottom: 1.2rem;
          padding: 1.2rem;
          display: flex;
          flex-direction: column;
          gap: 0.5rem;
        }
        .user-card .user-card-row {
          display: flex;
          justify-content: space-between;
          align-items: center;
          font-size: 1rem;
        }
        .user-card .user-card-label {
          color: #888;
          font-size: 0.95rem;
          min-width: 80px;
        }
        .user-card .role-badge { margin-left: 0.5rem; }
        .user-card .user-card-actions { margin-top: 0.7rem; }
    </style>
</head>
<body style="background:#f7fafd;">
<?php include 'header.inc'; ?>

<div class="admin-container">
    <div class="admin-header">
        <div class="admin-title">관리자 페이지</div>
        <div class="admin-subtitle">안녕하세요, <?php echo htmlspecialchars($_SESSION['nickname']); ?>님</div>
        <?php if (defined('TEST_LOGIN_ID') && TEST_LOGIN_ID): ?>
            <div style="margin-top: 0.5rem; padding: 0.5rem; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 6px; color: #856404; font-size: 0.9rem;">
                🧪 테스트 환경: <?php echo TEST_LOGIN_ID; ?> 계정으로 자동 로그인됨
            </div>
        <?php endif; ?>
        <div class="admin-links">
            <a href="user_activities.php" class="admin-link">📊 사용자 활동 기록</a>
            <a href="check_ip.php" class="admin-link">🌐 IP 주소 확인</a>
            <a href="develop.php" class="admin-link">🔧 개발 도구</a>
            <a href="logout.php" class="admin-link">🚪 로그아웃</a>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="message <?php echo $msg_type; ?>"><?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <div class="admin-section">
        <div class="section-title">관리자 비밀번호 변경</div>
        <form method="post" class="admin-form">
            <div class="form-group">
                <label class="form-label">현재 비밀번호</label>
                <input type="password" name="current_password" class="form-input" required>
            </div>
            <div class="form-group">
                <label class="form-label">새 비밀번호</label>
                <input type="password" name="new_password" class="form-input" required minlength="6">
            </div>
            <div class="form-group">
                <label class="form-label">새 비밀번호 확인</label>
                <input type="password" name="confirm_password" class="form-input" required minlength="6">
            </div>
            <button type="submit" name="change_password" class="admin-btn">비밀번호 변경</button>
        </form>
    </div>

    <div class="admin-section">
        <div class="section-title">사용자 관리</div>
        
        <form method="get" class="search-form">
            <input type="text" name="search" placeholder="닉네임, 이메일, 카카오ID로 검색..." 
                   value="<?php echo htmlspecialchars($search); ?>" class="search-input">
            <button type="submit" class="admin-btn">검색</button>
            <?php if ($search): ?>
                <a href="admin.php" class="admin-btn" style="text-decoration: none; background: #6c757d;">초기화</a>
            <?php endif; ?>
        </form>

        <div class="user-stats">
            <div>
                총 <?php echo number_format($total_users); ?>명의 사용자
                <?php if ($search): ?>
                    (검색 결과)
                <?php endif; ?>
            </div>
            <div>
                <?php if ($total_pages > 1): ?>
                    페이지 <?php echo $current_page; ?> / <?php echo number_format($total_pages); ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($total_pages > 1 && $total_users > 50): ?>
            <div class="pagination">
                <?php if ($current_page > 1): ?>
                    <a href="<?php echo build_admin_pagination_url(1, $search); ?>">&laquo; 처음</a>
                    <a href="<?php echo build_admin_pagination_url($current_page - 1, $search); ?>">&lsaquo; 이전</a>
                <?php else: ?>
                    <span class="disabled">&laquo; 처음</span>
                    <span class="disabled">&lsaquo; 이전</span>
                <?php endif; ?>
                
                <?php
                // 페이지 번호 표시 로직 (현재 페이지 기준으로 앞뒤 5개씩)
                $start_page = max(1, $current_page - 5);
                $end_page = min($total_pages, $current_page + 5);
                
                if ($start_page > 1): ?>
                    <a href="<?php echo build_admin_pagination_url(1, $search); ?>">1</a>
                    <?php if ($start_page > 2): ?>
                        <span>...</span>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                    <?php if ($i == $current_page): ?>
                        <span class="current"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="<?php echo build_admin_pagination_url($i, $search); ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($end_page < $total_pages): ?>
                    <?php if ($end_page < $total_pages - 1): ?>
                        <span>...</span>
                    <?php endif; ?>
                    <a href="<?php echo build_admin_pagination_url($total_pages, $search); ?>"><?php echo number_format($total_pages); ?></a>
                <?php endif; ?>
                
                <?php if ($current_page < $total_pages): ?>
                    <a href="<?php echo build_admin_pagination_url($current_page + 1, $search); ?>">다음 &rsaquo;</a>
                    <a href="<?php echo build_admin_pagination_url($total_pages, $search); ?>">마지막 &raquo;</a>
                <?php else: ?>
                    <span class="disabled">다음 &rsaquo;</span>
                    <span class="disabled">마지막 &raquo;</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (empty($users)): ?>
            <div style="text-align: center; color: #666; padding: 2rem;">사용자가 없습니다.</div>
        <?php else: ?>
            <table class="users-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>닉네임</th>
                        <th>이메일</th>
                        <th>카카오ID</th>
                        <th>로그인방식</th>
                        <th>권한</th>
                        <th>최종 로그인</th>
                        <th>가입일</th>
                        <th>권한 변경</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?php echo $user['id']; ?></td>
                            <td><?php echo htmlspecialchars($user['nickname']); ?></td>
                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                            <td><?php echo htmlspecialchars($user['login_id'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($user['login_by'] ?? '-'); ?></td>
                            <td>
                                <span class="role-badge role-<?php echo $user['usergroup']; ?>">
                                    <?php 
                                    $usergroup_labels = [
                                        'admin' => '관리자',
                                        'subadmin' => '부관리자',
                                        'user' => '일반사용자'
                                    ];
                                    echo $usergroup_labels[$user['usergroup']] ?? $user['usergroup'];
                                    ?>
                                </span>
                            </td>
                            <td class="last-login <?php echo $user['last_login_at'] ? '' : 'never'; ?>">
                                <?php 
                                if ($user['last_login_at']) {
                                    echo date('Y-m-d H:i', strtotime($user['last_login_at']));
                                } else {
                                    echo '로그인 없음';
                                }
                                ?>
                            </td>
                            <td><?php echo date('Y-m-d', strtotime($user['created_at'])); ?></td>
                            <td>
                                <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                    <form method="post" style="display: inline;">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <select name="new_usergroup" style="padding: 0.3rem; border: 1px solid #ddd; border-radius: 4px; margin-right: 0.5rem;">
                                            <option value="user" <?php echo $user['usergroup'] === 'user' ? 'selected' : ''; ?>>일반사용자</option>
                                            <option value="subadmin" <?php echo $user['usergroup'] === 'subadmin' ? 'selected' : ''; ?>>부관리자</option>
                                            <option value="admin" <?php echo $user['usergroup'] === 'admin' ? 'selected' : ''; ?>>관리자</option>
                                        </select>
                                        <button type="submit" name="change_usergroup" class="admin-btn" style="padding: 0.3rem 0.8rem; font-size: 0.9rem;">변경</button>
                                    </form>
                                <?php else: ?>
                                    <span style="color: #999; font-size: 0.9rem;">본인</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="user-cards">
                <?php foreach ($users as $user): ?>
                    <div class="user-card">
                        <div class="user-card-row"><span class="user-card-label">ID</span><span><?php echo $user['id']; ?></span></div>
                        <div class="user-card-row"><span class="user-card-label">닉네임</span><span><?php echo htmlspecialchars($user['nickname']); ?></span></div>
                        <div class="user-card-row"><span class="user-card-label">이메일</span><span><?php echo htmlspecialchars($user['email']); ?></span></div>
                        <div class="user-card-row"><span class="user-card-label">카카오ID</span><span><?php echo htmlspecialchars($user['login_id'] ?? '-'); ?></span></div>
                        <div class="user-card-row"><span class="user-card-label">로그인방식</span><span><?php echo htmlspecialchars($user['login_by'] ?? '-'); ?></span></div>
                        <div class="user-card-row"><span class="user-card-label">권한</span><span><span class="role-badge role-<?php echo $user['usergroup']; ?>"><?php 
                            $usergroup_labels = [
                                'admin' => '관리자',
                                'subadmin' => '부관리자',
                                'user' => '일반사용자'
                            ];
                            echo $usergroup_labels[$user['usergroup']] ?? $user['usergroup'];
                        ?></span></span></div>
                        <div class="user-card-row"><span class="user-card-label">최종 로그인</span><span class="last-login <?php echo $user['last_login_at'] ? '' : 'never'; ?>">
                            <?php 
                            if ($user['last_login_at']) {
                                echo date('Y-m-d H:i', strtotime($user['last_login_at']));
                            } else {
                                echo '로그인 없음';
                            }
                            ?>
                        </span></div>
                        <div class="user-card-row"><span class="user-card-label">가입일</span><span><?php echo date('Y-m-d', strtotime($user['created_at'])); ?></span></div>
                        <div class="user-card-row user-card-actions">
                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                    <select name="new_usergroup" style="padding: 0.3rem; border: 1px solid #ddd; border-radius: 4px; margin-right: 0.5rem;">
                                        <option value="user" <?php echo $user['usergroup'] === 'user' ? 'selected' : ''; ?>>일반사용자</option>
                                        <option value="subadmin" <?php echo $user['usergroup'] === 'subadmin' ? 'selected' : ''; ?>>부관리자</option>
                                        <option value="admin" <?php echo $user['usergroup'] === 'admin' ? 'selected' : ''; ?>>관리자</option>
                                    </select>
                                    <button type="submit" name="change_usergroup" class="admin-btn" style="padding: 0.3rem 0.8rem; font-size: 0.9rem;">변경</button>
                                </form>
                            <?php else: ?>
                                <span style="color: #999; font-size: 0.9rem;">본인</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <?php if ($total_pages > 1 && $total_users > 50): ?>
                <div class="pagination">
                    <?php if ($current_page > 1): ?>
                        <a href="<?php echo build_admin_pagination_url(1, $search); ?>">&laquo; 처음</a>
                        <a href="<?php echo build_admin_pagination_url($current_page - 1, $search); ?>">&lsaquo; 이전</a>
                    <?php else: ?>
                        <span class="disabled">&laquo; 처음</span>
                        <span class="disabled">&lsaquo; 이전</span>
                    <?php endif; ?>
                    
                    <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <?php if ($i == $current_page): ?>
                            <span class="current"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="<?php echo build_admin_pagination_url($i, $search); ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($current_page < $total_pages): ?>
                        <a href="<?php echo build_admin_pagination_url($current_page + 1, $search); ?>">다음 &rsaquo;</a>
                        <a href="<?php echo build_admin_pagination_url($total_pages, $search); ?>">마지막 &raquo;</a>
                    <?php else: ?>
                        <span class="disabled">다음 &rsaquo;</span>
                        <span class="disabled">마지막 &raquo;</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.inc'; ?>
</body>
</html> 