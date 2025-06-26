<?php
require_once 'sql.inc';

// 로그인 확인
if (!isset($_SESSION['user_id'])) {
    die('로그인이 필요합니다.');
}

$user_id = $_SESSION['user_id'];
$contract_id = (int)($_GET['id'] ?? 0);

if (!$contract_id) {
    die('잘못된 요청입니다.');
}

$pdo = get_pdo();

// 계약 정보 조회
$stmt = $pdo->prepare("
    SELECT c.*, p.created_by as property_owner
    FROM contracts c
    JOIN properties p ON c.property_id = p.id
    WHERE c.id = ? AND p.created_by = ?
");
$stmt->execute([$contract_id, $user_id]);
$contract = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$contract) {
    die('계약을 찾을 수 없습니다.');
}

$contract_file = $contract['contract_file'];

if (!$contract_file || !file_exists($contract_file)) {
    die('파일을 찾을 수 없습니다: ' . $contract_file);
}

// 파일 확장자에 따른 MIME 타입 설정
$file_extension = strtolower(pathinfo($contract_file, PATHINFO_EXTENSION));
$mime_types = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png'
];

$mime_type = $mime_types[$file_extension] ?? 'application/octet-stream';

// 간단한 파일 출력
header('Content-Type: ' . $mime_type);
header('Content-Length: ' . filesize($contract_file));
header('Content-Disposition: inline; filename="' . basename($contract_file) . '"');

// 파일 내용 출력
readfile($contract_file);
?> 