<?php
// DB 접속 정보
//define('DB_HOST', 'localhost'); // DB 서버
define('DB_HOST', 'movingcheck.kr'); // DB 서버
define('DB_USER', 'realtor2013');    // DB 아이디
define('DB_PASS', 'qlalf7137756!'); // DB 비번
define('DB_NAME', 'realtor2013'); // DB 명

// 홈페이지 제목
define('SITE_TITLE', '무빙체크');

// 카카오 JavaScript 키
define('KAKAO_JS_KEY', 'YOUR_KAKAO_JS_KEY');

// 파일 암호화를 위한 시크릿 키 (실제 운영 시에는 더 복잡한 키로 변경하세요)
define('SECRET_KEY', 'movingcheck_secure_file_key_2024');

// 업로드 사진 사이즈 한도 (예: 5MB)
define('UPLOAD_IMAGE_LIMIT', 5 * 1024 * 1024); // 5MB 

// 테스트 환경 설정
// 실제 운영 환경에서는 이 값을 null로 설정하거나 주석 처리하세요
define('TEST_LOGIN_ID', 'agent_001'); // 'admin', 'landlord_001', 'agent_001', 'agent_002' 중 선택
//define('TEST_LOGIN_ID', null); // 테스트 모드 비활성화

session_start();

// 테스트 환경에서 자동 로그인 처리
if (defined('TEST_LOGIN_ID') && TEST_LOGIN_ID && !isset($_SESSION['user_id'])) {
    require_once 'sql.inc';
    
    $pdo = get_pdo();
    if (!is_string($pdo)) {
        try {
            $sql = "SELECT id, login_id, email, nickname, role FROM users WHERE login_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([TEST_LOGIN_ID]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                // 테스트 사용자로 자동 로그인
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['login_id'] = $user['login_id'];
                $_SESSION['nickname'] = $user['nickname'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];
                
                // 로그인 활동 기록
                log_login($user['id'], "테스트 환경 자동 로그인: {$user['nickname']} ({$user['role']})");
            }
        } catch (Exception $e) {
            // 에러 무시 (테스트 환경이므로)
        }
    }
}

$isLoggedIn = isset($_SESSION['user_id']);
?>