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
            padding: 1.5rem;
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
        
        .photo-item.camera-mode {
            display: flex;
            flex-direction: column;
            align-items: center;
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
            
            .camera-controls {
                width: 100% !important;
                margin-top: 1rem !important;
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
        
        .camera-section {
            flex: 1;
            min-width: 300px;
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
        
        /* 카메라 관련 스타일 */
        .camera-container {
            position: relative;
            width: 100%;
            max-width: 400px;
            margin: 0 auto;
        }
        
        .camera-video {
            width: 100%;
            height: auto;
            border-radius: 8px;
            background: #000;
        }
        
        .overlay-container {
            position: relative;
            width: 100%;
            max-width: 400px;
            margin: 0 auto;
            border-radius: 8px;
            overflow: hidden;
            background: #000;
            /* aspect-ratio는 JavaScript에서 동적으로 설정 */
        }
        
        .overlay-video {
            width: 100%;
            height: 100%;
            display: block;
            border-radius: 8px;
            object-fit: contain;
            background: #000;
            transition: transform 0.3s ease;
        }
        
        /* 비디오 표시 스타일 */
        .overlay-video {
            transform-origin: center center;
        }
        

        
        .overlay-image {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            border-radius: 8px;
            opacity: 0.5;
            pointer-events: none;
            object-fit: cover;
            z-index: 10;
        }
        
        /* 비율별 컨테이너 초기 크기 (JavaScript에서 실제 비디오 비율로 덮어씀) */
        .overlay-container.portrait {
            aspect-ratio: 3/4;
            max-width: 300px;
        }
        
        .overlay-container.landscape {
            aspect-ratio: 4/3;
            max-width: 400px;
        }
        
        .overlay-container.square {
            aspect-ratio: 1/1;
            max-width: 350px;
        }
        
        .overlay-container.custom-ratio {
            /* JavaScript에서 동적으로 설정 */
        }
        
        /* 비디오 표시 모드는 JavaScript에서 동적으로 조정 */
        .overlay-video {
            object-fit: contain; /* 기본값, JavaScript에서 필요시 cover로 변경 */
        }
        
        .camera-controls {
            text-align: center;
            margin-top: 1rem;
        }
        
        .camera-controls .btn {
            margin: 0.25rem;
        }
        
        .captured-photo {
            max-width: 100%;
            height: auto;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-top: 1rem;
        }
        
        .comparison-container {
            display: none;
            margin-top: 1rem;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .comparison-photos {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .comparison-item {
            text-align: center;
            flex: 1;
            min-width: 200px;
            max-width: 300px;
        }
        
        .comparison-item h4 {
            margin-bottom: 0.5rem;
            font-size: 1rem;
            color: #333;
        }
        
        .comparison-item img {
            width: 100%;
            height: auto;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        @media (max-width: 768px) {
            .photo-item.preview-mode {
                flex-direction: column;
            }
            
            .comparison-photos {
                flex-direction: column !important;
                gap: 1.5rem !important;
            }
            
            .comparison-item {
                max-width: 100% !important;
            }
            
            .camera-container {
                max-width: 100%;
            }
            
            .photo-header h3 {
                font-size: 1rem;
            }
            
            /* 모바일에서 슬라이더 터치 영역 확장 */
            input[type="range"]::-webkit-slider-thumb {
                width: 32px !important;
                height: 32px !important;
            }
            
            input[type="range"]::-moz-range-thumb {
                width: 32px !important;
                height: 32px !important;
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

    <!-- iOS 사용자 안내 -->
    <div id="ios-notice" style="display: none; background: #e3f2fd; border: 1px solid #1976d2; border-radius: 8px; padding: 1rem; margin-bottom: 1.5rem;">
        <h4 style="color: #1976d2; margin-bottom: 0.5rem;">📱 iOS 사용자 안내</h4>
        <p style="margin: 0; font-size: 0.9rem; line-height: 1.4;">
            카메라 사용을 위해 다음 설정을 확인해주세요:<br>
            <strong>설정 → Safari → 카메라 → 허용</strong><br>
            또는 <strong>설정 → 개인정보 보호 → 카메라 → Safari 허용</strong>
        </p>
    </div>

    <script>
        // iOS 안내는 권한 거부 시에만 표시 (초기에는 숨김)
        
        // HTTPS 확인
        if (location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
            const notice = document.getElementById('ios-notice');
            notice.style.display = 'block';
            notice.style.background = '#fff3cd';
            notice.style.borderColor = '#ffc107';
            notice.innerHTML = `
                <h4 style="color: #856404; margin-bottom: 0.5rem;">⚠️ 보안 연결 필요</h4>
                <p style="margin: 0; font-size: 0.9rem; line-height: 1.4; color: #856404;">
                    카메라 사용을 위해 HTTPS 연결이 필요합니다.<br>
                    주소창에서 <strong>https://</strong>로 시작하는지 확인해주세요.<br>
                    <small>현재: ${location.protocol}//${location.hostname}</small>
                </p>
            `;
        }
    </script>

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
                <div class="camera-controls" style="width: 100%; text-align: center; margin-top: 1rem;">
                    <button class="btn btn-warning" onclick="startCameraMode('overview', <?php echo $overview['index']; ?>, '<?php echo htmlspecialchars($overview['path']); ?>')">
                        🔄 다시 촬영
                    </button>
                </div>
            </div>
            <?php else: ?>
            <!-- moveout 사진이 없는 경우: 위치확인용 사진은 퇴거 시 촬영 불필요 -->
            <div class="photo-item preview-mode overview-photo" id="photo-item-overview-<?php echo $overview['index']; ?>">
                <div class="movein-photo">
                    <img src="<?php echo htmlspecialchars($overview['path']); ?>" alt="입주 위치확인 사진">
                </div>
                <div class="camera-section">
                    <div class="camera-container" id="camera-container-overview-<?php echo $overview['index']; ?>">
                        <div style="text-align: center; padding: 2rem; color: #666;">
                            단지 위치를 확인하기 위한 사진입니다.<br>퇴거사진 촬영은 아래 세부 사진에서 가능합니다.
                        </div>
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
                <div class="camera-controls" style="width: 100%; text-align: center; margin-top: 1rem;">
                    <button class="btn btn-warning" onclick="startCameraMode('closeup', <?php echo $closeup['index']; ?>, '<?php echo htmlspecialchars($closeup['path']); ?>')">
                        🔄 다시 촬영
                    </button>
                </div>
            </div>
            <?php else: ?>
            <!-- moveout 사진이 없는 경우: 촬영 모드 -->
            <div class="photo-item preview-mode" id="photo-item-closeup-<?php echo $closeup['index']; ?>">
                            <div class="movein-photo">
                <img src="<?php echo htmlspecialchars($closeup['path']); ?>" alt="입주 세부 사진">
            </div>
                <div class="camera-section">
                    <div class="camera-container" id="camera-container-closeup-<?php echo $closeup['index']; ?>">
                        <div style="text-align: center; padding: 2rem; color: #666;">
                            버튼을 눌러 비교 사진 촬영
                        </div>
                    </div>
                    <div class="camera-controls">
                        <button class="btn btn-primary" onclick="startCameraMode('closeup', <?php echo $closeup['index']; ?>, '<?php echo htmlspecialchars($closeup['path']); ?>')">
                            📷 촬영 시작
                        </button>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</main>

<script>
// PHP 설정값을 JavaScript로 전달
const CAMERA_CONFIG = {
    idealWidth: <?php echo CAMERA_IDEAL_WIDTH; ?>,
    idealHeight: <?php echo CAMERA_IDEAL_HEIGHT; ?>,
    minWidth: <?php echo CAMERA_MIN_WIDTH; ?>,
    minHeight: <?php echo CAMERA_MIN_HEIGHT; ?>,
    jpegQuality: <?php echo CAMERA_JPEG_QUALITY; ?>
};

let currentStream = null;
let capturedPhotos = {};
let currentImageInfo = null; // 현재 입주 사진의 비율 정보 저장
let currentCameraOrientation = null; // 현재 카메라 방향 정보 저장

// 이미지 회전 감지 및 보정 함수
function getImageOrientation(file) {
    return new Promise((resolve) => {
        const reader = new FileReader();
        reader.onload = function(e) {
            const view = new DataView(e.target.result);
            if (view.getUint16(0, false) != 0xFFD8) {
                resolve(-2); // JPEG가 아님
                return;
            }
            const length = view.byteLength;
            let offset = 2;
            while (offset < length) {
                const marker = view.getUint16(offset, false);
                offset += 2;
                if (marker == 0xFFE1) {
                    if (view.getUint32(offset += 2, false) != 0x45786966) {
                        resolve(-1); // EXIF 없음
                        return;
                    }
                    const little = view.getUint16(offset += 6, false) == 0x4949;
                    offset += view.getUint32(offset + 4, little);
                    const tags = view.getUint16(offset, little);
                    offset += 2;
                    for (let i = 0; i < tags; i++) {
                        if (view.getUint16(offset + (i * 12), little) == 0x0112) {
                            resolve(view.getUint16(offset + (i * 12) + 8, little));
                            return;
                        }
                    }
                } else if ((marker & 0xFF00) != 0xFF00) {
                    break;
                }
                offset += view.getUint16(offset, false);
            }
            resolve(-1); // orientation 태그 없음
        };
        reader.readAsArrayBuffer(file);
    });
}

// Base64를 Blob으로 변환
function base64ToBlob(base64, mimeType) {
    const byteCharacters = atob(base64.split(',')[1]);
    const byteNumbers = new Array(byteCharacters.length);
    for (let i = 0; i < byteCharacters.length; i++) {
        byteNumbers[i] = byteCharacters.charCodeAt(i);
    }
    const byteArray = new Uint8Array(byteNumbers);
    return new Blob([byteArray], {type: mimeType});
}

// 입주 사진의 비율 분석 함수
function analyzeImageAspectRatio(imgElement) {
    return new Promise((resolve) => {
        if (imgElement.complete && imgElement.naturalWidth && imgElement.naturalHeight) {
            const width = imgElement.naturalWidth;
            const height = imgElement.naturalHeight;
            const aspectRatio = width / height;
            
            console.log(`이미지 크기: ${width}x${height}, 비율: ${aspectRatio.toFixed(3)}`);
            
            resolve({
                width: width,
                height: height,
                aspectRatio: aspectRatio,
                isPortrait: height > width,
                isLandscape: width > height,
                isSquare: Math.abs(aspectRatio - 1) < 0.1
            });
        } else {
            // 이미지가 로드되지 않은 경우 로드 완료 대기
            imgElement.onload = () => {
                const width = imgElement.naturalWidth;
                const height = imgElement.naturalHeight;
                const aspectRatio = width / height;
                
                console.log(`이미지 크기: ${width}x${height}, 비율: ${aspectRatio.toFixed(3)}`);
                
                resolve({
                    width: width,
                    height: height,
                    aspectRatio: aspectRatio,
                    isPortrait: height > width,
                    isLandscape: width > height,
                    isSquare: Math.abs(aspectRatio - 1) < 0.1
                });
            };
            
            imgElement.onerror = () => {
                console.error('이미지 로드 실패, 기본 비율 사용');
                resolve({
                    width: CAMERA_CONFIG.idealWidth,
                    height: CAMERA_CONFIG.idealHeight,
                    aspectRatio: CAMERA_CONFIG.idealWidth / CAMERA_CONFIG.idealHeight,
                    isPortrait: false,
                    isLandscape: true,
                    isSquare: false
                });
            };
        }
    });
}

// 반대 비율 제약조건 생성 (카메라 렌즈 비율 조정)
function createCameraConstraints(targetAspectRatio, isIOS) {
    // 카메라 렌즈 비율을 반대로 설정 (테두리는 그대로 유지)
    const cameraAspectRatio = 1 / targetAspectRatio;
    
    console.log(`카메라 제약조건 - 목표 비율: ${targetAspectRatio.toFixed(3)}, 카메라 렌즈 비율: ${cameraAspectRatio.toFixed(3)} (반대 비율)`);
    
    // 카메라 렌즈 비율에 맞는 해상도 계산
    let idealWidth, idealHeight;
    
    if (cameraAspectRatio >= 1) {
        // 카메라 렌즈가 가로인 경우
        idealWidth = CAMERA_CONFIG.idealWidth;
        idealHeight = Math.round(idealWidth / cameraAspectRatio);
    } else {
        // 카메라 렌즈가 세로인 경우
        idealHeight = CAMERA_CONFIG.idealHeight;
        idealWidth = Math.round(idealHeight * cameraAspectRatio);
    }
    
    // 최소값 계산
    const minWidth = Math.max(CAMERA_CONFIG.minWidth, Math.round(idealWidth * 0.8));
    const minHeight = Math.max(CAMERA_CONFIG.minHeight, Math.round(idealHeight * 0.8));
    
    console.log(`계산된 해상도 - 목표: ${idealWidth}x${idealHeight}, 최소: ${minWidth}x${minHeight}`);
    
    // 반대 비율 제약조건 설정
    const constraints = isIOS ? {
        video: {
            facingMode: 'environment',
            width: { ideal: idealWidth, min: minWidth, max: idealWidth * 1.1 },
            height: { ideal: idealHeight, min: minHeight, max: idealHeight * 1.1 },
            aspectRatio: { exact: cameraAspectRatio } // 반대 비율 강제
        }
    } : {
        video: { 
            width: { ideal: idealWidth, min: minWidth, max: idealWidth * 1.1 },
            height: { ideal: idealHeight, min: minHeight, max: idealHeight * 1.1 },
            aspectRatio: { exact: cameraAspectRatio }, // 반대 비율 강제
            facingMode: { ideal: 'environment' }
        }
    };
    
    return { 
        constraints, 
        idealWidth, 
        idealHeight, 
        minWidth, 
        minHeight,
        isPortraitTarget: targetAspectRatio < 1
    };
}



// 카메라 비율 확인 함수 (회전 없이)
function detectCameraOrientation(video, imageInfo) {
    const actualRatio = video.videoWidth / video.videoHeight;
    const targetRatio = imageInfo.aspectRatio;
    
    console.log(`비율 확인 - 비디오: ${actualRatio.toFixed(3)} (${actualRatio < 1 ? '세로' : '가로'}), 목표: ${targetRatio.toFixed(3)} (${targetRatio < 1 ? '세로' : '가로'})`);
    
    // 방향 확인
    const isTargetPortrait = targetRatio < 1;
    const isActualPortrait = actualRatio < 1;
    const isTargetLandscape = targetRatio > 1;
    const isActualLandscape = actualRatio > 1;
    
    // 비율 차이 계산
    const ratioDifference = Math.abs(actualRatio - targetRatio);
    
    console.log(`비율 차이: ${ratioDifference.toFixed(3)} (0.1 미만이면 좋음)`);
    
    // 회전 없이 비율만 확인
    return {
        needsRotation: false, // 회전하지 않음
        rotationAngle: 0,     // 회전하지 않음
        isTargetPortrait,
        isActualPortrait,
        actualRatio,
        targetRatio,
        ratioDifference
    };
}

// 비디오 표시 크기 조정 함수 (회전 없이)
function adjustVideoDisplaySize(video, container, targetRatio, actualRatio, cameraOrientation) {
    console.log(`비디오 표시 크기 조정 - 목표 비율: ${targetRatio.toFixed(3)}, 실제 비율: ${actualRatio.toFixed(3)}`);
    
    const ratioDifference = Math.abs(actualRatio - targetRatio);
    
    // 회전 제거 - 원래 Transform 제거
    video.style.transform = '';
    video.style.transformOrigin = '';
    video.classList.remove('rotated');
    
    // 목표 비율로 컨테이너 설정
    container.style.aspectRatio = `${targetRatio}`;
    
    // 목표 비율에 맞는 최대 너비 설정
    if (targetRatio < 1) {
        container.style.maxWidth = '300px';
        console.log('세로 목표: 최대 너비 300px');
    } else if (targetRatio < 1.2) {
        container.style.maxWidth = '350px';
        console.log('거의 정사각형 목표: 최대 너비 350px');
    } else {
        container.style.maxWidth = '400px';
        console.log('가로 목표: 최대 너비 400px');
    }
    
    // 항상 contain 모드 사용하여 줌인 방지
    video.style.objectFit = 'contain';
    video.style.objectPosition = 'center';
    
    if (ratioDifference < 0.1) {
        console.log('비디오 표시 모드: contain (비율 거의 일치, 완벽한 매칭)');
    } else if (ratioDifference < 0.3) {
        console.log('비디오 표시 모드: contain (비율 약간 차이, 양쪽에 여백 가능)');
    } else {
        console.log('비디오 표시 모드: contain (비율 큰 차이, 여백 있음)');
        
        // 비율 차이가 큰 경우 경고
        if (targetRatio < 1 && actualRatio > 1.5) {
            console.warn('세로 목표 + 가로 비디오: 카메라 제약조건을 더 강화해야 할 수 있습니다.');
        } else if (targetRatio > 1.5 && actualRatio < 1) {
            console.warn('가로 목표 + 세로 비디오: 카메라 제약조건을 더 강화해야 할 수 있습니다.');
        }
    }
    
    // 컨테이너의 현재 설정 확인
    const containerStyle = window.getComputedStyle(container);
    console.log(`컨테이너 최종 상태 - aspect-ratio: ${containerStyle.aspectRatio}, max-width: ${container.style.maxWidth}`);
    console.log(`비율 차이: ${ratioDifference.toFixed(3)} (0.1 미만이면 완벽한 매칭)`);
}

// 이미지 회전 보정 함수
function correctImageOrientation(canvas, orientation) {
    const ctx = canvas.getContext('2d');
    const width = canvas.width;
    const height = canvas.height;
    
    // 임시 캔버스 생성
    const tempCanvas = document.createElement('canvas');
    const tempCtx = tempCanvas.getContext('2d');
    
    // orientation에 따른 변환 적용
    switch (orientation) {
        case 2:
            // 수평 뒤집기
            tempCanvas.width = width;
            tempCanvas.height = height;
            tempCtx.scale(-1, 1);
            tempCtx.drawImage(canvas, -width, 0);
            break;
        case 3:
            // 180도 회전
            tempCanvas.width = width;
            tempCanvas.height = height;
            tempCtx.rotate(Math.PI);
            tempCtx.drawImage(canvas, -width, -height);
            break;
        case 4:
            // 수직 뒤집기
            tempCanvas.width = width;
            tempCanvas.height = height;
            tempCtx.scale(1, -1);
            tempCtx.drawImage(canvas, 0, -height);
            break;
        case 5:
            // 90도 반시계방향 회전 + 수평 뒤집기
            tempCanvas.width = height;
            tempCanvas.height = width;
            tempCtx.rotate(-Math.PI / 2);
            tempCtx.scale(-1, 1);
            tempCtx.drawImage(canvas, -height, -width);
            break;
        case 6:
            // 90도 시계방향 회전
            tempCanvas.width = height;
            tempCanvas.height = width;
            tempCtx.rotate(Math.PI / 2);
            tempCtx.drawImage(canvas, 0, -height);
            break;
        case 7:
            // 90도 시계방향 회전 + 수평 뒤집기
            tempCanvas.width = height;
            tempCanvas.height = width;
            tempCtx.rotate(Math.PI / 2);
            tempCtx.scale(-1, 1);
            tempCtx.drawImage(canvas, -height, 0);
            break;
        case 8:
            // 90도 반시계방향 회전
            tempCanvas.width = height;
            tempCanvas.height = width;
            tempCtx.rotate(-Math.PI / 2);
            tempCtx.drawImage(canvas, -width, 0);
            break;
        default:
            // 회전 없음 (orientation 1 또는 undefined)
            return canvas;
    }
    
    // 원본 캔버스에 보정된 이미지 복사
    canvas.width = tempCanvas.width;
    canvas.height = tempCanvas.height;
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.drawImage(tempCanvas, 0, 0);
    
    return canvas;
}

// iOS 사용자 안내 표시 함수
function showIOSGuide() {
    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);
    if (isIOS) {
        const notice = document.getElementById('ios-notice');
        notice.style.display = 'block';
        notice.style.background = '#e3f2fd';
        notice.style.borderColor = '#1976d2';
        notice.innerHTML = `
            <h4 style="color: #1976d2; margin-bottom: 0.5rem;">📱 iOS 카메라 권한 설정</h4>
            <p style="margin: 0; font-size: 0.9rem; line-height: 1.4;">
                카메라 권한이 거부되었습니다. 다음 방법으로 허용해주세요:<br><br>
                <strong>▼ Safari 주소창에서:</strong><br>
                1. 주소창 왼쪽 "aA" 버튼 클릭<br>
                2. "웹사이트 설정" 선택<br>
                3. "카메라" → "허용" 선택<br><br>
                <strong>▼ 또는 iOS 설정에서:</strong><br>
                1. 설정 → Safari → 카메라 → "허용"<br>
                2. 설정 → 개인정보 보호 → 카메라 → Safari 허용<br><br>
                <small>설정 변경 후 페이지를 새로고침해주세요.</small>
            </p>
        `;
    }
}

// 촬영 모드로 전환
function startCameraMode(type, index, originalPhotoPath) {
    const photoItem = document.getElementById(`photo-item-${type}-${index}`);
    const originalPhoto = photoItem.querySelector('.movein-photo img');
    const meta = photoItem.querySelector('.photo-meta');
    
    // 촬영 모드로 레이아웃 변경
    photoItem.className = 'photo-item camera-mode';
    photoItem.innerHTML = `
        <div class="photo-header">
            <h3>${type === 'overview' ? '위치확인용' : '세부'} 사진 촬영</h3>
            <p style="margin: 0.5rem 0; color: #666; font-size: 0.9rem;">
                ${meta ? meta.textContent : '기존 사진과 비교하여 같은 구도로 촬영해주세요'}
            </p>
            <div id="orientation-notice-${type}-${index}" style="display: none; margin: 0.5rem 0; padding: 0.5rem; background: #e3f2fd; border: 1px solid #1976d2; border-radius: 4px; font-size: 0.85rem; color: #1976d2;">
                📱 카메라가 자동으로 회전되어 입주 사진과 동일한 방향으로 촬영됩니다.
            </div>
            <div id="debug-info-${type}-${index}" style="display: none; margin: 0.5rem 0; padding: 0.5rem; background: #f8f9fa; border: 1px solid #6c757d; border-radius: 4px; font-size: 0.8rem; color: #6c757d; font-family: monospace;">
                디버그 정보가 여기에 표시됩니다.
            </div>
        </div>
        <div class="camera-container" id="camera-container-${type}-${index}">
            <div style="text-align: center; padding: 2rem; color: #666;">
                카메라를 준비하고 있습니다...
            </div>
        </div>
    `;
    
    // 카메라 시작
    requestCameraPermission(type, index, originalPhotoPath);
}

// 촬영 모드 종료
function exitCameraMode(type, index, originalPhotoPath) {
    // 카메라 정지
    if (currentStream) {
        currentStream.getTracks().forEach(track => track.stop());
        currentStream = null;
    }
    
    // 페이지 새로고침으로 원래 상태로 복원
    location.reload();
}

// getUserMedia를 위한 폴백 함수
function getUserMediaCompat() {
    // 최신 API
    if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
        return navigator.mediaDevices.getUserMedia.bind(navigator.mediaDevices);
    }
    
    // 구형 API 폴백
    const getUserMedia = navigator.webkitGetUserMedia || 
                        navigator.mozGetUserMedia || 
                        navigator.msGetUserMedia ||
                        navigator.getUserMedia;
    
    if (getUserMedia) {
        return function(constraints) {
            return new Promise((resolve, reject) => {
                getUserMedia.call(navigator, constraints, resolve, reject);
            });
        };
    }
    
    return null;
}

// 권한 요청 함수 (사용자 제스처에서 호출되어야 함)
async function requestCameraPermission(type, index, originalPhotoPath) {
    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);
    const container = document.getElementById(`camera-container-${type}-${index}`);
    
    // 카메라 API 지원 확인
    const getUserMedia = getUserMediaCompat();
    
    if (!getUserMedia) {
        container.innerHTML = `
            <div style="text-align: center; padding: 2rem; color: #dc3545; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 8px;">
                <h3 style="color: #721c24; margin-bottom: 1rem;">❌ 카메라 미지원</h3>
                <p>현재 브라우저에서는 카메라 기능을 지원하지 않습니다.</p>
                <p style="font-size: 0.9rem; margin-top: 1rem;">
                    ${isIOS ? 'Safari 최신 버전으로 업데이트하거나 HTTPS 환경에서 접속해주세요.' : '최신 브라우저를 사용해주세요.'}
                </p>
            </div>
        `;
        return;
    }
    
    try {
        // 먼저 간단한 권한 요청을 시도
        console.log('카메라 권한 요청 시작...');
        
        container.innerHTML = `
            <div style="text-align: center; padding: 2rem; color: #666;">
                <div style="margin-bottom: 1rem;">📷</div>
                <div>카메라 권한을 요청하고 있습니다...</div>
                <div style="font-size: 0.9rem; margin-top: 0.5rem; color: #888;">
                    ${isIOS ? 'Safari에서 "허용" 버튼을 눌러주세요' : '브라우저에서 카메라 접근을 허용해주세요'}
                </div>
            </div>
        `;
        
        const testConstraints = {
            video: true // 가장 기본적인 요청
        };
        
        // 권한 요청을 위한 임시 스트림
        const testStream = await getUserMedia(testConstraints);
        
        // 임시 스트림 즉시 정지
        if (testStream && testStream.getTracks) {
            testStream.getTracks().forEach(track => track.stop());
        } else if (testStream && testStream.stop) {
            testStream.stop(); // 구형 API
        }
        
        console.log('카메라 권한 획득 성공, 실제 카메라 시작...');
        
        // 권한이 허용되면 실제 카메라 시작
        await startCamera(type, index, originalPhotoPath);
        
    } catch (error) {
        console.error('권한 요청 실패:', error);
        
        let errorMessage = '카메라 권한이 필요합니다.\n\n';
        
        if (error.name === 'NotAllowedError' || error.name === 'PermissionDeniedError') {
            if (isIOS) {
                // iOS 사용자 안내 표시
                showIOSGuide();
                errorMessage += 'iPhone Safari에서 카메라 권한이 거부되었습니다.\n\n';
                errorMessage += '위쪽의 안내를 참고하여 카메라 권한을 허용해주세요.\n\n';
                errorMessage += '※ HTTP 환경에서는 카메라 접근이 제한될 수 있습니다.';
            } else {
                errorMessage += '브라우저에서 카메라 권한을 허용해주세요.';
            }
        } else if (error.name === 'NotFoundError') {
            errorMessage += '카메라를 찾을 수 없습니다.\n카메라가 연결되어 있고 다른 앱에서 사용 중이 아닌지 확인해주세요.';
        } else {
            errorMessage += '오류: ' + (error.message || error.toString());
        }
        
        container.innerHTML = `
            <div style="text-align: center; padding: 2rem; color: #dc3545; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 8px;">
                <h3 style="color: #721c24; margin-bottom: 1rem;">📷 카메라 사용 불가</h3>
                <pre style="white-space: pre-wrap; text-align: left; font-size: 0.9rem; line-height: 1.4;">${errorMessage}</pre>
                <button class="btn btn-primary" onclick="requestCameraPermission('${type}', ${index}, '${originalPhotoPath}')" style="margin-top: 1rem;">
                    다시 시도
                </button>
            </div>
        `;
    }
}

async function startCamera(type, index, originalPhotoPath) {
    const containerId = `camera-container-${type}-${index}`;
    const container = document.getElementById(containerId);
    
    // 기존 카메라 스트림 정지
    if (currentStream) {
        currentStream.getTracks().forEach(track => track.stop());
    }
    
    // iOS 사파리 확인
    const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);
    const isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);
    
    // 로딩 표시
    container.innerHTML = `
        <div style="text-align: center; padding: 2rem; color: #666;">
            <div style="margin-bottom: 1rem;">📷</div>
            <div>입주 사진 비율 분석 중...</div>
            <div style="font-size: 0.9rem; margin-top: 0.5rem; color: #888;">
                카메라 화면을 입주 사진과 동일한 비율로 설정합니다
            </div>
        </div>
    `;
    
    try {
        // 카메라 API 지원 확인
        const getUserMedia = getUserMediaCompat();
        
        if (!getUserMedia) {
            throw new Error('카메라 API를 지원하지 않는 브라우저입니다.');
        }

        // 입주 사진의 비율 분석
        const originalImg = new Image();
        originalImg.crossOrigin = 'anonymous';
        originalImg.src = originalPhotoPath;
        
        const imageInfo = await analyzeImageAspectRatio(originalImg);
        console.log('입주 사진 분석 결과:', imageInfo);
        
        // 전역 변수에 이미지 정보 저장
        currentImageInfo = imageInfo;
        
        // 전역 변수에 카메라 방향 정보 저장 (촬영 시 사용)
        currentCameraOrientation = null; // 비디오 로드 후 설정됨
        
        // 로딩 메시지 업데이트
        container.innerHTML = `
            <div style="text-align: center; padding: 2rem; color: #666;">
                <div style="margin-bottom: 1rem;">📷</div>
                <div>카메라 권한을 요청하고 있습니다...</div>
                <div style="font-size: 0.9rem; margin-top: 0.5rem; color: #888;">
                    ${isIOS ? 'Safari에서 "허용" 버튼을 눌러주세요' : '브라우저에서 카메라 접근을 허용해주세요'}
                </div>
                <div style="font-size: 0.8rem; margin-top: 0.5rem; color: #0064FF;">
                    목표 비율: ${imageInfo.aspectRatio.toFixed(3)} (${imageInfo.isPortrait ? '세로' : imageInfo.isLandscape ? '가로' : '정사각형'})
                </div>
            </div>
        `;

        // 권한 상태 확인 (지원하는 브라우저만)
        if (navigator.permissions) {
            try {
                const permissionStatus = await navigator.permissions.query({ name: 'camera' });
                console.log('카메라 권한 상태:', permissionStatus.state);
                
                if (permissionStatus.state === 'denied') {
                    throw new Error('카메라 권한이 차단되었습니다. 브라우저 설정에서 허용해주세요.');
                }
            } catch (permError) {
                console.log('권한 상태 확인 불가:', permError);
                // 권한 API를 지원하지 않는 경우 계속 진행
            }
        }

        // 입주 사진 비율에 맞는 카메라 제약조건 생성
        const cameraConfig = createCameraConstraints(imageInfo.aspectRatio, isIOS);
        
        console.log('카메라 스트림 요청 중...', cameraConfig.constraints);
        
        // 카메라 스트림 요청 - 단계적 폴백 시스템
        try {
            currentStream = await getUserMedia(cameraConfig.constraints);
            console.log('1단계: 최적 비율 + 고해상도 카메라 스트림 획득 성공');
        } catch (highResError) {
            console.log('1단계 실패, 2단계 시도: 비율 유지 + 중간 해상도...', highResError);
            
            // 2단계: 중간 해상도 + 비율 제약 유지
            const fallbackConfig = createCameraConstraints(imageInfo.aspectRatio, isIOS);
            fallbackConfig.constraints.video.width.ideal = Math.floor(fallbackConfig.idealWidth * 0.75);
            fallbackConfig.constraints.video.height.ideal = Math.floor(fallbackConfig.idealHeight * 0.75);
            
            try {
                currentStream = await getUserMedia(fallbackConfig.constraints);
                console.log('2단계: 중간 해상도 카메라 스트림 획득 성공');
            } catch (mediumResError) {
                console.log('2단계 실패, 3단계 시도: 비율 제약 완화...', mediumResError);
                
                // 3단계: 비율 제약 완화 (±20%)
                const relaxedConstraints = {
                    video: {
                        facingMode: 'environment',
                        width: { ideal: Math.floor(fallbackConfig.idealWidth * 0.5) },
                        height: { ideal: Math.floor(fallbackConfig.idealHeight * 0.5) },
                        aspectRatio: { ideal: imageInfo.aspectRatio, min: imageInfo.aspectRatio * 0.8, max: imageInfo.aspectRatio * 1.2 }
                    }
                };
                
                try {
                    currentStream = await getUserMedia(relaxedConstraints);
                    console.log('3단계: 완화된 비율 제약 카메라 스트림 획득 성공');
                } catch (relaxedError) {
                    console.log('3단계 실패, 4단계 시도: 비율 제약만...', relaxedError);
                    
                    // 4단계: 비율 제약만 (해상도 제약 없음)
                    const ratioOnlyConstraints = {
                        video: {
                            facingMode: 'environment',
                            aspectRatio: { ideal: imageInfo.aspectRatio }
                        }
                    };
                    
                    try {
                        currentStream = await getUserMedia(ratioOnlyConstraints);
                        console.log('4단계: 비율 제약만 카메라 스트림 획득 성공');
                    } catch (ratioError) {
                        console.log('4단계 실패, 5단계 시도: 최소 제약조건...', ratioError);
                        
                        // 5단계: 최소 제약조건 (비율 제약 없음)
                        const minimalConstraints = {
                            video: {
                                facingMode: 'environment'
                            }
                        };
                        
                        currentStream = await getUserMedia(minimalConstraints);
                        console.log('5단계: 최소 제약조건 카메라 스트림 획득 성공 (비율 불일치 가능)');
                    }
                }
            }
        }
        
        // 비율에 맞는 CSS 클래스 결정
        let ratioClass = 'custom-ratio';
        if (imageInfo.isSquare) {
            ratioClass = 'square';
        } else if (imageInfo.isPortrait) {
            ratioClass = 'portrait';
        } else if (imageInfo.isLandscape) {
            ratioClass = 'landscape';
        }
        
        // 세로 사진인 경우 화면 방향 강제 설정 시도
        if (imageInfo.isPortrait) {
            try {
                if (screen.orientation && screen.orientation.lock) {
                    screen.orientation.lock('portrait').catch(e => {
                        console.log('화면 방향 고정 실패 (선택사항):', e);
                    });
                }
            } catch (e) {
                console.log('화면 방향 API 미지원:', e);
            }
        }
        
        // 오버레이 컨테이너 생성
        container.innerHTML = `
            <div class="overlay-container ${ratioClass}" id="overlay-container-${type}-${index}">
                <video class="overlay-video" autoplay playsinline></video>
                <img class="overlay-image" src="${originalPhotoPath}" alt="입주 사진 오버레이">
                <canvas id="canvas-${type}-${index}" style="display:none;"></canvas>
            </div>
            <div class="camera-controls" style="text-align: center;">
                <button class="btn btn-primary" onclick="capturePhoto('${type}', ${index})" style="font-size: 1.1rem; padding: 1rem 2rem; margin-bottom: 1rem;">
                    📸 촬영하기
                </button>
                <div style="margin: 2rem 0; padding: 1rem; background: #f8f9fa; border-radius: 8px;">
                    <label style="display: block; margin-bottom: 1rem; font-weight: 600; color: #333;">
                        오버레이 투명도: <span id="opacity-value-${type}-${index}" style="color: #0064FF; font-weight: 700;">50</span>%
                    </label>
                    <input type="range" min="0" max="100" value="50" oninput="adjustOverlay('${type}', ${index}, this.value)" 
                           style="width: 100%; height: 8px; -webkit-appearance: none; appearance: none; background: #ddd; outline: none; border-radius: 4px;">
                </div>
                <button class="btn btn-secondary" onclick="exitCameraMode('${type}', ${index}, '${originalPhotoPath}')">
                    ✕ 취소
                </button>
            </div>
            <div class="comparison-container" id="comparison-${type}-${index}" style="display:none;">
                <h3 style="text-align: center; margin: 1.5rem 0;">📸 촬영 완료</h3>
                <div class="comparison-photos" style="display: flex; gap: 1rem; justify-content: center; max-width: 800px; margin: 0 auto;">
                    <div class="comparison-item" style="flex: 1; text-align: center;">
                        <h4 style="margin-bottom: 0.5rem; color: #1976d2;">입주 시</h4>
                        <img src="${originalPhotoPath}" alt="입주 사진" style="width: 100%; max-width: 300px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    </div>
                    <div class="comparison-item" style="flex: 1; text-align: center;">
                        <h4 style="margin-bottom: 0.5rem; color: #28a745;">퇴거 시 (지금 촬영)</h4>
                        <img id="captured-${type}-${index}" alt="촬영된 사진" style="width: 100%; max-width: 300px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); display:none;">
                    </div>
                </div>
                <div style="text-align: center; margin-top: 1.5rem; display: flex; gap: 1rem; justify-content: center;">
                    <button class="btn btn-success" onclick="savePhoto('${type}', ${index})" id="save-btn-${type}-${index}" style="display:none;">
                        💾 저장하기
                    </button>
                    <button class="btn btn-warning" onclick="retakePhoto('${type}', ${index})">
                        🔄 다시 촬영
                    </button>
                    <button class="btn btn-secondary" onclick="exitCameraMode('${type}', ${index}, '${originalPhotoPath}')">
                        ✕ 취소
                    </button>
                </div>
            </div>
        `;
        
        const video = container.querySelector('.overlay-video');
        const overlayImage = container.querySelector('.overlay-image');
        const overlayContainer = container.querySelector('.overlay-container');
        
        // 커스텀 비율인 경우 초기 설정 (나중에 실제 비디오 비율로 덮어씀)
        if (ratioClass === 'custom-ratio') {
            // 초기에는 목표 비율로 설정하지만, 비디오 로드 후 실제 비율로 변경됨
            overlayContainer.style.aspectRatio = `${imageInfo.aspectRatio}`;
            
            // 세로 비율인 경우 최대 너비 제한
            if (imageInfo.aspectRatio < 1) {
                overlayContainer.style.maxWidth = '300px';
            } else {
                overlayContainer.style.maxWidth = '400px';
            }
            
            console.log(`초기 커스텀 비율 설정: ${imageInfo.aspectRatio} (비디오 로드 후 실제 비율로 변경됨)`);
        }
        
        video.srcObject = currentStream;
        
        // 오버레이 이미지 로드 확인 및 초기 투명도 설정
        if (overlayImage) {
            overlayImage.onload = function() {
                console.log('오버레이 이미지 로드 완료');
                // 초기 투명도 50% 설정
                overlayImage.style.opacity = '0.5';
            };
            
            // 이미 로드된 경우
            if (overlayImage.complete) {
                overlayImage.style.opacity = '0.5';
                console.log('오버레이 이미지 이미 로드됨');
            }
        }
        
        // 카메라 스트림 정보 모니터링
        video.onloadedmetadata = function() {
            const actualRatio = video.videoWidth / video.videoHeight;
            console.log(`실제 카메라 해상도: ${video.videoWidth}x${video.videoHeight}, 비율: ${actualRatio.toFixed(3)}`);
            console.log(`목표 비율: ${imageInfo.aspectRatio.toFixed(3)}, 차이: ${Math.abs(actualRatio - imageInfo.aspectRatio).toFixed(3)}`);
            
                    // 카메라 방향 감지 및 자동 회전 처리
        const cameraOrientation = detectCameraOrientation(video, imageInfo);
        console.log('카메라 방향 감지 결과:', cameraOrientation);
        
        // 전역 변수에 카메라 방향 정보 저장
        currentCameraOrientation = cameraOrientation;
        
        // 디버그 정보 표시
        const debugElement = document.getElementById(`debug-info-${type}-${index}`);
        if (debugElement) {
            debugElement.style.display = 'block';
            debugElement.innerHTML = `
                비디오: ${cameraOrientation.actualRatio.toFixed(3)} (${cameraOrientation.isActualPortrait ? '세로' : '가로'})<br>
                목표: ${cameraOrientation.targetRatio.toFixed(3)} (${cameraOrientation.isTargetPortrait ? '세로' : '가로'})<br>
                회전 필요: ${cameraOrientation.needsRotation ? '예' : '아니오'}<br>
                회전 각도: ${cameraOrientation.rotationAngle}도
            `;
        }
        
        // 카메라 렌즈 비율 정보 표시
        const noticeElement = document.getElementById(`orientation-notice-${type}-${index}`);
        if (noticeElement) {
            if (cameraOrientation.ratioDifference > 0.1) {
                noticeElement.style.display = 'block';
                noticeElement.innerHTML = `
                    📷 카메라 렌즈가 입주 사진과 동일한 비율로 설정되었습니다.<br>
                    <small>카메라 렌즈 비율: ${cameraOrientation.actualRatio.toFixed(3)} → 목표 비율: ${cameraOrientation.targetRatio.toFixed(3)}</small>
                `;
                console.log('사용자에게 카메라 렌즈 비율 안내 메시지 표시');
            } else {
                noticeElement.style.display = 'none';
                console.log('카메라 렌즈 비율 일치 - 안내 불필요');
            }
        }
            
            // 비율 차이가 큰 경우 사용자에게 알림
            const ratioDifference = Math.abs(actualRatio - imageInfo.aspectRatio);
            if (ratioDifference > 0.1) {
                console.warn(`비율 차이가 큽니다. 목표: ${imageInfo.aspectRatio.toFixed(3)}, 실제: ${actualRatio.toFixed(3)}`);
            }
            
            // 비디오 표시 크기 조정 (컨테이너 포함)
            adjustVideoDisplaySize(video, overlayContainer, imageInfo.aspectRatio, actualRatio, cameraOrientation);
        };
        
    } catch (error) {
        console.error('카메라 접근 오류:', error);
        
        let errorMessage = '카메라에 접근할 수 없습니다.\n\n';
        
        if (error.name === 'NotAllowedError' || error.name === 'PermissionDeniedError') {
            if (isIOS) {
                // iOS 사용자 안내 표시
                showIOSGuide();
                errorMessage += 'iPhone Safari에서 카메라 권한이 거부되었습니다.\n\n';
                errorMessage += '위쪽의 안내를 참고하여 카메라 권한을 허용해주세요.';
            } else {
                errorMessage += '카메라 권한이 거부되었습니다.\n\n';
                errorMessage += '브라우저 주소창 근처의 카메라 아이콘을 클릭하여\n';
                errorMessage += '카메라 권한을 허용해주세요.';
            }
        } else if (error.name === 'NotFoundError') {
            errorMessage += '카메라를 찾을 수 없습니다. 기기에 카메라가 있는지 확인해주세요.';
        } else if (error.name === 'NotSupportedError') {
            errorMessage += '브라우저에서 카메라 API를 지원하지 않습니다.';
        } else if (error.name === 'NotReadableError') {
            errorMessage += '다른 앱에서 카메라를 사용 중입니다. 다른 앱을 종료 후 다시 시도해주세요.';
        } else {
            errorMessage += '오류: ' + error.message;
        }
        
        container.innerHTML = `
            <div style="text-align: center; padding: 2rem; color: #dc3545; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 8px;">
                <h3 style="color: #721c24; margin-bottom: 1rem;">📷 카메라 접근 오류</h3>
                <pre style="white-space: pre-wrap; text-align: left; font-size: 0.9rem; line-height: 1.4;">${errorMessage}</pre>
                <button class="btn btn-primary" onclick="startCamera('${type}', ${index}, '${originalPhotoPath}')" style="margin-top: 1rem;">
                    다시 시도
                </button>
            </div>
        `;
    }
}

function adjustOverlay(type, index, opacity) {
    const container = document.getElementById(`camera-container-${type}-${index}`);
    const overlayImage = container.querySelector('.overlay-image');
    const valueDisplay = document.getElementById(`opacity-value-${type}-${index}`);
    
    if (overlayImage) {
        overlayImage.style.opacity = opacity / 100;
        console.log(`오버레이 투명도 변경: ${opacity}% (${opacity / 100})`);
    }
    
    if (valueDisplay) {
        valueDisplay.textContent = opacity;
    }
}

async function capturePhoto(type, index) {
    const container = document.getElementById(`camera-container-${type}-${index}`);
    const video = container.querySelector('.overlay-video');
    const canvas = container.querySelector(`#canvas-${type}-${index}`);
    const context = canvas.getContext('2d');
    
    console.log('사진 캡처 시작 - 비디오 크기:', video.videoWidth, 'x', video.videoHeight);
    console.log('목표 비율:', currentImageInfo ? currentImageInfo.aspectRatio.toFixed(3) : '정보 없음');
    
    // 카메라 비율 확인
    const cameraOrientation = currentCameraOrientation || detectCameraOrientation(video, currentImageInfo);
    console.log('촬영 시 카메라 비율:', cameraOrientation);
    
    // 목표 비율에 맞는 캔버스 크기 계산 (회전 없이)
    let canvasWidth = video.videoWidth;
    let canvasHeight = video.videoHeight;
    let sourceX = 0;
    let sourceY = 0;
    let sourceWidth = video.videoWidth;
    let sourceHeight = video.videoHeight;
    
    if (currentImageInfo) {
        const videoRatio = video.videoWidth / video.videoHeight;
        const targetRatio = currentImageInfo.aspectRatio;
        
        console.log(`비디오 비율: ${videoRatio.toFixed(3)}, 목표 비율: ${targetRatio.toFixed(3)}`);
        
        // 비율 차이가 있는 경우 크롭 적용
        if (Math.abs(videoRatio - targetRatio) > 0.05) {
            if (videoRatio > targetRatio) {
                // 비디오가 더 가로로 넓음 - 좌우 크롭
                sourceWidth = Math.round(video.videoHeight * targetRatio);
                sourceX = Math.round((video.videoWidth - sourceWidth) / 2);
                canvasWidth = sourceWidth;
                canvasHeight = video.videoHeight;
                console.log(`가로 크롭: ${sourceX}, 0, ${sourceWidth}, ${sourceHeight}`);
            } else {
                // 비디오가 더 세로로 김 - 상하 크롭
                sourceHeight = Math.round(video.videoWidth / targetRatio);
                sourceY = Math.round((video.videoHeight - sourceHeight) / 2);
                canvasWidth = video.videoWidth;
                canvasHeight = sourceHeight;
                console.log(`세로 크롭: 0, ${sourceY}, ${sourceWidth}, ${sourceHeight}`);
            }
        }
    }
    
    // 캔버스 크기를 목표 크기로 설정
    canvas.width = canvasWidth;
    canvas.height = canvasHeight;
    
    // 크롭된 비디오 프레임을 캔버스에 그리기
    context.drawImage(video, sourceX, sourceY, sourceWidth, sourceHeight, 0, 0, canvasWidth, canvasHeight);
    
    console.log(`최종 캔버스 크기: ${canvasWidth}x${canvasHeight}, 비율: ${(canvasWidth/canvasHeight).toFixed(3)}`);
    
    // 모바일 기기에서 비디오 설정 로깅 (회전 보정 없음)
    const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
    if (isMobile) {
        // 비디오 트랙에서 설정 정보 확인
        const videoTrack = currentStream ? currentStream.getVideoTracks()[0] : null;
        let videoSettings = null;
        if (videoTrack && videoTrack.getSettings) {
            videoSettings = videoTrack.getSettings();
            console.log('비디오 설정:', videoSettings);
        }
        
        // 기기 방향 로깅 (회전 보정 없음)
        const orientation = screen.orientation ? screen.orientation.angle : window.orientation;
        console.log('기기 방향:', orientation, '캔버스 크기:', canvasWidth, 'x', canvasHeight);
        console.log('회전 보정 없음 - 카메라 제약조건으로 비율 제어');
    }
    
    // 캔버스에서 이미지 데이터 추출 (config.inc에서 설정된 품질)
    const imageData = canvas.toDataURL('image/jpeg', CAMERA_CONFIG.jpegQuality);
    capturedPhotos[`${type}-${index}`] = imageData;
    
    // 촬영된 사진 표시
    const capturedImg = document.getElementById(`captured-${type}-${index}`);
    capturedImg.src = imageData;
    capturedImg.style.display = 'block';
    
    // 비교 컨테이너 표시
    const comparisonContainer = document.getElementById(`comparison-${type}-${index}`);
    comparisonContainer.style.display = 'block';
    
    // 저장 버튼 표시
    const saveBtn = document.getElementById(`save-btn-${type}-${index}`);
    saveBtn.style.display = 'inline-block';
    
    // 촬영 창 숨기기 - 오버레이 컨테이너, 카메라 컨트롤, 헤더 숨기기
    const overlayContainer = container.querySelector('.overlay-container');
    const cameraControls = container.querySelector('.camera-controls');
    const photoHeader = container.parentElement.querySelector('.photo-header');
    if (overlayContainer) {
        overlayContainer.style.display = 'none';
    }
    if (cameraControls) {
        cameraControls.style.display = 'none';
    }
    if (photoHeader) {
        photoHeader.style.display = 'none';
    }
    
    // 카메라 정지
    stopCamera(type, index);
}

function stopCamera(type, index) {
    if (currentStream) {
        currentStream.getTracks().forEach(track => track.stop());
        currentStream = null;
    }
}

function retakePhoto(type, index) {
    // 비교 컨테이너 숨기기
    const comparisonContainer = document.getElementById(`comparison-${type}-${index}`);
    comparisonContainer.style.display = 'none';
    
    // 촬영된 사진 데이터 삭제
    delete capturedPhotos[`${type}-${index}`];
    
    // 카메라 다시 시작
    const container = document.getElementById(`camera-container-${type}-${index}`);
    const originalImg = container.querySelector('.overlay-image');
    if (originalImg) {
        // 이미지 정보 초기화
        currentImageInfo = null;
        startCamera(type, index, originalImg.src);
    }
}

function savePhoto(type, index) {
    const photoData = capturedPhotos[`${type}-${index}`];
    if (!photoData) {
        alert('저장할 사진이 없습니다.');
        return;
    }
    
    // 서버에 사진 저장 로직 구현
    const formData = new FormData();
    formData.append('contract_id', '<?php echo $contract_id; ?>');
    formData.append('photo_id', '<?php echo $photo_id; ?>');
    formData.append('type', type);
    formData.append('index', index);
    formData.append('photo_data', photoData);
    
    fetch('save_moveout_photo.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('사진이 저장되었습니다.');
            // 페이지 새로고침하여 처음 로딩 상태로 복원
            location.reload();
        } else {
            alert('저장 실패: ' + (data.message || '알 수 없는 오류'));
        }
    })
    .catch(error => {
        console.error('저장 오류:', error);
        alert('저장 중 오류가 발생했습니다.');
    });
}

// 페이지 언로드 시 카메라 스트림 정지
window.addEventListener('beforeunload', function() {
    if (currentStream) {
        currentStream.getTracks().forEach(track => track.stop());
    }
});
</script>

<?php include 'footer.inc'; ?>
</body>
</html> 