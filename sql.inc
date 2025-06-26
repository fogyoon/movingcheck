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

// 모든 테이블을 삭제 후 새로 생성하는 함수
function create_all_tables() {
    $pdo = get_pdo();
    if (is_string($pdo)) {
        return $pdo; // DB 연결 오류 메시지 반환
    }

    try {
        $sql = "
        SET FOREIGN_KEY_CHECKS=0;
        DROP TABLE IF EXISTS photo_comparisons;
        DROP TABLE IF EXISTS damage_reports;
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
          role ENUM('landlord','tenant','agent','admin') DEFAULT 'tenant',
          login_by VARCHAR(32) DEFAULT 'kakao',
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE properties (
          id INT AUTO_INCREMENT PRIMARY KEY,
          address VARCHAR(255),
          detail_address VARCHAR(128),
          description TEXT,
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
          landlord_id INT,
          tenant_id INT,
          agent_id INT,
          landlord_phone VARCHAR(32),
          tenant_phone VARCHAR(32),
          agent_phone VARCHAR(32),
          start_date DATE,
          end_date DATE,
          deposit BIGINT,
          monthly_rent BIGINT,
          contract_file VARCHAR(255),
          status ENUM('empty','movein_photo','movein_landlord_signed','movein_tenant_signed','moveout_photo','moveout_landlord_signed','moveout_tenant_signed','in_repair','finished') DEFAULT 'empty',
          created_by INT,
          created_ip VARCHAR(45),
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          FOREIGN KEY (property_id) REFERENCES properties(id),
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
          -- 입주 사진 7개
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
          movein_shot_type_07 ENUM('overview','closeup') DEFAULT 'overview',
          movein_file_path_07 VARCHAR(255),
          movein_meta_data_07 TEXT,
          -- 퇴거 사진 7개
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
          moveout_shot_type_07 ENUM('overview','closeup') DEFAULT 'overview',
          moveout_file_path_07 VARCHAR(255),
          moveout_meta_data_07 TEXT,
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
          signed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          signature_data TEXT,
          signer_ip VARCHAR(45),
          FOREIGN KEY (contract_id) REFERENCES contracts(id)
        );

        CREATE TABLE damage_reports (
          id INT AUTO_INCREMENT PRIMARY KEY,
          contract_id INT,
          photo_id INT,
          description TEXT,
          status ENUM('requested','in_progress','completed') DEFAULT 'requested',
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          FOREIGN KEY (contract_id) REFERENCES contracts(id),
          FOREIGN KEY (photo_id) REFERENCES photos(id)
        );

        CREATE TABLE photo_comparisons (
          id INT AUTO_INCREMENT PRIMARY KEY,
          contract_id INT,
          before_photo INT,
          after_photo INT,
          result TEXT,
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          FOREIGN KEY (contract_id) REFERENCES contracts(id),
          FOREIGN KEY (before_photo) REFERENCES photos(id),
          FOREIGN KEY (after_photo) REFERENCES photos(id)
        );

        CREATE TABLE user_activities (
          id INT AUTO_INCREMENT PRIMARY KEY,
          user_id INT,
          login_id VARCHAR(64),
          activity_type ENUM('login','logout','create_property','update_property','delete_property','create_contract','update_contract','delete_contract','upload_photo','delete_photo','create_signature','create_damage_report','update_damage_report','view_property','view_contract','other') NOT NULL,
          description TEXT,
          ip_address VARCHAR(45),
          user_agent TEXT,
          session_id VARCHAR(255),
          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
          FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        );
        ";
        foreach (explode(';', $sql) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt) {
                $pdo->exec($stmt);
            }
        }
        return 'DB 테이블이 성공적으로 초기화되었습니다!';
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
        // 1. 사용자 데이터 생성 (4명: landlord 1명, agent 2명, tenant 1명)
        $users_sql = "
        INSERT INTO users (login_id, email, nickname, phone, role, login_by) VALUES
        ('landlord_001', 'landlord@example.com', '김임대인', '010-1234-5678', 'landlord', 'kakao'),
        ('agent_001', 'agent1@example.com', '박부동산', '010-2345-6789', 'agent', 'kakao'),
        ('agent_002', 'agent2@example.com', '이중개', '010-3456-7890', 'agent', 'kakao'),
        ('tenant_001', 'tenant1@example.com', '최임차인', '010-5678-1234', 'tenant', 'kakao')
        ";
        $pdo->exec($users_sql);

        // 관리자 계정 생성 (비밀번호: monetcom)
        $admin_password = password_hash('monetcom', PASSWORD_DEFAULT);
        $admin_sql = "INSERT INTO users (login_id, email, nickname, password, role, login_by) VALUES (?, ?, ?, ?, ?, ?)";
        $admin_stmt = $pdo->prepare($admin_sql);
        $admin_stmt->execute(['admin', 'admin@movingcheck.kr', '관리자', $admin_password, 'admin', 'email']);

        // 2. 임대물 데이터 생성 (landlord가 1개, 첫번째 agent가 2개)
        $properties_sql = "
        INSERT INTO properties (address, detail_address, description, created_by, created_ip) VALUES
        ('서울시 강남구 테헤란로 123', '456동 789호', '강남역 근처 신축 아파트, 역세권, 편의시설 완비', 1, '127.0.0.1'),
        ('서울시 서초구 서초대로 456', '101동 202호', '서초구 중심가 오피스텔, 사무실로 활용 가능', 2, '127.0.0.1'),
        ('서울시 마포구 홍대로 789', '303동 404호', '홍대입구 근처 원룸, 학생들이 선호하는 위치', 2, '127.0.0.1')
        ";
        $pdo->exec($properties_sql);

        // 3. 계약 데이터 생성 (landlord 1, agent 2, tenant 1)
        $contracts_sql = "
        INSERT INTO contracts (property_id, landlord_id, tenant_id, agent_id, landlord_phone, tenant_phone, agent_phone, start_date, end_date, deposit, monthly_rent, contract_file, status, created_by, created_ip) VALUES
        (1, 1, 4, 2, '010-1234-5678', '010-5678-1234', '010-2345-6789', '2024-01-01', '2024-12-31', 10000000, 800000, NULL, 'empty', 1, '127.0.0.1'),
        (2, 1, 4, 2, '010-1234-5678', '010-5678-1234', '010-2345-6789', '2024-02-01', '2025-01-31', 15000000, 1200000, NULL, 'empty', 2, '127.0.0.1'),
        (3, 1, 4, 2, '010-1234-5678', '010-5678-1234', '010-2345-6789', '2024-03-01', '2025-02-28', 8000000, 600000, NULL, 'empty', 2, '127.0.0.1')
        ";
        $pdo->exec($contracts_sql);

        return '샘플 데이터가 성공적으로 생성되었습니다! (사용자 4명, 임대물 3개, 계약 3개)';
    } catch (PDOException $e) {
        return '샘플 데이터 생성 실패: ' . $e->getMessage();
    }
}

// 사용자 활동 기록 함수
function log_user_activity($user_id, $activity_type, $description = '', $session_id = null) {
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
        $sql = "INSERT INTO user_activities (user_id, login_id, activity_type, description, ip_address, user_agent, session_id) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$user_id, $login_id, $activity_type, $description, $ip_address, $user_agent, $session_id]);
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
            $sql = "SELECT ua.*, u.nickname FROM user_activities ua 
                    LEFT JOIN users u ON ua.user_id = u.id 
                    WHERE ua.user_id = ? 
                    ORDER BY ua.created_at DESC 
                    LIMIT ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$user_id, $limit]);
        } else {
            $sql = "SELECT ua.*, u.nickname FROM user_activities ua 
                    LEFT JOIN users u ON ua.user_id = u.id 
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
        $sql = "SELECT ua.*, u.nickname FROM user_activities ua 
                LEFT JOIN users u ON ua.user_id = u.id 
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

?>