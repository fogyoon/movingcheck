<?php
require_once 'sql.inc';

// 보안키 및 비밀번호 생성 함수
function generateSecurityKey() {
    // URL 안전한 랜덤 문자열 생성 (32자리)
    return bin2hex(random_bytes(16));
}

function generateSecurityPassword() {
    // 4자리 랜덤 숫자 생성
    return str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
}

// 사진 전송 처리 (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_share_link') {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
        exit;
    }
    
    $contract_id = (int)($_POST['contract_id'] ?? 0);
    $share_type = $_POST['share_type'] ?? ''; // 'landlord', 'tenant'
    
    if (!$contract_id || !$share_type) {
        echo json_encode(['success' => false, 'message' => '필수 파라미터가 누락되었습니다.']);
        exit;
    }
    
    try {
        $pdo = get_pdo();
        
        // 계약 정보 조회
        $stmt = $pdo->prepare('SELECT * FROM contracts WHERE id = ?');
        $stmt->execute([$contract_id]);
        $contract = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$contract) {
            echo json_encode(['success' => false, 'message' => '계약을 찾을 수 없습니다.']);
            exit;
        }
        
        // 보안키와 비밀번호 생성
        $security_key = generateSecurityKey();
        $security_password = generateSecurityPassword();
        
        // 데이터베이스에 저장 및 요청 시간 기록
        $request_field = '';
        $key_field = '';
        $password_field = '';
        $current_status = '';
        
        // 현재 계약 상태 확인
        $stmt_status = $pdo->prepare('SELECT status FROM contracts WHERE id = ?');
        $stmt_status->execute([$contract_id]);
        $current_status = $stmt_status->fetchColumn();
        
        // 요청 시간 기록할 필드 및 키/패스워드 필드 결정
        if ($current_status === 'movein_photo' && $share_type === 'landlord') {
            $request_field = 'movein_landlord_request_sent_at';
            $key_field = 'movein_landlord_key';
            $password_field = 'movein_landlord_password';
        } elseif ($current_status === 'movein_landlord_signed' && $share_type === 'tenant') {
            $request_field = 'movein_tenant_request_sent_at';
            $key_field = 'movein_tenant_key';
            $password_field = 'movein_tenant_password';
        } elseif ($current_status === 'moveout_photo' && $share_type === 'landlord') {
            $request_field = 'moveout_landlord_request_sent_at';
            $key_field = 'moveout_landlord_key';
            $password_field = 'moveout_landlord_password';
        } elseif ($current_status === 'moveout_landlord_signed' && $share_type === 'tenant') {
            $request_field = 'moveout_tenant_request_sent_at';
            $key_field = 'moveout_tenant_key';
            $password_field = 'moveout_tenant_password';
        }
        
        // 보안키, 비밀번호 및 요청 시간 업데이트
        if ($request_field && $key_field && $password_field) {
            $stmt = $pdo->prepare("UPDATE contracts SET {$key_field} = ?, {$password_field} = ?, {$request_field} = NOW() WHERE id = ?");
            $stmt->execute([$security_key, $security_password, $contract_id]);
        } else {
            echo json_encode(['success' => false, 'message' => '현재 계약 상태에서는 해당 서명 요청을 할 수 없습니다.']);
            exit;
        }
        
        // 공유 URL 생성
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $current_dir = str_replace('\\', '/', dirname($_SERVER['REQUEST_URI']));
        
        // 디렉토리 경로 정리 (이중 슬래시 방지)
        if ($current_dir === '/' || $current_dir === '') {
            $share_url = $protocol . '://' . $host . '/secure_view.php?key=' . $security_key . '&role=' . $share_type;
        } else {
            $current_dir = rtrim($current_dir, '/');
            $share_url = $protocol . '://' . $host . $current_dir . '/secure_view.php?key=' . $security_key . '&role=' . $share_type;
        }
        
        // 활동 로그 기록
        $recipient = ($share_type === 'landlord') ? '임대인' : '임차인';
        $photo_type = (strpos($contract['status'], 'moveout') !== false) ? '퇴거사진' : '입주사진';
        log_user_activity($_SESSION['user_id'], 'other', $recipient . '에게 ' . $photo_type . ' 전송 링크 생성', $contract_id);
        
        echo json_encode([
            'success' => true,
            'url' => $share_url,
            'password' => $security_password,
            'recipient' => $recipient,
            'photo_type' => $photo_type
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '오류가 발생했습니다: ' . $e->getMessage()]);
    }
    exit;
}

// 수리업체 사진 전송 처리 (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_repair_share_link') {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
        exit;
    }
    
    $contract_id = (int)($_POST['contract_id'] ?? 0);
    
    if (!$contract_id) {
        echo json_encode(['success' => false, 'message' => '필수 파라미터가 누락되었습니다.']);
        exit;
    }
    
    try {
        $pdo = get_pdo();
        
        // 계약 정보 조회
        $stmt = $pdo->prepare('SELECT * FROM contracts WHERE id = ?');
        $stmt->execute([$contract_id]);
        $contract = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$contract) {
            echo json_encode(['success' => false, 'message' => '계약을 찾을 수 없습니다.']);
            exit;
        }
        
        // moveout_tenant_signed 또는 in_repair 상태가 아니면 오류
        if (!in_array($contract['status'], ['moveout_tenant_signed', 'in_repair'])) {
            echo json_encode(['success' => false, 'message' => '퇴거 사진 임차인 서명이 완료된 상태 또는 수리 진행 중인 상태에서만 수리업체에 사진을 보낼 수 있습니다.']);
            exit;
        }
        
        // 퇴거 사진이 있는지 확인
        $stmt_photos = $pdo->prepare('SELECT COUNT(*) FROM photos WHERE contract_id = ? AND (moveout_file_path_01 IS NOT NULL OR moveout_file_path_02 IS NOT NULL OR moveout_file_path_03 IS NOT NULL OR moveout_file_path_04 IS NOT NULL OR moveout_file_path_05 IS NOT NULL OR moveout_file_path_06 IS NOT NULL)');
        $stmt_photos->execute([$contract_id]);
        $photo_count = $stmt_photos->fetchColumn();
        
        if ($photo_count == 0) {
            echo json_encode(['success' => false, 'message' => '퇴거 사진이 등록되어 있지 않습니다.']);
            exit;
        }
        
        // 임차인의 퇴거 사진 키가 있는지 확인
        if (empty($contract['moveout_tenant_key'])) {
            echo json_encode(['success' => false, 'message' => '임차인 퇴거 사진 링크가 생성되지 않았습니다.']);
            exit;
        }
        
        // 공유 URL 생성 (임차인 퇴거 링크와 동일)
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $current_dir = str_replace('\\', '/', dirname($_SERVER['REQUEST_URI']));
        
        // 디렉토리 경로 정리 (이중 슬래시 방지)
        if ($current_dir === '/' || $current_dir === '') {
            $share_url = $protocol . '://' . $host . '/secure_view.php?key=' . $contract['moveout_tenant_key'] . '&role=tenant';
        } else {
            $current_dir = rtrim($current_dir, '/');
            $share_url = $protocol . '://' . $host . $current_dir . '/secure_view.php?key=' . $contract['moveout_tenant_key'] . '&role=tenant';
        }
        
        // 계약 상태를 in_repair로 변경 (moveout_tenant_signed 상태인 경우만)
        if ($contract['status'] === 'moveout_tenant_signed') {
            $stmt_update = $pdo->prepare('UPDATE contracts SET status = "in_repair" WHERE id = ?');
            $stmt_update->execute([$contract_id]);
        }
        
        // 활동 로그 기록
        if ($contract['status'] === 'moveout_tenant_signed') {
            log_user_activity($_SESSION['user_id'], 'other', '수리업체에 퇴거사진 전송 링크 생성 및 수리 단계로 변경', $contract_id);
        } else {
            log_user_activity($_SESSION['user_id'], 'other', '수리업체에 퇴거사진 전송 링크 재생성', $contract_id);
        }
        
        echo json_encode([
            'success' => true,
            'url' => $share_url,
            'recipient' => '수리업체',
            'photo_type' => '입주/퇴거 비교사진'
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '오류가 발생했습니다: ' . $e->getMessage()]);
    }
    exit;
}

// 계약 종료 처리 (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'finish_contract') {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
        exit;
    }
    
    $contract_id = (int)($_POST['contract_id'] ?? 0);
    
    if (!$contract_id) {
        echo json_encode(['success' => false, 'message' => '필수 파라미터가 누락되었습니다.']);
        exit;
    }
    
    try {
        $pdo = get_pdo();
        
        // 계약 정보 조회
        $stmt = $pdo->prepare('SELECT * FROM contracts WHERE id = ?');
        $stmt->execute([$contract_id]);
        $contract = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$contract) {
            echo json_encode(['success' => false, 'message' => '계약을 찾을 수 없습니다.']);
            exit;
        }
        
        // 종료 가능한 상태인지 확인 (moveout_tenant_signed 또는 in_repair 상태)
        if (!in_array($contract['status'], ['moveout_tenant_signed', 'in_repair'])) {
            echo json_encode(['success' => false, 'message' => '종료할 수 있는 상태가 아닙니다.']);
            exit;
        }
        
        // 계약 상태를 finished로 변경
        $stmt_update = $pdo->prepare('UPDATE contracts SET status = "finished" WHERE id = ?');
        $stmt_update->execute([$contract_id]);
        
        // 활동 로그 기록
        log_user_activity($_SESSION['user_id'], 'other', '계약 종료 처리 완료', $contract_id);
        
        echo json_encode([
            'success' => true,
            'message' => '계약이 성공적으로 종료되었습니다.'
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '오류가 발생했습니다: ' . $e->getMessage()]);
    }
    exit;
}

// 삭제 요청 처리 (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_photo') {
    // JSON 응답을 위한 헤더 설정
    header('Content-Type: application/json');
    
    // 로그인 체크
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
        exit;
    }
    
    $photo_id = (int)($_POST['photo_id'] ?? 0);
    $contract_id = (int)($_POST['contract_id'] ?? 0);
    
    if (!$photo_id || !$contract_id) {
        echo json_encode(['success' => false, 'message' => '필수 파라미터가 누락되었습니다.']);
        exit;
    }
    
    try {
        $pdo = get_pdo();
        
        // 삭제할 photo 정보 조회
        $stmt = $pdo->prepare('SELECT * FROM photos WHERE id = ? AND contract_id = ?');
        $stmt->execute([$photo_id, $contract_id]);
        $photo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$photo) {
            echo json_encode(['success' => false, 'message' => '사진을 찾을 수 없습니다.']);
            exit;
        }
        
        // 삭제할 파일 경로 수집
        $files_to_delete = [];
        
        // movein 사진 파일들
        for ($i = 1; $i <= 6; $i++) {
            $field_name = 'movein_file_path_0' . $i;
            if (!empty($photo[$field_name])) {
                $files_to_delete[] = $photo[$field_name];
            }
        }
        
        // moveout 사진 파일들
        for ($i = 1; $i <= 6; $i++) {
            $field_name = 'moveout_file_path_0' . $i;
            if (!empty($photo[$field_name])) {
                $files_to_delete[] = $photo[$field_name];
            }
        }
        
        // 트랜잭션 시작
        $pdo->beginTransaction();
        
        try {
            // 데이터베이스에서 photo 레코드 삭제
            $stmt = $pdo->prepare('DELETE FROM photos WHERE id = ? AND contract_id = ?');
            $stmt->execute([$photo_id, $contract_id]);
            
            // 파일 삭제
            $deleted_files = [];
            $failed_files = [];
            
            foreach ($files_to_delete as $file_path) {
                if (file_exists($file_path)) {
                    if (unlink($file_path)) {
                        $deleted_files[] = $file_path;
                    } else {
                        $failed_files[] = $file_path;
                    }
                }
            }
            
            // 해당 계약에 남은 사진이 있는지 확인
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM photos WHERE contract_id = ?');
            $stmt->execute([$contract_id]);
            $remaining_photos = $stmt->fetchColumn();
            
            // 남은 사진이 없으면 계약 상태를 empty로 변경
            if ($remaining_photos == 0) {
                $stmt = $pdo->prepare('UPDATE contracts SET status = "empty" WHERE id = ?');
                $stmt->execute([$contract_id]);
            }
            
            // 트랜잭션 커밋
            $pdo->commit();
            
            // 활동 로그 기록
            log_user_activity($_SESSION['user_id'], 'delete_photo', '사진 삭제 (ID: ' . $photo_id . ', 계약 ID: ' . $contract_id . ')', $contract_id);
            
            // 성공 응답
            $response = [
                'success' => true,
                'message' => '사진이 성공적으로 삭제되었습니다.',
                'deleted_files_count' => count($deleted_files),
                'remaining_photos' => $remaining_photos
            ];
            
            // 파일 삭제 실패가 있으면 경고 메시지 추가
            if (!empty($failed_files)) {
                $response['warning'] = '일부 파일 삭제에 실패했습니다: ' . implode(', ', $failed_files);
            }
            
            echo json_encode($response);
            
        } catch (Exception $e) {
            // 트랜잭션 롤백
            $pdo->rollBack();
            throw $e;
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => '삭제 중 오류가 발생했습니다: ' . $e->getMessage()
        ]);
    }
    exit;
}

// 세션 및 로그인 체크는 header.inc/config.inc에서 처리됨
$contract_id = (int)($_GET['contract_id'] ?? 0);
if (!$contract_id) die('잘못된 접근입니다.');
$pdo = get_pdo();



// 계약 및 임대물 정보
$stmt = $pdo->prepare('SELECT c.*, p.address, p.detail_address FROM contracts c JOIN properties p ON c.property_id = p.id WHERE c.id = ?');
$stmt->execute([$contract_id]);
$contract = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$contract) die('계약을 찾을 수 없습니다.');
// 계약 상태 전역 변수로 지정
$status = $contract['status'];
// 사진 목록
$stmt = $pdo->prepare('SELECT * FROM photos WHERE contract_id = ? ORDER BY created_at DESC');
$stmt->execute([$contract_id]);
$photos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 퇴거 사진이 등록되었는지 확인
$has_moveout_photos = false;
foreach ($photos as $photo) {
  for ($i = 1; $i <= 6; $i++) {
    $index_str = str_pad($i, 2, '0', STR_PAD_LEFT);
    if (!empty($photo['moveout_file_path_' . $index_str])) {
      $has_moveout_photos = true;
      break 2;
    }
  }
}

// contracts.php와 동일한 status_info 배열
$status_info = [
    'empty' => [ 'label' => '사진 등록 필요', 'phase' => '입주', 'progress' => 0 ],
    'movein_photo' => [ 'label' => '서명 필요', 'phase' => '입주', 'progress' => 10 ],
    'movein_landlord_signed' => [ 'label' => '임대인 서명 완료', 'phase' => '입주', 'progress' => 20 ],
    'movein_tenant_signed' => [ 'label' => '임차인 서명 완료', 'phase' => '입주', 'progress' => 30 ],
    'moveout_photo' => [ 'label' => '파손 발생', 'phase' => '퇴거', 'progress' => 50 ],
    'moveout_landlord_signed' => [ 'label' => '임대인 파손 확인', 'phase' => '퇴거', 'progress' =>60 ],
    'moveout_tenant_signed' => [ 'label' => '임차인 파손 인정', 'phase' => '수리요망', 'progress' => 60 ],
    'in_repair' => [ 'label' => '수리중', 'phase' => '수리요망', 'progress' => 80 ],
    'finished' => [ 'label' => '계약 완료', 'phase' => '완료', 'progress' => 100 ],
];
function getStatusBadgeClass($status) {
    switch ($status) {
        case 'empty':
        case 'movein_photo':
        case 'movein_landlord_signed':
        case 'movein_tenant_signed':
            return 'movein';
        case 'moveout_photo':
        case 'moveout_landlord_signed':
        case 'moveout_tenant_signed':
            return 'moveout';
        case 'in_repair':
            return 'repair';
        case 'finished':
            return 'finished';
        default:
            return 'movein';
    }
}
// 현재 사용자의 계약에서의 역할 확인
$user_role_in_contract = '';
if (isset($_SESSION['user_id']) && $contract) {
    $current_user_id = $_SESSION['user_id'];
    if ($contract['landlord_id'] == $current_user_id) {
        $user_role_in_contract = 'landlord';
    } elseif ($contract['tenant_id'] == $current_user_id) {
        $user_role_in_contract = 'tenant';
    } elseif ($contract['agent_id'] == $current_user_id) {
        $user_role_in_contract = 'agent';
    }
}

// 전화번호 정규화 함수 (숫자만 추출)
function normalize_phone($phone) {
    return preg_replace('/[^0-9]/', '', trim($phone));
}

// 서명 불일치 확인되지 않은 것이 있는지 체크
function has_unconfirmed_signature_mismatch($contract_id, $pdo, $contract_info) {
    $stmt_sig = $pdo->prepare("SELECT id, signer_role, signer_name, signer_phone, purpose, mismatch_confirmed FROM signatures WHERE contract_id = ?");
    $stmt_sig->execute([$contract_id]);
    $signatures = $stmt_sig->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($signatures as $signature) {
        if ($signature['mismatch_confirmed']) {
            continue; // 이미 확인된 것은 건너뜀
        }
        
        $role = $signature['signer_role'];
        $signer_name = trim($signature['signer_name']);
        $signer_phone = trim($signature['signer_phone']);
        
        // 계약서 정보와 비교
        $contract_name = '';
        $contract_phone = '';
        if ($role === 'landlord') {
            $contract_name = trim($contract_info['landlord_name']);
            $contract_phone = trim($contract_info['landlord_phone']);
        } elseif ($role === 'tenant') {
            $contract_name = trim($contract_info['tenant_name']);
            $contract_phone = trim($contract_info['tenant_phone']);
        }
        
        // 불일치 확인
        $name_mismatch = false;
        $phone_mismatch = false;
        
        if (!empty($signer_name) && !empty($contract_name)) {
            $name_mismatch = strcasecmp($signer_name, $contract_name) !== 0;
        }
        
        if (!empty($signer_phone) && !empty($contract_phone)) {
            $phone_mismatch = normalize_phone($signer_phone) !== normalize_phone($contract_phone);
        }
        
        if ($name_mismatch || $phone_mismatch) {
            return true; // 확인되지 않은 불일치가 있음
        }
    }
    
    return false; // 확인되지 않은 불일치 없음
}

// 현재 계약의 서명 불일치 상태 확인
$has_unconfirmed_mismatch = has_unconfirmed_signature_mismatch($contract_id, $pdo, $contract);

// 서명 정보 조회
$stmt_signatures = $pdo->prepare('SELECT * FROM signatures WHERE contract_id = ? ORDER BY signed_at DESC');
$stmt_signatures->execute([$contract_id]);
$signatures = $stmt_signatures->fetchAll(PDO::FETCH_ASSOC);

// 서명 다시 요청 처리
$auto_request_sign = '';
if (isset($_GET['request_sign'])) {
    $request_sign = $_GET['request_sign'];
    // request_sign 형식: movein_landlord, movein_tenant, moveout_landlord, moveout_tenant
    if (preg_match('/^(movein|moveout)_(landlord|tenant)$/', $request_sign, $matches)) {
        $purpose = $matches[1];
        $role = $matches[2];
        
        // 현재 계약 상태와 요청된 서명이 맞는지 확인
        $should_allow = false;
        if ($purpose === 'movein' && in_array($status, ['movein_photo', 'movein_landlord_signed', 'movein_tenant_signed'])) {
            $should_allow = true;
        } elseif ($purpose === 'moveout' && in_array($status, ['moveout_photo', 'moveout_landlord_signed', 'moveout_tenant_signed'])) {
            $should_allow = true;
        }
        
        if ($should_allow && isset($_SESSION['user_id'])) {
            // 기존 해당 서명 삭제
            try {
                // 먼저 기존 서명 정보 조회 (서명 파일 경로 확보)
                $stmt_existing = $pdo->prepare('SELECT signature_data FROM signatures WHERE contract_id = ? AND purpose = ? AND signer_role = ?');
                $stmt_existing->execute([$contract_id, $purpose, $role]);
                $existing_signatures = $stmt_existing->fetchAll(PDO::FETCH_ASSOC);
                
                // 서명 파일 삭제
                foreach ($existing_signatures as $existing_sig) {
                    if (!empty($existing_sig['signature_data'])) {
                        // signature_data에서 파일 경로 추출 (JSON 형태일 수 있음)
                        $signature_file = $existing_sig['signature_data'];
                        if (strpos($signature_file, 'signatures/') === 0 && file_exists($signature_file)) {
                            unlink($signature_file);
                        }
                    }
                }
                
                // 데이터베이스에서 서명 삭제
                $stmt_delete = $pdo->prepare('DELETE FROM signatures WHERE contract_id = ? AND purpose = ? AND signer_role = ?');
                $stmt_delete->execute([$contract_id, $purpose, $role]);
                
                $deleted_count = $stmt_delete->rowCount();
                
                // 계약 상태를 이전 단계로 변경
                if ($deleted_count > 0) {
                    $new_status = $status; // 기본값은 현재 상태
                    
                    if ($purpose === 'movein' && $role === 'landlord' && $status === 'movein_landlord_signed') {
                        $new_status = 'movein_photo';
                    } elseif ($purpose === 'movein' && $role === 'tenant' && $status === 'movein_tenant_signed') {
                        $new_status = 'movein_landlord_signed';
                    } elseif ($purpose === 'moveout' && $role === 'landlord' && $status === 'moveout_landlord_signed') {
                        $new_status = 'moveout_photo';
                    } elseif ($purpose === 'moveout' && $role === 'tenant' && $status === 'moveout_tenant_signed') {
                        $new_status = 'moveout_landlord_signed';
                    }
                    
                    // 상태가 변경되는 경우에만 업데이트
                    if ($new_status !== $status) {
                        $stmt_update_status = $pdo->prepare('UPDATE contracts SET status = ? WHERE id = ?');
                        $stmt_update_status->execute([$new_status, $contract_id]);
                        $status = $new_status; // 현재 페이지에서도 상태 반영
                    }
                    
                    // 사용자 활동 기록
                    $role_korean = $role === 'landlord' ? '임대인' : '임차인';
                    $purpose_korean = $purpose === 'movein' ? '입주' : '퇴거';
                    log_user_activity($_SESSION['user_id'], 'other', "기존 {$purpose_korean} {$role_korean} 서명 삭제 및 계약 상태 '{$new_status}'로 변경 (재요청을 위해)", $contract_id);
                }
                
                $auto_request_sign = $role; // JavaScript에서 사용할 값
                
            } catch (Exception $e) {
                // 삭제 실패 시 로그 기록하고 계속 진행
                error_log('서명 삭제 실패: ' . $e->getMessage());
                $auto_request_sign = $role; // 실패해도 요청은 진행
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>등록 사진 - <?php echo htmlspecialchars($contract['address']); ?> - 무빙체크</title>
  <link rel="stylesheet" href="style.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@400;700;900&display=swap" rel="stylesheet">
  <style>
    /* =================================== */
    /* 임대물(Properties) 페이지 스타일 (Bootstrap 테마 참조) */
    /* =https://themes.getbootstrap.com/preview/?theme_id=92316 */
    /* =================================== */
    
    .prop-container {
      max-width: 1140px;
      margin: 2rem auto;
      padding: 0 1rem;
    }
    .page-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 2rem;
      padding-bottom: 1.5rem;
      border-bottom: 1px solid #e3eaf2;
    }
    .page-title {
      font-size: 1.8rem;
      font-weight: 700;
      color: #212529;
    }
    .page-subtitle {
      font-size: 1.1rem;
      color: #6c757d;
      margin-top: 0.5rem;
    }
    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
      font-weight: 600;
      text-align: center;
      vertical-align: middle;
      cursor: pointer;
      background-color: transparent;
      border: 1px solid transparent;
      padding: 0.5rem 1rem;
      font-size: 0.95rem;
      border-radius: 8px;
      text-decoration: none;
      transition: all 0.2s ease-in-out;
      white-space: nowrap;
      min-width: 70px;
      box-sizing: border-box;
    }
    .btn-primary {
      color: #fff;
      background-color: #0064FF;
      border-color: #0064FF;
    }
    .btn-primary:hover {
      background-color: #0052cc;
      border-color: #0052cc;
    }
    .btn-secondary {
      color: #6c757d;
      background-color: #f8f9fa;
      border-color: #dee2e6;
    }
    .btn-secondary:hover {
      background-color: #e9ecef;
    }
    .btn-success {
      color: #fff;
      background-color: #28a745;
      border-color: #28a745;
    }
    .btn-success:hover {
      background-color: #218838;
      border-color: #1e7e34;
    }
    .btn-warning {
      color: #212529;
      background-color: #ffc107;
      border-color: #ffc107;
    }
    .btn-warning:hover {
      background-color: #e0a800;
      border-color: #d39e00;
    }
    .btn-icon {
      padding: 0.5rem;
      min-width: 38px;
      min-height: 38px;
      justify-content: center;
    }
    .btn-light {
      color: #212529;
      background-color: #f8f9fa;
      border-color: #dee2e6;
    }
    .btn-light:hover { background-color: #e9ecef; }

    /* 테이블 */
    .prop-table-wrap {
      background-color: #fff;
      border: 1px solid #e3eaf2;
      border-radius: 12px;
      padding: 1rem;
      box-shadow: 0 4px 18px rgba(0,100,255,0.05);
    }
    .prop-table { width: 100%; border-collapse: collapse; min-width: 700px; }
    .prop-table th, .prop-table td { padding: 1rem; text-align: left; border-bottom: 1px solid #f0f2f5; }
    .prop-table th { color: #555; font-size: 0.9rem; font-weight: 600; text-transform: uppercase; }
    .prop-table tr:last-child td { border-bottom: none; }
    .prop-table .prop-address { font-weight: 600; color: #333; }
    .prop-table .prop-actions { display: flex; gap: 0.5rem; }

    /* 모바일 카드 */
    .prop-card-list { display: none; }

    @media (max-width: 768px) {
      .prop-table-wrap { display: none; }
      .prop-card-list { display: flex; flex-direction: column; gap: 1.2rem; }
      .prop-card {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 4px 18px rgba(0,100,255,0.07);
        border: 1px solid #e3eaf2;
        padding: 1.25rem;
      }
      .prop-card-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 1rem;
      }
      .prop-card-address {
        font-size: 1.1rem;
        font-weight: 700;
        color: #212529;
      }
      .prop-card-address small {
        display: block;
        font-weight: 400;
        color: #6c757d;
        font-size: 0.95rem;
      }
      .prop-card-body .desc {
        font-size: 0.95rem; color: #495057; margin-bottom: 1rem;
      }
      .prop-card-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding-top: 1rem;
        border-top: 1px solid #e9ecef;
      }
      .prop-card-footer .btn {
        font-size: 0.85rem;
        padding: 0.4rem 0.6rem;
      }
      .prop-card-body-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 0.1rem;
        padding-top: 0.1rem;
        margin-bottom: 0.5rem;
      }
      .prop-card-body-footer .prop-actions .btn.btn-icon {
        padding: 0.4rem;
        min-width: 34px;
        min-height: 34px;
      }
      .prop-card-body-footer .prop-actions .btn.btn-icon svg {
        width: 15px;
        height: 15px;
      }
      .prop-card-meta { color: #6c757d; font-size: 0.9rem; }
      .prop-actions { display: flex; gap: 0.5rem; }
    }

    /* 상태 뱃지 */
    .status-badge {
      display: inline-block;
      padding: 0.4rem 1rem;
      font-size: 0.85rem;
      font-weight: 600;
      border-radius: 20px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-left: 0.5rem;
    }
    .status-badge.movein {
      background-color: #e3f2fd;
      color: #1976d2;
      border: 1px solid #bbdefb;
    }
    .status-badge.moveout {
      background-color: #fff3e0;
      color: #f57c00;
      border: 1px solid #ffcc02;
    }
    .status-badge.repair {
      background-color: #fce4ec;
      color: #c2185b;
      border: 1px solid #f8bbd9;
    }
    .status-badge.finished {
      background-color: #e8f5e8;
      color: #388e3c;
      border: 1px solid #c8e6c9;
    }

    /* 서명 섹션 반응형 스타일 */
    .signature-item {
      display: flex;
      gap: 1.5rem;
      align-items: flex-start;
      overflow: hidden;
    }
    
    .signature-info {
      flex: 1;
      min-width: 300px;
      overflow: hidden;
    }
    
    .signature-image {
      flex-shrink: 0;
      overflow: hidden;
    }
    
    @media (max-width: 768px) {
      .signature-item {
        flex-direction: column;
        gap: 1rem;
        width: 100%;
        box-sizing: border-box;
      }
      
      .signature-info {
        min-width: unset;
        width: 100%;
        box-sizing: border-box;
      }
      
      .signature-info > div:first-child {
        grid-template-columns: 1fr !important;
        gap: 0.8rem !important;
      }
      
      .signature-image {
        align-self: center;
        width: 100%;
        box-sizing: border-box;
        text-align: center;
      }
      
      .signature-image img {
        max-width: 180px !important;
        max-height: 90px !important;
      }
      
      .signature-image .signature-placeholder {
        width: 180px !important;
        margin: 0 auto;
      }
    }
    
        @media (max-width: 480px) {
      .signature-info > div:first-child {
        grid-template-columns: 1fr !important;
        gap: 0.6rem !important;
      }
      
      .signature-image img {
        max-width: 150px !important;
        max-height: 75px !important;
      }
      
      .signature-image .signature-placeholder {
        width: 150px !important;
        padding: 0.8rem !important;
        margin: 0 auto;
      }
       
       /* 서명 섹션 컨테이너 모바일 조정 */
       .signatures-container {
         margin: 1rem auto !important;
         padding: 0 0.5rem !important;
         max-width: 100% !important;
         overflow: hidden;
         box-sizing: border-box;
       }
       
       .signatures-container > div {
         padding: 1rem !important;
         overflow: hidden;
         box-sizing: border-box;
       }
       
       .signatures-container h3 {
         font-size: 1.1rem !important;
         margin-bottom: 1rem !important;
       }
     }

    /* ... (생략: 테이블, 카드, 반응형 등 필요시 추가) ... */
    @media (max-width: 700px) {
      .address-title-wrap { flex-direction: column; align-items: flex-start !important; gap: 0.3rem; }
      .address-title-btns { width: 100%; margin-top: 0.3rem; }
      .address-title-text { font-size: 1.08rem; }
    }
    @media (min-width: 701px) {
      .address-title-wrap { flex-direction: row; align-items: center; }
      .address-title-btns { margin-top: 0; }
    }
    
    .btn-container {
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
      align-items: center;
    }
  </style>
</head>
<body style="background:#f8f9fa;">
<?php include 'header.inc'; ?>
<main class="prop-container">

  <div class="page-header">
    <h1 class="page-title">
      <img src="images/camera-icon.svg" alt="카메라" style="width: 32px; height: 32px; margin-right: 12px; vertical-align: middle;">등록 사진
    </h1>

    <div style="display:flex; justify-content:flex-end; align-items:center; max-width:900px; margin-bottom:0.5rem; margin-left:auto; margin-right:0;">
    <a href="contracts.php?property_id=<?php echo urlencode($contract['property_id']); ?>" id="backToListBtn" style="background-color: #6c757d; color: white; padding: 0.5rem 1rem; border-radius: 6px; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 0.4rem; box-shadow: 0 2px 6px rgba(108, 117, 125, 0.3); transition: all 0.2s ease; border: 1px solid #5a6268; font-size: 0.9rem;">
      <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16">
        <path fill-rule="evenodd" d="M15 8a.5.5 0 0 0-.5-.5H2.707l3.147-3.146a.5.5 0 1 0-.708-.708l-4 4a.5.5 0 0 0 0 .708l4 4a.5.5 0 0 0 .708-.708L2.707 8.5H14.5A.5.5 0 0 0 15 8z"/>
      </svg>
      돌아가기
    </a>
  </div>
  </div>

  <div class="address-title-wrap">
    <div class="address-title-text">
      <?php echo htmlspecialchars($contract['address']); ?><?php if (!empty($contract['detail_address'])): ?>, <?php echo htmlspecialchars($contract['detail_address']); ?><?php endif; ?>
      <!--
      <?php if ($GLOBALS['now_in_test_mode'] ?? false): ?>
        <span style="font-size:0.9rem; font-weight:500; color:#6c757d; margin-left:0.8rem; padding:0.2rem 0.6rem; background:#f8f9fa; border-radius:4px; border:1px solid #e9ecef;">
        <?php echo htmlspecialchars($status); ?>
        </span>
      <?php endif; ?>
      -->
    </div>
  </div>
  <div class="address-title-btns">
      <?php
      // 불일치 확인이 안 된 서명이 있으면 다음 단계 진행 불가
      if ($has_unconfirmed_mismatch) {
        echo '<div id="mismatch-notice" class="notice-warning">';
        echo '⚠️ <strong>서명 불일치 확인 필요</strong><br>';
        echo '확인되지 않은 서명 불일치가 있습니다. 계약 관리 페이지에서 불일치 내용을 먼저 확인해주세요.';
        echo '</div>';
      }
      
      // 서명 요청 상태 확인
      $landlord_request_sent = false;
      $tenant_request_sent = false;
      
      if ($status === 'movein_photo') {
        $landlord_request_sent = !empty($contract['movein_landlord_request_sent_at']);
      } elseif ($status === 'movein_landlord_signed') {
        $tenant_request_sent = !empty($contract['movein_tenant_request_sent_at']);
      } elseif ($status === 'moveout_photo') {
        $landlord_request_sent = !empty($contract['moveout_landlord_request_sent_at']);
      } elseif ($status === 'moveout_landlord_signed') {
        $tenant_request_sent = !empty($contract['moveout_tenant_request_sent_at']);
      }
      
      if ($status === 'empty') {
        echo '<div class="btn-container">';
        echo '<a href="photo_upload.php?contract_id=' . $contract_id . '" class="btn btn-primary">입주사진 등록</a>';
        echo '</div>';
      } elseif ($status === 'movein_photo') {
        echo '<div class="btn-container">';
        //echo '<a href="photo_upload.php?contract_id=' . $contract_id . '" class="btn btn-primary">입주사진 추가 등록</a>';
        if (!empty($photos) && !$has_unconfirmed_mismatch) { // 사진이 있고 불일치 미확인이 없을 때만 서명/전송 버튼 표시
          if ($user_role_in_contract === 'agent') {
            $button_text = $landlord_request_sent ? '임대인에게 사진 재전송' : '임대인에게 사진 전송';
            $onclick = $landlord_request_sent ? 'confirmResendLink(\'landlord\')' : 'generateShareLink(\'landlord\')';
            echo '<button class="btn btn-warning" onclick="' . $onclick . '">' . $button_text . '</button>';
          } else {
            echo '<button class="btn btn-success" onclick="handleSign()">본인 서명하기</button>';
          }
        }
        echo '</div>';
        // 사진이 있을 때 설명 문구 추가
        if (!empty($photos)) {
          echo '<div class="notice-text">';
          if ($user_role_in_contract === 'agent') {
            echo '<strong>사진 전송은 모든 사진 등록이 끝난 후 진행하세요.</strong>';
          } else {
            echo '<strong>본인 서명은 모든 사진 등록이 끝난 후 진행하세요.</strong>';
          }
          echo '</div>';
        }
      } elseif ($status === 'movein_landlord_signed') {
        echo '<div class="btn-container">';
        if (!$has_unconfirmed_mismatch) {
          $button_text = $tenant_request_sent ? '임차인에게 사진 재전송' : '임차인에게 사진 전송';
          $onclick = $tenant_request_sent ? 'confirmResendLink(\'tenant\')' : 'generateShareLink(\'tenant\')';
          echo '<button class="btn btn-warning" onclick="' . $onclick . '">' . $button_text . '</button>';
        }
        //echo '<a href="#" onclick="confirmPhotoChange(\'photo_upload.php?contract_id=' . $contract_id . '\'); return false;" class="btn btn-primary">입주사진 추가 등록</a>';
        echo '</div>';
        // 사진이 있을 때 설명 문구 추가
        if (!empty($photos)) {
          echo '<div class="notice-text">';
          echo '<strong>사진 전송은 모든 사진 등록이 끝난 후 진행하세요.</strong>';
          echo '</div>';
        }
      } elseif ($status === 'moveout_photo') {
        echo '<div class="btn-container">';
        if (!empty($photos) && !$has_unconfirmed_mismatch) { // 사진이 있고 불일치 미확인이 없을 때만 서명/전송 버튼 표시
          if ($user_role_in_contract === 'agent') {
            $button_text = $landlord_request_sent ? '임대인에게 파손사진 재전송' : '임대인에게 파손사진 전송';
            $onclick = $landlord_request_sent ? 'confirmResendLink(\'landlord\')' : 'generateShareLink(\'landlord\')';
            echo '<button class="btn btn-warning" onclick="' . $onclick . '">' . $button_text . '</button>';
          } else {
            echo '<button class="btn btn-success" onclick="handleSign()">퇴거 사진에 본인 서명하기</button>';
          }
        }
        echo '</div>';
        // 사진이 있을 때 설명 문구 추가 (파손사진 등록 버튼은 각 사진 항목에 있으므로 전체적인 안내만)
        if (!empty($photos)) {
          echo '<div class="notice-text">';
          if ($user_role_in_contract === 'agent') {
            echo '<strong>파손사진 전송은 모든 사진 등록이 끝난 후 진행하세요.</strong>';
          } else {
            echo '<strong>본인 서명은 모든 사진 등록이 끝난 후 진행하세요.</strong>';
          }
          echo '</div>';
        }
              } elseif ($status === 'moveout_landlord_signed') {
        echo '<div class="btn-container">';
        if (!$has_unconfirmed_mismatch) {
          $button_text = $tenant_request_sent ? '임차인에게 파손사진 재전송' : '임차인에게 파손사진 전송';
          $onclick = $tenant_request_sent ? 'confirmResendLink(\'tenant\')' : 'generateShareLink(\'tenant\')';
          echo '<button class="btn btn-warning" onclick="' . $onclick . '">' . $button_text . '</button>';
        }
        echo '</div>';
        // 사진이 있을 때 설명 문구 추가 (파손사진 등록 버튼은 각 사진 항목에 있으므로 전체적인 안내만)
        if (!empty($photos)) {
          echo '<div class="notice-text">';
          echo '<strong>파손사진 전송은 모든 사진 등록이 끝난 후 진행하세요.</strong>';
          echo '</div>';
        }
      } elseif ($status === 'moveout_tenant_signed' || $status === 'in_repair') {
        // 퇴거 사진이 있는지 확인
        $has_moveout_photos_basic = false;
        foreach ($photos as $p) {
          for ($i = 1; $i <= 6; $i++) {
            $field_name = 'moveout_file_path_0' . $i;
            if (!empty($p[$field_name])) {
              $has_moveout_photos_basic = true;
              break 2;
            }
          }
        }
        
        echo '<div class="btn-container">';
        if (($status === 'moveout_tenant_signed' || $status === 'in_repair') && $has_moveout_photos_basic) {
          echo '<button class="btn btn-warning" onclick="generateRepairShareLink()" style="font-weight: 600; box-shadow: 0 2px 8px rgba(255, 193, 7, 0.3);">🔧 수리업체에 사진 보내기</button>';
        }
        echo '<button class="btn-complete" onclick="finishContract()">계약 종료하기</button>';
        echo '</div>';
      }
      ?>
  </div>
  
  <?php if ($status === 'movein_photo' || $status === 'movein_landlord_signed'): ?>
    <div class="photo-btn-container" id="add-photo-btn-top">
      <?php
      if ($status === 'movein_photo') {
        echo '<a href="photo_upload.php?contract_id=' . $contract_id . '" class="btn btn-primary">입주사진 추가 등록</a>';
      } elseif ($status === 'movein_landlord_signed') {
        echo '<a href="#" onclick="confirmPhotoChange(\'photo_upload.php?contract_id=' . $contract_id . '\'); return false;" class="btn btn-primary">입주사진 추가 등록</a>';
      }
      ?>
    </div>
  <?php endif; ?>

  <!-- PC 테이블 -->
  <div class="prop-table-wrap">
    <table class="prop-table">
      <thead>
        <tr>
          <th>부위</th>
          <th>설명</th>
          <th>등록일</th>
          <th>사진</th>
          <th>작업</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($photos): foreach ($photos as $p): ?>
        <tr>
          <td><span class="prop-address"><?php echo htmlspecialchars($p['part']); ?></span></td>
          <td style="font-size:0.97rem; color:#495057; max-width: 250px; white-space: normal; word-break: break-all;"><?php echo nl2br(htmlspecialchars($p['description'])); ?></td>
          <td style="font-size:0.95em; color:#6c757d; white-space:nowrap;"><?php echo htmlspecialchars($p['created_at']); ?></td>
          <td>
            <?php
            // 입주 사진을 overview 우선으로 정렬하여 출력
            $movein_photos = [];
            for ($i=0; $i<6; $i++) {
              $fp = $p['movein_file_path_0'. $i] ?? null;
              $shot_type = $p['movein_shot_type_0'. $i] ?? null;
              if ($fp) {
                $movein_photos[] = ['path' => $fp, 'type' => $shot_type, 'index' => $i];
              }
            }
            // overview를 먼저, 나머지를 뒤에 정렬
            usort($movein_photos, function($a, $b) {
              if ($a['type'] === 'overview' && $b['type'] !== 'overview') return -1;
              if ($a['type'] !== 'overview' && $b['type'] === 'overview') return 1;
              return $a['index'] - $b['index']; // 같은 타입이면 원래 순서 유지
            });
            
            // 퇴거 사진을 overview 우선으로 정렬하여 출력
            $moveout_photos = [];
            for ($i=0; $i<6; $i++) {
              $fp = $p['moveout_file_path_0'. $i] ?? null;
              $shot_type = $p['moveout_shot_type_0'. $i] ?? null;
              if ($fp) {
                $moveout_photos[] = ['path' => $fp, 'type' => $shot_type, 'index' => $i];
              }
            }
            // overview를 먼저, 나머지를 뒤에 정렬
            usort($moveout_photos, function($a, $b) {
              if ($a['type'] === 'overview' && $b['type'] !== 'overview') return -1;
              if ($a['type'] !== 'overview' && $b['type'] === 'overview') return 1;
              return $a['index'] - $b['index']; // 같은 타입이면 원래 순서 유지
            });
            
            // 파손 사진이 있는 경우 입주/퇴거 구분해서 표시
            if (!empty($moveout_photos)) {
              echo '<div style="font-size: 0.8rem; font-weight: 600; color: #1976d2; margin-bottom: 0.3rem;">📋 입주시</div>';
              echo '<div style="display: flex; flex-wrap: wrap; gap: 0.3rem; margin-bottom: 1rem;">';
              foreach ($movein_photos as $photo) {
                $is_overview = ($photo['type'] === 'overview');
                $width = $is_overview ? '110' : '70';
                $border = $is_overview ? ' border:2px solid #1976d2;' : '';
                echo '<img src="'.htmlspecialchars($photo['path']).'" alt="입주사진" style="width:'.$width.'px; height:auto; border-radius:8px;'.$border.' cursor:pointer;" class="photo-thumb" data-photo-id="'.$p['id'].'" title="'.($is_overview ? '위치확인용' : '세부사진').'">';
              }
              echo '</div>';
              echo '<div style="font-size: 0.8rem; font-weight: 600; color: #dc3545; margin-bottom: 0.3rem;">🔧 퇴거시 (파손)</div>';
              echo '<div style="display: flex; flex-wrap: wrap; gap: 0.3rem;">';
              foreach ($moveout_photos as $photo) {
                $is_overview = ($photo['type'] === 'overview');
                $width = $is_overview ? '110' : '70';
                $border = $is_overview ? ' border:2px solid #dc3545;' : ' border:1px solid #dc3545;';
                echo '<img src="'.htmlspecialchars($photo['path']).'" alt="퇴거사진" style="width:'.$width.'px; height:auto; border-radius:8px;'.$border.' cursor:pointer;" class="photo-thumb" data-photo-id="'.$p['id'].'" title="'.($is_overview ? '위치확인용' : '세부사진').'">';
              }
              echo '</div>';
            } else {
              // 퇴거 사진이 없는 경우 기존 방식
              $photo_count = 0;
              foreach ($movein_photos as $photo) {
                $is_overview = ($photo['type'] === 'overview');
                $width = $is_overview ? '110' : '70';
                $margin = ($photo_count > 0) ? ' margin-left:4px; margin-top:4px;' : '';
                $border = $is_overview ? ' border:2px solid #1976d2;' : '';
                echo '<img src="'.htmlspecialchars($photo['path']).'" alt="입주사진" style="width:'.$width.'px; height:auto; border-radius:8px;'.$margin.$border.' cursor:pointer;" class="photo-thumb" data-photo-id="'.$p['id'].'" title="'.($is_overview ? '위치확인용' : '세부사진').'">';
                $photo_count++;
              }
            }
            ?>
          </td>
          <td>
            <?php
            if ($status === 'movein_photo' || $status === 'movein_landlord_signed') {
              if ($status === 'movein_landlord_signed') {
                echo '<a href="#" onclick="confirmPhotoChange(\'photo_upload.php?contract_id=' . $contract_id . '&photo_id=' . $p['id'] . '\'); return false;" class="btn btn-primary">수정</a> <button class="btn btn-warning" onclick="confirmDelete(' . $p['id'] . ')" style="margin-left:1.5rem;">삭제</button>';
              } else {
                echo '<a href="photo_upload.php?contract_id=' . $contract_id . '&photo_id=' . $p['id'] . '" class="btn btn-primary">수정</a> <button class="btn btn-warning" onclick="confirmDelete(' . $p['id'] . ')" style="margin-left:1.5rem;">삭제</button>';
              }
            } elseif ($status === 'movein_tenant_signed') {
              // 해당 부위에 퇴거 사진이 있는지 확인
              $has_this_moveout_photos = false;
              for ($i = 1; $i <= 6; $i++) {
                $index_str = str_pad($i, 2, '0', STR_PAD_LEFT);
                if (!empty($p['moveout_file_path_' . $index_str])) {
                  $has_this_moveout_photos = true;
                  break;
                }
              }
              $button_text = $has_this_moveout_photos ? '파손사진 재등록' : '파손사진 등록';
              echo '<a href="moveout_photo.php?contract_id=' . $contract_id . '&photo_id=' . $p['id'] . '" class="btn btn-primary">' . $button_text . '</a>';
            } elseif ($status === 'moveout_photo' || $status === 'moveout_landlord_signed') {
              // 해당 부위에 퇴거 사진이 있는지 확인
              $has_this_moveout_photos = false;
              for ($i = 1; $i <= 6; $i++) {
                $index_str = str_pad($i, 2, '0', STR_PAD_LEFT);
                if (!empty($p['moveout_file_path_' . $index_str])) {
                  $has_this_moveout_photos = true;
                  break;
                }
              }
              $button_text = $has_this_moveout_photos ? '파손사진 재등록' : '파손사진 등록';
              if ($status === 'moveout_landlord_signed') {
                echo '<a href="#" onclick="confirmMoveoutPhotoChange(\'moveout_photo.php?contract_id=' . $contract_id . '&photo_id=' . $p['id'] . '\'); return false;" class="btn btn-primary">' . $button_text . '</a>';
              } else {
                echo '<a href="moveout_photo.php?contract_id=' . $contract_id . '&photo_id=' . $p['id'] . '" class="btn btn-primary">' . $button_text . '</a>';
              }
            } elseif ($status === 'moveout_tenant_signed' || $status === 'in_repair') {
              // 수리 완료 상태 표시만
            }
            ?>
          </td>
        </tr>
        <?php endforeach; else: ?>
        <tr><td colspan="5" style="text-align:center; color:#888;">등록된 사진이 없습니다.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <!-- 모바일 카드형 -->
  <div class="prop-card-list">
    <?php if ($photos): foreach ($photos as $p): ?>
      <div class="prop-card">
        <div class="prop-card-header">
          <div class="prop-card-address">
            <?php echo htmlspecialchars($p['part']); ?>
            <small><?php echo htmlspecialchars($p['created_at']); ?></small>
          </div>
        </div>
        <div class="prop-card-body">
          <?php if ($p['description']): ?>
            <p class="desc">설명: <?php echo nl2br(htmlspecialchars($p['description'])); ?></p>
          <?php endif; ?>
          <div style="display:flex; flex-wrap:wrap; gap:0.4rem; margin-top:0.5rem;">
            <?php 
            // 입주 사진을 overview 우선으로 정렬하여 출력
            $movein_photos_mobile = [];
            for ($i=0; $i<6; $i++) {
              $fp = $p['movein_file_path_0'. $i] ?? null;
              $shot_type = $p['movein_shot_type_0'. $i] ?? null;
              if ($fp) {
                $movein_photos_mobile[] = ['path' => $fp, 'type' => $shot_type, 'index' => $i];
              }
            }
            // overview를 먼저, 나머지를 뒤에 정렬
            usort($movein_photos_mobile, function($a, $b) {
              if ($a['type'] === 'overview' && $b['type'] !== 'overview') return -1;
              if ($a['type'] !== 'overview' && $b['type'] === 'overview') return 1;
              return $a['index'] - $b['index']; // 같은 타입이면 원래 순서 유지
            });
            
            // 퇴거 사진을 overview 우선으로 정렬하여 출력
            $moveout_photos_mobile = [];
            for ($i=0; $i<6; $i++) {
              $fp = $p['moveout_file_path_0'. $i] ?? null;
              $shot_type = $p['moveout_shot_type_0'. $i] ?? null;
              if ($fp) {
                $moveout_photos_mobile[] = ['path' => $fp, 'type' => $shot_type, 'index' => $i];
              }
            }
            // overview를 먼저, 나머지를 뒤에 정렬
            usort($moveout_photos_mobile, function($a, $b) {
              if ($a['type'] === 'overview' && $b['type'] !== 'overview') return -1;
              if ($a['type'] !== 'overview' && $b['type'] === 'overview') return 1;
              return $a['index'] - $b['index']; // 같은 타입이면 원래 순서 유지
            });
            
            // 파손 사진이 있는 경우 입주/퇴거 구분해서 표시
            if (!empty($moveout_photos_mobile)) {
              echo '<div style="width: 100%; margin-bottom: 0.8rem;">';
              echo '<div style="font-size: 0.8rem; font-weight: 600; color: #1976d2; margin-bottom: 0.3rem;">📋 입주시</div>';
              echo '<div style="display: flex; flex-wrap: wrap; gap: 0.3rem; margin-bottom: 0.8rem;">';
              foreach ($movein_photos_mobile as $photo) {
                $is_overview = ($photo['type'] === 'overview');
                $width = $is_overview ? '100' : '65';
                $border = $is_overview ? ' border:2px solid #1976d2;' : '';
                echo '<img src="'.htmlspecialchars($photo['path']).'" alt="입주사진" style="width:'.$width.'px; height:auto; border-radius:8px;'.$border.' cursor:pointer;" class="photo-thumb" data-photo-id="'.$p['id'].'" title="'.($is_overview ? '위치확인용' : '세부사진').'">';
              }
              echo '</div>';
              echo '<div style="font-size: 0.8rem; font-weight: 600; color: #dc3545; margin-bottom: 0.3rem;">🔧 퇴거시 (파손)</div>';
              echo '<div style="display: flex; flex-wrap: wrap; gap: 0.3rem;">';
              foreach ($moveout_photos_mobile as $photo) {
                $is_overview = ($photo['type'] === 'overview');
                $width = $is_overview ? '100' : '65';
                $border = $is_overview ? ' border:2px solid #dc3545;' : ' border:1px solid #dc3545;';
                echo '<img src="'.htmlspecialchars($photo['path']).'" alt="퇴거사진" style="width:'.$width.'px; height:auto; border-radius:8px;'.$border.' cursor:pointer;" class="photo-thumb" data-photo-id="'.$p['id'].'" title="'.($is_overview ? '위치확인용' : '세부사진').'">';
              }
              echo '</div>';
              echo '</div>';
            } else {
              // 퇴거 사진이 없는 경우 기존 방식
              foreach ($movein_photos_mobile as $photo) {
                $is_overview = ($photo['type'] === 'overview');
                $width = $is_overview ? '100' : '65';
                $border = $is_overview ? ' border:2px solid #1976d2;' : '';
                echo '<img src="'.htmlspecialchars($photo['path']).'" alt="입주사진" style="width:'.$width.'px; height:auto; border-radius:8px;'.$border.' cursor:pointer;" class="photo-thumb" data-photo-id="'.$p['id'].'" title="'.($is_overview ? '위치확인용' : '세부사진').'">';
              }
            }
            ?>
          </div>
          <div style="margin-top:0.7rem; text-align:right;">
            <?php
            if ($status === 'movein_photo' || $status === 'movein_landlord_signed') {
              if ($status === 'movein_landlord_signed') {
                echo '<a href="#" onclick="confirmPhotoChange(\'photo_upload.php?contract_id=' . $contract_id . '&photo_id=' . $p['id'] . '\'); return false;" class="btn btn-primary">수정</a> <button class="btn btn-warning" onclick="confirmDelete(' . $p['id'] . ')" style="margin-left:1.5rem;">삭제</button>';
              } else {
                echo '<a href="photo_upload.php?contract_id=' . $contract_id . '&photo_id=' . $p['id'] . '" class="btn btn-primary">수정</a> <button class="btn btn-warning" onclick="confirmDelete(' . $p['id'] . ')" style="margin-left:1.5rem;">삭제</button>';
              }
            } elseif ($status === 'movein_tenant_signed') {
              // 해당 부위에 퇴거 사진이 있는지 확인
              $has_this_moveout_photos = false;
              for ($i = 1; $i <= 6; $i++) {
                $index_str = str_pad($i, 2, '0', STR_PAD_LEFT);
                if (!empty($p['moveout_file_path_' . $index_str])) {
                  $has_this_moveout_photos = true;
                  break;
                }
              }
              $button_text = $has_this_moveout_photos ? '파손사진 재등록' : '파손사진 등록';
              echo '<a href="moveout_photo.php?contract_id=' . $contract_id . '&photo_id=' . $p['id'] . '" class="btn btn-primary">' . $button_text . '</a>';
            } elseif ($status === 'moveout_photo' || $status === 'moveout_landlord_signed') {
              // 해당 부위에 퇴거 사진이 있는지 확인
              $has_this_moveout_photos = false;
              for ($i = 1; $i <= 6; $i++) {
                $index_str = str_pad($i, 2, '0', STR_PAD_LEFT);
                if (!empty($p['moveout_file_path_' . $index_str])) {
                  $has_this_moveout_photos = true;
                  break;
                }
              }
              $button_text = $has_this_moveout_photos ? '파손사진 재등록' : '파손사진 등록';
              if ($status === 'moveout_landlord_signed') {
                echo '<a href="#" onclick="confirmMoveoutPhotoChange(\'moveout_photo.php?contract_id=' . $contract_id . '&photo_id=' . $p['id'] . '\'); return false;" class="btn btn-primary">' . $button_text . '</a>';
              } else {
                echo '<a href="moveout_photo.php?contract_id=' . $contract_id . '&photo_id=' . $p['id'] . '" class="btn btn-primary">' . $button_text . '</a>';
              }
            } elseif ($status === 'moveout_tenant_signed' || $status === 'in_repair') {
              // 수리 완료 상태 표시만 (모바일)
            }
            ?>
          </div>
        </div>
      </div>
    <?php endforeach; else: ?>
      <div class="prop-card" style="text-align:center; color:#888;">등록된 사진이 없습니다.</div>
    <?php endif; ?>
  </div>

  <?php if (count($photos) > 3): ?>

    <?php if ($status === 'movein_photo' || $status === 'movein_landlord_signed'): ?>
      <div class="photo-btn-container" id="add-photo-btn-bottom">
        <?php
        if ($status === 'movein_photo') {
          echo '<a href="photo_upload.php?contract_id=' . $contract_id . '" class="btn btn-primary">입주사진 추가 등록</a>';
        } elseif ($status === 'movein_landlord_signed') {
          echo '<a href="#" onclick="confirmPhotoChange(\'photo_upload.php?contract_id=' . $contract_id . '\'); return false;" class="btn btn-primary">입주사진 추가 등록</a>';
        }
        ?>
      </div>
    <?php endif; ?>

    <div class="address-title-btns mobile-section">
      <?php
      if ($status === 'empty') {
        echo '<div class="btn-container">';
        echo '<a href="photo_upload.php?contract_id=' . $contract_id . '" class="btn btn-primary">입주사진 등록</a>';
        echo '</div>';
      } elseif ($status === 'movein_photo') {
        echo '<div class="btn-container">';
        //echo '<a href="photo_upload.php?contract_id=' . $contract_id . '" class="btn btn-primary">입주사진 추가 등록</a>';
        if (!empty($photos)) { // 사진이 있을 때만 서명/전송 버튼 표시
          if ($user_role_in_contract === 'agent') {
            echo '<button class="btn btn-warning">임대인에게 사진 전송</button>';
          } else {
            echo '<button class="btn btn-success" onclick="handleSign()">본인 서명하기</button>';
          }
        }
        echo '</div>';
        // 사진이 있을 때 설명 문구 추가
        if (!empty($photos)) {
          echo '<div class="notice-text">';
          if ($user_role_in_contract === 'agent') {
            echo '사진 전송은 모든 사진 등록이 끝난 후 진행하세요.';
          } else {
            echo '본인 서명은 모든 사진 등록이 끝난 후 진행하세요.';
          }
          echo '</div>';
        }
      } elseif ($status === 'movein_landlord_signed') {
        echo '<div class="btn-container">';
        if (!$has_unconfirmed_mismatch) {
          $button_text = $tenant_request_sent ? '임차인에게 사진 재전송' : '임차인에게 사진 전송';
          $onclick = $tenant_request_sent ? 'confirmResendLink(\'tenant\')' : 'generateShareLink(\'tenant\')';
          echo '<button class="btn btn-warning" onclick="' . $onclick . '">' . $button_text . '</button>';
        }
        //echo '<a href="#" onclick="confirmPhotoChange(\'photo_upload.php?contract_id=' . $contract_id . '\'); return false;" class="btn btn-primary">입주사진 추가 등록</a>';
        echo '</div>';
        // 사진이 있을 때 설명 문구 추가
        if (!empty($photos)) {
          echo '<div class="notice-text">';
          echo '사진 전송은 모든 사진 등록이 끝난 후 진행하세요.';
          echo '</div>';
        }
      } elseif ($status === 'moveout_photo') {
        echo '<div class="btn-container">';
        if (!empty($photos) && !$has_unconfirmed_mismatch) { // 사진이 있고 불일치 미확인이 없을 때만 서명/전송 버튼 표시
          if ($user_role_in_contract === 'agent') {
            $button_text = $landlord_request_sent ? '임대인에게 파손사진 재전송' : '임대인에게 파손사진 전송';
            $onclick = $landlord_request_sent ? 'confirmResendLink(\'landlord\')' : 'generateShareLink(\'landlord\')';
            echo '<button class="btn btn-warning" onclick="' . $onclick . '">' . $button_text . '</button>';
          } else {
            echo '<button class="btn btn-success" onclick="handleSign()">퇴거 사진에 본인 서명하기</button>';
          }
        }
        echo '</div>';
        // 사진이 있을 때 설명 문구 추가 (파손사진 등록 버튼은 각 사진 항목에 있으므로 전체적인 안내만)
        if (!empty($photos)) {
          echo '<div class="notice-text">';
          if ($user_role_in_contract === 'agent') {
            echo '<strong>파손사진 전송은 모든 사진 등록이 끝난 후 진행하세요.</strong>';
          } else {
            echo '<strong>본인 서명은 모든 사진 등록이 끝난 후 진행하세요.</strong>';
          }
          echo '</div>';
        }
      } elseif ($status === 'moveout_landlord_signed') {
        echo '<div class="btn-container">';
        if (!$has_unconfirmed_mismatch) {
          $button_text = $tenant_request_sent ? '임차인에게 파손사진 재전송' : '임차인에게 파손사진 전송';
          $onclick = $tenant_request_sent ? 'confirmResendLink(\'tenant\')' : 'generateShareLink(\'tenant\')';
          echo '<button class="btn btn-warning" onclick="' . $onclick . '">' . $button_text . '</button>';
        }
        echo '</div>';
        // 사진이 있을 때 설명 문구 추가 (파손사진 등록 버튼은 각 사진 항목에 있으므로 전체적인 안내만)
        if (!empty($photos)) {
          echo '<div class="notice-text">';
          echo '파손사진 전송은 모든 사진 등록이 끝난 후 진행하세요.';
          echo '</div>';
        }
      } elseif ($status === 'moveout_tenant_signed' || $status === 'in_repair') {
        // 퇴거 사진이 있는지 확인 (사진 3개 이상일 때 표시)
        $has_moveout_photos_large = false;
        foreach ($photos as $p) {
          for ($i = 1; $i <= 6; $i++) {
            $field_name = 'moveout_file_path_0' . $i;
            if (!empty($p[$field_name])) {
              $has_moveout_photos_large = true;
              break 2;
            }
          }
        }
        
        echo '<div class="btn-container">';
        if (($status === 'moveout_tenant_signed' || $status === 'in_repair') && $has_moveout_photos_large) {
          echo '<button class="btn btn-warning" onclick="generateRepairShareLink()" style="font-weight: 600; box-shadow: 0 2px 8px rgba(255, 193, 7, 0.3);">🔧 수리업체에 사진 보내기</button>';
        }
        echo '<button class="btn-complete" onclick="finishContract()">계약 종료하기</button>';
        echo '</div>';
      }
      ?>
    </div>
  <?php endif; ?>

  <!-- 서명 정보 섹션 -->
  <?php if (!empty($signatures)): ?>
    <div class="signatures-container" style="max-width: 900px; margin: 2rem auto; padding: 0 1rem;">
      <div style="background: #fff; border-radius: 12px; box-shadow: 0 4px 18px rgba(0,100,255,0.05); border: 1px solid #e3eaf2; padding: 1.5rem;">
        <h3 style="margin: 0 0 1.5rem 0; padding-bottom: 1rem; border-bottom: 2px solid #e3eaf2; font-size: 1.2rem; font-weight: 600; color: #212529;">
          ✍️ 완료된 서명 목록
        </h3>
        
        <div style="display: grid; gap: 1rem;">
          <?php foreach ($signatures as $signature): ?>
            <div style="background: #f8f9fa; border-radius: 8px; padding: 1.2rem; border-left: 4px solid #28a745;">
              <div class="signature-item">
                <!-- 서명 정보 -->
                <div class="signature-info">
                  <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 0.8rem;">
                    <div>
                      <strong style="color: #333; font-size: 0.9rem;">서명자:</strong>
                      <div style="color: #555; font-size: 1rem;"><?php echo htmlspecialchars($signature['signer_name']); ?></div>
                    </div>
                    <div>
                      <strong style="color: #333; font-size: 0.9rem;">전화번호:</strong>
                      <div style="color: #555; font-size: 1rem;"><?php echo htmlspecialchars($signature['signer_phone']); ?></div>
                    </div>
                    <div>
                      <strong style="color: #333; font-size: 0.9rem;">역할:</strong>
                      <div style="color: #555; font-size: 1rem;"><?php echo $signature['signer_role'] === 'landlord' ? '임대인' : ($signature['signer_role'] === 'tenant' ? '임차인' : '중개사'); ?></div>
                    </div>
                    <div>
                      <strong style="color: #333; font-size: 0.9rem;">구분:</strong>
                      <div style="color: #555; font-size: 1rem;"><?php echo $signature['purpose'] === 'movein' ? '입주' : '퇴거'; ?> 확인</div>
                    </div>
                  </div>
                  <div style="margin-top: 0.8rem; padding-top: 0.8rem; border-top: 1px solid #dee2e6;">
                    <strong style="color: #333; font-size: 0.9rem;">서명일시:</strong>
                    <span style="color: #666; font-size: 0.95rem;"><?php echo date('Y년 m월 d일 H:i', strtotime($signature['signed_at'])); ?></span>
                  </div>
                </div>
                
                <!-- 서명 이미지 -->
                <?php if (!empty($signature['signature_data'])): ?>
                  <div class="signature-image">
                    <div style="text-align: center; margin-bottom: 0.5rem;">
                      <strong style="color: #333; font-size: 0.9rem;">서명</strong>
                    </div>
                    <div style="background: white; border: 2px solid #ddd; border-radius: 8px; padding: 0.5rem; display: inline-block;">
                      <?php
                      // base64 데이터인지 파일 경로인지 확인
                      $signature_src = $signature['signature_data'];
                      if (strpos($signature_src, 'data:image/') === 0) {
                          // base64 데이터인 경우 그대로 사용
                          $display_src = $signature_src;
                      } else {
                          // 파일 경로인 경우 파일 존재 여부 확인
                          if (file_exists($signature_src)) {
                              $display_src = $signature_src;
                          } else {
                              $display_src = null;
                          }
                      }
                      ?>
                      <?php if ($display_src): ?>
                        <img src="<?php echo htmlspecialchars($display_src); ?>" 
                             alt="서명" 
                             onclick="showSignature('<?php echo htmlspecialchars($display_src); ?>')"
                             style="max-width: 200px; max-height: 100px; cursor: pointer; display: block; border-radius: 4px;" 
                             title="클릭하면 크게 볼 수 있습니다">
                        <div style="text-align: center; margin-top: 0.5rem;">
                          <button onclick="showSignature('<?php echo htmlspecialchars($display_src); ?>')" 
                                  style="background: #007bff; color: white; border: none; padding: 0.3rem 0.6rem; border-radius: 4px; cursor: pointer; font-size: 0.8rem;">
                            크게 보기
                          </button>
                        </div>
                      <?php else: ?>
                        <div style="background: #e9ecef; border: 2px dashed #adb5bd; border-radius: 8px; padding: 1rem; text-align: center; color: #6c757d; font-size: 0.9rem; width: 200px;">
                          서명 이미지<br>없음
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php else: ?>
                  <div class="signature-image" style="display: flex; align-items: center;">
                    <div class="signature-placeholder" style="background: #e9ecef; border: 2px dashed #adb5bd; border-radius: 8px; padding: 1rem; text-align: center; color: #6c757d; font-size: 0.9rem; width: 200px;">
                      서명 이미지<br>없음
                    </div>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  <?php endif; ?>
</main>
<!-- 사진 모달 -->
<div id="photoModal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100vw; height:100vh; background:rgba(0,0,0,0.85); align-items:center; justify-content:center;">
  <div id="photoModalContent" style="background:#fff; border-radius:12px; max-width:98vw; max-height:92vh; padding:2.2rem 1.2rem 1.2rem 1.2rem; overflow:auto; position:relative; box-shadow:0 8px 32px rgba(0,0,0,0.18);">
    <button id="photoModalClose" style="position:absolute; top:1.1rem; right:1.1rem; background:#222; color:#fff; border:none; border-radius:50%; width:36px; height:36px; font-size:1.3rem; cursor:pointer;">&times;</button>
    <div id="photoModalImages" style="display:flex; flex-wrap:wrap; gap:1.2rem; justify-content:center; align-items:center;"></div>
  </div>
</div>
<?php include 'footer.inc'; ?>

<!-- 사진 전송 팝업 모달 -->
<div id="shareModal" style="display: none; position: fixed; z-index: 10000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); align-items: center; justify-content: center;">
  <div style="background: white; border-radius: 16px; padding: 2rem; max-width: 450px; width: 90%; margin: 1rem; box-shadow: 0 12px 40px rgba(0, 0, 0, 0.2);">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
      <h3 style="margin: 0; color: #333; font-size: 1.3rem; font-weight: 600;">사진 전송</h3>
      <button id="shareModalClose" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #666; padding: 0.2rem 0.5rem; border-radius: 4px; line-height: 1;" title="닫기">×</button>
    </div>
    
    <div id="shareModalContent" style="margin-bottom: 1.5rem;">
      <!-- 내용이 동적으로 추가됩니다 -->
    </div>
    
    <div id="shareInfo" style="background: #f8f9fa; border-radius: 8px; padding: 1.2rem; margin-bottom: 1.5rem; border: 1px solid #e9ecef;">
      <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.8rem;">
        <span style="font-weight: 600; color: #333; font-size: 0.9rem;">공유 URL:</span>
      </div>
      <div style="background: white; border: 1px solid #ddd; border-radius: 4px; padding: 0.7rem; font-family: monospace; font-size: 0.85rem; word-break: break-all; color: #666; margin-bottom: 1rem;">
        <span id="shareUrl"></span>
      </div>
      
      <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.8rem;">
        <span style="font-weight: 600; color: #333; font-size: 0.9rem;">비밀번호:</span>
      </div>
      <div style="background: white; border: 1px solid #ddd; border-radius: 4px; padding: 0.7rem; font-family: monospace; font-size: 1.1rem; color: #333; font-weight: 600; text-align: center; letter-spacing: 0.2rem;">
        <span id="sharePassword"></span>
      </div>
    </div>
    
    <div style="text-align: center;">
      <button id="copyShareInfo" class="btn btn-primary" style="background: #007bff; color: white; border: none; padding: 0.8rem 2rem; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 1rem; box-shadow: 0 2px 8px rgba(0, 123, 255, 0.3);">
        📋 복사하기
      </button>
    </div>
    
    <div style="margin-top: 1rem; font-size: 0.85rem; color: #666; text-align: center; line-height: 1.5;">
      💡 복사된 내용을 카카오톡이나 문자로 전송하세요
    </div>
  </div>
</div>

<script>
// 돌아가기 버튼 호버 효과
document.addEventListener('DOMContentLoaded', function() {
  const backToListBtn = document.getElementById('backToListBtn');
  if (backToListBtn) {
    backToListBtn.addEventListener('mouseenter', function() {
      this.style.backgroundColor = '#5a6268';
      this.style.transform = 'translateY(-2px)';
      this.style.boxShadow = '0 4px 12px rgba(108, 117, 125, 0.4)';
    });
    
    backToListBtn.addEventListener('mouseleave', function() {
      this.style.backgroundColor = '#6c757d';
      this.style.transform = 'translateY(0)';
      this.style.boxShadow = '0 2px 6px rgba(108, 117, 125, 0.3)';
    });
    
    backToListBtn.addEventListener('click', function() {
      this.style.transform = 'scale(0.95)';
      setTimeout(() => {
        this.style.transform = 'scale(1)';
      }, 150);
    });
  }
});

// 사진 전송 링크 생성 함수
function generateShareLink(shareType) {
  const contractId = <?php echo $contract_id; ?>;
  
  // 로딩 표시
  const loadingModal = document.createElement('div');
  loadingModal.style.cssText = `
    position: fixed;
    z-index: 10001;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
  `;
  
  loadingModal.innerHTML = `
    <div style="background: white; border-radius: 12px; padding: 2rem; text-align: center;">
      <div style="font-size: 1.1rem; color: #333;">공유 링크를 생성하는 중...</div>
    </div>
  `;
  
  document.body.appendChild(loadingModal);
  
  // AJAX 요청
  const xhr = new XMLHttpRequest();
  xhr.open('POST', window.location.href, true);
  xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
  
  xhr.onreadystatechange = function() {
    if (xhr.readyState === 4) {
      document.body.removeChild(loadingModal);
      
      if (xhr.status === 200) {
        const response = JSON.parse(xhr.responseText);
        if (response.success) {
          showShareModal(response);
        } else {
          alert('오류: ' + (response.message || '알 수 없는 오류'));
        }
      } else {
        alert('서버 오류가 발생했습니다.');
      }
    }
  };
  
  xhr.send('action=generate_share_link&contract_id=' + contractId + '&share_type=' + shareType);
}

// 수리업체 사진 전송 링크 생성 함수
function generateRepairShareLink() {
  const contractId = <?php echo $contract_id; ?>;
  
  // 로딩 표시
  const loadingModal = document.createElement('div');
  loadingModal.style.cssText = `
    position: fixed;
    z-index: 10001;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
  `;
  
  loadingModal.innerHTML = `
    <div style="background: white; border-radius: 12px; padding: 2rem; text-align: center;">
      <div style="font-size: 1.1rem; color: #333;">수리업체용 링크를 생성하는 중...</div>
    </div>
  `;
  
  document.body.appendChild(loadingModal);
  
  // AJAX 요청
  const xhr = new XMLHttpRequest();
  xhr.open('POST', window.location.href, true);
  xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
  
  xhr.onreadystatechange = function() {
    if (xhr.readyState === 4) {
      document.body.removeChild(loadingModal);
      
      if (xhr.status === 200) {
        const response = JSON.parse(xhr.responseText);
        if (response.success) {
          showRepairShareModal(response);
        } else {
          alert('오류: ' + (response.message || '알 수 없는 오류'));
        }
      } else {
        alert('서버 오류가 발생했습니다.');
      }
    }
  };
  
  xhr.send('action=generate_repair_share_link&contract_id=' + contractId);
}

// 계약 종료 함수
function finishContract() {
  if (!confirm('계약을 종료 처리하시겠습니까?\n\n종료된 계약은 모든 기능이 비활성화되고 사진 확인만 가능해집니다.')) {
    return;
  }
  
  const contractId = <?php echo $contract_id; ?>;
  
  // 로딩 표시
  const loadingModal = document.createElement('div');
  loadingModal.style.cssText = `
    position: fixed;
    z-index: 10001;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
  `;
  
  loadingModal.innerHTML = `
    <div style="background: white; border-radius: 12px; padding: 2rem; text-align: center;">
      <div style="font-size: 1.1rem; color: #333;">계약을 종료하는 중...</div>
    </div>
  `;
  
  document.body.appendChild(loadingModal);
  
  // AJAX 요청
  const xhr = new XMLHttpRequest();
  xhr.open('POST', window.location.href, true);
  xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
  
  xhr.onreadystatechange = function() {
    if (xhr.readyState === 4) {
      document.body.removeChild(loadingModal);
      
      if (xhr.status === 200) {
        const response = JSON.parse(xhr.responseText);
        if (response.success) {
          alert('계약이 성공적으로 종료되었습니다.');
          // contracts.php로 리다이렉트
          window.location.href = 'contracts.php?property_id=<?php echo $contract['property_id']; ?>';
        } else {
          alert('오류: ' + (response.message || '알 수 없는 오류'));
        }
      } else {
        alert('서버 오류가 발생했습니다.');
      }
    }
  };
  
  xhr.send('action=finish_contract&contract_id=' + contractId);
}

// 공유 모달 표시
function showShareModal(data) {
  const modal = document.getElementById('shareModal');
  const content = document.getElementById('shareModalContent');
  const urlSpan = document.getElementById('shareUrl');
  const passwordSpan = document.getElementById('sharePassword');
  
  // 내용 설정
  content.innerHTML = `
    <div style="text-align: center; margin-bottom: 1rem;">
      <div style="font-size: 1.1rem; color: #333; margin-bottom: 0.5rem;">
        <strong>${data.recipient}</strong>에게 <strong>${data.photo_type}</strong>을 전송합니다.
      </div>
      <div style="font-size: 0.9rem; color: #666;">
        아래 URL과 비밀번호를 전달해주세요.
      </div>
    </div>
  `;
  
  urlSpan.textContent = data.url;
  passwordSpan.textContent = data.password;
  
  modal.style.display = 'flex';
}

// 수리업체용 공유 모달 표시
function showRepairShareModal(data) {
  const modal = document.getElementById('shareModal');
  const content = document.getElementById('shareModalContent');
  const urlSpan = document.getElementById('shareUrl');
  const passwordSpan = document.getElementById('sharePassword');
  
  // 내용 설정
  content.innerHTML = `
    <div style="text-align: center; margin-bottom: 1rem;">
      <div style="font-size: 1.1rem; color: #333; margin-bottom: 0.5rem;">
        <strong>${data.recipient}</strong>에게 <strong>${data.photo_type}</strong>을 전송합니다.
      </div>
      <div style="font-size: 0.9rem; color: #666;">
        아래 URL을 전달해주세요. (비밀번호 불필요)
      </div>
    </div>
  `;
  
  urlSpan.textContent = data.url;
  
  // 비밀번호 부분 숨기기
  const shareInfo = document.getElementById('shareInfo');
  const passwordSection = shareInfo.children[2]; // 비밀번호 라벨
  const passwordBox = shareInfo.children[3]; // 비밀번호 박스
  if (passwordSection) passwordSection.style.display = 'none';
  if (passwordBox) passwordBox.style.display = 'none';
  
  modal.style.display = 'flex';
}

// 클립보드 복사 기능
function copyToClipboard() {
  const url = document.getElementById('shareUrl').textContent;
  const passwordElement = document.getElementById('sharePassword');
  const password = passwordElement.textContent;
  const recipient = document.getElementById('shareModalContent').querySelector('strong').textContent;
  const photoType = document.getElementById('shareModalContent').querySelectorAll('strong')[1].textContent;
  
  // PHP에서 전달받은 임대물 주소
  const propertyAddress = '<?php echo htmlspecialchars($contract['address'] . ($contract['detail_address'] ? ', ' . $contract['detail_address'] : '')); ?>';
  
  // 비밀번호 부분이 숨겨져 있는지 확인 (수리업체용)
  const shareInfo = document.getElementById('shareInfo');
  const passwordSection = shareInfo.children[2]; // 비밀번호 라벨
  const isPasswordHidden = passwordSection && passwordSection.style.display === 'none';
  
  let copyText;
  if (isPasswordHidden) {
    // 수리업체용 (비밀번호 없음)
    copyText = `[${photoType} 확인 안내]

${recipient}님, 안녕하세요.
${photoType} 확인을 위한 링크를 전송드립니다.

임대물 주소: ${propertyAddress}

🔗 링크: ${url}

링크를 클릭하여 입주 및 퇴거 사진을 비교 확인하실 수 있습니다.

감사합니다.`;
  } else {
    // 일반 서명 요청용 (비밀번호 포함)
    copyText = `[${photoType} 확인 안내]

${recipient}님, 안녕하세요.
${photoType} 확인을 위한 링크를 전송드립니다.

임대물 주소: ${propertyAddress}

🔗 링크: ${url}

🔐 비밀번호: ${password}

링크를 클릭하신 후 비밀번호를 입력하여 사진을 확인하시고 서명해주세요.

감사합니다.`;
  }
  
  navigator.clipboard.writeText(copyText).then(function() {
    const copyBtn = document.getElementById('copyShareInfo');
    const originalText = copyBtn.textContent;
    copyBtn.textContent = '✅ 복사됨!';
    copyBtn.style.background = '#28a745';
    
    setTimeout(function() {
      copyBtn.textContent = originalText;
      copyBtn.style.background = '#007bff';
    }, 2000);
  }).catch(function() {
    // 클립보드 API가 지원되지 않는 경우 대체 방법
    const textArea = document.createElement('textarea');
    textArea.value = copyText;
    textArea.style.position = 'fixed';
    textArea.style.opacity = '0';
    document.body.appendChild(textArea);
    textArea.select();
    document.execCommand('copy');
    document.body.removeChild(textArea);
    
    alert('클립보드에 복사되었습니다!');
  });
}

// 모달 이벤트 리스너
document.getElementById('shareModalClose').onclick = function() {
  // 비밀번호 부분 다시 표시 (다음 모달 사용을 위해)
  const shareInfo = document.getElementById('shareInfo');
  const passwordSection = shareInfo.children[2]; // 비밀번호 라벨
  const passwordBox = shareInfo.children[3]; // 비밀번호 박스
  if (passwordSection) passwordSection.style.display = 'flex';
  if (passwordBox) passwordBox.style.display = 'block';
  
  document.getElementById('shareModal').style.display = 'none';
  // 사진 전송 완료 후 contracts.php로 리다이렉트
  window.location.href = 'contracts.php?property_id=<?php echo $contract['property_id']; ?>';
};

document.getElementById('shareModal').onclick = function(e) {
  if (e.target === this) {
    // 비밀번호 부분 다시 표시 (다음 모달 사용을 위해)
    const shareInfo = document.getElementById('shareInfo');
    const passwordSection = shareInfo.children[2]; // 비밀번호 라벨
    const passwordBox = shareInfo.children[3]; // 비밀번호 박스
    if (passwordSection) passwordSection.style.display = 'flex';
    if (passwordBox) passwordBox.style.display = 'block';
    
    this.style.display = 'none';
    // 사진 전송 완료 후 contracts.php로 리다이렉트
    window.location.href = 'contracts.php?property_id=<?php echo $contract['property_id']; ?>';
  }
};

document.getElementById('copyShareInfo').onclick = function() {
  copyToClipboard();
  
  // 복사 후 잠시 대기 후 모달 닫기
  setTimeout(function() {
    // 비밀번호 부분 다시 표시 (다음 모달 사용을 위해)
    const shareInfo = document.getElementById('shareInfo');
    const passwordSection = shareInfo.children[2]; // 비밀번호 라벨
    const passwordBox = shareInfo.children[3]; // 비밀번호 박스
    if (passwordSection) passwordSection.style.display = 'flex';
    if (passwordBox) passwordBox.style.display = 'block';
    
    document.getElementById('shareModal').style.display = 'none';
    // 사진 전송 완료 후 contracts.php로 리다이렉트
    window.location.href = 'contracts.php?property_id=<?php echo $contract['property_id']; ?>';
  }, 1500);
};

// 재전송 확인 함수
function confirmResendLink(shareType) {
  const roleName = shareType === 'landlord' ? '임대인' : '임차인';
  if (confirm(`${roleName}에게 서명 요청을 재전송하시겠습니까?\n\n⚠️ 주의사항:\n- 기존에 보낸 링크는 더 이상 사용할 수 없습니다\n- 새로운 링크와 비밀번호가 생성됩니다\n- ${roleName}에게 새로운 정보를 다시 전달해야 합니다`)) {
    generateShareLink(shareType);
  }
}

// 기존 함수들...
function handleSign() {
  // 모바일/태블릿 기기 판별 (iPad, Android tablet 포함)
  var isMobileOrTablet = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini|Tablet|PlayBook|Silk|Kindle|Nexus 7|Nexus 10|KFAPWI|SM-T|SCH-I800|GT-P|GT-N|SHW-M180S|SGH-T849|SHW-M180L|SHW-M180K/i.test(navigator.userAgent) || (navigator.userAgent.includes('Macintosh') && 'ontouchend' in document);
  if (isMobileOrTablet) {
    window.location.href = 'sign_photo.php?contract_id=<?php echo $contract_id; ?>';
  } else {
    alert('전자 서명은 모바일/태블릿 기기에서만 가능합니다.');
  }
}

// 사진 변경 확인 함수
function confirmPhotoChange(url) {
  if (confirm('사진을 추가 또는 수정한다면 임대인의 서명을 다시 받아야 합니다. 진행하시겠습니까?')) {
    window.location.href = url;
  }
}

// 파손사진 수정 확인 함수 (임대인 서명 완료 후)
function confirmMoveoutPhotoChange(url) {
  showMoveoutPhotoConfirm(url);
}

// 커스텀 확인 모달 표시 (취소 버튼에 포커스)
function showMoveoutPhotoConfirm(url) {
  // 모달 HTML 생성
  const modal = document.createElement('div');
  modal.style.cssText = `
    position: fixed;
    z-index: 10001;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.6);
    display: flex;
    align-items: center;
    justify-content: center;
  `;
  
  modal.innerHTML = `
    <div style="background: white; border-radius: 16px; padding: 2.5rem; max-width: 450px; width: 90%; margin: 1rem; box-shadow: 0 12px 40px rgba(0, 0, 0, 0.3);">
      <div style="text-align: center; margin-bottom: 2rem;">
        <div style="font-size: 3rem; color: #dc3545; margin-bottom: 1rem;">⚠️</div>
        <h3 style="margin: 0 0 1rem 0; color: #333; font-size: 1.3rem; font-weight: 600;">경고</h3>
        <p style="color: #666; font-size: 1.05rem; line-height: 1.5; margin: 0;">
          사진을 수정하면 임대인의 서명을 다시 받아야 합니다.<br>
          정말 진행하시겠습니까?
        </p>
      </div>
      
      <div style="display: flex; gap: 1rem; justify-content: center;">
        <button id="moveoutConfirmCancel" style="background: #6c757d; color: white; border: none; padding: 0.8rem 2rem; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 1rem; min-width: 100px;">
          취소
        </button>
        <button id="moveoutConfirmOk" style="background: #dc3545; color: white; border: none; padding: 0.8rem 2rem; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 1rem; min-width: 100px;">
          확인
        </button>
      </div>
    </div>
  `;
  
  document.body.appendChild(modal);
  
  // 취소 버튼에 포커스 (기본 선택)
  const cancelBtn = document.getElementById('moveoutConfirmCancel');
  const okBtn = document.getElementById('moveoutConfirmOk');
  
  setTimeout(() => {
    cancelBtn.focus();
  }, 100);
  
  // 이벤트 리스너
  cancelBtn.onclick = function() {
    document.body.removeChild(modal);
  };
  
  okBtn.onclick = function() {
    document.body.removeChild(modal);
    window.location.href = url;
  };
  
  // 모달 배경 클릭 시 취소
  modal.onclick = function(e) {
    if (e.target === modal) {
      document.body.removeChild(modal);
    }
  };
  
  // ESC 키로 취소
  const handleKeydown = function(e) {
    if (e.key === 'Escape') {
      document.body.removeChild(modal);
      document.removeEventListener('keydown', handleKeydown);
    } else if (e.key === 'Enter') {
      // Enter 키는 현재 포커스된 버튼 클릭
      document.activeElement.click();
    } else if (e.key === 'Tab') {
      // Tab 키로 버튼 간 이동
      e.preventDefault();
      if (document.activeElement === cancelBtn) {
        okBtn.focus();
      } else {
        cancelBtn.focus();
      }
    }
  };
  
  document.addEventListener('keydown', handleKeydown);
}
// 사진 모달 기능
const photoData = <?php echo json_encode(array_column($photos, null, 'id')); ?>;
document.querySelectorAll('.photo-thumb').forEach(function(img) {
  img.addEventListener('click', function() {
    const pid = this.getAttribute('data-photo-id');
    const p = photoData[pid];
    if (!p) return;
    const modal = document.getElementById('photoModal');
    const modalImages = document.getElementById('photoModalImages');
    modalImages.innerHTML = '';
    
    // movein과 moveout 사진을 인덱스별로 매칭
    const overviewPairs = [];
    const closeupPairs = [];
    
    for (let i = 1; i <= 6; i++) {
      const moveinFilePath = p['movein_file_path_0' + i];
      const moveinShotType = p['movein_shot_type_0' + i];
      const moveoutFilePath = p['moveout_file_path_0' + i];
      const moveoutShotType = p['moveout_shot_type_0' + i];
      
      if (moveinFilePath) {
        const pair = {
          index: i,
          movein: {src: moveinFilePath, type: 'movein'},
          moveout: moveoutFilePath ? {src: moveoutFilePath, type: 'moveout'} : null
        };
        
        if (moveinShotType === 'overview') {
          overviewPairs.push(pair);
        } else if (moveinShotType === 'closeup') {
          closeupPairs.push(pair);
        }
      }
    }
    
    // overview 사진 먼저 표시
    if (overviewPairs.length > 0) {
      const overviewSection = document.createElement('div');
      overviewSection.style.cssText = 'width: 100%; margin-bottom: 2.5rem;';
      
      const overviewTitle = document.createElement('h3');
      overviewTitle.textContent = '위치확인용 사진';
      overviewTitle.style.cssText = 'color: #1976d2; font-size: 1.2rem; margin-bottom: 1.5rem; text-align: center; font-weight: 600;';
      overviewSection.appendChild(overviewTitle);
      
      const overviewContainer = document.createElement('div');
      overviewContainer.style.cssText = 'display: flex; flex-direction: column; gap: 2rem; align-items: center;';
      
      overviewPairs.forEach(pair => {
        if (pair.moveout) {
          // moveout 사진이 있는 경우: 비교 보기
          const comparisonDiv = document.createElement('div');
          comparisonDiv.style.cssText = 'display: flex; gap: 1.5rem; align-items: flex-start; flex-wrap: wrap; justify-content: center; padding: 1rem; border: 2px solid #e9ecef; border-radius: 12px; background: #f8f9fa;';
          
          // 입주 시 사진
          const moveinDiv = document.createElement('div');
          moveinDiv.style.cssText = 'text-align: center;';
          
          const moveinLabel = document.createElement('h4');
          moveinLabel.textContent = '입주 시';
          moveinLabel.style.cssText = 'margin: 0 0 0.5rem 0; color: #1976d2; font-size: 1rem; font-weight: 600;';
          
          const moveinImg = document.createElement('img');
          moveinImg.src = pair.movein.src;
          moveinImg.style.cssText = 'max-width: 350px; max-height: 50vh; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); border: 2px solid #1976d2;';
          
          moveinDiv.appendChild(moveinLabel);
          moveinDiv.appendChild(moveinImg);
          
          // 퇴거 시 사진
          const moveoutDiv = document.createElement('div');
          moveoutDiv.style.cssText = 'text-align: center;';
          
          const moveoutLabel = document.createElement('h4');
          moveoutLabel.textContent = '퇴거 시';
          moveoutLabel.style.cssText = 'margin: 0 0 0.5rem 0; color: #28a745; font-size: 1rem; font-weight: 600;';
          
          const moveoutImg = document.createElement('img');
          moveoutImg.src = pair.moveout.src;
          moveoutImg.style.cssText = 'max-width: 350px; max-height: 50vh; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); border: 2px solid #28a745;';
          
          moveoutDiv.appendChild(moveoutLabel);
          moveoutDiv.appendChild(moveoutImg);
          
          comparisonDiv.appendChild(moveinDiv);
          comparisonDiv.appendChild(moveoutDiv);
          overviewContainer.appendChild(comparisonDiv);
        } else {
          // moveout 사진이 없는 경우: 단일 보기
          const singleDiv = document.createElement('div');
          singleDiv.style.cssText = 'text-align: center;';
          
          const img = document.createElement('img');
          img.src = pair.movein.src;
          img.style.cssText = 'max-width: 420px; max-height: 60vh; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.13); border: 2px solid #1976d2;';
          
          singleDiv.appendChild(img);
          overviewContainer.appendChild(singleDiv);
        }
      });
      
      overviewSection.appendChild(overviewContainer);
      modalImages.appendChild(overviewSection);
    }
    
    // closeup 사진 표시
    if (closeupPairs.length > 0) {
      const closeupSection = document.createElement('div');
      closeupSection.style.cssText = 'width: 100%;';
      
      const closeupTitle = document.createElement('h3');
      closeupTitle.textContent = '세부 사진';
      closeupTitle.style.cssText = 'color: #666; font-size: 1.2rem; margin-bottom: 1.5rem; text-align: center; font-weight: 600;';
      closeupSection.appendChild(closeupTitle);
      
      const closeupContainer = document.createElement('div');
      closeupContainer.style.cssText = 'display: flex; flex-direction: column; gap: 2rem; align-items: center;';
      
      closeupPairs.forEach(pair => {
        if (pair.moveout) {
          // moveout 사진이 있는 경우: 비교 보기
          const comparisonDiv = document.createElement('div');
          comparisonDiv.style.cssText = 'display: flex; gap: 1.5rem; align-items: flex-start; flex-wrap: wrap; justify-content: center; padding: 1rem; border: 2px solid #e9ecef; border-radius: 12px; background: #f8f9fa;';
          
          // 입주 시 사진
          const moveinDiv = document.createElement('div');
          moveinDiv.style.cssText = 'text-align: center;';
          
          const moveinLabel = document.createElement('h4');
          moveinLabel.textContent = '입주 시';
          moveinLabel.style.cssText = 'margin: 0 0 0.5rem 0; color: #1976d2; font-size: 1rem; font-weight: 600;';
          
          const moveinImg = document.createElement('img');
          moveinImg.src = pair.movein.src;
          moveinImg.style.cssText = 'max-width: 300px; max-height: 45vh; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); border: 2px solid #1976d2;';
          
          moveinDiv.appendChild(moveinLabel);
          moveinDiv.appendChild(moveinImg);
          
          // 퇴거 시 사진
          const moveoutDiv = document.createElement('div');
          moveoutDiv.style.cssText = 'text-align: center;';
          
          const moveoutLabel = document.createElement('h4');
          moveoutLabel.textContent = '퇴거 시';
          moveoutLabel.style.cssText = 'margin: 0 0 0.5rem 0; color: #28a745; font-size: 1rem; font-weight: 600;';
          
          const moveoutImg = document.createElement('img');
          moveoutImg.src = pair.moveout.src;
          moveoutImg.style.cssText = 'max-width: 300px; max-height: 45vh; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); border: 2px solid #28a745;';
          
          moveoutDiv.appendChild(moveoutLabel);
          moveoutDiv.appendChild(moveoutImg);
          
          comparisonDiv.appendChild(moveinDiv);
          comparisonDiv.appendChild(moveoutDiv);
          closeupContainer.appendChild(comparisonDiv);
        } else {
          // moveout 사진이 없는 경우: 단일 보기
          const singleDiv = document.createElement('div');
          singleDiv.style.cssText = 'text-align: center;';
          
          const img = document.createElement('img');
          img.src = pair.movein.src;
          img.style.cssText = 'max-width: 320px; max-height: 50vh; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.13);';
          
          singleDiv.appendChild(img);
          closeupContainer.appendChild(singleDiv);
        }
      });
      
      closeupSection.appendChild(closeupContainer);
      modalImages.appendChild(closeupSection);
    }
    
    modal.style.display = 'flex';
  });
});
document.getElementById('photoModalClose').onclick = function() {
  document.getElementById('photoModal').style.display = 'none';
};
document.getElementById('photoModal').onclick = function(e) {
  if (e.target === this) this.style.display = 'none';
};

// 사진 삭제 확인 모달
function confirmDelete(photoId) {
  const modal = document.createElement('div');
  modal.style.cssText = `
    position: fixed;
    z-index: 10000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
  `;
  
  const modalContent = document.createElement('div');
  modalContent.style.cssText = `
    background: white;
    border-radius: 12px;
    padding: 2rem;
    max-width: 400px;
    margin: 1rem;
    box-shadow: 0 8px 32px rgba(0,0,0,0.3);
  `;
  
  modalContent.innerHTML = `
    <h3 style="margin-top: 0; color: #d32f2f; font-size: 1.2rem;">사진 삭제 확인</h3>
    <p style="margin: 1rem 0; color: #555; line-height: 1.5;">
      이 사진을 삭제하면 <strong>복구할 수 없습니다</strong>.<br>
      정말 삭제하시겠습니까?
    </p>
    <div style="display: flex; gap: 0.7rem; justify-content: flex-end; margin-top: 1.5rem;">
      <button id="cancelDelete" class="btn btn-secondary" style="background: #6c757d; color: white; border: none; padding: 0.6rem 1.2rem; border-radius: 6px; cursor: pointer; font-weight: 600;">취소</button>
      <button id="confirmDeleteBtn" class="btn btn-danger" style="background: #dc3545; color: white; border: none; padding: 0.6rem 1.2rem; border-radius: 6px; cursor: pointer; font-weight: 600;">삭제</button>
    </div>
  `;
  
  modal.appendChild(modalContent);
  document.body.appendChild(modal);
  
  // 취소 버튼에 포커스 (기본 선택)
  const cancelBtn = document.getElementById('cancelDelete');
  const confirmBtn = document.getElementById('confirmDeleteBtn');
  
  cancelBtn.focus();
  
  // 취소 버튼 클릭
  cancelBtn.onclick = function() {
    document.body.removeChild(modal);
  };
  
  // 삭제 확인 버튼 클릭
  confirmBtn.onclick = function() {
    document.body.removeChild(modal);
    deletePhoto(photoId);
  };
  
  // 모달 배경 클릭 시 닫기
  modal.onclick = function(e) {
    if (e.target === modal) {
      document.body.removeChild(modal);
    }
  };
  
  // ESC 키로 닫기
  document.addEventListener('keydown', function escHandler(e) {
    if (e.key === 'Escape') {
      document.body.removeChild(modal);
      document.removeEventListener('keydown', escHandler);
    }
  });
}

// 사진 삭제 실행
function deletePhoto(photoId) {
  // 로딩 표시
  const loadingModal = document.createElement('div');
  loadingModal.style.cssText = `
    position: fixed;
    z-index: 10001;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
  `;
  
  loadingModal.innerHTML = `
    <div style="background: white; border-radius: 12px; padding: 2rem; text-align: center;">
      <div style="font-size: 1.1rem; color: #333;">사진을 삭제하는 중...</div>
    </div>
  `;
  
  document.body.appendChild(loadingModal);
  
  // AJAX로 삭제 요청
  const xhr = new XMLHttpRequest();
  xhr.open('POST', window.location.href, true);
  xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
  
  xhr.onreadystatechange = function() {
    if (xhr.readyState === 4) {
      document.body.removeChild(loadingModal);
      
      if (xhr.status === 200) {
        const response = JSON.parse(xhr.responseText);
        if (response.success) {
          alert('사진이 삭제되었습니다.');
          location.reload(); // 페이지 새로고침
        } else {
          alert('삭제 실패: ' + (response.message || '알 수 없는 오류'));
        }
      } else {
        alert('서버 오류가 발생했습니다.');
      }
    }
  };
  
  xhr.send('action=delete_photo&photo_id=' + photoId + '&contract_id=<?php echo $contract_id; ?>');
}

// 서명 이미지 보기 함수
function showSignature(signaturePath) {
  const modal = document.createElement('div');
  modal.style.cssText = `
    position: fixed;
    z-index: 10002;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.8);
    display: flex;
    align-items: center;
    justify-content: center;
  `;
  
  const modalContent = document.createElement('div');
  modalContent.style.cssText = `
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    max-width: 90vw;
    max-height: 90vh;
    overflow: auto;
    position: relative;
    box-shadow: 0 8px 32px rgba(0,0,0,0.3);
  `;
  
  modalContent.innerHTML = `
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
      <h4 style="margin: 0; color: #333; font-size: 1.1rem;">서명 이미지</h4>
      <button onclick="document.body.removeChild(this.closest('.signature-modal'))" 
              style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #666; padding: 0.2rem 0.5rem; border-radius: 4px;" 
              title="닫기">×</button>
    </div>
    <div style="text-align: center;">
      <img src="${signaturePath}" alt="서명" style="max-width: 100%; max-height: 60vh; border: 1px solid #ddd; border-radius: 8px;">
    </div>
  `;
  
  modal.className = 'signature-modal';
  modal.appendChild(modalContent);
  document.body.appendChild(modal);
  
  // 모달 배경 클릭 시 닫기
  modal.onclick = function(e) {
    if (e.target === modal) {
      document.body.removeChild(modal);
    }
  };
  
  // ESC 키로 닫기
  document.addEventListener('keydown', function escHandler(e) {
    if (e.key === 'Escape') {
      document.body.removeChild(modal);
      document.removeEventListener('keydown', escHandler);
    }
  });
}

// 자동 서명 요청 처리
<?php if (!empty($auto_request_sign)): ?>
document.addEventListener('DOMContentLoaded', function() {
  // 페이지 로드 후 잠시 대기한 후 자동으로 서명 요청 모달 실행
  setTimeout(function() {
    const roleToRequest = '<?php echo $auto_request_sign; ?>';
    if (roleToRequest) {
      // 사용자에게 알림
      const roleName = roleToRequest === 'landlord' ? '임대인' : '임차인';
      if (confirm(`${roleName}에게 서명을 다시 요청하시겠습니까?`)) {
        generateShareLink(roleToRequest);
      } else {
        // URL에서 request_sign 매개변수 제거
        const currentUrl = new URL(window.location);
        currentUrl.searchParams.delete('request_sign');
        window.history.replaceState(null, '', currentUrl.toString());
      }
    }
  }, 500);
});
<?php endif; ?>
</script>
</body>
</html> 