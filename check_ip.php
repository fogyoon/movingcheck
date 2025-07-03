<?php
require_once 'config.inc';

// 관리자만 접근 가능 (보안상 중요)
if (!isset($_SESSION['user_id']) || $_SESSION['usergroup'] !== 'admin') {
    header('Location: index.php');
    exit;
}

$current_ip = get_real_ip();
$is_allowed = is_test_ip_allowed();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IP 주소 확인 - <?php echo SITE_TITLE; ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .ip-container {
            max-width: 600px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        .ip-section {
            background: #fff;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .ip-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 1rem;
        }
        .ip-info {
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 1rem;
            margin: 1rem 0;
            font-family: 'Courier New', monospace;
        }
        .ip-address {
            font-size: 1.2rem;
            font-weight: bold;
            color: #0064FF;
        }
        .status-allowed {
            color: #28a745;
            font-weight: 600;
        }
        .status-blocked {
            color: #dc3545;
            font-weight: 600;
        }
        .code-block {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 1rem;
            margin: 1rem 0;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            overflow-x: auto;
        }
        .back-link {
            display: inline-block;
            background: #6c757d;
            color: white;
            padding: 0.8rem 1.5rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            margin-top: 1rem;
        }
        .back-link:hover {
            background: #545b62;
        }
        .note {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 1rem;
            border-radius: 6px;
            margin: 1rem 0;
        }
    </style>
</head>
<body>
    <div class="ip-container">
        <div class="ip-section">
            <h1 class="ip-title">🌐 IP 주소 확인</h1>
            
            <div class="ip-info">
                <div>현재 접속 IP 주소:</div>
                <div class="ip-address"><?php echo htmlspecialchars($current_ip); ?></div>
            </div>
            
            <div style="margin: 1rem 0;">
                <strong>테스트 모드 접근 권한:</strong>
                <?php if ($is_allowed): ?>
                    <span class="status-allowed">✅ 허용됨</span>
                <?php else: ?>
                    <span class="status-blocked">❌ 차단됨</span>
                <?php endif; ?>
            </div>
            
            <div class="note">
                <strong>📝 사용법:</strong><br>
                이 IP 주소를 config.inc의 TEST_ALLOWED_IPS 배열에 추가하면 테스트 모드를 사용할 수 있습니다.
            </div>
            
            <h3>config.inc 설정 예시:</h3>
            <div class="code-block">
define('TEST_ALLOWED_IPS', [<br>
&nbsp;&nbsp;&nbsp;&nbsp;'127.0.0.1',<br>
&nbsp;&nbsp;&nbsp;&nbsp;'::1',<br>
&nbsp;&nbsp;&nbsp;&nbsp;<strong>'<?php echo htmlspecialchars($current_ip); ?>',</strong> // 현재 IP 추가<br>
&nbsp;&nbsp;&nbsp;&nbsp;// 추가 IP 주소들...<br>
]);
            </div>
            
            <h3>현재 설정된 허용 IP 목록:</h3>
            <div class="code-block">
                <?php 
                $allowed_ips = TEST_ALLOWED_IPS;
                if (empty($allowed_ips)): ?>
                    <em>설정된 IP 없음 (모든 IP 허용)</em>
                <?php else: ?>
                    <?php foreach ($allowed_ips as $ip): ?>
                        <?php echo htmlspecialchars($ip); ?><br>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <h3>네트워크 정보:</h3>
            <div class="code-block">
                USER_AGENT: <?php echo htmlspecialchars($_SERVER['HTTP_USER_AGENT'] ?? 'N/A'); ?><br>
                REMOTE_ADDR: <?php echo htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? 'N/A'); ?><br>
                <?php if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])): ?>
                X_FORWARDED_FOR: <?php echo htmlspecialchars($_SERVER['HTTP_X_FORWARDED_FOR']); ?><br>
                <?php endif; ?>
                <?php if (!empty($_SERVER['HTTP_X_REAL_IP'])): ?>
                X_REAL_IP: <?php echo htmlspecialchars($_SERVER['HTTP_X_REAL_IP']); ?><br>
                <?php endif; ?>
                <?php if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])): ?>
                CF_CONNECTING_IP: <?php echo htmlspecialchars($_SERVER['HTTP_CF_CONNECTING_IP']); ?><br>
                <?php endif; ?>
            </div>
            
            <a href="admin.php" class="back-link">← 관리자 페이지로 돌아가기</a>
        </div>
    </div>
</body>
</html> 