<?php
require_once 'sql.inc';

// 로그인 확인
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    die('접근 권한이 없습니다.');
}

$user_id = $_SESSION['user_id'];
$contract_id = (int)($_GET['id'] ?? 0);

if (!$contract_id) {
    http_response_code(400);
    die('잘못된 요청입니다.');
}

$pdo = get_pdo();

// 계약 정보 조회 및 권한 확인
$stmt = $pdo->prepare("
    SELECT c.*, p.created_by as property_owner
    FROM contracts c
    JOIN properties p ON c.property_id = p.id
    WHERE c.id = ? AND p.created_by = ?
");
$stmt->execute([$contract_id, $user_id]);
$contract = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$contract) {
    http_response_code(404);
    die('계약을 찾을 수 없습니다.');
}

$contract_file = $contract['contract_file'];

if (!$contract_file || !file_exists($contract_file)) {
    http_response_code(404);
    die('파일을 찾을 수 없습니다: ' . $contract_file);
}

// 보안: 파일 경로 검증 (contracts 디렉토리 내의 파일만 허용)
$real_file_path = realpath($contract_file);
$contracts_dir = realpath('contracts/');

if (!$real_file_path || !$contracts_dir || strpos($real_file_path, $contracts_dir) !== 0) {
    http_response_code(403);
    die('잘못된 파일 경로입니다.');
}

// 보안: 허용된 파일 확장자만 확인
$file_extension = strtolower(pathinfo($contract_file, PATHINFO_EXTENSION));
$allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png'];

if (!in_array($file_extension, $allowed_extensions)) {
    http_response_code(403);
    die('허용되지 않는 파일 형식입니다.');
}

// 파일 확장자에 따른 MIME 타입 설정
$mime_types = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png'
];

$mime_type = $mime_types[$file_extension] ?? 'application/octet-stream';

// 보안 헤더 설정
header('Content-Type: ' . $mime_type);
header('Content-Length: ' . filesize($contract_file));
header('Content-Disposition: inline; filename="' . basename($contract_file) . '"');
header('Cache-Control: private, max-age=3600'); // 1시간 캐시
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// 파일 내용 출력
readfile($contract_file);
?> 