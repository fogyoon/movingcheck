<?php
require_once 'sql.inc';
require_once 'config.inc';

// 세션 시작 (서명 시 필요)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 세션 삭제 처리 (AJAX 요청)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_session') {
    header('Content-Type: application/json');
    
    $contract_id = (int)($_POST['contract_id'] ?? 0);
    
    // 해당 계약의 세션 정보만 삭제
    if ($contract_id && isset($_SESSION['verified_contracts'][$contract_id])) {
        unset($_SESSION['verified_contracts'][$contract_id]);
    }
    
    echo json_encode(['success' => true]);
    exit;
}

// URL 파라미터에서 보안키 및 역할 확인
$security_key = $_GET['key'] ?? '';
$url_role = $_GET['role'] ?? ''; // 'landlord' 또는 'tenant'
if (!$security_key) {
    die('잘못된 접근입니다.');
}
if (!in_array($url_role, ['landlord', 'tenant'])) {
    die('올바르지 않은 접근입니다.');
}

// 계약 정보 조회
$pdo = get_pdo();
if (is_string($pdo)) {
    die('데이터베이스 연결 오류');
}

// 현재 URL key가 어떤 서명 단계인지 확인
$key_type = null;
$key_column = null;
$password_column = null;
$signed_status = null;

$stmt = $pdo->prepare('SELECT c.*, p.address, p.detail_address, u.nickname as sender_name, u.phone as sender_phone
                       FROM contracts c 
                       LEFT JOIN properties p ON c.property_id = p.id 
                       LEFT JOIN users u ON c.user_id = u.id
                       WHERE c.movein_landlord_key = ? OR c.movein_tenant_key = ? OR c.moveout_landlord_key = ? OR c.moveout_tenant_key = ?');
$stmt->execute([$security_key, $security_key, $security_key, $security_key]);
$contract = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$contract) {
    die('계약을 찾을 수 없습니다.');
}

// 어떤 키 타입인지 확인
if ($contract['movein_landlord_key'] === $security_key && $url_role === 'landlord') {
    $key_type = 'movein_landlord';
    $key_column = 'movein_landlord_key';
    $password_column = 'movein_landlord_password';
    $signed_status = 'movein_landlord_signed';
} elseif ($contract['movein_tenant_key'] === $security_key && $url_role === 'tenant') {
    $key_type = 'movein_tenant';
    $key_column = 'movein_tenant_key';
    $password_column = 'movein_tenant_password';
    $signed_status = 'movein_tenant_signed';
} elseif ($contract['moveout_landlord_key'] === $security_key && $url_role === 'landlord') {
    $key_type = 'moveout_landlord';
    $key_column = 'moveout_landlord_key';
    $password_column = 'moveout_landlord_password';
    $signed_status = 'moveout_landlord_signed';
} elseif ($contract['moveout_tenant_key'] === $security_key && $url_role === 'tenant') {
    $key_type = 'moveout_tenant';
    $key_column = 'moveout_tenant_key';
    $password_column = 'moveout_tenant_password';
    $signed_status = 'moveout_tenant_signed';
} else {
    die('잘못된 보안 키입니다.');
}

// 서명 완료 여부 확인
$is_already_signed = false;
$stmt_signed = $pdo->prepare('SELECT COUNT(*) FROM signatures WHERE contract_id = ? AND signer_role = ? AND purpose = ?');
$purpose = (strpos($key_type, 'movein') !== false) ? 'movein' : 'moveout';
$stmt_signed->execute([$contract['id'], $url_role, $purpose]);
$is_already_signed = $stmt_signed->fetchColumn() > 0;

// 계약이 완료되었거나 비어있는 상태면 접근 불가
if (in_array($contract['status'], ['finished', 'empty'])) {
    die('현재 사진을 확인할 수 없습니다.');
}

// movein_tenant_signed 상태에서 moveout_photo로 자동 전환
if ($contract['status'] === 'movein_tenant_signed') {
    try {
        $stmt = $pdo->prepare('UPDATE contracts SET status = ? WHERE id = ?');
        $stmt->execute(['moveout_photo', $contract['id']]);
        
        // 활동 로그 기록
        log_user_activity(null, 'update_contract', '보안링크를 통한 자동 상태 전환: movein_tenant_signed -> moveout_photo', $contract['id']);
        
        // 계약 상태 업데이트
        $contract['status'] = 'moveout_photo';
    } catch (Exception $e) {
        // 오류가 발생해도 계속 진행
    }
}

// 비밀번호 확인 처리
$password_verified = false;
$show_limited_view = false;

// 서명이 완료된 상태에서는 비밀번호 없이 접근 가능 (제한된 정보만 표시)
if ($is_already_signed) {
    $password_verified = true;
    $show_limited_view = true;
} else {
    // 서명 전 상태에서는 비밀번호 확인 필요
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_password') {
        $input_password = $_POST['password'] ?? '';
        if ($input_password === $contract[$password_column]) {
            $_SESSION['verified_contracts'][$contract['id']] = true;
            $password_verified = true;
        } else {
            $password_error = '비밀번호가 올바르지 않습니다.';
        }
    }
    
    // 세션에서 비밀번호 확인 여부 체크
    if (isset($_SESSION['verified_contracts'][$contract['id']])) {
        $password_verified = true;
    }
}

// 서명 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_signature' && $password_verified) {
    $signature_data = $_POST['signature_data'] ?? '';
    $signer_name = $_POST['signer_name'] ?? '';
    $signer_phone = $_POST['signer_phone'] ?? '';
    
    if ($signature_data && $signer_name && $signer_phone) {
        // 현재 status에 따라 signer_role 결정
        $signer_role = '';
        $purpose = '';
        $new_status = '';
        
        switch ($contract['status']) {
            case 'movein_photo':
                $signer_role = 'landlord';
                $purpose = 'movein';
                $new_status = 'movein_landlord_signed';
                break;
            case 'movein_landlord_signed':
                $signer_role = 'tenant';
                $purpose = 'movein';
                $new_status = 'movein_tenant_signed';
                break;

            case 'moveout_photo':
                $signer_role = 'landlord';
                $purpose = 'moveout';
                $new_status = 'moveout_landlord_signed';
                break;
            case 'moveout_landlord_signed':
                $signer_role = 'tenant';
                $purpose = 'moveout';
                $new_status = 'moveout_tenant_signed';
                break;
        }
        
        if ($signer_role) {
            try {
                $pdo->beginTransaction();
                
                // 서명 저장
                $stmt = $pdo->prepare('INSERT INTO signatures (contract_id, signer_role, purpose, signer_name, signer_phone, signature_data, signer_ip) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$contract['id'], $signer_role, $purpose, $signer_name, $signer_phone, $signature_data, $_SERVER['REMOTE_ADDR'] ?? 'unknown']);
                
                // 계약 상태 업데이트
                $stmt = $pdo->prepare('UPDATE contracts SET status = ? WHERE id = ?');
                $stmt->execute([$new_status, $contract['id']]);
                
                // 활동 로그 기록 - 보안 접근이므로 user_id는 null로 저장
                log_user_activity(null, 'create_signature', '보안링크를 통한 ' . $signer_role . ' 서명 (' . $signer_name . ', ' . $signer_phone . ')', $contract['id']);
                
                $pdo->commit();
                
                $signature_success = true;
                $contract['status'] = $new_status; // 현재 계약 상태 업데이트
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $signature_error = '서명 저장 중 오류가 발생했습니다.';
            }
        }
    } else {
        $signature_error = '모든 정보를 입력해주세요.';
    }
}

// 사진 데이터 조회
$stmt = $pdo->prepare('SELECT * FROM photos WHERE contract_id = ? ORDER BY created_at ASC');
$stmt->execute([$contract['id']]);
$photos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 표시할 사진 종류 결정
$show_moveout = in_array($contract['status'], ['moveout_photo', 'moveout_landlord_signed', 'moveout_tenant_signed', 'in_repair']);

// URL role을 기반으로 서명 정보 결정
$required_signature = null;
$signer_role_text = ($url_role === 'landlord') ? '임대인' : '임차인';

// URL role과 계약 상태를 조합하여 서명 필요 여부 결정
$signature_purpose = '';
$should_allow_signature = false;

// 입주 단계 서명
if ($contract['status'] === 'movein_photo' && $url_role === 'landlord') {
    $signature_purpose = 'movein';
    $should_allow_signature = true;
} elseif ($contract['status'] === 'movein_landlord_signed' && $url_role === 'tenant') {
    $signature_purpose = 'movein';
    $should_allow_signature = true;
}
// 퇴거 단계 서명
elseif ($contract['status'] === 'moveout_photo' && $url_role === 'landlord') {
    $signature_purpose = 'moveout';
    $should_allow_signature = true;
} elseif ($contract['status'] === 'moveout_landlord_signed' && $url_role === 'tenant') {
    $signature_purpose = 'moveout';
    $should_allow_signature = true;
}

// 서명 정보 설정
if ($should_allow_signature) {
    $required_signature = ['role' => $url_role, 'purpose' => $signature_purpose];
}

// 해당 role의 기존 서명 확인 (현재 계약에서 해당 role과 purpose 조합)
$existing_signature = null;
$signature_completed = false;
if ($required_signature) {
    $stmt = $pdo->prepare('SELECT * FROM signatures WHERE contract_id = ? AND signer_role = ? AND purpose = ?');
    $stmt->execute([$contract['id'], $required_signature['role'], $required_signature['purpose']]);
    $existing_signature = $stmt->fetch(PDO::FETCH_ASSOC);
    $signature_completed = ($existing_signature !== false);
}

// 서명 필요 여부 최종 결정
$need_signature = ($required_signature && !$signature_completed);

// 해당 role의 모든 서명 기록 확인 (현재 계약에서)
$all_signatures_by_role = [];
$stmt = $pdo->prepare('SELECT * FROM signatures WHERE contract_id = ? AND signer_role = ? ORDER BY signed_at DESC');
$stmt->execute([$contract['id'], $url_role]);
$all_signatures_by_role = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 잘못된 접근인지 확인
$invalid_access = false;
if (!$should_allow_signature && empty($all_signatures_by_role)) {
    // 현재 상태에서 해당 role의 서명이 필요하지 않고, 과거에 서명한 기록도 없는 경우
    $invalid_access = true;
}

// 경고 메시지 결정
$warning_message = '';
if ($need_signature) {
    $role_name = ($signer_role_text === '임대인') ? '임대인' : '임차인';
    $warning_message = "⚠️ 본 계약과 관련없는 자가 {$role_name}으로 서명할 경우 법적 불이익을 당할 수 있습니다.";
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>사진 확인 - 무빙체크</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 1rem;
        }
        
        .password-container {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 60vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .password-form {
            background: white;
            border-radius: 16px;
            padding: 2.5rem;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
            text-align: center;
            max-width: 400px;
            width: 90%;
        }
        
        .password-input {
            width: 100%;
            padding: 1rem;
            font-size: 1.5rem;
            text-align: center;
            letter-spacing: 0.5rem;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            margin: 1rem 0;
        }
        
        .password-input:focus {
            border-color: #007bff;
            outline: none;
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.25);
        }
        
        .photo-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 2rem;
            margin: 2rem 0;
        }
        
        .photo-comparison {
            border: 2px solid #e9ecef;
            border-radius: 12px;
            padding: 1.5rem;
            background: #f8f9fa;
        }
        
        .photo-item {
            text-align: center;
            margin-bottom: 1.5rem;
        }
        
        .photo-item h4 {
            margin: 0 0 0.8rem 0;
            font-size: 1.1rem;
            color: #333;
        }
        
        .photo-item img {
            max-width: 100%;
            height: auto;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            cursor: pointer;
        }
        
        .movein-photos {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            justify-content: center;
        }
        
        .signature-section {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 2rem;
            margin: 2rem 0;
            border: 2px solid #e9ecef;
        }
        
        .signature-canvas {
            border: 2px solid #ddd;
            border-radius: 8px;
            background: white;
            cursor: crosshair;
        }
        
        .warning-box {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
            color: #856404;
            text-align: center;
            font-weight: 500;
        }
        
        .success-message {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            border-radius: 8px;
            padding: 1.5rem;
            margin: 1rem 0;
            color: #155724;
            text-align: center;
            font-size: 1.1rem;
            font-weight: 600;
        }
        
        .error-message {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
            color: #721c24;
            text-align: center;
        }
        
        /* 서명 버튼 스타일 */
        .sign-btns {
            display: flex;
            gap: 0.7rem;
            margin-top: 1.2rem;
        }
        
        .sign-btn {
            flex: 1;
            background: #0064FF;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 0.9rem 0;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.15s;
        }
        
        .sign-btn.clear {
            background: #bbb;
            color: #222;
        }
        
        .sign-btn.close {
            background: #eee;
            color: #1976d2;
        }
        
        .sign-btn:active {
            background: #0052cc;
        }
        
        .sign-btn.clear:active {
            background: #999;
        }
        
        .sign-btn.close:active {
            background: #ddd;
        }
        
        @media (max-width: 768px) {
            .photo-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .password-form {
                padding: 1.5rem;
            }
            
            /* 모바일에서 입주/퇴거 사진 비교를 세로로 표시 */
            .photo-comparison-grid {
                display: grid !important;
                grid-template-columns: 1fr !important;
                gap: 1rem !important;
            }
            
            .photo-comparison-item {
                margin-bottom: 1rem !important;
            }
            
            /* 모바일 서명 폼 스타일 */
            .signature-section {
                padding: 1rem !important;
                margin: 1rem 0 !important;
            }
            
            /* 모바일에서 입력 필드를 세로로 배치 */
            .signature-form-grid {
                display: block !important;
                grid-template-columns: none !important;
                gap: 1rem !important;
            }
            
            .signature-form-grid > div {
                margin-bottom: 1rem;
            }
            
            .signature-form-grid input {
                width: calc(100% - 1.6rem) !important;
                box-sizing: border-box;
            }
            
            /* 모바일 서명 캔버스 */
            .signature-canvas {
                width: 100% !important;
                max-width: 350px !important;
                height: 150px !important;
                box-sizing: border-box;
            }
            
            /* 모바일 서명 버튼 */
            .sign-btns {
                flex-direction: column !important;
                gap: 0.8rem !important;
                align-items: center;
            }
            
            .sign-btn {
                width: 100% !important;
                max-width: 280px;
                margin: 0 auto;
            }
            
            /* 모바일 닫기 버튼 */
            .sign-btn.close {
                max-width: 200px !important;
            }
        }
    </style>
</head>
<body>
    <!-- 로고 헤더 -->
    <div style="background: white; border-bottom: 1px solid #e9ecef; padding: 1rem 0;">
        <div class="container">
            <div style="text-align: center;">
                <a href="<?php echo SITE_ADDRESS; ?>" style="text-decoration: none; color: inherit; display: inline-block;">
                    <img src="mc_logo.svg" alt="무빙체크" style="height: 40px;">
                </a>
                <h2 style="margin: 0.5rem 0 0 0; color: #333; font-size: 1.3rem;">사진 확인</h2>
            </div>
        </div>
    </div>

    <?php if (!$password_verified): ?>
        <!-- 비밀번호 입력 화면 -->
        <div class="password-container">
            <form method="POST" class="password-form">
                <input type="hidden" name="action" value="verify_password">
                
                <h3 style="margin-top: 0; color: #333; font-size: 1.4rem;">비밀번호 입력</h3>
                <p style="color: #666; margin-bottom: 1.5rem;">
                    사진을 확인하기 위해 4자리 비밀번호를 입력해주세요.
                </p>
                
                <?php if (isset($password_error)): ?>
                    <div class="error-message"><?php echo $password_error; ?></div>
                <?php endif; ?>
                
                <input type="password" name="password" class="password-input" 
                       placeholder="****" maxlength="4" pattern="[0-9]{4}" required
                       autocomplete="off">
                
                <button type="submit" class="sign-btn" 
                        style="width: 100%; padding: 0.9rem; font-size: 1.1rem; font-weight: 700;">
                    확인
                </button>
            </form>
        </div>
    <?php else: ?>
        <!-- 사진 확인 화면 -->
        <div class="container">
            <?php if ($invalid_access): ?>
                <!-- 잘못된 접근 메시지 -->
                <div style="background: #f8d7da; border: 2px solid #f5c6cb; border-radius: 12px; padding: 2rem; margin: 2rem 0; text-align: center;">
                    <h3 style="margin-top: 0; color: #721c24; font-size: 1.4rem;">
                        ⚠️ 잘못된 접근입니다
                    </h3>
                    <p style="color: #721c24; font-size: 1.1rem; margin: 1rem 0;">
                        현재 계약 상태에서 <?php echo $signer_role_text; ?>의 서명이 필요하지 않거나,<br>
                        이미 다른 단계로 진행되었습니다.
                    </p>
                    <div style="background: white; border-radius: 8px; padding: 1.5rem; margin: 1rem 0; border: 1px solid #f5c6cb;">
                        <h4 style="margin: 0 0 1rem 0; color: #721c24;">📋 현재 계약 상태</h4>
                        <p style="font-size: 0.95rem; color: #721c24;">
                            <strong>상태:</strong> 
                            <?php 
                            $status_text = '';
                            switch($contract['status']) {
                                case 'movein_photo': $status_text = '입주 사진 촬영 완료'; break;
                                case 'movein_landlord_signed': $status_text = '입주 시 임대인 서명 완료'; break;
                                case 'movein_tenant_signed': $status_text = '입주 시 임차인 서명 완료'; break;
                                case 'moveout_photo': $status_text = '퇴거 사진 촬영 완료'; break;
                                case 'moveout_landlord_signed': $status_text = '퇴거 시 임대인 서명 완료'; break;
                                case 'moveout_tenant_signed': $status_text = '퇴거 시 임차인 서명 완료'; break;
                                default: $status_text = $contract['status']; break;
                            }
                            echo $status_text;
                            ?>
                        </p>
                    </div>
                </div>
            <?php else: ?>
                <?php if (isset($signature_success)): ?>
                    <div class="success-message">
                        ✅ 서명이 완료되었습니다. 감사합니다!
                    </div>
                <?php endif; ?>

                <?php if (isset($signature_error)): ?>
                    <div class="error-message"><?php echo $signature_error; ?></div>
                <?php endif; ?>

            <!-- 물건 정보 -->
            <div style="background: white; border-radius: 12px; padding: 2rem; margin: 1rem 0; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                <h3 style="margin-top: 0; color: #333; border-bottom: 2px solid #007bff; padding-bottom: 0.5rem;">📍 임대물 정보</h3>
                <div style="font-size: 1.1rem; font-weight: 600; color: #333; margin-bottom: 1rem;">
                    <?php echo htmlspecialchars($contract['address']); ?>
                    <?php if ($contract['detail_address']): ?>
                        , <?php echo htmlspecialchars($contract['detail_address']); ?>
                    <?php endif; ?>
                </div>
                <?php if (!$show_limited_view): ?>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; font-size: 0.95rem;">
                        <div><strong>계약기간:</strong> <?php echo htmlspecialchars($contract['start_date']); ?> ~ <?php echo htmlspecialchars($contract['end_date']); ?></div>
                        <div><strong>보증금:</strong> <?php echo number_format($contract['deposit']); ?>원</div>
                        <div><strong>월세:</strong> <?php echo number_format($contract['monthly_rent']); ?>원</div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!$show_limited_view): ?>
                <!-- 보낸 사람 정보 -->
                <div style="background: white; border-radius: 12px; padding: 2rem; margin: 1rem 0; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    <h3 style="margin-top: 0; color: #333; border-bottom: 2px solid #28a745; padding-bottom: 0.5rem;">👤 전송자 정보</h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem;">
                        <?php if ($contract['sender_name']): ?>
                            <div>
                                <strong>전송자:</strong> <?php echo htmlspecialchars($contract['sender_name']); ?> 
                                (<?php echo htmlspecialchars($contract['sender_phone'] ?? '전화번호 없음'); ?>)
                            </div>
                            <div>
                                <strong>역할:</strong> 
                                <?php 
                                // 전송자의 역할을 계약 정보에서 추정
                                $sender_role_text = '기타';
                                if ($contract['sender_name']) {
                                    if ($contract['landlord_name'] === $contract['sender_name']) {
                                        $sender_role_text = '임대인';
                                    } elseif ($contract['tenant_name'] === $contract['sender_name']) {
                                        $sender_role_text = '임차인';
                                    } elseif ($contract['agent_name'] === $contract['sender_name']) {
                                        $sender_role_text = '중개사';
                                    }
                                }
                                echo $sender_role_text;
                                ?>
                            </div>
                        <?php else: ?>
                            <div style="color: #666;">전송자 정보를 확인할 수 없습니다.</div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 경고 메시지 -->
            <?php if (!$show_limited_view && $warning_message): ?>
                <div class="warning-box">
                    <?php echo $warning_message; ?>
                </div>
            <?php endif; ?>

            <!-- 사진 표시 -->
            <div style="background: white; border-radius: 12px; padding: 2rem; margin: 1rem 0; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                <h3 style="margin-top: 0; color: #333; border-bottom: 2px solid #dc3545; padding-bottom: 0.5rem;">
                    📸 <?php echo $show_moveout ? '입주/퇴거 사진 비교' : '입주 사진'; ?>
                </h3>

                <?php if ($photos): ?>
                    <?php
                    // 입주/퇴거 사진 비교인 경우 퇴거 사진이 있는 부위만 필터링
                    $photos_to_display = $photos;
                    if ($show_moveout) {
                        $photos_to_display = array_filter($photos, function($photo) {
                            // 퇴거 사진이 하나라도 있는지 확인
                            for ($i = 1; $i <= 6; $i++) {
                                $moveout_file = $photo['moveout_file_path_0' . $i];
                                if (!empty($moveout_file)) {
                                    return true;
                                }
                            }
                            return false;
                        });
                    }
                    ?>
                    
                    <?php if (empty($photos_to_display)): ?>
                        <p style="text-align: center; color: #666; font-size: 1.1rem; padding: 2rem;">
                            <?php echo $show_moveout ? '퇴거 사진이 등록된 부위가 없습니다.' : '등록된 사진이 없습니다.'; ?>
                        </p>
                    <?php else: ?>
                        <div style="width: 100%;">
                            <?php foreach ($photos_to_display as $photo): ?>
                                <!-- 부위별 정보 -->
                                <div style="margin-bottom: 3rem;">
                                    <h4 style="color: #333; margin-bottom: 1rem; text-align: center; font-size: 1.5rem; border-bottom: 2px solid #e9ecef; padding-bottom: 0.5rem;">
                                        📍 <?php echo htmlspecialchars($photo['part']); ?>
                                    </h4>
                                    
                                    <?php if ($photo['description']): ?>
                                        <p style="color: #666; font-size: 0.9rem; text-align: center; margin-bottom: 2rem;">
                                            <?php echo nl2br(htmlspecialchars($photo['description'])); ?>
                                        </p>
                                    <?php endif; ?>

                                    <?php
                                    // movein과 moveout 사진을 인덱스별로 매칭
                                    $overviewPairs = [];
                                    $closeupPairs = [];
                                    
                                    for ($i = 1; $i <= 6; $i++) {
                                        $moveinFilePath = $photo['movein_file_path_0' . $i];
                                        $moveinShotType = $photo['movein_shot_type_0' . $i];
                                        $moveoutFilePath = $photo['moveout_file_path_0' . $i];
                                        $moveoutShotType = $photo['moveout_shot_type_0' . $i];
                                        
                                        if ($moveinFilePath) {
                                            $pair = [
                                                'index' => $i,
                                                'movein' => ['src' => $moveinFilePath, 'type' => 'movein'],
                                                'moveout' => $moveoutFilePath ? ['src' => $moveoutFilePath, 'type' => 'moveout'] : null
                                            ];
                                            
                                            // 입주/퇴거 비교 모드에서는 퇴거 사진이 있는 쌍만 표시
                                            if ($show_moveout && !$moveoutFilePath) {
                                                continue;
                                            }
                                            
                                            if ($moveinShotType === 'overview') {
                                                $overviewPairs[] = $pair;
                                            } else if ($moveinShotType === 'closeup') {
                                                $closeupPairs[] = $pair;
                                            }
                                        }
                                    }
                                    ?>

                                <!-- overview 사진 먼저 표시 -->
                                <?php if (count($overviewPairs) > 0): ?>
                                    <div style="width: 100%; margin-bottom: 2.5rem;">
                                        <h3 style="color: #1976d2; font-size: 1.2rem; margin-bottom: 1.5rem; text-align: center; font-weight: 600;">위치확인용 사진</h3>
                                        
                                        <div style="display: flex; flex-direction: column; gap: 2rem; align-items: center;">
                                            <?php foreach ($overviewPairs as $pair): ?>
                                                <?php if ($pair['moveout']): ?>
                                                    <!-- moveout 사진이 있는 경우: 비교 보기 -->
                                                    <div style="display: flex; gap: 1.5rem; align-items: flex-start; flex-wrap: wrap; justify-content: center; padding: 1rem; border: 2px solid #e9ecef; border-radius: 12px; background: #f8f9fa;">
                                                        <!-- 입주 시 사진 -->
                                                        <div style="text-align: center;">
                                                            <h4 style="margin: 0 0 0.5rem 0; color: #1976d2; font-size: 1rem; font-weight: 600;">입주 시</h4>
                                                            <img src="<?php echo htmlspecialchars($pair['movein']['src']); ?>" alt="입주사진" 
                                                                 style="max-width: 350px; max-height: 50vh; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); border: 2px solid #1976d2;">
                                                        </div>
                                                        
                                                        <!-- 퇴거 시 사진 -->
                                                        <div style="text-align: center;">
                                                            <h4 style="margin: 0 0 0.5rem 0; color: #28a745; font-size: 1rem; font-weight: 600;">퇴거 시</h4>
                                                            <img src="<?php echo htmlspecialchars($pair['moveout']['src']); ?>" alt="퇴거사진" 
                                                                 style="max-width: 350px; max-height: 50vh; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); border: 2px solid #28a745;">
                                                        </div>
                                                    </div>
                                                <?php else: ?>
                                                    <!-- moveout 사진이 없는 경우: 단일 보기 -->
                                                    <div style="text-align: center;">
                                                        <img src="<?php echo htmlspecialchars($pair['movein']['src']); ?>" alt="입주사진" 
                                                             style="max-width: 420px; max-height: 60vh; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.13); border: 2px solid #1976d2;">
                                                    </div>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- closeup 사진 표시 -->
                                <?php if (count($closeupPairs) > 0): ?>
                                    <div style="width: 100%;">
                                        <h3 style="color: #666; font-size: 1.2rem; margin-bottom: 1.5rem; text-align: center; font-weight: 600;">세부 사진</h3>
                                        
                                        <div style="display: flex; flex-direction: column; gap: 2rem; align-items: center;">
                                            <?php foreach ($closeupPairs as $pair): ?>
                                                <?php if ($pair['moveout']): ?>
                                                    <!-- moveout 사진이 있는 경우: 비교 보기 -->
                                                    <div style="display: flex; gap: 1.5rem; align-items: flex-start; flex-wrap: wrap; justify-content: center; padding: 1rem; border: 2px solid #e9ecef; border-radius: 12px; background: #f8f9fa;">
                                                        <!-- 입주 시 사진 -->
                                                        <div style="text-align: center;">
                                                            <h4 style="margin: 0 0 0.5rem 0; color: #1976d2; font-size: 1rem; font-weight: 600;">입주 시</h4>
                                                            <img src="<?php echo htmlspecialchars($pair['movein']['src']); ?>" alt="입주사진" 
                                                                 style="max-width: 300px; max-height: 45vh; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); border: 2px solid #1976d2;">
                                                        </div>
                                                        
                                                        <!-- 퇴거 시 사진 -->
                                                        <div style="text-align: center;">
                                                            <h4 style="margin: 0 0 0.5rem 0; color: #28a745; font-size: 1rem; font-weight: 600;">퇴거 시</h4>
                                                            <img src="<?php echo htmlspecialchars($pair['moveout']['src']); ?>" alt="퇴거사진" 
                                                                 style="max-width: 300px; max-height: 45vh; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); border: 2px solid #28a745;">
                                                        </div>
                                                    </div>
                                                <?php else: ?>
                                                    <!-- moveout 사진이 없는 경우: 단일 보기 -->
                                                    <div style="text-align: center;">
                                                        <img src="<?php echo htmlspecialchars($pair['movein']['src']); ?>" alt="입주사진" 
                                                             style="max-width: 320px; max-height: 50vh; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.13);">
                                                    </div>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                <?php else: ?>
                    <p style="text-align: center; color: #666; font-size: 1.1rem; padding: 2rem;">
                        등록된 사진이 없습니다.
                    </p>
                <?php endif; ?>
            </div>

            <!-- 서명 섹션 -->
            <?php if (!$show_limited_view && ($required_signature || !empty($all_signatures_by_role))): ?>
                <?php if ($signature_completed || (!$should_allow_signature && !empty($all_signatures_by_role))): ?>
                    <!-- 서명 완료 메시지 -->
                    <div style="background: #d4edda; border: 2px solid #c3e6cb; border-radius: 12px; padding: 2rem; margin: 2rem 0; text-align: center;">
                        <h3 style="margin-top: 0; color: #155724; font-size: 1.4rem;">
                            ✅ <?php echo $signer_role_text; ?> 서명 기록
                        </h3>
                        <p style="color: #155724; font-size: 1.1rem; margin: 1rem 0;">
                            <?php if ($signature_completed): ?>
                                현재 단계의 <?php echo $signer_role_text; ?> 서명이 완료되어 더 이상 서명할 수 없습니다.
                            <?php else: ?>
                                <?php echo $signer_role_text; ?> 서명 기록입니다.
                            <?php endif; ?>
                        </p>
                        
                        <?php foreach ($all_signatures_by_role as $index => $signature): ?>
                            <div style="background: white; border-radius: 8px; padding: 1.5rem; margin: 1rem 0; border: 1px solid #c3e6cb;">
                                <h4 style="margin: 0 0 1rem 0; color: #155724;">
                                    📝 서명 정보 <?php echo count($all_signatures_by_role) > 1 ? '(' . ($index + 1) . '/' . count($all_signatures_by_role) . ')' : ''; ?>
                                </h4>
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; font-size: 0.95rem;">
                                    <div><strong>서명자:</strong> <?php echo htmlspecialchars($signature['signer_name']); ?></div>
                                    <div><strong>전화번호:</strong> <?php echo htmlspecialchars($signature['signer_phone']); ?></div>
                                    <div><strong>서명일시:</strong> <?php echo date('Y-m-d H:i', strtotime($signature['signed_at'])); ?></div>
                                    <div><strong>서명구분:</strong> <?php echo $signature['purpose'] === 'movein' ? '입주' : '퇴거'; ?> 확인</div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($need_signature && !isset($signature_success)): ?>
                    <!-- 서명 입력 폼 -->
                    <div class="signature-section">
                        <h3 style="margin-top: 0; color: #333; text-align: center;">
                            ✍️ <?php echo ($show_moveout ? '입주, 퇴거(파손)' : '입주'); ?> 사진을 충분히 검토하였기에 서명합니다.
                        </h3>
                        
                        <form method="POST" id="signatureForm">
                            <input type="hidden" name="action" value="submit_signature">
                            <input type="hidden" name="signature_data" id="signatureData">
                            
                            <div class="signature-form-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 2rem; margin-bottom: 2rem;">
                                <div>
                                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #333;">성명 *</label>
                                    <input type="text" name="signer_name" required 
                                           style="width: 100%; padding: 0.8rem; border: 2px solid #e9ecef; border-radius: 6px; font-size: 1rem; box-sizing: border-box;"
                                           placeholder="서명자 성명을 입력하세요">
                                </div>
                                <div>
                                    <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #333;">전화번호 *</label>
                                    <input type="text" name="signer_phone" required 
                                           style="width: 100%; padding: 0.8rem; border: 2px solid #e9ecef; border-radius: 6px; font-size: 1rem; box-sizing: border-box;"
                                           placeholder="010-0000-0000" pattern="[0-9\-\s]*" inputmode="text">
                                </div>
                            </div>
                            
                            <div style="text-align: center; margin-bottom: 1rem;">
                                <label style="font-weight: 600; color: #333; margin-bottom: 0.5rem; display: block;">서명</label>
                                <canvas id="signatureCanvas" class="signature-canvas" width="400" height="200" 
                                        style="border: 2px solid #e9ecef; border-radius: 8px; background: white; max-width: 100%; touch-action: none; -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none; user-select: none;"></canvas>
                            </div>
                            
                            <div class="sign-btns" style="display: flex; gap: 0.7rem; margin-top: 1.2rem;">
                                <button type="button" onclick="clearSignature()" class="sign-btn clear">
                                    지우기
                                </button>
                                <button type="submit" class="sign-btn">
                                    서명 완료
                                </button>
                            </div>
                            
                            <div style="display: flex; justify-content: center; margin-top: 1.5rem;">
                                <button type="button" onclick="closeTab()" class="sign-btn close" style="max-width: 150px;">
                                    닫기
                                </button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <!-- 닫기 버튼 (제한된 뷰에서도 표시) -->
            <?php if ($show_limited_view): ?>
                <div style="display: flex; justify-content: center; margin: 2rem 0;">
                    <button type="button" onclick="closeTab()" class="sign-btn close" style="max-width: 200px;">
                        닫기
                    </button>
                </div>
            <?php endif; ?>
        </div>
            <?php endif; ?> <!-- invalid_access else 구문 닫기 -->
    <?php endif; ?>



    <script>
        // 계약 ID 및 세션 관리 변수
        const contractId = <?php echo $contract['id']; ?>;
        const isSignedView = <?php echo $show_limited_view ? 'true' : 'false'; ?>;
        const isSignatureSuccess = <?php echo isset($signature_success) ? 'true' : 'false'; ?>;
        
        // 세션 삭제 함수
        function clearSession() {
            if (navigator.sendBeacon) {
                // sendBeacon 사용 (페이지 언로드 시에도 안전하게 전송)
                const formData = new FormData();
                formData.append('action', 'clear_session');
                formData.append('contract_id', contractId);
                navigator.sendBeacon(window.location.href, formData);
            } else {
                // 일반 fetch 사용 (비동기)
                fetch(window.location.href, {
                    method: 'POST',
                    body: new URLSearchParams({
                        action: 'clear_session',
                        contract_id: contractId
                    }),
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    }
                }).catch(function(error) {
                    console.log('세션 삭제 중 오류:', error);
                });
            }
        }
        
        // 페이지 떠나기 이벤트 처리
        window.addEventListener('beforeunload', function(e) {
            clearSession();
        });
        
        // 페이지 숨김 이벤트 처리 (모바일에서 탭 전환 시)
        window.addEventListener('pagehide', function(e) {
            clearSession();
        });
        
        // 페이지 로딩 완료 후 서명 완료 상태이거나 제한된 뷰일 때 세션 삭제
        window.addEventListener('load', function() {
            if (isSignedView || isSignatureSuccess) {
                // 3초 후 세션 삭제 (사용자가 내용을 볼 시간 제공)
                setTimeout(function() {
                    clearSession();
                }, 3000);
            }
        });

        // 서명 캔버스 기능
        <?php if ($need_signature && !isset($signature_success)): ?>
        const canvas = document.getElementById('signatureCanvas');
        const ctx = canvas.getContext('2d');
        let isDrawing = false;
        let hasSignature = false;

        // 캔버스 크기 조정 함수
        function resizeCanvas() {
            const rect = canvas.getBoundingClientRect();
            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            
            // 모바일에서 화면 크기에 맞게 조정
            if (window.innerWidth <= 768) {
                const maxWidth = window.innerWidth - 40; // 좌우 20px 여백
                if (maxWidth < 400) {
                    canvas.width = Math.min(maxWidth, 350);
                    canvas.height = Math.floor(canvas.width * 0.5); // 높이 비율 조정
                }
            }
            
            canvas.style.width = canvas.width + 'px';
            canvas.style.height = canvas.height + 'px';
            
            // 고해상도 디스플레이 대응
            canvas.width = canvas.width * ratio;
            canvas.height = canvas.height * ratio;
            ctx.scale(ratio, ratio);
            
            // 캔버스 스타일 재설정
            ctx.strokeStyle = '#000';
            ctx.lineWidth = 2;
            ctx.lineCap = 'round';
        }

        // 초기 캔버스 설정
        resizeCanvas();

        // 화면 크기 변경 시 캔버스 조정
        window.addEventListener('resize', resizeCanvas);

        // 마우스 이벤트
        canvas.addEventListener('mousedown', startDrawing);
        canvas.addEventListener('mousemove', draw);
        canvas.addEventListener('mouseup', stopDrawing);
        canvas.addEventListener('mouseout', stopDrawing);

        // 터치 이벤트 (모바일)
        canvas.addEventListener('touchstart', handleTouch, { passive: false });
        canvas.addEventListener('touchmove', handleTouch, { passive: false });
        canvas.addEventListener('touchend', handleTouch, { passive: false });

        function getCanvasCoordinates(e) {
            const rect = canvas.getBoundingClientRect();
            const ratio = Math.max(window.devicePixelRatio || 1, 1);
            
            // 실제 canvas 크기와 표시되는 크기의 비율을 고려
            const scaleX = (canvas.width / ratio) / rect.width;
            const scaleY = (canvas.height / ratio) / rect.height;
            
            return {
                x: (e.clientX - rect.left) * scaleX,
                y: (e.clientY - rect.top) * scaleY
            };
        }

        function startDrawing(e) {
            isDrawing = true;
            const coords = getCanvasCoordinates(e);
            ctx.beginPath();
            ctx.moveTo(coords.x, coords.y);
            
            // 서명 중 페이지 스크롤 방지
            document.body.style.overflow = 'hidden';
        }

        function draw(e) {
            if (!isDrawing) return;
            
            const coords = getCanvasCoordinates(e);
            ctx.lineTo(coords.x, coords.y);
            ctx.stroke();
            hasSignature = true;
        }

        function stopDrawing() {
            isDrawing = false;
            
            // 서명 완료 후 페이지 스크롤 복원
            document.body.style.overflow = '';
        }

        function handleTouch(e) {
            e.preventDefault();
            e.stopPropagation();
            
            if (e.type === 'touchstart' || e.type === 'touchmove') {
                const touch = e.touches[0] || e.changedTouches[0];
                if (!touch) return;
                
                const mouseEvent = {
                    clientX: touch.clientX,
                    clientY: touch.clientY
                };
                
                if (e.type === 'touchstart') {
                    startDrawing(mouseEvent);
                } else if (e.type === 'touchmove') {
                    draw(mouseEvent);
                }
            } else if (e.type === 'touchend') {
                stopDrawing();
            }
        }

        function clearSignature() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            hasSignature = false;
            
            // 스타일 재설정
            ctx.strokeStyle = '#000';
            ctx.lineWidth = 2;
            ctx.lineCap = 'round';
        }
        
        // 탭 닫기 함수
        function closeTab() {
            let confirmed = true;
            
            // 서명이 필요한 상태에서만 확인 메시지 표시
            <?php if ($need_signature && !isset($signature_success)): ?>
            confirmed = confirm('서명하지 않고 사진 확인을 종료하시겠습니까?');
            <?php endif; ?>
            
            if (confirmed) {
                // 세션 삭제
                clearSession();
                
                try {
                    window.close();
                    // window.close()가 작동하지 않는 경우를 대비한 안내
                    setTimeout(function() {
                        alert('브라우저 설정으로 인해 자동으로 탭을 닫을 수 없습니다.\n수동으로 탭을 닫아주세요.');
                    }, 100);
                } catch (e) {
                    alert('브라우저 설정으로 인해 자동으로 탭을 닫을 수 없습니다.\n수동으로 탭을 닫아주세요.');
                }
            }
        }

        // 폼 제출 시 서명 데이터 저장
        document.getElementById('signatureForm').addEventListener('submit', function(e) {
            if (!hasSignature) {
                e.preventDefault();
                alert('서명을 해주세요.');
                return;
            }
            
            const signatureData = canvas.toDataURL();
            document.getElementById('signatureData').value = signatureData;
            
            // 서명 완료 후 폼 제출 전 세션 삭제 (비동기)
            clearSession();
        });
        <?php endif; ?>
        
        // 전역 함수로 closeTab 정의 (제한된 뷰에서도 사용 가능)
        <?php if ($show_limited_view): ?>
        // 탭 닫기 함수 (제한된 뷰용)
        function closeTab() {
            // 세션 삭제
            clearSession();
            
            try {
                window.close();
                // window.close()가 작동하지 않는 경우를 대비한 안내
                setTimeout(function() {
                    alert('브라우저 설정으로 인해 자동으로 탭을 닫을 수 없습니다.\n수동으로 탭을 닫아주세요.');
                }, 100);
            } catch (e) {
                alert('브라우저 설정으로 인해 자동으로 탭을 닫을 수 없습니다.\n수동으로 탭을 닫아주세요.');
            }
        }
        <?php endif; ?>
    </script>
</body>
</html> 