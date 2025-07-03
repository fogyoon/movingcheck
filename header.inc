<?php
require_once 'config.inc';
?>
<header class="mc-header">
  <div class="mc-header-left">
    <a href="index.php" style="text-decoration: none; display: flex; align-items: center; gap: 0.5rem;">
      <span class="mc-logo-svg" aria-label="로고">
        <img src="mc_logo.svg" alt="로고" width="120" height="120" style="display: block;" />
      </span>
      <!--<span class="mc-header-title"><?php echo SITE_TITLE; ?></span>-->
    </a>
  </div>
  <button class="mc-hamburger" id="mc-hamburger-btn" aria-label="메뉴 열기" aria-controls="mc-mobile-menu" aria-expanded="false">
    <span></span><span></span><span></span>
  </button>
  <nav class="mc-header-menu" id="mc-header-menu">
    <?php if ($isLoggedIn): ?>
      <?php if ($_SESSION['usergroup'] === 'admin'): ?>
        <a href="admin.php" class="mc-login-link">관리자</a>
        <a href="user_activities.php" class="mc-login-link">활동기록</a>
        <a href="develop.php" class="mc-login-link">개발도구</a>
        <a href="#" onclick="openInquiryModal(); return false;" class="mc-login-link">문의하기</a>
        <a href="logout.php" class="mc-login-link">로그아웃</a>
      <?php else: ?>
        <a href="properties.php" class="mc-login-link">내 임대물</a>
        <a href="#" onclick="openInquiryModal(); return false;" class="mc-login-link">문의하기</a>
        <a href="logout.php" class="mc-login-link">로그아웃</a>
      <?php endif; ?>
    <?php else: ?>
      <a href="#" onclick="openInquiryModal(); return false;" class="mc-login-link">문의하기</a>
      <a href="login.php" class="mc-login-link">로그인</a>
    <?php endif; ?>
  </nav>
  <nav class="mc-mobile-menu" id="mc-mobile-menu" style="display:none;">
    <?php if ($isLoggedIn): ?>
      <?php if ($_SESSION['usergroup'] === 'admin'): ?>
        <a href="admin.php" class="mc-login-link">관리자</a>
        <a href="user_activities.php" class="mc-login-link">활동기록</a>
        <a href="develop.php" class="mc-login-link">개발도구</a>
        <a href="#" onclick="openInquiryModal(); return false;" class="mc-login-link">문의하기</a>
        <a href="logout.php" class="mc-login-link">로그아웃</a>
      <?php else: ?>
        <a href="properties.php" class="mc-login-link">내 임대물</a>
        <a href="#" onclick="openInquiryModal(); return false;" class="mc-login-link">문의하기</a>
        <a href="logout.php" class="mc-login-link">로그아웃</a>
      <?php endif; ?>
    <?php else: ?>
      <a href="#" onclick="openInquiryModal(); return false;" class="mc-login-link">문의하기</a>
      <a href="login.php" class="mc-login-link">로그인</a>
    <?php endif; ?>
  </nav>
</header>
<script>
// 햄버거 메뉴 토글
const hamburgerBtn = document.getElementById('mc-hamburger-btn');
const mobileMenu = document.getElementById('mc-mobile-menu');
if (hamburgerBtn && mobileMenu) {
  hamburgerBtn.addEventListener('click', function() {
    const expanded = hamburgerBtn.getAttribute('aria-expanded') === 'true';
    hamburgerBtn.setAttribute('aria-expanded', !expanded);
    mobileMenu.style.display = expanded ? 'none' : 'flex';
    document.body.classList.toggle('mc-mobile-menu-open', !expanded);
  });
  // 메뉴 클릭 시 닫기
  mobileMenu.querySelectorAll('a').forEach(function(link) {
    link.addEventListener('click', function() {
      // 문의하기 링크인 경우 약간의 지연 후 메뉴 닫기 (모달이 열리도록)
      if (link.getAttribute('onclick') && link.getAttribute('onclick').includes('openInquiryModal')) {
        setTimeout(function() {
          mobileMenu.style.display = 'none';
          hamburgerBtn.setAttribute('aria-expanded', 'false');
          document.body.classList.remove('mc-mobile-menu-open');
        }, 100);
      } else {
        mobileMenu.style.display = 'none';
        hamburgerBtn.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('mc-mobile-menu-open');
      }
    });
  });
}
</script> 