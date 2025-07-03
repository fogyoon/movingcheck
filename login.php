<?php require_once 'sql.inc'; ?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>카카오 계정 로그인 - <?php echo SITE_TITLE; ?></title>
    <link rel="stylesheet" href="style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@400;700;900&display=swap" rel="stylesheet">
    <script src="https://developers.kakao.com/sdk/js/kakao.js"></script>
    <style>
      .login-container {
        max-width: 380px;
        margin: 0 auto;
        margin-top: 7vh;
        background: #fff;
        border-radius: 18px;
        box-shadow: 0 2px 16px rgba(0,100,255,0.07);
        padding: 2.5rem 2rem 2rem 2rem;
        text-align: center;
      }
      .login-title {
        font-size: 1.45rem;
        font-weight: 900;
        color: #222;
        margin-bottom: 1.2rem;
      }
      .login-desc {
        color: #555;
        font-size: 1.05rem;
        margin-bottom: 2.2rem;
        line-height: 1.6;
      }
      .kakao-btn {
        display: inline-block;
        background: #fee500;
        color: #222;
        font-size: 1.13rem;
        font-weight: 700;
        border: none;
        border-radius: 8px;
        padding: 0.95rem 2.5rem;
        margin-bottom: 1.7rem;
        text-decoration: none;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        transition: background 0.18s;
      }
      .kakao-btn:hover {
        background: #ffe066;
        color: #111;
      }
      .login-safe {
        font-size: 0.98rem;
        color: #888;
        margin-bottom: 1.5rem;
      }
      .login-back {
        display: inline-block;
        font-size: 0.98rem;
        color: #1976d2;
        text-decoration: none;
        margin-top: 1.2rem;
      }
      .login-back:hover {
        text-decoration: underline;
      }
    </style>
</head>
<body style="background:#f7fafd;">
<?php include 'header.inc'; ?>
  <div class="login-container">
    <div class="login-title">카카오 계정으로 로그인</div>
    <div class="login-desc">
      무빙체크는 카카오 계정으로 안전하게 로그인할 수 있습니다.<br>
      로그인 후 임대차 사진 기록, 전자서명, 공유 등 모든 서비스를 이용하실 수 있습니다.
    </div>
    <a href="#" class="kakao-btn" onclick="kakaoLogin(); return false;">
      <img src="https://developers.kakao.com/assets/img/about/logos/kakaolink/kakaolink_btn_medium.png" alt="카카오 로고" style="height:1.2em;vertical-align:middle;margin-right:0.5em;">카카오로 로그인
    </a>
    <div class="login-safe">
      카카오 계정 정보는 안전하게 보호되며, 동의 없이 게시글이나 사진이 업로드되지 않습니다.
    </div>
    <a href="index.php" class="login-back">← 메인으로 돌아가기</a>
    <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #eee;">
      <a href="admin_login.php" style="color: #666; text-decoration: none; font-size: 0.9rem;">🔐 관리자 로그인</a>
    </div>
  </div>
  <script>
    Kakao.init('<?php echo KAKAO_JS_KEY; ?>');
    function kakaoLogin() {
      Kakao.Auth.login({
        scope: 'profile_nickname,account_email',
        success: function(authObj) {
          Kakao.API.request({
            url: '/v2/user/me',
            success: function(res) {
              const user = {
                id: res.id,
                nickname: res.kakao_account.profile.nickname,
                email: res.kakao_account.email
              };
              fetch('user_login.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(user)
              })
              .then(response => response.json())
              .then(data => {
                if (data.result === 'ok') {
                  if (data.is_new_user) {
                    window.location.href = 'profile_setup.php';
                  } else {
                    window.location.href = 'index.php';
                  }
                }
              });
            }
          });
        }
      });
    }
  </script>
<?php include 'footer.inc'; ?>
</body>
</html> 