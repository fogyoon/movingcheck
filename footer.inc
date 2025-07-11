<?php
// sql.inc가 포함되지 않은 경우를 대비해 조건부 포함
if (!function_exists('get_pdo')) {
    require_once 'sql.inc';
}

// 로그인된 사용자 정보 조회 (문의하기 폼 자동 채우기용)
$current_user_info = null;
if (isset($_SESSION['user_id'])) {
    $pdo = get_pdo();
    if (!is_string($pdo)) {
        try {
            $stmt = $pdo->prepare("SELECT login_id, email, nickname, phone FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$_SESSION['user_id']]);
            $current_user_info = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // 에러 무시
        }
    }
}
?>


  <style>
    /* --- Footer 전체 스타일 --- */
    .pd-footer {
      background: #222;
      padding: 30px 20px;
      font-size: 14px;
      color: #eee;
      line-height: 1.7;
      border-top: 1px solid #444;
      letter-spacing: 0.4px;
    }

    /* 상단 메뉴 영역 */
    .footer-top {
      text-align: center;
      margin-bottom: 20px;
    }

    .footer-links {
      list-style: none;
      padding: 0;
      margin: 0;
      display: inline-flex;
      gap: 30px; /* 링크 간격 2배 */
      flex-wrap: wrap;
      justify-content: center;
    }

    .footer-links li a {
      display: inline-block;
      padding: 6px 14px;
      background-color: #2e2e2e; /* 링크 박스 배경 */
      color: #fff;
      border-radius: 4px;
      text-decoration: none;
      font-weight: 500;
      letter-spacing: 0.6px;
      transition: background-color 0.2s, color 0.2s;
    }

    .footer-links li a:hover {
      background-color: #444;
      color: #ffcc00;
    }

    /* 회사 정보 영역 */
    .footer-info {
      text-align: center;
      color: #ccc;
      letter-spacing: 0.5px;
      max-width: 900px;
      margin: 0 auto;
    }

    .footer-info p {
      margin: 6px 0;
    }

    /* 강조 텍스트 */
    .footer-info strong {
      color: #ddd;
      font-weight: 600;
    }

    /* 저작권 문구 */
    .footer-info .copyright {
      margin-top: 10px;
      font-size: 13px;
      color: #888;
      letter-spacing: 0.4px;
    }

    /* --- 반응형: 화면 가로가 작아질 때만 한 줄에 한 항목씩 --- */
    @media (max-width: 600px) {
      .footer-info p {
        display: block;
        white-space: normal;
        word-break: break-word;
        margin: 10px 0;
      }

      /* 상단 메뉴도 세로 정렬 */
      .footer-links {
        flex-direction: column;
        gap: 12px;
      }

      .footer-links li a {
        display: block;
        padding: 10px 20px;
      }
    }
  </style>



<footer class="pd-footer">
  <div class="footer-top">
    <ul class="footer-links">
      <li><a href="#" onclick="openPrivacyModal()">개인정보처리방침</a></li>
      <li><a href="#" onclick="openTermsModal()">이용약관</a></li>
      <li><a href="#" onclick="openInquiryModal()">문의하기</a></li>
    </ul>
  </div>

  <div class="footer-info">
    <p><strong>상호</strong>: 미소공인중개사사무소 &nbsp;l&nbsp; <strong>대표</strong>: 노상규</p>
    <p></p>
    <p><strong>사업자등록번호</strong>: 105-19-37274</p>

    <p><strong>주소</strong>: 서울시 마포구 월드컵북로54길 25, 1층 110호 (상암동, 상암DMC)</p>


    <p class="copyright">
      &copy; <?php echo date('Y'); ?> <?php echo SITE_TITLE; ?>. All rights reserved.
    </p>
  </div>
</footer>


<!-- 문의하기 모달 -->
<div id="inquiryModal" style="display: none; position: fixed; z-index: 10000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); align-items: center; justify-content: center;">
  <div style="background: white; border-radius: 16px; padding: 2rem; max-width: 500px; width: 90%; margin: 1rem; box-shadow: 0 12px 40px rgba(0, 0, 0, 0.2);">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
      <h3 style="margin: 0; color: #333; font-size: 1.3rem; font-weight: 600;">문의하기</h3>
      <button onclick="closeInquiryModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #666; padding: 0.2rem 0.5rem; border-radius: 4px; line-height: 1;" title="닫기">×</button>
    </div>
    
    <form id="inquiryForm" method="POST" style="margin: 0;">
      <input type="hidden" name="action" value="send_inquiry">
      <input type="hidden" name="current_page" value="">
      <input type="hidden" name="inquiry_token" value="<?php echo bin2hex(random_bytes(16)); ?>">
      
      <!-- Honeypot 필드 (봇 방지용 - 사용자에게는 보이지 않음) -->
      <input type="text" name="website" value="" style="display: none !important; position: absolute; left: -9999px;" tabindex="-1" autocomplete="off">
      
      <div style="margin-bottom: 1rem;">
        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #333;">성명 *</label>
        <input type="text" name="inquiry_name" required 
               style="width: 100%; padding: 0.8rem; border: 2px solid #e9ecef; border-radius: 6px; font-size: 1rem; box-sizing: border-box;"
               placeholder="성명을 입력하세요"
               value="<?php echo $current_user_info ? htmlspecialchars($current_user_info['nickname'] ?? '') : ''; ?>">
      </div>
      
      <div style="margin-bottom: 1rem;">
        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #333;">이메일 *</label>
        <input type="email" name="inquiry_email" required 
               style="width: 100%; padding: 0.8rem; border: 2px solid #e9ecef; border-radius: 6px; font-size: 1rem; box-sizing: border-box;"
               placeholder="회신받을 이메일 주소"
               value="<?php echo $current_user_info ? htmlspecialchars($current_user_info['email'] ?? '') : ''; ?>">
      </div>
      
      <div style="margin-bottom: 1rem;">
        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #333;">연락처</label>
        <div class="phone-input-group">
          <input type="text" class="phone-input" name="inquiry_phone_1" id="inquiry_phone_1" maxlength="3" inputmode="numeric">
          <span class="phone-separator">-</span>
          <input type="text" class="phone-input" name="inquiry_phone_2" id="inquiry_phone_2" maxlength="4" inputmode="numeric">
          <span class="phone-separator">-</span>
          <input type="text" class="phone-input" name="inquiry_phone_3" id="inquiry_phone_3" maxlength="4" inputmode="numeric">
          <input type="hidden" name="inquiry_phone" id="inquiry_phone" value="<?php echo $current_user_info ? htmlspecialchars($current_user_info['phone'] ?? '') : ''; ?>">
        </div>
      </div>
      
      <div style="margin-bottom: 1rem;">
        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #333;">문의 내용 *</label>
        <textarea name="inquiry_content" required rows="5"
                  style="width: 100%; padding: 0.8rem; border: 2px solid #e9ecef; border-radius: 6px; font-size: 1rem; box-sizing: border-box; resize: vertical;"
                  placeholder="문의하실 내용을 상세히 입력해주세요"></textarea>
      </div>
      
      <!-- 스팸 방지 CAPTCHA -->
      <?php
      $captcha_num1 = rand(1, 10);
      $captcha_num2 = rand(1, 10);
      $captcha_answer = $captcha_num1 + $captcha_num2;
      ?>
      <div style="margin-bottom: 1.5rem;">
        <label style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #333;">스팸 방지 확인 *</label>
        <div style="display: flex; align-items: center; gap: 0.5rem;">
          <span class="captcha-question" style="font-size: 1.1rem; font-weight: 600;"><?php echo $captcha_num1; ?> + <?php echo $captcha_num2; ?> = ?</span>
          <input type="number" name="captcha_answer" required min="0" max="20"
                 style="width: 80px; padding: 0.8rem; border: 2px solid #e9ecef; border-radius: 6px; font-size: 1rem; text-align: center;"
                 placeholder="답">
          <input type="hidden" name="captcha_correct" value="<?php echo $captcha_answer; ?>">
        </div>
      </div>
      
      <div style="display: flex; gap: 0.7rem; justify-content: flex-end;">
        <button type="button" onclick="closeInquiryModal()" 
                style="background: #bbb; color: #222; border: none; border-radius: 8px; padding: 0.9rem 1.5rem; font-size: 1rem; font-weight: 700; cursor: pointer; transition: background 0.15s;">
          취소
        </button>
        <button type="submit" 
                style="background: #0064FF; color: #fff; border: none; border-radius: 8px; padding: 0.9rem 1.5rem; font-size: 1rem; font-weight: 700; cursor: pointer; transition: background 0.15s;">
          보내기
        </button>
      </div>
    </form>
  </div>
</div>

<!-- 개인정보처리방침 모달 -->
<div id="privacyModal" style="display: none; position: fixed; z-index: 10000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); align-items: center; justify-content: center;">
  <div style="background: white; border-radius: 16px; padding: 2rem; max-width: 700px; width: 90%; margin: 1rem; box-shadow: 0 12px 40px rgba(0, 0, 0, 0.2); max-height: 80vh; overflow-y: auto;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
      <h3 style="margin: 0; color: #333; font-size: 1.3rem; font-weight: 600;">개인정보처리방침</h3>
      <button onclick="closePrivacyModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #666; padding: 0.2rem 0.5rem; border-radius: 4px; line-height: 1;" title="닫기">×</button>
    </div>
    
    <div style="line-height: 1.6; color: #444;">
      <h4 style="color: #333; margin-top: 1.5rem; margin-bottom: 0.8rem;">1. 개인정보의 수집 및 이용목적</h4>
      <p style="margin-bottom: 1rem;">
        무빙체크는 부동산 임대차 계약에서 입주 시 물건 상태를 촬영하고 퇴거 시 촬영된 사진과 비교하여 파손 여부를 판단하고 임대인과 임차인 간 분쟁을 줄이는 서비스를 제공합니다.
      </p>
      
      <h4 style="color: #333; margin-top: 1.5rem; margin-bottom: 0.8rem;">2. 수집하는 개인정보 항목</h4>
      <ul style="margin-bottom: 1rem; padding-left: 1.2rem;">
        <li>필수항목: 성명, 이메일 주소, 전화번호</li>
        <li>임대차 계약서 및 계약서 내용</li>
        <li>임대물 상태 확인을 위한 사진</li>
        <li>임대인과 임차인의 서명</li>
      </ul>
      
      <h4 style="color: #333; margin-top: 1.5rem; margin-bottom: 0.8rem;">3. 개인정보의 이용목적</h4>
      <ul style="margin-bottom: 1rem; padding-left: 1.2rem;">
        <li>임대차 계약 관련 서비스 제공</li>
        <li>임대물 상태 기록 및 비교 분석</li>
        <li>분쟁 발생 시 증빙자료 제공</li>
        <li>고객 문의 및 서비스 개선</li>
      </ul>
      
      <h4 style="color: #333; margin-top: 1.5rem; margin-bottom: 0.8rem;">4. 개인정보의 보유 및 이용기간</h4>
      <p style="margin-bottom: 1rem;">
        수집된 개인정보는 임대차 계약 기간 종료 후 3년간 보관되며, 보관기간 경과 후 지체없이 파기됩니다. 단, 관련 법령에 의한 정보보호 사유가 발생할 경우 해당 기간까지 보관됩니다.
      </p>
      
      <h4 style="color: #333; margin-top: 1.5rem; margin-bottom: 0.8rem;">5. 개인정보의 제3자 제공</h4>
      <p style="margin-bottom: 1rem;">
        무빙체크는 이용자의 개인정보를 원칙적으로 외부에 제공하지 않습니다. 다만, 분쟁 조정이나 법적 절차를 위해 필요한 경우 관련 기관에 제공할 수 있습니다.
      </p>
      
      <h4 style="color: #333; margin-top: 1.5rem; margin-bottom: 0.8rem;">6. 개인정보 보호책임자</h4>
      <p style="margin-bottom: 1rem;">
        개인정보 처리에 관한 업무를 총괄해서 책임지고, 개인정보 처리와 관련한 정보주체의 불만처리 및 피해구제 등을 위하여 아래와 같이 개인정보 보호책임자를 지정하고 있습니다.
      </p>
      <div style="background: #f8f9fa; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
        <p style="margin: 0.3rem 0;"><strong>성명:</strong> <?php echo defined('PRIVACY_OFFICER_NAME') ? PRIVACY_OFFICER_NAME : '홍길동'; ?></p>
        <p style="margin: 0.3rem 0;"><strong>연락처:</strong> <?php echo defined('PRIVACY_OFFICER_PHONE') ? PRIVACY_OFFICER_PHONE : '02-0000-0000'; ?></p>
        <p style="margin: 0.3rem 0;"><strong>이메일:</strong> <?php echo defined('PRIVACY_OFFICER_EMAIL') ? PRIVACY_OFFICER_EMAIL : 'privacy@movingcheck.kr'; ?></p>
      </div>
      
      <p style="margin-bottom: 1rem; font-size: 0.9rem; color: #666;">
        본 개인정보처리방침은 2024년 1월 1일부터 적용됩니다.
      </p>
    </div>
  </div>
</div>

<!-- 이용약관 모달 -->
<div id="termsModal" style="display: none; position: fixed; z-index: 10000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); align-items: center; justify-content: center;">
  <div style="background: white; border-radius: 16px; padding: 2rem; max-width: 700px; width: 90%; margin: 1rem; box-shadow: 0 12px 40px rgba(0, 0, 0, 0.2); max-height: 80vh; overflow-y: auto;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
      <h3 style="margin: 0; color: #333; font-size: 1.3rem; font-weight: 600;">이용약관</h3>
      <button onclick="closeTermsModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #666; padding: 0.2rem 0.5rem; border-radius: 4px; line-height: 1;" title="닫기">×</button>
    </div>
    
    <div style="line-height: 1.6; color: #444;">
      <h4 style="color: #333; margin-top: 1.5rem; margin-bottom: 0.8rem;">제1조 (목적)</h4>
      <p style="margin-bottom: 1rem;">
        본 약관은 무빙체크가 제공하는 부동산 임대차 계약 분쟁 예방 서비스(이하 "서비스")의 이용조건 및 절차, 무빙체크와 이용자의 권리, 의무, 책임사항과 기타 필요한 사항을 규정함을 목적으로 합니다.
      </p>
      
      <h4 style="color: #333; margin-top: 1.5rem; margin-bottom: 0.8rem;">제2조 (서비스의 내용)</h4>
      <ul style="margin-bottom: 1rem; padding-left: 1.2rem;">
        <li>임대차 계약 시 입주 상태 사진 촬영 및 저장</li>
        <li>퇴거 시 상태 사진 촬영 및 입주 시와 비교</li>
        <li>임대인과 임차인의 서명을 통한 상태 확인</li>
        <li>분쟁 발생 시 증빙자료 제공</li>
      </ul>
      
      <h4 style="color: #333; margin-top: 1.5rem; margin-bottom: 0.8rem;">제3조 (이용자의 의무)</h4>
      <ul style="margin-bottom: 1rem; padding-left: 1.2rem;">
        <li>정확한 정보 제공의 의무</li>
        <li>서비스 이용 시 촬영 및 서명에 성실히 참여</li>
        <li>타인의 권리를 침해하지 않을 의무</li>
        <li>서비스를 부정한 목적으로 이용하지 않을 의무</li>
      </ul>
      
      <h4 style="color: #333; margin-top: 1.5rem; margin-bottom: 0.8rem;">제4조 (무빙체크의 의무)</h4>
      <ul style="margin-bottom: 1rem; padding-left: 1.2rem;">
        <li>안전하고 지속적인 서비스 제공</li>
        <li>이용자의 개인정보 보호</li>
        <li>공정하고 객관적인 증빙자료 관리</li>
        <li>서비스 관련 문의에 대한 신속한 처리</li>
      </ul>
      
      <h4 style="color: #333; margin-top: 1.5rem; margin-bottom: 0.8rem;">제5조 (면책조항)</h4>
      <p style="margin-bottom: 1rem;">
        회사는 다음 각 호의 경우에는 책임을 지지 않습니다:
      </p>
      <ul style="margin-bottom: 1rem; padding-left: 1.2rem;">
        <li>천재지변 또는 이에 준하는 불가항력으로 인한 서비스 중단</li>
        <li>이용자의 귀책사유로 인한 서비스 이용 장애</li>
        <li>이용자 간의 분쟁에 대한 조정 및 해결</li>
        <li>무료로 제공되는 서비스와 관련하여 발생한 손해</li>
      </ul>
      
      <h4 style="color: #333; margin-top: 1.5rem; margin-bottom: 0.8rem;">제6조 (분쟁해결)</h4>
      <p style="margin-bottom: 1rem;">
        서비스 이용과 관련하여 무빙체크와 이용자 사이에 분쟁이 발생한 경우, 쌍방간의 협의를 통하여 해결하는 것을 원칙으로 하며, 협의가 이루어지지 않을 경우 관할 법원에서 해결합니다.
      </p>
      
      <p style="margin-bottom: 1rem; font-size: 0.9rem; color: #666;">
        본 약관은 2024년 1월 1일부터 적용됩니다.
      </p>
    </div>
  </div>
</div>

<!-- 문의하기 결과 메시지 -->
<?php if (isset($_POST['action']) && $_POST['action'] === 'send_inquiry'): ?>
  <?php
  // 스팸 방지 검증
  $spam_detected = false;
  $error_message = '';
  
  // 1. Honeypot 필드 확인 (봇이 채웠는지 확인)
  if (!empty($_POST['website'])) {
    $spam_detected = true;
    $error_message = '자동화된 요청이 감지되었습니다.';
  }
  
  // 2. CAPTCHA 확인
  $captcha_answer = intval($_POST['captcha_answer'] ?? 0);
  $captcha_correct = intval($_POST['captcha_correct'] ?? 0);
  if ($captcha_answer !== $captcha_correct) {
    $spam_detected = true;
    $error_message = '스팸 방지 확인 답이 올바르지 않습니다.';
  }
  
  // 3. Rate Limiting - 세션 기반 제한 (같은 세션에서 1분 내 재전송 방지)
  $last_inquiry_time = $_SESSION['last_inquiry_time'] ?? 0;
  if (time() - $last_inquiry_time < 60) {
    $spam_detected = true;
    $error_message = '문의는 1분에 한 번만 가능합니다. 잠시 후 다시 시도해주세요.';
  }
  
  // 4. 기본 필드 검증 및 길이 제한
  $inquiry_name = trim($_POST['inquiry_name'] ?? '');
  $inquiry_email = trim($_POST['inquiry_email'] ?? '');
  $inquiry_phone = trim($_POST['inquiry_phone'] ?? '');
  $inquiry_content = trim($_POST['inquiry_content'] ?? '');
  $current_page = $_POST['current_page'] ?? '';
  
  if (strlen($inquiry_content) > 2000) {
    $spam_detected = true;
    $error_message = '문의 내용이 너무 깁니다. (최대 2000자)';
  }
  
  // 5. 스팸성 키워드 필터
  $spam_keywords = ['viagra', 'casino', 'loan', 'sex', '대출', '카지노', '성인', '도박'];
  $content_lower = strtolower($inquiry_content);
  foreach ($spam_keywords as $keyword) {
    if (strpos($content_lower, strtolower($keyword)) !== false) {
      $spam_detected = true;
      $error_message = '부적절한 내용이 포함되어 있습니다.';
      break;
    }
  }
  
  if ($spam_detected) {
    echo "<script>
      document.addEventListener('DOMContentLoaded', function() {
        alert('$error_message');
      });
    </script>";
  } else {
    // 관리자 이메일 조회 (config.inc의 ADMIN_EMAIL 우선, 없으면 users 테이블의 admin 사용자 이메일 사용)
    $admin_email = '';
    
    // 1. config.inc의 ADMIN_EMAIL 확인
    if (defined('ADMIN_EMAIL') && ADMIN_EMAIL && trim(ADMIN_EMAIL) !== '') {
      $admin_email = ADMIN_EMAIL;
    } else {
      // 2. fallback: users 테이블의 admin 사용자 이메일
      $pdo = get_pdo();
      if (!is_string($pdo)) {
        $stmt = $pdo->prepare("SELECT email FROM users WHERE login_id = 'admin' LIMIT 1");
        $stmt->execute();
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($admin && $admin['email']) {
          $admin_email = $admin['email'];
        }
      }
    }
    
    if ($admin_email && $inquiry_name && $inquiry_email && $inquiry_content) {
      // 전송 시간 기록 (Rate Limiting용)
      $_SESSION['last_inquiry_time'] = time();
        // 이메일 내용 구성
        $subject = "[무빙체크] 문의: " . $inquiry_name . "님의 문의";
        $message = "무빙체크 홈페이지를 통해 문의가 접수되었습니다.\n\n";
        $message .= "문의자: " . $inquiry_name . "\n";
        $message .= "이메일: " . $inquiry_email . "\n";
        if ($inquiry_phone) {
          $message .= "연락처: " . $inquiry_phone . "\n";
        }
        
        // 로그인된 사용자 정보 추가
        if (isset($_SESSION['user_id']) && isset($_SESSION['login_id'])) {
          $message .= "사용자 아이디: " . $_SESSION['login_id'] . " (ID: " . $_SESSION['user_id'] . ")\n";
        } else {
          $message .= "사용자 아이디: (로그인하지 않음)\n";
        }
        
        // 현재 페이지 정보 추가
        if ($current_page) {
          $message .= "문의 페이지: " . $current_page . "\n";
        }
        
        $message .= "문의일시: " . date('Y-m-d H:i:s') . "\n\n";
        $message .= "문의 내용:\n" . $inquiry_content . "\n\n";
        
        // 헤더 설정
        $headers = "From: " . $inquiry_email . "\r\n";
        $headers .= "Reply-To: " . $inquiry_email . "\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        
        // 이메일 발송
        $mail_sent = mail($admin_email, $subject, $message, $headers);
        
        if ($mail_sent) {
          echo "<script>
            document.addEventListener('DOMContentLoaded', function() {
              alert('문의가 성공적으로 접수되었습니다. 빠른 시일 내에 답변드리겠습니다.');
            });
          </script>";
        } else {
          echo "<script>
            document.addEventListener('DOMContentLoaded', function() {
              alert('문의 발송에 실패했습니다. 잠시 후 다시 시도해주세요.');
            });
          </script>";
        }
      }
    }
  ?>
<?php endif; ?>

<script>
// 문의하기 모달 관리
function openInquiryModal() {
  // 현재 페이지 URL 설정
  document.querySelector('input[name="current_page"]').value = window.location.href;
  
  // 새로운 CAPTCHA 생성
  generateNewCaptcha();
  
  document.getElementById('inquiryModal').style.display = 'flex';
  document.body.style.overflow = 'hidden';
}

// 새로운 CAPTCHA 생성 함수
function generateNewCaptcha() {
  const num1 = Math.floor(Math.random() * 10) + 1;
  const num2 = Math.floor(Math.random() * 10) + 1;
  const answer = num1 + num2;
  
  // CAPTCHA 문제 업데이트
  const captchaText = document.querySelector('#inquiryModal .captcha-question');
  if (captchaText) {
    captchaText.textContent = num1 + ' + ' + num2 + ' = ?';
  }
  
  // 정답 필드 업데이트
  const captchaCorrect = document.querySelector('input[name="captcha_correct"]');
  if (captchaCorrect) {
    captchaCorrect.value = answer;
  }
  
  // 답 입력 필드 초기화
  const captchaAnswer = document.querySelector('input[name="captcha_answer"]');
  if (captchaAnswer) {
    captchaAnswer.value = '';
  }
}

function closeInquiryModal() {
  document.getElementById('inquiryModal').style.display = 'none';
  document.body.style.overflow = 'auto';
  
  // 폼 리셋 시 사용자 정보는 유지하고 문의 내용만 초기화
  const form = document.getElementById('inquiryForm');
  const contentField = form.querySelector('textarea[name="inquiry_content"]');
  if (contentField) {
    contentField.value = '';
  }
}

// 모달 배경 클릭 시 닫기
document.getElementById('inquiryModal').onclick = function(e) {
  if (e.target === this) {
    closeInquiryModal();
  }
};

// 개인정보처리방침 모달 관리
function openPrivacyModal() {
  document.getElementById('privacyModal').style.display = 'flex';
  document.body.style.overflow = 'hidden';
}

function closePrivacyModal() {
  document.getElementById('privacyModal').style.display = 'none';
  document.body.style.overflow = 'auto';
}

// 약관 모달 관리
function openTermsModal() {
  document.getElementById('termsModal').style.display = 'flex';
  document.body.style.overflow = 'hidden';
}

function closeTermsModal() {
  document.getElementById('termsModal').style.display = 'none';
  document.body.style.overflow = 'auto';
}

// 개인정보처리방침 모달 배경 클릭 시 닫기
document.getElementById('privacyModal').onclick = function(e) {
  if (e.target === this) {
    closePrivacyModal();
  }
};

// 약관 모달 배경 클릭 시 닫기
document.getElementById('termsModal').onclick = function(e) {
  if (e.target === this) {
    closeTermsModal();
  }
};

// ESC 키로 모달 닫기
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    if (document.getElementById('inquiryModal').style.display === 'flex') {
      closeInquiryModal();
    } else if (document.getElementById('privacyModal').style.display === 'flex') {
      closePrivacyModal();
    } else if (document.getElementById('termsModal').style.display === 'flex') {
      closeTermsModal();
    }
  }
});

// 폼 제출 시 로딩 표시
document.getElementById('inquiryForm').addEventListener('submit', function(e) {
  const submitBtn = this.querySelector('button[type="submit"]');
  submitBtn.textContent = '발송 중...';
  submitBtn.disabled = true;
});

function initializeInquiryPhoneInputs() {
  const hiddenInput = document.getElementById('inquiry_phone');
  const input1 = document.getElementById('inquiry_phone_1');
  const input2 = document.getElementById('inquiry_phone_2');
  const input3 = document.getElementById('inquiry_phone_3');
  if (hiddenInput && hiddenInput.value) {
    const parts = hiddenInput.value.split('-');
    if (parts.length >= 3) {
      input1.value = parts[0];
      input2.value = parts[1];
      input3.value = parts[2];
    }
  }
  [input1, input2, input3].forEach((input, index) => {
    input.addEventListener('input', function(e) {
      this.value = this.value.replace(/[^0-9]/g, '');
      
      // 다음 필드로 자동 이동
      if (index === 0) {
        // 첫 번째 필드 (국번)
        const areaCode = this.value;
        
        // 02가 입력되면 바로 두 번째 필드로 이동
        if (areaCode === '02') {
          input2.focus();
        }
        // 최대 길이에 도달하면 두 번째 필드로 이동
        else if (this.value.length >= this.maxLength) {
          input2.focus();
        }
        
        // 국번이 변경되면 두 번째 필드의 maxlength 조정
        if (areaCode === '02') {
          input2.maxLength = 4; // 서울도 4자리까지 허용
        } else {
          input2.maxLength = 4;
        }
      } else if (index === 1) {
        // 두 번째 필드 (중간 번호)
        const areaCode = input1.value;
        const currentLength = this.value.length;
        
        // 010인 경우 4자리가 채워졌을 때만 세 번째 필드로 이동
        if (areaCode === '010' && currentLength >= 4) {
          input3.focus();
        }
        // 다른 지역번호의 경우 3자리 또는 4자리에서 이동
        else if (areaCode !== '010') {
          if (currentLength >= 4) {
            input3.focus();
          } else if (currentLength === 3) {
            setTimeout(() => {
              if (this.value.length === 3 && document.activeElement === this) {
                input3.focus();
              }
            }, 1000);
          }
        }
      }
      
      updateHiddenInquiryPhoneField(input1, input2, input3);
    });
    input.addEventListener('keydown', function(e) {
      if (e.key === 'Backspace' && this.value.length === 0 && index > 0) {
        [input1, input2, input3][index - 1].focus();
      }
    });
  });
}

function updateHiddenInquiryPhoneField(input1, input2, input3) {
  const hiddenInput = document.getElementById('inquiry_phone');
  const value1 = input1.value.trim();
  const value2 = input2.value.trim();
  const value3 = input3.value.trim();
  if (value1 || value2 || value3) {
    hiddenInput.value = `${value1}-${value2}-${value3}`;
  } else {
    hiddenInput.value = '';
  }
}

document.addEventListener('DOMContentLoaded', function() {
  initializeInquiryPhoneInputs();
});

const inquiryForm = document.getElementById('inquiryForm');
if (inquiryForm) {
  inquiryForm.addEventListener('submit', function(e) {
    const input1 = document.getElementById('inquiry_phone_1');
    const input2 = document.getElementById('inquiry_phone_2');
    const input3 = document.getElementById('inquiry_phone_3');
    updateHiddenInquiryPhoneField(input1, input2, input3);
  });
}

</script>

<style>
/* 전화번호 입력 필드 스타일 */
.phone-input-group {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  width: 100%;
  box-sizing: border-box;
}
.phone-input {
  flex: 1 1 0;
  min-width: 0;
  max-width: 100px;
  padding: 0.75rem;
  border: 1px solid #ddd;
  border-radius: 6px;
  font-size: 1rem;
  text-align: center;
  background: #fff;
  transition: border-color 0.2s;
  box-sizing: border-box;
}
.phone-separator {
  font-size: 1.2rem;
  color: #666;
  font-weight: 500;
}
@media (max-width: 600px) {
  .phone-input-group {
    gap: 0.2rem;
    width: 100%;
  }
  .phone-input {
    padding: 0.5rem 0.2rem;
    font-size: 0.95rem;
    max-width: 70px;
  }
  .phone-separator {
    font-size: 0.9rem;
  }
}
</style>
</script> 