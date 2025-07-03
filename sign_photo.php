<?php
require_once 'sql.inc';
if (!isset($_SESSION['user_id'])) {
  header('Location: login.php');
  exit;
}
$pdo = get_pdo();
$user_id = $_SESSION['user_id'];
$contract_id = (int)($_GET['contract_id'] ?? 0);
if (!$contract_id) {
  die('잘못된 접근입니다.');
}
$contract_id = (int)($_GET['contract_id'] ?? 0);
if (!$contract_id) die('잘못된 접근입니다.');

$pdo = get_pdo();
$stmt = $pdo->prepare('SELECT c.*, p.address, p.detail_address FROM contracts c JOIN properties p ON c.property_id = p.id WHERE c.id = ?');
$stmt->execute([$contract_id]);
$contract = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$contract) die('계약을 찾을 수 없습니다.');
$success = isset($_GET['success']);

// 서명 저장 처리 (HTML 출력 전에!)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sign_image'])) {
    $signature_data = $_POST['sign_image'];
    if (strpos($signature_data, 'data:image/png;base64,') === 0) {
        // 현재 사용자의 계약에서의 역할 확인
        $signer_role = 'tenant'; // 기본값
        if (isset($_SESSION['user_id']) && $contract) {
            $current_user_id = $_SESSION['user_id'];
            if ($contract['landlord_id'] == $current_user_id) {
                $signer_role = 'landlord';
            } elseif ($contract['tenant_id'] == $current_user_id) {
                $signer_role = 'tenant';
            } elseif ($contract['agent_id'] == $current_user_id) {
                $signer_role = 'agent';
            }
        }
        
        $status = $contract['status'];
        if (strpos($status, 'movein') !== false) $purpose = 'movein';
        elseif (strpos($status, 'moveout') !== false) $purpose = 'moveout';
        else $purpose = 'movein';
        
        // 서명자 정보 가져오기
        $signer_name = $_SESSION['nickname'] ?? '';
        $signer_phone = '';
        if ($_SESSION['user_id']) {
            $user_stmt = $pdo->prepare("SELECT phone FROM users WHERE id = ?");
            $user_stmt->execute([$_SESSION['user_id']]);
            $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) $signer_phone = $user['phone'] ?? '';
        }
        
        // 서명 데이터를 base64로 DB에 저장
        $stmt = $pdo->prepare("INSERT INTO signatures (contract_id, signer_role, purpose, signer_name, signer_phone, signature_data, signer_ip) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $contract_id,
            $signer_role,
            $purpose,
            $signer_name,
            $signer_phone,
            $signature_data,
            $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
        
        // 임대인이 입주 사진에 서명한 경우 contract status 업데이트
        if ($signer_role === 'landlord' && $purpose === 'movein') {
            $update_stmt = $pdo->prepare("UPDATE contracts SET status = 'movein_landlord_signed' WHERE id = ?");
            $update_stmt->execute([$contract_id]);
        }
        
        // 활동 로그 기록
        log_user_activity($_SESSION['user_id'], 'create_signature', $signer_role . ' 서명 생성 (' . $purpose . ', 계약 ID: ' . $contract_id . ')', $contract_id);
        
        header('Location: sign_photo.php?contract_id=' . $contract_id . '&success=1');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>전자 서명 - 무빙체크</title>
  <link rel="stylesheet" href="style.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@400;700;900&display=swap" rel="stylesheet">
  <style>
    .sign-container { max-width: 420px; margin: 2.5rem auto; background: #fff; border-radius: 14px; box-shadow: 0 4px 18px rgba(0,100,255,0.07); padding: 2.2rem 1.5rem; text-align:center; }
    .sign-title { font-size: 1.3rem; font-weight: 700; color: #1976d2; margin-bottom: 1.2rem; }
    .sign-address { color:#555; font-size:1.05rem; margin-bottom:1.5rem; }
    .sign-pad-wrap { background:#f4f8ff; border:2px dashed #b3c6e0; border-radius:12px; padding:1.2rem 0.7rem; margin-bottom:1.2rem; }
    .sign-pad { background:#fff; border:1.5px solid #e3eaf2; border-radius:8px; width:100%; height:180px; touch-action: none; -webkit-user-select: none; -moz-user-select: none; -ms-user-select: none; user-select: none; }
    .sign-btns { display:flex; gap:0.7rem; margin-top:1.2rem; }
    .sign-btn { flex:1; background:#0064FF; color:#fff; border:none; border-radius:8px; padding:0.9rem 0; font-size:1.1rem; font-weight:700; cursor:pointer; transition:background 0.15s; }
    .sign-btn.clear { background:#bbb; color:#222; }
    .sign-btn:active { background:#0052cc; }
    .sign-success { background:#e3fcec; color:#197d4c; border:1px solid #b2f2d7; border-radius:8px; padding:0.8rem; margin-bottom:1.2rem; font-size:1.05rem; }
  </style>
</head>
<body style="background:#f8f9fa;">
<?php include 'header.inc'; ?>
<main>
  <div class="sign-container">
    <div class="sign-title">전자 서명</div>
    <div class="sign-address">
      <?php echo htmlspecialchars($contract['address']); ?><?php if (!empty($contract['detail_address'])): ?>, <?php echo htmlspecialchars($contract['detail_address']); ?><?php endif; ?>
    </div>
    <?php if ($success): ?>
      <div class="sign-success">서명이 정상적으로 저장되었습니다.</div>
      <a href="photo_list.php?contract_id=<?php echo $contract_id; ?>" class="sign-btn" style="display:block;">사진 목록으로 돌아가기</a>
    <?php else: ?>
      <div style="font-size:1.07rem; color:#1976d2; font-weight:500; margin-bottom:1.1rem;">등록된 사진을 충분히 검토하였기에 서명합니다.</div>
      <div class="sign-pad-wrap">
        <canvas id="signPad" class="sign-pad"></canvas>
      </div>
      <form id="signForm" method="post" enctype="multipart/form-data" style="margin:0;">
        <input type="hidden" name="sign_image" id="signImage">
        <div class="sign-btns">
          <button type="button" class="sign-btn clear" id="clearBtn">지우기</button>
          <button type="submit" class="sign-btn">서명 저장</button>
          <button type="button" class="sign-btn clear" id="cancelBtn" style="background:#eee; color:#1976d2;">취소</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</main>
<?php include 'footer.inc'; ?>
<script>
// 서명 패드 구현
const canvas = document.getElementById('signPad');
if (canvas) {
  const ctx = canvas.getContext('2d');
  let drawing = false, lastX = 0, lastY = 0;
  function resizeCanvas() {
    const dpr = window.devicePixelRatio || 1;
    canvas.width = canvas.offsetWidth * dpr;
    canvas.height = canvas.offsetHeight * dpr;
    ctx.setTransform(1,0,0,1,0,0);
    ctx.scale(dpr, dpr);
    ctx.clearRect(0,0,canvas.width,canvas.height);
  }
  window.addEventListener('resize', resizeCanvas);
  resizeCanvas();
  function startDraw(e) {
    e.preventDefault();
    e.stopPropagation();
    drawing = true;
    [lastX, lastY] = getXY(e);
    
    // 서명 중 페이지 스크롤 방지
    document.body.style.overflow = 'hidden';
  }
  function endDraw() { 
    drawing = false; 
    
    // 서명 완료 후 페이지 스크롤 복원
    document.body.style.overflow = '';
  }
  function draw(e) {
    if (!drawing) return;
    e.preventDefault();
    e.stopPropagation();
    
    const [x, y] = getXY(e);
    ctx.lineWidth = 2.5;
    ctx.lineCap = 'round';
    ctx.strokeStyle = '#1976d2';
    ctx.beginPath();
    ctx.moveTo(lastX, lastY);
    ctx.lineTo(x, y);
    ctx.stroke();
    [lastX, lastY] = [x, y];
  }
  function getXY(e) {
    const rect = canvas.getBoundingClientRect();
    const dpr = window.devicePixelRatio || 1;
    
    // 실제 canvas 크기와 표시되는 크기의 비율을 고려
    const scaleX = (canvas.width / dpr) / rect.width;
    const scaleY = (canvas.height / dpr) / rect.height;
    
    if (e.touches && e.touches.length) {
      return [
        (e.touches[0].clientX - rect.left) * scaleX,
        (e.touches[0].clientY - rect.top) * scaleY
      ];
    } else {
      return [
        (e.clientX - rect.left) * scaleX,
        (e.clientY - rect.top) * scaleY
      ];
    }
  }
  canvas.addEventListener('mousedown', startDraw);
  canvas.addEventListener('touchstart', startDraw, { passive: false });
  canvas.addEventListener('mousemove', draw);
  canvas.addEventListener('touchmove', draw, { passive: false });
  canvas.addEventListener('mouseup', endDraw);
  canvas.addEventListener('mouseleave', endDraw);
  canvas.addEventListener('touchend', endDraw, { passive: false });
  document.getElementById('clearBtn').onclick = function() {
    ctx.clearRect(0,0,canvas.width,canvas.height);
  };
  document.getElementById('signForm').onsubmit = function(e) {
    e.preventDefault();
    document.getElementById('signImage').value = canvas.toDataURL('image/png');
    this.submit();
  };
  document.getElementById('cancelBtn')?.addEventListener('click', function() {
    window.location.href = 'photo_list.php?contract_id=<?php echo $contract_id; ?>';
  });
}
</script>
</body>
</html>
