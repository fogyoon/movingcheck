<?php
require_once 'sql.inc';

// 로그인 확인 - 로그인되지 않은 경우 login.php로 리다이렉트
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// 성공 메시지 확인
$success_msg = '';
if (isset($_SESSION['success_msg'])) {
    $success_msg = $_SESSION['success_msg'];
    unset($_SESSION['success_msg']); // 한 번 표시 후 삭제
}
$search = safe_string($_GET['search'] ?? '', 100);
$pdo = get_pdo();
if ($search) {
    $stmt = $pdo->prepare("SELECT p.*, MAX(c.end_date) AS latest_contract_date FROM properties p LEFT JOIN contracts c ON p.id = c.property_id WHERE p.created_by = ? AND (p.address LIKE ? OR p.detail_address LIKE ? OR p.description LIKE ?) GROUP BY p.id ORDER BY latest_contract_date DESC, p.created_at DESC");
    $like = safe_like_pattern($search);
    $stmt->execute([$user_id, $like, $like, $like]);
} else {
    $stmt = $pdo->prepare("SELECT p.*, MAX(c.end_date) AS latest_contract_date FROM properties p LEFT JOIN contracts c ON p.id = c.property_id WHERE p.created_by = ? GROUP BY p.id ORDER BY latest_contract_date DESC, p.created_at DESC");
    $stmt->execute([$user_id]);
}
$properties = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 계약 건수 조회 (property_id => count)
$contract_counts = []; // 진행중인 계약만
$total_contract_counts = []; // 전체 계약 (종료된 것 포함)

if ($properties) {
    $ids = array_column($properties, 'id');
    $in = str_repeat('?,', count($ids)-1) . '?';
    
    // 진행중인 계약 건수
    $cstmt = $pdo->prepare("SELECT property_id, COUNT(*) as cnt FROM contracts WHERE property_id IN ($in) AND status != 'finished' GROUP BY property_id");
    $cstmt->execute($ids);
    foreach ($cstmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $contract_counts[$row['property_id']] = $row['cnt'];
    }
    
    // 전체 계약 건수 (종료된 것 포함)
    $total_cstmt = $pdo->prepare("SELECT property_id, COUNT(*) as cnt FROM contracts WHERE property_id IN ($in) GROUP BY property_id");
    $total_cstmt->execute($ids);
    foreach ($total_cstmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $total_contract_counts[$row['property_id']] = $row['cnt'];
    }
}

// 삭제 처리 (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_property_id'])) {
    $pid = safe_int($_POST['delete_property_id']);
    // 소유자 확인
    $stmt = $pdo->prepare("SELECT * FROM properties WHERE id = ? AND created_by = ?");
    $stmt->execute([$pid, $user_id]);
    $prop = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$prop) {
        echo json_encode(['result'=>'fail','msg'=>'권한이 없거나 이미 삭제된 임대물입니다.']);
        exit;
    }
    // 관련 계약 id 목록
    $cids = [];
    $cstmt = $pdo->prepare("SELECT id FROM contracts WHERE property_id = ?");
    $cstmt->execute([$pid]);
    foreach ($cstmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cids[] = $row['id'];
    }
    // 관련 사진 id 목록
    $photo_ids = [];
    if ($cids) {
        $in = str_repeat('?,', count($cids)-1) . '?';
        $pstmt = $pdo->prepare("SELECT id FROM photos WHERE contract_id IN ($in)");
        $pstmt->execute($cids);
        foreach ($pstmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $photo_ids[] = $row['id'];
        }
    }
    // 삭제 순서: photo_comparisons, damage_reports, signatures, photos, contracts, properties
    if ($cids) {
        $in = str_repeat('?,', count($cids)-1) . '?';
        $pdo->prepare("DELETE FROM photo_comparisons WHERE contract_id IN ($in)")->execute($cids);
        $pdo->prepare("DELETE FROM damage_reports WHERE contract_id IN ($in)")->execute($cids);
        $pdo->prepare("DELETE FROM signatures WHERE contract_id IN ($in)")->execute($cids);
    }
    if ($photo_ids) {
        $in = str_repeat('?,', count($photo_ids)-1) . '?';
        $pdo->prepare("DELETE FROM photo_comparisons WHERE before_photo IN ($in) OR after_photo IN ($in)")->execute($photo_ids);
        $pdo->prepare("DELETE FROM damage_reports WHERE photo_id IN ($in)")->execute($photo_ids);
        $pdo->prepare("DELETE FROM photos WHERE id IN ($in)")->execute($photo_ids);
    }
    if ($cids) {
        $in = str_repeat('?,', count($cids)-1) . '?';
        $pdo->prepare("DELETE FROM contracts WHERE id IN ($in)")->execute($cids);
    }
    // 임대물 삭제
    $pdo->prepare("DELETE FROM properties WHERE id = ?")->execute([$pid]);
    // 로그 기록
    log_user_activity($user_id, 'delete_property', '임대물(ID:'.$pid.') 및 관련 데이터 삭제', null);
    echo json_encode(['result'=>'ok']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>내 임대물 목록 - <?php echo SITE_TITLE; ?></title>
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
    
    /* 검색 폼 */
    .prop-search-form {
      display: flex;
      gap: 0.5rem;
      margin-bottom: 2rem;
    }
    .prop-search-input {
      flex: 1;
      padding: 0.7rem 1rem;
      border: 1px solid #ced4da;
      border-radius: 8px;
      font-size: 1rem;
    }
    .prop-search-input:focus {
      outline: none;
      border-color: #0064FF;
      box-shadow: 0 0 0 3px rgba(0,100,255,0.15);
    }
    .prop-search-btn {
      padding-left: 1.5rem;
      padding-right: 1.5rem;
    }

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
  </style>
</head>
<body style="background:#f8f9fa;">
<?php include 'header.inc'; ?>
<main class="prop-container">
  
  <?php if (isset($_GET['welcome']) && $_GET['welcome'] == '1'): ?>
    <div id="welcome-message" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; border-radius: 16px; padding: 2rem; margin-bottom: 2rem; text-align: center; position: relative; box-shadow: 0 8px 32px rgba(102, 126, 234, 0.3);">
      <div style="position: absolute; top: 10px; right: 15px; cursor: pointer; font-size: 1.5rem; opacity: 0.8;" onclick="closeWelcomeMessage()">×</div>
      <h2 style="margin: 0 0 0.5rem 0; font-size: 1.4rem; font-weight: 700;">🎉 무빙체크에 오신 것을 환영합니다!</h2>
      <p style="margin: 0 0 1rem 0; font-size: 1rem; opacity: 0.9;">프로필 설정이 완료되었습니다. 이제 무빙체크의 모든 기능을 편리하게 이용하실 수 있습니다.</p>
      <div style="display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap; margin-top: 1.5rem;">
        <div style="background: rgba(255,255,255,0.2); border-radius: 8px; padding: 0.8rem 1.2rem; font-size: 0.9rem;">
          ✨ 계약서 자동 입력
        </div>
        <div style="background: rgba(255,255,255,0.2); border-radius: 8px; padding: 0.8rem 1.2rem; font-size: 0.9rem;">
          📸 사진 기록 관리
        </div>
        <div style="background: rgba(255,255,255,0.2); border-radius: 8px; padding: 0.8rem 1.2rem; font-size: 0.9rem;">
          ✍️ 전자 서명
        </div>
      </div>
    </div>
    <script>
      function closeWelcomeMessage() {
        document.getElementById('welcome-message').style.display = 'none';
        // URL에서 welcome 파라미터 제거
        const url = new URL(window.location);
        url.searchParams.delete('welcome');
        window.history.replaceState({}, document.title, url.pathname + (url.search ? url.search : ''));
      }
      // 5초 후 자동으로 닫기
      setTimeout(closeWelcomeMessage, 5000);
    </script>
  <?php endif; ?>
  
  <div class="page-header">
    <h1 class="page-title">
      <img src="images/house-icon.svg" alt="집" style="width: 32px; height: 32px; margin-right: 12px; vertical-align: middle;">내 임대물 목록
    </h1>
    <a href="properties_edit.php" class="btn btn-primary">
      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
        <path d="M8 4a.5.5 0 0 1 .5.5v3h3a.5.5 0 0 1 0 1h-3v3a.5.5 0 0 1-1 0v-3h-3a.5.5 0 0 1 0-1h3v-3A.5.5 0 0 1 8 4z"/>
      </svg>
      새 임대물 등록
    </a>
  </div>
  
  <?php if ($success_msg): ?>
    <div style="background: #e3fcec; color: #197d4c; border: 1px solid #b2f2d7; border-radius: 8px; padding: 1rem; margin-bottom: 2rem; text-align: center; font-size: 1.05rem; font-weight: 600;">
      <?php echo htmlspecialchars($success_msg); ?>
    </div>
  <?php endif; ?>
  
  <?php if (empty($properties) && !$search): ?>
    <div style="text-align:center; margin: 4rem 0; padding: 2rem; background: #fff; border-radius: 12px;">
      <h2 style="font-size:1.3rem; margin-bottom:0.5rem;">등록된 임대물이 없습니다.</h2>
      <p style="color:#6c757d; margin-bottom:1.5rem;">아래 버튼을 눌러 첫 임대물을 등록하고 시작해보세요.</p>
      <a href="properties_edit.php" class="btn btn-primary" style="padding: 0.8rem 1.5rem; font-size: 1.1rem;">임대물 등록하기</a>
    </div>
  <?php else: ?>
    <form class="prop-search-form" method="get" autocomplete="off">
      <input type="text" name="search" class="prop-search-input" placeholder="주소, 상세주소, 설명으로 검색..." value="<?php echo htmlspecialchars($search); ?>">
      <button type="submit" class="btn btn-primary prop-search-btn">검색</button>
      <?php if ($search): ?>
        <a href="properties.php" class="btn btn-light">초기화</a>
      <?php endif; ?>
    </form>

    <?php if (empty($properties) && $search): ?>
      <div style="text-align:center; margin: 4rem 0; padding: 2rem; background: #fff; border-radius: 12px;">
        <h2 style="font-size:1.3rem; margin-bottom:0.5rem;">'<?php echo htmlspecialchars($search); ?>'에 대한 검색 결과가 없습니다.</h2>
        <p style="color:#6c757d;">다른 검색어로 다시 시도해보세요.</p>
      </div>
    <?php else: ?>
      <!-- PC 테이블 -->
      <div class="prop-table-wrap">
        <table class="prop-table">
          <thead>
            <tr>
              <th>주소</th>
              <th>설명</th>
              <th style="text-align:center;">진행중인 계약</th>
              <th>등록일</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($properties as $p): ?>
              <tr>
                <td>
                  <div class="prop-address"><?php echo htmlspecialchars($p['address']); ?></div>
                  <small style="color:#6c757d;"><?php echo htmlspecialchars($p['detail_address']); ?></small>
                </td>
                <td style="font-size:0.95rem; color:#495057; max-width: 250px; white-space: normal; word-break: break-all;"><?php echo htmlspecialchars($p['description']); ?></td>
                <td style="text-align:center; font-weight:700; color:#0064FF;"><?php echo $contract_counts[$p['id']] ?? 0; ?></td>
                <td style="font-size:0.95em; color:#6c757d; white-space:nowrap;"><?php echo date('Y-m-d', strtotime($p['created_at'])); ?></td>
                <td>
                  <div class="prop-actions">
                    <a class="btn btn-light btn-icon" href="properties_edit.php?id=<?php echo $p['id']; ?>" title="수정">
                      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M12.854.146a.5.5 0 0 0-.707 0L10.5 1.793 14.207 5.5l1.647-1.646a.5.5 0 0 0 0-.708l-3-3zm.646 6.061L9.793 2.5 3.293 9H3.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.207l6.5-6.5zm-7.468 7.468A.5.5 0 0 1 6 13.5V13h-.5a.5.5 0 0 1-.5-.5V12h-.5a.5.5 0 0 1-.5-.5V11h-.5a.5.5 0 0 1-.5-.5V10h-.5a.499.499 0 0 1-.175-.032l-.179.178a.5.5 0 0 0-.11.168l-2 5a.5.5 0 0 0 .65.65l5-2a.5.5 0 0 0 .168-.11l.178-.178z"/></svg>
                    </a>
                    <button class="btn btn-light btn-icon" data-pid="<?php echo $p['id']; ?>" title="삭제">
                      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z"/><path fill-rule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3V2h11v1h-11z"/></svg>
                    </button>
                    <?php if (($total_contract_counts[$p['id']] ?? 0) > 0): ?>
                      <a class="btn btn-light" href="contracts.php?property_id=<?php echo $p['id']; ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1 2.5A1.5 1.5 0 0 1 2.5 1h3A1.5 1.5 0 0 1 7 2.5v3A1.5 1.5 0 0 1 5.5 7h-3A1.5 1.5 0 0 1 1 5.5v-3zM2.5 2a.5.5 0 0 0-.5.5v3a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 .5-.5v-3a.5.5 0 0 0-.5-.5h-3z"/><path d="M1 9.5A1.5 1.5 0 0 1 2.5 8h3A1.5 1.5 0 0 1 7 9.5v3A1.5 1.5 0 0 1 5.5 14h-3A1.5 1.5 0 0 1 1 12.5v-3zM2.5 9a.5.5 0 0 0-.5.5v3a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 .5-.5v-3a.5.5 0 0 0-.5-.5h-3z"/><path d="M9 2.5A1.5 1.5 0 0 1 10.5 1h3A1.5 1.5 0 0 1 15 2.5v3A1.5 1.5 0 0 1 13.5 7h-3A1.5 1.5 0 0 1 9 5.5v-3zM10.5 2a.5.5 0 0 0-.5.5v3a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 .5-.5v-3a.5.5 0 0 0-.5-.5h-3zm-1 7.5A1.5 1.5 0 0 1 10.5 8h3a1.5 1.5 0 0 1 1.5 1.5v3a1.5 1.5 0 0 1-1.5 1.5h-3A1.5 1.5 0 0 1 9 12.5v-3zm1.5-.5a.5.5 0 0 0-.5.5v3a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 .5-.5v-3a.5.5 0 0 0-.5-.5h-3z"/></svg>
                        계약 확인
                      </a>
                    <?php endif; ?>
                    <a class="btn btn-primary" href="contract_edit.php?property_id=<?php echo $p['id']; ?>">
                      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M8 4a.5.5 0 0 1 .5.5v3h3a.5.5 0 0 1 0 1h-3v3a.5.5 0 0 1-1 0v-3h-3a.5.5 0 0 1 0-1h3v-3A.5.5 0 0 1 8 4z"/></svg>
                      계약 등록
                    </a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <!-- 모바일 카드형 -->
      <div class="prop-card-list">
        <?php foreach ($properties as $p): ?>
          <div class="prop-card">
            <div class="prop-card-header">
              <div class="prop-card-address">
                <?php echo htmlspecialchars($p['address']); ?>
                <small><?php echo htmlspecialchars($p['detail_address']); ?></small>
              </div>
            </div>
            <div class="prop-card-body">
              <?php if ($p['description']): ?>
                <p class="desc"><?php echo nl2br(htmlspecialchars($p['description'])); ?></p>
              <?php endif; ?>
              <div class="prop-card-body-footer">
                <div class="prop-card-meta">
                  등록: <?php echo date('Y-m-d', strtotime($p['created_at'])); ?>
                </div>
                <div class="prop-actions">
                  <a class="btn btn-light btn-icon" href="properties_edit.php?id=<?php echo $p['id']; ?>" title="수정">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M12.854.146a.5.5 0 0 0-.707 0L10.5 1.793 14.207 5.5l1.647-1.646a.5.5 0 0 0 0-.708l-3-3zm.646 6.061L9.793 2.5 3.293 9H3.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.207l6.5-6.5zm-7.468 7.468A.5.5 0 0 1 6 13.5V13h-.5a.5.5 0 0 1-.5-.5V12h-.5a.5.5 0 0 1-.5-.5V11h-.5a.5.5 0 0 1-.5-.5V10h-.5a.499.499 0 0 1-.175-.032l-.179.178a.5.5 0 0 0-.11.168l-2 5a.5.5 0 0 0 .65.65l5-2a.5.5 0 0 0 .168-.11l.178-.178z"/></svg>
                  </a>
                  <button class="btn btn-light btn-icon" data-pid="<?php echo $p['id']; ?>" title="삭제">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z"/><path fill-rule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3V2h11v1h-11z"/></svg>
                  </button>
                </div>
              </div>
            </div>
            <div class="prop-card-footer">
              <div class="prop-card-meta">
                <strong>계약 <?php echo $contract_counts[$p['id']] ?? 0; ?>건</strong>
              </div>
              <div class="prop-actions">
                  <?php if (($total_contract_counts[$p['id']] ?? 0) > 0): ?>
                    <a class="btn btn-light" href="contracts.php?property_id=<?php echo $p['id']; ?>">
                      <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M1 2.5A1.5 1.5 0 0 1 2.5 1h3A1.5 1.5 0 0 1 7 2.5v3A1.5 1.5 0 0 1 5.5 7h-3A1.5 1.5 0 0 1 1 5.5v-3zM2.5 2a.5.5 0 0 0-.5.5v3a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 .5-.5v-3a.5.5 0 0 0-.5-.5h-3z"/><path d="M1 9.5A1.5 1.5 0 0 1 2.5 8h3A1.5 1.5 0 0 1 7 9.5v3A1.5 1.5 0 0 1 5.5 14h-3A1.5 1.5 0 0 1 1 12.5v-3zM2.5 9a.5.5 0 0 0-.5.5v3a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 .5-.5v-3a.5.5 0 0 0-.5-.5h-3z"/><path d="M9 2.5A1.5 1.5 0 0 1 10.5 1h3A1.5 1.5 0 0 1 15 2.5v3A1.5 1.5 0 0 1 13.5 7h-3A1.5 1.5 0 0 1 9 5.5v-3zM10.5 2a.5.5 0 0 0-.5.5v3a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 .5-.5v-3a.5.5 0 0 0-.5-.5h-3zm-1 7.5A1.5 1.5 0 0 1 10.5 8h3a1.5 1.5 0 0 1 1.5 1.5v3a1.5 1.5 0 0 1-1.5 1.5h-3A1.5 1.5 0 0 1 9 12.5v-3zm1.5-.5a.5.5 0 0 0-.5.5v3a.5.5 0 0 0 .5.5h3a.5.5 0 0 0 .5-.5v-3a.5.5 0 0 0-.5-.5h-3z"/></svg>
                      계약 확인
                    </a>
                  <?php endif; ?>
                  <a class="btn btn-primary" href="contract_edit.php?property_id=<?php echo $p['id']; ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M8 4a.5.5 0 0 1 .5.5v3h3a.5.5 0 0 1 0 1h-3v3a.5.5 0 0 1-1 0v-3h-3a.5.5 0 0 1 0-1h3v-3A.5.5 0 0 1 8 4z"/></svg>
                    계약 등록
                  </a>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</main>
<div id="delete-modal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.4); align-items:center; justify-content:center; display:flex; opacity:0; visibility:hidden; transition: opacity 0.2s, visibility 0.2s;">
  <div style="background:#fff; border-radius:14px; box-shadow:0 4px 24px rgba(0,0,0,0.13); padding:2rem 1.5rem; max-width:90vw; width:360px; text-align:center; transform: scale(0.95); transition: transform 0.2s;">
    <h3 style="font-size:1.2rem; font-weight:700; color:#d32f2f; margin-top:0; margin-bottom:0.5rem;">임대물을 삭제하시겠습니까?</h3>
    <p style="font-size:1rem; color:#555; margin-bottom:1.5rem;">삭제하면 복구가 불가하며,<br>해당 임대물의 모든 계약/사진도 함께 삭제됩니다.</p>
    <div style="display:flex; gap:1rem; justify-content:center;">
      <button id="modal-cancel" class="btn btn-light" style="flex:1;">취소</button>
      <button id="modal-ok" class="btn btn-primary" style="flex:1; background-color:#d32f2f; border-color:#d32f2f;">삭제 확인</button>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const deleteModal = document.getElementById('delete-modal');
    const modalCancel = document.getElementById('modal-cancel');
    const modalOk = document.getElementById('modal-ok');
    let propertyIdToDelete = null;

    document.querySelectorAll('.prop-actions button[data-pid]').forEach(button => {
        button.addEventListener('click', function () {
            propertyIdToDelete = this.dataset.pid;
            deleteModal.style.opacity = '1';
            deleteModal.style.visibility = 'visible';
            deleteModal.querySelector('div').style.transform = 'scale(1)';
        });
    });

    function closeModal() {
      deleteModal.style.opacity = '0';
      deleteModal.style.visibility = 'hidden';
      deleteModal.querySelector('div').style.transform = 'scale(0.95)';
    }

    modalCancel.addEventListener('click', closeModal);
    deleteModal.addEventListener('click', function(e) {
      if (e.target === deleteModal) {
        closeModal();
      }
    });

    modalOk.addEventListener('click', function () {
        if (!propertyIdToDelete) return;
        const formData = new FormData();
        formData.append('delete_property_id', propertyIdToDelete);
        fetch('properties.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.result === 'ok') {
                location.reload();
            } else {
                alert(data.msg || '삭제 중 오류가 발생했습니다.');
                closeModal();
            }
        })
        .catch(err => {
            alert('삭제 중 오류가 발생했습니다.');
            console.error(err);
            closeModal();
        });
    });
});
</script>

<?php include 'footer.inc'; ?>
</body>
</html> 