<?php
require_once 'sql.inc';

// 로그인 확인 (관리자만 실행 가능)
if (!isset($_SESSION['user_id'])) {
    die('로그인이 필요합니다.');
}

$user_id = $_SESSION['user_id'];

// 관리자 권한 확인 (실제 운영 시에는 더 엄격한 권한 확인 필요)
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || $user['role'] !== 'admin') {
    die('관리자 권한이 필요합니다.');
}

echo "<h2>계약서 파일명 마이그레이션</h2>";

// 한글이 포함된 파일명을 가진 계약서 조회
$stmt = $pdo->prepare("
    SELECT c.id, c.contract_file, p.address
    FROM contracts c
    JOIN properties p ON c.property_id = p.id
    WHERE c.contract_file IS NOT NULL 
    AND c.contract_file != ''
    AND c.contract_file LIKE '%가%'
");
$stmt->execute();
$contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($contracts)) {
    echo "<p>한글이 포함된 파일명을 가진 계약서가 없습니다.</p>";
    exit;
}

echo "<p>총 " . count($contracts) . "개의 계약서 파일명을 변경합니다.</p>";

$success_count = 0;
$error_count = 0;

foreach ($contracts as $contract) {
    $old_file_path = $contract['contract_file'];
    
    // 파일이 존재하는지 확인
    if (!file_exists($old_file_path)) {
        echo "<p style='color: red;'>❌ 파일이 존재하지 않음: {$old_file_path}</p>";
        $error_count++;
        continue;
    }
    
    // 새 파일명 생성
    $file_extension = strtolower(pathinfo($old_file_path, PATHINFO_EXTENSION));
    $random_string = bin2hex(random_bytes(8));
    $current_date = date('Ymd');
    
    $new_file_name = sprintf('contract_%03d_%s_%s.%s', 
        $contract['id'], 
        $random_string,
        $current_date, 
        $file_extension
    );
    
    $new_file_path = 'contracts/' . $new_file_name;
    
    // 파일명 중복 체크
    $counter = 1;
    $original_new_file_path = $new_file_path;
    while (file_exists($new_file_path)) {
        $path_info = pathinfo($original_new_file_path);
        $new_file_name = sprintf('%s_%d.%s', 
            $path_info['filename'], 
            $counter, 
            $path_info['extension']
        );
        $new_file_path = 'contracts/' . $new_file_name;
        $counter++;
    }
    
    // 파일명 변경
    if (rename($old_file_path, $new_file_path)) {
        // 데이터베이스 업데이트
        $stmt = $pdo->prepare("UPDATE contracts SET contract_file = ? WHERE id = ?");
        $stmt->execute([$new_file_path, $contract['id']]);
        
        echo "<p style='color: green;'>✅ 성공: 계약 #{$contract['id']} ({$contract['address']})</p>";
        echo "<p style='margin-left: 20px;'>   {$old_file_path} → {$new_file_path}</p>";
        $success_count++;
    } else {
        echo "<p style='color: red;'>❌ 실패: 계약 #{$contract['id']} ({$contract['address']})</p>";
        echo "<p style='margin-left: 20px;'>   {$old_file_path}</p>";
        $error_count++;
    }
}

echo "<hr>";
echo "<h3>마이그레이션 완료</h3>";
echo "<p>성공: {$success_count}개</p>";
echo "<p>실패: {$error_count}개</p>";

if ($success_count > 0) {
    echo "<p style='color: green;'><strong>마이그레이션이 완료되었습니다. 이제 계약서 파일이 정상적으로 표시될 것입니다.</strong></p>";
}
?> 