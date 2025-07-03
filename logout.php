<?php
require_once 'config.inc';
require_once 'sql.inc';

// 로그아웃 활동 기록
if (isset($_SESSION['user_id'])) {
    log_logout($_SESSION['user_id'], "로그아웃: {$_SESSION['nickname']}");
}

// 세션 파괴
session_destroy();

// 메인 페이지로 리다이렉트
header('Location: index.php');
exit;
?> 