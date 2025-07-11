<?php
// 터치 서명 테스트 페이지 - 갤럭시Z 폴더폰 호환성 확인
// DB 없이 누구나 접근 가능한 독립적인 테스트 페이지

// 필요할 때 만 사용할 수 있게 지금은 index.php 로 리다이렉트
header('Location: index.php');
exit;

// 헤더 설정
header('Content-Type: text/html; charset=utf-8');

// 현재 시간 (페이지 로드 시간 표시용)
$load_time = date('Y-m-d H:i:s');

// 이메일 발송 처리
$email_result = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_email') {
    $test_data = json_decode($_POST['test_data'], true);
    
    if ($test_data) {
        $to = 'fogyoon71@gmail.com';
        $subject = '[X 표 따라그리기 테스트] ' . $test_data['device_info']['device_name'] . ' 테스트 결과';
        
        // 이메일 내용 생성
        $message = "=== X 표 따라그리기 테스트 결과 ===\n\n";
        $message .= "테스트 시간: " . date('Y-m-d H:i:s') . "\n";
        $message .= "IP 주소: " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown') . "\n\n";
        
        // 디바이스 정보
        $message .= "=== 디바이스 정보 ===\n";
        $message .= "기기: " . $test_data['device_info']['device_name'] . "\n";
        $message .= "갤럭시Z 폴더폰: " . ($test_data['device_info']['is_galaxy_z_fold'] ? 'YES' : 'NO') . "\n";
        $message .= "User Agent: " . $test_data['device_info']['user_agent'] . "\n";
        $message .= "플랫폼: " . $test_data['device_info']['platform'] . "\n";
        $message .= "화면 크기: " . $test_data['device_info']['screen_size'] . "\n";
        $message .= "뷰포트 크기: " . $test_data['device_info']['viewport_size'] . "\n";
        $message .= "터치 지원: " . ($test_data['device_info']['touch_support'] ? 'YES' : 'NO') . "\n";
        $message .= "최대 터치 포인트: " . $test_data['device_info']['max_touch_points'] . "\n";
        $message .= "디바이스 픽셀 비율: " . $test_data['device_info']['device_pixel_ratio'] . "\n";
        $message .= "화면 방향: " . $test_data['device_info']['orientation'] . "\n\n";
        
        // 테스트 결과
        $message .= "=== 테스트 결과 ===\n";
        $message .= "서명 성공: " . ($test_data['test_result']['signature_success'] ? 'YES' : 'NO') . "\n";
        $message .= "터치 이벤트 발생: " . ($test_data['test_result']['touch_events_detected'] ? 'YES' : 'NO') . "\n";
        $message .= "이미지 생성 성공: " . ($test_data['test_result']['image_generated'] ? 'YES' : 'NO') . "\n";
        if (isset($test_data['test_result']['image_size'])) {
            $message .= "이미지 크기: " . $test_data['test_result']['image_size'] . "\n";
        }
        if (isset($test_data['test_result']['canvas_resolution'])) {
            $message .= "캔버스 해상도: " . $test_data['test_result']['canvas_resolution'] . "\n";
        }
        $message .= "\n";
        
        // 이벤트 로그
        $message .= "=== 이벤트 로그 ===\n";
        if (isset($test_data['event_logs']) && is_array($test_data['event_logs'])) {
            foreach ($test_data['event_logs'] as $log) {
                $message .= $log . "\n";
            }
        }
        $message .= "\n";
        
        // 문제 분석을 위한 추가 정보
        $message .= "=== AI 분석을 위한 추가 정보 ===\n";
        $message .= "터치 이벤트 총 개수: " . (isset($test_data['debug_info']['touch_event_count']) ? $test_data['debug_info']['touch_event_count'] : '0') . "\n";
        $message .= "마지막 터치 좌표: " . (isset($test_data['debug_info']['last_coordinates']) ? $test_data['debug_info']['last_coordinates'] : 'None') . "\n";
        $message .= "캔버스 초기화 성공: " . (isset($test_data['debug_info']['canvas_initialized']) ? ($test_data['debug_info']['canvas_initialized'] ? 'YES' : 'NO') : 'Unknown') . "\n";
        $message .= "\n=== X 표 정확도 분석 ===\n";
        $message .= "X 표 중앙 좌표: " . (isset($test_data['debug_info']['x_guide_center']) ? $test_data['debug_info']['x_guide_center'] : 'N/A') . "\n";
        $message .= "X 표 크기: " . (isset($test_data['debug_info']['x_guide_size']) ? $test_data['debug_info']['x_guide_size'] : 'N/A') . "px\n";
        $message .= "좌표 정확도: " . (isset($test_data['debug_info']['accuracy_analysis']) ? $test_data['debug_info']['accuracy_analysis'] : 'N/A') . "\n";
        $message .= "좌표 분석: " . (isset($test_data['debug_info']['coordinate_analysis']) ? $test_data['debug_info']['coordinate_analysis'] : 'N/A') . "\n";
        $message .= "총 좌표 개수: " . (isset($test_data['debug_info']['total_coordinates']) ? $test_data['debug_info']['total_coordinates'] : '0') . "\n";
        
        // 삼성 Z 폴더 특별 정보
        if (isset($test_data['device_info']['fold_detected']) && $test_data['device_info']['fold_detected']) {
            $message .= "\n=== 삼성 Z 폴더 특별 정보 ===\n";
            $message .= "폴더 감지: YES\n";
            $message .= "캔버스 표시 크기: " . (isset($test_data['device_info']['canvas_rect']) ? $test_data['device_info']['canvas_rect'] : 'N/A') . "\n";
            $message .= "캔버스 실제 해상도: " . (isset($test_data['device_info']['canvas_resolution']) ? $test_data['device_info']['canvas_resolution'] : 'N/A') . "\n";
        }
        
        // 이메일 헤더
        $headers = "From: noreply@" . $_SERVER['HTTP_HOST'] . "\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
        
        // 이메일 발송
        if (mail($to, $subject, $message, $headers)) {
            $email_result = 'success';
        } else {
            $email_result = 'error';
        }
    } else {
        $email_result = 'invalid_data';
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>터치 서명 테스트 - 갤럭시Z 폴더폰 호환성 확인</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Noto Sans KR', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f8f9fa;
            color: #333;
            line-height: 1.6;
        }
        
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding: 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            color: #1976d2;
            font-size: 1.5rem;
            margin-bottom: 10px;
        }
        
        .header p {
            color: #666;
            font-size: 0.9rem;
        }
        
        .load-time {
            font-size: 0.8rem;
            color: #999;
            margin-top: 10px;
        }
        
        .device-info {
            background: #e3f2fd;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.85rem;
        }
        
        .device-info h3 {
            color: #1976d2;
            margin-bottom: 10px;
        }
        
        .sign-container {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .sign-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: #1976d2;
            margin-bottom: 15px;
            text-align: center;
        }
        
        .sign-pad-wrap {
            background: #f4f8ff;
            border: 2px dashed #b3c6e0;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .sign-pad {
            background: white;
            border: 2px solid #e3eaf2;
            border-radius: 8px;
            width: 100%;
            height: 200px;
            touch-action: none;
            -webkit-user-select: none;
            -moz-user-select: none;
            -ms-user-select: none;
            user-select: none;
            cursor: crosshair;
            -webkit-touch-callout: none;
            -webkit-tap-highlight-color: transparent;
            -webkit-overflow-scrolling: touch;
        }
        
        .sign-btns {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .btn {
            flex: 1;
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: #1976d2;
            color: white;
        }
        
        .btn-primary:hover {
            background: #1565c0;
        }
        
        .btn-secondary {
            background: #e0e0e0;
            color: #333;
        }
        
        .btn-secondary:hover {
            background: #d0d0d0;
        }
        
        .btn:disabled {
            background: #ccc !important;
            color: #666 !important;
            cursor: not-allowed !important;
        }
        
        .result-container {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .result-image {
            max-width: 100%;
            border: 1px solid #ddd;
            border-radius: 8px;
            margin: 15px 0;
        }
        
        .log-container {
            background: #f5f5f5;
            border-radius: 8px;
            padding: 15px;
            margin-top: 20px;
            max-height: 200px;
            overflow-y: auto;
        }
        
        .log-entry {
            font-family: 'Courier New', monospace;
            font-size: 0.8rem;
            color: #666;
            margin: 2px 0;
        }
        
        .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 8px;
        }
        
        .status-ready {
            background: #4caf50;
        }
        
        .status-drawing {
            background: #ff9800;
        }
        
        .status-error {
            background: #f44336;
        }
        
        .server-info {
            background: #f0f0f0;
            padding: 10px;
            border-radius: 6px;
            margin-top: 15px;
            font-size: 0.8rem;
            color: #666;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            
            .sign-pad {
                height: 150px;
            }
            
            .sign-btns {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🖋️ X 표 따라그리기 테스트</h1>
            <p>갤럭시Z 폴더폰에서 터치 좌표 정확도를 확인하는 테스트 페이지입니다. X 표를 따라 그려보세요.</p>
            <div class="load-time">페이지 로드 시간: <?php echo $load_time; ?></div>
        </div>
        
        <div class="device-info">
            <h3>📱 디바이스 정보</h3>
            <div id="deviceInfo">로딩 중...</div>
            
            <div class="server-info">
                <strong>서버 정보:</strong><br>
                IP: <?php echo $_SERVER['REMOTE_ADDR'] ?? 'Unknown'; ?><br>
                User Agent: <?php echo htmlspecialchars($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'); ?><br>
                서버 시간: <?php echo date('Y-m-d H:i:s'); ?>
            </div>
        </div>
        
        <div class="sign-container">
            <div class="sign-title">X 표 따라그리기 테스트</div>
            <div class="sign-pad-wrap">
                <div style="margin-bottom: 10px; font-size: 0.9rem; color: #666;">
                    <span class="status-indicator" id="statusIndicator"></span>
                    <span id="statusText">준비됨</span>
                </div>
                <canvas id="signPad" class="sign-pad"></canvas>
            </div>
            
            <div class="sign-btns">
                <button class="btn btn-secondary" id="clearBtn">지우기</button>
                <button class="btn btn-primary" id="testBtn">X 표 테스트</button>
            </div>
            
            <div class="sign-btns" style="margin-top: 10px;">
                <button class="btn btn-primary" id="emailBtn">📧 테스트 결과 이메일 발송</button>
            </div>
            
            <div style="text-align: center; margin-top: 15px;">
                <div style="font-size: 0.9rem; color: #666; margin-bottom: 10px;">
                    터치 이벤트 상태: <span id="touchStatus">대기 중</span>
                </div>
                <div style="font-size: 0.8rem; color: #999;">
                    마지막 터치 좌표: <span id="lastCoords">-</span>
                </div>
            </div>
        </div>
        
        <div class="result-container">
            <h3>📊 X 표 따라그리기 결과</h3>
            <div id="resultContent">
                <p style="color: #666; text-align: center; margin: 20px 0;">
                    회색 X 표를 따라 그린 후 "X 표 테스트" 버튼을 눌러주세요.
                </p>
            </div>
            
            <?php if ($email_result): ?>
                <div style="margin-top: 20px; padding: 15px; border-radius: 8px; <?php 
                    if ($email_result === 'success') {
                        echo 'background: #e8f5e8; border: 1px solid #4caf50; color: #2e7d32;';
                    } elseif ($email_result === 'error') {
                        echo 'background: #ffebee; border: 1px solid #f44336; color: #c62828;';
                    } else {
                        echo 'background: #fff3e0; border: 1px solid #ff9800; color: #ef6c00;';
                    }
                ?>">
                    <strong>📧 이메일 발송 결과:</strong><br>
                    <?php 
                        if ($email_result === 'success') {
                            echo '✅ 테스트 결과가 성공적으로 이메일로 전송되었습니다.';
                        } elseif ($email_result === 'error') {
                            echo '❌ 이메일 발송 중 오류가 발생했습니다. 다시 시도해주세요.';
                        } else {
                            echo '⚠️ 테스트 데이터가 올바르지 않습니다. 먼저 서명 테스트를 진행해주세요.';
                        }
                    ?>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="log-container">
            <h4>🔍 이벤트 로그</h4>
            <div id="eventLog"></div>
        </div>
    </div>

    <script>
        // 전역 변수
        let canvas, ctx;
        let isDrawing = false;
        let hasSignature = false;
        let lastX = 0, lastY = 0;
        let eventLog = [];
        let touchEventCount = 0;
        let canvasInitialized = false;
        let lastCoordinates = 'None';
        let testCompleted = false;
        let allTouchCoordinates = []; // 모든 터치 좌표 기록
        
        // 디바이스 정보 수집
        function getDeviceInfo() {
            const info = {
                userAgent: navigator.userAgent,
                platform: navigator.platform,
                devicePixelRatio: window.devicePixelRatio || 1,
                screenWidth: screen.width,
                screenHeight: screen.height,
                viewportWidth: window.innerWidth,
                viewportHeight: window.innerHeight,
                touchSupport: 'ontouchstart' in window,
                maxTouchPoints: navigator.maxTouchPoints || 0,
                orientation: screen.orientation ? screen.orientation.type : 'unknown'
            };
            
            // 갤럭시Z 폴더폰 감지
            const isGalaxyZFold = /SM-F\d+/i.test(navigator.userAgent) || 
                                  /Galaxy.*Fold/i.test(navigator.userAgent);
            
            return { ...info, isGalaxyZFold };
        }
        
        // 로그 추가
        function addLog(message, type = 'info') {
            const timestamp = new Date().toLocaleTimeString();
            const logEntry = `[${timestamp}] ${message}`;
            eventLog.push(logEntry);
            
            const logContainer = document.getElementById('eventLog');
            const logElement = document.createElement('div');
            logElement.className = 'log-entry';
            logElement.textContent = logEntry;
            logContainer.appendChild(logElement);
            
            // 최대 50개 로그만 유지
            if (eventLog.length > 50) {
                eventLog.shift();
                logContainer.removeChild(logContainer.firstChild);
            }
            
            logContainer.scrollTop = logContainer.scrollHeight;
        }
        
        // 상태 업데이트
        function updateStatus(status, indicatorClass) {
            document.getElementById('statusText').textContent = status;
            const indicator = document.getElementById('statusIndicator');
            indicator.className = `status-indicator ${indicatorClass}`;
        }
        
        // 캔버스 초기화
        function initCanvas() {
            canvas = document.getElementById('signPad');
            ctx = canvas.getContext('2d');
            
            resizeCanvas();
            
            // 이벤트 리스너 등록
            canvas.addEventListener('mousedown', handleMouseDown);
            canvas.addEventListener('mousemove', handleMouseMove);
            canvas.addEventListener('mouseup', handleMouseUp);
            canvas.addEventListener('mouseleave', handleMouseUp);
            
            // 터치 이벤트 (passive: false로 기본 동작 방지)
            canvas.addEventListener('touchstart', handleTouchStart, { passive: false });
            canvas.addEventListener('touchmove', handleTouchMove, { passive: false });
            canvas.addEventListener('touchend', handleTouchEnd, { passive: false });
            
            updateStatus('준비됨', 'status-ready');
            addLog('캔버스 초기화 완료');
            canvasInitialized = true;
        }
        
        // 캔버스 크기 조정 (삼성 Z 폴더 호환성 개선)
        function resizeCanvas() {
            const rect = canvas.getBoundingClientRect();
            const dpr = window.devicePixelRatio || 1;
            
            // 현재 캔버스 내용 보존
            const savedImageData = preserveCanvasContent();
            
            canvas.width = rect.width * dpr;
            canvas.height = rect.height * dpr;
            
            // 삼성 Z 폴더 호환성을 위한 개선된 초기화
            ctx.setTransform(1, 0, 0, 1, 0, 0);
            ctx.scale(dpr, dpr);
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            ctx.strokeStyle = '#1976d2';
            ctx.lineWidth = 2.5;
            
            // 캔버스 스타일 설정
            canvas.style.width = rect.width + 'px';
            canvas.style.height = rect.height + 'px';
            
            // X 표 그리기 (테스트용 가이드라인)
            drawXGuide();
            
            // 이전 내용 복원
            restoreCanvasContent(savedImageData);
        }
        
        // X 표 가이드라인 그리기
        function drawXGuide() {
            const rect = canvas.getBoundingClientRect();
            const centerX = rect.width / 2;
            const centerY = rect.height / 2;
            const size = Math.min(rect.width, rect.height) * 0.3; // 캔버스 크기의 30%
            
            // 가이드라인 스타일 설정
            ctx.save();
            ctx.strokeStyle = '#e0e0e0';
            ctx.lineWidth = 1;
            ctx.setLineDash([5, 5]);
            
            // X 표 그리기
            ctx.beginPath();
            // 왼쪽 위에서 오른쪽 아래로
            ctx.moveTo(centerX - size/2, centerY - size/2);
            ctx.lineTo(centerX + size/2, centerY + size/2);
            // 왼쪽 아래에서 오른쪽 위로
            ctx.moveTo(centerX - size/2, centerY + size/2);
            ctx.lineTo(centerX + size/2, centerY - size/2);
            ctx.stroke();
            
            // 중앙 점 그리기
            ctx.beginPath();
            ctx.arc(centerX, centerY, 3, 0, 2 * Math.PI);
            ctx.fillStyle = '#e0e0e0';
            ctx.fill();
            
            ctx.restore();
            
            // 가이드라인 정보 로그
            addLog(`X 가이드라인 그리기 완료 - 중앙: (${centerX.toFixed(1)}, ${centerY.toFixed(1)}), 크기: ${size.toFixed(1)}`);
        }
        
        // 캔버스 내용 보존 및 복원
        function preserveCanvasContent() {
            if (canvas.width > 0 && canvas.height > 0) {
                return ctx.getImageData(0, 0, canvas.width, canvas.height);
            }
            return null;
        }
        
        function restoreCanvasContent(imageData) {
            if (imageData && hasSignature) {
                try {
                    ctx.putImageData(imageData, 0, 0);
                    addLog('캔버스 내용 복원 완료');
                    return true;
                } catch (e) {
                    addLog('캔버스 내용 복원 실패: ' + e.message);
                    return false;
                }
            }
            return false;
        }
        
        // 좌표 계산 (삼성 Z 폴더 호환성 개선)
        function getCoordinates(e) {
            const rect = canvas.getBoundingClientRect();
            const dpr = window.devicePixelRatio || 1;
            
            const scaleX = (canvas.width / dpr) / rect.width;
            const scaleY = (canvas.height / dpr) / rect.height;
            
            let clientX, clientY;
            
            if (e.touches && e.touches.length > 0) {
                clientX = e.touches[0].clientX;
                clientY = e.touches[0].clientY;
            } else {
                clientX = e.clientX;
                clientY = e.clientY;
            }
            
            return {
                x: (clientX - rect.left) * scaleX,
                y: (clientY - rect.top) * scaleY
            };
        }
        
        // 드로잉 시작
        function startDrawing(e) {
            isDrawing = true;
            const coords = getCoordinates(e);
            lastX = coords.x;
            lastY = coords.y;
            lastCoordinates = `${coords.x.toFixed(1)}, ${coords.y.toFixed(1)}`;
            
            // 터치 좌표 기록
            allTouchCoordinates.push({
                x: coords.x,
                y: coords.y,
                type: 'start',
                timestamp: Date.now()
            });
            
            ctx.beginPath();
            ctx.moveTo(coords.x, coords.y);
            
            updateStatus('그리는 중', 'status-drawing');
            document.getElementById('touchStatus').textContent = '그리는 중';
            document.getElementById('lastCoords').textContent = lastCoordinates;
        }
        
        // 드로잉
        function draw(e) {
            if (!isDrawing) return;
            
            const coords = getCoordinates(e);
            
            ctx.lineTo(coords.x, coords.y);
            ctx.stroke();
            
            lastX = coords.x;
            lastY = coords.y;
            hasSignature = true;
            lastCoordinates = `${coords.x.toFixed(1)}, ${coords.y.toFixed(1)}`;
            
            // 터치 좌표 기록
            allTouchCoordinates.push({
                x: coords.x,
                y: coords.y,
                type: 'move',
                timestamp: Date.now()
            });
            
            document.getElementById('lastCoords').textContent = lastCoordinates;
        }
        
        // 드로잉 종료
        function stopDrawing() {
            if (!isDrawing) return;
            
            isDrawing = false;
            updateStatus('준비됨', 'status-ready');
            document.getElementById('touchStatus').textContent = '대기 중';
        }
        
        // 마우스 이벤트 핸들러
        function handleMouseDown(e) {
            e.preventDefault();
            addLog('마우스 다운: ' + e.clientX + ', ' + e.clientY);
            startDrawing(e);
        }
        
        function handleMouseMove(e) {
            if (isDrawing) {
                e.preventDefault();
                draw(e);
            }
        }
        
        function handleMouseUp(e) {
            if (isDrawing) {
                e.preventDefault();
                addLog('마우스 업');
                stopDrawing();
            }
        }
        
        // 터치 이벤트 핸들러 (삼성 Z 폴더 최적화)
        function handleTouchStart(e) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            
            const touch = e.touches[0];
            touchEventCount++;
            addLog(`터치 시작: ${touch.clientX}, ${touch.clientY} (터치 개수: ${e.touches.length})`);
            
            startDrawing(e);
        }
        
        function handleTouchMove(e) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            
            if (isDrawing) {
                touchEventCount++;
                draw(e);
            }
        }
        
        function handleTouchEnd(e) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            
            touchEventCount++;
            addLog('터치 종료');
            stopDrawing();
        }
        
        // 캔버스 클리어
        function clearCanvas() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            hasSignature = false;
            allTouchCoordinates = []; // 좌표 기록 초기화
            addLog('캔버스 초기화');
            updateStatus('준비됨', 'status-ready');
            
            // X 가이드라인 다시 그리기
            drawXGuide();
        }
        
        // 서명 테스트
        function testSignature() {
            if (!hasSignature) {
                alert('X 표를 따라 그려주세요.');
                return;
            }
            
            const imageData = canvas.toDataURL('image/png');
            const resultContainer = document.getElementById('resultContent');
            
            // X 가이드라인 정보 계산
            const rect = canvas.getBoundingClientRect();
            const centerX = rect.width / 2;
            const centerY = rect.height / 2;
            const size = Math.min(rect.width, rect.height) * 0.3;
            
            resultContainer.innerHTML = `
                <div style="margin-bottom: 15px;">
                    <strong>✅ X 표 따라그리기 테스트 성공!</strong>
                    <p style="color: #666; font-size: 0.9rem; margin-top: 5px;">
                        X 표를 따라 그리기가 완료되었습니다. 좌표 정확도를 확인해주세요.
                    </p>
                </div>
                <div style="text-align: center;">
                    <img src="${imageData}" alt="X 표 따라그리기 결과" class="result-image">
                </div>
                <div style="margin-top: 15px; font-size: 0.85rem; color: #666;">
                    <strong>데이터 정보:</strong><br>
                    형식: PNG (Base64)<br>
                    크기: ${Math.round(imageData.length / 1024)}KB<br>
                    해상도: ${canvas.width}x${canvas.height}px<br>
                    <strong>X 가이드라인 정보:</strong><br>
                    중앙 좌표: (${centerX.toFixed(1)}, ${centerY.toFixed(1)})<br>
                    X 표 크기: ${size.toFixed(1)}px<br>
                    마지막 터치 좌표: ${lastCoordinates}
                </div>
            `;
            
            addLog('X 표 따라그리기 테스트 완료 - 이미지 생성 성공');
            testCompleted = true;
        }
        

        
        // 테스트 데이터 수집 (삼성 Z 폴더 상세 정보 추가)
        function collectTestData() {
            const deviceInfo = getDeviceInfo();
            
            // X 가이드라인 정보 계산
            const rect = canvas.getBoundingClientRect();
            const centerX = rect.width / 2;
            const centerY = rect.height / 2;
            const size = Math.min(rect.width, rect.height) * 0.3;
            
            // 마지막 터치 좌표와 중앙점 거리 계산
            let accuracyInfo = 'N/A';
            let coordinateAnalysis = 'N/A';
            
            if (lastCoordinates !== 'None') {
                const coords = lastCoordinates.split(', ');
                const lastX = parseFloat(coords[0]);
                const lastY = parseFloat(coords[1]);
                const distance = Math.sqrt(Math.pow(lastX - centerX, 2) + Math.pow(lastY - centerY, 2));
                const accuracy = Math.max(0, 100 - (distance / (size/2)) * 100);
                accuracyInfo = `${distance.toFixed(1)}px (정확도: ${accuracy.toFixed(1)}%)`;
                
                // 모든 터치 좌표 분석
                if (allTouchCoordinates.length > 0) {
                    const totalPoints = allTouchCoordinates.length;
                    const startPoints = allTouchCoordinates.filter(p => p.type === 'start').length;
                    const movePoints = allTouchCoordinates.filter(p => p.type === 'move').length;
                    
                    // 평균 좌표 계산
                    const avgX = allTouchCoordinates.reduce((sum, p) => sum + p.x, 0) / totalPoints;
                    const avgY = allTouchCoordinates.reduce((sum, p) => sum + p.y, 0) / totalPoints;
                    
                    coordinateAnalysis = `총 ${totalPoints}개 좌표 (시작: ${startPoints}, 이동: ${movePoints}), 평균: (${avgX.toFixed(1)}, ${avgY.toFixed(1)})`;
                }
            }
            
            return {
                device_info: {
                    device_name: deviceInfo.isGalaxyZFold ? '갤럭시Z 폴더폰' : '일반 기기',
                    is_galaxy_z_fold: deviceInfo.isGalaxyZFold,
                    user_agent: deviceInfo.userAgent,
                    platform: deviceInfo.platform,
                    screen_size: `${deviceInfo.screenWidth}x${deviceInfo.screenHeight}`,
                    viewport_size: `${deviceInfo.viewportWidth}x${deviceInfo.viewportHeight}`,
                    touch_support: deviceInfo.touchSupport,
                    max_touch_points: deviceInfo.maxTouchPoints,
                    device_pixel_ratio: deviceInfo.devicePixelRatio,
                    orientation: deviceInfo.orientation,
                    // 삼성 Z 폴더 특별 정보
                    fold_detected: deviceInfo.isGalaxyZFold,
                    canvas_rect: canvas ? `${canvas.getBoundingClientRect().width}x${canvas.getBoundingClientRect().height}` : 'N/A',
                    canvas_resolution: canvas ? `${canvas.width}x${canvas.height}` : 'N/A'
                },
                test_result: {
                    signature_success: hasSignature && testCompleted,
                    touch_events_detected: touchEventCount > 0,
                    image_generated: hasSignature && testCompleted,
                    image_size: (() => {
                        try {
                            return hasSignature ? Math.round(canvas.toDataURL('image/png').length / 1024) + 'KB' : null;
                        } catch (e) {
                            return 'Error generating image';
                        }
                    })(),
                    canvas_resolution: canvas ? `${canvas.width}x${canvas.height}px` : null
                },
                event_logs: eventLog,
                debug_info: {
                    touch_event_count: touchEventCount,
                    last_coordinates: lastCoordinates,
                    canvas_initialized: canvasInitialized,
                    x_guide_center: `${centerX.toFixed(1)}, ${centerY.toFixed(1)}`,
                    x_guide_size: size.toFixed(1),
                    accuracy_analysis: accuracyInfo,
                    coordinate_analysis: coordinateAnalysis,
                    total_coordinates: allTouchCoordinates.length
                }
            };
        }
        
        // 이메일 발송
        function sendTestResultEmail() {
            if (!testCompleted && !hasSignature && touchEventCount === 0) {
                alert('먼저 X 표를 따라 그려보세요. 그리기가 실패해도 문제 분석을 위해 결과를 전송할 수 있습니다.');
                return;
            }
            
            const testData = collectTestData();
            const emailBtn = document.getElementById('emailBtn');
            
            // 버튼 비활성화
            emailBtn.disabled = true;
            emailBtn.textContent = '📧 이메일 발송 중...';
            
            // 폼 데이터 생성
            const formData = new FormData();
            formData.append('action', 'send_email');
            formData.append('test_data', JSON.stringify(testData));
            
            // 이메일 발송 요청
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.ok) {
                    addLog('이메일 발송 요청 완료');
                    alert('이메일 발송이 완료되었습니다. 페이지를 새로고침하여 결과를 확인하세요.');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    throw new Error('이메일 발송 실패');
                }
            })
            .catch(error => {
                console.error('이메일 발송 오류:', error);
                addLog('이메일 발송 오류: ' + error.message);
                alert('이메일 발송 중 오류가 발생했습니다. 다시 시도해주세요.');
            })
            .finally(() => {
                // 버튼 재활성화
                emailBtn.disabled = false;
                emailBtn.textContent = '📧 테스트 결과 이메일 발송';
            });
        }
        
        // 페이지 초기화
        function init() {
            // 디바이스 정보 표시
            const deviceInfo = getDeviceInfo();
            const deviceInfoElement = document.getElementById('deviceInfo');
            
            let infoHtml = `
                <div><strong>기기:</strong> ${deviceInfo.isGalaxyZFold ? '🔶 갤럭시Z 폴더폰 (감지됨)' : '일반 기기'}</div>
                <div><strong>플랫폼:</strong> ${deviceInfo.platform}</div>
                <div><strong>터치 지원:</strong> ${deviceInfo.touchSupport ? '✅ 지원' : '❌ 미지원'}</div>
                <div><strong>최대 터치 포인트:</strong> ${deviceInfo.maxTouchPoints}</div>
                <div><strong>디바이스 픽셀 비율:</strong> ${deviceInfo.devicePixelRatio}</div>
                <div><strong>화면 크기:</strong> ${deviceInfo.screenWidth}x${deviceInfo.screenHeight}</div>
                <div><strong>뷰포트 크기:</strong> ${deviceInfo.viewportWidth}x${deviceInfo.viewportHeight}</div>
                <div><strong>화면 방향:</strong> ${deviceInfo.orientation}</div>
            `;
            
            deviceInfoElement.innerHTML = infoHtml;
            
            // 갤럭시Z 폴더폰 감지 시 특별 메시지
            if (deviceInfo.isGalaxyZFold) {
                addLog('⚠️ 갤럭시Z 폴더폰이 감지되었습니다. 폴더 상태를 확인하세요.');
            }
            
            // 캔버스 초기화
            initCanvas();
            
            // 버튼 이벤트 리스너
            document.getElementById('clearBtn').addEventListener('click', clearCanvas);
            document.getElementById('testBtn').addEventListener('click', testSignature);
            document.getElementById('emailBtn').addEventListener('click', sendTestResultEmail);
            
            // 화면 회전/크기 변경 감지 (삼성 Z 폴더 최적화)
            let resizeTimeout;
            window.addEventListener('resize', () => {
                clearTimeout(resizeTimeout);
                resizeTimeout = setTimeout(() => {
                    resizeCanvas();
                    addLog('화면 크기 변경됨 - 내용 보존됨');
                }, 150); // 150ms 지연으로 연속 이벤트 방지
            });
            
            // 화면 방향 변경 감지 (삼성 Z 폴더 대응)
            if (screen.orientation) {
                screen.orientation.addEventListener('change', () => {
                    setTimeout(() => {
                        resizeCanvas();
                        addLog('화면 방향 변경됨: ' + screen.orientation.type + ' - 내용 보존됨');
                    }, 200);
                });
            }
            
            // 삼성 Z 폴더 특별 감지 및 로그
            if (deviceInfo.isGalaxyZFold) {
                addLog('🔶 삼성 Z 폴더 감지됨 - 폴더 상태 변화에 주의');
                addLog('📱 디바이스 픽셀 비율: ' + deviceInfo.devicePixelRatio);
                addLog('🖥️ 화면 크기: ' + deviceInfo.screenWidth + 'x' + deviceInfo.screenHeight);
                addLog('📐 뷰포트 크기: ' + deviceInfo.viewportWidth + 'x' + deviceInfo.viewportHeight);
            }
            
            addLog('페이지 초기화 완료');
        }
        
        // 페이지 로드 시 초기화
        document.addEventListener('DOMContentLoaded', init);
    </script>
</body>
</html> 