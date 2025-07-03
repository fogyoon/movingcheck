<?php
require_once 'sql.inc';

// 관리자 권한 확인 - 로그인되지 않았거나 admin이 아닌 경우 index.php로 리다이렉트
if (!isset($_SESSION['user_id']) || $_SESSION['usergroup'] !== 'admin') {
    header('Location: index.php');
    exit;
}

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['reset_db'])) {
        try {
            $msg = create_all_tables();
        } catch (Exception $e) {
            $msg = 'DB 초기화 실패: ' . $e->getMessage();
        }
    } elseif (isset($_POST['create_sample'])) {
        try {
            $msg = create_sample_data();
        } catch (Exception $e) {
            $msg = '샘플 데이터 생성 실패: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DB 테이블 초기화 - 개발 도구</title>
    <link rel="stylesheet" href="style.css">
    <style>
      .dev-container { max-width: 420px; margin: 4rem auto; background: #fff; border-radius: 16px; box-shadow: 0 2px 12px rgba(0,100,255,0.07); padding: 2.5rem 2rem; text-align: center; }
      .dev-title { font-size: 1.3rem; font-weight: 700; color: #0064FF; margin-bottom: 1.5rem; }
      .dev-btn { background: #0064FF; color: #fff; font-size: 1.08rem; font-weight: 700; border: none; border-radius: 8px; padding: 0.9rem 2.5rem; margin: 0.5rem; cursor: pointer; transition: background 0.18s; }
      .dev-btn:hover { background: #0052cc; }
      .dev-btn.sample { background: #28a745; }
      .dev-btn.sample:hover { background: #218838; }
      .dev-msg { margin-top: 1.5rem; color: #1976d2; font-size: 1.05rem; }
      .dev-msg.error { color: #d32f2f; font-weight: 700; background: #ffebee; padding: 1rem; border-radius: 8px; border-left: 4px solid #d32f2f; }
    </style>
</head>
<body style="background:#f7fafd;">
<?php include 'header.inc'; ?>
  <div class="dev-container">
    <div class="dev-title">DB 테이블 초기화 (개발용)</div>
    <form method="post">
      <button type="submit" name="reset_db" class="dev-btn" onclick="return confirm('정말로 모든 테이블을 삭제하고 새로 만드시겠습니까?')">DB 테이블 초기화</button>
      <button type="submit" name="create_sample" class="dev-btn sample" onclick="return confirm('샘플 데이터를 생성하시겠습니까?')">샘플 데이터 생성</button>
    </form>
    <?php if ($msg): ?>
      <div class="dev-msg <?php echo strpos($msg, '실패') !== false ? 'error' : ''; ?>"><?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>
    
    <div style="margin-top: 2rem; padding: 1.5rem; background: #f8f9fa; border-radius: 8px; border: 1px solid #e9ecef;">
      <h3 style="margin: 0 0 1rem 0; color: #495057; font-size: 1.1rem;">🧪 테스트 환경 설정</h3>
      <p style="margin: 0 0 1rem 0; color: #6c757d; font-size: 0.9rem;">
        현재 테스트 사용자: <strong><?php echo defined('TEST_LOGIN_ID') && TEST_LOGIN_ID ? TEST_LOGIN_ID : '없음'; ?></strong>
      </p>
      <p style="margin: 0 0 1rem 0; color: #6c757d; font-size: 0.9rem;">
        사용 가능한 테스트 계정: admin, user_001, user_002, user_003, user_004
      </p>
      <p style="margin: 0; color: #dc3545; font-size: 0.85rem;">
        ⚠️ 운영 환경에서는 config.inc에서 TEST_LOGIN_ID를 null로 설정하세요.
      </p>
    </div>
    
    <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid #eee;">
      <a href="user_activities.php" style="color: #0064FF; text-decoration: none; font-size: 0.95rem;">📊 사용자 활동 기록 보기</a>
      <br><br>
      <a href="admin_login.php" style="color: #0064FF; text-decoration: none; font-size: 0.95rem;">🔐 관리자 로그인</a>
    </div>
  </div>
<?php include 'footer.inc'; ?>
</body>
</html> 