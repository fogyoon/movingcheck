<?php
require_once 'sql.inc';

// 로그인 확인
if (!isset($_SESSION['user_id'])) {
    die('로그인이 필요합니다.');
}

$user_id = $_SESSION['user_id'];
$contract_id = (int)($_GET['id'] ?? 2); // 기본값 2

echo "<h2>계약서 파일 디버깅 - 계약 ID: {$contract_id}</h2>";

$pdo = get_pdo();

// 1. 계약 정보 조회
echo "<h3>1. 계약 정보 조회</h3>";
$stmt = $pdo->prepare("
    SELECT c.*, p.address, p.created_by as property_owner
    FROM contracts c
    JOIN properties p ON c.property_id = p.id
    WHERE c.id = ?
");
$stmt->execute([$contract_id]);
$contract = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$contract) {
    die("계약 ID {$contract_id}를 찾을 수 없습니다.");
}

echo "<p>계약 ID: {$contract['id']}</p>";
echo "<p>임대물 주소: {$contract['address']}</p>";
echo "<p>임대물 소유자: {$contract['property_owner']}</p>";
echo "<p>현재 사용자: {$user_id}</p>";
echo "<p>권한 확인: " . ($contract['property_owner'] == $user_id ? '✅ 허용' : '❌ 거부') . "</p>";

// 2. 파일 정보 확인
echo "<h3>2. 파일 정보 확인</h3>";
$contract_file = $contract['contract_file'];

if (!$contract_file) {
    die("계약서 파일 경로가 없습니다.");
}

echo "<p>파일 경로: {$contract_file}</p>";
echo "<p>파일 존재: " . (file_exists($contract_file) ? '✅ 예' : '❌ 아니오') . "</p>";

if (file_exists($contract_file)) {
    echo "<p>파일 크기: " . filesize($contract_file) . " bytes</p>";
    echo "<p>파일 권한: " . substr(sprintf('%o', fileperms($contract_file)), -4) . "</p>";
    echo "<p>파일 읽기 가능: " . (is_readable($contract_file) ? '✅ 예' : '❌ 아니오') . "</p>";
    echo "<p>파일 확장자: " . pathinfo($contract_file, PATHINFO_EXTENSION) . "</p>";
}

// 3. 디렉토리 정보
echo "<h3>3. 디렉토리 정보</h3>";
$contracts_dir = 'contracts/';
echo "<p>contracts 디렉토리: {$contracts_dir}</p>";
echo "<p>디렉토리 존재: " . (is_dir($contracts_dir) ? '✅ 예' : '❌ 아니오') . "</p>";
echo "<p>디렉토리 읽기 가능: " . (is_readable($contracts_dir) ? '✅ 예' : '❌ 아니오') . "</p>";
echo "<p>현재 작업 디렉토리: " . getcwd() . "</p>";

// 4. 파일 내용 테스트
echo "<h3>4. 파일 내용 테스트</h3>";
if (file_exists($contract_file) && is_readable($contract_file)) {
    $file_extension = strtolower(pathinfo($contract_file, PATHINFO_EXTENSION));
    
    if (in_array($file_extension, ['jpg', 'jpeg', 'png'])) {
        echo "<p>이미지 파일입니다. 미리보기:</p>";
        echo "<img src='{$contract_file}' style='max-width: 300px; border: 1px solid #ccc;' alt='계약서 미리보기'>";
    } elseif ($file_extension === 'pdf') {
        echo "<p>PDF 파일입니다.</p>";
        echo "<a href='{$contract_file}' target='_blank'>PDF 파일 직접 열기</a>";
    }
    
    // 파일의 처음 100바이트 확인
    $handle = fopen($contract_file, 'rb');
    if ($handle) {
        $content = fread($handle, 100);
        fclose($handle);
        echo "<p>파일 시작 부분 (16진수): " . bin2hex($content) . "</p>";
    }
}

// 5. 간단한 파일 출력 테스트
echo "<h3>5. 간단한 파일 출력 테스트</h3>";
if (file_exists($contract_file) && is_readable($contract_file)) {
    echo "<p><a href='simple_view.php?id={$contract_id}' target='_blank'>간단한 파일 출력 테스트</a></p>";
}

echo "<hr>";
echo "<p><a href='view_contract.php?id={$contract_id}' target='_blank'>원래 view_contract.php 테스트</a></p>";
?> 