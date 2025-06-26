<?php
require_once 'sql.inc';

// 로그인 확인 - 로그인되지 않은 경우 login.php로 리다이렉트
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
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

// 계약 정보 조회
$sql = "
    SELECT c.*
    FROM contracts c
    WHERE c.property_id = ? AND c.user_id = ?
";

if (!$show_finished) {
    $sql .= " AND c.status != 'finished'";
}

$sql .= " ORDER BY c.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute([$property_id, $user_id]);
$contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
        'progress' => 25,
        'buttons' => ['photo_edit', 'sign_request']
    ],
    'movein_landlord_signed' => [
        'label' => '임대인 서명 완료',
        'phase' => '입주',
        'progress' => 50,
        'buttons' => ['photo_edit', 'sign_request']
    ],
    'movein_tenant_signed' => [
        'label' => '임차인 서명 완료',
        'phase' => '입주',
        'progress' => 75,
        'buttons' => ['moveout_photo', 'complete']
    ],
    'moveout_photo' => [
        'label' => '파손 발생',
        'phase' => '퇴거',
        'progress' => 100,
        'buttons' => ['tenant_sign_request']
    ],
    'moveout_landlord_signed' => [
        'label' => '임차인 파손 인정',
        'phase' => '수리중',
        'progress' => 125,
        'buttons' => ['repair_request', 'complete']
    ],
    'moveout_tenant_signed' => [
        'label' => '수리중',
        'phase' => '수리중',
        'progress' => 150,
        'buttons' => ['complete']
    ],
    'in_repair' => [
        'label' => '수리중',
        'phase' => '수리중',
        'progress' => 175,
        'buttons' => ['complete']
    ],
    'finished' => [
        'label' => '계약 완료',
        'phase' => '완료',
        'progress' => 200,
        'buttons' => ['view_details']
    ]
];

// 버튼 텍스트 정의
$button_texts = [
    'photo_upload' => '사진 등록',
    'photo_edit' => '사진 수정',
    'sign_request' => '서명 요청',
    'moveout_photo' => '퇴실 사진 등록',
    'tenant_sign_request' => '임차인 서명 요청',
    'repair_request' => '수리 의뢰',
    'complete' => '완료',
    'view_details' => '상세보기'
];

// 버튼 라벨/액션 매핑
$photo_action_map = [
  'empty' => ['label' => '사진 등록', 'action' => 'photo_upload'],
  'movein_photo' => ['label' => '사진 등록', 'action' => 'photo_upload'],
  'movein_landlord_signed' => ['label' => '사진 확인 전송', 'action' => 'photo_confirm_send'],
  'movein_tenant_signed' => ['label' => '입주 사진 확인(퇴거 사진 등록)', 'action' => 'movein_photo_confirm'],
  'moveout_photo' => ['label' => '입주 사진 확인(퇴거 사진 등록)', 'action' => 'movein_photo_confirm'],
  'moveout_landlord_signed' => ['label' => '퇴거 사진 확인 전송', 'action' => 'moveout_confirm_send'],
  'moveout_tenant_signed' => ['label' => '퇴거 사진 확인', 'action' => 'moveout_confirm'],
  'in_repair' => ['label' => '퇴거 사진 확인', 'action' => 'moveout_confirm'],
  'finished' => ['label' => '사진 확인', 'action' => 'photo_view'],
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
      background-color: #e8f5e8;
      color: #388e3c;
      border: 1px solid #c8e6c9;
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
        <a href="properties.php" class="btn btn-secondary">
          ← 임대물 목록으로
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
                    계약 #<?php echo $contract['id']; ?>
                    <a href="contract_edit.php?property_id=<?php echo $property_id; ?>&id=<?php echo $contract['id']; ?>" class="edit-btn" title="수정">
                      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M12.854.146a.5.5 0 0 0-.707 0L10.5 1.793 14.207 5.5l1.647-1.646a.5.5 0 0 0 0-.708l-3-3zm.646 6.061L9.793 2.5 3.293 9H3.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.207l6.5-6.5zm-7.468 7.468A.5.5 0 0 1 6 13.5V13h-.5a.5.5 0 0 1-.5-.5V12h-.5a.5.5 0 0 1-.5-.5V11h-.5a.5.5 0 0 1-.5-.5V10h-.5a.499.499 0 0 1-.175-.032l-.179.178a.5.5 0 0 0-.11.168l-2 5a.5.5 0 0 0 .65.65l5-2a.5.5 0 0 0 .168-.11l.178-.178z"/></svg>
                    </a>
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
                      $photo_btn = $photo_action_map[$s] ?? null;
                      if ($photo_btn) {
                    ?>
                      <button class="btn btn-primary" onclick="handlePhotoAction('<?php echo $photo_btn['action']; ?>', <?php echo $contract['id']; ?>)">
                        <?php echo $photo_btn['label']; ?>
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
                계약 #<?php echo $contract['id']; ?>
                <a href="contract_edit.php?property_id=<?php echo $property_id; ?>&id=<?php echo $contract['id']; ?>" class="edit-btn" title="수정">
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M12.854.146a.5.5 0 0 0-.707 0L10.5 1.793 14.207 5.5l1.647-1.646a.5.5 0 0 0 0-.708l-3-3zm.646 6.061L9.793 2.5 3.293 9H3.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.207l6.5-6.5zm-7.468 7.468A.5.5 0 0 1 6 13.5V13h-.5a.5.5 0 0 1-.5-.5V12h-.5a.5.5 0 0 1-.5-.5V11h-.5a.5.5 0 0 1-.5-.5V10h-.5a.499.499 0 0 1-.175-.032l-.179.178a.5.5 0 0 0-.11.168l-2 5a.5.5 0 0 0 .65.65l5-2a.5.5 0 0 0 .168-.11l.178-.178z"/></svg>
                </a>
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

            <div class="status-bar">
              <div class="status-label"><?php echo $status['label']; ?></div>
              <div class="progress-container">
                <div class="progress-bar" style="width: <?php echo min($status['progress'], 100); ?>%;"></div>
              </div>
            </div>

            <div class="contract-card-actions">
              <?php
                $s = $contract['status'];
                $photo_btn = $photo_action_map[$s] ?? null;
                if ($photo_btn) {
              ?>
                <button class="btn btn-primary" onclick="handlePhotoAction('<?php echo $photo_btn['action']; ?>', <?php echo $contract['id']; ?>)">
                  <?php echo $photo_btn['label']; ?>
                </button>
              <?php } ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <script>
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

    function handlePhotoAction(action, contractId) {
      switch(action) {
        case 'photo_upload':
          window.location.href = 'photo_list.php?contract_id=' + contractId;
          break;
        case 'photo_confirm_send':
          alert('사진 확인 전송 기능을 구현해주세요. 계약 ID: ' + contractId);
          break;
        case 'movein_photo_confirm':
          alert('입주 사진 확인(퇴거 사진 등록) 기능을 구현해주세요. 계약 ID: ' + contractId);
          break;
        case 'moveout_confirm_send':
          alert('퇴거 사진 확인 전송 기능을 구현해주세요. 계약 ID: ' + contractId);
          break;
        case 'moveout_confirm':
          alert('퇴거 사진 확인 기능을 구현해주세요. 계약 ID: ' + contractId);
          break;
        case 'photo_view':
          window.location.href = 'photo_list.php?contract_id=' + contractId;
          break;
        default:
          alert('알 수 없는 사진 관련 액션입니다.');
      }
    }
  </script>

  <?php include 'footer.inc'; ?>
</body>
</html> 