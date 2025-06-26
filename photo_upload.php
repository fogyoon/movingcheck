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
// 계약 정보
$stmt = $pdo->prepare('SELECT c.*, p.address FROM contracts c JOIN properties p ON c.property_id = p.id WHERE c.id = ?');
$stmt->execute([$contract_id]);
$contract = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$contract) die('계약을 찾을 수 없습니다.');
// purpose 자동 결정(입주/퇴거/수리)
$status = $contract['status'];
if (strpos($status, 'movein') !== false) $purpose = 'movein';
elseif (strpos($status, 'moveout') !== false) $purpose = 'moveout';
elseif (strpos($status, 'repair') !== false) $purpose = 'repair';
else $purpose = 'other';

$msg = '';
$msg_type = '';
// 사진 등록 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $part = trim($_POST['part'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $overview_file = $_FILES['overview'] ?? null;
    $closeup_files = $_FILES['closeup'] ?? null;
    $movein_file_paths = [];
    $movein_shot_types = [];
    $movein_meta_datas = [];
    $moveout_file_paths = [];
    $moveout_shot_types = [];
    $moveout_meta_datas = [];
    // 사진 등록 불가 상태 체크
    if ($status === 'finished') {
        $msg = '계약이 완료된 상태에서는 사진을 등록할 수 없습니다.';
        $msg_type = 'error';
    } else {
        // 업로드 목적 결정 (입주/퇴거)
        $is_movein = ($status === 'empty' || $status === 'movein_photo' || $status === 'movein_landlord_signed');
        $is_moveout = ($status === 'movein_tenant_signed' || $status === 'moveout_photo' || $status === 'moveout_landlord_signed');
        // overview(위치확인) 1장
        if ($overview_file && $overview_file['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($overview_file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png'];
            if (in_array($ext, $allowed)) {
                $fname = sprintf('photo_%d_%s_%s.%s', $contract_id, uniqid(), date('YmdHis'), $ext);
                $fpath = 'photos/' . $fname;
                move_uploaded_file($overview_file['tmp_name'], $fpath);
                if ($is_movein) {
                    $movein_file_paths[] = $fpath;
                    $movein_shot_types[] = 'overview';
                    $movein_meta_datas[] = '';
                } elseif ($is_moveout) {
                    $moveout_file_paths[] = $fpath;
                    $moveout_shot_types[] = 'overview';
                    $moveout_meta_datas[] = '';
                }
            }
        }
        // closeup(세부) 1~6장
        if ($closeup_files && isset($closeup_files['name']) && is_array($closeup_files['name'])) {
            for ($i=0; $i<6; $i++) {
                if (!empty($closeup_files['name'][$i]) && $closeup_files['error'][$i] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($closeup_files['name'][$i], PATHINFO_EXTENSION));
                    $allowed = ['jpg','jpeg','png'];
                    if (in_array($ext, $allowed)) {
                        $fname = sprintf('photo_%d_%s_%s_%d.%s', $contract_id, uniqid(), date('YmdHis'), $i+1, $ext);
                        $fpath = 'photos/' . $fname;
                        move_uploaded_file($closeup_files['tmp_name'][$i], $fpath);
                        if ($is_movein) {
                            $movein_file_paths[] = $fpath;
                            $movein_shot_types[] = 'closeup';
                            $movein_meta_datas[] = '';
                        } elseif ($is_moveout) {
                            $moveout_file_paths[] = $fpath;
                            $moveout_shot_types[] = 'closeup';
                            $moveout_meta_datas[] = '';
                        }
                    }
                }
            }
        }
        // DB 저장 전에 동일 부위(위치) 중복 체크
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM photos WHERE contract_id = ? AND part = ?');
        $stmt->execute([$contract_id, $part]);
        if ($stmt->fetchColumn() > 0) {
            $msg = '이미 동일한 부위(위치)로 등록된 사진이 있습니다.';
            $msg_type = 'error';
        } else if (($is_movein && $movein_file_paths) || ($is_moveout && $moveout_file_paths)) {
            $sql = "INSERT INTO photos (contract_id, uploader_id, part, description
                , movein_shot_type_01, movein_file_path_01, movein_meta_data_01
                , movein_shot_type_02, movein_file_path_02, movein_meta_data_02
                , movein_shot_type_03, movein_file_path_03, movein_meta_data_03
                , movein_shot_type_04, movein_file_path_04, movein_meta_data_04
                , movein_shot_type_05, movein_file_path_05, movein_meta_data_05
                , movein_shot_type_06, movein_file_path_06, movein_meta_data_06
                , moveout_shot_type_01, moveout_file_path_01, moveout_meta_data_01
                , moveout_shot_type_02, moveout_file_path_02, moveout_meta_data_02
                , moveout_shot_type_03, moveout_file_path_03, moveout_meta_data_03
                , moveout_shot_type_04, moveout_file_path_04, moveout_meta_data_04
                , moveout_shot_type_05, moveout_file_path_05, moveout_meta_data_05
                , moveout_shot_type_06, moveout_file_path_06, moveout_meta_data_06
                , taken_at, uploader_ip)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $params = [
                $contract_id, $user_id, $part, $description
            ];
            // movein 6개
            for ($i=0; $i<6; $i++) {
                $params[] = $movein_shot_types[$i] ?? null;
                $params[] = $movein_file_paths[$i] ?? null;
                $params[] = $movein_meta_datas[$i] ?? null;
            }
            // moveout 6개
            for ($i=0; $i<6; $i++) {
                $params[] = $moveout_shot_types[$i] ?? null;
                $params[] = $moveout_file_paths[$i] ?? null;
                $params[] = $moveout_meta_datas[$i] ?? null;
            }
            $params[] = date('Y-m-d H:i:s');
            $params[] = $_SERVER['REMOTE_ADDR'] ?? '';

            // 디버깅: 파라미터 값 화면에 출력
            echo '<pre style="background:#222;color:#fff;padding:1em;z-index:9999;position:relative;">';
            echo "PARAMS:\n";
            print_r($params);
            echo '</pre>';

            $stmt = $pdo->prepare($sql);
            if ($stmt->execute($params)) {
                // 사진 등록 성공 후 status 변경 및 signatures 삭제
                if ($status === 'empty') {
                    $pdo->prepare('UPDATE contracts SET status = ? WHERE id = ?')->execute(['movein_photo', $contract_id]);
                } elseif ($status === 'movein_landlord_signed') {
                    $pdo->prepare('UPDATE contracts SET status = ? WHERE id = ?')->execute(['movein_photo', $contract_id]);
                    $pdo->prepare('DELETE FROM signatures WHERE contract_id = ? AND purpose = ? AND signer_role = ?')->execute([$contract_id, 'movein', 'landlord']);
                } elseif ($status === 'movein_tenant_signed') {
                    $pdo->prepare('UPDATE contracts SET status = ? WHERE id = ?')->execute(['moveout_photo', $contract_id]);
                } elseif ($status === 'moveout_landlord_signed') {
                    $pdo->prepare('UPDATE contracts SET status = ? WHERE id = ?')->execute(['moveout_photo', $contract_id]);
                    $pdo->prepare('DELETE FROM signatures WHERE contract_id = ? AND purpose = ? AND signer_role = ?')->execute([$contract_id, 'moveout', 'landlord']);
                }
                // 나머지는 상태 유지
                header('Location: photo_list.php?contract_id=' . $contract_id);
                exit;
            }
            $msg = '사진이 등록되었습니다!';
            $msg_type = 'success';
        } else {
            $msg = '사진을 1장 이상 업로드 해주세요.';
            $msg_type = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>사진 등록 - <?php echo htmlspecialchars($contract['address']); ?> - 무빙체크</title>
  <link rel="stylesheet" href="style.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@400;700;900&display=swap" rel="stylesheet">
  <style>
    .edit-container, .photo-upload-form { max-width: 600px; margin: 2rem auto; background: #fff; border-radius: 14px; box-shadow: 0 4px 18px rgba(0,100,255,0.07); padding: 2.2rem 1.5rem; }
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
    .photo-upload-form h2 { font-size: 1.3rem; font-weight: 700; margin-bottom: 1.2rem; }
    .photo-upload-form label, .photo-upload-form input, .photo-upload-form textarea {
      display: block;
      width: 100%;
      text-align: left;
      margin-left: 0;
      margin-right: 0;
      box-sizing: border-box;
    }
    .photo-upload-form label {
      margin-bottom: 0.6rem;
      margin-top: 1.2rem;
    }
    .photo-upload-form input,
    .photo-upload-form textarea {
      margin-bottom: 1.1rem;
    }
    .photo-upload-form .btn { margin-top: 1.2rem; }
    .photo-upload-form .desc { color: #888; font-size: 0.97em; margin-bottom: 0.7rem; }
    .file-upload-area { border: 2px dashed #b3c6e0; border-radius: 14px; background: #f4f8ff; min-height: 140px; display: flex; flex-direction: column; align-items: center; justify-content: center; cursor: pointer; margin-bottom: 1rem; position: relative; transition: border-color 0.2s; padding: 1.5rem 1rem; }
    .file-upload-area.dragover { border-color: #1976d2; background: #e3f0ff; }
    .file-upload-icon { font-size: 2.5rem; margin-bottom: 0.5rem; color: #1976d2; }
    .file-upload-text { font-size: 1.1rem; color: #1976d2; margin-bottom: 0.3rem; }
    .file-upload-hint { color: #888; font-size: 0.97em; margin-bottom: 0.5rem; }
    .thumb-list { display: flex; flex-wrap: wrap; gap: 0.7rem; margin-top: 0.5rem; }
    .thumb-item { position: relative; width: 90px; height: 90px; border-radius: 10px; overflow: hidden; background: #e9ecef; display: flex; align-items: center; justify-content: center; }
    .thumb-item img { width: 100%; height: 100%; object-fit: cover; }
    .thumb-remove { position: absolute; top: 2px; right: 2px; background: rgba(0,0,0,0.5); color: #fff; border: none; border-radius: 50%; width: 22px; height: 22px; font-size: 1.1rem; cursor: pointer; display: flex; align-items: center; justify-content: center; z-index: 2; }
    .thumb-placeholder { width: 90px; height: 90px; border-radius: 10px; background: #f4f8ff; display: flex; align-items: center; justify-content: center; color: #b3c6e0; font-size: 2.2rem; border: 1.5px dashed #b3c6e0; }
    .photo-list { margin: 2rem auto; max-width: 700px; }
    .photo-list h3 { font-size: 1.1rem; margin-bottom: 1rem; }
    .photo-list-item { background: #f8f9fa; border-radius: 10px; padding: 1rem; margin-bottom: 1.2rem; display: flex; gap: 1.2rem; align-items: flex-start; }
    .photo-list-item img { width: 120px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,100,255,0.06); }
    .photo-list-item .photo-info { flex: 1; }
    .photo-list-item .photo-info .part { font-weight: 700; color: #1976d2; }
    .photo-list-item .photo-info .desc { color: #555; margin: 0.3rem 0 0.7rem 0; }
    @media (max-width:600px) { .photo-upload-form, .photo-list { padding: 1rem; } .photo-list-item { flex-direction: column; align-items: stretch; } .photo-list-item img { width: 100%; max-width: 95vw; } .thumb-list { gap: 0.4rem; } .thumb-item, .thumb-placeholder { width: 70px; height: 70px; font-size: 1.5rem; } }
  </style>
</head>
<body>
<?php include 'header.inc'; ?>
<main>
  <div class="photo-upload-form">
    <h2>사진 등록</h2>
    <?php if ($msg): ?>
      <div class="edit-msg <?php echo $msg_type; ?>">■ <?php echo $msg; ?></div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" id="photoForm">
      <div class="edit-form-group">
        <label class="edit-label">부위(위치) <span style="color:#d32f2f;">*</span></label>
        <input type="text" name="part" class="edit-input" required placeholder="예: 거실 벽, 욕실 세면대 등">
      </div>
      <div class="edit-form-group">
        <label class="edit-label">설명</label>
        <textarea name="description" class="edit-textarea" rows="2" placeholder="특이사항, 상태 등"></textarea>
      </div>
      <div class="desc">위치확인용(전체) 사진 1장, 세부(근접) 사진 1~6장 업로드</div>
      <div class="edit-form-group">
        <label class="edit-label">위치확인용(전체) 사진 <span style="color:#d32f2f;">*</span></label>
        <div class="file-upload-area" id="overviewUploadArea" style="margin-bottom:1rem;">
          <div class="file-upload-text" id="overviewUploadText">사진을 선택하거나 촬영하세요</div>
          <div class="file-upload-hint">JPG, PNG 파일 지원 (최대 1장, 5MB)</div>
          <input type="file" name="overview" id="overviewInput" accept="image/*" style="display:none;">
          <div class="thumb-list" id="overviewThumbList">
            <div class="thumb-placeholder" id="overviewPlaceholder">+</div>
          </div>
        </div>
      </div>
      <div class="edit-form-group">
        <label class="edit-label">세부(근접) 사진 (최대 6장) <span style="color:#d32f2f;">*</span></label>
        <div class="file-upload-area" id="closeupUploadArea">
          <div class="file-upload-text" id="closeupUploadText">사진을 선택하거나 촬영하세요 (여러장 가능)</div>
          <div class="file-upload-hint">JPG, PNG 파일 지원 (최대 6장, 각 5MB)</div>
          <input type="file" name="closeup[]" id="closeupInput" accept="image/*" multiple style="display:none;">
          <div class="thumb-list" id="closeupThumbList">
            <div class="thumb-placeholder" id="closeupPlaceholder">+</div>
          </div>
        </div>
      </div>
      <div style="display:flex; gap:0.7rem; margin-top:1.2rem;">
        <button type="submit" class="edit-btn" style="flex:1;">사진 등록</button>
        <a href="photo_list.php?contract_id=<?php echo $contract_id; ?>" class="edit-btn" style="background:#bbb; color:#222; text-align:center; text-decoration:none; flex:1;">취소</a>
      </div>
    </form>
  </div>
  <!-- 사진 목록은 photo_list.php에서 확인하세요. -->
</main>
<?php if ($msg_type === 'error' && $msg): ?>
<script>alert('<?php echo addslashes($msg); ?>');</script>
<?php endif; ?>
<script>
// overview 업로드 영역
const overviewArea = document.getElementById('overviewUploadArea');
const overviewInput = document.getElementById('overviewInput');
const overviewText = document.getElementById('overviewUploadText');
const overviewThumbList = document.getElementById('overviewThumbList');
const overviewPlaceholder = document.getElementById('overviewPlaceholder');

function renderOverviewThumb() {
  overviewThumbList.innerHTML = '';
  if (overviewInput.files && overviewInput.files.length > 0) {
    const file = overviewInput.files[0];
    const reader = new FileReader();
    const thumbItem = document.createElement('div');
    thumbItem.className = 'thumb-item';
    const img = document.createElement('img');
    thumbItem.appendChild(img);
    const removeBtn = document.createElement('button');
    removeBtn.className = 'thumb-remove';
    removeBtn.type = 'button';
    removeBtn.innerHTML = '&times;';
    removeBtn.onclick = function() {
      overviewInput.value = '';
      renderOverviewThumb();
      overviewText.textContent = '사진을 선택하거나 촬영하세요';
    };
    thumbItem.appendChild(removeBtn);
    reader.onload = e => { img.src = e.target.result; };
    reader.readAsDataURL(file);
    overviewThumbList.appendChild(thumbItem);
  } else {
    const placeholder = document.createElement('div');
    placeholder.className = 'thumb-placeholder';
    placeholder.id = 'overviewPlaceholder';
    placeholder.textContent = '+';
    overviewThumbList.appendChild(placeholder);
  }
}
overviewArea.addEventListener('click', () => overviewInput.click());
overviewInput.addEventListener('change', function() {
  renderOverviewThumb();
  if (this.files.length > 0) {
    overviewText.textContent = this.files[0].name;
  } else {
    overviewText.textContent = '사진을 선택하거나 촬영하세요';
  }
});
overviewArea.addEventListener('dragover', e => { e.preventDefault(); overviewArea.classList.add('dragover'); });
overviewArea.addEventListener('dragleave', () => overviewArea.classList.remove('dragover'));
overviewArea.addEventListener('drop', e => {
  e.preventDefault(); overviewArea.classList.remove('dragover');
  if (e.dataTransfer.files.length > 0) {
    overviewInput.files = e.dataTransfer.files;
    renderOverviewThumb();
    overviewText.textContent = e.dataTransfer.files[0].name;
  }
});
renderOverviewThumb();

// closeup 업로드 영역 (누적 업로드 지원)
const closeupArea = document.getElementById('closeupUploadArea');
const closeupInput = document.getElementById('closeupInput');
const closeupText = document.getElementById('closeupUploadText');
const closeupThumbList = document.getElementById('closeupThumbList');
const closeupPlaceholder = document.getElementById('closeupPlaceholder');
let closeupFiles = [];

function updateCloseupInputFromArray() {
  const dt = new DataTransfer();
  closeupFiles.forEach(f => dt.items.add(f));
  closeupInput.files = dt.files;
}
function renderCloseupThumbs() {
  closeupThumbList.innerHTML = '';
  if (closeupFiles.length > 0) {
    closeupFiles.forEach((file, i) => {
      const reader = new FileReader();
      const thumbItem = document.createElement('div');
      thumbItem.className = 'thumb-item';
      const img = document.createElement('img');
      thumbItem.appendChild(img);
      const removeBtn = document.createElement('button');
      removeBtn.className = 'thumb-remove';
      removeBtn.type = 'button';
      removeBtn.innerHTML = '&times;';
      removeBtn.onclick = function() {
        closeupFiles.splice(i, 1);
        updateCloseupInputFromArray();
        renderCloseupThumbs();
        if (closeupFiles.length === 0) closeupText.textContent = '사진을 선택하거나 촬영하세요 (여러장 가능)';
      };
      thumbItem.appendChild(removeBtn);
      reader.onload = e => { img.src = e.target.result; };
      reader.readAsDataURL(file);
      closeupThumbList.appendChild(thumbItem);
    });
  } else {
    const placeholder = document.createElement('div');
    placeholder.className = 'thumb-placeholder';
    placeholder.id = 'closeupPlaceholder';
    placeholder.textContent = '+';
    closeupThumbList.appendChild(placeholder);
  }
}
closeupArea.addEventListener('click', () => closeupInput.click());
closeupInput.addEventListener('change', function(e) {
  // 누적: 기존 closeupFiles + 새로 선택한 파일
  if (this.files.length > 0) {
    let newFiles = Array.from(this.files);
    // 최대 6장 제한
    let total = closeupFiles.length + newFiles.length;
    if (closeupFiles.length >= 6) {
      alert('6개까지만 등록이 가능합니다.');
      this.value = '';
      return;
    }
    if (total > 6) {
      newFiles = newFiles.slice(0, 6 - closeupFiles.length);
      alert('6개까지만 등록이 가능합니다.');
    }
    closeupFiles = closeupFiles.concat(newFiles);
    if (closeupFiles.length > 6) closeupFiles = closeupFiles.slice(0, 6);
    updateCloseupInputFromArray();
    renderCloseupThumbs();
    let names = closeupFiles.map(f => f.name);
    closeupText.textContent = names.join(', ');
  }
  // input을 리셋해서 같은 파일도 다시 선택 가능하게
  this.value = '';
});
closeupArea.addEventListener('dragover', e => { e.preventDefault(); closeupArea.classList.add('dragover'); });
closeupArea.addEventListener('dragleave', () => closeupArea.classList.remove('dragover'));
closeupArea.addEventListener('drop', e => {
  e.preventDefault(); closeupArea.classList.remove('dragover');
  let newFiles = Array.from(e.dataTransfer.files);
  let total = closeupFiles.length + newFiles.length;
  if (closeupFiles.length >= 6) {
    alert('6개까지만 등록이 가능합니다.');
    return;
  }
  if (total > 6) {
    newFiles = newFiles.slice(0, 6 - closeupFiles.length);
    alert('6개까지만 등록이 가능합니다.');
  }
  closeupFiles = closeupFiles.concat(newFiles);
  if (closeupFiles.length > 6) closeupFiles = closeupFiles.slice(0, 6);
  updateCloseupInputFromArray();
  renderCloseupThumbs();
  let names = closeupFiles.map(f => f.name);
  closeupText.textContent = names.join(', ');
});
renderCloseupThumbs();
// 폼 제출 시 closeupFiles를 input.files에 반영
const photoForm = document.getElementById('photoForm');
photoForm.addEventListener('submit', function(e) {
  updateCloseupInputFromArray();
  // overview 파일 선택 여부 검사
  if (!overviewInput.files || overviewInput.files.length === 0) {
    alert('위치확인용(전체) 사진을 1장 등록해야 합니다.');
    e.preventDefault();
    return false;
  }
  // closeupFiles가 1개 이상인지 검사
  if (closeupFiles.length === 0) {
    alert('세부(근접) 사진을 1장 이상 등록해야 합니다.');
    e.preventDefault();
    return false;
  }
});
</script>
<?php include 'footer.inc'; ?>
</body>
</html> 