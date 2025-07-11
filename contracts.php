<?php
require_once 'sql.inc';

// 로그인 확인 - 로그인되지 않은 경우 login.php로 리다이렉트
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// 확인완료 처리 (AJAX 요청)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_mismatch') {
    header('Content-Type: application/json');
    
    $signature_id = (int)($_POST['signature_id'] ?? 0);
    if (!$signature_id) {
        echo json_encode(['success' => false, 'message' => '유효하지 않은 서명 ID입니다.']);
        exit;
    }

    $pdo = get_pdo();
    if (is_string($pdo)) {
        echo json_encode(['success' => false, 'message' => 'DB 연결 오류: ' . $pdo]);
        exit;
    }

    try {
        // 서명 정보 조회 및 권한 확인
        $stmt = $pdo->prepare("
            SELECT s.*, c.user_id as contract_owner, c.landlord_id, c.tenant_id, c.agent_id 
            FROM signatures s 
            JOIN contracts c ON s.contract_id = c.id 
            WHERE s.id = ?
        ");
        $stmt->execute([$signature_id]);
        $signature = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$signature) {
            echo json_encode(['success' => false, 'message' => '서명을 찾을 수 없습니다.']);
            exit;
        }
        
        // 권한 확인: 계약 관련자만 확인 가능
        $has_permission = false;
        if ($user_id == $signature['contract_owner'] || 
            $user_id == $signature['landlord_id'] || 
            $user_id == $signature['tenant_id'] || 
            $user_id == $signature['agent_id']) {
            $has_permission = true;
        }
        
        // 관리자는 항상 권한 있음
        $stmt_admin = $pdo->prepare("SELECT usergroup FROM users WHERE id = ?");
        $stmt_admin->execute([$user_id]);
        $user_info = $stmt_admin->fetch(PDO::FETCH_ASSOC);
        if ($user_info && in_array($user_info['usergroup'], ['admin', 'subadmin'])) {
            $has_permission = true;
        }
        
        if (!$has_permission) {
            echo json_encode(['success' => false, 'message' => '권한이 없습니다.']);
            exit;
        }
        
        // 불일치 확인 완료 처리
        $stmt_update = $pdo->prepare("UPDATE signatures SET mismatch_confirmed = TRUE WHERE id = ?");
        $stmt_update->execute([$signature_id]);
        
        // 사용자 활동 기록 (함수가 없다면 간단히 처리)
        try {
            log_user_activity($user_id, 'other', "서명 불일치 확인 완료 (서명 ID: {$signature_id})", $signature['contract_id']);
        } catch (Exception $e) {
            // 로그 기록 실패해도 계속 진행
        }
        
        echo json_encode(['success' => true, 'message' => '서명 불일치 확인이 완료되었습니다.']);
        exit;
        
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'DB 오류: ' . $e->getMessage()]);
        exit;
    }
}

$property_id = (int)($_GET['property_id'] ?? 0);

if (!$property_id) {
    header('Location: properties.php');
    exit;
}

$pdo = get_pdo();

// 임대물 정보 조회
$stmt = $pdo->prepare("SELECT * FROM properties WHERE id = ? AND created_by = ?");
$stmt->execute([$property_id, $user_id]);
$property = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$property) {
    header('Location: properties.php');
    exit;
}

// 종료된 계약 보기 설정
$show_finished = isset($_GET['show_finished']) && $_GET['show_finished'] === '1';

// 계약 정보 조회 (순서 번호 포함)
// 먼저 모든 계약의 순서 번호를 계산
$order_sql = "
    SELECT c.*, 
           ROW_NUMBER() OVER (PARTITION BY c.property_id ORDER BY c.created_at ASC) as contract_order
    FROM contracts c
    WHERE c.property_id = ? AND c.user_id = ?
";

$order_stmt = $pdo->prepare($order_sql);
$order_stmt->execute([$property_id, $user_id]);
$all_contracts = $order_stmt->fetchAll(PDO::FETCH_ASSOC);

// 종료된 계약 필터링 (표시 여부에 따라)
$contracts = [];
foreach ($all_contracts as $contract) {
    if ($show_finished || $contract['status'] != 'finished') {
        $contracts[] = $contract;
    }
}

// 최신 순으로 정렬
usort($contracts, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});

// 전화번호 정규화 함수 (숫자만 추출)
function normalize_phone($phone) {
    return preg_replace('/[^0-9]/', '', trim($phone));
}

// 완료된 서명 정보 조회 및 불일치 확인
function get_contract_signatures($contract_id, $pdo, $contract_info) {
    $stmt_sig = $pdo->prepare("SELECT id, signer_role, signer_name, signer_phone, purpose, signed_at, mismatch_confirmed FROM signatures WHERE contract_id = ? ORDER BY signed_at ASC");
    $stmt_sig->execute([$contract_id]);
    $signatures = $stmt_sig->fetchAll(PDO::FETCH_ASSOC);
    
    $result = [];
    
    foreach ($signatures as $signature) {
        $role = $signature['signer_role'];
        $signer_name = trim($signature['signer_name']);
        $signer_phone = trim($signature['signer_phone']);
        $purpose = $signature['purpose'];
        $signed_at = $signature['signed_at'];
        $mismatch_confirmed = $signature['mismatch_confirmed'];
        
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
        
        if (!$mismatch_confirmed && !empty($signer_name) && !empty($contract_name)) {
            $name_mismatch = strcasecmp($signer_name, $contract_name) !== 0;
        }
        
        if (!$mismatch_confirmed && !empty($signer_phone) && !empty($contract_phone)) {
            $phone_mismatch = normalize_phone($signer_phone) !== normalize_phone($contract_phone);
        }
        
        $has_mismatch = $name_mismatch || $phone_mismatch;
        
        $result[] = [
            'id' => $signature['id'],
            'role' => $role,
            'role_korean' => $role === 'landlord' ? '임대인' : '임차인',
            'purpose' => $purpose,
            'purpose_korean' => $purpose === 'movein' ? '입주' : '퇴거',
            'signer_name' => $signer_name,
            'signer_phone' => $signer_phone,
            'signed_at' => $signed_at,
            'has_mismatch' => $has_mismatch,
            'name_mismatch' => $name_mismatch,
            'phone_mismatch' => $phone_mismatch,
            'mismatch_confirmed' => $mismatch_confirmed
        ];
    }
    
    return $result;
}

// 서명 요청 상태 확인 (완료된 서명은 제외)
function get_signature_request_status($contract, $signatures) {
    $requests = [];
    
    // 완료된 서명들의 타입 목록 생성
    $completed_signatures = [];
    foreach ($signatures as $sig) {
        $sig_type = $sig['purpose'] . '_' . $sig['role'];
        $completed_signatures[] = $sig_type;
    }
    
    // 입주 임대인 요청
    if ($contract['movein_landlord_request_sent_at'] && !in_array('movein_landlord', $completed_signatures)) {
        $requests[] = [
            'type' => 'movein_landlord',
            'korean' => '입주 사진 임대인 서명 요청',
            'sent_at' => $contract['movein_landlord_request_sent_at']
        ];
    }
    
    // 입주 임차인 요청
    if ($contract['movein_tenant_request_sent_at'] && !in_array('movein_tenant', $completed_signatures)) {
        $requests[] = [
            'type' => 'movein_tenant',
            'korean' => '입주 사진 임차인 서명 요청',
            'sent_at' => $contract['movein_tenant_request_sent_at']
        ];
    }
    
    // 퇴거 임대인 요청
    if ($contract['moveout_landlord_request_sent_at'] && !in_array('moveout_landlord', $completed_signatures)) {
        $requests[] = [
            'type' => 'moveout_landlord',
            'korean' => '퇴거 사진 임대인 서명 요청',
            'sent_at' => $contract['moveout_landlord_request_sent_at']
        ];
    }
    
    // 퇴거 임차인 요청
    if ($contract['moveout_tenant_request_sent_at'] && !in_array('moveout_tenant', $completed_signatures)) {
        $requests[] = [
            'type' => 'moveout_tenant',
            'korean' => '퇴거 사진 임차인 서명 요청',
            'sent_at' => $contract['moveout_tenant_request_sent_at']
        ];
    }
    
    return $requests;
}

// 서명자 정보와 계약자 정보 불일치 확인 (기존 코드 제거)
$mismatched_contracts = [];
// foreach ($contracts as $contract) {
//     $contract_id = $contract['id'];
//     
//     // 해당 계약의 서명 정보 조회
//     $stmt_sig = $pdo->prepare("SELECT signer_role, signer_name, signer_phone, purpose FROM signatures WHERE contract_id = ?");
//     $stmt_sig->execute([$contract_id]);
//     $signatures = $stmt_sig->fetchAll(PDO::FETCH_ASSOC);
//     
//     $mismatches = [];
//     
//     foreach ($signatures as $signature) {
//         // ... 기존 불일치 확인 로직 제거
//     }
//     
//     if (!empty($mismatches)) {
//         $mismatched_contracts[$contract_id] = [
//             'contract' => $contract,
//             'mismatches' => $mismatches
//         ];
//     }
// }

// finished 되지 않은 계약이 있는지 확인
$stmt_unfinished = $pdo->prepare("
    SELECT COUNT(*) as count 
    FROM contracts 
    WHERE property_id = ? AND status != 'finished'
");
$stmt_unfinished->execute([$property_id]);
$unfinished_count = $stmt_unfinished->fetch(PDO::FETCH_ASSOC)['count'];

// 상태별 정보 정의
$status_info = [
    'empty' => [
        'label' => '사진 등록 필요',
        'phase' => '입주',
        'progress' => 0,
        'buttons' => ['photo_upload']
    ],
    'movein_photo' => [
        'label' => '서명 필요',
        'phase' => '입주',
        'progress' => 10,
        'buttons' => ['photo_edit', 'sign_request']
    ],
    'movein_landlord_signed' => [
        'label' => '임대인 서명 완료',
        'phase' => '입주',
        'progress' => 20,
        'buttons' => ['photo_edit', 'sign_request']
    ],
    'movein_tenant_signed' => [
        'label' => '임차인 서명 완료',
        'phase' => '입주',
        'progress' => 30,
        'buttons' => ['moveout_photo', 'complete']
    ],
    'moveout_photo' => [
        'label' => '파손 확인',
        'phase' => '퇴거',
        'progress' => 50,
        'buttons' => ['tenant_sign_request']
    ],
    'moveout_landlord_signed' => [
        'label' => '임대인 파손 확인',
        'phase' => '퇴거',
        'progress' => 60,
        'buttons' => ['repair_request', 'complete']
    ],
    'moveout_tenant_signed' => [
        'label' => '임차인 파손 인정',
        'phase' => '수리요망',
        'progress' => 70,
        'buttons' => ['complete']
    ],
    'in_repair' => [
        'label' => '수리중',
        'phase' => '수리중',
        'progress' => 80,
        'buttons' => ['complete']
    ],
    'finished' => [
        'label' => '계약 종료',
        'phase' => '종료',
        'progress' => 100,
        'buttons' => ['view_details']
    ]
];

// 상태별 버튼 라벨 매핑
$photo_button_labels = [
  'empty' => '사진 등록',
  'movein_photo' => '사진 확인 등록',
  'movein_landlord_signed' => '사진 확인 전송',
  'movein_tenant_signed' => '입주 사진 확인(퇴거 사진 등록)',
  'moveout_photo' => '입주 사진 확인(퇴거 사진 등록)',
  'moveout_landlord_signed' => '퇴거 사진 확인 전송',
  'moveout_tenant_signed' => '퇴거 사진 확인',
  'in_repair' => '퇴거 사진 확인',
  'finished' => '사진 확인',
];

// 상태별 뱃지 클래스 결정 함수
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

?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>계약 관리 - <?php echo htmlspecialchars($property['address']); ?> - <?php echo SITE_TITLE; ?></title>
  <link rel="stylesheet" href="style.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@400;700;900&display=swap" rel="stylesheet">
  <style>
    /* =================================== */
    /* 계약(Contracts) 페이지 스타일 */
    /* =================================== */
    
    .contract-container {
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

    /* 버튼 */
    .btn {
      display: inline-flex;
      align-items: center;
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

    /* 테이블 */
    .contract-table-wrap {
      background-color: #fff;
      border: 1px solid #e3eaf2;
      border-radius: 12px;
      padding: 1rem;
      box-shadow: 0 4px 18px rgba(0,100,255,0.05);
    }
    .contract-table { 
      width: 100%; 
      border-collapse: collapse; 
      min-width: 800px; 
    }
    .contract-table th, .contract-table td { 
      padding: 1rem; 
      text-align: left; 
      border-bottom: 1px solid #f0f2f5; 
    }
    .contract-table th { 
      color: #555; 
      font-size: 0.9rem; 
      font-weight: 600; 
      text-transform: uppercase; 
    }
    .contract-table tr:last-child td { 
      border-bottom: none; 
    }
    .contract-table .contract-info { 
      font-weight: 600; 
      color: #333; 
    }
    .contract-table .contract-actions { 
      display: flex; 
      gap: 0.5rem; 
      flex-wrap: wrap;
    }

    /* 상태 바 */
    .status-bar {
      background-color: #f8f9fa;
      border-radius: 8px;
      padding: 1rem;
      margin-bottom: 1rem;
    }
    .status-label {
      font-weight: 600;
      color: #333;
      margin-bottom: 0.5rem;
    }
    .progress-container {
      background-color: #e9ecef;
      border-radius: 10px;
      height: 8px;
      overflow: hidden;
      margin-bottom: 0.5rem;
    }
    .progress-bar {
      height: 100%;
      background: linear-gradient(90deg, #0064FF, #28a745);
      border-radius: 10px;
      transition: width 0.3s ease;
    }

    /* 카드 뷰 (모바일) */
    .contract-cards {
      display: none;
    }
    .contract-card {
      background-color: #fff;
      border: 1px solid #e3eaf2;
      border-radius: 12px;
      padding: 1.5rem;
      margin-bottom: 1rem;
      box-shadow: 0 4px 18px rgba(0,100,255,0.05);
    }
    .contract-card-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 1rem;
    }
    .contract-card-title {
      font-weight: 700;
      color: #333;
      font-size: 1.1rem;
    }
    .contract-card-info {
      margin-bottom: 1rem;
    }
    .contract-card-info-row {
      display: flex;
      justify-content: space-between;
      margin-bottom: 0.5rem;
    }
    .contract-card-label {
      color: #6c757d;
      font-size: 0.9rem;
    }
    .contract-card-value {
      font-weight: 600;
      color: #333;
    }
    .contract-card-actions {
      display: flex;
      gap: 0.5rem;
      flex-wrap: wrap;
      margin-top: 1rem;
    }

    /* 반응형 */
    @media (max-width: 768px) {
      .contract-table-wrap {
        display: none;
      }
      .contract-cards {
        display: block;
      }
      .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
      }
      .contract-table .contract-actions {
        flex-direction: column;
      }
      .contract-card-actions {
        flex-direction: column;
      }
      .contract-card-actions .btn {
        width: 100%;
        justify-content: center;
      }
      /* 모바일에서 계약서 보기 버튼 크기 조정 */
      .contract-view-btn {
        width: 28px;
        height: 28px;
        margin-left: 6px;
      }
      .contract-view-btn img {
        width: 14px;
        height: 14px;
      }
      /* 모바일에서 계약 카드 제목 영역 조정 */
      .contract-card-title {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 4px;
      }
      .contract-card-title .status-badge {
        margin-left: auto;
      }
    }

    /* 금액 포맷팅 */
    .amount {
      font-family: 'Courier New', monospace;
      font-weight: 600;
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
      background-color: #f8f9fa;
      color: #6c757d;
      border: 1px solid #dee2e6;
    }

    /* 토글 스위치 스타일 */
    .toggle-switch-container {
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    
    .toggle-switch {
      position: relative;
      display: inline-block;
      width: 50px;
      height: 24px;
    }
    
    .toggle-switch input {
      opacity: 0;
      width: 0;
      height: 0;
    }
    
    .toggle-slider {
      position: absolute;
      cursor: pointer;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background-color: #ccc;
      transition: .4s;
      border-radius: 24px;
    }
    
    .toggle-slider:before {
      position: absolute;
      content: "";
      height: 18px;
      width: 18px;
      left: 3px;
      bottom: 3px;
      background-color: white;
      transition: .4s;
      border-radius: 50%;
    }
    
    input:checked + .toggle-slider {
      background-color: #0064FF;
    }
    
    input:checked + .toggle-slider:before {
      transform: translateX(26px);
    }
    
    .toggle-label {
      font-size: 0.9rem;
      color: #6c757d;
      font-weight: 500;
    }

    .contract-info {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      margin-bottom: 0.5rem;
    }
    
    .edit-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 32px;
      height: 32px;
      background-color: #f8f9fa;
      border: 1px solid #dee2e6;
      border-radius: 6px;
      text-decoration: none;
      color: #6c757d;
      transition: all 0.2s ease-in-out;
      margin-left: 0.5rem;
    }
    
    .edit-btn:hover {
      background-color: #e9ecef;
      border-color: #adb5bd;
      color: #495057;
      transform: scale(1.05);
    }
    
    .contract-card-title {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      font-weight: 600;
      color: #212529;
    }

    /* 계약서 보기 버튼 */
    .contract-view-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 32px;
      height: 32px;
      border: 1px solid #dee2e6;
      border-radius: 6px;
      background-color: #fff;
      color: #6c757d;
      text-decoration: none;
      transition: all 0.2s ease-in-out;
      margin-left: 8px;
    }
    .contract-view-btn:hover {
      background-color: #f8f9fa;
      border-color: #0064FF;
      color: #0064FF;
      transform: translateY(-1px);
      box-shadow: 0 2px 8px rgba(0,100,255,0.15);
    }
    .contract-view-btn img {
      width: 16px;
      height: 16px;
      filter: brightness(0.7);
      transition: filter 0.2s ease-in-out;
    }
    .contract-view-btn:hover img {
      filter: brightness(1);
    }
  </style>
</head>
<body>
  <?php include 'header.inc'; ?>

  <div class="contract-container">
    <div class="page-header">
      <div>
        <h1 class="page-title">
          <img src="images/contract-icon.svg" alt="계약서" style="width: 32px; height: 32px; margin-right: 12px; vertical-align: middle;">계약 관리
        </h1>
        <div class="page-subtitle"><?php echo htmlspecialchars($property['address']); ?> <?php echo htmlspecialchars($property['detail_address']); ?></div>
      </div>
      <div style="display: flex; align-items: center; gap: 1rem;">
        <!-- 종료된 계약 보기 스위치 -->
        <div class="toggle-switch-container">
          <label class="toggle-switch">
            <input type="checkbox" id="showFinishedToggle" <?php echo $show_finished ? 'checked' : ''; ?>>
            <span class="toggle-slider"></span>
          </label>
          <span class="toggle-label">종료된 계약 보기</span>
        </div>
        <a href="properties.php" class="btn btn-primary" id="backToListBtn" style="background-color: #6c757d; color: white; padding: 0.5rem 1rem; border-radius: 6px; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 0.4rem; box-shadow: 0 2px 6px rgba(108, 117, 125, 0.3); transition: all 0.2s ease; border: 1px solid #5a6268; font-size: 0.9rem;">
          <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16">
            <path fill-rule="evenodd" d="M15 8a.5.5 0 0 0-.5-.5H2.707l3.147-3.146a.5.5 0 1 0-.708-.708l-4 4a.5.5 0 0 0 0 .708l4 4a.5.5 0 0 0 .708-.708L2.707 8.5H14.5A.5.5 0 0 0 15 8z"/>
          </svg>
          임대물 목록으로
        </a>
      </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
      <div class="alert alert-success" style="background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
        ✅ 계약이 성공적으로 <?php echo $_GET['success'] == '1' ? '등록' : '수정'; ?>되었습니다.
      </div>
    <?php endif; ?>



    <?php if (empty($contracts)): ?>
      <div class="contract-table-wrap">
        <div style="text-align: center; padding: 3rem; color: #6c757d;">
          <h3><?php echo $show_finished ? '등록된 계약이 없습니다' : '진행 중인 계약이 없습니다'; ?></h3>
          <p><?php echo $show_finished ? '새로운 계약을 등록해주세요.' : '새로운 계약을 등록하거나 종료된 계약 보기를 켜주세요.'; ?></p>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($unfinished_count == 0): ?>
      <div style="text-align: center; padding: 2rem; margin-top: 1rem;">
        <a href="contract_edit.php?property_id=<?php echo $property_id; ?>" class="btn btn-primary" style="padding: 0.75rem 2rem; font-size: 1.1rem;">
          + 새 계약 등록
        </a>
      </div>
    <?php endif; ?>

    <?php if (!empty($contracts)): ?>
      <!-- PC 테이블 뷰 -->
      <div class="contract-table-wrap">
        <table class="contract-table">
          <thead>
            <tr>
              <th>계약 정보</th>
              <th>임대인</th>
              <th>임차인</th>
              <th>계약 기간</th>
              <th>보증금/월세</th>
              <th>상태</th>
              <th>작업</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($contracts as $contract): ?>
              <?php $status = $status_info[$contract['status']] ?? $status_info['empty']; ?>
              <tr>
                <td>
                  <div class="contract-info">
                    계약 #<?php echo $contract['contract_order']; ?>
                    <?php 
                    // movein_tenant_signed 단계부터는 계약 수정 불가
                    $editable_statuses = ['empty', 'movein_photo', 'movein_landlord_signed'];
                    if (in_array($contract['status'], $editable_statuses)): 
                    ?>
                      <a href="contract_edit.php?property_id=<?php echo $property_id; ?>&id=<?php echo $contract['id']; ?>" class="edit-btn" title="수정">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M12.854.146a.5.5 0 0 0-.707 0L10.5 1.793 14.207 5.5l1.647-1.646a.5.5 0 0 0 0-.708l-3-3zm.646 6.061L9.793 2.5 3.293 9H3.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.207l6.5-6.5zm-7.468 7.468A.5.5 0 0 1 6 13.5V13h-.5a.5.5 0 0 1-.5-.5V12h-.5a.5.5 0 0 1-.5-.5V11h-.5a.5.5 0 0 1-.5-.5V10h-.5a.499.499 0 0 1-.175-.032l-.179.178a.5.5 0 0 0-.11.168l-2 5a.5.5 0 0 0 .65.65l5-2a.5.5 0 0 0 .168-.11l.178-.178z"/></svg>
                      </a>
                    <?php else: ?>
                      <span class="edit-btn disabled" title="입주 임차인 서명 완료 후에는 계약 수정이 불가능합니다" style="color: #6c757d; cursor: not-allowed;" onclick="showEditDisabledMessage()">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M12.854.146a.5.5 0 0 0-.707 0L10.5 1.793 14.207 5.5l1.647-1.646a.5.5 0 0 0 0-.708l-3-3zm.646 6.061L9.793 2.5 3.293 9H3.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.207l6.5-6.5zm-7.468 7.468A.5.5 0 0 1 6 13.5V13h-.5a.5.5 0 0 1-.5-.5V12h-.5a.5.5 0 0 1-.5-.5V11h-.5a.5.5 0 0 1-.5-.5V10h-.5a.499.499 0 0 1-.175-.032l-.179.178a.5.5 0 0 0-.11.168l-2 5a.5.5 0 0 0 .65.65l5-2a.5.5 0 0 0 .168-.11l.178-.178z"/></svg>
                      </span>
                    <?php endif; ?>
                    <?php if ($contract['contract_file'] && file_exists($contract['contract_file'])): ?>
                      <a href="view_contract.php?id=<?php echo $contract['id']; ?>" target="_blank" class="contract-view-btn" title="계약서 보기">
                        <img src="images/contract-icon.svg" alt="계약서">
                      </a>
                    <?php endif; ?>
                    <span class="status-badge <?php echo getStatusBadgeClass($contract['status']); ?>">
                      <?php echo $status['phase']; ?>
                    </span>
                  </div>
                  <div style="font-size: 0.9rem; color: #6c757d;">
                    <?php echo $contract['agent_name'] ? '중개: ' . htmlspecialchars($contract['agent_name']) : '직접 계약'; ?>
                    <?php if ($contract['agent_phone']): ?>
                      <br><?php echo htmlspecialchars($contract['agent_phone']); ?>
                    <?php endif; ?>
                  </div>
                </td>
                <td>
                  <div><?php echo htmlspecialchars($contract['landlord_name'] ?? '-'); ?></div>
                  <?php if ($contract['landlord_phone']): ?>
                    <div style="font-size: 0.9rem; color: #6c757d;"><?php echo htmlspecialchars($contract['landlord_phone']); ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <div><?php echo htmlspecialchars($contract['tenant_name'] ?? '-'); ?></div>
                  <?php if ($contract['tenant_phone']): ?>
                    <div style="font-size: 0.9rem; color: #6c757d;"><?php echo htmlspecialchars($contract['tenant_phone']); ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <div><?php echo date('Y.m.d', strtotime($contract['start_date'])); ?></div>
                  <div style="color: #6c757d;">~ <?php echo date('Y.m.d', strtotime($contract['end_date'])); ?></div>
                </td>
                <td>
                  <div class="amount"><?php echo number_format($contract['deposit']); ?>원</div>
                  <div class="amount" style="color: #0064FF;"><?php echo number_format($contract['monthly_rent']); ?>원/월</div>
                </td>
                <td>
                  <?php 
                  $signatures = get_contract_signatures($contract['id'], $pdo, $contract);
                  $requests = get_signature_request_status($contract, $signatures);
                  ?>
                  
                  <?php if (!empty($requests)): ?>
                    <div style="margin-bottom: 1rem; padding: 0.8rem; background-color: #fff3e0; border-radius: 6px; border-left: 3px solid #ff9800;">
                      <div style="font-size: 0.85rem; font-weight: 600; color: #e65100; margin-bottom: 0.5rem;">📤 보낸 서명 요청</div>
                      <?php foreach ($requests as $req): ?>
                        <div style="font-size: 0.8rem; margin-bottom: 0.3rem; color: #bf360c;">
                          <?php echo $req['korean']; ?>
                          <span style="color: #6c757d;">(<?php echo date('m/d H:i', strtotime($req['sent_at'])); ?>)</span>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                  
                  <?php if (!empty($signatures)): ?>
                    <div style="margin-bottom: 1rem; padding: 0.8rem; background-color: #f8f9fa; border-radius: 6px; border-left: 3px solid #28a745;">
                      <div style="font-size: 0.85rem; font-weight: 600; color: #155724; margin-bottom: 0.5rem;">✅ 완료된 서명</div>
                      <?php foreach ($signatures as $sig): ?>
                        <div style="font-size: 0.8rem; margin-bottom: 0.3rem; color: #495057;">
                          <?php echo $sig['purpose_korean']; ?> 사진 <?php echo $sig['role_korean']; ?> 서명 완료
                          <span style="color: #6c757d;">(<?php echo date('m/d H:i', strtotime($sig['signed_at'])); ?>)</span>
                        </div>
                                                 <?php if ($sig['has_mismatch'] && !$sig['mismatch_confirmed']): ?>
                           <div style="margin-left: 1rem; margin-bottom: 0.5rem; padding: 0.4rem; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px;">
                             <div style="font-size: 0.75rem; color: #856404; margin-bottom: 0.3rem;">
                               ⚠️ 서명자의 
                               <?php if ($sig['name_mismatch'] && $sig['phone_mismatch']): ?>
                                 이름과 전화번호가
                               <?php elseif ($sig['name_mismatch']): ?>
                                 이름이
                               <?php elseif ($sig['phone_mismatch']): ?>
                                 전화번호가
                               <?php endif; ?>
                               계약서와 일치하지 않습니다.
                             </div>
                             <div style="font-size: 0.7rem; color: #6c757d; margin-bottom: 0.3rem;">
                               서명자에게 연락하여 서명의 진위여부를 확인하세요.<br>
                               잘못된 서명이었다면 다시 서명 신청을 보내시고, 맞는 서명이라면 "확인완료" 버튼을 눌러주세요.
                             </div>
                             <div style="display: flex; gap: 0.3rem;">
                               <button class="btn btn-warning" style="font-size: 0.7rem; padding: 0.2rem 0.4rem;" onclick="requestResign(<?php echo $contract['id']; ?>, '<?php echo $sig['purpose']; ?>', '<?php echo $sig['role']; ?>')">
                                 서명 다시 요청
                               </button>
                               <button class="btn btn-success" style="font-size: 0.7rem; padding: 0.2rem 0.4rem;" onclick="confirmMismatch(<?php echo $sig['id']; ?>)">
                                 확인완료
                               </button>
                             </div>
                           </div>
                         <?php endif; ?>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                  <div class="status-bar">
                    <div class="status-label"><?php echo $status['label']; ?></div>
                    <div class="progress-container">
                      <div class="progress-bar" style="width: <?php echo min($status['progress'], 100); ?>%;"></div>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="contract-actions">
                    <?php
                      $s = $contract['status'];
                      $button_label = $photo_button_labels[$s] ?? null;
                      if ($button_label) {
                    ?>
                      <button class="btn btn-primary" onclick="window.location.href='photo_list.php?contract_id=<?php echo $contract['id']; ?>'">
                        <?php echo $button_label; ?>
                      </button>
                    <?php } ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- 모바일 카드 뷰 -->
      <div class="contract-cards">
        <?php foreach ($contracts as $contract): ?>
          <?php $status = $status_info[$contract['status']] ?? $status_info['empty']; ?>
          <div class="contract-card">
            <div class="contract-card-header">
              <div class="contract-card-title">
                계약 #<?php echo $contract['contract_order']; ?>
                <?php 
                // movein_tenant_signed 단계부터는 계약 수정 불가
                $editable_statuses = ['empty', 'movein_photo', 'movein_landlord_signed'];
                if (in_array($contract['status'], $editable_statuses)): 
                ?>
                  <a href="contract_edit.php?property_id=<?php echo $property_id; ?>&id=<?php echo $contract['id']; ?>" class="edit-btn" title="수정">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M12.854.146a.5.5 0 0 0-.707 0L10.5 1.793 14.207 5.5l1.647-1.646a.5.5 0 0 0 0-.708l-3-3zm.646 6.061L9.793 2.5 3.293 9H3.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.207l6.5-6.5zm-7.468 7.468A.5.5 0 0 1 6 13.5V13h-.5a.5.5 0 0 1-.5-.5V12h-.5a.5.5 0 0 1-.5-.5V11h-.5a.5.5 0 0 1-.5-.5V10h-.5a.499.499 0 0 1-.175-.032l-.179.178a.5.5 0 0 0-.11.168l-2 5a.5.5 0 0 0 .65.65l5-2a.5.5 0 0 0 .168-.11l.178-.178z"/></svg>
                  </a>
                                 <?php else: ?>
                   <span class="edit-btn disabled" title="입주 임차인 서명 완료 후에는 계약 수정이 불가능합니다" style="color: #6c757d; cursor: not-allowed;" onclick="showEditDisabledMessage()">
                     <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M12.854.146a.5.5 0 0 0-.707 0L10.5 1.793 14.207 5.5l1.647-1.646a.5.5 0 0 0 0-.708l-3-3zm.646 6.061L9.793 2.5 3.293 9H3.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.207l6.5-6.5zm-7.468 7.468A.5.5 0 0 1 6 13.5V13h-.5a.5.5 0 0 1-.5-.5V12h-.5a.5.5 0 0 1-.5-.5V11h-.5a.5.5 0 0 1-.5-.5V10h-.5a.499.499 0 0 1-.175-.032l-.179.178a.5.5 0 0 0-.11.168l-2 5a.5.5 0 0 0 .65.65l5-2a.5.5 0 0 0 .168-.11l.178-.178z"/></svg>
                   </span>
                 <?php endif; ?>
                <?php if ($contract['contract_file'] && file_exists($contract['contract_file'])): ?>
                  <a href="view_contract.php?id=<?php echo $contract['id']; ?>" target="_blank" class="contract-view-btn" title="계약서 보기">
                    <img src="images/contract-icon.svg" alt="계약서">
                  </a>
                <?php endif; ?>
                <span class="status-badge <?php echo getStatusBadgeClass($contract['status']); ?>">
                  <?php echo $status['phase']; ?>
                </span>
              </div>
            </div>
            
            <div class="contract-card-info">
              <div class="contract-card-info-row">
                <span class="contract-card-label">임대인:</span>
                <span class="contract-card-value">
                  <?php echo htmlspecialchars($contract['landlord_name'] ?? '-'); ?>
                  <?php if ($contract['landlord_phone']): ?>
                    <br><span style="font-size: 0.9rem; color: #6c757d;"><?php echo htmlspecialchars($contract['landlord_phone']); ?></span>
                  <?php endif; ?>
                </span>
              </div>
              <div class="contract-card-info-row">
                <span class="contract-card-label">임차인:</span>
                <span class="contract-card-value">
                  <?php echo htmlspecialchars($contract['tenant_name'] ?? '-'); ?>
                  <?php if ($contract['tenant_phone']): ?>
                    <br><span style="font-size: 0.9rem; color: #6c757d;"><?php echo htmlspecialchars($contract['tenant_phone']); ?></span>
                  <?php endif; ?>
                </span>
              </div>
              <div class="contract-card-info-row">
                <span class="contract-card-label">중개사:</span>
                <span class="contract-card-value">
                  <?php echo $contract['agent_name'] ? htmlspecialchars($contract['agent_name']) : '직접 계약'; ?>
                  <?php if ($contract['agent_phone']): ?>
                    <br><span style="font-size: 0.9rem; color: #6c757d;"><?php echo htmlspecialchars($contract['agent_phone']); ?></span>
                  <?php endif; ?>
                </span>
              </div>
              <div class="contract-card-info-row">
                <span class="contract-card-label">계약 기간:</span>
                <span class="contract-card-value">
                  <?php echo date('Y.m.d', strtotime($contract['start_date'])); ?> ~ <?php echo date('Y.m.d', strtotime($contract['end_date'])); ?>
                </span>
              </div>
              <div class="contract-card-info-row">
                <span class="contract-card-label">보증금:</span>
                <span class="contract-card-value amount"><?php echo number_format($contract['deposit']); ?>원</span>
              </div>
              <div class="contract-card-info-row">
                <span class="contract-card-label">월세:</span>
                <span class="contract-card-value amount" style="color: #0064FF;"><?php echo number_format($contract['monthly_rent']); ?>원</span>
              </div>
            </div>

            <?php 
            $signatures = get_contract_signatures($contract['id'], $pdo, $contract);
            $requests = get_signature_request_status($contract, $signatures);
            ?>
            
            <?php if (!empty($requests)): ?>
              <div style="margin-bottom: 1rem; padding: 1rem; background-color: #fff3e0; border-radius: 8px; border-left: 4px solid #ff9800;">
                <div style="font-size: 0.9rem; font-weight: 600; color: #e65100; margin-bottom: 0.8rem;">📤 보낸 서명 요청</div>
                <?php foreach ($requests as $req): ?>
                  <div style="font-size: 0.85rem; margin-bottom: 0.5rem; color: #bf360c;">
                    <?php echo $req['korean']; ?>
                    <span style="color: #6c757d;">(<?php echo date('m/d H:i', strtotime($req['sent_at'])); ?>)</span>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            
            <?php if (!empty($signatures)): ?>
              <div style="margin-bottom: 1rem; padding: 1rem; background-color: #f8f9fa; border-radius: 8px; border-left: 4px solid #28a745;">
                <div style="font-size: 0.9rem; font-weight: 600; color: #155724; margin-bottom: 0.8rem;">✅ 완료된 서명</div>
                <?php foreach ($signatures as $sig): ?>
                  <div style="font-size: 0.85rem; margin-bottom: 0.5rem; color: #495057;">
                    <?php echo $sig['purpose_korean']; ?> 사진 <?php echo $sig['role_korean']; ?> 서명 완료
                    <span style="color: #6c757d;">(<?php echo date('m/d H:i', strtotime($sig['signed_at'])); ?>)</span>
                  </div>
                                     <?php if ($sig['has_mismatch'] && !$sig['mismatch_confirmed']): ?>
                     <div style="margin-left: 1rem; margin-bottom: 0.8rem; padding: 0.8rem; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 6px;">
                       <div style="font-size: 0.8rem; color: #856404; margin-bottom: 0.5rem;">
                         ⚠️ 서명자의 
                         <?php if ($sig['name_mismatch'] && $sig['phone_mismatch']): ?>
                           이름과 전화번호가
                         <?php elseif ($sig['name_mismatch']): ?>
                           이름이
                         <?php elseif ($sig['phone_mismatch']): ?>
                           전화번호가
                         <?php endif; ?>
                         계약서와 일치하지 않습니다.
                       </div>
                       <div style="font-size: 0.75rem; color: #6c757d; margin-bottom: 0.8rem; line-height: 1.4;">
                         서명자에게 연락하여 서명의 진위여부를 확인하세요.<br>
                         잘못된 서명이었다면 다시 서명 신청을 보내시고, 맞는 서명이라면 "확인완료" 버튼을 눌러주세요.
                       </div>
                       <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                         <button class="btn btn-warning" style="font-size: 0.75rem; padding: 0.3rem 0.6rem;" onclick="requestResign(<?php echo $contract['id']; ?>, '<?php echo $sig['purpose']; ?>', '<?php echo $sig['role']; ?>')">
                           서명 다시 요청
                         </button>
                         <button class="btn btn-success" style="font-size: 0.75rem; padding: 0.3rem 0.6rem;" onclick="confirmMismatch(<?php echo $sig['id']; ?>)">
                           확인완료
                         </button>
                       </div>
                     </div>
                   <?php endif; ?>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <div class="status-bar">
              <div class="status-label"><?php echo $status['label']; ?></div>
              <div class="progress-container">
                <div class="progress-bar" style="width: <?php echo min($status['progress'], 100); ?>%;"></div>
              </div>
            </div>

            <div class="contract-card-actions">
              <?php
                $s = $contract['status'];
                $button_label = $photo_button_labels[$s] ?? null;
                if ($button_label) {
              ?>
                <button class="btn btn-primary" onclick="window.location.href='photo_list.php?contract_id=<?php echo $contract['id']; ?>'">
                  <?php echo $button_label; ?>
                </button>
              <?php } ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <script>
    // 임대물 목록 버튼 호버 효과
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
          this.style.boxShadow = '0 2px 8px rgba(108, 117, 125, 0.3)';
        });
        
        backToListBtn.addEventListener('click', function() {
          this.style.transform = 'scale(0.95)';
          setTimeout(() => {
            this.style.transform = 'scale(1)';
          }, 150);
        });
      }
    });

    // 토글 스위치 이벤트 처리
    document.getElementById('showFinishedToggle').addEventListener('change', function() {
      const showFinished = this.checked;
      const currentUrl = new URL(window.location);
      
      if (showFinished) {
        currentUrl.searchParams.set('show_finished', '1');
      } else {
        currentUrl.searchParams.delete('show_finished');
      }
      
      window.location.href = currentUrl.toString();
    });

    // 비활성화된 수정 버튼 클릭 시 안내 메시지
    function showEditDisabledMessage() {
      alert('입주 임차인 서명이 완료된 후에는 계약 내용을 수정할 수 없습니다.\n\n계약의 무결성 보장을 위해 퇴거 과정 진행 중이거나 완료된 계약은 수정이 제한됩니다.');
    }

    // 서명 불일치 확인 완료 처리
    function confirmMismatch(signatureId) {
      if (!confirm('이 서명이 올바른 서명임을 확인하였습니까?\n확인 후에는 더 이상 불일치 경고가 표시되지 않습니다.')) {
        return;
      }

      const formData = new FormData();
      formData.append('action', 'confirm_mismatch');
      formData.append('signature_id', signatureId);

      fetch(window.location.href, {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          alert('서명 확인이 완료되었습니다.');
          location.reload();
        } else {
          alert('오류가 발생했습니다: ' + (data.message || '알 수 없는 오류'));
        }
      })
      .catch(error => {
        console.error('Error:', error);
        alert('서버 오류가 발생했습니다.');
      });
    }

    // 서명 다시 요청 - photo_list.php로 이동하여 해당 서명 요청
    function requestResign(contractId, purpose, role) {
      if (confirm(`${purpose === 'movein' ? '입주' : '퇴거'} 사진 ${role === 'landlord' ? '임대인' : '임차인'} 서명을 다시 요청하시겠습니까?`)) {
        window.location.href = `photo_list.php?contract_id=${contractId}&request_sign=${purpose}_${role}`;
      }
    }
  </script>

  <?php include 'footer.inc'; ?>
</body>
</html> 