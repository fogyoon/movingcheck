<?php
require_once 'sql.inc';

// 로그인 확인 - 로그인되지 않은 경우 login.php로 리다이렉트
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$property_id = (int)($_GET['property_id'] ?? 0);
$contract_id = (int)($_GET['id'] ?? 0);

if (!$property_id && !$contract_id) {
    header('Location: properties.php');
    exit;
}

$pdo = get_pdo();

// 계약 수정인 경우 기존 데이터 조회
$contract = null;
if ($contract_id) {
    $stmt = $pdo->prepare("
        SELECT c.*, p.address, p.detail_address
        FROM contracts c 
        JOIN properties p ON c.property_id = p.id 
        WHERE c.id = ? AND p.created_by = ?
    ");
    $stmt->execute([$contract_id, $user_id]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$contract) {
        header('Location: properties.php');
        exit;
    }
    $property_id = $contract['property_id'];
}

// 임대물 정보 조회
$stmt = $pdo->prepare("SELECT * FROM properties WHERE id = ? AND created_by = ?");
$stmt->execute([$property_id, $user_id]);
$property = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$property) {
    header('Location: properties.php');
    exit;
}

// 사용자 목록 조회 (임대인, 임차인, 중개사)
$stmt = $pdo->prepare("SELECT id, nickname, role FROM users WHERE role IN ('landlord', 'tenant', 'agent') ORDER BY role, nickname");
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 현재 사용자 정보 조회
$stmt = $pdo->prepare("SELECT id, nickname, role, phone FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$current_user = $stmt->fetch(PDO::FETCH_ASSOC);

// 임대인/중개사 전화번호 자동 입력용 변수
$auto_landlord_phone = '';
$auto_agent_phone = '';
if ($current_user['role'] === 'landlord' && !empty($current_user['phone'])) {
    $auto_landlord_phone = $current_user['phone'];
}
if ($current_user['role'] === 'agent' && !empty($current_user['phone'])) {
    $auto_agent_phone = $current_user['phone'];
}

// 폼 제출 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $landlord_name = trim($_POST['landlord_name'] ?? '');
    $tenant_name = trim($_POST['tenant_name'] ?? '');
    $agent_name = trim($_POST['agent_name'] ?? '');
    $landlord_phone = trim($_POST['landlord_phone'] ?? '');
    $tenant_phone = trim($_POST['tenant_phone'] ?? '');
    $agent_phone = trim($_POST['agent_phone'] ?? '');
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $deposit = (int)($_POST['deposit'] ?? 0);
    $monthly_rent = (int)($_POST['monthly_rent'] ?? 0);
    
    // 파일 업로드 처리
    $contract_file = null;
    if (isset($_FILES['contract_file']) && $_FILES['contract_file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'contracts/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_extension = strtolower(pathinfo($_FILES['contract_file']['name'], PATHINFO_EXTENSION));
        
        // 허용된 파일 형식 체크
        $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png'];
        if (!in_array($file_extension, $allowed_extensions)) {
            $error = '지원하지 않는 파일 형식입니다. PDF, JPG, PNG 파일만 업로드 가능합니다.';
        } else {
            // 파일명 생성: contract_{계약ID}_{랜덤문자열}_{날짜}.{확장자}
            $current_date = date('Ymd');
            $random_string = bin2hex(random_bytes(8)); // 16자리 랜덤 문자열
            
            // 임시 파일명 (계약 ID가 아직 없을 수 있음)
            if ($contract_id) {
                $file_name = sprintf('contract_%03d_%s_%s.%s', 
                    $contract_id, 
                    $random_string,
                    $current_date, 
                    $file_extension
                );
            } else {
                $file_name = sprintf('contract_new_%s_%s.%s', 
                    $random_string,
                    $current_date, 
                    $file_extension
                );
            }
            
            $file_path = $upload_dir . $file_name;
            
            // 파일명 중복 체크 및 처리
            $counter = 1;
            $original_file_path = $file_path;
            while (file_exists($file_path)) {
                $path_info = pathinfo($original_file_path);
                $file_name = sprintf('%s_%d.%s', 
                    $path_info['filename'], 
                    $counter, 
                    $path_info['extension']
                );
                $file_path = $upload_dir . $file_name;
                $counter++;
            }
            
            if (move_uploaded_file($_FILES['contract_file']['tmp_name'], $file_path)) {
                $contract_file = $file_path;
            } else {
                $error = '파일 업로드에 실패했습니다.';
            }
        }
    }
    
    try {
        if ($contract_id) {
            // 계약 수정
            $sql = "UPDATE contracts SET 
                    landlord_name = ?, tenant_name = ?, agent_name = ?, 
                    landlord_phone = ?, tenant_phone = ?, agent_phone = ?,
                    start_date = ?, end_date = ?, deposit = ?, monthly_rent = ?";
            $params = [$landlord_name, $tenant_name, $agent_name, $landlord_phone, $tenant_phone, $agent_phone, $start_date, $end_date, $deposit, $monthly_rent];
            
            if ($contract_file) {
                $sql .= ", contract_file = ?";
                $params[] = $contract_file;
            }
            
            $sql .= " WHERE id = ?";
            $params[] = $contract_id;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            // 파일이 업로드된 경우 파일명을 올바른 계약 ID로 업데이트
            if ($contract_file && file_exists($contract_file)) {
                $file_extension = pathinfo($contract_file, PATHINFO_EXTENSION);
                $random_string = bin2hex(random_bytes(8)); // 16자리 랜덤 문자열
                $current_date = date('Ymd');
                
                $new_file_name = sprintf('contract_%03d_%s_%s.%s', 
                    $contract_id, 
                    $random_string,
                    $current_date, 
                    $file_extension
                );
                
                $new_file_path = 'contracts/' . $new_file_name;
                
                // 파일명 중복 체크 및 처리
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
                
                if (rename($contract_file, $new_file_path)) {
                    // 데이터베이스의 파일 경로도 업데이트
                    $stmt = $pdo->prepare("UPDATE contracts SET contract_file = ? WHERE id = ?");
                    $stmt->execute([$new_file_path, $contract_id]);
                }
            }
            
            log_user_activity($user_id, 'update_contract', '계약 수정 (ID: ' . $contract_id . ')');
            $message = '계약이 성공적으로 수정되었습니다.';
        } else {
            // 새 계약 등록
            $sql = "INSERT INTO contracts (property_id, user_id, landlord_name, tenant_name, agent_name, landlord_phone, tenant_phone, agent_phone, start_date, end_date, deposit, monthly_rent, contract_file, created_by, created_ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$property_id, $user_id, $landlord_name, $tenant_name, $agent_name, $landlord_phone, $tenant_phone, $agent_phone, $start_date, $end_date, $deposit, $monthly_rent, $contract_file, $user_id, $_SERVER['REMOTE_ADDR']]);
            
            $contract_id = $pdo->lastInsertId();
            
            // 파일이 업로드된 경우 파일명을 올바른 계약 ID로 업데이트
            if ($contract_file && file_exists($contract_file)) {
                $file_extension = pathinfo($contract_file, PATHINFO_EXTENSION);
                $random_string = bin2hex(random_bytes(8)); // 16자리 랜덤 문자열
                $current_date = date('Ymd');
                
                $new_file_name = sprintf('contract_%03d_%s_%s.%s', 
                    $contract_id, 
                    $random_string,
                    $current_date, 
                    $file_extension
                );
                
                $new_file_path = 'contracts/' . $new_file_name;
                
                // 파일명 중복 체크 및 처리
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
                
                if (rename($contract_file, $new_file_path)) {
                    // 데이터베이스의 파일 경로도 업데이트
                    $stmt = $pdo->prepare("UPDATE contracts SET contract_file = ? WHERE id = ?");
                    $stmt->execute([$new_file_path, $contract_id]);
                    $contract_file = $new_file_path;
                }
            }
            
            log_user_activity($user_id, 'create_contract', '새 계약 등록 (ID: ' . $contract_id . ')');
            $message = '계약이 성공적으로 등록되었습니다.';
        }
        
        // 성공 후 계약 목록으로 리다이렉트
        header('Location: contracts.php?property_id=' . $property_id . '&success=1');
        exit;
        
    } catch (PDOException $e) {
        $error = '계약 저장 중 오류가 발생했습니다: ' . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo $contract_id ? '계약 수정' : '새 계약 등록'; ?> - <?php echo SITE_TITLE; ?></title>
  <link rel="stylesheet" href="style.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@400;700;900&display=swap" rel="stylesheet">
  <style>
    /* =================================== */
    /* 계약 등록/수정 페이지 스타일 */
    /* =================================== */
    
    .contract-edit-container {
      max-width: 800px;
      margin: 2rem auto;
      padding: 0 1rem;
      width: 100%;
      box-sizing: border-box;
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

    /* 폼 */
    .contract-form {
      background-color: #fff;
      border: 1px solid #e3eaf2;
      border-radius: 12px;
      padding: 2rem;
      box-shadow: 0 4px 18px rgba(0,100,255,0.05);
      overflow: hidden; /* 내용이 넘치지 않도록 */
    }
    .form-section {
      margin-bottom: 2rem;
    }
    .form-section-title {
      font-size: 1.2rem;
      font-weight: 700;
      color: #333;
      margin-bottom: 1rem;
      padding-bottom: 0.5rem;
      border-bottom: 2px solid #0064FF;
    }
    .form-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1.5rem;
      margin-bottom: 1rem;
      align-items: start; /* 상단 정렬로 겹침 방지 */
    }
    .form-group {
      margin-bottom: 1rem;
      min-width: 0; /* 겹침 방지 */
      width: 100%; /* 전체 너비 사용 */
    }
    .form-group.full-width {
      grid-column: 1 / -1;
    }
    .form-label {
      display: block;
      font-weight: 600;
      color: #333;
      margin-bottom: 0.5rem;
      white-space: nowrap; /* 라벨 줄바꿈 방지 */
    }
    .form-input, .form-select {
      width: 100%;
      padding: 0.75rem;
      border: 1px solid #ced4da;
      border-radius: 8px;
      font-size: 1rem;
      transition: border-color 0.2s, box-shadow 0.2s;
      box-sizing: border-box; /* 패딩 포함 너비 계산 */
      max-width: 100%; /* 최대 너비 제한 */
      overflow: hidden; /* 내용 넘침 방지 */
      text-overflow: ellipsis; /* 긴 텍스트 처리 */
    }
    .form-input:focus, .form-select:focus {
      outline: none;
      border-color: #0064FF;
      box-shadow: 0 0 0 3px rgba(0,100,255,0.15);
    }
    .form-input[type="file"] {
      padding: 0.5rem;
    }
    .form-help {
      font-size: 0.9rem;
      color: #6c757d;
      margin-top: 0.25rem;
    }

    /* 파일 업로드 영역 */
    .file-upload-area {
      border: 2px dashed #ced4da;
      border-radius: 8px;
      padding: 2rem;
      text-align: center;
      transition: border-color 0.2s;
      cursor: pointer;
    }
    .file-upload-area:hover {
      border-color: #0064FF;
    }
    .file-upload-area.dragover {
      border-color: #0064FF;
      background-color: rgba(0,100,255,0.05);
    }
    .file-upload-icon {
      font-size: 3rem;
      color: #6c757d;
      margin-bottom: 1rem;
    }
    .file-upload-text {
      font-size: 1.1rem;
      color: #333;
      margin-bottom: 0.5rem;
    }
    .file-upload-hint {
      font-size: 0.9rem;
      color: #6c757d;
    }

    /* OCR 결과 미리보기 */
    .ocr-preview {
      background-color: #f8f9fa;
      border: 1px solid #e3eaf2;
      border-radius: 8px;
      padding: 1rem;
      margin-top: 1rem;
      display: none;
    }
    .ocr-preview.show {
      display: block;
    }
    .ocr-preview-title {
      font-weight: 600;
      color: #333;
      margin-bottom: 0.5rem;
    }
    .ocr-preview-content {
      font-size: 0.9rem;
      color: #666;
      line-height: 1.5;
    }

    /* 알림 메시지 */
    .alert {
      padding: 1rem;
      border-radius: 8px;
      margin-bottom: 1rem;
    }
    .alert-success {
      background-color: #d4edda;
      color: #155724;
      border: 1px solid #c3e6cb;
    }
    .alert-danger {
      background-color: #f8d7da;
      color: #721c24;
      border: 1px solid #f5c6cb;
    }

    /* 반응형 */
    @media (max-width: 768px) {
      .contract-edit-container {
        padding: 0 0.5rem;
        margin: 1rem auto;
      }
      .form-row {
        grid-template-columns: 1fr;
        gap: 0.5rem;
      }
      .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
      }
      .contract-form {
        padding: 1rem;
        margin: 0;
        border-radius: 8px;
      }
      .form-input, .form-select {
        font-size: 16px; /* 모바일에서 줌 방지 */
        padding: 0.75rem;
        width: 100%;
        box-sizing: border-box;
        border-radius: 6px;
      }
      .form-row .form-group {
        margin-bottom: 0.5rem;
      }
      .form-group {
        margin-bottom: 0.75rem;
      }
      .form-section {
        margin-bottom: 1.5rem;
      }
      .form-section-title {
        font-size: 1.1rem;
        margin-bottom: 0.75rem;
      }
    }
    
    /* 작은 화면에서 추가 조정 */
    @media (max-width: 480px) {
      .contract-edit-container {
        padding: 0 0.25rem;
      }
      .contract-form {
        padding: 0.75rem;
      }
      .form-input, .form-select {
        padding: 0.6rem;
        font-size: 16px;
      }
    }

    /* 금액 입력 필드 */
    .amount-input {
      font-family: 'Courier New', monospace;
      font-weight: 600;
    }
    
    /* 자동 입력 필드 스타일 */
    .form-input[readonly], .form-select[disabled] {
      background-color: #f8f9fa;
      color: #6c757d;
      cursor: not-allowed;
    }
    
    /* 드롭다운 화살표 숨기기 */
    .form-select[disabled] {
      -webkit-appearance: none;
      -moz-appearance: none;
      appearance: none;
      background-image: none !important;
    }
    
    /* readonly 입력 필드 스타일 */
    .form-input[readonly] {
      background-color: #f8f9fa;
      border-color: #dee2e6;
    }
    
    /* 필수 필드 스타일 */
    .form-label.required::after {
      content: ' *';
      color: #dc3545;
      font-weight: bold;
    }
    
    .form-input:required:invalid {
      border-color: #dc3545;
    }
    
    .form-input:required:valid {
      border-color: #28a745;
    }
  </style>
</head>
<body>
  <?php include 'header.inc'; ?>

  <div class="contract-edit-container">
    <div class="page-header">
      <div>
        <h1 class="page-title"><?php echo $contract_id ? '계약 수정' : '새 계약 등록'; ?></h1>
        <div class="page-subtitle"><?php echo htmlspecialchars($property['address']); ?> <?php echo htmlspecialchars($property['detail_address']); ?></div>
      </div>
      <a href="contracts.php?property_id=<?php echo $property_id; ?>" class="btn btn-secondary">
        ← 계약 목록으로
      </a>
    </div>

    <?php if (isset($error)): ?>
      <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form class="contract-form" method="post" enctype="multipart/form-data">
      <!-- 계약서 업로드 섹션 -->
      <div class="form-section">
        <h2 class="form-section-title">계약서 업로드 (선택사항)</h2>
        <div class="form-group full-width">
          <div class="file-upload-area" id="fileUploadArea">
            <div class="file-upload-icon">📄</div>
            <div class="file-upload-text">계약서 사진을 업로드하세요</div>
            <div class="file-upload-hint">JPG, PNG, PDF 파일 지원 (최대 10MB)</div>
            <input type="file" name="contract_file" id="contractFile" accept="image/*,.pdf" style="display: none;">
          </div>
        </div>
      </div>

      <!-- 계약 정보 입력 섹션 -->
      <div class="form-section">
        <h2 class="form-section-title">계약 정보</h2>
        
        <div class="form-row">
          <div class="form-group">
            <label class="form-label required" for="landlord_name">임대인</label>
            <input type="text" class="form-input" name="landlord_name" id="landlord_name" 
                   value="<?php 
                     if ($contract) {
                       echo htmlspecialchars($contract['landlord_name'] ?? '');
                     } elseif ($current_user['role'] === 'landlord') {
                       echo htmlspecialchars($current_user['nickname']);
                     }
                   ?>" 
                   <?php echo ($current_user['role'] === 'landlord') ? 'readonly' : ''; ?> required>
            <?php if ($current_user['role'] === 'landlord'): ?>
              <div class="form-help">자동 입력됨 (작성자)</div>
            <?php endif; ?>
          </div>
          
          <div class="form-group">
            <label class="form-label required" for="tenant_name">임차인</label>
            <input type="text" class="form-input" name="tenant_name" id="tenant_name" 
                   value="<?php echo $contract ? htmlspecialchars($contract['tenant_name'] ?? '') : ''; ?>" required>
            <div class="form-help">임차인 이름을 입력하세요</div>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label required" for="landlord_phone">임대인 전화번호</label>
            <input type="tel" class="form-input" name="landlord_phone" id="landlord_phone" 
                   value="<?php echo $contract ? htmlspecialchars($contract['landlord_phone'] ?? '') : (isset($auto_landlord_phone) ? htmlspecialchars($auto_landlord_phone) : ''); ?>"
                   placeholder="010-1234-5678" required>
            <div class="form-help">임대인 연락처를 입력하세요</div>
          </div>
          
          <div class="form-group">
            <label class="form-label required" for="tenant_phone">임차인 전화번호</label>
            <input type="tel" class="form-input" name="tenant_phone" id="tenant_phone" 
                   value="<?php echo $contract ? htmlspecialchars($contract['tenant_phone'] ?? '') : ''; ?>"
                   placeholder="010-1234-5678" required>
            <div class="form-help">임차인 연락처를 입력하세요</div>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="agent_name">중개사</label>
          <input type="text" class="form-input" name="agent_name" id="agent_name"
                 value="<?php echo $contract ? htmlspecialchars($contract['agent_name'] ?? '') : ''; ?>"
                 placeholder="중개사 이름 또는 ID 입력 (선택사항)">
          <div class="form-help">중개사 이름 또는 ID를 입력하세요</div>
        </div>

        <div class="form-group">
          <label class="form-label" for="agent_phone">중개사 전화번호</label>
          <input type="tel" class="form-input" name="agent_phone" id="agent_phone" 
                 value="<?php echo $contract ? htmlspecialchars($contract['agent_phone'] ?? '') : (isset($auto_agent_phone) ? htmlspecialchars($auto_agent_phone) : ''); ?>"
                 placeholder="010-1234-5678">
          <div class="form-help">중개사 연락처를 입력하세요</div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label required" for="start_date">계약 시작일</label>
            <input type="date" class="form-input" name="start_date" id="start_date" 
                   value="<?php echo $contract ? $contract['start_date'] : ''; ?>" required>
          </div>
          
          <div class="form-group">
            <label class="form-label required" for="end_date">계약 종료일</label>
            <input type="date" class="form-input" name="end_date" id="end_date" 
                   value="<?php echo $contract ? $contract['end_date'] : ''; ?>" required>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label required" for="deposit">보증금 (원)</label>
            <input type="number" class="form-input amount-input" name="deposit" id="deposit" 
                   value="<?php echo $contract ? $contract['deposit'] : ''; ?>" 
                   placeholder="0" min="0" step="100000" required>
            <div class="form-help">숫자만 입력 (예: 10000000)</div>
          </div>
          
          <div class="form-group">
            <label class="form-label required" for="monthly_rent">월세 (원)</label>
            <input type="number" class="form-input amount-input" name="monthly_rent" id="monthly_rent" 
                   value="<?php echo $contract ? $contract['monthly_rent'] : ''; ?>" 
                   placeholder="0" min="0" step="10000" required>
            <div class="form-help">숫자만 입력 (예: 800000)</div>
          </div>
        </div>
      </div>

      <!-- 버튼 영역 -->
      <div class="form-section" style="text-align: center; margin-top: 2rem;">
        <button type="submit" class="btn btn-primary" style="padding: 0.75rem 2rem; font-size: 1.1rem;">
          <?php echo $contract_id ? '계약 수정' : '계약 등록'; ?>
        </button>
        <a href="contracts.php?property_id=<?php echo $property_id; ?>" class="btn btn-secondary" style="margin-left: 1rem;">
          취소
        </a>
      </div>
    </form>
  </div>

  <script>
    // 파일 업로드 영역 이벤트 처리
    const fileUploadArea = document.getElementById('fileUploadArea');
    const contractFile = document.getElementById('contractFile');

    fileUploadArea.addEventListener('click', () => {
      // 파일이 이미 선택된 경우 초기화
      if (contractFile.files.length > 0) {
        contractFile.value = '';
        const fileUploadText = document.querySelector('.file-upload-text');
        const fileUploadHint = document.querySelector('.file-upload-hint');
        fileUploadText.textContent = '계약서 사진을 업로드하세요';
        fileUploadHint.textContent = 'JPG, PNG, PDF 파일 지원 (최대 10MB)';
      }
      contractFile.click();
    });

    fileUploadArea.addEventListener('dragover', (e) => {
      e.preventDefault();
      fileUploadArea.classList.add('dragover');
    });

    fileUploadArea.addEventListener('dragleave', () => {
      fileUploadArea.classList.remove('dragover');
    });

    fileUploadArea.addEventListener('drop', (e) => {
      e.preventDefault();
      fileUploadArea.classList.remove('dragover');
      
      const files = e.dataTransfer.files;
      if (files.length > 0) {
        // 기존 파일 초기화
        contractFile.value = '';
        const fileUploadText = document.querySelector('.file-upload-text');
        const fileUploadHint = document.querySelector('.file-upload-hint');
        fileUploadText.textContent = '계약서 사진을 업로드하세요';
        fileUploadHint.textContent = 'JPG, PNG, PDF 파일 지원 (최대 10MB)';
        
        // 스타일 초기화
        fileUploadArea.style.borderColor = '';
        fileUploadArea.style.backgroundColor = '';
        
        contractFile.files = files;
        handleFileUpload(files[0]);
      }
    });

    contractFile.addEventListener('change', (e) => {
      if (e.target.files.length > 0) {
        handleFileUpload(e.target.files[0]);
      }
    });

    function handleFileUpload(file) {
      // 파일 크기 체크 (10MB)
      if (file.size > 10 * 1024 * 1024) {
        alert('파일 크기는 10MB 이하여야 합니다.');
        return;
      }

      // 파일 타입 체크
      const allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
      if (!allowedTypes.includes(file.type)) {
        alert('JPG, PNG, PDF 파일만 업로드 가능합니다.');
        return;
      }

      // 파일 업로드 영역 텍스트 업데이트
      const fileUploadText = document.querySelector('.file-upload-text');
      const fileUploadHint = document.querySelector('.file-upload-hint');
      fileUploadText.textContent = `선택된 파일: ${file.name}`;
      fileUploadHint.textContent = `파일 크기: ${(file.size / 1024 / 1024).toFixed(2)}MB`;
      
      // 파일 선택 상태 시각적 피드백
      fileUploadArea.style.borderColor = '#28a745';
      fileUploadArea.style.backgroundColor = '#f8fff9';
    }

    // 금액 입력 필드 포맷팅
    document.getElementById('deposit').addEventListener('input', function(e) {
      const value = e.target.value.replace(/[^0-9]/g, '');
      e.target.value = value;
      
      // 한글 금액 표시
      if (value) {
        const koreanAmount = convertToKoreanCurrency(parseInt(value));
        const depositHelp = e.target.parentNode.querySelector('.form-help');
        if (depositHelp) {
          depositHelp.textContent = `한글: ${koreanAmount}`;
        }
      } else {
        const depositHelp = e.target.parentNode.querySelector('.form-help');
        if (depositHelp) {
          depositHelp.textContent = '숫자만 입력 (예: 10000000)';
        }
      }
    });

    document.getElementById('monthly_rent').addEventListener('input', function(e) {
      const value = e.target.value.replace(/[^0-9]/g, '');
      e.target.value = value;
      
      // 한글 금액 표시
      if (value) {
        const koreanAmount = convertToKoreanCurrency(parseInt(value));
        const rentHelp = e.target.parentNode.querySelector('.form-help');
        if (rentHelp) {
          rentHelp.textContent = `한글: ${koreanAmount}`;
        }
      } else {
        const rentHelp = e.target.parentNode.querySelector('.form-help');
        if (rentHelp) {
          rentHelp.textContent = '숫자만 입력 (예: 800000)';
        }
      }
    });

    // 숫자를 한글 금액으로 변환하는 함수
    function convertToKoreanCurrency(num) {
      if (num === 0) return '영원';
      
      const units = ['', '만', '억', '조'];
      const digits = ['', '일', '이', '삼', '사', '오', '육', '칠', '팔', '구'];
      const positions = ['', '십', '백', '천'];
      
      let result = '';
      let unitIndex = 0;
      
      while (num > 0) {
        let section = num % 10000;
        let sectionStr = '';
        
        if (section > 0) {
          let pos = 0;
          while (section > 0) {
            const digit = section % 10;
            if (digit > 0) {
              if (pos > 0) {
                sectionStr = positions[pos] + sectionStr;
              }
              if (digit > 1 || pos === 0) {
                sectionStr = digits[digit] + sectionStr;
              }
            }
            section = Math.floor(section / 10);
            pos++;
          }
          
          if (unitIndex > 0) {
            sectionStr += units[unitIndex];
          }
          
          result = sectionStr + result;
        }
        
        num = Math.floor(num / 10000);
        unitIndex++;
      }
      
      return result + '원';
    }

    // 페이지 로드 시 기존 값에 대한 한글 금액 표시
    document.addEventListener('DOMContentLoaded', function() {
      const depositInput = document.getElementById('deposit');
      const monthlyRentInput = document.getElementById('monthly_rent');
      
      if (depositInput && depositInput.value) {
        const koreanAmount = convertToKoreanCurrency(parseInt(depositInput.value));
        const depositHelp = depositInput.parentNode.querySelector('.form-help');
        if (depositHelp) {
          depositHelp.textContent = `한글: ${koreanAmount}`;
        }
      }
      
      if (monthlyRentInput && monthlyRentInput.value) {
        const koreanAmount = convertToKoreanCurrency(parseInt(monthlyRentInput.value));
        const rentHelp = monthlyRentInput.parentNode.querySelector('.form-help');
        if (rentHelp) {
          rentHelp.textContent = `한글: ${koreanAmount}`;
        }
      }
    });

    // 폼 제출 시 필수 항목 검증
    document.querySelector('.contract-form').addEventListener('submit', function(e) {
      const landlordName = document.getElementById('landlord_name').value.trim();
      const tenantName = document.getElementById('tenant_name').value.trim();
      const landlordPhone = document.getElementById('landlord_phone').value.trim();
      const tenantPhone = document.getElementById('tenant_phone').value.trim();
      const startDate = document.getElementById('start_date').value.trim();
      const endDate = document.getElementById('end_date').value.trim();
      const deposit = document.getElementById('deposit').value.trim();
      const monthlyRent = document.getElementById('monthly_rent').value.trim();
      
      let hasError = false;
      let errorMessage = '';
      
      if (!landlordName) {
        errorMessage += '• 임대인 이름을 입력해주세요.\n';
        hasError = true;
      }
      
      if (!tenantName) {
        errorMessage += '• 임차인 이름을 입력해주세요.\n';
        hasError = true;
      }
      
      if (!landlordPhone) {
        errorMessage += '• 임대인 전화번호를 입력해주세요.\n';
        hasError = true;
      }
      
      if (!tenantPhone) {
        errorMessage += '• 임차인 전화번호를 입력해주세요.\n';
        hasError = true;
      }
      
      if (!startDate) {
        errorMessage += '• 계약 시작일을 입력해주세요.\n';
        hasError = true;
      }
      
      if (!endDate) {
        errorMessage += '• 계약 종료일을 입력해주세요.\n';
        hasError = true;
      }
      
      if (!deposit) {
        errorMessage += '• 보증금을 입력해주세요.\n';
        hasError = true;
      }
      
      if (!monthlyRent) {
        errorMessage += '• 월세를 입력해주세요.\n';
        hasError = true;
      }
      
      // 계약 기간 검증
      if (startDate && endDate && startDate >= endDate) {
        errorMessage += '• 계약 종료일은 시작일보다 늦어야 합니다.\n';
        hasError = true;
      }
      
      if (hasError) {
        e.preventDefault();
        alert('다음 필수 항목을 입력해주세요:\n\n' + errorMessage);
        return false;
      }
    });
  </script>

  <?php include 'footer.inc'; ?>
</body>
</html> 