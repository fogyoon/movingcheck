<?php
/**
 * 파일 보안 관련 헬퍼 함수들
 */

// 파일명을 안전하게 암호화하는 함수
function generate_secure_filename($original_name, $contract_id) {
    $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    $timestamp = time();
    $random = bin2hex(random_bytes(8)); // 16자리 랜덤 문자열
    
    // 계약 ID와 타임스탬프, 랜덤값을 조합하여 고유한 파일명 생성
    $hash = hash('sha256', $contract_id . $timestamp . $random . SECRET_KEY);
    
    return sprintf('%s_%s.%s', $hash, $random, $extension);
}

// 파일 경로가 안전한지 검증하는 함수
function is_safe_file_path($file_path) {
    // 파일 경로가 비어있는지 확인
    if (empty($file_path)) {
        return false;
    }
    
    // 절대 경로로 변환
    $real_path = realpath($file_path);
    $contracts_dir = realpath('contracts/');
    
    // contracts 디렉토리 내의 파일인지 확인
    if (!$real_path || !$contracts_dir || strpos($real_path, $contracts_dir) !== 0) {
        error_log("File path validation failed: real_path=$real_path, contracts_dir=$contracts_dir");
        return false;
    }
    
    // 허용된 확장자만 확인
    $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png'];
    $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    
    if (!in_array($extension, $allowed_extensions)) {
        error_log("File extension not allowed: $extension");
        return false;
    }
    
    // 파일명에 특수문자나 한글이 포함되어 있는지 확인
    $filename = basename($file_path);
    if (preg_match('/[^a-zA-Z0-9._-]/', $filename)) {
        error_log("Filename contains invalid characters: $filename");
        return false;
    }
    
    return true;
}

// 파일 접근 권한을 확인하는 함수
function can_access_contract_file($user_id, $contract_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count
        FROM contracts c
        JOIN properties p ON c.property_id = p.id
        WHERE c.id = ? AND p.created_by = ?
    ");
    $stmt->execute([$contract_id, $user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result['count'] > 0;
}

// 파일 다운로드 로그를 기록하는 함수
function log_file_access($user_id, $contract_id, $action = 'view') {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO file_access_logs (user_id, contract_id, action, ip_address, access_time, user_agent)
            VALUES (?, ?, ?, ?, NOW(), ?)
        ");
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $stmt->execute([$user_id, $contract_id, $action, $_SERVER['REMOTE_ADDR'], $user_agent]);
    } catch (PDOException $e) {
        // 테이블이 없거나 오류가 발생해도 계속 진행
        // 실제 운영 환경에서는 로그를 기록하거나 관리자에게 알림
        error_log("File access log error: " . $e->getMessage());
    }
}

// 안전한 파일 출력 함수
function output_file_safely($file_path, $mime_type) {
    if (!file_exists($file_path)) {
        http_response_code(404);
        die('파일을 찾을 수 없습니다.');
    }
    
    $file_size = filesize($file_path);
    if ($file_size === false || $file_size > 50 * 1024 * 1024) { // 50MB 제한
        http_response_code(413);
        die('파일이 너무 큽니다.');
    }
    
    // 보안 헤더 설정
    header('Content-Type: ' . $mime_type);
    header('Content-Length: ' . $file_size);
    header('Content-Disposition: inline; filename="' . basename($file_path) . '"');
    header('Cache-Control: private, max-age=3600'); // 1시간 캐시
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    
    // 파일 내용 직접 출력
    readfile($file_path);
}
?> 