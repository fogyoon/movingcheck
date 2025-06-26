<?php
require_once 'config.inc';
require_once 'sql.inc';

// 관리자 권한 확인 - 로그인되지 않았거나 admin이 아닌 경우 index.php로 리다이렉트
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

$msg = '';
$msg_type = '';

// 비밀번호 변경 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
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
                    log_user_activity($_SESSION['user_id'], 'other', '관리자 비밀번호 변경');
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
}

// 사용자 역할 변경 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_role'])) {
    $user_id = $_POST['user_id'] ?? '';
    $new_role = $_POST['new_role'] ?? '';
    
    if ($user_id && $new_role && $user_id != $_SESSION['user_id']) {
        $pdo = get_pdo();
        if (!is_string($pdo)) {
            try {
                $sql = "UPDATE users SET role = ? WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$new_role, $user_id]);
                
                $msg = '사용자 역할이 성공적으로 변경되었습니다.';
                $msg_type = 'success';
                
                // 활동 기록
                log_user_activity($_SESSION['user_id'], 'other', "사용자 역할 변경: ID {$user_id} -> {$new_role}");
            } catch (Exception $e) {
                $msg = '역할 변경 중 오류가 발생했습니다.';
                $msg_type = 'error';
            }
        } else {
            $msg = '데이터베이스 연결 오류입니다.';
            $msg_type = 'error';
        }
    } else {
        $msg = '자신의 역할은 변경할 수 없습니다.';
        $msg_type = 'error';
    }
}

// 사용자 목록 조회
$pdo = get_pdo();
$users = [];
$search = $_GET['search'] ?? '';

// 1. Pagination logic
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;
$total_users = 0;
if (!is_string($pdo)) {
    $count_sql = "SELECT COUNT(*) FROM users";
    $total_users = $pdo->query($count_sql)->fetchColumn();
}
$total_pages = ceil($total_users / $per_page);

if (!is_string($pdo)) {
    try {
        if ($search) {
            $sql = "SELECT id, login_id, email, nickname, phone, role, login_by, created_at FROM users 
                    WHERE nickname LIKE ? OR email LIKE ? OR login_id LIKE ? 
                    ORDER BY created_at DESC LIMIT ? OFFSET ?";
            $stmt = $pdo->prepare($sql);
            $search_term = "%{$search}%";
            $stmt->bindValue(1, $search_term, PDO::PARAM_STR);
            $stmt->bindValue(2, $search_term, PDO::PARAM_STR);
            $stmt->bindValue(3, $search_term, PDO::PARAM_STR);
            $stmt->bindValue(4, (int)$per_page, PDO::PARAM_INT);
            $stmt->bindValue(5, (int)$offset, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $sql = "SELECT id, login_id, email, nickname, phone, role, login_by, created_at FROM users ORDER BY created_at DESC LIMIT ? OFFSET ?";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(1, (int)$per_page, PDO::PARAM_INT);
            $stmt->bindValue(2, (int)$offset, PDO::PARAM_INT);
            $stmt->execute();
        }
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $msg = '사용자 목록 조회 중 오류가 발생했습니다.';
        $msg_type = 'error';
    }
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
        .role-landlord { background: #28a745; color: #fff; }
        .role-agent { background: #17a2b8; color: #fff; }
        .role-tenant { background: #6c757d; color: #fff; }
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
                        <th>역할</th>
                        <th>가입일</th>
                        <th>역할 변경</th>
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
                                <span class="role-badge role-<?php echo $user['role']; ?>">
                                    <?php 
                                    $role_labels = [
                                        'admin' => '관리자',
                                        'landlord' => '임대인',
                                        'agent' => '중개사',
                                        'tenant' => '임차인'
                                    ];
                                    echo $role_labels[$user['role']] ?? $user['role'];
                                    ?>
                                </span>
                            </td>
                            <td><?php echo date('Y-m-d', strtotime($user['created_at'])); ?></td>
                            <td>
                                <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                    <form method="post" style="display: inline;">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <select name="new_role" style="padding: 0.3rem; border: 1px solid #ddd; border-radius: 4px; margin-right: 0.5rem;">
                                            <option value="tenant" <?php echo $user['role'] === 'tenant' ? 'selected' : ''; ?>>임차인</option>
                                            <option value="landlord" <?php echo $user['role'] === 'landlord' ? 'selected' : ''; ?>>임대인</option>
                                            <option value="agent" <?php echo $user['role'] === 'agent' ? 'selected' : ''; ?>>중개사</option>
                                            <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>관리자</option>
                                        </select>
                                        <button type="submit" name="change_role" class="admin-btn" style="padding: 0.3rem 0.8rem; font-size: 0.9rem;">변경</button>
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
                        <div class="user-card-row"><span class="user-card-label">역할</span><span><span class="role-badge role-<?php echo $user['role']; ?>"><?php 
                            $role_labels = [
                                'admin' => '관리자',
                                'landlord' => '임대인',
                                'agent' => '중개사',
                                'tenant' => '임차인'
                            ];
                            echo $role_labels[$user['role']] ?? $user['role'];
                        ?></span></span></div>
                        <div class="user-card-row"><span class="user-card-label">가입일</span><span><?php echo date('Y-m-d', strtotime($user['created_at'])); ?></span></div>
                        <div class="user-card-row user-card-actions">
                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                    <select name="new_role" style="padding: 0.3rem; border: 1px solid #ddd; border-radius: 4px; margin-right: 0.5rem;">
                                        <option value="tenant" <?php echo $user['role'] === 'tenant' ? 'selected' : ''; ?>>임차인</option>
                                        <option value="landlord" <?php echo $user['role'] === 'landlord' ? 'selected' : ''; ?>>임대인</option>
                                        <option value="agent" <?php echo $user['role'] === 'agent' ? 'selected' : ''; ?>>중개사</option>
                                        <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>관리자</option>
                                    </select>
                                    <button type="submit" name="change_role" class="admin-btn" style="padding: 0.3rem 0.8rem; font-size: 0.9rem;">변경</button>
                                </form>
                            <?php else: ?>
                                <span style="color: #999; font-size: 0.9rem;">본인</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($total_pages > 1): ?>
                <div style="margin:2rem 0; text-align:center;">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page'=>$i])); ?>"
                           style="display:inline-block; min-width:32px; padding:0.5rem 0.9rem; margin:0 0.2rem; border-radius:6px; background:<?php echo $i==$page?'#0064FF':'#e9ecef'; ?>; color:<?php echo $i==$page?'#fff':'#333'; ?>; font-weight:600; text-decoration:none; font-size:1rem;">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.inc'; ?>
</body>
</html> 