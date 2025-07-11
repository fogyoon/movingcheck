<?php
require_once 'sql.inc';

// 로그인 체크
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$contract_id = (int)($_GET['contract_id'] ?? 0);
$photo_id = (int)($_GET['photo_id'] ?? 0);

if (!$contract_id || !$photo_id) {
    die('잘못된 접근입니다.');
}

$pdo = get_pdo();

// 계약 정보 조회
$stmt = $pdo->prepare('SELECT c.*, p.address, p.detail_address FROM contracts c JOIN properties p ON c.property_id = p.id WHERE c.id = ?');
$stmt->execute([$contract_id]);
$contract = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$contract) die('계약을 찾을 수 없습니다.');

// 사진 정보 조회
$stmt = $pdo->prepare('SELECT * FROM photos WHERE id = ? AND contract_id = ?');
$stmt->execute([$photo_id, $contract_id]);
$photo = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$photo) die('사진을 찾을 수 없습니다.');

// 입주 사진들을 타입별로 분류
$movein_overview = [];
$movein_closeup = [];

// 퇴거 사진들을 타입별로 분류
$moveout_overview = [];
$moveout_closeup = [];

for ($i = 1; $i <= 6; $i++) {
    $index_str = str_pad($i, 2, '0', STR_PAD_LEFT);
    
    // 입주 사진 처리
    $movein_file_path = $photo['movein_file_path_' . $index_str] ?? null;
    $movein_shot_type = $photo['movein_shot_type_' . $index_str] ?? null;
    $movein_meta_data = $photo['movein_meta_data_' . $index_str] ?? null;
    
    if ($movein_file_path) {
        $photo_data = [
            'path' => $movein_file_path,
            'meta' => $movein_meta_data,
            'index' => $i
        ];
        
        if ($movein_shot_type === 'overview') {
            $movein_overview[] = $photo_data;
        } elseif ($movein_shot_type === 'closeup') {
            $movein_closeup[] = $photo_data;
        }
    }
    
    // 퇴거 사진 처리
    $moveout_file_path = $photo['moveout_file_path_' . $index_str] ?? null;
    $moveout_shot_type = $photo['moveout_shot_type_' . $index_str] ?? null;
    $moveout_meta_data = $photo['moveout_meta_data_' . $index_str] ?? null;
    
    if ($moveout_file_path) {
        $photo_data = [
            'path' => $moveout_file_path,
            'meta' => $moveout_meta_data,
            'index' => $i
        ];
        
        if ($moveout_shot_type === 'overview') {
            $moveout_overview[] = $photo_data;
        } elseif ($moveout_shot_type === 'closeup') {
            $moveout_closeup[] = $photo_data;
        }
    }
}

$status = $contract['status'];

// moveout_tenant_signed 상태에서는 퇴거 사진 촬영 불가
if ($status === 'moveout_tenant_signed') {
    echo '<script>alert("임차인 서명이 완료된 상태에서는 퇴거 사진을 수정할 수 없습니다."); history.back();</script>';
    exit;
}

// 퇴거 사진 촬영이 가능한 상태 확인
if (!in_array($status, ['movein_tenant_signed', 'moveout_photo', 'moveout_landlord_signed'])) {
    echo '<script>alert("퇴거 사진 촬영이 불가능한 상태입니다."); history.back();</script>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="mobile-web-app-capable" content="yes">
    <title>파손사진 촬영 - <?php echo htmlspecialchars($contract['address']); ?> - 무빙체크</title>
    <link rel="stylesheet" href="style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        .moveout-container {
            max-width: 1200px;
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
        
        .photo-section {
            background: #fff;
            border-radius: 12px;
            padding: 0.8rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 18px rgba(0,100,255,0.05);
            border: 1px solid #e3eaf2;
        }
        
        .section-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e9ecef;
        }
        
        .overview-title {
            color: #1976d2;
            border-bottom-color: #1976d2;
        }
        
        .closeup-title {
            color: #666;
            border-bottom-color: #666;
        }
        
        .photo-item {
            margin-bottom: 2rem;
            padding: 1rem;
            border: 1px solid #e9ecef;
            border-radius: 8px;
        }
        

        
        .photo-item.preview-mode {
            display: flex;
            gap: 1.5rem;
            align-items: flex-start;
        }
        
        .photo-item.comparison-mode {
            display: flex;
            gap: 1.5rem;
            align-items: flex-start;
            flex-wrap: wrap;
        }
        
        .movein-photo {
            flex: 1;
            max-width: 400px;
        }
        
        .moveout-photo {
            flex: 1;
            max-width: 400px;
        }
        
        .moveout-photo img {
            width: 100%;
            height: auto;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border: 2px solid #28a745;
        }
        
        /* 반응형 디자인 */
        @media (max-width: 768px) {
            .photo-item.comparison-mode {
                flex-direction: column;
            }
            
            .movein-photo,
            .moveout-photo {
                max-width: 100%;
            }
            

        }
        
        .movein-photo img {
            width: 100%;
            height: auto;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .overview-photo img {
            border: 2px solid #1976d2;
        }
        
        .photo-meta {
            font-size: 0.9rem;
            color: #666;
            margin-top: 0.5rem;
            padding: 0.5rem;
            background: #f8f9fa;
            border-radius: 4px;
        }
        
        .button-section {
            flex: 1;
            min-width: 200px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
        }
        
        .button-section .description {
            text-align: center;
            padding: 1rem;
            color: #666;
            margin-bottom: 0.5rem;
            width: 100%;
        }
        
        .button-section .button-container {
            text-align: center;
            width: 100%;
        }
        
        .photo-header {
            text-align: center;
            margin-bottom: 1rem;
        }
        
        .photo-header h3 {
            margin: 0;
            color: #333;
            font-size: 1.1rem;
        }
        
        /* 슬라이더 스타일 */
        input[type="range"] {
            -webkit-appearance: none !important;
            appearance: none !important;
            height: 8px !important;
            background: #ddd !important;
            outline: none !important;
            border-radius: 4px !important;
            cursor: pointer !important;
        }
        
        /* 웹킷 기반 브라우저용 슬라이더 thumb */
        input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 24px;
            height: 24px;
            background: #0064FF;
            border-radius: 50%;
            cursor: pointer;
            box-shadow: 0 2px 6px rgba(0, 100, 255, 0.3);
            transition: all 0.2s ease;
        }
        
        input[type="range"]::-webkit-slider-thumb:hover {
            background: #0052cc;
            box-shadow: 0 4px 12px rgba(0, 100, 255, 0.4);
            transform: scale(1.1);
        }
        
        /* Firefox용 슬라이더 thumb */
        input[type="range"]::-moz-range-thumb {
            width: 24px;
            height: 24px;
            background: #0064FF;
            border-radius: 50%;
            cursor: pointer;
            border: none;
            box-shadow: 0 2px 6px rgba(0, 100, 255, 0.3);
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.75rem 1.5rem;
            font-size: 1rem;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
            gap: 0.5rem;
        }
        
        .btn-primary {
            background: #0064FF;
            color: white;
        }
        
        .btn-primary:hover {
            background: #0052cc;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background: #218838;
        }
        
        .btn-secondary {
            color: #6c757d;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
        }
        
        .btn-secondary:hover {
            background-color: #e9ecef;
        }
        
        .btn-warning {
            background: #ffc107;
            color: #212529;
        }
        
        .btn-warning:hover {
            background: #e0a800;
        }
        


        


        
        @media (max-width: 768px) {
            .photo-item.preview-mode {
                flex-direction: column;
            }
            
            .photo-header h3 {
                font-size: 1rem;
            }
            
            .button-section .description {
                padding: 0.5rem;
            }
        }
    </style>
</head>
<body style="background:#f8f9fa;">
<?php include 'header.inc'; ?>

<main class="moveout-container">
    <div class="page-header">
        <h1 class="page-title">
            <img src="images/camera-icon.svg" alt="카메라" style="width: 32px; height: 32px; margin-right: 12px; vertical-align: middle;">
            파손사진 촬영
        </h1>
        <div style="display:flex; justify-content:flex-end; align-items:center; max-width:900px; margin-bottom:0.5rem; margin-left:auto; margin-right:0;">
            <a href="photo_list.php?contract_id=<?php echo $contract_id; ?>" class="btn btn-secondary">
                ← 돌아가기
            </a>
        </div>
    </div>
    
    <div style="font-size: 1.1rem; font-weight: 600; color: #333; margin-bottom: 1.5rem;">
        <?php echo htmlspecialchars($contract['address']); ?>
        <?php if (!empty($contract['detail_address'])): ?>
            , <?php echo htmlspecialchars($contract['detail_address']); ?>
        <?php endif; ?>
    </div>
    
    <div style="margin-bottom: 1.5rem;">
        <div style="font-size: 1.3rem; font-weight: 700; color: #1976d2; margin-bottom: 0.5rem;">
            📍 <?php echo htmlspecialchars($photo['part']); ?>
        </div>
        <?php if (!empty($photo['description'])): ?>
            <div style="font-size: 1rem; color: #666; line-height: 1.4;">
                <?php echo nl2br(htmlspecialchars($photo['description'])); ?>
            </div>
        <?php endif; ?>
    </div>


    <?php if (!empty($movein_overview)): ?>
    <div class="photo-section">
        <h2 class="section-title overview-title">위치확인용 사진</h2>
        <?php foreach ($movein_overview as $overview): ?>
            <?php 
            // 해당 인덱스의 moveout 사진이 있는지 확인
            $moveout_photo = null;
            foreach ($moveout_overview as $moveout) {
                if ($moveout['index'] === $overview['index']) {
                    $moveout_photo = $moveout;
                    break;
                }
            }
            ?>
            
            <?php if ($moveout_photo): ?>
            <!-- moveout 사진이 있는 경우: 비교 보기 -->
            <div class="photo-item comparison-mode overview-photo" id="photo-item-overview-<?php echo $overview['index']; ?>">
                <div class="movein-photo">
                    <h4 style="margin-bottom: 0.5rem; color: #1976d2;">입주 시</h4>
                    <img src="<?php echo htmlspecialchars($overview['path']); ?>" alt="입주 위치확인 사진">
                </div>
                <div class="moveout-photo">
                    <h4 style="margin-bottom: 0.5rem; color: #28a745;">퇴거 시 (촬영 완료)</h4>
                    <img src="<?php echo htmlspecialchars($moveout_photo['path']); ?>" alt="퇴거 위치확인 사진">
                </div>
                <!--
                <div class="camera-controls" style="width: 100%; text-align: center; margin-top: 1rem;">
                    <button class="btn btn-warning" onclick="startCameraMode('overview', <?php echo $overview['index']; ?>, '<?php echo htmlspecialchars($overview['path']); ?>')">
                        🔄 다시 촬영
                    </button>
                </div>
                -->
            </div>
            <?php else: ?>
            <!-- moveout 사진이 없는 경우: 위치확인용 사진은 퇴거 시 촬영 불필요 -->
            <div class="photo-item preview-mode overview-photo" id="photo-item-overview-<?php echo $overview['index']; ?>">
                <div class="movein-photo">
                    <img src="<?php echo htmlspecialchars($overview['path']); ?>" alt="입주 위치확인 사진">
                </div>
                <div class="button-section">
                    <div class="description">
                        단지 위치를 확인하기 위한 사진입니다.<br>퇴거사진 촬영은 아래 세부 사진에서 가능합니다.
                    </div>
                </div>
            </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($movein_closeup)): ?>
    <div class="photo-section">
        <h2 class="section-title closeup-title">세부 사진</h2>
        <?php foreach ($movein_closeup as $closeup): ?>
            <?php 
            // 해당 인덱스의 moveout 사진이 있는지 확인
            $moveout_photo = null;
            foreach ($moveout_closeup as $moveout) {
                if ($moveout['index'] === $closeup['index']) {
                    $moveout_photo = $moveout;
                    break;
                }
            }
            ?>
            
            <?php if ($moveout_photo): ?>
            <!-- moveout 사진이 있는 경우: 비교 보기 -->
            <div class="photo-item comparison-mode" id="photo-item-closeup-<?php echo $closeup['index']; ?>">
                <div class="movein-photo">
                    <h4 style="margin-bottom: 0.5rem; color: #1976d2;">입주 시</h4>
                    <img src="<?php echo htmlspecialchars($closeup['path']); ?>" alt="입주 세부 사진">
                </div>
                <div class="moveout-photo">
                    <h4 style="margin-bottom: 0.5rem; color: #28a745;">퇴거 시 (촬영 완료)</h4>
                    <img src="<?php echo htmlspecialchars($moveout_photo['path']); ?>" alt="퇴거 세부 사진">
                </div>
                <div style="width: 100%; text-align: center; margin-top: 1rem;">
                    <a href="camera_capture.php?contract_id=<?php echo $contract_id; ?>&photo_id=<?php echo $photo_id; ?>&type=closeup&index=<?php echo $closeup['index']; ?>" class="btn btn-warning">
                        🔄 다시 촬영
                    </a>
                </div>
            </div>
            <?php else: ?>
            <!-- moveout 사진이 없는 경우: 촬영 모드 -->
            <div class="photo-item preview-mode" id="photo-item-closeup-<?php echo $closeup['index']; ?>">
                            <div class="movein-photo">
                <img src="<?php echo htmlspecialchars($closeup['path']); ?>" alt="입주 세부 사진">
            </div>
                <div class="button-section">
                    <div class="description">
                        버튼을 눌러 비교 사진 촬영
                    </div>
                    <div class="button-container">
                        <a href="camera_capture.php?contract_id=<?php echo $contract_id; ?>&photo_id=<?php echo $photo_id; ?>&type=closeup&index=<?php echo $closeup['index']; ?>" class="btn btn-primary">
                            📷 촬영 시작
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</main>

<script>
// 페이지 로드 시 촬영한 사진 위치로 스크롤
document.addEventListener('DOMContentLoaded', function() {
    // URL에서 촬영한 사진 정보 확인
    const urlParams = new URLSearchParams(window.location.search);
    const capturedType = urlParams.get('captured_type');
    const capturedIndex = urlParams.get('captured_index');
    const scrollType = urlParams.get('scroll_type');
    const scrollIndex = urlParams.get('scroll_index');
    
    // 저장 완료 후 촬영한 사진으로 스크롤
    if (capturedType && capturedIndex) {
        setTimeout(function() {
            const targetElement = document.getElementById(`photo-item-${capturedType}-${capturedIndex}`);
            
            if (targetElement) {
                targetElement.scrollIntoView({ 
                    behavior: 'smooth', 
                    block: 'center' 
                });
                
                // 하이라이트 효과 추가 (저장 완료 - 초록색)
                targetElement.style.transition = 'box-shadow 0.3s ease';
                targetElement.style.boxShadow = '0 0 20px rgba(40, 167, 69, 0.5)';
                
                // 3초 후 하이라이트 제거
                setTimeout(function() {
                    targetElement.style.boxShadow = '';
                }, 3000);
            }
        }, 1000);
    }
    
    // 취소 후 촬영하려던 사진으로 스크롤
    if (scrollType && scrollIndex) {
        console.log('스크롤 파라미터:', scrollType, scrollIndex);
        setTimeout(function() {
            const targetElement = document.getElementById(`photo-item-${scrollType}-${scrollIndex}`);
            console.log('찾은 요소:', targetElement);
            
            if (targetElement) {
                targetElement.scrollIntoView({ 
                    behavior: 'smooth', 
                    block: 'center' 
                });
                
                // 하이라이트 효과 추가 (취소 - 파란색)
                targetElement.style.transition = 'box-shadow 0.3s ease';
                targetElement.style.boxShadow = '0 0 20px rgba(25, 118, 210, 0.5)';
                
                // 3초 후 하이라이트 제거
                setTimeout(function() {
                    targetElement.style.boxShadow = '';
                }, 3000);
            } else {
                console.log('요소를 찾을 수 없음:', `photo-item-${scrollType}-${scrollIndex}`);
                // 페이지의 모든 photo-item ID들을 로그로 출력
                const allPhotoItems = document.querySelectorAll('[id^="photo-item-"]');
                console.log('페이지의 모든 photo-item들:');
                allPhotoItems.forEach(item => {
                    console.log(item.id);
                });
            }
        }, 1000);
    }
});
</script>

<?php include 'footer.inc'; ?>
</body>
</html> 