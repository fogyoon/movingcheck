<?php
require_once 'config.inc';

// PDO 객체 반환 함수
function get_pdo() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8', DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $pdo;
        } catch (PDOException $e) {
            return 'DB 연결 실패: ' . $e->getMessage();
        }
    }
    return $pdo;
}

// 폴더 내 모든 파일을 삭제하는 함수
function delete_folder_files($folder_path) {
    $deleted_count = 0;
    if (!is_dir($folder_path)) {
        return $deleted_count;
    }
    
    $files = glob($folder_path . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            if (unlink($file)) {
                $deleted_count++;
            }
        }
    }
    return $deleted_count;
}

// 모든 테이블을 삭제 후 새로 생성하는 함수
function create_all_tables() {
    $pdo = get_pdo();
    if (is_string($pdo)) {
        return $pdo; // DB 연결 오류 메시지 반환
    }

    // 테이블 삭제 전에 관련 파일들 삭제
    $contracts_deleted = delete_folder_files('contracts');
    $photos_deleted = delete_folder_files('photos');
    $signatures_deleted = delete_folder_files('signatures');
    
    $file_deletion_msg = '';
    if ($contracts_deleted > 0 || $photos_deleted > 0 || $signatures_deleted > 0) {
        $file_deletion_msg = "\n파일 삭제: 계약서 {$contracts_deleted}개, 사진 {$photos_deleted}개, 서명 {$signatures_deleted}개";
    }

    try {
        $sql = "
        SET FOREIGN_KEY_CHECKS=0;
        DROP TABLE IF EXISTS signatures;
        DROP TABLE IF EXISTS photos;
        DROP TABLE IF EXISTS contracts;
        DROP TABLE IF EXISTS properties;
        DROP TABLE IF EXISTS users;
        DROP TABLE IF EXISTS user_activities;
        SET FOREIGN_KEY_CHECKS=1;

        CREATE TABLE users (
          id INT AUTO_INCREMENT PRIMARY KEY,
          login_id VARCHAR(64) UNIQUE,
          email VARCHAR(128),
          nickname VARCHAR(64),
          phone VARCHAR(32),
          password VARCHAR(255),
          usergroup ENUM('admin','subadmin','user') DEFAULT 'user',
          login_by VARCHAR(32) DEFAULT 'kakao',
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE properties (
          id INT AUTO_INCREMENT PRIMARY KEY,
          address VARCHAR(255),
          detail_address VARCHAR(128),
          category VARCHAR(32),
          description TEXT,
          is_public BOOLEAN DEFAULT FALSE,
          is_admin_created BOOLEAN DEFAULT FALSE,
          created_by INT,
          created_ip VARCHAR(45),
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
          updated_ip VARCHAR(45) DEFAULT NULL,
          FOREIGN KEY (created_by) REFERENCES users(id)
        );

        CREATE TABLE contracts (
          id INT AUTO_INCREMENT PRIMARY KEY,
          property_id INT,
          user_id INT, -- 등록자
          landlord_id INT, -- 임대인 아이디
          tenant_id INT, -- 임차인 아이디  
          agent_id INT, -- 중개사 아이디
          landlord_name VARCHAR(64),
          tenant_name VARCHAR(64),
          agent_name VARCHAR(64),
          landlord_phone VARCHAR(32),
          tenant_phone VARCHAR(32),
          agent_phone VARCHAR(32),
          start_date DATE,
          end_date DATE,
          deposit BIGINT,
          monthly_rent BIGINT,
          contract_file VARCHAR(255),
          -- 각 서명 단계별 보안 키와 비밀번호
          movein_landlord_key VARCHAR(255),
          movein_landlord_password VARCHAR(255),
          movein_tenant_key VARCHAR(255),
          movein_tenant_password VARCHAR(255),
          moveout_landlord_key VARCHAR(255),
          moveout_landlord_password VARCHAR(255),
          moveout_tenant_key VARCHAR(255),
          moveout_tenant_password VARCHAR(255),
          status ENUM('empty','movein_photo','movein_landlord_signed','movein_tenant_signed','moveout_photo','moveout_landlord_signed','moveout_tenant_signed','in_repair','finished') DEFAULT 'empty',
          movein_landlord_request_sent_at DATETIME DEFAULT NULL,
          movein_tenant_request_sent_at DATETIME DEFAULT NULL,
          moveout_landlord_request_sent_at DATETIME DEFAULT NULL,
          moveout_tenant_request_sent_at DATETIME DEFAULT NULL,
          created_by INT,
          created_ip VARCHAR(45),
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          FOREIGN KEY (property_id) REFERENCES properties(id),
          FOREIGN KEY (user_id) REFERENCES users(id),
          FOREIGN KEY (landlord_id) REFERENCES users(id),
          FOREIGN KEY (tenant_id) REFERENCES users(id),
          FOREIGN KEY (agent_id) REFERENCES users(id),
          FOREIGN KEY (created_by) REFERENCES users(id)
        );

        CREATE TABLE photos (
          id INT AUTO_INCREMENT PRIMARY KEY,
          contract_id INT,
          uploader_id INT,
          part VARCHAR(64),
          description TEXT,
          -- 입주 사진 6개
          movein_shot_type_01 ENUM('overview','closeup') DEFAULT 'overview',
          movein_file_path_01 VARCHAR(255),
          movein_meta_data_01 TEXT,
          movein_shot_type_02 ENUM('overview','closeup') DEFAULT 'overview',
          movein_file_path_02 VARCHAR(255),
          movein_meta_data_02 TEXT,
          movein_shot_type_03 ENUM('overview','closeup') DEFAULT 'overview',
          movein_file_path_03 VARCHAR(255),
          movein_meta_data_03 TEXT,
          movein_shot_type_04 ENUM('overview','closeup') DEFAULT 'overview',
          movein_file_path_04 VARCHAR(255),
          movein_meta_data_04 TEXT,
          movein_shot_type_05 ENUM('overview','closeup') DEFAULT 'overview',
          movein_file_path_05 VARCHAR(255),
          movein_meta_data_05 TEXT,
          movein_shot_type_06 ENUM('overview','closeup') DEFAULT 'overview',
          movein_file_path_06 VARCHAR(255),
          movein_meta_data_06 TEXT,
          -- 퇴거 사진 6개
          moveout_shot_type_01 ENUM('overview','closeup') DEFAULT 'overview',
          moveout_file_path_01 VARCHAR(255),
          moveout_meta_data_01 TEXT,
          moveout_shot_type_02 ENUM('overview','closeup') DEFAULT 'overview',
          moveout_file_path_02 VARCHAR(255),
          moveout_meta_data_02 TEXT,
          moveout_shot_type_03 ENUM('overview','closeup') DEFAULT 'overview',
          moveout_file_path_03 VARCHAR(255),
          moveout_meta_data_03 TEXT,
          moveout_shot_type_04 ENUM('overview','closeup') DEFAULT 'overview',
          moveout_file_path_04 VARCHAR(255),
          moveout_meta_data_04 TEXT,
          moveout_shot_type_05 ENUM('overview','closeup') DEFAULT 'overview',
          moveout_file_path_05 VARCHAR(255),
          moveout_meta_data_05 TEXT,
          moveout_shot_type_06 ENUM('overview','closeup') DEFAULT 'overview',
          moveout_file_path_06 VARCHAR(255),
          moveout_meta_data_06 TEXT,
          taken_at DATETIME,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          uploader_ip VARCHAR(45),
          FOREIGN KEY (contract_id) REFERENCES contracts(id),
          FOREIGN KEY (uploader_id) REFERENCES users(id)
        );

        CREATE TABLE signatures (
          id INT AUTO_INCREMENT PRIMARY KEY,
          contract_id INT,
          signer_role ENUM('landlord','tenant','agent') NOT NULL,
          purpose ENUM('movein','moveout') NOT NULL,
          signer_name VARCHAR(64),
          signer_phone VARCHAR(32),
          signed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          signature_data TEXT,
          signer_ip VARCHAR(45),
          mismatch_confirmed BOOLEAN DEFAULT FALSE,
          FOREIGN KEY (contract_id) REFERENCES contracts(id)
        );



        CREATE TABLE user_activities (
          id INT AUTO_INCREMENT PRIMARY KEY,
          user_id INT,
          login_id VARCHAR(64),
          contract_id INT,
          property_id INT,
          activity_type ENUM('login','logout','create_property','update_property','delete_property','create_contract','update_contract','delete_contract','upload_photo','delete_photo','create_signature','view_property','view_contract','other') NOT NULL,
          description TEXT,
          ip_address VARCHAR(45),
          user_agent TEXT,
          session_id VARCHAR(255),
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
          FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE SET NULL,
          FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE SET NULL
        );
        ";
        foreach (explode(';', $sql) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt) {
                $pdo->exec($stmt);
            }
        }
        return 'DB 테이블이 성공적으로 초기화되었습니다!' . $file_deletion_msg;
    } catch (PDOException $e) {
        return '테이블 생성 실패: ' . $e->getMessage();
    }
}

// 여기에 SQL 관련 함수들을 추가하세요. 

// 샘플 데이터 생성 함수
function create_sample_data() {
    $pdo = get_pdo();
    if (is_string($pdo)) {
        return $pdo; // DB 연결 오류 메시지 반환
    }

    try {
        // 1. 사용자 데이터 생성 (4명: 일반 사용자)
        $users_sql = "
        INSERT INTO users (login_id, email, nickname, phone, usergroup, login_by) VALUES
        ('user_001', 'kim@example.com', '김사용자', '010-1234-5678', 'user', 'kakao'),
        ('user_002', 'park@example.com', '박사용자', '010-2345-6789', 'user', 'kakao'),
        ('user_003', 'lee@example.com', '이사용자', '010-3456-7890', 'user', 'kakao'),
        ('user_004', 'choi@example.com', '최사용자', '010-5678-1234', 'user', 'kakao')
        ";
        $pdo->exec($users_sql);

        // 관리자 계정 생성 (비밀번호: monetcom)
        $admin_password = password_hash('monetcom', PASSWORD_DEFAULT);
        $admin_sql = "INSERT INTO users (login_id, email, nickname, password, usergroup, login_by) VALUES (?, ?, ?, ?, ?, ?)";
        $admin_stmt = $pdo->prepare($admin_sql);
        $admin_stmt->execute(['admin', 'admin@movingcheck.kr', '관리자', $admin_password, 'admin', 'email']);

        // 2. 임대물 데이터 생성 (사용자들이 생성)
        $properties_sql = "
        INSERT INTO properties (address, detail_address, description, is_public, is_admin_created, created_by, created_ip) VALUES
        ('서울시 강남구 테헤란로 123', '456동 789호', '강남역 근처 신축 아파트, 역세권, 편의시설 완비', TRUE, FALSE, 1, '127.0.0.1'),
        ('서울시 서초구 서초대로 456', '101동 202호', '서초구 중심가 오피스텔, 사무실로 활용 가능', TRUE, FALSE, 2, '127.0.0.1'),
        ('서울시 마포구 홍대로 789', '303동 404호', '홍대입구 근처 원룸, 학생들이 선호하는 위치', FALSE, FALSE, 2, '127.0.0.1')
        ";
        $pdo->exec($properties_sql);

        // 3. 계약 데이터 생성 (사용자들이 다양한 역할로 계약 참여)
        $contracts_sql = "
        INSERT INTO contracts (property_id, user_id, landlord_id, tenant_id, agent_id, landlord_name, tenant_name, agent_name, landlord_phone, tenant_phone, agent_phone, start_date, end_date, deposit, monthly_rent, contract_file, status, created_by, created_ip) VALUES
        (1, 1, 1, 4, 2, '김사용자', '최사용자', '박사용자', '010-1234-5678', '010-5678-1234', '010-2345-6789', '2024-01-01', '2024-12-31', 10000000, 800000, NULL, 'empty', 1, '127.0.0.1'),
        (2, 2, 2, 3, 1, '박사용자', '이사용자', '김사용자', '010-2345-6789', '010-3456-7890', '010-1234-5678', '2024-04-01', '2025-03-31', 12000000, 900000, NULL, 'empty', 2, '127.0.0.1'),
        (3, 3, 4, 1, 2, '최사용자', '김사용자', '박사용자', '010-5678-1234', '010-1234-5678', '010-2345-6789', '2024-05-01', '2025-04-30', 11000000, 850000, NULL, 'empty', 3, '127.0.0.1')
        ";
        $pdo->exec($contracts_sql);

        return '샘플 데이터가 성공적으로 생성되었습니다! (사용자 5명, 임대물 3개, 계약 3개)';
    } catch (PDOException $e) {
        return '샘플 데이터 생성 실패: ' . $e->getMessage();
    }
}

// 사용자 활동 기록 함수
function log_user_activity($user_id, $activity_type, $description = '', $contract_id = null, $property_id = null, $session_id = null) {
    $pdo = get_pdo();
    if (is_string($pdo)) {
        return false; // DB 연결 오류
    }

    try {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if ($session_id === null) {
            $session_id = session_id();
        }
        // login_id 조회
        $login_id = null;
        if ($user_id) {
            $stmt = $pdo->prepare("SELECT login_id FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) $login_id = $row['login_id'];
        }
        $sql = "INSERT INTO user_activities (user_id, login_id, contract_id, property_id, activity_type, description, ip_address, user_agent, session_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id, $login_id, $contract_id, $property_id, $activity_type, $description, $ip_address, $user_agent, $session_id]);
        return true;
    } catch (PDOException $e) {
        error_log('사용자 활동 기록 실패: ' . $e->getMessage());
        return false;
    }
}

// 로그인 활동 기록
function log_login($user_id, $description = '카카오 로그인') {
    return log_user_activity($user_id, 'login', $description);
}

// 로그아웃 활동 기록
function log_logout($user_id, $description = '로그아웃') {
    return log_user_activity($user_id, 'logout', $description);
}

// 사용자 활동 조회 함수
function get_user_activities($user_id = null, $limit = 50) {
    $pdo = get_pdo();
    if (is_string($pdo)) {
        return [];
    }

    try {
        if ($user_id) {
            $sql = "SELECT ua.*, u.nickname, c.id as contract_ref_id FROM user_activities ua 
                    LEFT JOIN users u ON ua.user_id = u.id 
                    LEFT JOIN contracts c ON ua.contract_id = c.id
                    WHERE ua.user_id = ? 
                    ORDER BY ua.created_at DESC 
                    LIMIT ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$user_id, $limit]);
        } else {
            $sql = "SELECT ua.*, u.nickname, c.id as contract_ref_id FROM user_activities ua 
                    LEFT JOIN users u ON ua.user_id = u.id 
                    LEFT JOIN contracts c ON ua.contract_id = c.id
                    ORDER BY ua.created_at DESC 
                    LIMIT ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$limit]);
        }
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('사용자 활동 조회 실패: ' . $e->getMessage());
        return [];
    }
}

// IP 주소별 활동 조회
function get_activities_by_ip($ip_address, $limit = 50) {
    $pdo = get_pdo();
    if (is_string($pdo)) {
        return [];
    }

    try {
        $sql = "SELECT ua.*, u.nickname, c.id as contract_ref_id FROM user_activities ua 
                LEFT JOIN users u ON ua.user_id = u.id 
                LEFT JOIN contracts c ON ua.contract_id = c.id
                WHERE ua.ip_address = ? 
                ORDER BY ua.created_at DESC 
                LIMIT ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$ip_address, $limit]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('IP별 활동 조회 실패: ' . $e->getMessage());
        return [];
    }
}

// =============================================
// SQL 인젝션 방지 및 입력 검증 함수들
// =============================================

/**
 * 입력값 검증 및 정제 함수
 * @param mixed $input 입력값
 * @param string $type 데이터 타입 ('int', 'string', 'email', 'phone', 'date', 'url')
 * @param int $max_length 최대 길이 (string 타입만)
 * @return mixed 검증된 값 또는 false
 */
function validate_input($input, $type = 'string', $max_length = null) {
    // null 또는 빈 값 처리
    if ($input === null || $input === '') {
        return '';
    }
    
    switch ($type) {
        case 'int':
            // 정수 타입 검증
            if (!is_numeric($input) || (int)$input != $input) {
                return false;
            }
            return (int)$input;
            
        case 'email':
            // 이메일 타입 검증
            $email = filter_var(trim($input), FILTER_VALIDATE_EMAIL);
            if ($email === false) {
                return false;
            }
            return $max_length ? substr($email, 0, $max_length) : $email;
            
        case 'phone':
            // 전화번호 타입 검증 (한국 전화번호 형식)
            $phone = preg_replace('/[^0-9-]/', '', trim($input));
            if (!preg_match('/^(010|011|016|017|018|019)-?[0-9]{3,4}-?[0-9]{4}$/', $phone)) {
                return false;
            }
            return $phone;
            
        case 'date':
            // 날짜 타입 검증 (YYYY-MM-DD 형식)
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($input))) {
                return false;
            }
            $date = DateTime::createFromFormat('Y-m-d', trim($input));
            if (!$date || $date->format('Y-m-d') !== trim($input)) {
                return false;
            }
            return trim($input);
            
        case 'url':
            // URL 타입 검증
            $url = filter_var(trim($input), FILTER_VALIDATE_URL);
            if ($url === false) {
                return false;
            }
            return $max_length ? substr($url, 0, $max_length) : $url;
            
        default: // 'string'
            // 문자열 타입 검증 및 정제
            $string = trim((string)$input);
            
            // XSS 방지를 위한 HTML 태그 제거 (기본 HTML 태그 허용하지 않음)
            $string = strip_tags($string);
            
            // 길이 제한 적용
            if ($max_length && mb_strlen($string, 'UTF-8') > $max_length) {
                $string = mb_substr($string, 0, $max_length, 'UTF-8');
            }
            
            return $string;
    }
}

/**
 * 안전한 정수 추출
 * @param mixed $input 입력값
 * @param int $default 기본값
 * @return int 검증된 정수값
 */
function safe_int($input, $default = 0) {
    $result = validate_input($input, 'int');
    return $result !== false ? $result : $default;
}

/**
 * 안전한 문자열 추출  
 * @param mixed $input 입력값
 * @param int $max_length 최대 길이
 * @param string $default 기본값
 * @return string 검증된 문자열
 */
function safe_string($input, $max_length = null, $default = '') {
    $result = validate_input($input, 'string', $max_length);
    return $result !== false ? $result : $default;
}

/**
 * 안전한 이메일 추출
 * @param mixed $input 입력값
 * @param string $default 기본값
 * @return string 검증된 이메일 또는 기본값
 */
function safe_email($input, $default = '') {
    $result = validate_input($input, 'email', 128);
    return $result !== false ? $result : $default;
}

/**
 * 안전한 전화번호 추출
 * @param mixed $input 입력값
 * @param string $default 기본값
 * @return string 검증된 전화번호 또는 기본값
 */
function safe_phone($input, $default = '') {
    $result = validate_input($input, 'phone');
    return $result !== false ? $result : $default;
}

/**
 * 안전한 날짜 추출
 * @param mixed $input 입력값
 * @param string $default 기본값
 * @return string 검증된 날짜 또는 기본값
 */
function safe_date($input, $default = '') {
    $result = validate_input($input, 'date');
    return $result !== false ? $result : $default;
}

/**
 * SQL 인젝션 방지를 위한 LIKE 패턴 생성
 * @param string $search 검색어
 * @return string 안전한 LIKE 패턴
 */
function safe_like_pattern($search) {
    // 특수문자 이스케이프
    $escaped = str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], trim($search));
    return '%' . $escaped . '%';
}

/**
 * 배열 값들의 타입 검증
 * @param array $array 검증할 배열
 * @param string $type 데이터 타입
 * @return array 검증된 배열
 */
function validate_array($array, $type = 'string') {
    if (!is_array($array)) {
        return [];
    }
    
    $validated = [];
    foreach ($array as $key => $value) {
        $validated_value = validate_input($value, $type);
        if ($validated_value !== false) {
            $validated[$key] = $validated_value;
        }
    }
    
    return $validated;
}

/**
 * IN 절을 위한 안전한 플레이스홀더 생성
 * @param array $values 값 배열
 * @return string 플레이스홀더 문자열 ("?,?,?")
 */
function safe_in_placeholders($values) {
    if (!is_array($values) || empty($values)) {
        return '';
    }
    return str_repeat('?,', count($values) - 1) . '?';
}

/**
 * SQL 주입 공격 패턴 검사
 * @param string $input 검사할 문자열
 * @return bool 위험한 패턴이 발견되면 true
 */
function detect_sql_injection($input) {
    if (!is_string($input)) {
        return false;
    }
    
    // 위험한 SQL 키워드 패턴
    $dangerous_patterns = [
        '/(\s|^)(union|select|insert|update|delete|drop|create|alter|exec|execute)\s/i',
        '/(\s|^)(or|and)\s+[\'\"]?\d+[\'\"]?\s*=\s*[\'\"]?\d+[\'\"]?/i',
        '/[\'\"][\s]*;[\s]*\w+/i',
        '/[\'\"][\s]*(\||&){2}/i',
        '/<script[^>]*>.*?<\/script>/is',
        '/javascript:/i',
        '/vbscript:/i',
        '/onload|onerror|onclick/i'
    ];
    
    foreach ($dangerous_patterns as $pattern) {
        if (preg_match($pattern, $input)) {
            return true;
        }
    }
    
    return false;
}

/**
 * 통합 입력 검증 함수
 * @param array $data $_POST 또는 $_GET 데이터
 * @param array $rules 검증 규칙 ['field_name' => ['type' => 'string', 'max_length' => 100, 'required' => true]]
 * @return array ['valid' => bool, 'data' => array, 'errors' => array]
 */
function validate_form_data($data, $rules) {
    $validated_data = [];
    $errors = [];
    $is_valid = true;
    
    foreach ($rules as $field => $rule) {
        $type = $rule['type'] ?? 'string';
        $max_length = $rule['max_length'] ?? null;
        $required = $rule['required'] ?? false;
        $default = $rule['default'] ?? '';
        
        $value = $data[$field] ?? null;
        
        // 필수 필드 체크
        if ($required && ($value === null || $value === '')) {
            $errors[$field] = "{$field}은(는) 필수 입력 항목입니다.";
            $is_valid = false;
            continue;
        }
        
        // 값이 없으면 기본값 사용
        if ($value === null || $value === '') {
            $validated_data[$field] = $default;
            continue;
        }
        
        // SQL 인젝션 패턴 검사
        if (detect_sql_injection($value)) {
            $errors[$field] = "{$field}에 허용되지 않는 문자가 포함되어 있습니다.";
            $is_valid = false;
            continue;
        }
        
        // 타입별 검증
        $validated_value = validate_input($value, $type, $max_length);
        
        if ($validated_value === false) {
            $errors[$field] = "{$field}의 형식이 올바르지 않습니다.";
            $is_valid = false;
        } else {
            $validated_data[$field] = $validated_value;
        }
    }
    
    return [
        'valid' => $is_valid,
        'data' => $validated_data,
        'errors' => $errors
    ];
}

/**
 * 계약과 관련된 모든 데이터를 삭제하는 함수
 * @param int $contract_id 삭제할 계약 ID
 * @return array ['success' => bool, 'deleted_files' => int, 'deleted_signatures' => int, 'deleted_photos' => int, 'message' => string]
 */
function delete_contract_with_related_data($contract_id) {
    $pdo = get_pdo();
    if (is_string($pdo)) {
        return ['success' => false, 'message' => $pdo];
    }
    
    try {
        // 트랜잭션 시작
        $pdo->beginTransaction();
        
        $deleted_files = 0;
        $deleted_signatures = 0;
        $deleted_photos = 0;
        
        // 1. 사진 파일들 물리적 삭제를 위해 파일 경로 수집
        $photo_paths = [];
        $stmt = $pdo->prepare('SELECT * FROM photos WHERE contract_id = ?');
        $stmt->execute([$contract_id]);
        $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($photos as $photo) {
            // 입주 사진 파일 경로들 수집
            for ($i = 1; $i <= 6; $i++) {
                $file_path = $photo["movein_file_path_0{$i}"];
                if (!empty($file_path) && file_exists($file_path)) {
                    $photo_paths[] = $file_path;
                }
            }
            
            // 퇴거 사진 파일 경로들 수집  
            for ($i = 1; $i <= 6; $i++) {
                $file_path = $photo["moveout_file_path_0{$i}"];
                if (!empty($file_path) && file_exists($file_path)) {
                    $photo_paths[] = $file_path;
                }
            }
        }
        
        // 2. 물리적 사진 파일들 삭제
        foreach ($photo_paths as $file_path) {
            if (unlink($file_path)) {
                $deleted_files++;
            }
        }
        
        // 3. signatures 테이블에서 관련 레코드 삭제
        $stmt = $pdo->prepare('DELETE FROM signatures WHERE contract_id = ?');
        $stmt->execute([$contract_id]);
        $deleted_signatures = $stmt->rowCount();
        
        // 4. photos 테이블에서 관련 레코드 삭제  
        $stmt = $pdo->prepare('DELETE FROM photos WHERE contract_id = ?');
        $stmt->execute([$contract_id]);
        $deleted_photos = $stmt->rowCount();
        
        // 5. contracts 테이블에서 계약 삭제
        $stmt = $pdo->prepare('DELETE FROM contracts WHERE id = ?');
        $stmt->execute([$contract_id]);
        $deleted_contracts = $stmt->rowCount();
        
        // 트랜잭션 커밋
        $pdo->commit();
        
        if ($deleted_contracts > 0) {
            return [
                'success' => true,
                'deleted_files' => $deleted_files,
                'deleted_signatures' => $deleted_signatures, 
                'deleted_photos' => $deleted_photos,
                'message' => "계약 ID {$contract_id}와 관련된 모든 데이터가 성공적으로 삭제되었습니다. (사진파일: {$deleted_files}개, 서명: {$deleted_signatures}개, 사진레코드: {$deleted_photos}개)"
            ];
        } else {
            return [
                'success' => false,
                'deleted_files' => 0,
                'deleted_signatures' => 0,
                'deleted_photos' => 0,
                'message' => "계약 ID {$contract_id}를 찾을 수 없습니다."
            ];
        }
        
    } catch (PDOException $e) {
        // 트랜잭션 롤백
        $pdo->rollBack();
        return [
            'success' => false,
            'deleted_files' => 0,
            'deleted_signatures' => 0, 
            'deleted_photos' => 0,
            'message' => '계약 삭제 중 오류가 발생했습니다: ' . $e->getMessage()
        ];
    }
}

/**
 * 여러 계약을 한번에 삭제하는 함수
 * @param array $contract_ids 삭제할 계약 ID 배열
 * @return array ['success' => bool, 'total_deleted_files' => int, 'total_deleted_signatures' => int, 'total_deleted_photos' => int, 'results' => array, 'message' => string]
 */
function delete_multiple_contracts($contract_ids) {
    if (!is_array($contract_ids) || empty($contract_ids)) {
        return [
            'success' => false,
            'total_deleted_files' => 0,
            'total_deleted_signatures' => 0,
            'total_deleted_photos' => 0,
            'results' => [],
            'message' => '삭제할 계약 ID가 제공되지 않았습니다.'
        ];
    }
    
    $results = [];
    $total_deleted_files = 0;
    $total_deleted_signatures = 0;
    $total_deleted_photos = 0;
    $success_count = 0;
    
    foreach ($contract_ids as $contract_id) {
        $result = delete_contract_with_related_data($contract_id);
        $results[] = $result;
        
        if ($result['success']) {
            $success_count++;
            $total_deleted_files += $result['deleted_files'];
            $total_deleted_signatures += $result['deleted_signatures'];
            $total_deleted_photos += $result['deleted_photos'];
        }
    }
    
    $total_count = count($contract_ids);
    $overall_success = $success_count === $total_count;
    
    return [
        'success' => $overall_success,
        'total_deleted_files' => $total_deleted_files,
        'total_deleted_signatures' => $total_deleted_signatures,
        'total_deleted_photos' => $total_deleted_photos,
        'results' => $results,
        'message' => "{$total_count}개 계약 중 {$success_count}개가 성공적으로 삭제되었습니다. (총 사진파일: {$total_deleted_files}개, 서명: {$total_deleted_signatures}개, 사진레코드: {$total_deleted_photos}개)"
    ];
}

?>