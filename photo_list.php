<?php
require_once 'sql.inc';
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

// contracts.php와 동일한 status_info 배열
$status_info = [
    'empty' => [ 'label' => '사진 등록 필요', 'phase' => '입주', 'progress' => 0 ],
    'movein_photo' => [ 'label' => '서명 필요', 'phase' => '입주', 'progress' => 25 ],
    'movein_landlord_signed' => [ 'label' => '임대인 서명 완료', 'phase' => '입주', 'progress' => 50 ],
    'movein_tenant_signed' => [ 'label' => '임차인 서명 완료', 'phase' => '입주', 'progress' => 75 ],
    'moveout_photo' => [ 'label' => '파손 발생', 'phase' => '퇴거', 'progress' => 100 ],
    'moveout_landlord_signed' => [ 'label' => '임차인 파손 인정', 'phase' => '수리중', 'progress' => 125 ],
    'moveout_tenant_signed' => [ 'label' => '수리중', 'phase' => '수리중', 'progress' => 150 ],
    'in_repair' => [ 'label' => '수리중', 'phase' => '수리중', 'progress' => 175 ],
    'finished' => [ 'label' => '계약 완료', 'phase' => '완료', 'progress' => 200 ],
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
$user_role = $_SESSION['role'] ?? '';
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
    <a href="contracts.php?property_id=<?php echo urlencode($contract['property_id']); ?>"  class="btn btn-secondary">
    ← 돌아가기
    </a>
  </div>


  </div>

  <div class="address-title-wrap" style="max-width:900px; margin:0 auto 0.5rem auto; display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:1rem;">
    <div class="address-title-text" style="font-size:1.18rem; font-weight:600; color:#222;">
      <?php echo htmlspecialchars($contract['address']); ?><?php if (!empty($contract['detail_address'])): ?>, <?php echo htmlspecialchars($contract['detail_address']); ?><?php endif; ?>
    </div>
    <div class="address-title-btns">
      <?php
      if ($status === 'empty') {
        echo '<a href="photo_upload.php?contract_id=' . $contract_id . '" class="btn btn-primary">입주사진 등록</a>';
      } elseif ($status === 'movein_photo') {
        echo '<a href="photo_upload.php?contract_id=' . $contract_id . '" class="btn btn-primary">입주사진 등록</a> ';
        if ($user_role === 'agent') {
          echo '<button class="btn btn-warning">임대인에게 사진 전송</button>';
        } else {
          echo '<button class="btn btn-success" onclick="handleSign()">서명하기</button>';
        }
      } elseif ($status === 'movein_landlord_signed') {
        echo '<button class="btn btn-warning">임차인에게 사진 전송</button>';
      } elseif ($status === 'moveout_photo') {
        if ($user_role === 'agent') {
          echo '<button class="btn btn-warning">임대인에게 파손사진 전송</button>';
        } else {
          echo '<button class="btn btn-success" onclick="handleSign()">퇴거 사진에 서명하기</button>';
        }
      } elseif ($status === 'moveout_landlord_signed') {
        echo '<button class="btn btn-warning">임차인에게 파손사진 전송</button>';
      } elseif ($status === 'moveout_tenant_signed' || $status === 'in_repair') {
        echo '<button class="btn btn-secondary">종료</button>';
      }
      ?>
    </div>
  </div>

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
            // 입주 사진 출력
            for ($i=0; $i<6; $i++) {
              $fp = $p['movein_file_path_0'. $i] ?? null;
              if ($fp) {
                echo '<img src="'.htmlspecialchars($fp).'" alt="입주사진" style="width:'.($i==0?'90':'60').'px; border-radius:8px;'.($i>0?' margin-left:4px; margin-top:4px;':'').' cursor:pointer;" class="photo-thumb" data-photo-id="'.$p['id'].'">';
              }
            }
            // 퇴거 사진 출력
            for ($i=0; $i<6; $i++) {
              $fp = $p['moveout_file_path_0'. $i] ?? null;
              if ($fp) {
                echo '<img src="'.htmlspecialchars($fp).'" alt="퇴거사진" style="width:'.($i==0?'90':'60').'px; border-radius:8px;'.($i>0?' margin-left:4px; margin-top:4px;':'').' cursor:pointer;" class="photo-thumb" data-photo-id="'.$p['id'].'">';
              }
            }
            ?>
          </td>
          <td>
            <?php
            if ($status === 'movein_photo' || $status === 'movein_landlord_signed') {
              echo '<button class="btn btn-danger">삭제</button> <button class="btn btn-primary">사진 다시 등록</button>';
            } elseif ($status === 'movein_tenant_signed') {
              echo '<button class="btn btn-primary">파손사진 등록</button>';
            } elseif ($status === 'moveout_photo' || $status === 'moveout_landlord_signed') {
              $is_damage = $p['is_damage'] ?? false;
              if ($is_damage) {
                echo '<button class="btn btn-primary">파손사진 수정</button>';
              } else {
                echo '<button class="btn btn-primary">파손사진 등록</button>';
              }
            } elseif ($status === 'moveout_tenant_signed' || $status === 'in_repair') {
              $is_damage = $p['is_damage'] ?? false;
              if ($is_damage) {
                echo '<button class="btn btn-success">수리업체에 사진 전송</button>';
              }
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
            for ($i=0; $i<6; $i++) {
              $fp = $p['movein_file_path_0'. $i] ?? null;
              if ($fp) {
                echo '<img src="'.htmlspecialchars($fp).'" alt="입주사진" style="width:'.($i==0?'90':'60').'px; border-radius:8px; cursor:pointer;" class="photo-thumb" data-photo-id="'.$p['id'].'">';
              }
            }
            for ($i=0; $i<6; $i++) {
              $fp = $p['moveout_file_path_0'. $i] ?? null;
              if ($fp) {
                echo '<img src="'.htmlspecialchars($fp).'" alt="퇴거사진" style="width:'.($i==0?'90':'60').'px; border-radius:8px; cursor:pointer;" class="photo-thumb" data-photo-id="'.$p['id'].'">';
              }
            }
            ?>
          </div>
          <div style="margin-top:0.7rem;">
            <?php
            if ($status === 'movein_photo' || $status === 'movein_landlord_signed') {
              echo '<button class="btn btn-danger">삭제</button> <button class="btn btn-primary">사진 다시 등록</button>';
            } elseif ($status === 'movein_tenant_signed') {
              echo '<button class="btn btn-primary">파손사진 등록</button>';
            } elseif ($status === 'moveout_photo' || $status === 'moveout_landlord_signed') {
              $is_damage = $p['is_damage'] ?? false;
              if ($is_damage) {
                echo '<button class="btn btn-primary">파손사진 수정</button>';
              } else {
                echo '<button class="btn btn-primary">파손사진 등록</button>';
              }
            } elseif ($status === 'moveout_tenant_signed' || $status === 'in_repair') {
              $is_damage = $p['is_damage'] ?? false;
              if ($is_damage) {
                echo '<button class="btn btn-success">수리업체에 사진 전송</button>';
              }
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
    <div style="text-align:center; margin:2.5rem 0 1.5rem 0;">
      <?php
      if ($status === 'empty') {
        echo '<a href="photo_upload.php?contract_id=' . $contract_id . '" class="btn btn-primary">입주사진 등록</a>';
      } elseif ($status === 'movein_photo') {
        echo '<a href="photo_upload.php?contract_id=' . $contract_id . '" class="btn btn-primary">입주사진 등록</a> ';
        if ($user_role === 'agent') {
          echo '<button class="btn btn-warning">임대인에게 사진 전송</button>';
        } else {
          echo '<button class="btn btn-success" onclick="handleSign()">서명하기</button>';
        }
      } elseif ($status === 'movein_landlord_signed') {
        echo '<button class="btn btn-warning">임차인에게 사진 전송</button>';
      } elseif ($status === 'moveout_photo') {
        if ($user_role === 'agent') {
          echo '<button class="btn btn-warning">임대인에게 파손사진 전송</button>';
        } else {
          echo '<button class="btn btn-success" onclick="handleSign()">퇴거 사진에 서명하기</button>';
        }
      } elseif ($status === 'moveout_landlord_signed') {
        echo '<button class="btn btn-warning">임차인에게 파손사진 전송</button>';
      } elseif ($status === 'moveout_tenant_signed' || $status === 'in_repair') {
        echo '<button class="btn btn-secondary">종료</button>';
      }
      ?>
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
<script>
function handleSign() {
  // 모바일/태블릿 기기 판별 (iPad, Android tablet 포함)
  var isMobileOrTablet = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini|Tablet|PlayBook|Silk|Kindle|Nexus 7|Nexus 10|KFAPWI|SM-T|SCH-I800|GT-P|GT-N|SHW-M180S|SGH-T849|SHW-M180L|SHW-M180K/i.test(navigator.userAgent) || (navigator.userAgent.includes('Macintosh') && 'ontouchend' in document);
  if (isMobileOrTablet) {
    window.location.href = 'sign_photo.php?contract_id=<?php echo $contract_id; ?>';
  } else {
    alert('전자 서명은 모바일/태블릿 기기에서만 가능합니다.');
  }
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
    for (let i = 0; i < 6; i++) {
      const key1 = 'movein_file_path_0' + i;
      if (p[key1]) {
        const img = document.createElement('img');
        img.src = p[key1];
        img.style.maxWidth = '420px';
        img.style.maxHeight = '70vh';
        img.style.borderRadius = '12px';
        img.style.boxShadow = '0 2px 12px rgba(0,0,0,0.13)';
        img.style.background = '#f8f9fa';
        img.style.margin = '0 auto';
        modalImages.appendChild(img);
      }
      const key2 = 'moveout_file_path_0' + i;
      if (p[key2]) {
        const img = document.createElement('img');
        img.src = p[key2];
        img.style.maxWidth = '420px';
        img.style.maxHeight = '70vh';
        img.style.borderRadius = '12px';
        img.style.boxShadow = '0 2px 12px rgba(0,0,0,0.13)';
        img.style.background = '#f8f9fa';
        img.style.margin = '0 auto';
        modalImages.appendChild(img);
      }
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
</script>
</body>
</html> 