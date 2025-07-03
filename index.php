<?php require_once 'config.inc'; 

// 로그인 상태에 따른 자동 리다이렉트
if ($isLoggedIn) {
    if ($_SESSION['usergroup'] === 'admin') {
        header('Location: admin.php');
        exit;
    } else {
        header('Location: properties.php');
       exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_TITLE; ?></title>
    <meta name="description" content="임대차, 사진으로 투명하게. 무빙체크는 임대인과 임차인을 위한 임대차 사진 기록 및 비교 서비스입니다.">
    <link rel="stylesheet" href="style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@400;700;900&display=swap" rel="stylesheet">
</head>
<body class="main-home">
    <?php include 'header.inc'; ?>
    <section class="hero">
        <div class="hero-bg"></div>
        <div class="hero-content">
            <h1 class="hero-title">임대인과 임차인 모두 안심할 수 있는<br>임대차 사진 기록 및 비교 서비스</h1>
            <a href="login.php" class="mc-btn-main">카카오로 시작하기</a>
        </div>
        <div class="scroll-down-arrow">
            <svg width="80" height="36" viewBox="0 0 80 36" fill="none" xmlns="http://www.w3.org/2000/svg">
                <polyline points="10,10 40,30 70,10" fill="none" stroke="#fff" stroke-width="8" stroke-linecap="round" stroke-linejoin="round" opacity="0.7"/>
            </svg>
        </div>
    </section>
    <section class="features" id="features">
        <div class="feature-block feature-bg1 feature-photo-record">
            <div class="feature-desc">
                <h2>사진 기록</h2>
                <p>임대물 상태를 실제 사진으로 남기고, 계약 종료 시 비교할 수 있습니다.</p>
            </div>
        </div>
        <div class="feature-block feature-bg2">
            <img src="https://images.unsplash.com/photo-1556740772-1a741367b93e?auto=format&fit=crop&w=400&q=80" alt="전자서명 예시" class="feature-img">
            <div class="feature-desc">
                <h2>전자서명</h2>
                <p>임대인 임차인 모두 전자서명하여 분쟁을 최소화합니다.</p>
            </div>
        </div>
        <div class="feature-block feature-bg1">
            <img src="https://images.unsplash.com/photo-1519125323398-675f0ddb6308?auto=format&fit=crop&w=400&q=80" alt="간편 공유 예시" class="feature-img">
            <div class="feature-desc">
                <h2>간편 공유</h2>
                <p>카카오톡 등으로 사진 확인 페이지를 쉽게 공유할 수 있습니다.</p>
            </div>
        </div>
    </section>
    <?php include 'footer.inc'; ?>
</body>
</html> 