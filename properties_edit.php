<?php
require_once 'sql.inc';
require_once 'config.inc';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$pdo = get_pdo();
$user_id = $_SESSION['user_id'];
$editing = false;
$property = [
    'address' => '',
    'detail_address' => '',
    'category' => PROPERTY_CATEGORY[0],
    'description' => ''
];
$msg = '';
$msg_type = '';

// 기존 임대물 ID가 제공되었는지 확인
$property = null;
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    die('로그인이 필요합니다.');
}

$is_edit_mode = false;
if (isset($_GET['id'])) {
    $property_id = safe_int($_GET['id']);
    if ($property_id) {
        $stmt = $pdo->prepare('SELECT * FROM properties WHERE id = ? AND created_by = ?');
        $stmt->execute([$property_id, $user_id]);
        $property = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($property) {
            $is_edit_mode = true;
        } else {
            $msg = '수정할 권한이 없거나 임대물이 존재하지 않습니다.';
            $msg_type = 'error';
        }
    }
}

// 저장 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 입력 검증 규칙 정의
    $validation_rules = [
        'address' => ['type' => 'string', 'required' => true, 'max_length' => 200],
        'detail_address' => ['type' => 'string', 'required' => false, 'max_length' => 100],
        'category' => ['type' => 'string', 'required' => true, 'max_length' => 32],
        'description' => ['type' => 'string', 'required' => false, 'max_length' => 1000]
    ];
    
    // 입력 데이터 검증
    $validation = validate_form_data($_POST, $validation_rules);
    
    if (!$validation['valid']) {
        $error = '입력 형식이 올바르지 않습니다: ' . implode(', ', $validation['errors']);
    } else {
        $address = $validation['data']['address'];
        $detail_address = $validation['data']['detail_address'];
        $category = $validation['data']['category'] ?? PROPERTY_CATEGORY[0];
        $description = $validation['data']['description'];
        
        try {
            if ($is_edit_mode && $property) {
                // 임대물 수정
                $stmt = $pdo->prepare('UPDATE properties SET address=?, detail_address=?, category=?, description=?, updated_at=NOW(), updated_ip=? WHERE id=? AND created_by=?');
                $stmt->execute([$address, $detail_address, $category, $description, $_SERVER['REMOTE_ADDR'] ?? '', $property['id'], $user_id]);
                log_user_activity($user_id, 'update_property', '임대물(ID:' . $property['id'] . ') 수정: ' . $address, null, $property['id']);
                
                // 세션에 성공 메시지 저장하고 properties.php로 리다이렉트
                $_SESSION['success_msg'] = '임대물이 성공적으로 수정되었습니다.';
                header('Location: properties.php');
                exit;
            } else {
                // 새 임대물 등록 - 중복 체크 먼저 수행
                // detail_address 정규화: 빈 문자열을 빈 문자열로 통일
                $detail_address_check = trim($detail_address);
                
                // 기존 등록된 임대물 중 동일한 주소가 있는지 확인
                $check_stmt = $pdo->prepare('SELECT id FROM properties WHERE address = ? AND TRIM(COALESCE(detail_address, \'\')) = ? AND created_by = ?');
                $check_stmt->execute([$address, $detail_address_check, $user_id]);
                $existing_property = $check_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existing_property) {
                    // 중복된 임대물이 있는 경우
                    $msg = '동일한 주소의 임대물이 이미 등록되어 있습니다. 주소를 다시 확인해주세요.';
                    $msg_type = 'error';
                } else {
                    // 중복이 없는 경우 새 임대물 등록
                    $stmt = $pdo->prepare('INSERT INTO properties (address, detail_address, category, description, created_by, created_ip) VALUES (?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$address, $detail_address, $category, $description, $user_id, $_SERVER['REMOTE_ADDR'] ?? '']);
                    $new_property_id = $pdo->lastInsertId();
                    log_user_activity($user_id, 'create_property', '새 임대물 등록: ' . $address, null, $new_property_id);
                    
                    // 새로 등록된 임대물의 계약 목록 페이지로 리다이렉트
                    header('Location: contracts.php?property_id=' . $new_property_id);
                    exit;
                }
            }
        } catch (PDOException $e) {
            $msg = '임대물 저장 중 오류가 발생했습니다: ' . $e->getMessage();
            $msg_type = 'error';
        }
    }
    
    // 폼에 값 유지 (오류 발생 시 또는 중복 체크 실패 시)
    if (isset($address)) {
        $property = [
            'address' => $address ?? '',
            'detail_address' => $detail_address ?? '',
            'category' => $category ?? PROPERTY_CATEGORY[0],
            'description' => $description ?? ''
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_edit_mode ? '임대물 수정' : '임대물 등록'; ?> - <?php echo SITE_TITLE; ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .edit-container { max-width: 500px; margin: 2.5rem auto; background: #fff; border-radius: 14px; box-shadow: 0 2px 12px rgba(0,100,255,0.07); padding: 2.2rem 1.5rem; }
        .edit-title { font-size: 1.3rem; font-weight: 700; color: #1976d2; margin-bottom: 1.5rem; text-align: center; }
        .edit-form-group { margin-bottom: 1.4rem; }
        .edit-label { display: block; font-size: 1.05rem; font-weight: 600; color: #333; margin-bottom: 0.5rem; }
        .edit-input, .edit-textarea { width: 100%; padding: 0.8rem; border: 1.5px solid #e3eaf2; border-radius: 8px; font-size: 1rem; box-sizing: border-box; }
        .edit-input:focus, .edit-textarea:focus { outline: none; border-color: #0064FF; }
        .edit-textarea { min-height: 90px; resize: vertical; }
        .edit-btn { background: #0064FF; color: #fff; border: none; border-radius: 8px; padding: 0.9rem 2.2rem; font-size: 1.1rem; font-weight: 700; cursor: pointer; width: 100%; margin-top: 0.5rem; transition: background 0.15s; }
        .edit-btn:hover { background: #0052cc; }
        .edit-msg { text-align: center; margin-bottom: 1.2rem; font-size: 1.05rem; border-radius: 8px; padding: 0.7rem; }
        .edit-msg.success { background: #e3fcec; color: #197d4c; border: 1px solid #b2f2d7; }
        .edit-msg.error { background: #fff0f0; color: #d32f2f; border: 1px solid #ffcdd2; }
    </style>
</head>
<body>
<?php include 'header.inc'; ?>
<div class="edit-container">
    <div class="edit-title"><?php echo $is_edit_mode ? '임대물 정보 수정' : '임대물 신규 등록'; ?></div>
    <?php if ($msg): ?>
        <div class="edit-msg <?php echo $msg_type; ?>"><?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>
    <form method="post" autocomplete="off">
        <div class="edit-form-group">
            <label class="edit-label">부동산 유형 <span style="color:#d32f2f;">*</span></label>
            <select name="category" class="edit-input" required>
                <?php foreach (PROPERTY_CATEGORY as $cat): ?>
                    <option value="<?php echo htmlspecialchars($cat); ?>" <?php if (($property['category'] ?? PROPERTY_CATEGORY[0]) === $cat) echo 'selected'; ?>><?php echo htmlspecialchars($cat); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="edit-form-group">
            <label class="edit-label">주소 <span style="color:#d32f2f;">*</span></label>
            <div style="display:flex; gap:0.5rem;">
                <input type="text" id="address" name="address" class="edit-input" required value="<?php echo htmlspecialchars($property['address'] ?? ''); ?>" style="flex:1;" readonly>
                <button type="button" onclick="execDaumPostcode()" class="edit-btn" style="width:auto; padding:0 1.2rem; font-size:1rem; background:#1976d2;">주소 검색</button>
            </div>
        </div>
        <div class="edit-form-group">
            <label class="edit-label">상세주소</label>
            <input type="text" name="detail_address" class="edit-input" value="<?php echo htmlspecialchars($property['detail_address'] ?? ''); ?>">
        </div>
        <div class="edit-form-group">
            <label class="edit-label">설명</label>
            <textarea name="description" class="edit-textarea" placeholder="이 물건에 본인만의 메모할 사항이 있으면 입력하세요."><?php echo htmlspecialchars($property['description'] ?? ''); ?></textarea>
        </div>
        <div style="display:flex; gap:0.7rem;">
            <button type="submit" class="edit-btn"><?php echo $is_edit_mode ? '수정 완료' : '등록 완료'; ?></button>
            <a href="properties.php" class="edit-btn" style="background:#bbb; color:#222; text-align:center; text-decoration:none;">취소</a>
        </div>
    </form>
</div>
<?php include 'footer.inc'; ?>
<script src="https://t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js"></script>
<script>
function execDaumPostcode() {
    new daum.Postcode({
        oncomplete: function(data) {
            document.getElementById('address').value = data.roadAddress || data.jibunAddress || '';
        }
    }).open();
}
</script>
</body>
</html> 