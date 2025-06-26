<?php
require_once 'config.inc';
require_once 'sql.inc';

header('Content-Type: application/json');
$data = json_decode(file_get_contents('php://input'), true);
if (!$data) { 
    http_response_code(400); 
    echo json_encode(['result'=>'fail']); 
    exit; 
}

$id = $data['id'];
$email = $data['email'];
$nickname = $data['nickname'];

$pdo = get_pdo();
if (is_string($pdo)) {
    http_response_code(500);
    echo json_encode(['result'=>'fail', 'error'=>'DB 연결 실패']);
    exit;
}

try {
    $sql = "INSERT INTO users (login_id, email, nickname, login_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE email=VALUES(email), nickname=VALUES(nickname), login_by=VALUES(login_by)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id, $email, $nickname, 'kakao']);
    
    // 사용자 ID 조회
    $user_sql = "SELECT id FROM users WHERE login_id = ?";
    $user_stmt = $pdo->prepare($user_sql);
    $user_stmt->execute([$id]);
    $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        // 로그인 활동 기록
        log_login($user['id'], "카카오 계정으로 로그인: {$nickname}");
        
        // 세션에 사용자 정보 저장
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['login_id'] = $id;
        $_SESSION['nickname'] = $nickname;
        $_SESSION['email'] = $email;
    }
    
    echo json_encode(['result' => 'ok']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['result'=>'fail', 'error'=>$e->getMessage()]);
} 