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
    // 기존 사용자인지 확인
    $check_sql = "SELECT id FROM users WHERE login_id = ?";
    $check_stmt = $pdo->prepare($check_sql);
    $check_stmt->execute([$id]);
    $existing_user = $check_stmt->fetch(PDO::FETCH_ASSOC);
    $is_new_user = !$existing_user;
    
    $sql = "INSERT INTO users (login_id, email, nickname, login_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE email=VALUES(email), nickname=VALUES(nickname), login_by=VALUES(login_by)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id, $email, $nickname, 'kakao']);
    
    // 사용자 ID 조회
    $user_sql = "SELECT id FROM users WHERE login_id = ?";
    $user_stmt = $pdo->prepare($user_sql);
    $user_stmt->execute([$id]);
    $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        // 신규 사용자인 경우 가입 로그 기록
        if ($is_new_user) {
            log_user_activity($user['id'], 'other', "신규 사용자 가입: {$nickname} ({$email})", null);
        }
        
        // 로그인 활동 기록
        log_login($user['id'], "카카오 계정으로 로그인: {$nickname}");
        
        // 세션에 사용자 정보 저장
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['login_id'] = $id;
        $_SESSION['nickname'] = $nickname;
        $_SESSION['email'] = $email;
        
        // 새로운 사용자인 경우 플래그 설정
        if ($is_new_user) {
            $_SESSION['is_new_user'] = true;
        }
    }
    
    echo json_encode(['result' => 'ok', 'is_new_user' => $is_new_user]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['result'=>'fail', 'error'=>$e->getMessage()]);
} 