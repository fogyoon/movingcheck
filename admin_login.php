<?php 
require_once 'config.inc';
require_once 'sql.inc';

$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if ($email && $password) {
        $pdo = get_pdo();
        if (!is_string($pdo)) {
            try {
                $sql = "SELECT id, email, nickname, password, role FROM users WHERE email = ? AND password IS NOT NULL";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user && password_verify($password, $user['password'])) {
                    if ($user['role'] === 'admin') {
                        // 로그인 성공
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['email'] = $user['email'];
                        $_SESSION['nickname'] = $user['nickname'];
                        $_SESSION['role'] = $user['role'];
                        
                        // 로그인 활동 기록
                        log_login($user['id'], "관리자 로그인: {$user['nickname']}");
                        
                        header('Location: admin.php');
                        exit;
                    } else {
                        $error_msg = '관리자 권한이 없습니다.';
                    }
                } else {
                    $error_msg = '이메일 또는 비밀번호가 올바르지 않습니다.';
                }
            } catch (Exception $e) {
                $error_msg = '로그인 처리 중 오류가 발생했습니다.';
            }
        } else {
            $error_msg = '데이터베이스 연결 오류입니다.';
        }
    } else {
        $error_msg = '이메일과 비밀번호를 모두 입력해주세요.';
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>관리자 로그인 - <?php echo SITE_TITLE; ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .admin-login-container {
            max-width: 400px;
            margin: 4rem auto;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            padding: 2.5rem 2rem;
        }
        .admin-login-title {
            font-size: 1.4rem;
            font-weight: 700;
            color: #333;
            text-align: center;
            margin-bottom: 2rem;
        }
        .admin-form-group {
            margin-bottom: 1.5rem;
        }
        .admin-form-label {
            display: block;
            font-size: 0.95rem;
            font-weight: 600;
            color: #555;
            margin-bottom: 0.5rem;
        }
        .admin-form-input {
            width: 100%;
            padding: 0.8rem;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.2s;
            box-sizing: border-box;
        }
        .admin-form-input:focus {
            outline: none;
            border-color: #0064FF;
        }
        .admin-login-btn {
            width: 100%;
            background: #0064FF;
            color: #fff;
            font-size: 1.1rem;
            font-weight: 700;
            border: none;
            border-radius: 8px;
            padding: 1rem;
            cursor: pointer;
            transition: background 0.2s;
        }
        .admin-login-btn:hover {
            background: #0052cc;
        }
        .admin-error {
            background: #f8d7da;
            color: #721c24;
            padding: 0.8rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
        }
        .admin-back-link {
            display: block;
            text-align: center;
            margin-top: 1.5rem;
            color: #0064FF;
            text-decoration: none;
            font-size: 0.95rem;
        }
        .admin-back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body style="background:#f7fafd;">
<?php include 'header.inc'; ?>

<div class="admin-login-container">
    <div class="admin-login-title">관리자 로그인</div>
    
    <?php if ($error_msg): ?>
        <div class="admin-error"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>
    
    <form method="post">
        <div class="admin-form-group">
            <label class="admin-form-label">이메일</label>
            <input type="email" name="email" class="admin-form-input" required 
                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
        </div>
        
        <div class="admin-form-group">
            <label class="admin-form-label">비밀번호</label>
            <input type="password" name="password" class="admin-form-input" required>
        </div>
        
        <button type="submit" class="admin-login-btn">관리자 로그인</button>
    </form>
    
    <a href="login.php" class="admin-back-link">← 일반 로그인으로 돌아가기</a>
</div>

<?php include 'footer.inc'; ?>
</body>
</html> 