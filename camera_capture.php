<?php
require_once 'sql.inc';
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = get_pdo();
$user_id = $_SESSION['user_id'];
$contract_id = (int)($_GET['contract_id'] ?? 0);
$photo_id = (int)($_GET['photo_id'] ?? 0);
$type = $_GET['type'] ?? ''; // 'overview' 또는 'closeup'
$index = (int)($_GET['index'] ?? 0);

if (!$contract_id || !$photo_id || !$type || $index === 0) {
    die('잘못된 접근입니다.');
}

// 계약 정보 조회
$stmt = $pdo->prepare('SELECT c.*, p.address, p.detail_address FROM contracts c JOIN properties p ON c.property_id = p.id WHERE c.id = ?');
$stmt->execute([$contract_id]);
$contract = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$contract) die('계약을 찾을 수 없습니다.');

// 특정 photo_id의 사진 정보 조회
$stmt = $pdo->prepare('SELECT * FROM photos WHERE id = ? AND contract_id = ?');
$stmt->execute([$photo_id, $contract_id]);
$photo = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$photo) die('사진을 찾을 수 없습니다.');

$movein_photos = [];
$moveout_photos = [];

// movein 사진들
for ($i = 1; $i <= 6; $i++) {
    $path = $photo['movein_file_path_0' . $i] ?? null;
    $shot_type = $photo['movein_shot_type_0' . $i] ?? null;
    if ($path) {
        $movein_photos[] = [
            'path' => $path,
            'type' => $shot_type,
            'index' => $i,
            'photo_id' => $photo['id']
        ];
    }
}

// moveout 사진들
for ($i = 1; $i <= 6; $i++) {
    $path = $photo['moveout_file_path_0' . $i] ?? null;
    $shot_type = $photo['moveout_shot_type_0' . $i] ?? null;
    if ($path) {
        $moveout_photos[] = [
            'path' => $path,
            'type' => $shot_type,
            'index' => $i,
            'photo_id' => $photo['id']
        ];
    }
}

// 해당 타입과 인덱스의 입주 사진 찾기
$target_photo = null;
foreach ($movein_photos as $photo) {
    if ($photo['type'] === $type && $photo['index'] === $index) {
        $target_photo = $photo;
        break;
    }
}

if (!$target_photo) {
    die('해당 사진을 찾을 수 없습니다.');
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
    <title>사진 촬영 - <?php echo htmlspecialchars($contract['address']); ?> - 무빙체크</title>
    <link rel="stylesheet" href="style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Noto Sans KR', sans-serif;
            background: #000;
            color: #fff;
            overflow-y: auto;
            min-height: 100vh;
            min-height: -webkit-fill-available;
        }
        
        .camera-page {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            min-height: -webkit-fill-available;
        }
        

        
        /* 메인 컨테이너 */
        .main-container {
            display: flex;
            flex: 1;
        }
        
        /* 참고 사진 영역 */
        .reference-section {
            flex: 0 0 30%;
            background: #111;
            display: flex;
            flex-direction: column;
            padding: 0.5rem;
            border-right: 1px solid rgba(255,255,255,0.1);
            align-items: center;
            justify-content: center;
        }
        
        /* 참고 사진 제목 섹션 */
        .reference-title-section {
            background: rgba(0,0,0,0.9);
            padding: 0.3rem;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            flex-shrink: 0;
        }
        
        .reference-title {
            font-size: 1.4rem;
            margin: 0;
            text-align: center;
            color: #1976d2;
            font-weight: 600;
        }
        
        .reference-photo-container {
            display: flex;
            align-items: center;
            justify-content: center;
            background: #000;
            border-radius: 8px;
            overflow: hidden;
            width: 400px;
            height: 300px;
            margin: 0 auto;
            /* 비율은 JavaScript에서 동적으로 설정됨 */
        }
        
        .reference-photo {
            max-width: 95%;
            max-height: 95%;
            object-fit: contain;
            border-radius: 8px;
        }
        
        /* 카메라 영역 */
        .camera-section {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: #000;
        }
        
        .camera-container {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 0.5rem;
            position: relative;
        }
        
        .camera-viewport {
            width: 400px;
            height: 300px;
            max-width: 95%;
            max-height: 70%;
            position: relative;
            border-radius: 8px;
            overflow: hidden;
            background: #000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.5);
            /* 비율은 JavaScript에서 동적으로 설정됨 */
        }
        
        .camera-video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 8px;
        }
        
        .overlay-image {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 8px;
            opacity: 0.5;
            pointer-events: none;
            z-index: 10;
        }
        
        /* 컨트롤 영역 */
        .controls-section {
            background: rgba(0,0,0,0.9);
            padding: 1.5rem;
            border-top: 1px solid rgba(255,255,255,0.1);
            flex-shrink: 0;
            margin-top: 0.5rem;
        }
        
        .opacity-control {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .opacity-control label {
            font-size: 0.9rem;
            white-space: nowrap;
        }
        
        .opacity-control input {
            width: 100px;
        }
        
        .opacity-value {
            color: #1976d2;
            font-weight: 600;
            min-width: 30px;
        }
        
        .camera-controls {
            display: flex;
            justify-content: center;
            gap: 1rem;
            align-items: center;
        }
        
        .camera-btn {
            border: none;
            border-radius: 50px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            padding: 0.8rem 1.5rem;
        }
        
        .camera-capture {
            background: #fff;
            color: #000;
            padding: 0.8rem 1.5rem;
            border-radius: 25px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        
        .camera-cancel {
            background: rgba(255, 255, 255, 0.2);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        
        /* 결과 확인 모드 */
        .result-container {
            background: rgba(0,0,0,0.95);
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            margin-top: 1rem;
            border-radius: 8px;
            min-height: 400px;
        }
        
        .result-content {
            text-align: center;
            width: 100%;
        }
        
        .result-title {
            font-size: 1.3rem;
            margin-bottom: 1.5rem;
            color: #fff;
        }
        
        .result-photo {
            max-width: 400px;
            max-height: 400px;
            width: auto;
            height: auto;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            margin-bottom: 2rem;
            object-fit: contain;
        }
        
        .result-controls {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            align-items: center;
        }
        
        .result-buttons-row {
            display: flex;
            gap: 1rem;
            justify-content: center;
        }
        
        .result-btn {
            border: none;
            border-radius: 25px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            padding: 0.8rem 1.5rem;
            min-width: 100px;
        }
        
        .result-save {
            background: #28a745;
            color: #fff;
        }
        
        .result-retake {
            background: #ffc107;
            color: #000;
        }
        
        .result-cancel {
            background: rgba(255, 255, 255, 0.2);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        
        /* 로딩 및 에러 */
        .loading {
            text-align: center;
            padding: 2rem;
            font-size: 1.1rem;
        }
        
        .error {
            text-align: center;
            background: rgba(255, 107, 107, 0.1);
            padding: 2rem;
            border-radius: 8px;
            margin: 1rem;
            border: 1px solid rgba(255, 107, 107, 0.3);
        }
        
        /* 모바일 반응형 */
        @media (max-width: 768px) {
            .main-container {
                flex-direction: column;
            }
            
            .reference-section {
                flex: 0 0 20%;
                border-right: none;
                border-bottom: 1px solid rgba(255,255,255,0.1);
                padding: 0.3rem;
                align-items: center;
                justify-content: center;
                position: relative;
            }
            
            .reference-title {
                font-size: 1.1rem;
                margin: 0;
            }
            
            .reference-photo-container {
                width: 320px;
                height: 240px;
                /* 비율은 JavaScript에서 동적으로 설정됨 */
            }
            
            .reference-photo {
                max-width: 95%;
                max-height: 95%;
            }
            
            .camera-viewport {
                max-width: 95%;
                max-height: 60%;
                /* 비율은 JavaScript에서 동적으로 설정됨 */
            }
            

            
            .controls-section {
                padding: 0.8rem;
                margin-top: 0.3rem;
            }
            
            .opacity-control {
                margin-bottom: 0.8rem;
            }
            
            .opacity-control label {
                font-size: 0.8rem;
            }
            
            .camera-capture {
                padding: 0.6rem 1.2rem;
                font-size: 0.9rem;
            }
            
            .camera-cancel {
                padding: 0.6rem 1rem;
                font-size: 0.9rem;
            }
            
            .result-photo {
                max-width: 95%;
                max-height: 300px;
            }
            
            .result-buttons-row {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .result-btn {
                width: 100%;
                max-width: 200px;
            }
        }
        
        /* 세로 모드 */
        @media (max-width: 768px) and (orientation: portrait) {
            .reference-section {
                flex: 0 0 15%;
            }
            
            .reference-photo-container {
                width: 280px;
                height: 210px;
                /* 비율은 JavaScript에서 동적으로 설정됨 */
            }
            
            .reference-photo {
                max-width: 95%;
                max-height: 95%;
            }
            
            .camera-viewport {
                /* 비율은 JavaScript에서 동적으로 설정됨 */
            }
        }
        
        /* 가로 모드 */
        @media (max-width: 1024px) and (orientation: landscape) {
            .main-container {
                flex-direction: row;
            }
            
            .reference-section {
                flex: 0 0 25%;
            }
            
            .camera-section {
                flex-direction: row;
                align-items: stretch;
            }
            
            .camera-container {
                flex: 1;
                justify-content: center;
            }
            
            .controls-section {
                flex: 0 0 120px;
                margin-top: 0;
                margin-left: 0.5rem;
                border-top: none;
                border-left: 1px solid rgba(255,255,255,0.1);
                display: flex;
                flex-direction: column;
                justify-content: center;
                padding: 1rem 0.8rem;
            }
            
            .opacity-control {
                flex-direction: column;
                gap: 0.5rem;
                margin-bottom: 1rem;
            }
            
            .opacity-control label {
                font-size: 0.8rem;
                text-align: center;
            }
            
            .opacity-control input {
                width: 80px;
            }
            
            .camera-controls {
                flex-direction: column;
                gap: 0.8rem;
            }
            
            .camera-btn {
                width: 100%;
                padding: 0.6rem 0.8rem;
                font-size: 0.9rem;
            }
            
            .camera-viewport {
                max-width: 100%;
                max-height: 100%;
            }
        }
        

    </style>
</head>
<body>
    <div class="camera-page">
        <!-- 참고 사진 제목 -->
        <div class="reference-title-section">
            <h2 class="reference-title">입주 시 사진 (참고용)</h2>
        </div>
        
        <!-- 메인 컨테이너 -->
        <div class="main-container">
            <!-- 참고 사진 영역 -->
            <div class="reference-section">
                <div class="reference-photo-container">
                    <img class="reference-photo" src="<?php echo htmlspecialchars($target_photo['path']); ?>" alt="입주 사진">
                </div>
            </div>
            
            <!-- 카메라 영역 -->
            <div class="camera-section">
                
                <div class="camera-container" id="cameraContainer">
                    <div class="loading">
                        <div style="font-size: 2rem; margin-bottom: 1rem;">📷</div>
                        <div>카메라를 준비하고 있습니다...</div>
                    </div>
                </div>
                
                <!-- 컨트롤 영역 -->
                <div class="controls-section">
                    <div class="opacity-control">
                        <label>오버레이 투명도:</label>
                        <input type="range" min="0" max="100" value="0" oninput="adjustOverlay(this.value)">
                        <span class="opacity-value" id="opacityValue">0</span>%
                    </div>
                    
                    <div class="camera-controls">
                        <button class="camera-btn camera-capture" onclick="capturePhoto()">촬영</button>
                        <button class="camera-btn camera-cancel" onclick="cancelCapture()">취소</button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 결과 확인 모드 -->
        <div class="result-container" id="resultContainer">
            <div class="result-content">
                <h3 class="result-title">촬영 완료</h3>
                <img id="resultImage" class="result-photo" alt="촬영된 사진" style="display:none;">
                <div class="result-controls">
                    <div class="result-buttons-row">
                        <button class="result-btn result-save" onclick="savePhoto()">저장</button>
                        <button class="result-btn result-retake" onclick="retakePhoto()">재촬영</button>
                    </div>
                    <button class="result-btn result-cancel" onclick="cancelCapture()">취소</button>
                </div>
            </div>
        </div>
    </div>

    <canvas id="cameraCanvas" style="display:none;"></canvas>

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
        let capturedPhotoData = null;
        let currentImageInfo = null;

        // 페이지 로드 시 카메라 시작
        document.addEventListener('DOMContentLoaded', function() {
            startCamera();
        });
        
        // 화면 회전 시 카메라 뷰포트 재조정
        window.addEventListener('orientationchange', function() {
            setTimeout(function() {
                if (currentImageInfo) {
                    const viewport = adjustCameraViewportToImageRatio(currentImageInfo);
                    const cameraViewport = document.querySelector('.camera-viewport');
                    if (cameraViewport) {
                        cameraViewport.style.width = viewport.width + 'px';
                        cameraViewport.style.height = viewport.height + 'px';
                    }
                    
                    // 참고 사진 컨테이너도 재조정
                    const referenceContainer = document.querySelector('.reference-photo-container');
                    if (referenceContainer) {
                        const isMobile = window.innerWidth <= 768;
                        const isLandscape = window.innerWidth > window.innerHeight;
                        
                        if (isMobile && isLandscape) {
                            const refWidth = Math.min(viewport.width * 0.8, 200);
                            const refHeight = refWidth / currentImageInfo.aspectRatio;
                            referenceContainer.style.width = refWidth + 'px';
                            referenceContainer.style.height = refHeight + 'px';
                        } else {
                            referenceContainer.style.width = viewport.width + 'px';
                            referenceContainer.style.height = viewport.height + 'px';
                        }
                    }
                }
            }, 500); // 회전 완료 후 0.5초 대기
        });

        // 카메라 시작
        async function startCamera() {
            const container = document.getElementById('cameraContainer');
            
            try {
                // 입주 사진 비율 분석
                const referenceImg = document.querySelector('.reference-photo');
                currentImageInfo = await analyzeImageAspectRatio(referenceImg);
                
                // 입주 사진 비율에 맞춰 카메라 뷰포트 크기 계산
                const viewport = adjustCameraViewportToImageRatio(currentImageInfo);
                
                // 참고 사진 컨테이너도 동일한 크기로 조정
                const referenceContainer = document.querySelector('.reference-photo-container');
                if (referenceContainer) {
                    // 가로 모드에서는 참고 사진을 더 작게 표시
                    const isMobile = window.innerWidth <= 768;
                    const isLandscape = window.innerWidth > window.innerHeight;
                    
                    if (isMobile && isLandscape) {
                        // 가로 모드: 참고 사진을 더 작게
                        const refWidth = Math.min(viewport.width * 0.8, 200);
                        const refHeight = refWidth / currentImageInfo.aspectRatio;
                        referenceContainer.style.width = refWidth + 'px';
                        referenceContainer.style.height = refHeight + 'px';
                    } else {
                        // 세로 모드: 기존 방식
                        referenceContainer.style.width = viewport.width + 'px';
                        referenceContainer.style.height = viewport.height + 'px';
                    }
                }
                
                // 카메라 권한 요청
                const stream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: 'environment',
                        width: { ideal: 640, min: 320 },
                        height: { ideal: 480, min: 240 }
                    }
                });
                
                currentStream = stream;
                
                // 카메라 UI 생성
                container.innerHTML = `
                    <div class="camera-viewport" style="width: ${viewport.width}px; height: ${viewport.height}px;">
                        <video class="camera-video" autoplay playsinline muted></video>
                        <img class="overlay-image" src="<?php echo htmlspecialchars($target_photo['path']); ?>" alt="입주 사진 오버레이" style="opacity: 0;">
                    </div>
                `;
                
                const video = container.querySelector('.camera-video');
                video.srcObject = stream;
                
                // 비디오 로드 완료 후 크기 조정
                video.addEventListener('loadedmetadata', function() {
                    console.log('비디오 크기:', video.videoWidth, 'x', video.videoHeight);
                    console.log('입주 사진 비율:', currentImageInfo.aspectRatio);
                    console.log('카메라 뷰포트 크기:', viewport.width, 'x', viewport.height);
                    if (video.videoWidth > 0 && video.videoHeight > 0) {
                        video.style.display = 'block';
                    }
                });
                
            } catch (error) {
                console.error('카메라 접근 오류:', error);
                
                let errorMessage = '카메라에 접근할 수 없습니다.\n\n';
                
                if (error.name === 'NotAllowedError') {
                    errorMessage += '카메라 권한이 거부되었습니다. 브라우저 설정에서 카메라 권한을 허용해주세요.';
                } else if (error.name === 'NotFoundError') {
                    errorMessage += '카메라를 찾을 수 없습니다. 카메라가 연결되어 있는지 확인해주세요.';
                } else {
                    errorMessage += '오류: ' + error.message;
                }
                
                container.innerHTML = `
                    <div class="error">
                        <h3>📷 카메라 사용 불가</h3>
                        <p>${errorMessage}</p>
                        <button class="camera-btn camera-cancel" onclick="cancelCapture()" style="margin-top: 1rem;">
                            돌아가기
                        </button>
                    </div>
                `;
            }
        }

        // 이미지 비율 분석
        function analyzeImageAspectRatio(imgElement) {
            return new Promise((resolve) => {
                if (imgElement.complete && imgElement.naturalWidth && imgElement.naturalHeight) {
                    const width = imgElement.naturalWidth;
                    const height = imgElement.naturalHeight;
                    const aspectRatio = width / height;
                    
                    resolve({
                        width: width,
                        height: height,
                        aspectRatio: aspectRatio,
                        isPortrait: height > width,
                        isLandscape: width > height,
                        isSquare: Math.abs(aspectRatio - 1) < 0.1
                    });
                } else {
                    imgElement.onload = () => {
                        const width = imgElement.naturalWidth;
                        const height = imgElement.naturalHeight;
                        const aspectRatio = width / height;
                        
                        resolve({
                            width: width,
                            height: height,
                            aspectRatio: aspectRatio,
                            isPortrait: height > width,
                            isLandscape: width > height,
                            isSquare: Math.abs(aspectRatio - 1) < 0.1
                        });
                    };
                }
            });
        }

        // 입주 사진 비율에 맞춰 카메라 뷰포트 크기 계산
        function adjustCameraViewportToImageRatio(imageInfo) {
            // 모바일 여부 확인
            const isMobile = window.innerWidth <= 768;
            const isLandscape = window.innerWidth > window.innerHeight;
            
            // 가로 모드에서 컨트롤이 오른쪽에 있을 때 카메라 영역 계산
            let availableWidth, availableHeight;
            
            if (isMobile && isLandscape) {
                // 가로 모드: 컨트롤이 오른쪽에 있으므로 카메라 영역이 더 넓어짐
                availableWidth = window.innerWidth * 0.7; // 70% (참고사진 25% + 컨트롤 5% 제외)
                availableHeight = window.innerHeight * 0.9; // 90% (제목 영역 제외)
            } else {
                // 세로 모드: 기존 방식
                availableWidth = window.innerWidth * 0.95;
                availableHeight = window.innerHeight * (isMobile ? 0.6 : 0.7);
            }
            
            // 기본 크기 설정
            const baseWidth = isMobile ? 320 : 400;
            const baseHeight = isMobile ? 240 : 300;
            
            let viewportWidth, viewportHeight;
            
            if (imageInfo.aspectRatio > 1) {
                // 가로형 이미지 (landscape)
                viewportWidth = Math.min(baseWidth, availableWidth);
                viewportHeight = viewportWidth / imageInfo.aspectRatio;
                
                // 높이가 최대 높이를 초과하면 높이 기준으로 조정
                if (viewportHeight > availableHeight) {
                    viewportHeight = availableHeight;
                    viewportWidth = viewportHeight * imageInfo.aspectRatio;
                }
            } else {
                // 세로형 이미지 (portrait)
                viewportHeight = Math.min(baseHeight, availableHeight);
                viewportWidth = viewportHeight * imageInfo.aspectRatio;
                
                // 너비가 최대 너비를 초과하면 너비 기준으로 조정
                if (viewportWidth > availableWidth) {
                    viewportWidth = availableWidth;
                    viewportHeight = viewportWidth / imageInfo.aspectRatio;
                }
            }
            
            // 정수로 반올림
            return {
                width: Math.round(viewportWidth),
                height: Math.round(viewportHeight)
            };
        }

        // 사진 촬영
        function capturePhoto() {
            const video = document.querySelector('.camera-video');
            const canvas = document.getElementById('cameraCanvas');
            const context = canvas.getContext('2d');
            
            // 입주 사진 비율에 맞춰 캔버스 크기 설정
            if (currentImageInfo) {
                // 입주 사진의 비율을 유지하면서 적절한 크기로 설정
                let canvasWidth, canvasHeight;
                
                if (currentImageInfo.aspectRatio > 1) {
                    // 가로형 이미지
                    canvasWidth = 1920; // 기본 가로 해상도
                    canvasHeight = Math.round(canvasWidth / currentImageInfo.aspectRatio);
                } else {
                    // 세로형 이미지
                    canvasHeight = 1920; // 기본 세로 해상도
                    canvasWidth = Math.round(canvasHeight * currentImageInfo.aspectRatio);
                }
                
                canvas.width = canvasWidth;
                canvas.height = canvasHeight;
                
                // 비디오 프레임을 캔버스에 그리기 (입주 사진 비율에 맞춰 크롭)
                const videoAspectRatio = video.videoWidth / video.videoHeight;
                let sourceX = 0, sourceY = 0, sourceWidth = video.videoWidth, sourceHeight = video.videoHeight;
                
                if (videoAspectRatio > currentImageInfo.aspectRatio) {
                    // 비디오가 더 넓음 - 가로를 크롭
                    sourceWidth = Math.round(video.videoHeight * currentImageInfo.aspectRatio);
                    sourceX = Math.round((video.videoWidth - sourceWidth) / 2);
                } else if (videoAspectRatio < currentImageInfo.aspectRatio) {
                    // 비디오가 더 좁음 - 세로를 크롭
                    sourceHeight = Math.round(video.videoWidth / currentImageInfo.aspectRatio);
                    sourceY = Math.round((video.videoHeight - sourceHeight) / 2);
                }
                
                context.drawImage(video, sourceX, sourceY, sourceWidth, sourceHeight, 0, 0, canvasWidth, canvasHeight);
            } else {
                // fallback: 기존 방식
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                context.drawImage(video, 0, 0, canvas.width, canvas.height);
            }
            
            // 캔버스에서 이미지 데이터 추출
            const imageData = canvas.toDataURL('image/jpeg', CAMERA_CONFIG.jpegQuality);
            capturedPhotoData = imageData;
            
            // 촬영된 사진 표시
            const resultImg = document.getElementById('resultImage');
            resultImg.src = imageData;
            resultImg.style.display = 'block';
            
            // 메인 컨테이너 숨기기 (카메라와 참고 사진)
            const mainContainer = document.querySelector('.main-container');
            mainContainer.style.display = 'none';
            
            // 결과 컨테이너 표시
            const resultContainer = document.getElementById('resultContainer');
            resultContainer.style.display = 'flex';
            
            // 결과 컨테이너로 스크롤
            resultContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
            
            // 카메라 정지
            if (currentStream) {
                currentStream.getTracks().forEach(track => track.stop());
                currentStream = null;
            }
        }

        // 오버레이 투명도 조절
        function adjustOverlay(value) {
            const overlayImage = document.querySelector('.overlay-image');
            if (overlayImage) {
                overlayImage.style.opacity = value / 100;
            }
            document.getElementById('opacityValue').textContent = value;
        }

        // 다시 촬영
        function retakePhoto() {
            // 결과 컨테이너 숨기기
            const resultContainer = document.getElementById('resultContainer');
            resultContainer.style.display = 'none';
            
            // 메인 컨테이너 다시 표시
            const mainContainer = document.querySelector('.main-container');
            mainContainer.style.display = 'flex';
            
            // 촬영된 사진 데이터 삭제
            capturedPhotoData = null;
            
            // 카메라 다시 시작
            startCamera();
        }

        // 사진 저장
        function savePhoto() {
            if (!capturedPhotoData) {
                alert('저장할 사진이 없습니다.');
                return;
            }
            
            // 저장 중 표시
            const saveBtn = event.target;
            const originalText = saveBtn.textContent;
            saveBtn.textContent = '저장 중...';
            saveBtn.disabled = true;
            
            // AJAX로 사진 저장
            const formData = new FormData();
            formData.append('contract_id', '<?php echo $contract_id; ?>');
            formData.append('photo_id', '<?php echo $photo_id; ?>');
            formData.append('type', '<?php echo $type; ?>');
            formData.append('index', '<?php echo $index; ?>');
            formData.append('photo_data', capturedPhotoData);
            
            fetch('save_moveout_photo.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // moveout_photo.php로 돌아가기 - 촬영한 사진 위치 정보 포함
                    window.location.href = 'moveout_photo.php?contract_id=<?php echo $contract_id; ?>&photo_id=<?php echo $photo_id; ?>&captured_type=<?php echo $type; ?>&captured_index=<?php echo $index; ?>';
                } else {
                    alert('저장 실패: ' + (data.message || '알 수 없는 오류'));
                    saveBtn.textContent = originalText;
                    saveBtn.disabled = false;
                }
            })
            .catch(error => {
                console.error('저장 오류:', error);
                alert('저장 중 오류가 발생했습니다.');
                saveBtn.textContent = originalText;
                saveBtn.disabled = false;
            });
        }

        // 취소
        function cancelCapture() {
            // 카메라 정지
            if (currentStream) {
                currentStream.getTracks().forEach(track => track.stop());
                currentStream = null;
            }
            
            // 촬영된 사진이 있으면 captured_type, 없으면 scroll_type 사용
            if (capturedPhotoData) {
                // 결과 모드에서 취소 - 촬영된 사진 위치로 스크롤
                window.location.href = 'moveout_photo.php?contract_id=<?php echo $contract_id; ?>&photo_id=<?php echo $photo_id; ?>&captured_type=<?php echo $type; ?>&captured_index=<?php echo $index; ?>';
            } else {
                // 촬영 모드에서 취소 - 촬영하려던 사진 위치로 스크롤
                window.location.href = 'moveout_photo.php?contract_id=<?php echo $contract_id; ?>&photo_id=<?php echo $photo_id; ?>&scroll_type=<?php echo $type; ?>&scroll_index=<?php echo $index; ?>';
            }
        }

        // 페이지 떠날 때 카메라 정지
        window.addEventListener('beforeunload', function() {
            if (currentStream) {
                currentStream.getTracks().forEach(track => track.stop());
            }
        });
    </script>
</body>
</html> 