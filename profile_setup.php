<?php
require_once 'config.inc';
require_once 'sql.inc';

// 로그인 확인 및 새로운 사용자 확인

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$current_nickname = $_SESSION['nickname'] ?? '';
$current_email = $_SESSION['email'] ?? '';

// 전화번호 DB에서 불러오기
$current_phone = '';
$pdo = get_pdo();
if (!is_string($pdo)) {
    $stmt = $pdo->prepare('SELECT phone FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && isset($row['phone'])) {
        $current_phone = $row['phone'];
    }
}

// 신규 가입 여부 플래그
$is_new_user = isset($_SESSION['is_new_user']) && $_SESSION['is_new_user'];

// 폼 제출 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'skip') {
        // 건너뛰기
        unset($_SESSION['is_new_user']);
        header('Location: properties.php');
        exit;
    } elseif ($action === 'save') {
        // 입력 검증 규칙 정의
        $validation_rules = [
            'nickname' => ['type' => 'string', 'required' => true, 'max_length' => 50],
            'phone' => ['type' => 'phone', 'required' => false],
            'email' => ['type' => 'email', 'required' => true, 'max_length' => 100]
        ];
        
        // 입력 데이터 검증
        $validation = validate_form_data($_POST, $validation_rules);
        
        if (!$validation['valid']) {
            $error = '입력 형식이 올바르지 않습니다: ' . implode(', ', $validation['errors']);
        } else {
            $nickname = $validation['data']['nickname'];
            $phone = $validation['data']['phone'];
            $email = $validation['data']['email'];
        
        if ($nickname && $email) {
            $pdo = get_pdo();
            if (!is_string($pdo)) {
                try {
                    $sql = "UPDATE users SET nickname = ?, phone = ?, email = ? WHERE id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$nickname, $phone, $email, $user_id]);
                    
                    // 세션의 닉네임/이메일도 업데이트
                    $_SESSION['nickname'] = $nickname;
                    $_SESSION['email'] = $email;
                    
                    // 활동 기록
                    log_user_activity($user_id, 'other', "프로필 정보 설정 완료: {$nickname}", null);
                    
                    // 새로운 사용자 플래그 제거
                    unset($_SESSION['is_new_user']);
                    
                    header('Location: properties.php?welcome=1');
                    exit;
                } catch (Exception $e) {
                    $error = '정보 저장 중 오류가 발생했습니다.';
                }
            } else {
                $error = '데이터베이스 연결 오류입니다.';
            }
            } else {
                $error = '이름과 이메일을 입력해주세요.';
            }
        } // else 블록 닫기
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>프로필 설정 - <?php echo SITE_TITLE; ?></title>
    <link rel="stylesheet" href="style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        .setup-container {
            max-width: 480px;
            margin: 0 auto;
            margin-top: 5vh;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,100,255,0.1);
            padding: 2.5rem 2rem;
            text-align: center;
        }
        .setup-header {
            margin-bottom: 2rem;
        }
        .setup-title {
            font-size: 1.6rem;
            font-weight: 900;
            color: #0064FF;
            margin-bottom: 0.5rem;
        }
        .setup-subtitle {
            font-size: 1.1rem;
            color: #333;
            margin-bottom: 1rem;
        }
        .setup-description {
            background: #f8f9ff;
            border: 1px solid #e3eaff;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            text-align: left;
        }
        .setup-description h3 {
            color: #0064FF;
            font-size: 1.1rem;
            margin: 0 0 1rem 0;
            font-weight: 700;
        }
        .setup-description ul {
            margin: 0;
            padding-left: 1.2rem;
            color: #555;
            line-height: 1.6;
        }
        .setup-description li {
            margin-bottom: 0.5rem;
        }
        .setup-form {
            text-align: left;
            margin-bottom: 2rem;
        }
        .form-group {
            margin-bottom: 1.5rem;
        }
        .form-label {
            display: block;
            font-weight: 600;
            color: #333;
            margin-bottom: 0.5rem;
        }
        .form-input {
            width: 100%;
            padding: 0.8rem;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.2s;
            box-sizing: border-box;
        }
        .form-input:focus {
            outline: none;
            border-color: #0064FF;
        }
        .form-help {
            font-size: 0.9rem;
            color: #666;
            margin-top: 0.3rem;
        }
        .button-group {
            display: flex;
            gap: 1rem;
            justify-content: center;
        }
        .btn {
            padding: 0.9rem 1.8rem;
            font-size: 1rem;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        .btn-primary {
            background: #0064FF;
            color: #fff;
        }
        .btn-primary:hover {
            background: #0052cc;
        }
        .btn-secondary {
            background: #6c757d;
            color: #fff;
        }
        .btn-secondary:hover {
            background: #5a6268;
        }
        .error-message {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            padding: 0.8rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
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
        @media (max-width: 600px) {
            .setup-container {
                margin: 2rem 1rem;
                padding: 2rem 1.5rem;
            }
            .button-group {
                flex-direction: column;
            }
            .btn {
                width: 100%;
            }
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
</head>
<body style="background:#f7fafd;">
<?php include 'header.inc'; ?>

<div class="setup-container">
    <?php if ($is_new_user): ?>
    <div class="setup-header">
        <div class="setup-title">🎉 무빙체크에 오신 것을 환영합니다!</div>
        <div class="setup-subtitle">프로필 정보를 설정하여 더욱 편리하게 이용해보세요</div>
    </div>
    <div class="setup-description">
        <h3>📋 프로필 정보 입력 시 장점</h3>
        <ul>
            <li><strong>자동 입력:</strong> 계약서 작성 시 이름과 연락처가 자동으로 입력됩니다</li>
            <li><strong>빠른 소통:</strong> 임대인/임차인과의 연락이 더욱 원활해집니다</li>
            <li><strong>편리한 관리:</strong> 내 정보가 모든 계약에서 일관되게 관리됩니다</li>
            <li><strong>신뢰도 향상:</strong> 완성된 프로필로 더 신뢰받는 거래가 가능합니다</li>
        </ul>
    </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form class="setup-form" method="POST">
        <input type="hidden" name="action" value="save">
        
        <div class="form-group">
            <label class="form-label" for="nickname">이름 *</label>
            <input type="text" 
                   id="nickname" 
                   name="nickname" 
                   class="form-input" 
                   value="<?php echo htmlspecialchars($current_nickname); ?>" 
                   placeholder="실명을 입력하세요" 
                   required>
            <div class="form-help">계약서에 표시될 이름입니다. 실명 사용을 권장합니다.</div>
        </div>

        <div class="form-group">
            <label class="form-label" for="phone">전화번호</label>
            <div class="phone-input-group">
                <input type="text" class="phone-input" name="phone_1" id="phone_1" maxlength="3" inputmode="numeric">
                <span class="phone-separator">-</span>
                <input type="text" class="phone-input" name="phone_2" id="phone_2" maxlength="4" inputmode="numeric">
                <span class="phone-separator">-</span>
                <input type="text" class="phone-input" name="phone_3" id="phone_3" maxlength="4" inputmode="numeric">
                <input type="hidden" name="phone" id="phone" value="<?php echo htmlspecialchars($current_phone); ?>">
            </div>
            <div class="form-help">연락처를 입력하시면 임대차 관련 소통이 더욱 원활해집니다.</div>
        </div>

        <div style="margin-bottom: 1.5rem;">
            <label class="form-label" for="email">이메일</label>
            <input type="email" id="email" name="email" class="form-input" value="<?php echo htmlspecialchars($current_email); ?>" placeholder="이메일을 입력하세요" required>
        </div>

        <div class="button-group">
            <button type="submit" class="btn btn-primary">설정 완료</button>
            <?php if ($is_new_user): ?>
            <button type="submit" name="action" value="skip" class="btn btn-secondary">나중에 설정</button>
            <?php else: ?>
            <button type="submit" name="action" value="skip" class="btn btn-secondary">취소</button>
            <?php endif; ?>
        </div>
    </form>

    <div style="margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid #eee; font-size: 0.9rem; color: #666;">
        💡 언제든지 내 정보에서 프로필을 수정할 수 있습니다.
    </div>
</div>

<script>
// 전화번호 입력 필드 초기화 및 이벤트 설정
function initializePhoneInputs() {
    const hiddenInput = document.getElementById('phone');
    const input1 = document.getElementById('phone_1');
    const input2 = document.getElementById('phone_2');
    const input3 = document.getElementById('phone_3');
    // 기존 값이 있으면 분리해서 표시
    if (hiddenInput && hiddenInput.value) {
        const parts = hiddenInput.value.split('-');
        if (parts.length >= 3) {
            input1.value = parts[0];
            input2.value = parts[1];
            input3.value = parts[2];
        }
    }
    // 입력 이벤트 설정
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
            
            // hidden 필드 업데이트
            updateHiddenPhoneField(input1, input2, input3);
        });
        // 백스페이스로 이전 필드로 이동
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Backspace' && this.value.length === 0 && index > 0) {
                [input1, input2, input3][index - 1].focus();
            }
        });
    });
}
function updateHiddenPhoneField(input1, input2, input3) {
    const hiddenInput = document.getElementById('phone');
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
    initializePhoneInputs();
});
// 폼 제출 시 phone 값 조합
const setupForm = document.querySelector('.setup-form');
if (setupForm) {
    setupForm.addEventListener('submit', function(e) {
        const input1 = document.getElementById('phone_1');
        const input2 = document.getElementById('phone_2');
        const input3 = document.getElementById('phone_3');
        updateHiddenPhoneField(input1, input2, input3);
    });
}
</script>

<?php include 'footer.inc'; ?>
</body>
</html> 